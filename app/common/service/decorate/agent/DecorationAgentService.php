<?php
declare(strict_types=1);
namespace app\common\service\decorate\agent;

use app\common\model\decorate\DecoratePage;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\auth\TenantAdmin;
use app\tenantapi\logic\auth\AuthLogic;
use app\common\service\app\AppFrontendManifestService;
use app\common\service\decorate\DecorateTemplateService;
use app\common\service\decorate\DecorateDataSourceService;
use app\common\service\power\MarketTextModelRuntimeService;
use NeuronAI\Workflow\Workflow;
use think\facade\Db;
use RuntimeException;

/** Tenant/admin-scoped persistence. Model/tool workers only write candidates. */
final class DecorationAgentService
{
    private static array $leases=[];
    private static function fence(int $id): void
    {
        if(!isset(self::$leases[$id]))return;
        $ok=Db::name('decorate_agent_run')->where('id',$id)->where('lease_token',self::$leases[$id])->where('lease_expire_time','>=',time())->update(['lease_expire_time'=>time()+600]);
        if(!$ok && Db::name('decorate_agent_run')->where('id',$id)->where('lease_token',self::$leases[$id])->where('lease_expire_time','>=',time())->count()===0)throw new RuntimeException('装修执行租约已失效');
    }
    private static function assertOperator(int $tenant,int $admin,string $scope): void
    {
        $actor=TenantAdmin::where(['tenant_id'=>$tenant,'id'=>$admin])->find();
        if(!$actor||(int)$actor['disable']!==0)throw new RuntimeException('管理员已停用，装修执行已停止');
        if((int)$actor['root']===1)return;
        $uris=array_map('strtolower',AuthLogic::getAuthByAdminId($admin));
        if(!in_array('decorate.page/save',$uris,true)||($scope==='new_page'&&!in_array('decorate.page/add',$uris,true)))throw new RuntimeException('装修权限已变化，执行已停止');
    }
    private static function availableLinks(int $tenant,int $template): array
    {
        $links=DecorateTemplateService::linkLists($tenant,$template,'mobile');
        foreach(AppFrontendManifestService::tenantEntries($tenant,'uniapp') as $entry){
            $pages=$entry['meta']['pages']??[];if(!$pages)$pages=[['name'=>$entry['name'],'path'=>$entry['path']]];
            foreach($pages as $page)if(!empty($page['path']))$links[]=['path'=>$page['path'],'query'=>$page['query']??[],'name'=>$page['name']??$entry['name'],'canTab'=>(bool)($page['canTab']??false),'type'=>'app_center','app_code'=>$entry['app_code'],'entry_key'=>$entry['entry_key']??'','terminal'=>'uniapp'];
        }
        return $links;
    }
    private static function componentIndex(array $data): array
    {
        $result=[];foreach(DecorationAgentOperations::index($data) as $id=>$node)$result[]=['id'=>$id,'name'=>$node['widget']['name'],'path'=>$node['path']];return $result;
    }
    private static function compactCatalog(): array
    {return array_map(fn($d)=>array_intersect_key($d,array_flip(['name','title','category','repeatable','container'])),DecorationAgentCatalog::summary()['components']);}
    public static function json(mixed $value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    public static function decode(mixed $value): array {return is_array($value)?$value:(json_decode((string)$value,true)?:[]);}
    public static function hash(array $page): string {return hash('sha256',self::json($page));}
    public static function enabled(): bool {return filter_var(config('decoration_agent.enabled',false),FILTER_VALIDATE_BOOLEAN);}
    private static function q(string $table,int $tenant,int $admin){return Db::name('decorate_agent_'.$table)->where(['tenant_id'=>$tenant,'admin_id'=>$admin]);}
    private static function owned(string $table,int $tenant,int $admin,int $id): array
    {
        if($tenant<=0||$admin<=0||$id<=0)throw new RuntimeException('装修会话身份或编号无效');
        $row=self::q($table,$tenant,$admin)->where('id',$id)->find();
        if(!$row)throw new RuntimeException('装修记录不存在或无权访问');
        return $row;
    }
    public static function assertEnabled(): void {if(!self::enabled())throw new RuntimeException('AI智能装修暂未启用');}
    private static function insert(string $table,int $tenant,int $admin,array $data): int
    {return (int)Db::name('decorate_agent_'.$table)->insertGetId($data+['tenant_id'=>$tenant,'admin_id'=>$admin,'create_time'=>time(),'update_time'=>time()]);}
    public static function payload(array $page): array
    {
        return ['data'=>self::decode($page['draft_data']?:($page['data']??'[]')),'meta'=>self::decode($page['draft_meta']?:($page['meta']??'[]')),'name'=>(string)$page['name'],'new_page'=>false];
    }
    private static function page(int $tenant,int $template,int $id,bool $lock=false): array
    {
        $query=DecoratePage::where(['tenant_id'=>$tenant,'template_id'=>$template,'id'=>$id,'terminal'=>'mobile'])->where('draft_deleted',0);
        if($lock)$query->lock(true);
        $page=$query->find();if(!$page)throw new RuntimeException('移动装修页面不存在');return $page->toArray();
    }
    public static function bootstrap(int $tenant,int $admin,array $p=[]): array
    {
        $catalog=new DecorationAgentCatalog();
        $data=['enabled'=>self::enabled(),'catalog_hash'=>$catalog->hash(),'catalog_version'=>$catalog->version(),'text_models'=>[],'image_models'=>[],'limits'=>['model_calls'=>6,'tools'=>20,'images'=>5,'attachments'=>5,'attachment_bytes'=>10485760],'sources'=>['tenant','content','upload','generate']];
        if(!$data['enabled'])return $data;
        if(!class_exists(Workflow::class)){$data['enabled']=false;$data['reason']='装修Agent运行依赖尚未安装';return $data;}
        try{foreach(['session','message','run','candidate','image_slot','checkpoint'] as $table)self::q($table,$tenant,$admin)->limit(1)->select();}catch(\Throwable $e){$data['enabled']=false;$data['reason']='装修Agent数据尚未初始化';return $data;}
        $data['text_models']=MarketTextModelRuntimeService::textOptions($tenant);
        $data['image_models']=DecorationAgentMediaService::options($tenant);
        if(!empty($p['page_id']))$data['current_hash']=self::hash(self::payload(self::page($tenant,(int)$p['template_id'],(int)$p['page_id'])));
        return $data;
    }
    public static function createSession(int $tenant,int $admin,array $p): array
    {
        self::assertEnabled();$page=self::page($tenant,(int)($p['template_id']??0),(int)($p['page_id']??0));
        $id=self::insert('session',$tenant,$admin,['template_id'=>$page['template_id'],'page_id'=>$page['id'],'scope'=>'page','selected_id'=>'','preferences'=>self::json([]),'facts'=>'[]']);
        return self::session($tenant,$admin,$id);
    }
    public static function sessions(int $tenant,int $admin,array $p): array
    {return self::q('session',$tenant,$admin)->where('template_id',(int)($p['template_id']??0))->when(!empty($p['page_id']),fn($q)=>$q->where('page_id',(int)$p['page_id']))->order('id','desc')->limit(50)->select()->toArray();}
    public static function session(int $tenant,int $admin,int $id): array
    {
        $s=self::owned('session',$tenant,$admin,$id);$s['preferences']=self::decode($s['preferences']);$s['facts']=self::decode($s['facts']);
        $s['messages']=array_map(function($m){$m['attachments']=self::decode($m['attachments']);return $m;},self::q('message',$tenant,$admin)->where('session_id',$id)->order('id')->select()->toArray());
        $s['candidates']=self::q('candidate',$tenant,$admin)->where('session_id',$id)->field('id,run_id,revision,status,applied_page_id,create_time')->order('id','desc')->limit(50)->select()->toArray();
        $s['checkpoints']=self::q('checkpoint',$tenant,$admin)->where('session_id',$id)->field('id,page_id,candidate_id,kind,create_time,after_hash')->order('id','desc')->limit(50)->select()->toArray();
        $s['current_hash']=self::hash(self::payload(self::page($tenant,(int)$s['template_id'],(int)$s['page_id'])));
        $s['last_run']=self::q('run',$tenant,$admin)->where('session_id',$id)->field('id,status,candidate_id')->order('id','desc')->find();$s['latest_run']=$s['last_run']?self::run($tenant,$admin,(int)$s['last_run']['id']):null;return $s;
    }
    private static function input(int $tenant,int $admin,array $p): array
    {
        if(trim((string)($p['message']??''))==='')throw new RuntimeException('请输入装修需求');
        if(mb_strlen((string)$p['message'])>12000)throw new RuntimeException('装修需求过长');
        if(($p['catalog_hash']??'')!==DecorationAgentCatalog::hash())throw new RuntimeException('组件契约版本不一致，请更新编辑器');
        $attachments=DecorationAgentMediaService::validateAttachments($tenant,(array)($p['attachments']??[]));
        $vision=false;foreach($attachments as $a)if(array_intersect($a['uses'],['layout','style']))$vision=true;
        if(empty($p['model_selection']))throw new RuntimeException('请选择文本对话模型');
        $model=MarketTextModelRuntimeService::resolveModel($tenant,$p['model_selection'],$vision);
        $sources=array_values(array_intersect((array)($p['sources']??['tenant','content','upload']),['tenant','content','upload','generate']));
        if(in_array('generate',$sources,true)&&empty($p['image_model_selection']))throw new RuntimeException('请选择生图模型');
        $scope=(string)($p['scope']??'page');if(!in_array($scope,['page','selected','new_page'],true))throw new RuntimeException('装修范围无效');
        if($scope==='selected'&&empty($p['selected_id']))throw new RuntimeException('请先选择组件');
        $budget=(float)($p['budget_points']??0);if(!is_finite($budget)||$budget<=0)throw new RuntimeException('请确认本轮消费预算');
        $imageName='';if(in_array('generate',$sources,true)){$selection=(array)$p['image_model_selection'];$product=(int)($selection['market_product_id']??$selection['id']??$selection['product_id']??$selection['model_id']??0);foreach(DecorationAgentMediaService::options($tenant) as $option)if((int)($option['market_product_id']??$option['id'])===$product){$imageName=(string)$option['name'];break;}}
        return ['image_model_name'=>$imageName,'message'=>trim((string)$p['message']),'model_selection'=>['id'=>$model['id'],'sku_id'=>$model['market_sku_id']],'image_model_selection'=>(array)($p['image_model_selection']??[]),'sources'=>$sources,'image_strategy'=>($p['image_strategy']??'')==='generate_first'?'generate_first':'existing_first','attachments'=>$attachments,'requires_vision'=>$vision,'scope'=>$scope,'selected_id'=>(string)($p['selected_id']??''),'budget_points'=>$budget,'catalog_hash'=>$p['catalog_hash']];
    }
    private static function textEstimate(array $model,string $text,int $output=4096): float
    {
        $total=0;foreach(['input'=>MarketTextModelRuntimeService::estimateTokens($text),'output'=>$output] as $key=>$tokens){$price=$model[$key]??[];$total+=(float)($price['platform_price']??0)*$tokens/max(1,(float)($price['usage_unit_size']??1000000));}return round($total,6);
    }
    public static function quote(int $tenant,int $admin,array $p): array
    {
        self::assertEnabled();$copy=$p;$copy['budget_points']=max(1,(float)($p['budget_points']??1));$input=self::input($tenant,$admin,$copy);
        $s=!empty($p['session_id'])?self::owned('session',$tenant,$admin,(int)$p['session_id']):['template_id'=>(int)($p['template_id']??0),'page_id'=>(int)($p['page_id']??0)];$page=self::payload(self::page($tenant,(int)$s['template_id'],(int)$s['page_id']));
        $model=MarketTextModelRuntimeService::resolveModel($tenant,$input['model_selection'],$input['requires_vision']);
        $estimate=self::textEstimate($model,self::json($page).$input['message'].DecorationAgentDecision::prompt().self::json(self::compactCatalog()));
        $imageQuote=[];if(in_array('generate',$input['sources'],true))$imageQuote=DecorationAgentMediaService::quote($tenant,$input['image_model_selection'],1,['attachments'=>$input['attachments'],'reference_file_ids'=>array_column(array_filter($input['attachments'],fn($a)=>in_array('generation',$a['uses'],true)),'file_id')]);
        return ['estimated_points'=>$estimate,'text_estimated_points'=>$estimate,'max_text_estimated_points'=>$estimate*6,'image_unit_points'=>(float)($imageQuote['tenant_cost_points']??$imageQuote['tenant_cost']??0),'max_model_calls'=>6,'max_images'=>5,'estimate_only'=>true];
    }
    public static function start(int $tenant,int $admin,array $p): array
    {
        self::assertEnabled();$key=trim((string)($p['idempotency_key']??''));if($key===''||strlen($key)>100)throw new RuntimeException('提交标识无效');
        $old=self::q('run',$tenant,$admin)->where('idempotency_key',$key)->find();if($old)return self::run($tenant,$admin,(int)$old['id']);
        $input=self::input($tenant,$admin,$p);
        return Db::transaction(function()use($tenant,$admin,$p,$key,$input){
            $s=self::q('session',$tenant,$admin)->where('id',(int)$p['session_id'])->lock(true)->find();if(!$s)throw new RuntimeException('装修会话不存在');
            $active=self::q('run',$tenant,$admin)->where('session_id',$s['id'])->whereIn('status',['queued','running','waiting_images'])->find();if($active)throw new RuntimeException('当前会话已有执行中的任务');
            $base=self::payload(self::page($tenant,(int)$s['template_id'],(int)$s['page_id']));$candidate=$base;
            $existing=!empty($s['active_candidate_id'])?self::q('candidate',$tenant,$admin)->where('id',$s['active_candidate_id'])->find():null;
            if($existing&&in_array($existing['status'],['ready','waiting_images'],true)){$candidate=self::decode($existing['payload_json']);$base=self::decode($existing['base_json']);}
            if($input['scope']==='new_page'&&!($candidate['new_page']??false))$candidate=['data'=>[],'meta'=>$base['meta'],'name'=>'新页面','new_page'=>true];
            $ctx=['base'=>$base,'candidate'=>$candidate,'observations'=>[],'changes'=>[],'failures'=>[],'receipts'=>[],'facts'=>self::decode($s['facts']),'calling'=>false];
            $id=self::insert('run',$tenant,$admin,['session_id'=>$s['id'],'input_json'=>self::json($input),'context_json'=>self::json($ctx),'result_json'=>'{}','events_json'=>'[]','idempotency_key'=>$key,'next_run_time'=>time()]);
            self::insert('message',$tenant,$admin,['session_id'=>$s['id'],'run_id'=>$id,'role'=>'user','content'=>$input['message'],'attachments'=>self::json($input['attachments'])]);
            self::q('session',$tenant,$admin)->where('id',$s['id'])->update(['scope'=>$input['scope'],'selected_id'=>$input['selected_id'],'preferences'=>self::json(array_diff_key($input,['message'=>1,'attachments'=>1])),'update_time'=>time()]);
            self::event($id,'queued',['message'=>'开始安排页面']);return self::run($tenant,$admin,$id);
        });
    }
    public static function run(int $tenant,int $admin,int $id): array
    {
        $row=self::owned('run',$tenant,$admin,$id);$result=self::decode($row['result_json']);
        return ['id'=>$id,'status'=>$row['status'],'candidate_id'=>(int)$row['candidate_id'],'model_calls'=>(int)$row['model_calls'],'tool_calls'=>(int)$row['tool_calls'],'spent_points'=>(float)$row['spent_points'],'events'=>self::decode($row['events_json']),'image_slots'=>self::slots($tenant,$admin,$id)]+$result;
    }
    public static function events(int $tenant,int $admin,int $id,int $after): array
    {$r=self::run($tenant,$admin,$id);return ['status'=>$r['status'],'events'=>array_values(array_filter($r['events'],fn($e)=>$e['id']>$after)),'run'=>$r];}
    public static function cancel(int $tenant,int $admin,int $id): array
    {
        $r=self::owned('run',$tenant,$admin,$id);self::q('run',$tenant,$admin)->where('id',$id)->update(['cancel_requested'=>1,'update_time'=>time()]);
        if($r['status']==='queued')self::q('run',$tenant,$admin)->where('id',$id)->where('status','queued')->update(['status'=>'canceled']);
        self::event($id,'cancel_requested',['message'=>'已停止后续步骤；已提交的图片任务保留结果']);return self::run($tenant,$admin,$id);
    }
    public static function event(int $runId,string $type,array $data): void
    {
        Db::transaction(function()use($runId,$type,$data){$r=Db::name('decorate_agent_run')->where('id',$runId)->lock(true)->find();if(!$r)return;$events=self::decode($r['events_json']);$last=end($events);$events[]=['id'=>(int)($last['id']??0)+1,'type'=>$type,'data'=>$data,'time'=>time()];Db::name('decorate_agent_run')->where('id',$runId)->update(['events_json'=>self::json(array_slice($events,-200)),'update_time'=>time()]);});
    }
    private static function slots(int $tenant,int $admin,int $runId,int $candidateId=0): array
    {
        $run=$runId>0?self::q('run',$tenant,$admin)->where('id',$runId)->find():null;$input=$run?self::decode($run['input_json']):[];$remaining=$run?max(0,(float)($input['budget_points']??0)-self::spent($run)):null;
        return array_map(function($r)use($input,$remaining){$r['request']=self::decode($r['request_json']);$r['result']=self::decode($r['result_json']);$r['image_model_name']=$input['image_model_name']??'原生图模型';$r['remaining_budget_points']=$remaining;unset($r['request_json'],$r['result_json']);return $r;},self::q('image_slot',$tenant,$admin)->where('run_id',$runId)->when($candidateId>0,fn($q)=>$q->where('candidate_id',$candidateId))->order('id')->select()->toArray());
    }
    private static function resolvedPage(int $tenant,array $page,?array $payload=null): array
    {
        $payload=$payload??self::payload($page);$source=$page;$source['data']=self::json($payload['data']);$source['meta']=self::json($payload['meta']);
        $resolved=DecorateDataSourceService::applyToPage($source,['tenant_id'=>$tenant,'user_id'=>0,'terminal'=>'mobile','preview'=>true]);
        $page['resolved_sources']=$resolved['resolved_sources']??[];return $page;
    }
    public static function candidate(int $tenant,int $admin,int $id): array
    {$r=self::owned('candidate',$tenant,$admin,$id);$p=self::decode($r['payload_json']);$s=self::owned('session',$tenant,$admin,(int)$r['session_id']);$page=self::page($tenant,(int)$s['template_id'],(int)$s['page_id']);if($p['new_page']??false)$page['page_code']='';$p['resolved_sources']=self::resolvedPage($tenant,$page,$p)['resolved_sources'];return $p+['base_data'=>self::decode($r['base_json'])['data']??[],'base_meta'=>self::decode($r['base_json'])['meta']??[],'id'=>$id,'revision'=>(int)$r['revision'],'status'=>$r['status'],'base_hash'=>$r['base_hash'],'changes'=>self::decode($r['changes_json']),'image_slots'=>self::slots($tenant,$admin,(int)$r['run_id'],$id)];}
    public static function discard(int $tenant,int $admin,int $id): array
    {$r=self::owned('candidate',$tenant,$admin,$id);if($r['status']==='applied')throw new RuntimeException('已应用的候选请通过撤销恢复');self::q('candidate',$tenant,$admin)->where('id',$id)->update(['status'=>'discarded']);self::q('session',$tenant,$admin)->where('id',$r['session_id'])->where('active_candidate_id',$id)->update(['active_candidate_id'=>0]);return ['id'=>$id,'status'=>'discarded'];}
    private static function conflictMessage(array $conflicts,array $current,array $candidate): string
    {
        $nodes=DecorationAgentOperations::index($current['data']??[])+DecorationAgentOperations::index($candidate['data']??[]);$labels=[];
        foreach($conflicts as $conflict){$widget=$nodes[(string)($conflict['widget_id']??'')]['widget']??null;$label='页面';$field='配置';if($widget){try{$definition=DecorationAgentCatalog::definition($widget['name']);$label=$definition['title'];$path=explode('.',(string)($conflict['field']??''));$schema=$definition[$path[0]==='styles'?'style_fields':'content_fields'][$path[1]??'']??[];$field=$schema['label']??'组件排列或内容';}catch(\Throwable $e){$label=(string)($widget['title']??'组件');}}$labels[]=$label.'（'.$field.'）';}
        return '草稿修改冲突：'.implode('、',array_unique($labels)).'。请基于最新草稿重新生成方案。';
    }
    public static function apply(int $tenant,int $admin,array $p): array
    {
        $id=(int)($p['candidate_id']??0);$key=(string)($p['idempotency_key']??'');if($key===''||strlen($key)>100)throw new RuntimeException('应用标识无效');
        return Db::transaction(function()use($tenant,$admin,$p,$id,$key){
            $old=self::q('checkpoint',$tenant,$admin)->where('idempotency_key',$key)->find();if($old){$s=self::owned('session',$tenant,$admin,(int)$old['session_id']);$page=self::page($tenant,(int)$s['template_id'],(int)$old['page_id']);return ['page'=>self::resolvedPage($tenant,$page),'checkpoint_id'=>$old['id'],'current_hash'=>self::hash(self::payload($page))];}
            $c=self::q('candidate',$tenant,$admin)->where('id',$id)->lock(true)->find();if(!$c||!in_array($c['status'],['ready','waiting_images','applied','rolled_back'],true))throw new RuntimeException('候选不可应用');
            if(isset($p['revision'])&&(int)$p['revision']!==(int)$c['revision'])throw new RuntimeException('候选已更新，请重新预览');
            $old=self::q('checkpoint',$tenant,$admin)->where('idempotency_key',$key)->lock(true)->find();if($old){$ss=self::owned('session',$tenant,$admin,(int)$old['session_id']);$pp=self::page($tenant,(int)$ss['template_id'],(int)$old['page_id']);return ['page'=>self::resolvedPage($tenant,$pp),'checkpoint_id'=>$old['id'],'current_hash'=>self::hash(self::payload($pp))];}$s=self::owned('session',$tenant,$admin,(int)$c['session_id']);$target=self::decode($c['payload_json']);$new=($target['new_page']??false)&&!(int)$c['applied_page_id'];
            $page=self::page($tenant,(int)$s['template_id'],(int)($c['applied_page_id']?:$s['page_id']),true);$current=self::payload($page);
            if(!empty($p['expected_hash'])&&$p['expected_hash']!==self::hash($current))throw new RuntimeException('页面已经变化，请刷新后重新应用');
            $before=$new?['origin_page_id'=>(int)$s['page_id']]:$current;
            if($new){$page=DecorateTemplateService::addPage($tenant,['template_id'=>$s['template_id'],'terminal'=>'mobile','name'=>$target['name'],'page_code'=>'ai_'.bin2hex(random_bytes(6))]);}
            else{$base=self::decode($c['base_json']);$base['new_page']=false;$target['new_page']=false;$merge=DecorationAgentMerge::merge($base,$current,$target);if($merge['conflicts'])throw new RuntimeException(self::conflictMessage($merge['conflicts'],$current,$target));$target=$merge['merged'];}
            $target['new_page']=false;
            $saved=DecorateTemplateService::savePage($tenant,['template_id'=>$s['template_id'],'id'=>$page['id'],'terminal'=>'mobile','name'=>$target['name'],'data'=>self::json($target['data']),'meta'=>self::json($target['meta'])]);
            $after=self::payload($saved);
            $checkpoint=self::insert('checkpoint',$tenant,$admin,['session_id'=>$s['id'],'candidate_id'=>$id,'page_id'=>$page['id'],'kind'=>$new?'create':'apply','before_json'=>self::json($before),'after_json'=>self::json($after),'before_hash'=>self::hash($before),'after_hash'=>self::hash($after),'idempotency_key'=>$key]);
            self::q('candidate',$tenant,$admin)->where('id',$id)->update(['status'=>'applied','applied_page_id'=>$page['id']]);self::q('session',$tenant,$admin)->where('id',$s['id'])->update(['page_id'=>$page['id'],'active_candidate_id'=>0]);
            return ['page'=>self::resolvedPage($tenant,$saved),'checkpoint_id'=>$checkpoint,'candidate_id'=>$id,'current_hash'=>self::hash($after)];
        });
    }
    public static function rollback(int $tenant,int $admin,array $p): array
    {
        $key=(string)($p['idempotency_key']??('rollback:'.(int)($p['checkpoint_id']??0)));if(strlen($key)>100)throw new RuntimeException('恢复标识无效');
        return Db::transaction(function()use($tenant,$admin,$p,$key){
            $c=self::q('checkpoint',$tenant,$admin)->where('id',(int)($p['checkpoint_id']??0))->lock(true)->find();if(!$c)throw new RuntimeException('检查点不存在');
            if(!in_array($c['kind'],['apply','create','image'],true))throw new RuntimeException('该检查点不能直接恢复，请通过候选重新应用');
            $s=self::owned('session',$tenant,$admin,(int)$c['session_id']);
            $old=self::q('checkpoint',$tenant,$admin)->where('idempotency_key',$key)->lock(true)->find();
            if($old){$page=self::page($tenant,(int)$s['template_id'],(int)$old['page_id']);return ['page'=>self::resolvedPage($tenant,$page),'checkpoint_id'=>$old['id'],'current_hash'=>self::hash(self::payload($page)),'deleted'=>$old['kind']==='undo_create','deleted_page_id'=>$old['kind']==='undo_create'?$c['page_id']:0];}
            $page=self::page($tenant,(int)$s['template_id'],(int)$c['page_id'],true);$current=self::payload($page);$before=self::decode($c['before_json']);$after=self::decode($c['after_json']);
            $deleted=false;
            if($c['kind']==='create'){
                if(self::hash($current)!==$c['after_hash'])throw new RuntimeException('新页面已修改，不能直接撤销创建');
                DecorateTemplateService::deletePage($tenant,(int)$page['id']);$origin=(int)($before['origin_page_id']??0);$saved=self::page($tenant,(int)$s['template_id'],$origin);$deleted=true;
                self::q('session',$tenant,$admin)->where('id',$s['id'])->update(['page_id'=>$origin]);
            }else{
                $merge=DecorationAgentMerge::merge($after,$current,$before);if($merge['conflicts'])throw new RuntimeException(self::conflictMessage($merge['conflicts'],$current,$before));$target=$merge['merged'];
                $saved=DecorateTemplateService::savePage($tenant,['template_id'=>$s['template_id'],'id'=>$page['id'],'terminal'=>'mobile','name'=>$target['name'],'data'=>self::json($target['data']),'meta'=>self::json($target['meta'])]);
            }
            self::q('candidate',$tenant,$admin)->where('id',$c['candidate_id'])->update(['status'=>'rolled_back','applied_page_id'=>$deleted?0:$page['id']]);
            self::q('session',$tenant,$admin)->where('id',$s['id'])->update(['active_candidate_id'=>0]);
            $record=self::insert('checkpoint',$tenant,$admin,['session_id'=>$s['id'],'candidate_id'=>$c['candidate_id'],'page_id'=>$saved['id'],'kind'=>$deleted?'undo_create':'rollback','before_json'=>self::json($current),'after_json'=>self::json(self::payload($saved)),'before_hash'=>self::hash($current),'after_hash'=>self::hash(self::payload($saved)),'idempotency_key'=>$key]);
            return ['page'=>self::resolvedPage($tenant,$saved),'deleted'=>$deleted,'deleted_page_id'=>$deleted?$page['id']:0,'checkpoint_id'=>$record,'current_hash'=>self::hash(self::payload($saved))];
        });
    }
    public static function retryImage(int $tenant,int $admin,array $p): array
    {
        self::assertEnabled();return Db::transaction(function()use($tenant,$admin,$p){
            $slot=self::q('image_slot',$tenant,$admin)->where('id',(int)($p['slot_id']??0))->lock(true)->find();if(!$slot)throw new RuntimeException('图片位置不存在');
            $next=self::q('image_slot',$tenant,$admin)->where(['run_id'=>$slot['run_id'],'slot_key'=>$slot['slot_key'],'attempt'=>(int)$slot['attempt']+1])->lock(true)->find();if($next)return ['slot_id'=>$next['id']];
            if($slot['status']!=='failed'&&!($slot['status']==='success'&&($p['regenerate']??false)===true))throw new RuntimeException('只有确定失败的图片允许重试；成功图片需明确选择重新生成');if(self::q('image_slot',$tenant,$admin)->where('run_id',$slot['run_id'])->count()>=5)throw new RuntimeException('本轮图片次数已达上限，请新建一轮继续');
            $requestJson=$slot['request_json'];
            if($slot['status']==='success'){$candidate=self::owned('candidate',$tenant,$admin,(int)$slot['candidate_id']);if($candidate['status']==='discarded')throw new RuntimeException('已舍弃的候选不能重新生成图片');$payload=self::decode($candidate['payload_json']);$request=self::decode($requestJson);$nodes=DecorationAgentOperations::index($payload['data']);$node=$nodes[$request['widget_id']]??null;if(!$node)throw new RuntimeException('图片组件已删除，请新建一轮调整');$request['old_value']=DecorationAgentOperations::get($node['widget'],$request['field']);$requestJson=self::json($request);}
            $id=self::insert('image_slot',$tenant,$admin,['session_id'=>$slot['session_id'],'run_id'=>$slot['run_id'],'candidate_id'=>$slot['candidate_id'],'slot_key'=>$slot['slot_key'],'request_json'=>$requestJson,'result_json'=>'{}','attempt'=>(int)$slot['attempt']+1]);
            self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->update(['status'=>$slot['status']==='success'?'regenerated':'retried']);self::q('run',$tenant,$admin)->where('id',$slot['run_id'])->update(['status'=>'waiting_images','cancel_requested'=>0,'next_run_time'=>time()]);return ['slot_id'=>$id];
        });
    }
    public static function replaceImage(int $tenant,int $admin,array $p): array
    {
        $slot=self::owned('image_slot',$tenant,$admin,(int)$p['slot_id']);$a=DecorationAgentMediaService::validateAttachments($tenant,[['file_id'=>(int)$p['file_id'],'uses'=>['asset']]]);$c=self::candidate($tenant,$admin,(int)$slot['candidate_id']);$request=self::decode($slot['request_json']);$map=DecorationAgentOperations::index($c['data']);if(!isset($map[$request['widget_id']]))throw new RuntimeException('图片组件已删除');$request['old_value']=DecorationAgentOperations::get($map[$request['widget_id']]['widget'],$request['field']);$slot['request_json']=self::json($request);self::hydrate($slot,(string)$a[0]['url']);self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->update(['status'=>'success','result_json'=>self::json(['images'=>$a])]);return self::candidate($tenant,$admin,(int)$slot['candidate_id']);
    }
    public static function tick(int $tenant=0): int
    {
        if(!self::enabled())return 0;
        $q=Db::name('decorate_agent_run')->whereIn('status',['queued','running','waiting_images'])->where('next_run_time','<=',time())->where('lease_expire_time','<',time());if($tenant>0)$q->where('tenant_id',$tenant);
        $ids=$q->order('id')->limit(10)->column('id');$count=0;
        foreach($ids as $id){$token=bin2hex(random_bytes(16));$claimed=Db::name('decorate_agent_run')->where('id',$id)->where('lease_expire_time','<',time())->whereIn('status',['queued','running','waiting_images'])->update(['lease_token'=>$token,'lease_expire_time'=>time()+600]);if(!$claimed)continue;
            self::$leases[(int)$id]=$token;try{self::step((int)$id,$token);}catch(\Throwable $e){if(Db::name('decorate_agent_run')->where('id',$id)->where('lease_token',$token)->count())self::finish((int)$id,'failed',['error'=>mb_substr($e->getMessage(),0,1000)]);}finally{unset(self::$leases[(int)$id]);Db::name('decorate_agent_run')->where('id',$id)->where('lease_token',$token)->update(['lease_token'=>'','lease_expire_time'=>0]);}$count++;}
        return $count;
    }
    private static function saveContext(int $id,array $ctx): void {self::fence($id);Db::name('decorate_agent_run')->where('id',$id)->update(['context_json'=>self::json($ctx),'update_time'=>time()]);}
    private static function step(int $id,string $token): void
    {
        $r=Db::name('decorate_agent_run')->where('id',$id)->find();$tenant=(int)$r['tenant_id'];$admin=(int)$r['admin_id'];$input=self::decode($r['input_json']);$ctx=self::decode($r['context_json']);request()->tenantId=$tenant;$input['attachments']=DecorationAgentMediaService::validateAttachments($tenant,$input['attachments']);
        self::assertOperator($tenant,$admin,$input['scope']);
        if((int)$r['cancel_requested']){self::finish($id,'canceled',['summary'=>'后续执行已停止']);return;}
        if($r['status']==='waiting_images'){self::processImages($r,$input);return;}
        if($ctx['calling']??false){self::finish($id,'needs_review',['error'=>'上次模型调用结果待核实，禁止自动重复提交']);return;}
        if((int)$r['model_calls']>=6){self::finish($id,'limited',['error'=>'已达到本轮模型调用上限，请分轮继续']);return;}
        $s=self::owned('session',$tenant,$admin,(int)$r['session_id']);
        $links=self::availableLinks($tenant,(int)$s['template_id']);$sources=DecorateDataSourceService::catalog($tenant);
        $recent=self::q('message',$tenant,$admin)->where('session_id',$s['id'])->order('id','desc')->limit(16)->select()->toArray();$recent=array_reverse($recent);
        $catalog=self::compactCatalog();$details=[];$nodes=DecorationAgentOperations::index($ctx['candidate']['data']);$selected=$nodes[$input['selected_id']]??null;if($selected)$details[]=DecorationAgentCatalog::definition($selected['widget']['name']);
        foreach($catalog as $item){$name=$item['name']??'';if($name&&((!empty($item['title'])&&str_contains($input['message'],$item['title']))||in_array($name,['news','banner','nav'],true))){$details[]=DecorationAgentCatalog::definition($name);if(count($details)>=8)break;}}
        $context=['scope'=>$input['scope'],'selected_id'=>$input['selected_id'],'page'=>$ctx['candidate'],'component_index'=>self::componentIndex($ctx['candidate']['data']),'component_catalog'=>$catalog,'related_contracts'=>$details,'page_meta_contract'=>DecorationAgentCatalog::pageMeta(),'links'=>$links,'data_sources'=>$sources,'image_sources'=>$input['sources'],'image_strategy'=>$input['image_strategy'],'attachments'=>$input['attachments'],'facts'=>$ctx['facts'],'conversation'=>array_map(fn($m)=>['role'=>$m['role'],'content'=>$m['content']],$recent),'tool_results'=>$ctx['observations']];
        $content=self::json($context)."\n用户需求：".$input['message'];$model=MarketTextModelRuntimeService::resolveModel($tenant,$input['model_selection'],$input['requires_vision']);$spent=self::spent($r);
        if($spent+self::textEstimate($model,$content.DecorationAgentDecision::prompt().str_repeat('v',count(array_filter($input['attachments'],fn($a)=>array_intersect($a['uses'],['layout','style']))) * 6000))>$input['budget_points']){self::finish($id,'budget_exceeded',['error'=>'本轮预算不足以继续，请提高预算后发起新一轮']);return;}
        $references=DecorationAgentMediaService::visionReferences($tenant,$input['attachments']);
        Db::name('decorate_agent_run')->where('id',$id)->update(['status'=>'running','model_calls'=>(int)$r['model_calls']+1,'spent_points'=>$spent]);$ctx['calling']=true;self::saveContext($id,$ctx);self::event($id,'model',['message'=>'理解需求并安排组件','step'=>(int)$r['model_calls']+1]);
        $provider=function()use($tenant,$id,$input,$content,$references,&$ctx){return MarketTextModelRuntimeService::generate($tenant,0,['content'=>$content,'reference_images'=>$references,'model_selection'=>$input['model_selection'],'system_prompt'=>DecorationAgentDecision::prompt(),'max_tokens'=>4096,'request_timeout_seconds'=>180,'action_code'=>'decoration_agent_text','source_app_code'=>'system_default','business_table'=>'decorate_agent_run','business_id'=>$id,'_disable_model_fallback'=>true,'_disable_transient_retry'=>true,'_disable_compatibility_retry'=>true,'_decoration_preserve_uncertain'=>true],function($event,$data)use($id,&$ctx){if($event==='app_task'){self::fence($id);$ctx['receipts'][]=$data;self::saveContext($id,$ctx);}},true);};
        try{$workflow=new Workflow('decoration:'.$id.':'.((int)$r['model_calls']+1));$workflow->setMaxSteps(1)->addNode(new DecorationAgentDecisionNode($provider));$result=$workflow->run()->get('result');}
        catch(\Throwable $e){$latest=end($ctx['receipts']);$receipt=!empty($latest['consumption_id'])?AiConsumptionLog::where('id',$latest['consumption_id'])->find():null;$known=$receipt&&$receipt['run_status']==='failed'&&$receipt['billing_status']==='refunded';if($known){$ctx['calling']=false;self::saveContext($id,$ctx);}self::finish($id,$known?'failed':'needs_review',['error'=>'模型调用未完成：'.mb_substr($e->getMessage(),0,800)]);return;}
        $ctx['calling']=false;$ctx['last_result']=$result;self::fence($id);if(in_array($result['billing']['billing_status']??'', ['pending_usage','pending_confirmation'],true)){self::saveContext($id,$ctx);self::finish($id,'needs_review',['error'=>'模型结果费用待核实，已停止后续调用']);return;}self::saveContext($id,$ctx);
        try{$decision=DecorationAgentDecision::parse((string)($result['content']??''));}catch(\InvalidArgumentException $e){$ctx['observations'][]=['error'=>$e->getMessage()];self::saveContext($id,$ctx);return;}
        $ctx['facts']=array_slice(array_merge($ctx['facts'],$decision['facts']),-40);
        self::q('session',$tenant,$admin)->where('id',$s['id'])->update(['facts'=>self::json($ctx['facts'])]);
        if($decision['kind']==='clarify'){self::saveContext($id,$ctx);self::finish($id,'awaiting_input',['question'=>$decision['question']]);return;}
        $ops=$decision['operations'];$count=(int)$r['tool_calls'];$imageRequests=[];
        foreach($ops as $op){foreach(['widget_id','parent_id'] as $targetKey)if(isset($op[$targetKey])&&str_starts_with((string)$op[$targetKey],'@')){$alias=substr($op[$targetKey],1);if(!isset($ctx['refs'][$alias]))throw new RuntimeException('本轮组件引用不存在');$op[$targetKey]=$ctx['refs'][$alias];}if(++$count>20){self::finish($id,'limited',['error'=>'已达到本轮工具次数上限']);return;}if(Db::name('decorate_agent_run')->where('id',$id)->value('cancel_requested')){self::finish($id,'canceled',[]);return;}
            try{$query=self::queryTool($tenant,$admin,$s,$input,$ctx,$op,$links,$sources);if($query!==null){$ctx['observations'][]=['tool'=>$op['type'],'result'=>$query];if($op['type']==='search_materials')$ctx['approved_material_urls']=array_values(array_unique(array_merge($ctx['approved_material_urls']??[],array_column($query,'url'))));}else{$scope=['scope'=>$input['scope'],'selected_id'=>$input['selected_id'],'allowed_links'=>$links,'approved_urls'=>array_merge(self::explicitUrls($input['message'],$input['attachments']),$ctx['approved_material_urls']??[])];$applied=DecorationAgentOperations::apply($ctx['candidate'],[$op],$scope);$ctx['candidate']=$applied['candidate'];if($op['type']==='add_component'&&!empty($op['ref'])){$last=end($applied['changes']);$ctx['refs'][(string)$op['ref']]=$last['widget_id'];}$ctx['changes']=array_merge($ctx['changes'],$applied['changes']);$imageRequests=array_merge($imageRequests,$applied['image_requests']);}$ctx['failures'][self::json($op)]=0;}
            catch(\Throwable $e){$key=self::json($op);$ctx['failures'][$key]=($ctx['failures'][$key]??0)+1;$ctx['observations'][]=['tool'=>$op['type'],'error'=>$e->getMessage()];if($ctx['failures'][$key]>1){self::saveContext($id,$ctx);self::finish($id,'failed',['error'=>'重复操作失败：'.$e->getMessage()]);return;}$decision['kind']='tools';}
            self::saveContext($id,$ctx);Db::name('decorate_agent_run')->where('id',$id)->update(['tool_calls'=>$count]);self::event($id,'tool',['message'=>'已处理装修操作','tool'=>$op['type']]);
        }
        $r=Db::name('decorate_agent_run')->where('id',$id)->find();$cId=(int)$r['candidate_id'];
        if(!$cId){$cId=self::insert('candidate',$tenant,$admin,['session_id'=>$s['id'],'run_id'=>$id,'base_hash'=>self::hash($ctx['base']),'base_json'=>self::json($ctx['base']),'status'=>'building','payload_json'=>self::json($ctx['candidate']),'changes_json'=>self::json($ctx['changes'])]);Db::name('decorate_agent_run')->where('id',$id)->update(['candidate_id'=>$cId]);}
        else{self::q('candidate',$tenant,$admin)->where('id',$cId)->inc('revision')->update(['payload_json'=>self::json($ctx['candidate']),'changes_json'=>self::json($ctx['changes'])]);}
        foreach($imageRequests as $req){if(!in_array('generate',$input['sources'],true)){$ctx['observations'][]=['error'=>'未授权AI生图'];$decision['kind']='tools';continue;}if(self::q('image_slot',$tenant,$admin)->where('run_id',$id)->count()>=5){$ctx['observations'][]=['error'=>'已达到五张图片上限'];continue;}$key=(string)$req['widget_id'].':'.(string)$req['field'];if(self::q('image_slot',$tenant,$admin)->where(['run_id'=>$id,'slot_key'=>$key])->whereIn('status',['queued','running','submitting'])->count())continue;self::insert('image_slot',$tenant,$admin,['session_id'=>$s['id'],'run_id'=>$id,'candidate_id'=>$cId,'slot_key'=>$key,'request_json'=>self::json($req),'result_json'=>'{}']);}
        self::saveContext($id,$ctx);self::q('session',$tenant,$admin)->where('id',$s['id'])->update(['active_candidate_id'=>$cId,'facts'=>self::json($ctx['facts'])]);
        if($decision['kind']==='final'){$summary=count($ctx['changes']).'项装修调整已生成，请预览后应用。';$pending=self::q('image_slot',$tenant,$admin)->where('run_id',$id)->whereIn('status',['queued','running'])->count();self::q('candidate',$tenant,$admin)->where('id',$cId)->update(['status'=>$pending?'waiting_images':'ready']);self::finish($id,$pending?'waiting_images':'completed',['summary'=>$summary,'warnings'=>$decision['warnings']]);}
    }
    private static function explicitUrls(string $message,array $attachments): array
    {preg_match_all('~https?://[^\s<>"\x{3000}-\x{9fff}]+~u',$message,$m);return array_values(array_unique(array_merge($m[0],array_column(array_filter($attachments,fn($a)=>in_array('asset',$a['uses']??[],true)),'url'))));}
    private static function queryTool(int $tenant,int $admin,array $s,array $input,array $ctx,array $op,array $links,array $sources): ?array
    {
        return match($op['type']){
            'get_context'=>['page'=>$ctx['candidate'],'component_index'=>self::componentIndex($ctx['candidate']['data']),'selected_id'=>$input['selected_id']],
            'catalog'=>empty($op['name'])?self::compactCatalog():DecorationAgentCatalog::definition((string)$op['name']),
            'data_sources'=>empty($op['key'])?$sources: ['key'=>$op['key'],'items'=>DecorateDataSourceService::resolve((string)$op['key'],array_intersect_key((array)($op['params']??[]),array_flip(['limit','sort','terminal'])),$tenant,['user_id'=>0,'terminal'=>'uniapp'])],'links'=>$links,
            'search_materials'=>DecorationAgentMediaService::search($tenant,(string)($op['query']??''),$input['sources'],$input['attachments'],min(12,(int)($op['limit']??12))),
            'icons'=>[
                ['component'=>'button-group','field'=>'content.data.*.icon','mode_fields'=>['content.button_type'=>'icon'],'options'=>[['value'=>'⌕','label'=>'搜索'],['value'=>'▤','label'=>'资讯'],['value'=>'▧','label'=>'图片'],['value'=>'★','label'=>'收藏'],['value'=>'◉','label'=>'客服']]],
                ['component'=>'floating-action','field'=>'content.data.*.icon','mode_fields'=>['content.data.*.icon_mode'=>'system'],'options'=>[['value'=>'auto','label'=>'跟随操作'],['value'=>'link','label'=>'跳转'],['value'=>'back_top','label'=>'回到顶部'],['value'=>'service','label'=>'客服'],['value'=>'phone','label'=>'电话'],['value'=>'home','label'=>'首页'],['value'=>'star','label'=>'收藏'],['value'=>'message','label'=>'消息'],['value'=>'none','label'=>'无图标']]],
                ['component'=>'nav','field'=>'content.data.*.image','description'=>'使用素材图片；不支持桌面Element图标名称']],
            'validate'=>DecorationAgentOperations::apply($ctx['candidate'],[],['scope'=>$input['scope'],'selected_id'=>$input['selected_id'],'allowed_links'=>$links,'approved_urls'=>array_merge(self::explicitUrls($input['message'],$input['attachments']),$ctx['approved_material_urls']??[])]),
            default=>null};
    }
    private static function spent(array $run): float
    {
        $sum=0;$ctx=self::decode($run['context_json']);foreach($ctx['receipts']??[] as $receipt){$c=AiConsumptionLog::where('id',(int)($receipt['consumption_id']??0))->find();if($c)$sum+=(float)$c['actual_tenant_cost'];}
        foreach(Db::name('decorate_agent_image_slot')->where('run_id',$run['id'])->where('consumption_id','>',0)->column('consumption_id') as $id){$c=AiConsumptionLog::where('id',$id)->find();if($c)$sum+=$c['billing_status']==='refunded'?0.0:($c['billing_status']==='settled'?(float)$c['actual_tenant_cost']:(float)$c['reserved_tenant_cost']);}return $sum;
    }
    private static function finish(int $id,string $status,array $result): void
    {
        $r=Db::name('decorate_agent_run')->where('id',$id)->find();if(!$r)return;self::fence($id);
        Db::name('decorate_agent_run')->where('id',$id)->update(['status'=>$status,'result_json'=>self::json($result),'spent_points'=>self::spent($r),'next_run_time'=>time()+($status==='waiting_images'?3:0),'update_time'=>time()]);
        self::event($id,$status,$result);
        if(in_array($status,['completed','awaiting_input','failed','limited','budget_exceeded','needs_review','canceled'],true))self::insert('message',(int)$r['tenant_id'],(int)$r['admin_id'],['session_id'=>$r['session_id'],'run_id'=>$id,'role'=>'assistant','content'=>(string)($result['summary']??$result['question']??$result['error']??'执行已结束'),'attachments'=>'[]']);
    }
    private static function processImages(array $r,array $input): void
    {
        $tenant=(int)$r['tenant_id'];$admin=(int)$r['admin_id'];$pending=0;$uncertain=false;
        foreach(self::slots($tenant,$admin,(int)$r['id']) as $slot){if(!in_array($slot['status'],['queued','running','submitting'],true))continue;$request=$slot['request'];$request['attachments']=$input['attachments'];self::assertOperator($tenant,$admin,$input['scope']);$request['reference_file_ids']=(array)($request['reference_file_ids']??[]);$request['reference_images']=[];self::fence((int)$r['id']);if(Db::name('decorate_agent_run')->where('id',$r['id'])->value('cancel_requested')){self::finish((int)$r['id'],'canceled',[]);return;}
            foreach($input['attachments'] as $a)if(in_array($a['file_id'],(array)($request['reference_file_ids']??[]),true)||in_array('generation',$a['uses'],true))$request['reference_file_ids'][]=$a['file_id'];$request['reference_file_ids']=array_values(array_unique($request['reference_file_ids']));
            try{
                if($slot['status']==='submitting'){$uncertain=true;continue;}
                if($slot['status']==='queued'){
                    $quote=DecorationAgentMediaService::quote($tenant,$input['image_model_selection'],1,$request);if(($quote['cost_known']??true)===false)throw new RuntimeException('该图片规格按实际用量计费，无法在本轮预算内预估，请选择按次规格');$cost=(float)($quote['tenant_cost_points']??$quote['tenant_cost']??0);
                    if(self::spent($r)+$cost>$input['budget_points'])throw new RuntimeException('图片费用超过本轮剩余预算');
                    $receipt=Db::transaction(function()use($tenant,$admin,$slot,$r,$input,$request){
                        self::fence((int)$r['id']);$locked=self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->lock(true)->find();if($locked['status']!=='queued')throw new RuntimeException('图片任务已被处理');
                        if(Db::name('decorate_agent_run')->where('id',$r['id'])->value('cancel_requested'))throw new RuntimeException('任务已停止');
                        $receipt=DecorationAgentMediaService::reserve($tenant,$admin,(int)$slot['id'],$input['image_model_selection'],$request);
                        self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->update(['consumption_id'=>$receipt['consumption_id'],'status'=>'submitting']);return $receipt;
                    });
                    self::fence((int)$r['id']);if(Db::name('decorate_agent_run')->where('id',$r['id'])->value('cancel_requested')){\app\common\service\power\MarketImageModelRuntimeService::cancel((int)$receipt['consumption_id']);self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->update(['status'=>'canceled']);self::finish((int)$r['id'],'canceled',[]);return;}
                    $result=DecorationAgentMediaService::submit((int)$receipt['consumption_id'],$request);
                }else{$result=DecorationAgentMediaService::poll($tenant,(int)$slot['consumption_id']);}
                $status=$result['status']??'running';self::fence((int)$r['id']);if(in_array($status,['pending_confirmation','needs_review','unknown'],true)){$uncertain=true;self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->update(['status'=>'needs_review','result_json'=>self::json($result)]);continue;}
                if($status==='success'){$images=$result['images']??[];$url=(string)($images[0]['url']??$images[0]['image_uri']??$images[0]['uri']??'');if($url==='')throw new RuntimeException('图片结果为空');self::hydrate($slot,$url);self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->update(['status'=>'success','result_json'=>self::json($result)]);self::event((int)$r['id'],'image',['message'=>'配图已完成','slot_id'=>$slot['id']]);}
                elseif(in_array($status,['failed','canceled'],true))throw new RuntimeException((string)($result['error']??'图片生成失败'));
                else{$pending++;self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->update(['status'=>'running','result_json'=>self::json($result)]);}
            }catch(\Throwable $e){self::fence((int)$r['id']);$current=self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->find();$billing=!empty($current['consumption_id'])?AiConsumptionLog::where('id',$current['consumption_id'])->find():null;$ambiguous=($current['status']??'')==='submitting'||($billing&&$billing['billing_status']==='settled');if($ambiguous)$uncertain=true;self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->update(['status'=>$ambiguous?'needs_review':'failed','error'=>mb_substr($e->getMessage(),0,1000)]);}
        }
        if($uncertain){self::finish((int)$r['id'],'needs_review',['error'=>'部分图片提交结果待核实，不自动重复生成']);return;}
        if(!$pending){self::q('candidate',$tenant,$admin)->where('id',$r['candidate_id'])->where('status','waiting_images')->update(['status'=>'ready']);self::finish((int)$r['id'],'completed',['summary'=>'候选页面已生成，失败图片可单独重试']);}
        else Db::name('decorate_agent_run')->where('id',$r['id'])->update(['next_run_time'=>time()+3,'spent_points'=>self::spent($r)]);
    }
    private static function hydrate(array $slot,string $url): void
    {
        $tenant=(int)$slot['tenant_id'];$admin=(int)$slot['admin_id'];$request=self::decode($slot['request_json']??$slot['request']??[]);
        Db::transaction(function()use($tenant,$admin,$slot,$request,$url){$c=self::q('candidate',$tenant,$admin)->where('id',$slot['candidate_id'])->lock(true)->find();if(!$c||$c['status']==='discarded')return;
            $payload=self::decode($c['payload_json']);$index=DecorationAgentOperations::index($payload['data']);$node=$index[$request['widget_id']]??null;if(!$node||DecorationAgentOperations::get($node['widget'],$request['field'])!==($request['old_value']??null)){self::event((int)$slot['run_id'],'image_detached',['message'=>'图片位置已被修改，素材已保留']);return;}if(($request['old_value']??null)===$url)return;$op=['type'=>'update_component','widget_id'=>$request['widget_id'],'fields'=>[$request['field']=>$url]];$new=DecorationAgentOperations::apply($payload,[$op],['scope'=>'page','approved_urls'=>[$url],'allowed_links'=>[]]);
            $old=$payload;self::q('candidate',$tenant,$admin)->where('id',$c['id'])->inc('revision')->update(['payload_json'=>self::json($new['candidate'])]);
            if($c['status']==='applied'){$s=self::owned('session',$tenant,$admin,(int)$c['session_id']);$page=self::page($tenant,(int)$s['template_id'],(int)$c['applied_page_id'],true);$current=self::payload($page);$old['new_page']=false;$target=$new['candidate'];$target['new_page']=false;$merge=DecorationAgentMerge::merge($old,$current,$target);if($merge['conflicts'])return;
                $saved=DecorateTemplateService::savePage($tenant,['template_id'=>$s['template_id'],'id'=>$page['id'],'terminal'=>'mobile','data'=>self::json($merge['merged']['data']),'meta'=>self::json($merge['merged']['meta'])]);self::insert('checkpoint',$tenant,$admin,['session_id'=>$s['id'],'candidate_id'=>$c['id'],'page_id'=>$page['id'],'kind'=>'image','before_json'=>self::json($current),'after_json'=>self::json(self::payload($saved)),'before_hash'=>self::hash($current),'after_hash'=>self::hash(self::payload($saved)),'idempotency_key'=>'image:'.$slot['id'].':'.hash('sha256',$url)]);
                $group=self::q('checkpoint',$tenant,$admin)->where(['candidate_id'=>$c['id'],'page_id'=>$page['id']])->whereIn('kind',['apply','create'])->order('id','desc')->lock(true)->find();
                if($group){$groupAfter=self::decode($group['after_json']);$advanced=DecorationAgentOperations::apply($groupAfter,[$op],['scope'=>'page','approved_urls'=>[$url],'allowed_links'=>[]])['candidate'];self::q('checkpoint',$tenant,$admin)->where('id',$group['id'])->update(['after_json'=>self::json($advanced),'after_hash'=>self::hash($advanced)]);}
            }
        });
    }
}
