<?php
declare(strict_types=1);
namespace app\common\service\decorate\agent;

use app\common\service\FileService;
use think\facade\Db;
use RuntimeException;

/** Approval metadata is durable; design images remain references, never DIY content. */
final class DecorationAgentDesignService
{
    public static function designing(array $input,array $context): bool
    {return ($input['design_first']??false)===true&&empty($context['approved_design']);}

    private static function ownedRun(int $tenant,int $admin,int $id): array
    {
        $run=Db::name('decorate_agent_run')->where(['id'=>$id,'tenant_id'=>$tenant,'admin_id'=>$admin])->find();
        if(!$run)throw new RuntimeException('设计执行不存在或无权访问');
        return $run;
    }

    public static function initialState(int $tenant,int $admin,array $session,array $input): array
    {
        $state=DecorationAgentService::decode($session['preferences'])['_design_state']??[];
        $latest=(int)($state['run_id']??0);$parent=(int)($input['design_parent_run_id']??0);
        if($parent&&$parent!==$latest)throw new RuntimeException('设计版本已更新，请使用最新设计稿调整');
        $reference=null;$prior=[];
        if($latest){
            $old=self::ownedRun($tenant,$admin,$latest);
            if((int)$old['session_id']!==(int)$session['id'])throw new RuntimeException('设计会话不匹配');
            if($old['status']==='needs_review')throw new RuntimeException('上次设计提交或费用待核实，不能重新生成');
            $design=DecorationAgentService::decode($old['context_json'])['design']??[];
            if((int)$old['cancel_requested']||($design['status']??'')==='canceled'){
                if($parent)throw new RuntimeException('已取消设计不能作为调整参考');$latest=0;
            }elseif(in_array($design['status']??'',['awaiting_confirmation','approved'],true)&&!empty($design['asset'])){
                $asset=DecorationAgentAssetReferences::designAsset((int)$design['slot_id'],$tenant,$admin);
                if(!$asset||!self::sameAsset($asset,$design['asset'])||self::designHash($design)!==($design['hash']??''))throw new RuntimeException('上一版设计资源或哈希已变化');
                $reference=['run_id'=>$latest]+array_intersect_key($design,array_flip(['revision','hash','slot_id','blueprint','asset']));
            }elseif(in_array($old['status'],['failed','awaiting_input','budget_exceeded','limited'],true)&&empty($design['asset'])){$prior=$design['blueprint']??[];}
            else throw new RuntimeException('上一版设计尚不可用作调整参考');
        }
        return ['status'=>'planning','revision'=>(int)($state['revision']??0)+1,'parent_run_id'=>$parent?:$latest,'parent_reference'=>$reference,'prior_blueprint'=>$prior,'slot_id'=>0,'prompt'=>'','blueprint'=>[],'hash'=>''];
    }

    /** Called under the existing session start lock. */
    public static function registerStart(int $tenant,int $admin,int $id,array $session,array $design): void
    {
        $preferences=DecorationAgentService::decode(Db::name('decorate_agent_session')->where('id',$session['id'])->value('preferences'));
        $previous=(int)($preferences['_design_state']['run_id']??0);
        if($previous){
            $old=self::ownedRun($tenant,$admin,$previous);$ctx=DecorationAgentService::decode($old['context_json']);
            if(isset($ctx['design'])){$ctx['design']['status']='superseded';Db::name('decorate_agent_run')->where('id',$previous)->update(['context_json'=>DecorationAgentService::json($ctx)]);DecorationAgentService::event($previous,'design_superseded',['revision'=>$design['revision']]);}
        }
        $preferences['_design_state']=['run_id'=>$id,'revision'=>$design['revision']];
        Db::name('decorate_agent_session')->where(['id'=>$session['id'],'tenant_id'=>$tenant,'admin_id'=>$admin])->update(['preferences'=>DecorationAgentService::json($preferences)]);
    }

    public static function assertOperation(array $input,array $context,array $operation): void
    {
        if(!self::designing($input,$context)){
            if(($operation['type']??'')==='request_design')throw new RuntimeException('当前轮不是设计稿生成阶段');
            return;
        }
        if(!in_array($operation['type']??'',['request_design','get_context','catalog','data_sources','links','search_materials','icons','validate'],true))throw new RuntimeException('请先生成并确认设计稿，确认前不能修改页面或生成独立素材');
    }

    public static function request(array $operation,array $input,array $generation,array $materials=[],int $tenant=0,array $links=[],?array $parentReference=null): array
    {
        if(!in_array('generate',$input['sources'],true))throw new RuntimeException('未授权生成设计稿');
        $prompt=trim((string)($operation['prompt']??''));$blueprint=$operation['blueprint']??null;
        if($prompt===''||mb_strlen($prompt)>8000||!is_array($blueprint)||$blueprint===[]||strlen(DecorationAgentService::json($blueprint))>12000)throw new RuntimeException('请提供完整设计图提示词和有界页面规划');
        $regions=$blueprint['regions']??null;$components=[];
        if(!is_array($regions)||!array_is_list($regions)||count($regions)<1||count($regions)>20)throw new RuntimeException('设计规划需要有界regions及真实组件');
        foreach($regions as $region){
            if(!is_array($region)||!is_array($region['components']??null)||!array_is_list($region['components'])||!$region['components'])throw new RuntimeException('每个设计区域必须声明实际components');
            foreach($region['components'] as $component){$spec=is_string($component)?['name'=>$component]:$component;if(!is_array($spec)||isset($spec['id']))throw new RuntimeException('设计组件规范无效或自行填写ID');DecorationAgentCatalog::definition((string)($spec['name']??''));$components[]=$spec;}
        }
        $urls=array_column($materials,'url');
        foreach($materials as $material){$identity=DecorationAgentAssetReferences::materialAsset($material,$tenant);if($identity)$urls[]='/'.ltrim($identity['image_uri'],'/');}
        // Validate renderable overrides in memory only: this never creates a durable candidate.
        DecorationAgentOperations::apply(['data'=>[],'meta'=>[]],[['type'=>'replace_page','components'=>$components]],['scope'=>'page','approved_urls'=>$urls,'allowed_links'=>$links]);
        $ratios=$generation['selected_model']['ratio_options']??[];$ratio=(string)($operation['ratio']??(in_array('9:16',$ratios,true)?'9:16':($ratios[0]??'')));
        if($ratio===''||!in_array($ratio,$ratios,true))throw new RuntimeException('设计图比例不在当前模型支持范围内');
        $ids=$operation['reference_material_ids']??array_column($parentReference['blueprint']['selected_materials']??[],'id');
        if(!is_array($ids)||!array_is_list($ids)||count($ids)>5||count(array_filter($ids,'is_string'))!==count($ids))throw new RuntimeException('设计素材参考标识无效');
        $maxReferences=(int)($generation['selected_model']['max_reference_images']??0);
        $referenceCount=count($ids)+($parentReference?1:0)+count(array_filter($input['attachments']??[],fn($attachment)=>in_array('generation',$attachment['uses']??[],true)));
        if($maxReferences>0&&$referenceCount>$maxReferences)throw new RuntimeException('设计素材参考超过当前模型允许数量');
        if($referenceCount&&!($generation['selected_model']['supports_reference_images']??false))throw new RuntimeException('当前生图模型不支持素材参考图');
        $selected=[];$byId=array_column($materials,null,'id');$builtins=array_column(DecorationAgentMediaService::builtinMaterials(),null,'id');
        foreach(array_unique($ids) as $id){
            $material=$byId[$id]??null;if(!$material)throw new RuntimeException('素材参考必须来自本轮授权搜索结果');
            if(!in_array($material['source']??'',$input['sources'],true))throw new RuntimeException('设计素材来源未授权');
            if(isset($builtins[$id])){$selected[]=['id'=>$id,'kind'=>'builtin','source'=>'content','uri'=>$builtins[$id]['uri']];continue;}
            $asset=DecorationAgentAssetReferences::materialAsset($material,$tenant);if(!$asset)throw new RuntimeException('设计素材缺少可核实的存储身份');
            $selected[]=['id'=>$id,'kind'=>'stored','source'=>$material['source'],'file_id'=>(int)($material['file_id']??0),'slot_id'=>(int)($material['slot_id']??0),'asset'=>array_intersect_key($asset,array_flip(['image_uri','storage_scope','storage_engine','storage_domain']))];
        }
        $replacements=[];
        foreach($materials as $material){$asset=DecorationAgentAssetReferences::materialAsset($material,$tenant);if($asset&&is_string($material['url']??null))$replacements[$material['url']]='/'.ltrim($asset['image_uri'],'/');}
        foreach($blueprint['regions'] as &$region)foreach($region['components'] as &$spec)if(is_array($spec))$spec=self::freezeSpec($spec,$replacements);
        unset($region,$spec);
        $blueprint['selected_materials']=$selected;
        $referenceOrder=$parentReference?'第一张参考图是上一版完整UI，之后按selected_materials顺序对应真实素材。':'参考图按selected_materials顺序对应真实素材。';
        if($parentReference)$prompt='这是对上一版设计稿的调整：第一张参考图是上一版完整UI，保留未要求变更的主视觉、图标、入口及布局，仅修改明确要求的区域。后续参考图依次对应selected_materials。'.$prompt;
        return ['purpose'=>'design','prompt'=>'生成完整移动页面UI设计稿，展示页面从顶部到全部内容区及底部的整体布局。不是装饰主视觉，不是单张背景；文字、图标、卡片和间距必须清晰。依据可用组件和真实内容，不能虚构业务案例。使用所提供的真实素材参考，保持素材内容和已规划参数，不把它们替换为虚构图片。'.$prompt."\n严格遵循以下可实现组件规划；".$referenceOrder."URI/ID仅是资源标识，不要把它们画进页面：".DecorationAgentService::json($blueprint),'blueprint'=>$blueprint,'ratio'=>$ratio,'reference_file_ids'=>[],'design_materials'=>$selected,'design_parent'=>$parentReference];
    }

    private static function freezeSpec(array $spec,array $replacements): array
    {
        $definition=DecorationAgentCatalog::definition($spec['name']);
        foreach(['content'=>'content_fields','styles'=>'style_fields'] as $section=>$fields)foreach($definition[$fields]??[] as $key=>$schema)if(($schema['type']??'')==='image'&&is_string($spec[$section][$key]??null))$spec[$section][$key]=$replacements[$spec[$section][$key]]??$spec[$section][$key];
        $freezeRows=function(array $rows,array $list)use($replacements): array {foreach($rows as &$row)foreach($list['fields']??[] as $key=>$schema)if(($schema['type']??'')==='image'&&is_string($row[$key]??null))$row[$key]=$replacements[$row[$key]]??$row[$key];unset($row);return $rows;};
        foreach($definition['lists']??[] as $key=>$list)if(isset($spec['content'][$key]))$spec['content'][$key]=$freezeRows($spec['content'][$key],$list);
        foreach($spec['fields']??[] as $path=>$value){
            if(preg_match('/^(content|styles)\.([a-zA-Z0-9_-]+)(?:\.(\d+)\.([a-zA-Z0-9_-]+))?$/D',$path,$parts)){
                $schema=$definition[$parts[1]==='content'?'content_fields':'style_fields'][$parts[2]]??[];
                if(isset($parts[4]))$schema=$definition['lists'][$parts[2]]['fields'][$parts[4]]??[];
                if(($schema['type']??'')==='image'&&is_string($value))$spec['fields'][$path]=$replacements[$value]??$value;
                elseif($parts[1]==='content'&&isset($definition['lists'][$parts[2]])&&!isset($parts[4]))$spec['fields'][$path]=$freezeRows($value,$definition['lists'][$parts[2]]);
            }
        }
        foreach($spec['children']??[] as $i=>$child)$spec['children'][$i]=self::freezeSpec($child,$replacements);
        return $spec;
    }

    public static function parentReferenceBytes(int $tenant,int $admin,int $childId): ?array
    {
        $child=self::ownedRun($tenant,$admin,$childId);$context=DecorationAgentService::decode($child['context_json']);$design=$context['design']??[];$reference=$design['parent_reference']??null;
        if(!$reference)return null;
        $session=Db::name('decorate_agent_session')->where(['id'=>$child['session_id'],'tenant_id'=>$tenant,'admin_id'=>$admin])->find();$latest=DecorationAgentService::decode($session['preferences']??'[]')['_design_state']??[];
        if((int)$child['cancel_requested']||!self::designing(DecorationAgentService::decode($child['input_json']),$context)
            ||(int)($latest['run_id']??0)!==$childId||(int)($latest['revision']??0)!==(int)$design['revision']
            ||(int)$design['parent_run_id']!==(int)$reference['run_id'])throw new RuntimeException('设计调整参考链已取消或被替代');
        $parent=self::ownedRun($tenant,$admin,(int)$reference['run_id']);$previous=DecorationAgentService::decode($parent['context_json'])['design']??[];
        $frozen=['run_id'=>(int)$parent['id']]+array_intersect_key($previous,array_flip(['revision','hash','slot_id','blueprint','asset']));
        if((int)$parent['cancel_requested']||(int)$parent['session_id']!==(int)$child['session_id']||($previous['status']??'')!=='superseded'
            ||(int)$previous['revision']+1!==(int)$design['revision']||$frozen!==$reference||self::designHash($previous)!==$reference['hash'])throw new RuntimeException('上一版设计身份、哈希或调整顺序已变化');
        $asset=DecorationAgentAssetReferences::designAsset((int)$reference['slot_id'],$tenant,$admin);
        if(!$asset||!self::sameAsset($asset,$reference['asset']))throw new RuntimeException('上一版设计资源归属已变化');
        $read=DecorationAgentMediaService::readStoredImage($tenant,$asset);
        if(!hash_equals($reference['asset']['sha256'],$read['sha256']))throw new RuntimeException('上一版设计图片内容已变化');
        return $read;
    }

    public static function materialReferences(int $tenant,array $slot): array
    {
        $run=self::ownedRun($tenant,(int)$slot['admin_id'],(int)$slot['run_id']);if((int)$run['cancel_requested'])throw new RuntimeException('设计任务已取消');
        $input=DecorationAgentService::decode($run['input_json']);$request=DecorationAgentService::decode($slot['request_json']);$refs=[];$builtins=array_column(DecorationAgentMediaService::builtinMaterials(),null,'id');
        if(($request['purpose']??'')!=='design')throw new RuntimeException('普通图片位置不能读取设计阶段素材参考');
        $parent=DecorationAgentService::decode($run['context_json'])['design']['parent_reference']??null;
        if(($request['design_parent']??null)!==$parent)throw new RuntimeException('设计参考必须来自当前冻结的调整链');
        if($parent){$read=self::parentReferenceBytes($tenant,(int)$slot['admin_id'],(int)$slot['run_id']);$refs[]='data:'.$read['mime_type'].';base64,'.base64_encode($read['bytes']);}
        foreach($request['design_materials']??[] as $material){
            if(!in_array($material['source']??'',$input['sources'],true))throw new RuntimeException('设计素材来源已失效');
            if(($material['kind']??'')==='builtin'){
                $builtin=$builtins[$material['id']]??null;if(!$builtin||$builtin['uri']!==$material['uri'])throw new RuntimeException('内置素材参考已变化');
                $bytes=file_get_contents(public_path().$builtin['uri']);$mime=getimagesizefromstring($bytes)['mime'];$refs[]='data:'.$mime.';base64,'.base64_encode($bytes);continue;
            }
            $asset=DecorationAgentAssetReferences::materialAsset($material,$tenant);if(!$asset||!self::sameAsset($asset,$material['asset']))throw new RuntimeException('设计素材归属或存储身份已变化');
            $read=DecorationAgentMediaService::readStoredImage($tenant,$asset);$refs[]='data:'.$read['mime_type'].';base64,'.base64_encode($read['bytes']);
        }
        return $refs;
    }

    public static function approvedMaterials(int $tenant,array $approved,array $sources): array
    {
        $result=[];$builtins=array_column(DecorationAgentMediaService::builtinMaterials(),null,'id');
        foreach($approved['blueprint']['selected_materials']??[] as $material){
            if(!in_array($material['source']??'',$sources,true))continue;
            if(($material['kind']??'')==='builtin'){
                $builtin=$builtins[$material['id']]??null;if($builtin&&$builtin['uri']===$material['uri'])$result[]=$builtin;
                continue;
            }
            $asset=DecorationAgentAssetReferences::materialAsset($material,$tenant);
            if(!$asset||!self::sameAsset($asset,$material['asset']))continue;
            $result[]=array_replace($material,$asset,['url'=>self::assetUrl($tenant,$asset),'uri'=>$asset['image_uri'],'type'=>'image','role'=>'selected_design_material','title'=>'已确认设计稿选定的真实素材','dimensions_known'=>isset($asset['width'],$asset['height'])]);
        }
        return $result;
    }

    public static function blockedUris(int $tenant): array
    {
        $ids=DecorationAgentMediaService::evidenceFileIds($tenant);$uris=[];
        if($ids)$uris=Db::name('tenant_file')->where('tenant_id',$tenant)->whereIn('id',$ids)->column('uri');
        $slots=Db::name('decorate_agent_image_slot')->where('tenant_id',$tenant)->whereLike('request_json','%"purpose"%')->field('request_json,result_json')->select()->toArray();
        foreach($slots as $slot)if((DecorationAgentService::decode($slot['request_json'])['purpose']??'')==='design')foreach(DecorationAgentService::decode($slot['result_json'])['images']??[] as $image)if(is_string($image['image_uri']??null))$uris[]=$image['image_uri'];
        return array_values(array_unique($uris));
    }

    /** Design/reference screenshots are not decorative assets, including signed URL aliases. */
    public static function assertNoReferenceImages(array $page,?array $approved,array $blocked=[],int $tenant=0): void
    {
        if(!$approved&&!$blocked&&!$tenant)return;
        $uris=array_filter([(string)($approved['asset']['image_uri']??''),(string)($blocked['screenshot_uri']??'')]);
        foreach((array)($blocked['uris']??[]) as $uri)$uris[]=(string)$uri;
        if($tenant>0)$uris=array_merge($uris,self::blockedUris($tenant));
        $keys=array_map(fn($uri)=>self::imagePath((string)$uri),$uris);
        $walk=function(array $rows)use(&$walk,$keys): void {
            foreach($rows as $widget){
                if(!is_array($widget))continue;
                try{$def=($widget['name']??'')==='page-meta'?DecorationAgentCatalog::pageMeta():DecorationAgentCatalog::definition($widget['name']??'');}catch(\Throwable $error){continue;}
                $values=[];foreach(['content'=>'content_fields','styles'=>'style_fields'] as $section=>$schemas)foreach($def[$schemas]??[] as $key=>$schema)if(($schema['type']??'')==='image')$values[]=$widget[$section][$key]??'';
                foreach($def['lists']??[] as $key=>$list)foreach($widget['content'][$key]??[] as $row)foreach($list['fields']??[] as $field=>$schema)if(($schema['type']??'')==='image')$values[]=$row[$field]??'';
                foreach($values as $value)if(is_string($value)&&$value!==''&&in_array(self::imagePath($value),$keys,true))throw new RuntimeException('整页设计图或验收截图不能作为DIY图片素材');
                if(isset($widget['children']))$walk($widget['children']);
            }
        };
        $walk($page['data']??[]);$walk($page['meta']??[]);
    }

    /** Compare resource paths without expanding the engine's exact URL approval set. */
    private static function imagePath(string $value): string
    {
        $path=parse_url($value,PHP_URL_PATH);
        if(!is_string($path))throw new RuntimeException('图片资源路径无效');
        // Proxies can decode more than once; fail closed for excessive or control-byte aliases.
        for($i=0;$i<8;$i++){$decoded=rawurldecode($path);if($decoded===$path)break;$path=$decoded;}
        if(rawurldecode($path)!==$path||preg_match('/[\x00-\x1F\x7F]/',$path))throw new RuntimeException('图片资源路径编码不明确');
        $segments=[];
        foreach(explode('/',str_replace('\\','/',$path)) as $part){
            if($part===''||$part==='.')continue;
            if($part==='..'){array_pop($segments);continue;}
            $segments[]=$part;
        }
        return implode('/',$segments);
    }

    public static function persist(int $tenant,int $admin,int $runId,array &$context,array $decision,?array $request): void
    {
        if($request===null){
            Db::name('decorate_agent_run')->where('id',$runId)->update(['context_json'=>DecorationAgentService::json($context)]);
            if($decision['kind']==='final')DecorationAgentService::finishDesign($runId,'awaiting_input',['reply'=>$decision['reply'],'question'=>$decision['question'],'summary'=>'设计图尚未生成，页面未开始实施。']);
            return;
        }
        Db::transaction(function()use($tenant,$admin,$runId,&$context,$decision,$request): void {
            $run=self::ownedRun($tenant,$admin,$runId);
            if((int)$run['cancel_requested'])return;
            $slot=Db::name('decorate_agent_image_slot')->where(['tenant_id'=>$tenant,'admin_id'=>$admin,'run_id'=>$runId,'slot_key'=>'design:page'])->find();
            if(!$slot){$slotId=(int)Db::name('decorate_agent_image_slot')->insertGetId(['tenant_id'=>$tenant,'admin_id'=>$admin,'session_id'=>$run['session_id'],'run_id'=>$runId,'candidate_id'=>0,'slot_key'=>'design:page','request_json'=>DecorationAgentService::json($request),'result_json'=>'{}','status'=>'queued','create_time'=>time(),'update_time'=>time()]);}
            else {$slotId=(int)$slot['id'];if(!in_array($slot['status'],['queued','running','submitting'],true))throw new RuntimeException('已有设计结果，不自动重复生成');}
            $context['design']=array_replace($context['design'],['status'=>'generating','slot_id'=>$slotId,'prompt'=>$request['prompt'],'blueprint'=>$request['blueprint']]);
            $context['observations'][]=['tool'=>'request_design','result'=>['slot_id'=>$slotId,'status'=>'queued','purpose'=>'design']];
            Db::name('decorate_agent_run')->where('id',$runId)->update(['context_json'=>DecorationAgentService::json($context)]);
            DecorationAgentService::finishDesign($runId,'waiting_images',['reply'=>$decision['reply'],'summary'=>'正在生成设计稿，确认后再实施页面。']);
        });
    }

    /** A design slot deliberately has no candidate; verify its current approval phase instead. */
    public static function assertSubmission(array $slot,array $run): void
    {
        $tenant=(int)$run['tenant_id'];$admin=(int)$run['admin_id'];
        $session=Db::name('decorate_agent_session')->where(['id'=>$run['session_id'],'tenant_id'=>$tenant,'admin_id'=>$admin])->lock(true)->find();
        $current=Db::name('decorate_agent_run')->where(['id'=>$run['id'],'tenant_id'=>$tenant,'admin_id'=>$admin])->lock(true)->find();
        $currentSlot=Db::name('decorate_agent_image_slot')->where(['id'=>$slot['id'],'tenant_id'=>$tenant,'admin_id'=>$admin])->lock(true)->find();
        if(!$session||!$current||!$currentSlot)throw new RuntimeException('设计提交归属已失效');
        $context=DecorationAgentService::decode($current['context_json']);$design=$context['design']??[];
        $input=DecorationAgentService::decode($current['input_json']);$latest=DecorationAgentService::decode($session['preferences'])['_design_state']??[];
        if(!self::designing($input,$context)||!in_array($input['scope']??'',['page','new_page'],true)||!in_array('generate',$input['sources']??[],true)
            ||$current['status']!=='waiting_images'||(int)$current['cancel_requested']||($context['phase']??'')!=='design'
            ||(int)($latest['run_id']??0)!==(int)$current['id']||(int)($latest['revision']??0)!==(int)($design['revision']??0)
            ||($design['status']??'')!=='generating'||(int)($design['slot_id']??0)!==(int)$currentSlot['id']
            ||(int)$current['candidate_id']!==0||(int)$currentSlot['candidate_id']!==0
            ||(int)$currentSlot['run_id']!==(int)$current['id']||(int)$currentSlot['session_id']!==(int)$session['id']
            ||$currentSlot['slot_key']!=='design:page'||(DecorationAgentService::decode($currentSlot['request_json'])['purpose']??'')!=='design'){
            throw new RuntimeException('设计已取消、被新版本替代或提交位置不匹配');
        }
    }

    public static function complete(array $slot,array $result): void
    {
        $tenant=(int)$slot['tenant_id'];$admin=(int)$slot['admin_id'];$id=(int)$slot['run_id'];
        // Persist the settled result first; storage reads run without a database lock.
        Db::name('decorate_agent_image_slot')->where(['id'=>$slot['id'],'tenant_id'=>$tenant,'admin_id'=>$admin])->update(['status'=>'success','result_json'=>DecorationAgentService::json($result)]);
        $asset=DecorationAgentAssetReferences::designAsset((int)$slot['id'],$tenant,$admin);
        if(!$asset)throw new RuntimeException('设计资源尚未形成可核实的存储结果');
        $read=DecorationAgentMediaService::readStoredImage($tenant,$asset);
        $identity=array_intersect_key($asset,array_flip(['image_uri','storage_scope','storage_engine','storage_domain']));
        $identity+=['width'=>$read['width'],'height'=>$read['height'],'sha256'=>$read['sha256']];
        Db::transaction(function()use($tenant,$admin,$id,$slot,$identity): void {
            $run=self::ownedRun($tenant,$admin,$id);$ctx=DecorationAgentService::decode($run['context_json']);
            if((int)$run['cancel_requested']||($ctx['design']['status']??'')==='superseded')return;
            $ctx['design']['asset']=$identity;$ctx['design']['status']='awaiting_confirmation';
            $ctx['design']['hash']=self::designHash($ctx['design']);
            Db::name('decorate_agent_run')->where('id',$id)->update(['context_json'=>DecorationAgentService::json($ctx)]);
        });
    }

    private static function canonical(mixed $value): mixed
    {
        if(!is_array($value))return $value;
        if(!array_is_list($value))ksort($value);
        return array_map([self::class,'canonical'],$value);
    }
    private static function designHash(array $design): string
    {return hash('sha256',DecorationAgentService::json(self::canonical(array_intersect_key($design,array_flip(['revision','prompt','blueprint','asset'])))));}

    public static function draft(array $run): ?array
    {
        $ctx=DecorationAgentService::decode($run['context_json']);$d=$ctx['design']??null;if(!$d)return null;
        $status=(string)$d['status'];if((int)$run['cancel_requested'])$status='canceled';elseif(in_array($run['status'],['failed','needs_review','limited','budget_exceeded'],true))$status=$run['status']==='needs_review'?'needs_review':'failed';
        $url='';if(!empty($d['asset'])){$asset=DecorationAgentAssetReferences::designAsset((int)$d['slot_id'],(int)$run['tenant_id'],(int)$run['admin_id']);if($asset&&self::sameAsset($asset,$d['asset']))$url=self::assetUrl((int)$run['tenant_id'],$asset);}
        return ['run_id'=>(int)$run['id'],'revision'=>(int)$d['revision'],'hash'=>(string)$d['hash'],'status'=>$status,'url'=>$url,'slot_id'=>(int)$d['slot_id'],'prompt'=>(string)$d['prompt'],'blueprint'=>$d['blueprint'],'width'=>(int)($d['asset']['width']??0),'height'=>(int)($d['asset']['height']??0)]+array_intersect_key($d,array_flip(['implementation_run_id']));
    }

    private static function sameAsset(array $a,array $b): bool
    {foreach(['image_uri','storage_scope','storage_engine','storage_domain'] as $field)if(($a[$field]??'')!==($b[$field]??''))return false;return true;}

    public static function assetUrl(int $tenant,array $asset): string
    {
        $request=request();$previous=$request->tenantId??null;$request->tenantId=$tenant;
        try{return ($asset['storage_engine']??'')==='local'?'/'.ltrim($asset['image_uri'],'/'):FileService::getFileUrlByStorage($asset['image_uri'],$asset['storage_scope'],$asset['storage_engine'],(string)($asset['storage_domain']??''));}finally{$request->tenantId=$previous;}
    }

    public static function confirmedDesign(int $tenant,int $admin,int $id,string $hash): array
    {
        $hint=self::ownedRun($tenant,$admin,$id);
        $session=Db::name('decorate_agent_session')->where(['id'=>$hint['session_id'],'tenant_id'=>$tenant,'admin_id'=>$admin])->lock(true)->find();
        $run=Db::name('decorate_agent_run')->where(['id'=>$id,'tenant_id'=>$tenant,'admin_id'=>$admin])->lock(true)->find();
        if(!$run)throw new RuntimeException('设计执行不存在');$ctx=DecorationAgentService::decode($run['context_json']);$design=$ctx['design']??[];$latest=DecorationAgentService::decode($session['preferences']??'[]')['_design_state']['run_id']??0;
        if((int)$run['cancel_requested']||$latest!==$id||($design['status']??'')!=='approved'||!hash_equals((string)($design['hash']??''),$hash)||self::designHash($design)!==$hash)throw new RuntimeException('设计稿未确认、已取消或版本已失效');
        $asset=DecorationAgentAssetReferences::designAsset((int)$design['slot_id'],$tenant,$admin);
        if(!$asset||!self::sameAsset($asset,$design['asset']))throw new RuntimeException('设计资源归属或存储身份已变化');
        return ['run_id'=>$id]+array_intersect_key($design,array_flip(['revision','hash','slot_id','prompt','blueprint','asset']));
    }

    public static function referenceBytes(int $tenant,int $admin,array $approved): array
    {
        $current=self::confirmedDesign($tenant,$admin,(int)$approved['run_id'],(string)$approved['hash']);
        if($current!==$approved)throw new RuntimeException('冻结设计身份不匹配');
        $read=DecorationAgentMediaService::readStoredImage($tenant,$current['asset']);
        if(!hash_equals($current['asset']['sha256'],$read['sha256']))throw new RuntimeException('设计图内容已变化，请重新生成并确认');
        return $read;
    }

    public static function approvedForRun(int $tenant,int $admin,int $runId,int $slotId): array
    {
        $run=self::ownedRun($tenant,$admin,$runId);if((int)$run['cancel_requested'])throw new RuntimeException('实施任务已取消');
        $approved=DecorationAgentService::decode($run['context_json'])['approved_design']??[];
        if(!$approved||(int)$approved['slot_id']!==$slotId)throw new RuntimeException('参考图必须来自本轮冻结的已确认设计稿');
        return self::confirmedDesign($tenant,$admin,(int)$approved['run_id'],(string)$approved['hash']);
    }

    public static function assertCanApply(int $tenant,int $admin,int $sessionId): void
    {
        $session=Db::name('decorate_agent_session')->where(['id'=>$sessionId,'tenant_id'=>$tenant,'admin_id'=>$admin])->lock(true)->find();$latest=(int)(DecorationAgentService::decode($session['preferences']??'[]')['_design_state']['run_id']??0);
        if(!$latest)return;$run=Db::name('decorate_agent_run')->where(['id'=>$latest,'tenant_id'=>$tenant,'admin_id'=>$admin])->lock(true)->find();
        if(!$run)throw new RuntimeException('最新设计执行不存在');$status=self::draft($run)['status'];
        if(in_array($status,['planning','generating','awaiting_confirmation'],true))throw new RuntimeException('请先确认设计稿，再预览并应用实施候选');
    }

    public static function confirm(int $tenant,int $admin,array $parameters): array
    {
        $id=(int)($parameters['run_id']??0);$revision=(int)($parameters['revision']??0);$hash=(string)($parameters['design_hash']??'');$key=trim((string)($parameters['idempotency_key']??''));$budget=(float)($parameters['budget_points']??0);
        if($key===''||strlen($key)>100||!is_finite($budget)||$budget<=0||strlen($hash)!==64)throw new RuntimeException('请提供有效设计版本、确认标识和实施预算');
        $hint=self::ownedRun($tenant,$admin,$id);
        return Db::transaction(function()use($tenant,$admin,$id,$revision,$hash,$key,$budget,$hint): array {
            $session=Db::name('decorate_agent_session')->where(['id'=>$hint['session_id'],'tenant_id'=>$tenant,'admin_id'=>$admin])->lock(true)->find();
            $run=Db::name('decorate_agent_run')->where('id',$id)->lock(true)->find();$ctx=DecorationAgentService::decode($run['context_json']);$d=$ctx['design']??[];
            $latest=(int)(DecorationAgentService::decode($session['preferences'])['_design_state']['run_id']??0);
            if($latest!==$id||(int)$run['cancel_requested']||$revision!==(int)($d['revision']??0)||!hash_equals((string)($d['hash']??''),$hash)||self::designHash($d)!==$hash)throw new RuntimeException('设计稿已更新、取消或确认版本不匹配');
            if(($d['status']??'')==='approved'&&!empty($d['implementation_run_id']))return DecorationAgentService::run($tenant,$admin,(int)$d['implementation_run_id']);
            if($run['status']!=='awaiting_design_confirmation'||($d['status']??'')!=='awaiting_confirmation'||!DecorationAgentAssetReferences::designAsset((int)$d['slot_id'],$tenant,$admin))throw new RuntimeException('设计稿尚未成功或费用待核实，不能确认');
            $approved=['run_id'=>$id]+array_intersect_key($d,array_flip(['revision','hash','slot_id','prompt','blueprint','asset']));
            $d['status']='approved';$ctx['design']=$d;Db::name('decorate_agent_run')->where('id',$id)->update(['context_json'=>DecorationAgentService::json($ctx)]);
            $input=DecorationAgentService::decode($run['input_json']);
            // Confirmation is a new authorization. Preserve the design-only brief in its original history.
            $input['message']='我已确认第'.$revision.'版设计稿。现在授权按已确认的完整设计图及冻结规划开始页面实施：使用真实可编辑组件还原布局、文字、配色、图标和已选素材；仅在当前已授权来源和本轮预算内生成所需独立素材。请生成候选页面供预览，完成后通过实际截图与设计稿逐项复审。';
            $input['design_first']=false;unset($input['design_parent_run_id']);$input['session_id']=$session['id'];$input['budget_points']=$budget;$input['idempotency_key']='design-implementation:'.hash('sha256',$tenant.':'.$admin.':'.$id.':'.$hash.':'.$key);
            $implementation=DecorationAgentService::start($tenant,$admin,$input,$approved);
            $ctx['design']['implementation_run_id']=$implementation['id'];$ctx['design']['confirmation_key']=$key;Db::name('decorate_agent_run')->where('id',$id)->update(['context_json'=>DecorationAgentService::json($ctx)]);
            DecorationAgentService::finishDesign($id,'completed',['reply'=>'设计稿已确认，开始按真实组件实施；完成后需图对图验收。']);
            return $implementation;
        });
    }
}
