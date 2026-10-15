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
    private static function visualReviewContext(array $context): ?array
    {
        if(empty($context['visual_review']))return null;
        $design=$context['approved_design']??[];$review=$context['visual_review'];$logicalWidth=375;
        $dimensions=static function(array $values)use($logicalWidth):array {
            $width=max(0,(int)($values['width']??0));$height=max(0,(int)($values['height']??0));
            return ['width_px'=>$width?:null,'height_px'=>$height?:null,'to_logical_px_scale'=>$width>0?$logicalWidth/$width:null];
        };
        return ['priority'=>'最新冻结批准图片优先于旧蓝图/默认值/历史提案；旧蓝图不是视觉批准规范。真实契约、权限及来源限制保持优先。',
            'target'=>array_intersect_key($design,array_flip(['run_id','revision','hash','slot_id'])),
            'server_validation_contract'=>[
                'candidate_binding'=>['field'=>'candidate_hash','object'=>'完整候选payload（含_decoration_design设计绑定）','checks'=>'服务端校验tenant/admin/session、candidate_id、revision及完整候选hash；入队和模型调用前校验，完成与应用时再次重验。'],
                'draft_concurrency'=>['field'=>'expected_hash','object'=>'当前未应用的页面草稿','comparison'=>'仅与当前草稿hash比较以防并发变化，不与candidate_hash比较；候选与草稿是不同对象，两个hash正常可以不同。'],
                'capture_evidence'=>['meaning'=>'capture_attested=false表示客户端预览截图没有服务器DOM来源证明，不表示PNG无效，也不是必须改为true的通过条件。','validated'=>'服务端校验ADMIN文件归属、实际PNG字节/尺寸/hash、区别于设计原图，并在后续重验。','limit'=>'仅支持对所提供两图的模型视觉对照与服务器交互配置检查；不证明真实DOM、真实点击、登录或业务调用。'],
                'model_scope'=>'按实际两图给六类具体证据，不能以两个不同对象hash不等或缺少DOM attestation自造拒绝条件；可见差异仍须判未通过，写入后仍需新截图，最终版本/费用/应用许可由服务端硬校验决定。',
            ],
            'image_dimensions'=>['logical_width_px'=>$logicalWidth,'approved_design'=>$dimensions($design['asset']??[]),'actual_capture'=>$dimensions(['width'=>$review['screenshot_width']??0,'height'=>$review['screenshot_height']??0]),'units'=>'尺寸是实际图片像素，乘各自scale后比较逻辑px；375逻辑宽下1rpx=0.5px，px字段不按rpx解释。'],
            'region_checks'=>['按图片辨认页头、主视觉、入口卡、标题及内容区的target/actual几何边界、宽高和间距，估算需明确标注。','区分区块标题、卡标题、正文/标签，比较可见字号、字重、行高、基线与换行。','比较图标可见bbox、内边距、形状及材质，不能仅检查icon_size外壳参数。','比较图片比例、主体位置、构图与裁切及卡片内图文排列；需要独立元素时按批准参考生成。','比较外壳/内卡背景、文字对比度、边框与阴影；只选择当前runtime_notes真正支持的参数。','任一区域有明确差异或关键项无法辨认，不能判通过；修复后需要新截图。'],
            'acceptance_rule'=>'六类具体证据；视觉五类由图对图判断，interaction仅server_contract；任何写入/生图均使本轮验收不通过。'];
    }

    private static function modelContext(int $tenant,int $admin,array $session,array $input,array &$ctx,bool $quote=false): array
    {
        $links=self::availableLinks($tenant,(int)$session['template_id']);
        $sources=DecorateDataSourceService::catalog($tenant);
        $recent=!empty($session['id'])?array_reverse(self::q('message',$tenant,$admin)->where('session_id',$session['id'])->order('id','desc')->limit(16)->select()->toArray()):[];
        if($quote){$recent[]=['role'=>'user','content'=>$input['message']];$recent=array_slice($recent,-16);}
        $icons=DecorationAgentDesignContext::icons();
        $reusable=self::reusableMaterials($tenant,$admin,$session,$input,$ctx,(int)($ctx['run_id']??0));
        $materials=DecorationAgentMediaService::search($tenant,'',$input['sources'],$input['attachments'],12);
        $materialAnchor=$ctx['approved_design']??$ctx['design']['parent_reference']??null;
        $designMaterials=[];
        if($materialAnchor){$designMaterials=DecorationAgentDesignService::approvedMaterials($tenant,$materialAnchor,$input['sources']);$materials=array_values(array_column(array_merge($designMaterials,$materials),null,'id'));$materials=array_slice($materials,0,12);}
        $ctx['approved_material_urls']=array_values(array_filter($ctx['approved_material_urls']??[],fn($url)=>!DecorationAgentAssetReferences::managedValue($url,$ctx['reusable_material_manifest'])));
        $ctx['trusted_materials']=array_values(array_filter($ctx['trusted_materials']??[],fn($material)=>!DecorationAgentAssetReferences::managedValue((string)($material['url']??''),$ctx['reusable_material_manifest'])));
        $materials=array_merge($reusable,$materials);
        $ctx['approved_material_urls']=array_values(array_unique(array_merge($ctx['approved_material_urls']??[],array_column($materials,'url'),DecorationAgentDesignContext::iconUrls($icons))));
        $ctx['trusted_materials']=array_values(array_merge($ctx['trusted_materials']??[],$materials));
        $inventory=[];
        foreach(['ai_tools','app_entries','article','image_cases','video_cases','digital_human_cases'] as $key){
            $rows=DecorateDataSourceService::resolve($key,['limit'=>50,'terminal'=>'uniapp'],$tenant,['user_id'=>0,'terminal'=>'uniapp']);
            $inventory[$key]=['count'=>count($rows),'limit'=>50,'may_have_more'=>count($rows)>=50];
        }
        if($inventory['ai_tools']['count']===0)$inventory['ai_tools']['warning']='当前没有推荐工具，推荐配置过滤保持不变；使用真实uniapp app_entries或links，不写虚构入口。';
        $inventory['article']['warning']='这是文章资讯，不是经过确认的AI课程目录；不得把普通文章包装成教程。';
        foreach(['user_profile','membership_summary','account_summary','recent_tasks','user_assets'] as $key)$inventory[$key]=['requires_login'=>true,'warning'=>'按访问页面的登录用户实时读取；后台编辑未登录用户时不补假数据。'];
        $result=[
            'visual_review_target'=>self::visualReviewContext($ctx),
            'scope'=>$input['scope'],'selected_id'=>$input['selected_id'],'page'=>$ctx['candidate'],'component_index'=>self::componentIndex($ctx['candidate']['data']),
            'component_catalog'=>self::compactCatalog(),'related_contracts'=>DecorationAgentDesignContext::related($ctx['candidate'],$input['scope'],$input['selected_id'],$input['message']),
            'shared_style_contract'=>DecorationAgentDesignContext::sharedStyles(),'page_meta_contract'=>DecorationAgentCatalog::pageMeta(),'design_guide'=>DecorationAgentDesignContext::guide(),
            'links'=>$links,'data_sources'=>$sources,'source_inventory'=>$inventory,'icon_catalog'=>DecorationAgentDesignContext::iconSummary($icons),'material_summary'=>$materials,'reusable_materials'=>$ctx['reusable_materials']??[],
            'image_sources'=>$input['sources'],'image_strategy'=>$input['image_strategy'],'generation'=>self::generationContext($tenant,$input,$ctx),
            'visual_review'=>$ctx['visual_review']??null,'vision_reference_order'=>!empty($ctx['visual_review'])?['实际候选截图','已确认设计稿']:(!empty($ctx['approved_design'])?['layout/style附件（若有）','已确认设计稿']:(!empty($ctx['design']['parent_reference'])?['layout/style附件（若有）','上一版完整设计稿']:[])),
            'design_parent'=>$ctx['design']['parent_reference']??null,'prior_blueprint'=>$ctx['design']['prior_blueprint']??[],
            'design_phase'=>$ctx['phase']??($input['design_first']??false?'design':'normal'),'approved_design'=>$ctx['approved_design']??null,'design_constraints'=>!empty($ctx['approved_design'])||($input['design_first']??false)?'设计图用于视觉对照，严禁作为整页背景或单图组件。按全46真实契约还原布局、参数、图标及素材；需截图图对图复审，未经复审不能声称1:1。':'',
            'attachments'=>$input['attachments'],'facts'=>$ctx['facts']??[],'conversation'=>array_map(fn($message)=>['role'=>$message['role'],'content'=>$message['content']],$recent),'tool_results'=>$ctx['observations']??[],
        ];
        if(empty($ctx['visual_review']))return $result;
        $current=DecorationAgentAssetReferences::freezeMaterials(self::reusePage($ctx['candidate'],$input),$tenant,$input['sources'],$input['attachments']);
        $valid=DecorationAgentAssetReferences::refreshMaterials($current,$tenant,$input['sources'],$input['attachments']);
        return DecorationAgentReviewContext::project($result,$valid,$designMaterials);
    }

    private static function generationContext(int $tenant,array $input,array $ctx): array
    {
        $allowed=in_array('generate',$input['sources'],true);$model=null;
        if($allowed)foreach(DecorationAgentMediaService::options($tenant) as $option)if((int)($option['market_product_id']??$option['id']??0)===(int)($input['image_model_selection']['market_product_id']??$input['image_model_selection']['product_id']??$input['image_model_selection']['id']??0)){$model=$option;break;}
        if($model!==null){
            $skuId=(int)($input['image_model_selection']['market_sku_id']??$input['image_model_selection']['sku_id']??0);$sku=null;
            foreach($model['skus']??[] as $item)if((int)($item['market_sku_id']??0)===$skuId||($skuId===0&&count($model['skus'])===1)){$sku=$item;break;}
            $model=array_intersect_key($model,array_flip(['market_product_id','name','model_code','quality_options','resolution_options','ratio_options','default_quality','default_ratio','supports_reference_images','max_reference_images']));
            $model['selected_sku']=$sku;
        }
        $slots=[];foreach(DecorationAgentOperations::index($ctx['candidate']['data']??[]) as $id=>$node)foreach(DecorationAgentDesignContext::contract($node['widget']['name'])['image_slots'] as $slot){
            $field=$slot['field'];if(str_contains($field,'*')){$parts=explode('.*.',$field);$list=DecorationAgentOperations::get($node['widget'],$parts[0]);foreach(is_array($list)?array_keys($list):[] as $index)$slots[]=['widget_id'=>$id]+array_replace($slot,['field'=>str_replace('*',(string)$index,$field)]);}else $slots[]=['widget_id'=>$id]+$slot;
        }
        return ['allowed'=>$allowed,'strategy'=>$input['image_strategy'],'selected_model'=>$model,'budget_points'=>$input['budget_points'],'remaining_budget_points'=>$input['remaining_budget_points']??$input['budget_points'],'image_limit'=>5,'rendered_slots'=>$slots,'rules'=>'只有generate已授权才request_image；existing_first先匹配本轮素材，明显不合用途才生成；generate_first可直接生成。一个需要的图片位置只提交一次，已注册槽位必须能被组件实际渲染；不重复未确定提交，结果先存储再使用。'];
    }

    private static function componentIndex(array $data): array
    {
        $result=[];foreach(DecorationAgentOperations::index($data) as $id=>$node)$result[]=['id'=>$id,'name'=>$node['widget']['name'],'path'=>$node['path']];return $result;
    }
    private static function compactCatalog(): array
    {return DecorationAgentDesignContext::digest();}
    public static function json(mixed $value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    public static function decode(mixed $value): array {return is_array($value)?$value:(json_decode((string)$value,true)?:[]);}
    public static function hash(array $page): string {return hash('sha256',self::json($page));}
    /** Missing additive schema is an idle prerequisite, not a group crash. */
    public static function workerReady(): bool
    {
        $prefix=(string)config('database.connections.mysql.prefix','');
        $tables=array_map(fn($name)=>$prefix.'decorate_agent_'.$name,['session','message','run','candidate','image_slot','checkpoint']);
        $rows=Db::query('SELECT TABLE_NAME AS table_name FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?,?,?,?,?,?)',$tables);
        return count($rows)===count($tables);
    }
    private static function q(string $table,int $tenant,int $admin){return Db::name('decorate_agent_'.$table)->where(['tenant_id'=>$tenant,'admin_id'=>$admin]);}
    private static function owned(string $table,int $tenant,int $admin,int $id): array
    {
        if($tenant<=0||$admin<=0||$id<=0)throw new RuntimeException('装修会话身份或编号无效');
        $row=self::q($table,$tenant,$admin)->where('id',$id)->find();
        if(!$row)throw new RuntimeException('装修记录不存在或无权访问');
        return $row;
    }
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
        $data=['enabled'=>true,'catalog_hash'=>$catalog->hash(),'catalog_version'=>$catalog->version(),'text_models'=>[],'image_models'=>[],'limits'=>['model_calls'=>6,'tools'=>20,'images'=>5,'attachments'=>5,'attachment_bytes'=>10485760],'sources'=>['tenant','content','upload','generate']];
        if(!class_exists(Workflow::class)){$data['enabled']=false;$data['reason']='装修Agent运行依赖尚未安装';return $data;}
        try{foreach(['session','message','run','candidate','image_slot','checkpoint'] as $table)self::q($table,$tenant,$admin)->limit(1)->select();}catch(\Throwable $e){$data['enabled']=false;$data['reason']='装修Agent数据尚未初始化';return $data;}
        $data['text_models']=MarketTextModelRuntimeService::textOptions($tenant);
        $data['image_models']=DecorationAgentMediaService::options($tenant);
        if(!empty($p['page_id']))$data['current_hash']=self::hash(self::payload(self::page($tenant,(int)$p['template_id'],(int)$p['page_id'])));
        return $data;
    }
    public static function createSession(int $tenant,int $admin,array $p): array
    {
        $page=self::page($tenant,(int)($p['template_id']??0),(int)($p['page_id']??0));
        $id=self::insert('session',$tenant,$admin,['template_id'=>$page['template_id'],'page_id'=>$page['id'],'scope'=>'page','selected_id'=>'','preferences'=>self::json([]),'facts'=>'[]']);
        return self::session($tenant,$admin,$id);
    }
    public static function sessions(int $tenant,int $admin,array $p): array
    {return self::q('session',$tenant,$admin)->where('template_id',(int)($p['template_id']??0))->when(!empty($p['page_id']),fn($q)=>$q->where('page_id',(int)$p['page_id']))->order('id','desc')->limit(50)->select()->toArray();}
    public static function session(int $tenant,int $admin,int $id): array
    {
        $s=self::owned('session',$tenant,$admin,$id);$s['preferences']=self::decode($s['preferences']);$s['facts']=self::decode($s['facts']);
        $s['messages']=array_map(function($m){$m['attachments']=self::decode($m['attachments']);return $m;},self::q('message',$tenant,$admin)->where('session_id',$id)->order('id')->select()->toArray());
        $s['candidates']=array_map(function($candidate){$payload=self::decode($candidate['payload_json']);return array_intersect_key($candidate,array_flip(['id','run_id','revision','status','applied_page_id','create_time']))+['payload_hash'=>self::hash($payload),'_decoration_design'=>$payload['_decoration_design']??null,'acceptance'=>self::acceptanceForCandidate($candidate)];},self::q('candidate',$tenant,$admin)->where('session_id',$id)->order('id','desc')->limit(50)->select()->toArray());
        $s['checkpoints']=self::q('checkpoint',$tenant,$admin)->where('session_id',$id)->field('id,page_id,candidate_id,kind,create_time,after_hash')->order('id','desc')->limit(50)->select()->toArray();
        $s['design_drafts']=array_values(array_filter(array_map([DecorationAgentDesignService::class,'draft'],self::q('run',$tenant,$admin)->where('session_id',$id)->order('id','desc')->limit(100)->select()->toArray())));
        $s['current_hash']=self::hash(self::payload(self::page($tenant,(int)$s['template_id'],(int)$s['page_id'])));
        $s['last_run']=self::q('run',$tenant,$admin)->where('session_id',$id)->field('id,status,candidate_id')->order('id','desc')->find();$s['latest_run']=$s['last_run']?self::run($tenant,$admin,(int)$s['last_run']['id']):null;return $s;
    }
    private static function input(int $tenant,int $admin,array $p,bool $approved=false): array
    {
        if(trim((string)($p['message']??''))==='')throw new RuntimeException('请输入装修需求');
        if(mb_strlen((string)$p['message'])>12000)throw new RuntimeException('装修需求过长');
        if(($p['catalog_hash']??'')!==DecorationAgentCatalog::hash())throw new RuntimeException('组件契约版本不一致，请更新编辑器');
        $attachments=DecorationAgentMediaService::validateAttachments($tenant,(array)($p['attachments']??[]));
        $designFirst=$p['design_first']??false;if(!is_bool($designFirst))throw new RuntimeException('设计稿优先参数必须是布尔值');
        $vision=$designFirst||$approved;foreach($attachments as $a)if(array_intersect($a['uses'],['layout','style']))$vision=true;
        if(empty($p['model_selection']))throw new RuntimeException('请选择文本对话模型');
        $model=MarketTextModelRuntimeService::resolveModel($tenant,$p['model_selection'],$vision);
        $sources=array_values(array_intersect((array)($p['sources']??['tenant','content','upload']),['tenant','content','upload','generate']));
        if(in_array('generate',$sources,true)&&empty($p['image_model_selection']))throw new RuntimeException('请选择生图模型');
        $scope=(string)($p['scope']??'page');if(!in_array($scope,['page','selected','new_page'],true))throw new RuntimeException('装修范围无效');
        if($scope==='selected'&&empty($p['selected_id']))throw new RuntimeException('请先选择组件');
        if($designFirst&&($scope==='selected'||!in_array('generate',$sources,true)))throw new RuntimeException('设计稿优先仅支持整页范围，并且必须授权AI生图');
        if(!empty($p['design_parent_run_id'])&&!$designFirst)throw new RuntimeException('调整设计必须启用设计稿优先');
        $budget=(float)($p['budget_points']??0);if(!is_finite($budget)||$budget<=0)throw new RuntimeException('请确认本轮消费预算');
        $imageName='';if(in_array('generate',$sources,true)){$selection=(array)$p['image_model_selection'];$product=(int)($selection['market_product_id']??$selection['id']??$selection['product_id']??$selection['model_id']??0);foreach(DecorationAgentMediaService::options($tenant) as $option)if((int)($option['market_product_id']??$option['id'])===$product){$imageName=(string)$option['name'];break;}}
        if($designFirst&&$imageName==='')throw new RuntimeException('设计稿优先需要当前可用的生图模型');
        if(($designFirst||$approved)&&in_array('generate',$sources,true))DecorationAgentMediaService::assertDesignReferenceModel($tenant,(array)$p['image_model_selection']);
        return ['design_first'=>$designFirst,'design_parent_run_id'=>(int)($p['design_parent_run_id']??0),'image_model_name'=>$imageName,'message'=>trim((string)$p['message']),'model_selection'=>['id'=>$model['id'],'sku_id'=>$model['market_sku_id']],'image_model_selection'=>(array)($p['image_model_selection']??[]),'sources'=>$sources,'image_strategy'=>($p['image_strategy']??'')==='generate_first'?'generate_first':'existing_first','attachments'=>$attachments,'requires_vision'=>$vision,'scope'=>$scope,'selected_id'=>(string)($p['selected_id']??''),'budget_points'=>$budget,'catalog_hash'=>$p['catalog_hash']];
    }
    private static function visionReferenceCount(array $input,array $context): int
    {
        return count(array_filter($input['attachments'],fn($attachment)=>array_intersect($attachment['uses'],['layout','style'])))+(!empty($context['approved_design'])||!empty($context['design']['parent_reference'])?1:0);
    }
    private static function modelReferences(int $tenant,int $admin,int $id,array $input,array $context): array
    {
        $references=DecorationAgentMediaService::visionReferences($tenant,$input['attachments']);$designImage=null;
        if(!empty($context['approved_design']))$designImage=DecorationAgentDesignService::referenceBytes($tenant,$admin,$context['approved_design']);
        elseif(!empty($context['design']['parent_reference']))$designImage=DecorationAgentDesignService::parentReferenceBytes($tenant,$admin,$id);
        if($designImage)$references[]='data:'.$designImage['mime_type'].';base64,'.base64_encode($designImage['bytes']);
        return $references;
    }
    private static function textEstimate(array $model,string $text,int $output=4096): float
    {
        $total=0;foreach(['input'=>MarketTextModelRuntimeService::estimateTokens($text),'output'=>$output] as $key=>$tokens){$price=$model[$key]??[];$total+=(float)($price['platform_price']??0)*$tokens/max(1,(float)($price['usage_unit_size']??1000000));}return round($total,6);
    }

    private static function continuationCandidate(int $tenant,int $admin,array $session,array $parameters,bool $lock=false): ?array
    {
        $keys=['continue_candidate_id','continue_candidate_revision','continue_candidate_hash'];$present=array_intersect($keys,array_keys($parameters));
        if(!$present)return null;if(count($present)!==3)throw new RuntimeException('继续候选需要完整编号、版本及哈希');
        $id=filter_var($parameters[$keys[0]],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$revision=filter_var($parameters[$keys[1]],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$hash=$parameters[$keys[2]];
        if($id===false||$revision===false||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new RuntimeException('继续候选版本无效');
        $query=self::q('candidate',$tenant,$admin)->where('id',$id);if($lock)$query->lock(true);$candidate=$query->find();
        if(!$candidate||(int)$candidate['session_id']!==(int)($session['id']??0)||$candidate['status']!=='ready'||(int)$candidate['revision']!==$revision||!hash_equals(self::hash(self::decode($candidate['payload_json'])),$hash))throw new RuntimeException('继续候选不存在、已变化或不属于当前会话');
        $origin=self::owned('run',$tenant,$admin,(int)$candidate['run_id']);
        $originPage=self::decode($origin['context_json'])['session_page']??null;
        if($originPage!==null&&$originPage!==['template_id'=>(int)$session['template_id'],'page_id'=>(int)$session['page_id']])throw new RuntimeException('继续候选页面已变化');
        if($originPage===null&&!self::legacyCandidatePageMatches($tenant,$admin,$session))throw new RuntimeException('旧候选无法证明属于当前页面，请重新生成');
        if((int)$origin['session_id']!==(int)$session['id']||((int)$candidate['applied_page_id']>0&&(int)$candidate['applied_page_id']!==(int)$session['page_id']))throw new RuntimeException('继续候选页面归属不匹配');
        if(($parameters['scope']??'page')==='selected'&&!isset(DecorationAgentOperations::index(self::decode($candidate['payload_json'])['data']??[])[(string)($parameters['selected_id']??'')]))throw new RuntimeException('选中的组件不在继续候选内');
        return $candidate;
    }

    private static function legacyCandidatePageMatches(int $tenant,int $admin,array $session): bool
    {
        // A new-page apply moves the session. Legacy candidates lack its original page tuple.
        $created=self::q('checkpoint',$tenant,$admin)->where(['session_id'=>$session['id'],'kind'=>'create'])->order('id','desc')->find();
        // Run creation order cannot prove when a delayed new-page candidate was applied.
        return !$created;
    }

    private static function reusePage(array $page,array $input): array
    {
        if(($input['scope']??'page')!=='selected')return $page;
        $node=DecorationAgentOperations::index($page['data']??[])[(string)($input['selected_id']??'')]??null;
        return ['data'=>$node?[$node['widget']]:[],'meta'=>[]];
    }

    /** Recovery uses owned snapshots, never the session's arbitrary URL list or a tenant-wide asset scan. */
    private static function reusableMaterials(int $tenant,int $admin,array $session,array $input,array &$context,int $runId=0,?array $anchor=null): array
    {
        if(!array_key_exists('reusable_material_manifest',$context)){
            $pages=[$context['base']??[],$context['candidate']??[]];$history=[];
            $anchors=$anchor?[$anchor]:[];
            if(!$anchor&&$runId>0)$anchors=self::q('candidate',$tenant,$admin)->where('session_id',$session['id'])->where('run_id','<',$runId)->whereIn('status',['ready','applied','rolled_back'])->order('id','desc')->limit(20)->select()->toArray();
            foreach($anchors as $snapshot){
                if((int)$snapshot['run_id']>0){
                    $old=self::owned('run',$tenant,$admin,(int)$snapshot['run_id']);$oldContext=self::decode($old['context_json']);$originPage=$oldContext['session_page']??null;
                    if($originPage!==null&&$originPage!==['template_id'=>(int)$session['template_id'],'page_id'=>(int)$session['page_id']])continue;
                    if($originPage===null&&!self::legacyCandidatePageMatches($tenant,$admin,$session))continue;
                    $history=array_merge($history,$oldContext['trusted_materials']??[]);
                }
                $pages[]=self::decode($snapshot['payload_json']);
            }
            $manifest=[];foreach($pages as $page)foreach(DecorationAgentAssetReferences::freezeMaterials(self::reusePage($page,$input),$tenant,$input['sources'],$input['attachments'],$history) as $entry)$manifest[$entry['kind'].':'.$entry['id'].':'.$entry['stable_uri']]=$entry;
            $context['reusable_material_manifest']=array_values($manifest);
        }
        $valid=DecorationAgentAssetReferences::refreshMaterials($context['reusable_material_manifest'],$tenant,$input['sources'],$input['attachments']);
        $context['reusable_materials']=array_map(fn($entry)=>array_replace($entry,['id'=>$entry['kind']==='file'?'file:'.$entry['id']:'slot:'.$entry['id'].':'.hash('sha256',$entry['stable_uri'])]),$valid);$materials=[];
        foreach($valid as $entry)foreach($entry['aliases'] as $alias)$materials[]=array_replace($entry,['id'=>$entry['kind']==='file'?'file:'.$entry['id']:'slot:'.$entry['id'].':'.hash('sha256',$entry['stable_uri']),'url'=>$alias]);
        return $materials;
    }
    public static function quote(int $tenant,int $admin,array $p): array
    {
        $copy=$p;$copy['budget_points']=max(1,(float)($p['budget_points']??1));$input=self::input($tenant,$admin,$copy);
        $s=!empty($p['session_id'])?self::owned('session',$tenant,$admin,(int)$p['session_id']):['template_id'=>(int)($p['template_id']??0),'page_id'=>(int)($p['page_id']??0)];$page=self::payload(self::page($tenant,(int)$s['template_id'],(int)$s['page_id']));
        $model=MarketTextModelRuntimeService::resolveModel($tenant,$input['model_selection'],$input['requires_vision']);
        $selected=self::continuationCandidate($tenant,$admin,$s,$p);$candidate=$selected?self::decode($selected['payload_json']):$page;
        if(!$selected&&!empty($s['active_candidate_id'])){$active=self::q('candidate',$tenant,$admin)->where('id',$s['active_candidate_id'])->find();if($active&&in_array($active['status'],['ready','waiting_images'],true))$candidate=self::decode($active['payload_json']);}
        if($input['scope']==='new_page'&&!($candidate['new_page']??false))$candidate=['data'=>[],'meta'=>$page['meta'],'name'=>'新页面','new_page'=>true];
        $quoteContext=['base'=>$selected?self::decode($selected['base_json']):$page,'candidate'=>$candidate,'facts'=>self::decode($s['facts']??'[]'),'observations'=>[]];
        $latest=(int)(self::decode($s['preferences']??'[]')['_design_state']['run_id']??0);
        if(!$input['design_first']&&$selected&&($provenance=self::decode($selected['payload_json'])['_decoration_design']??null)){$quoteContext['approved_design']=DecorationAgentDesignService::confirmedDesign($tenant,$admin,(int)$provenance['run_id'],(string)$provenance['hash']);$input['requires_vision']=true;$model=MarketTextModelRuntimeService::resolveModel($tenant,$input['model_selection'],true);}
        if(!$input['design_first']&&$latest&&empty($quoteContext['approved_design'])){$designRun=self::owned('run',$tenant,$admin,$latest);$design=DecorationAgentDesignService::draft($designRun);if(($design['status']??'')==='approved'){$quoteContext['approved_design']=DecorationAgentDesignService::confirmedDesign($tenant,$admin,$latest,$design['hash']);$input['requires_vision']=true;$model=MarketTextModelRuntimeService::resolveModel($tenant,$input['model_selection'],true);}}
        if($input['design_first']&&!empty($s['id'])){$quoteContext['phase']='design';$quoteContext['design']=DecorationAgentDesignService::initialState($tenant,$admin,$s,$input);}
        self::reusableMaterials($tenant,$admin,$s,$input,$quoteContext,0,$selected);
        $context=self::modelContext($tenant,$admin,$s,$input,$quoteContext,true);
        $estimate=self::textEstimate($model,self::json($context)."\n用户需求：".$input['message'].DecorationAgentDecision::prompt().str_repeat('v',self::visionReferenceCount($input,$quoteContext) * 6000));
        $imageQuote=[];if(in_array('generate',$input['sources'],true))$imageQuote=DecorationAgentMediaService::quote($tenant,$input['image_model_selection'],1,['attachments'=>$input['attachments'],'reference_file_ids'=>array_column(array_filter($input['attachments'],fn($a)=>in_array('generation',$a['uses'],true)),'file_id')]);
        return ['continue_candidate'=>$selected?['id'=>(int)$selected['id'],'revision'=>(int)$selected['revision'],'hash'=>self::hash(self::decode($selected['payload_json']))]:null,'vision_reference_count'=>self::visionReferenceCount($input,$quoteContext),'estimated_points'=>$estimate,'text_estimated_points'=>$estimate,'max_text_estimated_points'=>$estimate*6,'image_unit_points'=>(float)($imageQuote['tenant_cost_points']??$imageQuote['tenant_cost']??0),'max_model_calls'=>6,'max_images'=>$input['design_first']?1:5,'estimate_only'=>true];
    }
    public static function start(int $tenant,int $admin,array $p,?array $approvedDesign=null): array
    {
        $key=trim((string)($p['idempotency_key']??''));if($key===''||strlen($key)>100)throw new RuntimeException('提交标识无效');
        $old=self::q('run',$tenant,$admin)->where('idempotency_key',$key)->find();if($old){if(array_intersect(['continue_candidate_id','continue_candidate_revision','continue_candidate_hash'],array_keys($p))){$frozen=self::decode($old['input_json']);foreach(['continue_candidate_id','continue_candidate_revision','continue_candidate_hash'] as $field)if(!array_key_exists($field,$p)||(string)($frozen[$field]??'')!==(string)$p[$field])throw new RuntimeException('提交标识已绑定不同继续候选');}return self::run($tenant,$admin,(int)$old['id']);}
        $selectedSession=self::owned('session',$tenant,$admin,(int)($p['session_id']??0));$selected=self::continuationCandidate($tenant,$admin,$selectedSession,$p);
        if($approvedDesign===null&&!($p['design_first']??false)&&$selected&&($provenance=self::decode($selected['payload_json'])['_decoration_design']??null))$approvedDesign=DecorationAgentDesignService::confirmedDesign($tenant,$admin,(int)$provenance['run_id'],(string)$provenance['hash']);
        if($approvedDesign===null&&!($p['design_first']??false)&&empty($p['design_implementation_run_id'])){
            $session=self::owned('session',$tenant,$admin,(int)($p['session_id']??0));$latest=(int)(self::decode($session['preferences'])['_design_state']['run_id']??0);
            if($latest){$designRun=self::owned('run',$tenant,$admin,$latest);$draft=DecorationAgentDesignService::draft($designRun);if(($draft['status']??'')==='approved')$approvedDesign=DecorationAgentDesignService::confirmedDesign($tenant,$admin,$latest,$draft['hash']);elseif(in_array($draft['status']??'',['planning','generating','awaiting_confirmation'],true))throw new RuntimeException('请先确认或取消最新设计稿，普通继续不能绕过设计审批');}
        }
        if($approvedDesign===null&&!($p['design_first']??false)&&!empty($p['design_implementation_run_id'])){
            $anchor=self::owned('run',$tenant,$admin,(int)$p['design_implementation_run_id']);if((int)$anchor['session_id']!==(int)($p['session_id']??0))throw new RuntimeException('设计实施会话不匹配');$frozen=self::decode($anchor['context_json'])['approved_design']??null;if(!$frozen)throw new RuntimeException('指定任务没有已确认设计');$approvedDesign=DecorationAgentDesignService::confirmedDesign($tenant,$admin,(int)$frozen['run_id'],(string)$frozen['hash']);
        }
        if($approvedDesign!==null&&($p['design_first']??false))throw new RuntimeException('设计生成与已确认实施不能同时启用');
        $input=self::input($tenant,$admin,$p,$approvedDesign!==null);
        if($selected)foreach(['continue_candidate_id','continue_candidate_revision','continue_candidate_hash'] as $field)$input[$field]=$p[$field];
        return Db::transaction(function()use($tenant,$admin,$p,$key,$input,$approvedDesign,$selectedSession){
            // Match apply/hydrate: candidate before session, then revalidate under both locks.
            $selected=self::continuationCandidate($tenant,$admin,$selectedSession,$p,true);
            $s=self::q('session',$tenant,$admin)->where('id',(int)$p['session_id'])->lock(true)->find();if(!$s)throw new RuntimeException('装修会话不存在');
            $active=self::q('run',$tenant,$admin)->where('session_id',$s['id'])->whereIn('status',['queued','running','waiting_images'])->find();if($active)throw new RuntimeException('当前会话已有执行中的任务');
            $base=self::payload(self::page($tenant,(int)$s['template_id'],(int)$s['page_id']));$candidate=$base;
            $existing=$selected?self::continuationCandidate($tenant,$admin,$s,$p):null;if(!$existing)$existing=!empty($s['active_candidate_id'])?self::q('candidate',$tenant,$admin)->where('id',$s['active_candidate_id'])->find():null;
            if($existing&&in_array($existing['status'],['ready','waiting_images'],true)){$candidate=self::decode($existing['payload_json']);$base=self::decode($existing['base_json']);}
            if($input['scope']==='new_page'&&!($candidate['new_page']??false))$candidate=['data'=>[],'meta'=>$base['meta'],'name'=>'新页面','new_page'=>true];
            $ctx=['session_page'=>['template_id'=>(int)$s['template_id'],'page_id'=>(int)$s['page_id']],'base'=>$base,'candidate'=>$candidate,'observations'=>[],'changes'=>[],'failures'=>[],'receipts'=>[],'facts'=>self::decode($s['facts']),'calling'=>false];
            if($input['design_first']){unset($ctx['candidate']['_decoration_design']);$ctx['phase']='design';$ctx['design']=DecorationAgentDesignService::initialState($tenant,$admin,$s,$input);}
            elseif($approvedDesign!==null){$ctx['phase']='implementation';$ctx['approved_design']=$approvedDesign;$ctx['candidate']['_decoration_design']=array_intersect_key($approvedDesign,array_flip(['run_id','revision','hash']));}
            self::reusableMaterials($tenant,$admin,$s,$input,$ctx,0,$existing);
            $id=self::insert('run',$tenant,$admin,['session_id'=>$s['id'],'input_json'=>self::json($input),'context_json'=>self::json($ctx),'result_json'=>'{}','events_json'=>'[]','idempotency_key'=>$key,'next_run_time'=>time()]);
            self::insert('message',$tenant,$admin,['session_id'=>$s['id'],'run_id'=>$id,'role'=>'user','content'=>$input['message'],'attachments'=>self::json($input['attachments'])]);
            self::q('session',$tenant,$admin)->where('id',$s['id'])->update(['scope'=>$input['scope'],'selected_id'=>$input['selected_id'],'preferences'=>self::json(array_replace(self::decode($s['preferences']),array_diff_key($input,['message'=>1,'attachments'=>1,'continue_candidate_id'=>1,'continue_candidate_revision'=>1,'continue_candidate_hash'=>1]))),'update_time'=>time()]);
            if($input['design_first'])DecorationAgentDesignService::registerStart($tenant,$admin,$id,$s,$ctx['design']);
            self::event($id,'queued',['message'=>'开始安排页面']);return self::run($tenant,$admin,$id);
        });
    }

    private static function extractionSource(int $tenant,int $admin,array $tuple): array
    {
        $id=DecorationAgentDesignExtraction::positive($tuple['candidate_id']??null,'候选编号');
        $candidate=self::owned('candidate',$tenant,$admin,$id);$session=self::owned('session',$tenant,$admin,(int)$candidate['session_id']);
        $parameters=['continue_candidate_id'=>$id,'continue_candidate_revision'=>$tuple['revision']??null,'continue_candidate_hash'=>$tuple['candidate_hash']??null];
        $candidate=self::continuationCandidate($tenant,$admin,$session,$parameters);$payload=self::decode($candidate['payload_json']);$provenance=$payload['_decoration_design']??null;
        if(!is_array($provenance))throw new \InvalidArgumentException('此候选没有已确认设计稿');
        $approved=DecorationAgentDesignService::confirmedDesign($tenant,$admin,(int)$provenance['run_id'],(string)$provenance['hash']);
        if((int)$approved['revision']!==(int)$provenance['revision']||(int)self::owned('run',$tenant,$admin,(int)$approved['run_id'])['session_id']!==(int)$session['id'])throw new \InvalidArgumentException('候选设计版本不匹配');
        $origin=self::owned('run',$tenant,$admin,(int)$candidate['run_id']);$input=self::decode($origin['input_json']);self::assertOperator($tenant,$admin,(string)$input['scope']);
        $page=self::page($tenant,(int)$session['template_id'],(int)$session['page_id']);
        $context=['candidate'=>$payload,'approved_design'=>$approved];$targets=self::generationContext($tenant,$input,$context)['rendered_slots'];$index=DecorationAgentOperations::index($payload['data']);
        if($input['scope']==='selected'){$selected=$index[$input['selected_id']]??null;$targets=array_values(array_filter($targets,fn($target)=>$selected&&array_slice($index[$target['widget_id']]['path'],0,count($selected['path']))===$selected['path']));}
        foreach($targets as &$target){
            $widget=$index[$target['widget_id']]['widget'];$parts=explode('.',$target['field']);$row=count($parts)===4?($widget['content'][$parts[1]][$parts[2]]??[]):[];
            $title=$row['title']??$row['name']??$widget['content']['title']??'';$position=array_search($target['widget_id'],array_keys($index),true)+1;
            $target['label']=$widget['name'].' #'.$position.($title!==''?' · '.mb_substr((string)$title,0,60):'').(count($parts)===4?' · 第'.((int)$parts[2]+1).'行':'').' · '.$target['field'];
            $target['current_value']=DecorationAgentOperations::get($widget,$target['field']);$target['container_background']=null;
            foreach(['image_background','item_background','background_color','bg_color'] as $field){$color=$widget['styles'][$field]??null;if(is_string($color)&&preg_match('/^#[0-9a-f]{6}$/iD',$color)){$target['container_background']=strtoupper($color);break;}}
        }unset($target);
        return compact('candidate','session','payload','approved','input','targets')+['expected_hash'=>self::hash(self::payload($page)),
            'epoch'=>['active_candidate_id'=>(int)$session['active_candidate_id'],'latest_run_id'=>(int)self::q('run',$tenant,$admin)->where('session_id',$session['id'])->max('id')]];
    }

    public static function extractionOptions(int $tenant,int $admin,array $parameters): array
    {
        if(array_diff(array_keys($parameters),['candidate_id','revision','candidate_hash']))throw new \InvalidArgumentException('候选参数无效');
        $source=self::extractionSource($tenant,$admin,$parameters);$approved=$source['approved'];
        $models=array_values(array_filter(DecorationAgentMediaService::options($tenant),fn($model)=>($model['available']??true)&&!empty($model['supports_reference_images'])));
        $selection=$source['input']['image_model_selection']??[];
        if(!$selection&&$models&&!empty($models[0]['skus'][0]))$selection=['market_product_id'=>(int)$models[0]['market_product_id'],'market_sku_id'=>(int)$models[0]['skus'][0]['market_sku_id']];
        return ['candidate'=>['id'=>(int)$source['candidate']['id'],'revision'=>(int)$source['candidate']['revision'],'hash'=>self::hash($source['payload'])],
            'expected_hash'=>$source['expected_hash'],'design'=>array_intersect_key($approved,array_flip(['run_id','revision','hash','slot_id']))+['url'=>DecorationAgentDesignService::assetUrl($tenant,$approved['asset']),'width'=>(int)$approved['asset']['width'],'height'=>(int)$approved['asset']['height']],
            'targets'=>$source['targets'],'image_models'=>$models,'image_model_selection'=>$selection,'background_modes'=>['solid'],'supports_transparent_background'=>false,'region_scale'=>1000,'max_region_area'=>600000,'quantity'=>1];
    }

    private static function prepareExtraction(int $tenant,int $admin,array $canonical): array
    {
        $source=self::extractionSource($tenant,$admin,array_intersect_key($canonical,array_flip(['candidate_id','revision','candidate_hash'])));
        if($source['expected_hash']!==$canonical['expected_hash'])throw new \InvalidArgumentException('页面草稿已变化，请重新报价');
        foreach(['run_id'=>'design_run_id','revision'=>'design_revision','hash'=>'design_hash'] as $field=>$inputKey)if((string)$source['approved'][$field]!==(string)$canonical[$inputKey])throw new \InvalidArgumentException('已确认设计版本已变化');
        $target=null;foreach($source['targets'] as $slot)if($slot['widget_id']===$canonical['widget_id']&&$slot['field']===$canonical['field']){$target=$slot;break;}
        if(!$target)throw new \InvalidArgumentException('请选择候选中的真实可渲染图片字段');
        DecorationAgentDesignExtraction::assertModel($tenant,$canonical['image_model_selection'],$canonical['ratio']);
        $uncertain=self::q('image_slot',$tenant,$admin)->where('session_id',$source['session']['id'])->whereIn('status',['submitting','needs_review'])->field('request_json')->select()->toArray();
        foreach($uncertain as $slot){$request=self::decode($slot['request_json']);if(($request['generation_mode']??'')==='design_extract'&&($request['widget_id']??'')===$canonical['widget_id']&&($request['field']??'')===$canonical['field']&&(int)($request['reference_design_slot_id']??0)===(int)$source['approved']['slot_id'])throw new \InvalidArgumentException('该素材上次提交结果仍待核对，禁止重复生成');}
        $operation=['type'=>'request_image']+array_intersect_key($canonical,array_flip(['widget_id','field','prompt','ratio','generation_mode','region','asset_role','background']));
        $applied=DecorationAgentOperations::apply($source['payload'],[$operation],['scope'=>$source['input']['scope'],'selected_id'=>$source['input']['selected_id']??'']);
        $request=$applied['image_requests'][0];$request['reference_design_slot_id']=(int)$source['approved']['slot_id'];
        return $source+['request'=>$request,'canonical'=>$canonical,'sources'=>array_values(array_unique([...$source['input']['sources'],'generate']))];
    }

    private static function extractionQuote(int $tenant,int $admin,array $prepared): array
    {
        $canonical=$prepared['canonical'];
        $quote=DecorationAgentMediaService::quoteDesignExtraction($tenant,$admin,$canonical['image_model_selection'],$prepared['request'],$prepared['approved']);
        if(!($quote['cost_known']??false))throw new \InvalidArgumentException('提取素材需要可确定单次价格的图片规格');
        $price=(float)($quote['tenant_cost_points']??$quote['tenant_cost']??0);
        if($price>$canonical['budget_points'])throw new \InvalidArgumentException('图片费用超过本次确认预算');
        $attachments=array_map(fn($attachment)=>array_intersect_key($attachment,array_flip(['file_id','uses','sha256'])),$prepared['input']['attachments']??[]);
        $fingerprint=self::hash(['owner'=>[$tenant,$admin],'canonical'=>$canonical,'sources'=>$prepared['sources'],'authorized_attachments'=>$attachments,'epoch'=>$prepared['epoch'],
            'asset'=>array_intersect_key($prepared['approved']['asset'],array_flip(['image_uri','storage_scope','storage_engine','storage_domain','width','height','sha256'])),'old_value'=>$prepared['request']['old_value'],'old_ref'=>$prepared['request']['old_ref'],'unit_points'=>$price]);
        return ['estimated_points'=>$price,'cost_known'=>true,'quantity'=>1,'reference_count'=>1,'budget_points'=>$canonical['budget_points'],
            'image_model_selection'=>$canonical['image_model_selection'],'quote_hash'=>$fingerprint,'canonical_request'=>$canonical,'warnings'=>['根据批准图框选区域定位生成独立素材，不保证像素级原样切图；生成后仍需预览和重新截图复审。']];
    }

    public static function quoteDesignAsset(int $tenant,int $admin,array $parameters): array
    {
        $canonical=DecorationAgentDesignExtraction::normalize($parameters);
        return self::extractionQuote($tenant,$admin,self::prepareExtraction($tenant,$admin,$canonical));
    }

    public static function extractDesignAsset(int $tenant,int $admin,array $parameters): array
    {
        $userKey=$parameters['idempotency_key']??null;
        if(!is_string($userKey)||trim($userKey)===''||strlen($userKey)>100)throw new DecorationAgentDesignExtractionRejected('提取提交标识无效');
        $key='design-asset:'.hash('sha256',trim($userKey));$old=self::q('run',$tenant,$admin)->where('idempotency_key',$key)->find();
        $normalize=static function()use($parameters):array {
            $canonical=DecorationAgentDesignExtraction::normalize($parameters,true);
            if(!is_string($parameters['quote_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$parameters['quote_hash']))throw new \InvalidArgumentException('请先确认本次图片报价');
            return [$canonical,self::hash(['request'=>$canonical,'quote_hash'=>$parameters['quote_hash']])];
        };
        if($old){[, $requestHash]=$normalize();if((self::decode($old['context_json'])['manual_extraction']['request_hash']??'')!==$requestHash)throw new RuntimeException('提交标识已绑定另一项素材提取，请勿更换参数重复提交');return self::run($tenant,$admin,(int)$old['id']);}
        try {
            [$canonical,$requestHash]=$normalize();$prepared=self::prepareExtraction($tenant,$admin,$canonical);$quote=self::extractionQuote($tenant,$admin,$prepared);
            if(!hash_equals($quote['quote_hash'],$parameters['quote_hash']))throw new \InvalidArgumentException('候选、会话或图片价格已变化，请重新报价确认');
            $id=Db::transaction(function()use($tenant,$admin,$key,$requestHash,$prepared,$quote,$canonical):int {
                self::q('candidate',$tenant,$admin)->where('id',$canonical['candidate_id'])->lock(true)->find();
                self::q('session',$tenant,$admin)->where('id',$prepared['session']['id'])->lock(true)->find();
                $old=self::q('run',$tenant,$admin)->where('idempotency_key',$key)->find();if($old){if((self::decode($old['context_json'])['manual_extraction']['request_hash']??'')!==$requestHash)throw new RuntimeException('提交标识已用于另一项提取');return (int)$old['id'];}
                $fresh=self::prepareExtraction($tenant,$admin,$canonical);if($fresh['epoch']!==$prepared['epoch'])throw new \InvalidArgumentException('会话已有新操作，请重新报价确认');
                if(self::q('run',$tenant,$admin)->where('session_id',$fresh['session']['id'])->whereIn('status',['queued','running','waiting_images'])->count())throw new \InvalidArgumentException('当前会话已有执行中的任务');
                $input=array_replace($fresh['input'],['design_first'=>false,'sources'=>$fresh['sources'],'image_model_selection'=>$canonical['image_model_selection'],'budget_points'=>$canonical['budget_points'],'message'=>'根据已确认设计图框选区域提取一个独立素材','images_only'=>true]);
                unset($input['visual_review'],$input['continue_candidate_id'],$input['continue_candidate_revision'],$input['continue_candidate_hash']);
                $context=['session_page'=>['template_id'=>(int)$fresh['session']['template_id'],'page_id'=>(int)$fresh['session']['page_id']],'base'=>self::decode($fresh['candidate']['base_json']),'candidate'=>$fresh['payload'],'phase'=>'implementation','approved_design'=>$fresh['approved'],'observations'=>[],'changes'=>[],'failures'=>[],'receipts'=>[],'facts'=>[],'calling'=>false,
                    'manual_extraction'=>['request_hash'=>$requestHash,'quote_hash'=>$quote['quote_hash'],'quoted_unit_points'=>$quote['estimated_points'],'source_candidate_id'=>$canonical['candidate_id'],'canonical_request'=>$canonical,'epoch'=>$fresh['epoch']]];
                $id=self::insert('run',$tenant,$admin,['session_id'=>$fresh['session']['id'],'input_json'=>self::json($input),'context_json'=>self::json($context),'result_json'=>'{}','events_json'=>'[]','idempotency_key'=>$key,'next_run_time'=>time()]);
                self::insert('message',$tenant,$admin,['session_id'=>$fresh['session']['id'],'run_id'=>$id,'role'=>'user','content'=>$input['message'],'attachments'=>'[]']);
                $request=$fresh['request'];$request['quoted_unit_points']=$quote['estimated_points'];
                self::persistDecision($tenant,$admin,$id,$fresh['session'],$input,$context,['kind'=>'final','reply'=>'已安排一个设计素材提取任务，完成后请预览并重新截图复审。','question'=>'','warnings'=>[]],[$request]);
                return $id;
            });
        } catch(\InvalidArgumentException $error){
            // Another request may have committed the same key while this one
            // was validating. Never label an existing/unknown submission safe.
            if(self::q('run',$tenant,$admin)->where('idempotency_key',$key)->count())throw new RuntimeException($error->getMessage(),0,$error);
            throw new DecorationAgentDesignExtractionRejected($error->getMessage(),0,$error);
        }
        // A serialization/network error after commit remains unknown to the client.
        return self::run($tenant,$admin,$id);
    }

    public static function reviewDesign(int $tenant,int $admin,array $parameters): array
    {
        $allowed=['candidate_id','revision','candidate_hash','expected_hash','design_run_id','design_hash','screenshot_file_id','budget_points','idempotency_key','model_selection'];
        if(array_diff(array_keys($parameters),$allowed))throw new RuntimeException('验收参数包含不支持的字段');
        $selection=array_key_exists('model_selection',$parameters)?self::reviewModelSelection($parameters['model_selection']):null;
        $key=trim((string)($parameters['idempotency_key']??''));
        if($key===''||strlen($key)>100)throw new RuntimeException('验收提交标识无效');
        $key='design-review:'.hash('sha256',$key);
        $old=self::q('run',$tenant,$admin)->where('idempotency_key',$key)->find();
        if($old){
            $evidence=self::decode($old['context_json'])['visual_review']??[];
            foreach(['candidate_id','revision','candidate_hash','expected_hash','design_run_id','design_hash','screenshot_file_id'] as $field)if((string)($evidence[$field]??'')!==(string)($parameters[$field]??''))throw new RuntimeException('验收标识已用于其他版本，请重新提交');
            // Replay compares the effective frozen identity without resolving a model
            // that may since have been delisted. Omitted selection keeps legacy replay.
            if($selection!==null){
                $frozen=self::decode($old['input_json'])['model_selection']??[];
                if((int)($frozen['id']??0)!==$selection['id']||(isset($selection['sku_id'])&&(int)($frozen['sku_id']??0)!==$selection['sku_id']))throw new RuntimeException('验收标识已用于其他模型，请使用新的提交标识');
            }
            return self::run($tenant,$admin,(int)$old['id']);
        }
        $prepared=DecorationAgentAcceptance::validateReview($tenant,$admin,$parameters);
        $candidate=$prepared['candidate'];$original=self::owned('run',$tenant,$admin,(int)$candidate['run_id']);
        $request=self::decode($original['input_json']);$request['message']='将最新批准设计图与实际候选截图逐区域视觉对照，发现差异用真实组件和独立素材修复。'.DecorationAgentDecision::visualReviewInstruction();
        $request['catalog_hash']=DecorationAgentCatalog::hash();$request['design_first']=false;unset($request['design_parent_run_id']);
        $request['budget_points']=$parameters['budget_points'];$request['attachments']=$prepared['attachments'];
        if($selection!==null)$request['model_selection']=$selection;
        $input=self::input($tenant,$admin,$request,true);self::assertOperator($tenant,$admin,$input['scope']);
        // The shared resolver supports SKU-first and ID fallback. A deliberate
        // review override must match both supplied identities exactly.
        if($selection!==null&&((int)$input['model_selection']['id']!==$selection['id']||(isset($selection['sku_id'])&&(int)$input['model_selection']['sku_id']!==$selection['sku_id'])))throw new RuntimeException('所选视觉模型与可用输入规格不匹配');
        $input['visual_review']=$prepared['visual_review'];
        return Db::transaction(function()use($tenant,$admin,$key,$input,$prepared,$candidate): array {
            $session=self::q('session',$tenant,$admin)->where('id',$candidate['session_id'])->lock(true)->find();
            if(!$session)throw new RuntimeException('验收会话不存在');
            $active=self::q('run',$tenant,$admin)->where('session_id',$session['id'])->whereIn('status',['queued','running','waiting_images'])->find();if($active)throw new RuntimeException('当前会话已有执行中的任务');
            $current=self::owned('candidate',$tenant,$admin,(int)$candidate['id']);if($current['payload_json']!==$candidate['payload_json']||(int)$current['revision']!==(int)$candidate['revision'])throw new RuntimeException('候选已变化，请重新截图');
            $context=['base'=>self::decode($candidate['base_json']),'candidate'=>$prepared['snapshot'],'phase'=>'implementation',
                'approved_design'=>$prepared['approved_design'],'visual_review'=>$prepared['visual_review'],
                'observations'=>[],'changes'=>[],'failures'=>[],'receipts'=>[],'facts'=>self::decode($session['facts']),'calling'=>false];
            $id=self::insert('run',$tenant,$admin,['session_id'=>$session['id'],'candidate_id'=>$candidate['id'],'input_json'=>self::json($input),'context_json'=>self::json($context),'result_json'=>'{}','events_json'=>'[]','idempotency_key'=>$key,'next_run_time'=>time()]);
            self::insert('message',$tenant,$admin,['session_id'=>$session['id'],'run_id'=>$id,'role'=>'user','content'=>'已提交实际候选截图，请与已确认设计稿对照验收。','attachments'=>self::json($prepared['attachments'])]);
            self::event($id,'queued',['message'=>'开始设计稿与实际截图对照']);return self::run($tenant,$admin,$id);
        });
    }

    private static function reviewModelSelection($selection): array
    {
        if(!is_array($selection)||!array_key_exists('id',$selection)||array_diff(array_keys($selection),['id','sku_id']))throw new RuntimeException('验收模型参数无效');
        $normalized=[];
        foreach($selection as $field=>$value){
            if((!is_int($value)&&!is_string($value))||!preg_match('/^[1-9][0-9]*$/D',(string)$value)||filter_var($value,FILTER_VALIDATE_INT)===false)throw new RuntimeException('验收模型或规格标识无效');
            $normalized[$field]=(int)$value;
        }
        return $normalized;
    }

    private static function acceptanceForCandidate(array $candidate): ?array
    {
        $hash=self::hash(self::decode($candidate['payload_json']));
        $runs=self::q('run',(int)$candidate['tenant_id'],(int)$candidate['admin_id'])->where('candidate_id',$candidate['id'])->order('id','desc')->field('context_json')->select()->toArray();
        foreach($runs as $row){
            $report=self::decode($row['context_json'])['acceptance']??null;if(!$report||(int)($report['candidate_id']??0)!==(int)$candidate['id'])continue;
            if((int)($report['revision']??0)!==(int)$candidate['revision']||($report['candidate_hash']??'')!==$hash){$report['passed']=false;if(($report['status']??'')==='passed')$report['status']='stale';}
            return $report;
        }
        return null;
    }
    public static function run(int $tenant,int $admin,int $id): array
    {
        $row=self::owned('run',$tenant,$admin,$id);$result=self::decode($row['result_json']);$ctx=self::decode($row['context_json']);
        return ['acceptance'=>$ctx['acceptance']??null,'approved_design'=>$ctx['approved_design']??null,'phase'=>$ctx['phase']??'normal','design_draft'=>DecorationAgentDesignService::draft($row),'assistant_reply'=>(string)($ctx['assistant_reply']??$result['reply']??''),'assistant_step'=>(int)($ctx['assistant_step']??0),'assistant_complete'=>(bool)($ctx['assistant_complete']??false),'id'=>$id,'status'=>$row['status'],'candidate_id'=>(int)$row['candidate_id'],'model_calls'=>(int)$row['model_calls'],'tool_calls'=>(int)$row['tool_calls'],'spent_points'=>(float)$row['spent_points'],'events'=>self::decode($row['events_json']),'image_slots'=>self::slots($tenant,$admin,$id)]+$result;
    }
    public static function events(int $tenant,int $admin,int $id,int $after): array
    {$r=self::run($tenant,$admin,$id);return ['status'=>$r['status'],'events'=>array_values(array_filter($r['events'],fn($e)=>$e['id']>$after)),'run'=>$r];}
    public static function cancel(int $tenant,int $admin,int $id): array
    {
        $hint=self::owned('run',$tenant,$admin,$id);
        return Db::transaction(function()use($tenant,$admin,$id,$hint):array{
            $candidate=(int)$hint['candidate_id']>0?self::q('candidate',$tenant,$admin)->where('id',$hint['candidate_id'])->lock(true)->find():null;
            $run=self::q('run',$tenant,$admin)->where('id',$id)->lock(true)->find();if(!$run)throw new RuntimeException('装修记录不存在或无权访问');
            $update=['cancel_requested'=>1,'update_time'=>time()];if(in_array($run['status'],['queued','awaiting_design_confirmation'],true))$update['status']='canceled';
            self::q('run',$tenant,$admin)->where('id',$id)->update($update);self::cancelUnsubmittedImages($run,$candidate);
            self::event($id,'cancel_requested',['message'=>'已停止后续步骤；已提交的图片任务保留结果']);return self::run($tenant,$admin,$id);
        });
    }

    private static function cancelUnsubmittedImages(array $run,?array $candidate=null): void
    {
        $tenant=(int)$run['tenant_id'];$admin=(int)$run['admin_id'];
        self::q('image_slot',$tenant,$admin)->where(['run_id'=>$run['id'],'status'=>'queued','consumption_id'=>0])->update(['status'=>'canceled','error'=>'本轮已停止，图片未提交']);
        if($candidate&&$candidate['status']==='waiting_images'&&!self::q('image_slot',$tenant,$admin)->where('candidate_id',$candidate['id'])->whereIn('status',['queued','running','submitting','needs_review'])->count())self::q('candidate',$tenant,$admin)->where('id',$candidate['id'])->where('status','waiting_images')->update(['status'=>'ready']);
    }

    public static function event(int $runId,string $type,array $data): void
    {
        Db::transaction(function()use($runId,$type,$data){$r=Db::name('decorate_agent_run')->where('id',$runId)->lock(true)->find();if(!$r)return;$events=self::decode($r['events_json']);$last=end($events);$events[]=['id'=>(int)($last['id']??0)+1,'type'=>$type,'data'=>$data,'time'=>time()];$update=['events_json'=>self::json(array_slice($events,-200)),'update_time'=>time()];if($type==='assistant'){$ctx=self::decode($r['context_json']);$ctx['assistant_reply']=(string)($data['text']??'');$ctx['assistant_step']=(int)($data['step']??0);$ctx['assistant_complete']=(bool)($data['complete']??false);$update['context_json']=self::json($ctx);}Db::name('decorate_agent_run')->where('id',$runId)->update($update);});
    }
    private static function slots(int $tenant,int $admin,int $runId,int $candidateId=0): array
    {
        $query=self::q('image_slot',$tenant,$admin);
        if($candidateId>0)$query->where('candidate_id',$candidateId);else $query->where('run_id',$runId);
        $runs=[];$result=[];
        foreach($query->order('id')->select()->toArray() as $slot){
            $owner=(int)$slot['run_id'];
            if(!isset($runs[$owner])){$run=self::owned('run',$tenant,$admin,$owner);$input=self::decode($run['input_json']);$runs[$owner]=['name'=>$input['image_model_name']??'原生图模型','remaining'=>max(0,(float)($input['budget_points']??0)-self::spent($run))];}
            $slot['request']=self::decode($slot['request_json']);$slot['result']=self::decode($slot['result_json']);
            $slot['image_model_name']=$runs[$owner]['name'];$slot['remaining_budget_points']=$runs[$owner]['remaining'];
            unset($slot['request_json'],$slot['result_json']);$result[]=$slot;
        }
        return $result;
    }
    private static function resolvedPage(int $tenant,array $page,?array $payload=null): array
    {
        $payload=$payload??self::payload($page);$source=$page;$source['data']=self::json($payload['data']);$source['meta']=self::json($payload['meta']);
        $resolved=DecorateDataSourceService::applyToPage($source,['tenant_id'=>$tenant,'user_id'=>0,'terminal'=>'mobile','preview'=>true]);
        $page['resolved_sources']=$resolved['resolved_sources']??[];if(isset($resolved['resolved_asset_urls']))$page['resolved_asset_urls']=$resolved['resolved_asset_urls'];return $page;
    }
    public static function candidate(int $tenant,int $admin,int $id): array
    {$r=self::owned('candidate',$tenant,$admin,$id);$p=self::decode($r['payload_json']);$s=self::owned('session',$tenant,$admin,(int)$r['session_id']);$page=self::page($tenant,(int)$s['template_id'],(int)$s['page_id']);if($p['new_page']??false)$page['page_code']='';$p['resolved_sources']=self::resolvedPage($tenant,$page,$p)['resolved_sources'];return $p+['base_data'=>self::decode($r['base_json'])['data']??[],'base_meta'=>self::decode($r['base_json'])['meta']??[],'id'=>$id,'run_id'=>(int)$r['run_id'],'acceptance'=>self::acceptanceForCandidate($r),'payload_hash'=>self::hash(self::decode($r['payload_json'])),'revision'=>(int)$r['revision'],'status'=>$r['status'],'base_hash'=>$r['base_hash'],'changes'=>self::decode($r['changes_json']),'image_slots'=>self::slots($tenant,$admin,(int)$r['run_id'],$id)];}
    /** Read-only same-origin raster transport, scoped to one exact owned candidate snapshot. */
    public static function candidateCaptureAssets(int $tenant,int $admin,array $parameters): array
    {
        if(array_diff(array_keys($parameters),['candidate_id','revision','candidate_hash']))throw new RuntimeException('截图素材接口仅接受候选版本，不接受URL或资源地址');
        $id=filter_var($parameters['candidate_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $revision=filter_var($parameters['revision']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$hash=$parameters['candidate_hash']??null;
        if($id===false||$revision===false||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new RuntimeException('截图候选版本参数无效');
        $candidate=self::owned('candidate',$tenant,$admin,$id);$payload=self::decode($candidate['payload_json']);
        if(!in_array($candidate['status'],['ready','waiting_images','applied','rolled_back'],true)||(int)$candidate['revision']!==$revision||!hash_equals(self::hash($payload),$hash))throw new RuntimeException('候选已变化或不可截图，请刷新预览');
        DecorationAgentDesignService::assertNoReferenceImages($payload,null,[],$tenant);
        $identities=DecorationAgentAssetReferences::captureIdentities($payload,$tenant);$map=[];$assets=[];$total=0;
        if(count($identities)>30)throw new RuntimeException('候选截图图片数量超过本轮传输上限');
        // No DB locks during storage I/O. Read once per immutable identity, then recheck the snapshot.
        foreach($identities as $value=>$identity){
            $read=DecorationAgentMediaService::readStoredImage($tenant,$identity);$length=strlen($read['bytes']);$total+=$length;
            if($total>20*1024*1024)throw new RuntimeException('候选截图图片总大小超过20MB');
            $data='data:'.$read['mime_type'].';base64,'.base64_encode($read['bytes']);$map[$value]=$data;$map[$read['url']]=$data;
            $assets[]=['value'=>$value,'delivery_url'=>$read['url'],'mime_type'=>$read['mime_type'],'width'=>$read['width'],'height'=>$read['height'],'sha256'=>$read['sha256'],'byte_length'=>$length];
        }
        $fresh=self::owned('candidate',$tenant,$admin,$id);
        if((int)$fresh['revision']!==$revision||$fresh['payload_json']!==$candidate['payload_json']||$fresh['status']!==$candidate['status'])throw new RuntimeException('候选在读取图片时已变化，请刷新预览后截图');
        DecorationAgentDesignService::assertNoReferenceImages($payload,null,[],$tenant);
        return ['candidate_id'=>$id,'revision'=>$revision,'candidate_hash'=>$hash,'image_data_urls'=>$map,'assets'=>$assets];
    }

    public static function discard(int $tenant,int $admin,int $id): array
    {return Db::transaction(function()use($tenant,$admin,$id){$r=self::q('candidate',$tenant,$admin)->where('id',$id)->lock(true)->find();if(!$r)throw new RuntimeException('装修记录不存在或无权访问');if($r['status']==='applied')throw new RuntimeException('已应用的候选请通过撤销恢复');self::q('candidate',$tenant,$admin)->where('id',$id)->update(['status'=>'discarded']);self::q('session',$tenant,$admin)->where('id',$r['session_id'])->where('active_candidate_id',$id)->update(['active_candidate_id'=>0]);self::q('image_slot',$tenant,$admin)->where(['candidate_id'=>$id,'status'=>'queued','consumption_id'=>0])->update(['status'=>'canceled','error'=>'候选已舍弃，图片未提交']);if((int)$r['run_id']>0)self::cancel($tenant,$admin,(int)$r['run_id']);return ['id'=>$id,'status'=>'discarded'];});}
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
            DecorationAgentDesignService::assertCanApply($tenant,$admin,(int)$c['session_id']);
            if(class_exists(DecorationAgentAcceptance::class))DecorationAgentAcceptance::assertCanApply($c,$tenant,$admin);
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
        $hint=self::owned('checkpoint',$tenant,$admin,(int)($p['checkpoint_id']??0));
        return Db::transaction(function()use($tenant,$admin,$p,$key,$hint){
            $candidate=self::q('candidate',$tenant,$admin)->where('id',$hint['candidate_id'])->lock(true)->find();if(!$candidate)throw new RuntimeException('检查点对应候选不存在');
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
        $hint=self::owned('image_slot',$tenant,$admin,(int)($p['slot_id']??0));if((self::decode($hint['request_json'])['purpose']??'')==='design')throw new RuntimeException('设计稿需通过新版本调整，不能直接重试图片位置');return Db::transaction(function()use($tenant,$admin,$p,$hint){
            // Same order as discard/hydrate/publish: candidate, run, then slot.
            $candidate=self::q('candidate',$tenant,$admin)->where('id',$hint['candidate_id'])->lock(true)->find();if(!$candidate||$candidate['status']==='discarded')throw new RuntimeException('已舍弃的候选不能重新生成或重试图片');
            $run=self::q('run',$tenant,$admin)->where('id',$hint['run_id'])->lock(true)->find();if(!$run)throw new RuntimeException('图片所属执行不存在');if($run['status']==='needs_review')throw new RuntimeException('本轮提交或费用仍待核实，不能追加图片调用');
            $slot=self::q('image_slot',$tenant,$admin)->where('id',(int)($p['slot_id']??0))->lock(true)->find();if(!$slot)throw new RuntimeException('图片位置不存在');
            $next=self::q('image_slot',$tenant,$admin)->where(['run_id'=>$slot['run_id'],'slot_key'=>$slot['slot_key'],'attempt'=>(int)$slot['attempt']+1])->lock(true)->find();if($next)return ['slot_id'=>$next['id']];
            if(in_array($run['status'],['queued','running','waiting_images'],true))throw new RuntimeException('当前轮执行尚未结束，请等待完成后重试');
            if($slot['status']!=='failed'&&!($slot['status']==='success'&&($p['regenerate']??false)===true))throw new RuntimeException('只有确定失败的图片允许重试；成功图片需明确选择重新生成');if(self::q('image_slot',$tenant,$admin)->where('run_id',$slot['run_id'])->count()>=5)throw new RuntimeException('本轮图片次数已达上限，请新建一轮继续');
            $requestJson=$slot['request_json'];
            if($slot['status']==='success'){$payload=self::decode($candidate['payload_json']);$request=self::decode($requestJson);$nodes=DecorationAgentOperations::index($payload['data']);$node=$nodes[$request['widget_id']]??null;if(!$node)throw new RuntimeException('图片组件已删除，请新建一轮调整');$request['old_value']=DecorationAgentOperations::get($node['widget'],$request['field']);$request['old_ref']=$node['widget']['_decoration_asset_refs'][$request['field']]??null;$requestJson=self::json($request);}
            $id=self::insert('image_slot',$tenant,$admin,['session_id'=>$slot['session_id'],'run_id'=>$slot['run_id'],'candidate_id'=>$slot['candidate_id'],'slot_key'=>$slot['slot_key'],'request_json'=>$requestJson,'result_json'=>'{}','attempt'=>(int)$slot['attempt']+1]);
            self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->update(['status'=>$slot['status']==='success'?'regenerated':'retried']);self::q('run',$tenant,$admin)->where('id',$slot['run_id'])->update(['status'=>'waiting_images','cancel_requested'=>0,'next_run_time'=>time()]);return ['slot_id'=>$id];
        });
    }
    public static function replaceImage(int $tenant,int $admin,array $p): array
    {
        $slot=self::owned('image_slot',$tenant,$admin,(int)$p['slot_id']);if((self::decode($slot['request_json'])['purpose']??'')==='design')throw new RuntimeException('不能将普通素材替换为设计稿');$a=DecorationAgentMediaService::validateAttachments($tenant,[['file_id'=>(int)$p['file_id'],'uses'=>['asset']]]);$c=self::candidate($tenant,$admin,(int)$slot['candidate_id']);$request=self::decode($slot['request_json']);$map=DecorationAgentOperations::index($c['data']);if(!isset($map[$request['widget_id']]))throw new RuntimeException('图片组件已删除');$request['old_value']=DecorationAgentOperations::get($map[$request['widget_id']]['widget'],$request['field']);$request['old_ref']=$map[$request['widget_id']]['widget']['_decoration_asset_refs'][$request['field']]??null;$slot['request_json']=self::json($request);self::hydrate($slot,(string)$a[0]['url'],null,$a[0]);self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->update(['status'=>'success','result_json'=>self::json(['images'=>$a])]);return self::candidate($tenant,$admin,(int)$slot['candidate_id']);
    }
    public static function tick(int $tenant=0): int
    {
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
        if(!empty($ctx['visual_review'])){
            $freshReview=DecorationAgentAcceptance::validateReview($tenant,$admin,$ctx['visual_review']+['budget_points'=>$input['budget_points'],'idempotency_key'=>'worker:'.$id]);
            if($freshReview['visual_review']['screenshot_sha256']!==$ctx['visual_review']['screenshot_sha256'])throw new RuntimeException('验收截图已变化，请重新提交');
        }
        $links=self::availableLinks($tenant,(int)$s['template_id']);$sources=DecorateDataSourceService::catalog($tenant);
        $model=MarketTextModelRuntimeService::resolveModel($tenant,$input['model_selection'],$input['requires_vision']);$spent=self::spent($r);$input['remaining_budget_points']=max(0,$input['budget_points']-$spent);
        $ctx['run_id']=$id;$context=self::modelContext($tenant,$admin,$s,$input,$ctx);
        $content=self::json($context)."\n用户需求：".$input['message'];
        $estimateText=$content.DecorationAgentDecision::prompt().str_repeat('v',self::visionReferenceCount($input,$ctx)*6000);
        $estimated=self::textEstimate($model,$estimateText);
        if(!empty($ctx['visual_review'])){
            $ctx['budget_preflight']=['projection'=>'visual_review_v1','model_id'=>(int)$model['id'],'input_sku_id'=>(int)$model['market_sku_id'],
                'input_tokens'=>MarketTextModelRuntimeService::estimateTokens($estimateText),'output_tokens'=>4096,'vision_reference_count'=>self::visionReferenceCount($input,$ctx),
                'context_json_bytes'=>strlen(self::json($context)),'estimated_points'=>$estimated,'spent_points'=>$spent,'budget_points'=>$input['budget_points']];
            self::saveContext($id,$ctx);
        }
        if($spent+$estimated>$input['budget_points']){self::finish($id,'budget_exceeded',['error'=>'本轮预算不足以继续，请提高预算后发起新一轮']);return;}
        $references=self::modelReferences($tenant,$admin,$id,$input,$ctx);
        Db::name('decorate_agent_run')->where('id',$id)->update(['status'=>'running','model_calls'=>(int)$r['model_calls']+1,'spent_points'=>$spent]);$ctx['calling']=true;self::saveContext($id,$ctx);self::event($id,'model',['message'=>'理解需求并安排组件','step'=>(int)$r['model_calls']+1]);
        $replyParser=new DecorationAgentReplyStream();$replyStep=(int)$r['model_calls']+1;$replyText='';$replyFlushed='';$replyTime=0.0;
        $publishReply=function(string $text,bool $complete=false)use($id,$replyStep,&$ctx,&$replyText,&$replyFlushed,&$replyTime): void {
            $replyText=$text;
            if (!$complete && ($text===$replyFlushed || microtime(true)-$replyTime<0.25)) return;
            self::fence($id);$ctx['assistant_reply']=$text;$ctx['assistant_step']=$replyStep;$ctx['assistant_complete']=$complete;
            self::event($id,'assistant',['text'=>$text,'step'=>$replyStep,'complete'=>$complete]);$replyFlushed=$text;$replyTime=microtime(true);
        };
        $provider=function()use($tenant,$id,$input,$content,$references,&$ctx,$replyParser,$publishReply){return MarketTextModelRuntimeService::generate($tenant,0,['content'=>$content,'reference_images'=>$references,'model_selection'=>$input['model_selection'],'system_prompt'=>DecorationAgentDecision::prompt(),'max_tokens'=>4096,'request_timeout_seconds'=>180,'action_code'=>'decoration_agent_text','source_app_code'=>'system_default','business_table'=>'decorate_agent_run','business_id'=>$id,'_disable_model_fallback'=>true,'_disable_transient_retry'=>true,'_disable_compatibility_retry'=>true,'_decoration_preserve_uncertain'=>true,'_require_final_content'=>true,'_decoration_native_responses_transport'=>true],function($event,$data)use($id,&$ctx,$replyParser,$publishReply){if($event==='delta'&&empty($data['reasoning'])&&empty($ctx['visual_review']))$publishReply($replyParser->append((string)($data['delta']??'')));if($event==='app_task'){self::fence($id);$ctx['receipts'][]=$data;self::saveContext($id,$ctx);}},true);};
        try{$workflow=new Workflow('decoration:'.$id.':'.((int)$r['model_calls']+1));$workflow->setMaxSteps(1)->addNode(new DecorationAgentDecisionNode($provider));$result=$workflow->run()->get('result');}
        catch(\Throwable $e){$partialReply=empty($ctx['visual_review'])?DecorationAgentDecision::partialReply($e):'';if($partialReply!==''&&str_starts_with($partialReply,$replyText))$replyText=$partialReply;if($replyText!==$replyFlushed){$replyTime=0;$publishReply($replyText);}$latest=end($ctx['receipts']);$receipt=!empty($latest['consumption_id'])?AiConsumptionLog::where('id',$latest['consumption_id'])->find():null;$known=$receipt&&$receipt['run_status']==='failed'&&$receipt['billing_status']==='refunded';if($known){$ctx['calling']=false;self::saveContext($id,$ctx);}self::finish($id,$known?'failed':'needs_review',DecorationAgentDecision::failureDetails($e)+['error'=>'模型调用未完成：'.mb_substr($e->getMessage(),0,800)]);return;}
        $replyTime=0;if(empty($ctx['visual_review']))$publishReply((new DecorationAgentReplyStream())->append(DecorationAgentDecision::finalContent($result)));
        $ctx['calling']=false;$ctx['last_result']=$result;self::fence($id);if(in_array($result['billing']['billing_status']??'', ['pending_usage','pending_confirmation'],true)){self::saveContext($id,$ctx);self::finish($id,'needs_review',['error'=>'模型结果费用待核实，已停止后续调用']);return;}self::saveContext($id,$ctx);
        if(Db::name('decorate_agent_run')->where('id',$id)->value('cancel_requested')){self::finish($id,'canceled',['summary'=>'执行已停止，已发生费用保留']);return;}
        try{$decision=DecorationAgentDecision::parse(DecorationAgentDecision::finalContent($result));if(!DecorationAgentDesignService::designing($input,$ctx))DecorationAgentOperations::validateCreationBindings($decision['operations']);}catch(\InvalidArgumentException $e){$ctx['observations'][]=['error'=>$e->getMessage()];$stop=self::decisionFailureLimitReached($ctx,$e->getMessage());self::saveContext($id,$ctx);if($stop)self::finish($id,'failed',['error'=>'模型重复返回同类无效装修方案，已停止后续调用：'.$e->getMessage()]);return;}
        if(empty($ctx['visual_review']))$publishReply(DecorationAgentDecision::message($decision),true);
        $ctx['facts']=array_slice(array_merge($ctx['facts'],$decision['facts']),-40);
        self::q('session',$tenant,$admin)->where('id',$s['id'])->update(['facts'=>self::json($ctx['facts'])]);
        if($decision['kind']==='clarify'){self::saveContext($id,$ctx);self::finish($id,'awaiting_input',['reply'=>$decision['reply'],'question'=>$decision['question']]);return;}
        $ops=$decision['operations'];$count=(int)$r['tool_calls'];$imageRequests=[];$designRequest=null;
        foreach($ops as $op){
            if(Db::name('decorate_agent_run')->where('id',$id)->value('cancel_requested')){self::finish($id,'canceled',[]);return;}
            if(self::duplicateSourceBinding($ctx,$op,$input))continue;
            if(++$count>20){self::finish($id,'limited',['error'=>'已达到本轮工具次数上限']);return;}
            try{DecorationAgentDesignService::assertOperation($input,$ctx,$op);if($op['type']==='request_design'){if($designRequest!==null)throw new RuntimeException('本轮只生成一张设计稿');$designRequest=DecorationAgentDesignService::request($op,$input,self::generationContext($tenant,$input,$ctx),$ctx['trusted_materials']??[],$tenant,$links,$ctx['design']['parent_reference']??null);self::saveContext($id,$ctx);Db::name('decorate_agent_run')->where('id',$id)->update(['tool_calls'=>$count]);self::event($id,'tool',['message'=>'已安排完整页面设计稿','tool'=>'request_design']);continue;}foreach(['widget_id','parent_id'] as $targetKey)if(isset($op[$targetKey])&&str_starts_with((string)$op[$targetKey],'@')){$alias=substr($op[$targetKey],1);if(!isset($ctx['refs'][$alias]))throw new RuntimeException('本轮组件引用不存在');$op[$targetKey]=$ctx['refs'][$alias];}$query=self::queryTool($tenant,$admin,$s,$input,$ctx,$op,$links,$sources);if($query!==null){$ctx['observations'][]=['tool'=>$op['type'],'result'=>$query];if($op['type']==='search_materials'){$ctx['approved_material_urls']=array_values(array_unique(array_merge($ctx['approved_material_urls']??[],array_column($query,'url'))));$ctx['trusted_materials']=array_values(array_merge($ctx['trusted_materials']??[],$query));}if($op['type']==='icons')$ctx['approved_material_urls']=array_values(array_unique(array_merge($ctx['approved_material_urls']??[],DecorationAgentDesignContext::iconUrls($query))));}else{$scope=['scope'=>$input['scope'],'selected_id'=>$input['selected_id'],'allowed_links'=>$links,'approved_urls'=>array_merge(self::explicitUrls($input['message'],$input['attachments']),$ctx['approved_material_urls']??[]),'approved_design'=>$ctx['approved_design']??null,'blocked_sources'=>$ctx['visual_review']['blocked_sources']??[],'run_id'=>$id,'admin_id'=>$admin,'sources'=>$input['sources'],'attachments'=>$input['attachments'],'reusable_material_manifest'=>$ctx['reusable_material_manifest']??[]];$applied=self::applyVisualOperation($ctx['candidate'],$op,$scope,$ctx['trusted_materials']??[],$tenant);$ctx['candidate']=$applied['candidate'];if($op['type']==='add_component'&&!empty($op['ref'])){$last=end($applied['changes']);$ctx['refs'][(string)$op['ref']]=$last['widget_id'];}$ctx['changes']=array_merge($ctx['changes'],$applied['changes']);$imageRequests=array_merge($imageRequests,$applied['image_requests']);}$ctx['failures'][self::json($op)]=0;}
            catch(\Throwable $e){$key=self::json($op);$ctx['failures'][$key]=($ctx['failures'][$key]??0)+1;$ctx['observations'][]=['tool'=>$op['type'],'error'=>$e->getMessage()];if($ctx['failures'][$key]>1){self::saveContext($id,$ctx);self::finish($id,'failed',['error'=>'重复操作失败：'.$e->getMessage()]);return;}$decision['kind']='tools';}
            self::saveContext($id,$ctx);Db::name('decorate_agent_run')->where('id',$id)->update(['tool_calls'=>$count]);self::event($id,'tool',['message'=>'已处理装修操作','tool'=>$op['type']]);
        }
        if(!empty($ctx['visual_review'])&&($ctx['changes']||$imageRequests))$decision['kind']='final';
        if(DecorationAgentDesignService::designing($input,$ctx)){DecorationAgentDesignService::persist($tenant,$admin,$id,$ctx,$decision,$designRequest);return;}
        self::persistDecision($tenant,$admin,$id,$s,$input,$ctx,$decision,$imageRequests);
    }

    private static function persistDecision(int $tenant,int $admin,int $id,array $s,array $input,array &$ctx,array $decision,array $imageRequests): void
    {
        if($decision['kind']==='tools'&&!$ctx['changes']&&!$imageRequests){self::saveContext($id,$ctx);return;}
        if(!empty($ctx['visual_review'])&&$decision['kind']==='final'&&!$ctx['changes']&&!$imageRequests){
            $review=self::owned('run',$tenant,$admin,$id);$candidate=self::owned('candidate',$tenant,$admin,(int)$review['candidate_id']);
            $review['context_json']=self::json($ctx);$ctx['acceptance']=DecorationAgentAcceptance::record($candidate,$review,$decision['acceptance']??[],false);
            $decision=self::reviewReply($id,$ctx,$decision,false);self::saveContext($id,$ctx);self::finish($id,'completed',['reply'=>$decision['reply'],'question'=>$decision['question'],'acceptance'=>$ctx['acceptance'],'warnings'=>$decision['warnings']]);return;
        }
        // Earlier tool turns may already have created an image-only candidate for this run.
        $runCandidate=(int)self::q('run',$tenant,$admin)->where('id',$id)->value('candidate_id');
        $hasCandidate=$runCandidate>0&&self::q('candidate',$tenant,$admin)->where(['id'=>$runCandidate,'run_id'=>$id])->count()>0;
        // A conversational turn preserves the previous active candidate without creating another.
        if($decision['kind']==='final'&&!$ctx['changes']&&!$imageRequests&&!$hasCandidate){self::saveContext($id,$ctx);self::finish($id,'completed',['reply'=>$decision['reply'],'question'=>$decision['question'],'warnings'=>$decision['warnings']]);return;}
        Db::transaction(function()use($tenant,$admin,$id,$s,$input,&$ctx,$decision,$imageRequests){
        $snapshot=Db::name('decorate_agent_run')->where('id',$id)->find();$cId=(int)$snapshot['candidate_id'];
        $existing=$cId?self::q('candidate',$tenant,$admin)->where('id',$cId)->lock(true)->find():null;
        $r=Db::name('decorate_agent_run')->where('id',$id)->lock(true)->find();self::fence($id);
        if((int)$r['cancel_requested']||($existing&&$existing['status']==='discarded')){self::finish($id,'canceled',['summary'=>'候选已停止或舍弃，后续操作未应用']);return;}
        if(!$cId){$cId=self::insert('candidate',$tenant,$admin,['session_id'=>$s['id'],'run_id'=>$id,'base_hash'=>self::hash($ctx['base']),'base_json'=>self::json($ctx['base']),'status'=>'building','payload_json'=>self::json($ctx['candidate']),'changes_json'=>self::json($ctx['changes'])]);Db::name('decorate_agent_run')->where('id',$id)->update(['candidate_id'=>$cId]);}
        else{self::q('candidate',$tenant,$admin)->where('id',$cId)->inc('revision')->update(['payload_json'=>self::json($ctx['candidate']),'changes_json'=>self::json($ctx['changes'])]);}
        foreach ($imageRequests as $req) {
            if (!in_array('generate',$input['sources'],true)) {$ctx['observations'][]=['error'=>'未授权AI生图'];$decision['kind']='tools';continue;}
            $key=(string)$req['widget_id'].':'.(string)$req['field'];
            $pendingSlot=self::q('image_slot',$tenant,$admin)->where(['run_id'=>$id,'candidate_id'=>$cId,'slot_key'=>$key])
                ->whereIn('status',['queued','running','submitting'])->find();
            if ($pendingSlot) {
                self::acknowledgeImage($ctx,(int)$pendingSlot['id'],$req,(string)$pendingSlot['status'],true);
                continue;
            }
            if (self::q('image_slot',$tenant,$admin)->where('run_id',$id)->count()>=5) {$ctx['observations'][]=['error'=>'已达到五张图片上限'];continue;}
            $slotId=self::insert('image_slot',$tenant,$admin,['session_id'=>$s['id'],'run_id'=>$id,'candidate_id'=>$cId,'slot_key'=>$key,'request_json'=>self::json($req),'result_json'=>'{}']);
            self::acknowledgeImage($ctx,$slotId,$req,'queued',false);
        }
        self::saveContext($id,$ctx);self::q('session',$tenant,$admin)->where('id',$s['id'])->update(['active_candidate_id'=>$cId,'facts'=>self::json($ctx['facts'])]);
        if($decision['kind']==='final'){$summary=count($ctx['changes']).'项装修调整已生成，请预览后应用。';$pending=self::q('image_slot',$tenant,$admin)->where(['run_id'=>$id,'candidate_id'=>$cId])->whereIn('status',['queued','running','submitting'])->count();self::q('candidate',$tenant,$admin)->where('id',$cId)->update(['status'=>$pending?'waiting_images':'ready']);if(!empty($ctx['visual_review'])){$review=self::owned('run',$tenant,$admin,$id);$review['context_json']=self::json($ctx);$ctx['acceptance']=DecorationAgentAcceptance::record(self::owned('candidate',$tenant,$admin,$cId),$review,$decision['acceptance']??[],true);$decision=self::reviewReply($id,$ctx,$decision,true);self::saveContext($id,$ctx);}self::finish($id,$pending?'waiting_images':'completed',['reply'=>$decision['reply'],'question'=>$decision['question'],'summary'=>$summary,'warnings'=>$decision['warnings']]);}
        });
    }

    private static function reviewReply(int $id,array &$context,array $decision,bool $hadWrites): array
    {
        $text=$hadWrites?'差异修复已生成候选，请重新截图复审；本轮尚未通过验收。':(!empty($context['acceptance']['passed'])?'当前版本通过模型视觉对照和交互配置检查；真实点击与业务流程仍需实际验证。':'本轮未通过当前版本验收，请查看检查结果并重新截图或补充证据。');
        $context['assistant_reply']=$text;$context['assistant_complete']=true;$context['assistant_step']=(int)Db::name('decorate_agent_run')->where('id',$id)->value('model_calls');
        self::saveContext($id,$context);self::event($id,'assistant',['text'=>$text,'step'=>$context['assistant_step'],'complete'=>true]);
        $decision['reply']=$text;$decision['question']='';return $decision;
    }

    private static function acknowledgeImage(array &$ctx,int $slotId,array $request,string $status,bool $reused): void
    {
        $ctx['observations'][]=['tool'=>'request_image','result'=>[
            'slot_id'=>$slotId,'widget_id'=>(string)$request['widget_id'],'field'=>(string)$request['field'],
            'status'=>$status,'reused'=>$reused,'message'=>'该图片位置已有任务，后续无需重复请求；结束编排后将等待图片完成。',
        ]];
    }

    private static function duplicateSourceBinding(array $ctx,array $op,array $input): bool
    {
        if(($op['type']??'')!=='bind_source')return false;
        $id=(string)($op['widget_id']??'');
        if(str_starts_with($id,'@')){$alias=substr($id,1);if(!isset($ctx['refs'][$alias]))return false;$op['widget_id']=$ctx['refs'][$alias];}
        try{return DecorationAgentOperations::isUnchangedBinding($ctx['candidate'],$op,['scope'=>$input['scope'],'selected_id'=>$input['selected_id']??'']);}
        catch(\InvalidArgumentException $e){return false;}
    }

    private static function decisionFailureLimitReached(array &$ctx,string $reason): bool
    {
        $ctx['decision_failures'][$reason]=($ctx['decision_failures'][$reason]??0)+1;
        return $ctx['decision_failures'][$reason]>=2;
    }
    private static function explicitUrls(string $message,array $attachments): array
    {preg_match_all('~https?://[^\s<>"\x{3000}-\x{9fff}]+~u',$message,$m);return array_values(array_unique(array_merge($m[0],array_column(array_filter($attachments,fn($a)=>in_array('asset',$a['uses']??[],true)),'url'))));}
    private static function queryTool(int $tenant,int $admin,array $s,array $input,array $ctx,array $op,array $links,array $sources): ?array
    {
        return match($op['type']){
            'get_context'=>self::modelContext($tenant,$admin,$s,$input,$ctx),
            'catalog'=>DecorationAgentDesignContext::discover($op),
            'data_sources'=>empty($op['key'])?$sources: ['key'=>$op['key'],'items'=>DecorateDataSourceService::resolve((string)$op['key'],array_intersect_key((array)($op['params']??[]),array_flip(['limit','sort','terminal'])),$tenant,['user_id'=>0,'terminal'=>'uniapp'])],'links'=>$links,
            'search_materials'=>DecorationAgentMediaService::search($tenant,(string)($op['query']??''),$input['sources'],$input['attachments'],min(12,(int)($op['limit']??12))),
            'icons'=>DecorationAgentDesignContext::icons((string)($op['query']??'')),
            'validate'=>DecorationAgentOperations::apply($ctx['candidate'],[],['scope'=>$input['scope'],'selected_id'=>$input['selected_id'],'allowed_links'=>$links,'approved_urls'=>array_merge(self::explicitUrls($input['message'],$input['attachments']),$ctx['approved_material_urls']??[])]),
            default=>null};
    }
    private static function spent(array $run): float
    {
        $sum=0;$ctx=self::decode($run['context_json']);foreach($ctx['receipts']??[] as $receipt){$c=AiConsumptionLog::where('id',(int)($receipt['consumption_id']??0))->find();if($c)$sum+=(float)$c['actual_tenant_cost'];}
        foreach(Db::name('decorate_agent_image_slot')->where('run_id',$run['id'])->where('consumption_id','>',0)->column('consumption_id') as $id){$c=AiConsumptionLog::where('id',$id)->find();if($c)$sum+=$c['billing_status']==='refunded'?0.0:($c['billing_status']==='settled'?(float)$c['actual_tenant_cost']:(float)$c['reserved_tenant_cost']);}return $sum;
    }
    public static function finishDesign(int $id,string $status,array $result): void {self::fence($id);self::finish($id,$status,$result);}
    public static function confirmDesign(int $tenant,int $admin,array $p): array {$design=self::owned('run',$tenant,$admin,(int)($p['run_id']??0));self::assertOperator($tenant,$admin,(string)(self::decode($design['input_json'])['scope']??'page'));return DecorationAgentDesignService::confirm($tenant,$admin,$p);}

    private static function finish(int $id,string $status,array $result): void
    {
        // Publish the terminal state, event and conversation message as one visible checkpoint.
        Db::transaction(function()use($id,$status,$result): void {
            $hint=Db::name('decorate_agent_run')->where('id',$id)->find();if(!$hint)return;
            $candidate=(int)$hint['candidate_id']>0?self::q('candidate',(int)$hint['tenant_id'],(int)$hint['admin_id'])->where('id',$hint['candidate_id'])->lock(true)->find():null;
            $r=Db::name('decorate_agent_run')->where('id',$id)->lock(true)->find();if(!$r)return;self::fence($id);
            if(!in_array($status,['queued','running','waiting_images'],true))self::cancelUnsubmittedImages($r,$candidate&&(int)$candidate['id']===(int)$r['candidate_id']?$candidate:null);
            $previous=self::decode($r['result_json']);if($status==='completed'&& !isset($result['reply']) && isset($previous['reply']))$result+=array_intersect_key($previous,array_flip(['reply','question']));
            Db::name('decorate_agent_run')->where('id',$id)->update(['status'=>$status,'result_json'=>self::json($result),'spent_points'=>self::spent($r),'next_run_time'=>time()+($status==='waiting_images'?3:0),'update_time'=>time()]);
            self::event($id,$status,$result);
            if(in_array($status,['completed','awaiting_input','awaiting_design_confirmation','failed','limited','budget_exceeded','needs_review','canceled'],true)){
                $content=DecorationAgentDecision::message($result)?:((string)($result['summary']??$result['error']??'执行已结束'));
                // Retry completion may reuse the same reply. The locked run serializes exact-message checks.
                $exists=self::q('message',(int)$r['tenant_id'],(int)$r['admin_id'])->where(['session_id'=>$r['session_id'],'run_id'=>$id,'role'=>'assistant'])->whereRaw('BINARY content = ?',[$content])->find();
                if(!$exists)self::insert('message',(int)$r['tenant_id'],(int)$r['admin_id'],['session_id'=>$r['session_id'],'run_id'=>$id,'role'=>'assistant','content'=>$content,'attachments'=>'[]']);
            }
        });
    }
    private static function processImages(array $r,array $input): void
    {
        $tenant=(int)$r['tenant_id'];$admin=(int)$r['admin_id'];$pending=0;$uncertain=false;
        foreach(self::slots($tenant,$admin,(int)$r['id']) as $slot){if(!in_array($slot['status'],['queued','running','submitting'],true))continue;$request=$slot['request'];$extracting=($request['generation_mode']??'')==='design_extract';$request['attachments']=$extracting?[]:$input['attachments'];self::assertOperator($tenant,$admin,$input['scope']);$request['reference_file_ids']=(array)($request['reference_file_ids']??[]);$request['reference_images']=[];self::fence((int)$r['id']);if(Db::name('decorate_agent_run')->where('id',$r['id'])->value('cancel_requested')){self::finish((int)$r['id'],'canceled',[]);return;}
            if(!$extracting)foreach($input['attachments'] as $a)if(in_array($a['file_id'],(array)($request['reference_file_ids']??[]),true)||in_array('generation',$a['uses'],true))$request['reference_file_ids'][]=$a['file_id'];$request['reference_file_ids']=array_values(array_unique($request['reference_file_ids']));
            try{
                if($slot['status']==='submitting'){$uncertain=true;continue;}
                if($slot['status']==='queued'){
                    $quote=DecorationAgentMediaService::quote($tenant,$input['image_model_selection'],1,$request,(int)$slot['id']);if(($quote['cost_known']??true)===false)throw new RuntimeException('该图片规格按实际用量计费，无法在本轮预算内预估，请选择按次规格');$cost=(float)($quote['tenant_cost_points']??$quote['tenant_cost']??0);
                    if($extracting&&isset($request['quoted_unit_points'])&&abs($cost-(float)$request['quoted_unit_points'])>0.000001)throw new RuntimeException('图片价格已变化，请重新报价后发起新一轮');
                    if(self::spent($r)+$cost>$input['budget_points'])throw new RuntimeException('图片费用超过本轮剩余预算');
                    $receipt=Db::transaction(function()use($tenant,$admin,$slot,$r,$input,$request){
                        if(($slot['request']['purpose']??'')==='design')DecorationAgentDesignService::assertSubmission($slot,$r);
                        else{$candidate=self::q('candidate',$tenant,$admin)->where('id',$slot['candidate_id'])->lock(true)->find();if(!$candidate||$candidate['status']==='discarded')throw new RuntimeException('候选已舍弃，图片提交已停止');}
                        self::fence((int)$r['id']);$locked=self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->lock(true)->find();if($locked['status']!=='queued')throw new RuntimeException('图片任务已被处理');
                        if(Db::name('decorate_agent_run')->where('id',$r['id'])->value('cancel_requested'))throw new RuntimeException('任务已停止');
                        $receipt=DecorationAgentMediaService::reserve($tenant,$admin,(int)$slot['id'],$input['image_model_selection'],$request);
                        self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->update(['consumption_id'=>$receipt['consumption_id'],'status'=>'submitting']);return $receipt;
                    });
                    self::fence((int)$r['id']);if(Db::name('decorate_agent_run')->where('id',$r['id'])->value('cancel_requested')){\app\common\service\power\MarketImageModelRuntimeService::cancel((int)$receipt['consumption_id']);self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->update(['status'=>'canceled']);self::finish((int)$r['id'],'canceled',[]);return;}
                    $result=DecorationAgentMediaService::submit((int)$receipt['consumption_id'],$request);
                }else{$result=DecorationAgentMediaService::poll($tenant,(int)$slot['consumption_id']);}
                $status=$result['status']??'running';self::fence((int)$r['id']);if(in_array($status,['pending_confirmation','needs_review','unknown'],true)){$uncertain=true;self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->update(['status'=>'needs_review','result_json'=>self::json($result)]);continue;}
                if($status==='success'){self::completeImage($slot,$result);self::event((int)$r['id'],'image',['message'=>'配图已完成','slot_id'=>$slot['id']]);}
                elseif(in_array($status,['failed','canceled'],true))throw new RuntimeException((string)($result['error']??'图片生成失败'));
                else{$pending++;self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->update(['status'=>'running','result_json'=>self::json($result)]);}
            }catch(\Throwable $e){self::fence((int)$r['id']);$current=self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->find();$billing=!empty($current['consumption_id'])?AiConsumptionLog::where('id',$current['consumption_id'])->find():null;$ambiguous=($current['status']??'')==='submitting'||($billing&&$billing['billing_status']==='settled');if($ambiguous)$uncertain=true;self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->update(['status'=>$ambiguous?'needs_review':'failed','error'=>mb_substr($e->getMessage(),0,1000)]);}
        }
        if($uncertain){self::finish((int)$r['id'],'needs_review',['error'=>'部分图片提交结果待核实，不自动重复生成']);return;}
        if(!$pending&&($input['design_first']??false)){
            $fresh=Db::name('decorate_agent_run')->where('id',$r['id'])->find();$draft=DecorationAgentDesignService::draft($fresh);
            if(($draft['status']??'')==='awaiting_confirmation')self::finish((int)$r['id'],'awaiting_design_confirmation',['reply'=>'设计稿已生成，请确认后开始页面实施。']);
            elseif(($draft['status']??'')==='canceled')self::finish((int)$r['id'],'canceled',['reply'=>'设计生成已停止。']);else self::finish((int)$r['id'],'failed',['error'=>'设计图未形成可确认结果，页面未开始实施。']);return;
        }
        if(!$pending){self::q('candidate',$tenant,$admin)->where('id',$r['candidate_id'])->where('status','waiting_images')->update(['status'=>'ready']);$failed=!empty($input['images_only'])&&self::q('image_slot',$tenant,$admin)->where('run_id',$r['id'])->whereIn('status',['failed','canceled'])->count()>0;self::finish((int)$r['id'],$failed?'failed':'completed',['summary'=>$failed?'素材提取未完成，原候选与页面未改变，请查看图片错误。':'候选页面已生成，失败图片可单独重试']);}
        else Db::name('decorate_agent_run')->where('id',$r['id'])->update(['next_run_time'=>time()+3,'spent_points'=>self::spent($r)]);
    }
    private static function applyVisualOperation(array $page,array $op,array $scope,array $materials,int $tenant): array
    {
        $manifest=$scope['reusable_material_manifest']??[];
        $valid=DecorationAgentAssetReferences::refreshMaterials($manifest,$tenant,$scope['sources']??[],$scope['attachments']??[]);
        $scope['approved_urls']=array_values(array_filter($scope['approved_urls']??[],fn($url)=>!DecorationAgentAssetReferences::managedValue($url,$manifest)));
        $materials=array_values(array_filter($materials,fn($material)=>!DecorationAgentAssetReferences::managedValue((string)($material['url']??''),$manifest)));
        foreach($valid as $entry)foreach($entry['aliases'] as $alias){$scope['approved_urls'][]=$alias;$materials[]=array_replace($entry,['url'=>$alias]);}
        // Capture an image request only after its authorized source has a durable identity.
        if (($op['type'] ?? '') === 'request_image') $page=self::attachScopedMaterials($page,$materials,$tenant,$scope);
        if(($op['type']??'')==='request_image'&&array_intersect((array)($op['reference_file_ids']??[]),DecorationAgentMediaService::evidenceFileIds($tenant)))throw new RuntimeException('验收截图不能作为独立素材生成参考');
        if(($op['type']??'')==='request_image'&&isset($op['generation_mode'])){DecorationAgentDesignExtraction::operation($op);if(empty($scope['approved_design']))throw new RuntimeException('素材提取需要本轮已确认的设计稿');}
        if(($op['type']??'')==='request_image'&&!empty($scope['approved_design'])&&!array_key_exists('reference_design_slot_id',$op))$op['reference_design_slot_id']=(int)$scope['approved_design']['slot_id'];
        if(($op['type']??'')==='request_image'&&array_key_exists('reference_design_slot_id',$op)){
            if(!is_int($op['reference_design_slot_id'])||$op['reference_design_slot_id']<=0)throw new RuntimeException('设计参考位置无效');
            DecorationAgentDesignService::approvedForRun($tenant,(int)($scope['admin_id']??0),(int)($scope['run_id']??0),$op['reference_design_slot_id']);
        }
        $applied=DecorationAgentOperations::apply($page,[$op],$scope);
        if(isset($op['reference_design_slot_id']))foreach($applied['image_requests'] as &$request)$request['reference_design_slot_id']=$op['reference_design_slot_id'];unset($request);
        DecorationAgentAssetReferences::assertManagedReuse($applied['candidate'],$manifest,$valid,$page);
        DecorationAgentDesignService::assertNoReferenceImages($applied['candidate'],$scope['approved_design']??null,$scope['blocked_sources']??[],$tenant);
        $applied['candidate']=self::attachScopedMaterials($applied['candidate'],$materials,$tenant,$scope);
        $fresh=DecorationAgentAssetReferences::refreshMaterials($manifest,$tenant,$scope['sources']??[],$scope['attachments']??[]);
        DecorationAgentAssetReferences::assertManagedBindings($applied['candidate'],$manifest,$fresh,$page);
        return $applied;
    }

    private static function attachScopedMaterials(array $page,array $materials,int $tenant,array $scope): array
    {
        if (!$materials) return $page;
        if (($scope['scope'] ?? '') !== 'selected') return DecorationAgentAssetReferences::attachMaterials($page,$materials,$tenant);
        $index=DecorationAgentOperations::index($page['data'] ?? []);
        $selected=$index[(string)($scope['selected_id'] ?? '')] ?? null;
        if (!$selected) throw new RuntimeException('选中的组件不存在');
        $node=&$page['data'];
        foreach ($selected['path'] as $depth=>$position) {
            if ($depth>0) $node=&$node['children'];
            $node=&$node[$position];
        }
        $converted=DecorationAgentAssetReferences::attachMaterials(['data'=>[$node],'meta'=>[]],$materials,$tenant);
        $node=$converted['data'][0];
        unset($node);
        return $page;
    }

    private static function completeImage(array $slot,array $result): void
    {
        if((self::decode($slot['request_json']??$slot['request']??[])['purpose']??'')==='design'){DecorationAgentDesignService::complete($slot,$result);return;}
        $tenant=(int)$slot['tenant_id'];$admin=(int)$slot['admin_id'];$asset=$result['images'][0]??null;
        if(!is_array($asset)||empty($asset['image_uri'])||empty($asset['storage_scope'])||empty($asset['storage_engine']))throw new RuntimeException('图片尚未形成可保存的资源');
        $url=(string)($asset['url']??$asset['image_uri']);
        Db::transaction(function()use($tenant,$admin,$slot,$result,$asset,$url): void {
            // Completion, cancellation and finalization share candidate -> run -> slot order.
            self::q('candidate',$tenant,$admin)->where('id',$slot['candidate_id'])->lock(true)->find();
            self::fence((int)$slot['run_id']);
            self::q('image_slot',$tenant,$admin)->where('id',$slot['id'])->update(['status'=>'success','result_json'=>self::json($result)]);
            self::hydrate($slot,$url,$asset);
        });
    }

    private static function hydrate(array $slot,string $url,?array $asset=null,?array $material=null): void
    {
        $tenant=(int)$slot['tenant_id'];$admin=(int)$slot['admin_id'];$request=self::decode($slot['request_json']??$slot['request']??[]);
        Db::transaction(function()use($tenant,$admin,$slot,$request,$url,$asset,$material){$c=self::q('candidate',$tenant,$admin)->where('id',$slot['candidate_id'])->lock(true)->find();if(!$c||in_array($c['status'],['discarded','rolled_back'],true))return;
            $payload=self::decode($c['payload_json']);$index=DecorationAgentOperations::index($payload['data']);$node=$index[$request['widget_id']]??null;if(!$node||DecorationAgentOperations::get($node['widget'],$request['field'])!==($request['old_value']??null)||($node['widget']['_decoration_asset_refs'][$request['field']]??null)!==($request['old_ref']??null)){self::event((int)$slot['run_id'],'image_detached',['message'=>'图片位置已被修改，素材已保留']);return;}if(($request['old_value']??null)===$url)return;$op=['type'=>'update_component','widget_id'=>$request['widget_id'],'fields'=>[$request['field']=>$url]];$new=DecorationAgentOperations::apply($payload,[$op],['scope'=>'page','approved_urls'=>[$url],'allowed_links'=>[]]);
            if($asset!==null)$new['candidate']=DecorationAgentAssetReferences::attachGenerated($new['candidate'],$slot,$asset);
            elseif($material!==null)$new['candidate']=self::attachScopedMaterials($new['candidate'],[$material],$tenant,['scope'=>'selected','selected_id'=>$request['widget_id']]);
            $old=$payload;self::q('candidate',$tenant,$admin)->where('id',$c['id'])->inc('revision')->update(['payload_json'=>self::json($new['candidate'])]);
            if($c['status']==='applied'){$s=self::owned('session',$tenant,$admin,(int)$c['session_id']);$page=self::page($tenant,(int)$s['template_id'],(int)$c['applied_page_id'],true);$current=self::payload($page);$old['new_page']=false;$target=$new['candidate'];$target['new_page']=false;$merge=DecorationAgentMerge::merge($old,$current,$target);if($merge['conflicts'])return;
                $saved=DecorateTemplateService::savePage($tenant,['template_id'=>$s['template_id'],'id'=>$page['id'],'terminal'=>'mobile','data'=>self::json($merge['merged']['data']),'meta'=>self::json($merge['merged']['meta'])]);self::insert('checkpoint',$tenant,$admin,['session_id'=>$s['id'],'candidate_id'=>$c['id'],'page_id'=>$page['id'],'kind'=>'image','before_json'=>self::json($current),'after_json'=>self::json(self::payload($saved)),'before_hash'=>self::hash($current),'after_hash'=>self::hash(self::payload($saved)),'idempotency_key'=>'image:'.$slot['id'].':'.hash('sha256',$url)]);
                $group=self::q('checkpoint',$tenant,$admin)->where(['candidate_id'=>$c['id'],'page_id'=>$page['id']])->whereIn('kind',['apply','create'])->order('id','desc')->lock(true)->find();
                if($group){$groupAfter=self::decode($group['after_json']);$advanced=DecorationAgentOperations::apply($groupAfter,[$op],['scope'=>'page','approved_urls'=>[$url],'allowed_links'=>[]])['candidate'];if($asset!==null)$advanced=DecorationAgentAssetReferences::attachGenerated($advanced,$slot,$asset);elseif($material!==null)$advanced=self::attachScopedMaterials($advanced,[$material],$tenant,['scope'=>'selected','selected_id'=>$request['widget_id']]);self::q('checkpoint',$tenant,$admin)->where('id',$group['id'])->update(['after_json'=>self::json($advanced),'after_hash'=>self::hash($advanced)]);}
            }
        });
    }
}
