<?php
namespace app\common\service\app\aigc_micro_video;

use app\common\service\app\AppAccessService;
use app\common\service\point\PointService;
use app\common\service\FileService;
use think\facade\Db;
use InvalidArgumentException;
use RuntimeException;

final class MicroVideoService
{
    public static function friendly(\Throwable $e): string
    {
        \think\facade\Log::warning('Micro video: '.get_class($e).' '.$e->getMessage());
        return $e instanceof InvalidArgumentException||($e instanceof RuntimeException&&!str_contains($e->getMessage(),'SQLSTATE'))?$e->getMessage():'暂时无法完成操作，请稍后重试';
    }
    public static function available(int $tenant): void
    {
        if(AppAccessService::assertTenantCanUse($tenant,MicroVideoStore::APP,0,false)!==null)throw new InvalidArgumentException('微视频应用未开通或未上架');
        if((int)MicroVideoSettings::get($tenant)['status']!==1)throw new InvalidArgumentException('微视频应用已停用');
    }
    public static function form(int $tenant,int $user,array $p,bool $imagesRequired=true): array
    {
        self::available($tenant);$config=MicroVideoSettings::get($tenant);$id=(string)($p['model_id']??$config['default_model_id']);MicroVideoPolicy::allowedModel($id,$config['video_model_ids']);$model=MicroVideoCatalog::model($tenant,$id);
        $quality=(string)($p['quality']??$config['default_quality']);$ratio=(string)($p['ratio']??$config['default_ratio']);MicroVideoCatalog::specs($model,$quality,$ratio);
        $target=(int)($p['target_duration']??30);if(!in_array($target,$config['target_duration_options'],true))throw new InvalidArgumentException('请选择开放的目标时长');
        $mode=(string)($p['mode']??'theme');if(!in_array($mode,['theme','prompt'],true))throw new InvalidArgumentException('创作模式无效');
        $theme=trim((string)($p['theme']??''));$prompt=trim((string)($p['prompt']??''));
        if($imagesRequired&&($mode==='theme'?$theme==='':$prompt===''))throw new InvalidArgumentException($mode==='theme'?'请输入一句话主题':'请输入完整提示词');
        if(mb_strlen($theme)>1000||mb_strlen($prompt)>20000)throw new InvalidArgumentException('主题或提示词超出长度限制');
        if($imagesRequired&&empty($p['images']))throw new InvalidArgumentException('请至少上传一张参考图片');
        $images=$imagesRequired?MicroVideoMedia::validateImages($tenant,$user,(array)($p['images']??[]),(int)$config['max_images']):[];
        $direction=MicroVideoSettings::direction($tenant,(int)($p['direction_id']??0));
        return ['params'=>['model_id'=>$id,'quality'=>$quality,'ratio'=>$ratio,'target_duration'=>$target,'mode'=>$mode,'theme'=>$theme,'prompt'=>$prompt,'images'=>$images,'direction_id'=>(int)$direction['id']],'config'=>$config,'model'=>$model,'direction'=>$direction];
    }
    private static function key(array $p): string
    {
        $key=trim((string)($p['idempotency_key']??''));if(!preg_match('/^[a-zA-Z0-9_.:-]{8,90}$/D',$key))throw new InvalidArgumentException('请求标识无效，请刷新后重试');return $key;
    }
    private static function createDraft(int $tenant,int $user,array $prepared,string $key,string $status): array
    {
        return Db::transaction(function()use($tenant,$user,$prepared,$key,$status){
            $row=MicroVideoStore::table('task')->where(['tenant_id'=>$tenant,'user_id'=>$user,'idempotency_key'=>$key])->find();
            if($row){if(MicroVideoPolicy::fingerprint(MicroVideoPolicy::stableParams(MicroVideoStore::json($row['params_json'])))!==MicroVideoPolicy::fingerprint(MicroVideoPolicy::stableParams($prepared['params'])))throw new InvalidArgumentException('请求已用于其他创作内容，请使用新请求标识');return $row;}
            $now=time();$p=$prepared['params'];$data=['tenant_id'=>$tenant,'user_id'=>$user,'idempotency_key'=>$key,'title'=>mb_substr($p['theme']?:$p['prompt'],0,80),'status'=>$status,'params_json'=>MicroVideoStore::encode($p),'config_snapshot'=>MicroVideoStore::encode($prepared['config']+['shot_timeline_version'=>1]),'model_snapshot'=>MicroVideoStore::encode($prepared['model']),'direction_snapshot'=>MicroVideoStore::encode($prepared['direction']),'prompt'=>$p['prompt'],'analysis'=>'','continuity'=>'','planning_result'=>'{}','quote_json'=>'{}','confirmed_quote'=>'{}','next_run_at'=>$now,'create_time'=>$now,'update_time'=>$now];
            $id=MicroVideoStore::table('task')->insertGetId($data);return MicroVideoStore::owned($tenant,$user,$id);
        });
    }
    public static function writePrompt(int $tenant,int $user,array $p): array
    {
        $prepared=self::form($tenant,$user,$p);$row=self::createDraft($tenant,$user,$prepared,'prompt:'.self::key($p),'prompt_draft');
        $lock=fopen(runtime_path().'micro_prompt_'.$row['id'].'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new InvalidArgumentException('提示词正在编写，请稍后查看');
        try{
            $images=MicroVideoMedia::validateImages($tenant,$user,$prepared['params']['images'],$prepared['config']['max_images']);
            if($row['analysis']===''){$analysis=MicroVideoText::run($row,'analysis',$prepared['config']['vision_model_id'],$prepared['config']['analysis_template'],'请按图片顺序分析下列参考图片。',MicroVideoMedia::visionInputs($images));$row['analysis']=(string)$analysis['content'];MicroVideoStore::update($row['id'],['analysis'=>$row['analysis']]);}
            $content=MicroVideoStore::encode(['theme'=>$prepared['params']['theme'],'original_prompt'=>$prepared['params']['prompt'],'mode'=>$prepared['params']['mode'],'analysis'=>$row['analysis'],'direction'=>$prepared['direction']['prompt'],'continuity'=>$prepared['config']['continuity_template'],'target_duration'=>$prepared['params']['target_duration']]);
            $timeline=MicroVideoTimeline::required(MicroVideoStore::json($row['config_snapshot']));
            $validate=$timeline?function(array $r)use($prepared){MicroVideoTimeline::validate((string)$r['content'],(int)$prepared['params']['target_duration']);}:null;
            $system=$prepared['config']['prompt_template'].($timeline?"\n".MicroVideoTimeline::instructions(false):'');
            $result=MicroVideoText::run($row,'prompt',$prepared['config']['script_model_id'],$system,$content,[],$validate);
            if($validate)$validate($result);
            MicroVideoStore::update($row['id'],['prompt'=>$result['content'],'status'=>'prompt_ready']);
            return ['prompt'=>$result['content'],'analysis'=>$row['analysis'],'draft_id'=>(int)$row['id'],'planning_points'=>self::planningPoints((int)$row['id'])];
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function createPlan(int $tenant,int $user,array $p): array
    {
        $prepared=self::form($tenant,$user,$p);$row=self::createDraft($tenant,$user,$prepared,'plan:'.self::key($p),'planning');
        if((int)($p['draft_id']??0)>0&&$row['analysis']===''){
            $source=MicroVideoStore::owned($tenant,$user,(int)$p['draft_id']);$old=MicroVideoStore::json($source['params_json']);$new=$prepared['params'];$oldConfig=MicroVideoStore::json($source['config_snapshot']);
            if(MicroVideoPolicy::fingerprint(MicroVideoPolicy::stableParams(['images'=>$old['images']??[]]))===MicroVideoPolicy::fingerprint(MicroVideoPolicy::stableParams(['images'=>$new['images']]))&&($oldConfig['vision_model_id']??'')===$prepared['config']['vision_model_id']&&($oldConfig['analysis_template']??'')===$prepared['config']['analysis_template'])MicroVideoStore::update((int)$row['id'],['analysis'=>$source['analysis']]);
        }
        return self::detail($tenant,$user,(int)$row['id']);
    }
    public static function savePlan(int $tenant,int $user,array $p): array
    {
        self::available($tenant);$id=(int)($p['id']??0);
        Db::transaction(function()use($tenant,$user,$id,$p){
            $row=MicroVideoStore::owned($tenant,$user,$id,true);if($row['status']!=='awaiting_confirmation'||(int)$row['plan_revision']!==(int)($p['plan_revision']??-1))throw new InvalidArgumentException('方案已变化，请刷新后重新确认');
            $params=MicroVideoStore::json($row['params_json']);$config=MicroVideoStore::json($row['config_snapshot']);$model=MicroVideoStore::json($row['model_snapshot']);
            MicroVideoMedia::validateImages($tenant,$user,$params['images'],$config['max_images']);
            $allowed=array_column(MicroVideoCatalog::specs($model,$params['quality'],$params['ratio']),'duration');
            $segments=MicroVideoPolicy::segments((array)($p['segments']??[]),$allowed,count($params['images']),(int)$model['max_reference_images'],(int)$config['max_segments'],MicroVideoTimeline::required($config));
            self::replaceSegments($row,$segments);MicroVideoStore::update($id,['plan_revision'=>(int)$row['plan_revision']+1,'quote_token'=>'','quote_json'=>'{}']);
        });return self::detail($tenant,$user,$id);
    }
    public static function replaceSegments(array $task,array $segments): void
    {
        if(MicroVideoStore::table('attempt')->where(['task_id'=>$task['id'],'kind'=>'video'])->count())throw new InvalidArgumentException('视频已提交，不能覆盖已确认方案');
        MicroVideoStore::table('segment')->where('task_id',$task['id'])->delete();
        foreach($segments as $s){$s['image_indices']=MicroVideoStore::encode($s['image_indices']);MicroVideoStore::table('segment')->insert($s+['task_id'=>$task['id'],'tenant_id'=>$task['tenant_id'],'user_id'=>$task['user_id'],'status'=>'pending','create_time'=>time(),'update_time'=>time()]);}
    }
    public static function estimate(int $tenant,int $user,array $p,bool $admin=false): array
    {
        self::available($tenant);
        if(empty($p['id'])){
            $form=self::form($tenant,$user,$p,false);$params=$form['params'];$specs=MicroVideoCatalog::specs($form['model'],$params['quality'],$params['ratio']);$durations=MicroVideoPolicy::durations($params['target_duration'],array_column($specs,'duration'),$form['config']['max_segments']);$sum=0;
            foreach($durations as $d){$q=MicroVideoCatalog::quote($tenant,MicroVideoCatalog::spec($form['model'],$params['quality'],$params['ratio'],$d),$params['model_id']);$sum+=$q['user_charge_points'];}
            return ['user_charge_points'=>round($sum,4),'approximate'=>true,'planned_duration'=>array_sum($durations),'planning_points'=>0,'planning_notice'=>'图片理解和提示词规划按模型实际用量单独计费'];
        }
        $id=(int)$p['id'];$row=MicroVideoStore::owned($tenant,$admin?0:$user,$id);$revision=(int)($p['plan_revision']??$row['plan_revision']);if($revision!==(int)$row['plan_revision'])throw new InvalidArgumentException('方案版本已变化，请刷新');
        $quote=self::quoteTask($row);
        $token=bin2hex(random_bytes(24));$expires=time()+600;
        Db::transaction(function()use($tenant,$user,$admin,$id,$revision,$quote,$token,$expires){$row=MicroVideoStore::owned($tenant,$admin?0:$user,$id,true);if((int)$row['plan_revision']!==$revision)throw new InvalidArgumentException('方案已修改，请重新估价');MicroVideoStore::update($id,['quote_json'=>MicroVideoStore::encode($quote),'quote_token'=>$token,'quote_expires'=>$expires]);});
        return ['user_charge_points'=>$quote['user_charge_points'],'planning_points'=>self::planningPoints($id),'approximate'=>false,'quote_token'=>$token,'plan_revision'=>$revision,'expires_at'=>$expires];
    }
    public static function quoteTask(array $row): array
    {
        $params=MicroVideoStore::json($row['params_json']);$config=MicroVideoSettings::get((int)$row['tenant_id']);MicroVideoPolicy::allowedModel($params['model_id'],$config['video_model_ids']);$model=MicroVideoCatalog::model((int)$row['tenant_id'],$params['model_id']);
        $rows=MicroVideoStore::segments((int)$row['id']);$quotes=[];$user=0.;$tenant=0.;
        foreach($rows as $segment){
            if(!in_array($segment['status'],['pending','failed'],true))continue;
            $spec=MicroVideoCatalog::spec($model,$params['quality'],$params['ratio'],(int)$segment['duration']);$q=MicroVideoCatalog::quote((int)$row['tenant_id'],$spec,$params['model_id']);$quotes[(string)$segment['id']]=$q;$user+=$q['user_charge_points'];$tenant+=$q['tenant_cost_points'];
        }
        return ['plan_revision'=>(int)$row['plan_revision'],'plan_hash'=>self::planHash($row,$rows),'segments'=>$quotes,'user_charge_points'=>round($user,4),'tenant_cost_points'=>round($tenant,4)];
    }
    private static function planHash(array $row,array $rows): string
    {
        return MicroVideoPolicy::fingerprint(['params'=>MicroVideoPolicy::stableParams(MicroVideoStore::json($row['params_json'])),'continuity'=>$row['continuity'],'revision'=>(int)$row['plan_revision'],'segments'=>array_map(fn($s)=>MicroVideoStore::segmentData($s),$rows)]);
    }
    public static function submit(int $tenant,int $user,array $p): array
    {
        self::available($tenant);$id=(int)($p['id']??0);$key=self::key($p);$row=MicroVideoStore::owned($tenant,$user,$id);
        if($row['submit_key']===$key&&$row['submit_key']!=='')return self::detail($tenant,$user,$id);
        $current=self::quoteTask($row);self::validateQuote($row,$p,$current);$params=MicroVideoStore::json($row['params_json']);MicroVideoMedia::validateImages($tenant,$user,$params['images'],15);
        PointService::assertCanConsumeAmounts($tenant,$user,$current['tenant_cost_points'],$current['user_charge_points']);
        Db::transaction(function()use($tenant,$user,$p,$id,$key,$current){$row=MicroVideoStore::owned($tenant,$user,$id,true);if($row['submit_key']===$key)return;if($row['status']!=='awaiting_confirmation')throw new InvalidArgumentException('作品已提交或仍在规划，请刷新状态');self::validateQuote($row,$p,$current);MicroVideoStore::update($id,['status'=>'running','confirmed_quote'=>MicroVideoStore::encode($current),'submit_key'=>$key,'error'=>'','next_run_at'=>time()]);});
        return self::detail($tenant,$user,$id);
    }
    private static function validateQuote(array $row,array $p,array $quote): void
    {
        if((int)($p['plan_revision']??$row['plan_revision'])!==(int)$row['plan_revision']||$row['quote_token']===''||!hash_equals($row['quote_token'],(string)($p['quote_token']??''))||(int)$row['quote_expires']<time()||MicroVideoPolicy::fingerprint(MicroVideoStore::json($row['quote_json']))!==MicroVideoPolicy::fingerprint($quote))throw new InvalidArgumentException('方案或报价已变化，请重新估价并确认');
    }
    public static function retry(int $tenant,int $user,array $p,bool $admin=false): array
    {
        self::available($tenant);$id=(int)($p['id']??0);$key=self::key($p);$row=MicroVideoStore::owned($tenant,$admin?0:$user,$id);
        if($row['resume_key']===$key)return self::detail($tenant,$admin?0:$user,$id,$admin);
        if($row['status']!=='waiting_continue')throw new InvalidArgumentException('当前作品无需继续，请刷新查看');
        MicroVideoText::reconcile($id);$quote=self::quoteTask($row);
        if(!$admin)self::validateQuote($row,$p,$quote);
        PointService::assertCanConsumeAmounts($tenant,(int)$row['user_id'],$quote['tenant_cost_points'],$quote['user_charge_points']);
        Db::transaction(function()use($tenant,$user,$id,$p,$admin,$key,$quote){
            $row=MicroVideoStore::owned($tenant,$admin?0:$user,$id,true);if($row['resume_key']===$key)return;if($row['status']!=='waiting_continue')throw new InvalidArgumentException('作品正在继续，请刷新查看');if(!$admin)self::validateQuote($row,$p,$quote);
            $segments=MicroVideoStore::segments($id);$ids=MicroVideoPolicy::retryIds($segments);
            if($admin&&!empty($p['segment_id'])){if(!in_array((int)$p['segment_id'],$ids,true))throw new InvalidArgumentException('该分段无需重试');$ids=[(int)$p['segment_id']];}
            if(!empty($p['merge_only'])&&$ids)throw new InvalidArgumentException('仍有未完成内容，不能仅合并');
            if($ids)MicroVideoStore::table('segment')->whereIn('id',$ids)->update(['status'=>'pending','error'=>'','update_time'=>time()]);
            $unknown=MicroVideoStore::table('attempt')->where('task_id',$id)->whereIn('status',['submitting','unknown'])->count();if(!$segments&&$unknown)throw new InvalidArgumentException('模型调用状态待核对，已保留进度');
            if(!$segments)MicroVideoStore::table('attempt')->where('task_id',$id)->where('status','validation_failed')->where('kind','<>','video')->update(['status'=>'failed','update_time'=>time()]);
            MicroVideoStore::update($id,['status'=>$segments?'running':'planning','resume_key'=>$key,'confirmed_quote'=>MicroVideoStore::encode($quote),'error'=>'','next_run_at'=>time(),'merge_status'=>'pending']);
        });return self::detail($tenant,$admin?0:$user,$id,$admin);
    }
    public static function detail(int $tenant,int $user,int $id,bool $admin=false): array
    {
        $row=MicroVideoStore::owned($tenant,$admin?0:$user,$id);$params=MicroVideoStore::json($row['params_json']);
        // Never return internal file identity, supplier errors, pricing costs or system templates.
        foreach($params['images']??[] as $index=>$image){try{$image=MicroVideoMedia::validateImages($tenant,(int)$row['user_id'],[$image],1)[0];}catch(\Throwable){$image['url']='';}$params['images'][$index]=array_intersect_key($image,array_flip(['uri','url','name','role']));}
        $state=MicroVideoPolicy::publicState($row['status']);unset($state['can_show_error']);
        $out=$state+['id'=>(int)$row['id'],'title'=>$row['title'],'params'=>$params,'prompt'=>$row['prompt'],'analysis'=>$row['analysis'],'continuity'=>$row['continuity'],'plan_revision'=>(int)$row['plan_revision'],'segments'=>[],'estimate'=>['user_charge_points'=>(float)(MicroVideoStore::json($row['quote_json'])['user_charge_points']??0),'planning_points'=>self::planningPoints($id)],'result_url'=>self::fileUrl($row['result_uri'],$row),'cover_url'=>self::fileUrl($row['cover_uri'],$row),'actual_duration'=>(float)$row['actual_duration'],'create_time'=>(int)$row['create_time'],'update_time'=>(int)$row['update_time']];
        if($row['status']==='awaiting_confirmation'||$admin){
            foreach(MicroVideoStore::segments($id) as $segment){$s=MicroVideoStore::segmentData($segment);if($admin){$s+=array_intersect_key($segment,array_flip(['status','actual_duration','video_uri','error','attempt_no']));$s['can_retry']=$segment['status']==='failed';$s['attempts']=MicroVideoStore::table('attempt')->where('segment_id',$segment['id'])->order('attempt_no')->select()->toArray();}$out['segments'][]=$s;}
        }
        if($admin){
            $attempts=MicroVideoStore::table('attempt')->where('task_id',$id)->order('id')->select()->toArray();
            $consumptionIds=array_values(array_filter(array_column($attempts,'consumption_id')));
            $out+=['tenant_id'=>$tenant,'user_id'=>(int)$row['user_id'],'merge_status'=>$row['merge_status'],'error'=>$row['error'],'attempts'=>$attempts,
                'user_charge_points'=>$consumptionIds?(float)Db::name('ai_consumption_log')->whereIn('id',$consumptionIds)->sum('actual_user_price'):0,
                'tenant_cost_points'=>$consumptionIds?(float)Db::name('ai_consumption_log')->whereIn('id',$consumptionIds)->sum('actual_tenant_cost'):0];
            foreach($out['segments'] as &$s){$source=MicroVideoStore::table('segment')->where('id',$s['id'])->find();$s['video_url']=self::fileUrl($source['video_uri'],$source);}unset($s);
        }
        return $out;
    }
    public static function lists(int $tenant,int $user,array $p,bool $admin=false): array
    {
        $q=MicroVideoStore::table('task')->where(['tenant_id'=>$tenant,'delete_time'=>0])->whereNotIn('status',['prompt_draft','prompt_ready']);if(!$admin)$q->where('user_id',$user);
        if(!empty($p['status']))$q->where('status',(string)$p['status']);
        if($admin&&!empty($p['user_keyword'])){$word=trim((string)$p['user_keyword']);$users=Db::name('user')->where('tenant_id',$tenant)->whereLike('account|nickname','%'.$word.'%')->column('id');if(ctype_digit($word))$users[]=(int)$word;$q->whereIn('user_id',$users?:[0]);}
        $count=(clone $q)->count();$ids=$q->order('id','desc')->page(max(1,(int)($p['page_no']??$p['page']??1)),max(1,min(50,(int)($p['page_size']??20))))->column('id');
        return ['lists'=>array_map(fn($id)=>self::detail($tenant,$user,(int)$id,$admin),$ids),'count'=>$count];
    }
    public static function delete(int $tenant,int $user,int $id): void
    {
        Db::transaction(function()use($tenant,$user,$id){$row=MicroVideoStore::owned($tenant,$user,$id,true);if(in_array($row['status'],['planning','running','merging'],true))throw new InvalidArgumentException('创作进行中，请完成后再删除');MicroVideoStore::update($id,['delete_time'=>time()]);});
    }
    public static function planningPoints(int $id): float{return round((float)MicroVideoStore::table('attempt')->where('task_id',$id)->where('kind','<>','video')->sum('user_charge_points'),4);}
    private static function fileUrl(string $uri,array $row): string{return $uri===''?'':FileService::getFileUrlByStorage($uri,$row['storage_scope'],$row['storage_engine'],$row['storage_domain']);}
}
