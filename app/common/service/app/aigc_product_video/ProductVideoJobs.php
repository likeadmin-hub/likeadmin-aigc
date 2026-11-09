<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_product_video;
use app\common\service\app\aigc_micro_video\{MicroVideoMedia,MicroVideoCatalog,MicroVideoFrameCatalog};
use app\common\service\power\{MarketTextModelRuntimeService as Text,MarketImageModelRuntimeService as Image,MarketUsageSettlementService as Usage,TextModelExecutionPolicy};
use app\common\service\point\PointService;
use InvalidArgumentException;
use think\facade\Db;

final class ProductVideoJobs
{
    public static function table(){return ProductVideoService::table('job');}
    public static function owned(int $tenant,int $user,int $id,bool $admin=false): array
    {$q=self::table()->where(['id'=>$id,'delete_time'=>0]);if(!$admin||$tenant>0)$q->where('tenant_id',$tenant);if(!$admin)$q->where('user_id',$user);$row=$q->find();if(!$row)throw new InvalidArgumentException('创作任务不存在或无权访问');return $row;}
    public static function dto(array $row): array
    {
        $result=ProductVideoService::publicBody(ProductVideoPolicy::json($row['result_json']),(int)$row['tenant_id']);
        if($row['action']==='video'){
            $link=ProductVideoService::table('job_stage')->where('root_job_id',$row['id'])->whereLike('stage','chapters%')->order('id','desc')->find();
            if($link){$child=self::table()->where('id',$link['child_job_id'])->find();if($child)$result['chapters']=['status'=>$child['status'],'data'=>ProductVideoPolicy::json($child['result_json']),'user_charge_points'=>(float)$child['user_charge_points'],'billing_status'=>$child['billing_status']];}
        }
        $request=ProductVideoPolicy::json($row['request_json']);$snapshot=(array)($request['snapshot']??[]);
        $displayPrompt=(string)(($snapshot['mode']??'normal')==='advanced'?($snapshot['plan']['data']['video_prompt']??$snapshot['prompt']??''):($snapshot['prompt']??''));
        if(($snapshot['mode']??'normal')==='normal'&&($snapshot['normal_source']??'prompt')==='template')foreach(ProductVideoPrompts::templates() as $template)if($template['code']===($snapshot['template_code']??''))$displayPrompt='商品展示模板：'.$template['name'];
        $presentation=['mode'=>$snapshot['mode']??'normal','selection'=>array_intersect_key((array)($snapshot['video_selection']??[]),array_flip(['model_id','quality','ratio','duration'])),
            'prompt'=>$displayPrompt,
            'references'=>ProductVideoService::publicBody(array_slice((array)($snapshot['images']??[]),0,3),(int)$row['tenant_id'])];
        return ['id'=>(int)$row['id'],'presentation'=>$presentation,'project_id'=>(int)$row['project_id'],'project_revision'=>(int)$row['project_revision'],'action'=>$row['action'],
            'status'=>$row['status'],'result'=>$result,
            'error'=>$row['error']===''?'':($row['status']==='unknown'?'模型受理或费用待核对，请保留此任务，勿重复提交':'创作未完成，请检查素材、模型或余额后重试'),
            'user_charge_points'=>(float)$row['user_charge_points'],'billing_status'=>$row['billing_status'],
            'create_time'=>(int)$row['create_time'],'update_time'=>(int)$row['update_time']];
    }
    public static function lists(int $tenant,int $user,array $p,bool $admin=false): array
    {
        $q=self::table()->where('delete_time',0)->whereNotIn('id',function($q){$q->name(ProductVideoPolicy::APP.'_job_stage')->field('child_job_id');});if(!$admin||$tenant>0)$q->where('tenant_id',$tenant);if(!$admin)$q->where('user_id',$user);
        $group=(string)($p['group']??'all');if(!in_array($group,['all','video','helper'],true))throw new InvalidArgumentException('任务分类无效');if($group==='video')$q->where('action','video');elseif($group==='helper')$q->where('action','<>','video');
        if(!empty($p['project_id']))$q->where('project_id',(int)$p['project_id']);
        $page=max(1,(int)($p['page_no']??1));$size=max(1,min(50,(int)($p['page_size']??20)));$count=(clone $q)->count();
        return ['lists'=>array_map([self::class,'dto'],$q->order('id','desc')->page($page,$size)->select()->toArray()),'count'=>$count,'page_no'=>$page,'page_size'=>$size];
    }
    public static function prepare(int $tenant,int $user,array $p): array
    {
        if(($p['action']??'')==='analysis')return ProductVideoPipeline::prepare($tenant,$user,$p);
        ProductVideoService::available($tenant,$user);$project=ProductVideoService::owned($tenant,$user,(int)($p['project_id']??0));
        if((int)($p['revision']??0)!==(int)$project['revision'])throw new InvalidArgumentException('草稿已更新，请保存后重新报价');
        $action=(string)($p['action']??'');if(!in_array($action,ProductVideoPolicy::ACTIONS,true))throw new InvalidArgumentException('创作操作无效');
        $body=ProductVideoPolicy::body(ProductVideoPolicy::json($project['body_json']));$request=['action'=>$action,'snapshot'=>$body,'project_revision'=>(int)$project['revision']];
        $images=MicroVideoMedia::validateImages($tenant,$user,$body['images'],15);
        if(in_array($action,['product','plan','storyboard','video'],true)&&!$images)throw new InvalidArgumentException('请先添加商品图片');
        $request['images']=$images;$settings=ProductVideoService::settings($tenant);
        if($action==='video'){
            $prompt=ProductVideoPolicy::prompt($body);if($body['mode']==='normal'&&($body['normal_source']??'prompt')==='prompt'&&$body['prompt']==='')throw new InvalidArgumentException('请填写视频描述');
            $wanted=(array)$body['video_selection'];$model=ProductVideoCatalog::model($tenant,$body);
            $spec=ProductVideoCatalog::spec($model,$body);
            if(($body['reference_mode']??'reference')!=='start_end'&&count($images)>(int)$model['max_reference_images'])throw new InvalidArgumentException('当前视频模型不支持这么多商品图片，请减少图片或更换模型');
            $assets=[];foreach($images as $index=>$image)$assets[]=['type'=>'image','uri'=>$image['uri'],'role'=>$spec['generation_method']==='start_end'?($index===0?'first_frame_image':'last_frame_image'):($spec['generation_method']==='image_to_video'?'first_frame_image':'reference_image')]+$image;
            foreach($body['generation_assets'] as $asset)$assets[]=$asset;
            ProductVideoMaterials::assertMentions($body,$assets);
            if(($body['audio']??null)!==null){if(empty($model['supports_generate_audio']))throw new InvalidArgumentException('所选模型不支持声音开关，请使用自动声音设置');$spec['generate_audio']=(bool)$body['audio'];}
            $storyboard=$body['storyboard'];$boardNotice='';
            if($body['mode']==='advanced'&&is_array($storyboard)&&($storyboard['input_key']??'')===ProductVideoPolicy::hash((array)$body['plan'])&&$spec['generation_method']!=='image_to_video'){
                $extra=MicroVideoMedia::validateImages($tenant,$user,(array)($storyboard['images']??[]),1);
                $capacity=(int)$model['max_reference_images'];foreach($model['material_modes']??[] as $native)if(($native['key']??'')===($spec['material_mode']??'')){$capacity=0;foreach($native['fields'] as $field)if($field['kind']==='image')$capacity+=(int)($field['maximum']??0);}
                if(count($images)+count($extra)<=$capacity){
                    $boardNotice='本次会将故事板作为额外图片参考提交。';
                    foreach($extra as $image)$assets[]=['type'=>'image','uri'=>$image['uri'],'role'=>'reference_image']+$image;
                    $prompt.='附加故事板仅供镜头参考，成片必须是单画面连续视频，不要出现网格、编号或多画面拼贴。';
                }else $boardNotice='本次图片数量已达模型上限，故事板仅供预览，不提交视频模型。';
            }elseif($body['mode']==='advanced'&&$storyboard){$boardNotice='本次故事板已过期或模型不支持额外参考，仅供预览，不提交视频模型。';}
            $selection=$spec+['model_id'=>$model['id'],'channel'=>$model['id'],'resolution'=>$spec['quality']];
            $request+=['selection'=>$selection,'prompt'=>$prompt,'reference_images'=>array_column($assets,'uri'),'reference_assets'=>$assets,'selected_mentions'=>$body['selected_mentions']];
            $quote=MicroVideoCatalog::runtime($spec)::quote($tenant,$selection+['prompt'=>$prompt,'reference_images'=>$request['reference_images'],'reference_assets'=>$assets]);
            $request['selection']['market_sku_id']=(int)$quote['market_sku_id'];
            $request['runtime']=MicroVideoCatalog::runtime($spec);$quote['approximate']=false;if($boardNotice!=='')$quote['notice']=$boardNotice;
            if(!empty($body['analyze_chapters'])){
                $id=$body['analysis_model_id']?:$settings['analysis_model_id'];$options=Text::mediaOptions($tenant,true);if($id==='')$id=(string)($options[0]['id']??'');
                if(!$id||!in_array($id,array_column($options,'id'),true))throw new InvalidArgumentException('请先选择成片分析模型，或关闭生成后分析章节');
                $analysis=Text::resolveModel($tenant,$id,true);$request['chapter_model']=['id'=>$id,'fingerprint'=>TextModelExecutionPolicy::fingerprint($analysis)];
                $quote['phased_costs']=[['action'=>'video','model_name'=>$model['label'],'approximate'=>false,'user_charge_points'=>$quote['user_charge_points']],['action'=>'chapters','model_name'=>$analysis['name'],'approximate'=>true,'user_charge_points'=>null]];
                $quote['notice']=($quote['notice']??'').'视频按当前规格生成一次；完成后分析真实成片章节，分析按实际模型用量另行结算';
            }
        }elseif($action==='storyboard'){
            ProductVideoPolicy::prompt(array_replace($body,['mode'=>'advanced']));
            $models=MicroVideoFrameCatalog::compatible(MicroVideoFrameCatalog::models($tenant,1),[count($images)]);
            $wanted=(array)$body['image_selection'];$id=(string)($wanted['model_id']??$settings['default_image_model_id']);$model=null;
            foreach($models as $candidate)if($candidate['id']===$id||($id===''&&$model===null))$model=$candidate;
            if(!$model)throw new InvalidArgumentException('暂无支持当前商品图片数量的分镜图片模型');
            $spec=null;foreach($model['specs'] as $candidate)if(($wanted['quality']??$candidate['quality'])===$candidate['quality']&&($wanted['ratio']??'16:9')===$candidate['ratio']){$spec=$candidate;break;}
            if(!$spec)throw new InvalidArgumentException('分镜图片模型不支持当前画质或比例');
            $selection=['model_id'=>$model['id'],'market_sku_id'=>$spec['market_sku_id'],'quality'=>$spec['quality'],'ratio'=>$spec['ratio']];
            $prompt='为商品短视频绘制一张按镜头顺序排列的故事板。以参考商品为准保持外观一致，每格清晰表现一个镜头，避免额外文字和水印。分镜内容：'.ProductVideoPolicy::encode($body['plan']['data']);
            $request+=['selection'=>$selection,'prompt'=>$prompt,'plan_key'=>ProductVideoPolicy::hash((array)$body['plan'])];
            $quote=Image::quote($tenant,$selection+self::imageRequest($request),1);$quote['approximate']=false;
        }else{
            $video=null;
            if($action==='reference'){
                if(!$body['reference_video'])throw new InvalidArgumentException('请先添加参考视频');
                $video=MicroVideoMedia::validateVideo($tenant,$user,(string)$body['reference_video']['uri']);
            }
            if($action==='chapters'){
                $prior=self::owned($tenant,$user,(int)($p['video_job_id']??0));$r=ProductVideoPolicy::json($prior['result_json']);
                if($prior['action']!=='video'||$prior['status']!=='success'||(int)$prior['project_id']!==(int)$project['id'])throw new InvalidArgumentException('请先选择本草稿已完成的视频');
                $video=MicroVideoMedia::validateVideo($tenant,$user,(string)($r['videos'][0]['video_uri']??$r['videos'][0]['uri']??''));
                $request['video_job_id']=(int)$prior['id'];
            }
            if(in_array($action,['reference','chapters'],true))$request['images']=[];
            if($action==='plan'){
                $v=$body['video_selection'];$model=ProductVideoCatalog::model($tenant,$body);
                ProductVideoCatalog::spec($model,$body);
                if(count($images)>(int)$model['max_reference_images'])throw new InvalidArgumentException('当前视频模型不支持这么多商品图片，请减少图片或更换模型');
                $request['duration']=(int)$v['duration'];
            }
            $id=(string)($video?($body['analysis_model_id']?:$settings['analysis_model_id']):($body['planning_model_id']?:$settings['planning_model_id']));$options=Text::mediaOptions($tenant,(bool)$video);
            if($id==='')$id=(string)($options[0]['id']??'');
            if(!$id||!in_array($id,array_column($options,'id'),true))throw new InvalidArgumentException($video?'暂无上架的视频理解模型':'暂无上架的商品分析模型');
            $model=Text::resolveModel($tenant,$id,true);$request['selection']=['model_id'=>$id];$request['model_fingerprint']=TextModelExecutionPolicy::fingerprint($model);
            $template=null;foreach(ProductVideoPrompts::templates() as $t)if($t['code']===$body['template_code'])$template=$t;
            $reference=$body['reference_analysis'];if(($reference['video_uri']??'')!==($body['reference_video']['uri']??''))$reference=null;
            $request['content']=ProductVideoPolicy::encode(['product_info'=>$body['product_info'],'requirements'=>$body['requirements'],'language'=>$body['language'],
                'template'=>$template,'reference_observations'=>$reference['data']??null,'duration'=>$request['duration']??null,'ratio'=>$body['video_selection']['ratio']??'',
                'prompt'=>($body['mode']==='advanced'?($body['plan']['data']['video_prompt']??$body['prompt']):$body['prompt']),'material_roles'=>array_map(static fn($a)=>array_intersect_key($a,array_flip(['type','mention_name','reference_slot_id','duration'])),$body['generation_assets']),'market_region'=>$body['market_region']??'','audio'=>$body['audio']??null,'image_slots'=>array_column($request['images'],'reference_slot_id'),'actual_duration'=>$video['duration']??null]);
            $request['video']=$video;$request['system']=ProductVideoPrompts::system($action);
            // Media token counts are supplier measured. A fictitious scalar quote would mislead users.
            $quote=['approximate'=>true,'user_charge_points'=>null,'tenant_cost_points'=>0,'model_name'=>$model['name'],
                'input'=>$model['input'],'output'=>$model['output'],'notice'=>'按模型实际输入和输出用量结算'];
        }
        if($action==='optimize'&&trim((string)($body['mode']==='advanced'?($body['plan']['data']['video_prompt']??$body['prompt']):$body['prompt']))==='')throw new InvalidArgumentException('请先填写需要优化的视频描述');
        $request['quote']=$quote;return ['project'=>$project,'request'=>$request,'quote'=>$quote,'hash'=>ProductVideoPolicy::requestKey($request)];
    }
    public static function imageRequest(array $r): array
    {return ['prompt'=>$r['prompt'],'quantity'=>1,'reference_images'=>MicroVideoMedia::visionInputs($r['images'])];}
    public static function estimate(int $tenant,int $user,array $p): array
    {
        $i=self::prepare($tenant,$user,$p);$token=bin2hex(random_bytes(24));$expires=time()+600;
        ProductVideoService::table('quote')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$i['project']['id'],'token'=>$token,'input_hash'=>$i['hash'],'request_json'=>ProductVideoPolicy::encode($i['request']),'expires_at'=>$expires,'create_time'=>time()]);
        $q=$i['quote'];return ['quote_token'=>$token,'expires_at'=>$expires,'action'=>$i['request']['action'],'approximate'=>$q['approximate'],'user_charge_points'=>$q['user_charge_points'],
            'notice'=>$q['notice']??'按当前规格生成一次','model_name'=>$q['model_name']??'','phased_costs'=>$q['phased_costs']??[]];
    }
    public static function create(int $tenant,int $user,array $p): array
    {
        $key=ProductVideoPolicy::key((string)($p['idempotency_key']??''));
        $old=self::table()->where(['tenant_id'=>$tenant,'user_id'=>$user,'idempotency_key'=>$key])->find();
        if($old){if((int)$old['project_id']!==(int)($p['project_id']??0)||(int)$old['project_revision']!==(int)($p['revision']??0)||$old['action']!==($p['action']??''))throw new InvalidArgumentException('提交标识已用于其他内容');return self::dto($old);}
        $i=self::prepare($tenant,$user,$p);$token=(string)($p['quote_token']??'');
        $quote=ProductVideoService::table('quote')->where(['tenant_id'=>$tenant,'user_id'=>$user,'token'=>$token,'input_hash'=>$i['hash']])->find();
        if(!$quote||(int)$quote['expires_at']<time())throw new InvalidArgumentException('资料、规格或报价已变化，请重新确认');
        $q=$i['quote'];PointService::assertCanConsumeAmounts($tenant,$user,(float)($q['tenant_cost_points']?:0.01),(float)($q['precheck_user_points']??$q['user_charge_points']??0.01));
        return Db::transaction(static function()use($tenant,$user,$p,$key,$i,$quote){
            $project=ProductVideoService::owned($tenant,$user,(int)$p['project_id'],true);if((int)$project['revision']!==(int)$p['revision'])throw new InvalidArgumentException('草稿已更新，请重新确认');
            $prior=self::table()->where(['tenant_id'=>$tenant,'user_id'=>$user,'idempotency_key'=>$key])->find();if($prior)return self::dto($prior);
            $row=ProductVideoService::table('quote')->where('id',$quote['id'])->lock(true)->find();if(!$row||$row['consumed_job_id']||(int)$row['expires_at']<time())throw new InvalidArgumentException('该报价已使用或过期，请查看已有任务');
            if($i['request']['action']==='chapters'){
                $video=self::table()->where(['id'=>$i['request']['video_job_id'],'tenant_id'=>$tenant,'user_id'=>$user,'delete_time'=>0])->lock(true)->find();
                if(!$video||$video['status']!=='success')throw new InvalidArgumentException('成片记录不可用，请刷新后重试');
            }
            $id=self::table()->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$project['id'],'project_revision'=>$project['revision'],'action'=>$i['request']['action'],
                'idempotency_key'=>$key,'input_hash'=>$i['hash'],'status'=>'queued','request_json'=>ProductVideoPolicy::encode($i['request']),'receipt_json'=>'{}','result_json'=>'{}','next_run_at'=>time(),'create_time'=>time(),'update_time'=>time()]);
            if($i['request']['action']==='chapters')ProductVideoService::table('job_stage')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'root_job_id'=>$i['request']['video_job_id'],'stage'=>'chapters_'.$id,'child_job_id'=>$id,'create_time'=>time()]);
            ProductVideoService::table('quote')->where('id',$quote['id'])->update(['consumed_job_id'=>$id]);return self::dto(self::owned($tenant,$user,$id));
        });
    }
    public static function recover(int $tenant,int $user,int $id): array
    {$row=self::owned($tenant,$user,$id);ProductVideoWorker::reconcile($row);return self::dto(self::owned($tenant,$user,$id));}
    public static function delete(int $tenant,int $user,int $id): void
    {
        Db::transaction(static function()use($tenant,$user,$id){
            self::owned($tenant,$user,$id);$row=self::table()->where('id',$id)->lock(true)->find();
            if($row['action']==='video'&&self::table()->whereIn('id',function($q)use($id){$q->name(ProductVideoPolicy::APP.'_job_stage')->where('root_job_id',$id)->field('child_job_id');})->whereIn('status',['queued','running','unknown'])->count())throw new InvalidArgumentException('成片章节仍在处理，请保留记录');
            if(!in_array($row['status'],['success','partial','failed','canceled'],true))throw new InvalidArgumentException('任务尚未结束，请保留进度');
            self::table()->where('id',$id)->update(['delete_time'=>time(),'update_time'=>time()]);
        });
    }
}
