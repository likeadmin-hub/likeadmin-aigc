<?php
namespace app\common\service\app\aigc_micro_video;

use think\facade\Db;
use RuntimeException;

/** One leased stage per tick. No SQL lock spans provider HTTP or media processing. */
final class MicroVideoWorker
{
    public static function tick(int $id=0): int
    {
        $token=bin2hex(random_bytes(16));
        $task=Db::transaction(function()use($id,$token){
            $q=MicroVideoStore::table('task')->where('delete_time',0)->whereIn('status',['planning','running','merging'])->where('next_run_at','<=',time())->where('lease_until','<',time());if($id)$q->where('id',$id);
            $row=$q->order(['next_run_at'=>'asc','id'=>'asc'])->lock(true)->find();if(!$row)return null;
            MicroVideoStore::update((int)$row['id'],['lease_token'=>$token,'lease_until'=>time()+600]);return $row;
        });
        if(!$task)return 0;
        try{
            if($task['status']==='planning')self::plan($task);else self::video($task);
        }catch(\Throwable $e){
            MicroVideoStore::update((int)$task['id'],['status'=>'waiting_continue','error'=>mb_substr($e->getMessage(),0,1000),'next_run_at'=>time()+10]);
            \think\facade\Log::warning('Micro video task '.$task['id'].': '.$e->getMessage());
        }finally{
            MicroVideoStore::table('task')->where(['id'=>$task['id'],'lease_token'=>$token])->update(['lease_token'=>'','lease_until'=>0,'update_time'=>time()]);
        }
        return 1;
    }
    private static function plan(array $task): void
    {
        $tenant=(int)$task['tenant_id'];$user=(int)$task['user_id'];$p=MicroVideoStore::json($task['params_json']);$c=MicroVideoStore::json($task['config_snapshot']);$model=MicroVideoStore::json($task['model_snapshot']);$direction=MicroVideoStore::json($task['direction_snapshot']);
        MicroVideoService::available($tenant);
        $images=MicroVideoMedia::validateImages($tenant,$user,$p['images'],(int)$c['max_images']);
        if($task['analysis']===''){
            $r=MicroVideoText::run($task,'analysis',$c['vision_model_id'],$c['analysis_template'],'请按顺序逐张分析参考图片并说明它们之间的关系。',MicroVideoMedia::visionInputs($images));
            MicroVideoStore::update((int)$task['id'],['analysis'=>(string)$r['content'],'next_run_at'=>time()]);return;
        }
        $specs=MicroVideoCatalog::specs($model,$p['quality'],$p['ratio']);$supported=array_column($specs,'duration');$durations=MicroVideoPolicy::durations($p['target_duration'],$supported,(int)$c['max_segments']);
        $input=['mode'=>$p['mode'],'theme'=>$p['theme'],'confirmed_prompt'=>$p['prompt'],'image_analysis'=>$task['analysis'],'direction'=>$direction['prompt'],'shared_continuity'=>$c['continuity_template'].' '.$direction['continuity_prompt'],'durations'=>$durations,'image_count'=>count($images),'image_index_base'=>0,'max_reference_images'=>(int)$model['max_reference_images'],'ratio'=>$p['ratio'],'output_schema'=>['prompt'=>'完整整片提示词','continuity'=>'整片主体与风格共同设定','segments'=>[['title'=>'本段标题','duration'=>'必须按durations对应项','prompt'=>'可独立调用的完整视频提示词，包含主体设定和衔接','image_indices'=>[0],'start_state'=>'起始状态','end_state'=>'结束状态','continuity'=>'前后衔接与必须保持项']]],'requirements'=>'严格按durations数量和顺序生成每段，不输出解释。遵循提示词模式保留用户故事与要求，不擅自换题材。每段引用实际图片；角色/商品/场景不得突变。'];
        $validate=function(array $r)use($supported,$images,$model,$c,$durations){$parsed=MicroVideoText::parse((string)$r['content']);$segments=MicroVideoPolicy::segments($parsed['segments'],$supported,count($images),(int)$model['max_reference_images'],(int)$c['max_segments']);if(array_column($segments,'duration')!==$durations)throw new \InvalidArgumentException('模型分段时长与已规划规格不一致');if(trim((string)($parsed['continuity']??''))==='')throw new \InvalidArgumentException('方案缺少整片一致性设定');};
        $r=MicroVideoText::run($task,'script',$c['script_model_id'],$c['planning_template'],MicroVideoStore::encode($input),[],$validate);$validate($r);$parsed=MicroVideoText::parse((string)$r['content']);
        $segments=MicroVideoPolicy::segments($parsed['segments'],$supported,count($images),(int)$model['max_reference_images'],(int)$c['max_segments']);
        Db::transaction(function()use($task,$parsed,$segments,$p,$c){$fresh=MicroVideoStore::owned((int)$task['tenant_id'],(int)$task['user_id'],(int)$task['id'],true);if($fresh['status']!=='planning')return;MicroVideoService::replaceSegments($fresh,$segments);MicroVideoStore::update((int)$task['id'],['status'=>'awaiting_confirmation','prompt'=>$p['prompt']!==''?$p['prompt']:(string)($parsed['prompt']??$p['theme']),'continuity'=>$c['continuity_template'].' '.(string)$parsed['continuity'],'planning_result'=>MicroVideoStore::encode($parsed),'plan_revision'=>(int)$fresh['plan_revision']+1,'error'=>'']);});
    }
    private static function video(array $task): void
    {
        $segments=MicroVideoStore::segments((int)$task['id']);if(!$segments)throw new RuntimeException('作品缺少已确认方案');
        foreach($segments as $segment){
            if($segment['status']==='success'||$segment['status']==='failed')continue;
            self::segment($task,$segment);
            MicroVideoStore::update((int)$task['id'],['next_run_at'=>time()+5]);return;
        }
        if(array_filter($segments,fn($s)=>$s['status']==='failed')){MicroVideoStore::update((int)$task['id'],['status'=>'waiting_continue']);return;}
        MicroVideoStore::update((int)$task['id'],['status'=>'merging','merge_status'=>'running']);
        try{
            $result=MicroVideoMedia::merge($task,$segments);
            MicroVideoStore::update((int)$task['id'],['status'=>'success','merge_status'=>'success','result_uri'=>$result['uri'],'cover_uri'=>$result['cover_uri']??'','actual_duration'=>$result['duration'],'storage_scope'=>$result['storage_scope'],'storage_engine'=>$result['storage_engine'],'storage_domain'=>$result['storage_domain'],'error'=>'']);
        }catch(\Throwable $e){MicroVideoStore::update((int)$task['id'],['merge_status'=>'failed']);throw $e;}
    }
    private static function segment(array $task,array $segment): void
    {
        $tenant=(int)$task['tenant_id'];$user=(int)$task['user_id'];$p=MicroVideoStore::json($task['params_json']);$config=MicroVideoStore::json($task['config_snapshot']);$model=MicroVideoStore::json($task['model_snapshot']);$now=time();
        $attempt=null;
        try{
            if((int)$segment['attempt_id']>0)$attempt=MicroVideoStore::table('attempt')->where('id',$segment['attempt_id'])->find();
            if($segment['status']==='pending'){
                MicroVideoService::available($tenant);
                $images=MicroVideoMedia::validateImages($tenant,$user,$p['images'],(int)$config['max_images']);$indices=MicroVideoStore::json($segment['image_indices']);$selected=array_map(fn($index)=>$images[$index],$indices);
                $spec=MicroVideoCatalog::spec($model,$p['quality'],$p['ratio'],(int)$segment['duration']);
                $frozen=MicroVideoStore::json($task['confirmed_quote'])['segments'][(string)$segment['id']]??null;if(!$frozen)throw new RuntimeException('当前片段缺少已确认报价，请重新确认费用');
                // Capture references as actual bytes/public storage URLs, not browser localhost URLs.
                $uris=MicroVideoMedia::visionInputs($selected);$assets=[];foreach($uris as $i=>$uri)$assets[]=['type'=>'image','uri'=>$uri,'url'=>$uri,'role'=>$spec['generation_method']==='image_to_video'?'first_frame_image':'reference_image','name'=>$selected[$i]['name']??''];
                $request=$spec+['model_id'=>$p['model_id'],'channel'=>$p['model_id'],'resolution'=>$p['quality'],'quantity'=>1,'prompt'=>self::segmentPrompt($task,$segment),'reference_images'=>$uris,'reference_assets'=>$assets];
                $attempt=Db::transaction(function()use($task,$segment,$request,$tenant,$user,$frozen){
                    $current=MicroVideoStore::table('segment')->where(['id'=>$segment['id'],'task_id'=>$task['id']])->lock(true)->find();if($current['status']!=='pending')return null;
                    $no=(int)$current['attempt_no']+1;$id=MicroVideoStore::table('attempt')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$task['id'],'segment_id'=>$segment['id'],'kind'=>'video','attempt_no'=>$no,'status'=>'prepared','request_json'=>MicroVideoStore::encode(['selection'=>$frozen['selection'],'request'=>$request]),'result_json'=>'{}','user_charge_points'=>$frozen['user_charge_points'],'create_time'=>time(),'update_time'=>time()]);
                    MicroVideoStore::table('segment')->where('id',$segment['id'])->update(['status'=>'submitting','attempt_no'=>$no,'attempt_id'=>$id,'error'=>'','update_time'=>time()]);return MicroVideoStore::table('attempt')->where('id',$id)->find();
                });
                if(!$attempt)return;
            }
            if(!$attempt)throw new RuntimeException('片段执行记录缺失，需核对');
            $frozenRequest=MicroVideoStore::json($attempt['request_json']);$selection=$frozenRequest['selection'];$request=$frozenRequest['request'];$runtime=MicroVideoCatalog::runtime($selection);
            if((int)$attempt['consumption_id']===0){
                if($attempt['status']!=='prepared')throw new RuntimeException('片段提交结果待核对，请稍后查看');
                $expected=MicroVideoStore::json($task['confirmed_quote'])['segments'][(string)$segment['id']]??[];$current=MicroVideoCatalog::quote($tenant,$selection,$p['model_id']);
                if(MicroVideoPolicy::fingerprint($expected)!==MicroVideoPolicy::fingerprint($current))throw new RuntimeException('模型售价已变化，请重新确认费用');
                $reservation=$runtime::reserve($tenant,$user,MicroVideoStore::APP,'video_generate','aigc_micro_video_segment','micro:'.$attempt['id'],$selection,$request);
                $runtime::linkBusinessTask((int)$reservation['app_task_id'],(int)$segment['id']);
                MicroVideoStore::table('attempt')->where('id',$attempt['id'])->update(['consumption_id'=>(int)$reservation['consumption_id'],'app_task_id'=>(int)$reservation['app_task_id'],'update_time'=>time()]);$attempt['consumption_id']=(int)$reservation['consumption_id'];
            }
            $receipt=MicroVideoStore::json($attempt['result_json']);
            if(!empty($receipt['videos']))$response=$receipt;
            elseif($attempt['status']==='prepared'){
                MicroVideoStore::table('attempt')->where('id',$attempt['id'])->update(['status'=>'submitting','update_time'=>time()]);
                $response=$runtime::submit((int)$attempt['consumption_id'],$request);
            }else $response=$runtime::refresh((int)$attempt['consumption_id']);
            $consumption=Db::name('ai_consumption_log')->where('id',$attempt['consumption_id'])->find();
            $status=(string)($response['status']??'running');
            MicroVideoStore::table('attempt')->where('id',$attempt['id'])->update(['status'=>$status==='failed'?'failed':(!empty($response['videos'])?'storing':'running'),'provider_task_id'=>(string)($response['provider_task_id']??''),'result_json'=>MicroVideoStore::encode($response),'billing_status'=>(string)($consumption['billing_status']??''),'user_charge_points'=>(float)($consumption['actual_user_price']??$attempt['user_charge_points']),'update_time'=>time()]);
            if($status==='failed')throw new RuntimeException((string)($response['error']??'片段需要重新生成'));
            if(empty($response['videos'])){MicroVideoStore::table('segment')->where('id',$segment['id'])->update(['status'=>'running','update_time'=>time()]);return;}
            MicroVideoStore::table('segment')->where('id',$segment['id'])->update(['status'=>'storing','update_time'=>time()]);
            $video=$response['videos'][0];$uri=(string)($video['video_uri']??$video['url']??'');if($uri==='')throw new RuntimeException('视频结果暂不可读取');
            $stored=MicroVideoMedia::persistSegment($tenant,$user,$uri,(int)$task['id'],(int)$segment['id']);
            $values=['status'=>'success','video_uri'=>$stored['video_uri'],'actual_duration'=>$stored['duration'],'width'=>$stored['width'],'height'=>$stored['height'],'storage_scope'=>$stored['storage_scope'],'storage_engine'=>$stored['storage_engine'],'storage_domain'=>$stored['storage_domain'],'error'=>'','update_time'=>time()];
            MicroVideoStore::table('segment')->where('id',$segment['id'])->update($values);MicroVideoStore::table('attempt')->where('id',$attempt['id'])->update(['status'=>'success','update_time'=>time()]);
        }catch(\Throwable $e){
            $current=MicroVideoStore::table('segment')->where('id',$segment['id'])->find();$a=$attempt?MicroVideoStore::table('attempt')->where('id',$attempt['id'])->find():null;
            $c=$a&&(int)$a['consumption_id']>0?Db::name('ai_consumption_log')->where('id',$a['consumption_id'])->find():null;
            $knownFailure=!$c||in_array($c['run_status']??'',['failed','canceled'],true);
            // A durable/successful provider receipt is retried at persistence only.
            $status=$current['status']==='storing'?'storing':($knownFailure?'failed':'running');
            MicroVideoStore::table('segment')->where('id',$segment['id'])->update(['status'=>$status,'error'=>mb_substr($e->getMessage(),0,1000),'update_time'=>time()]);
            if($a&&$knownFailure)MicroVideoStore::table('attempt')->where('id',$a['id'])->update(['status'=>'failed','error'=>mb_substr($e->getMessage(),0,1000),'update_time'=>time()]);
            if($status==='running'){MicroVideoStore::update((int)$task['id'],['next_run_at'=>time()+15]);return;}throw $e;
        }
    }
    private static function segmentPrompt(array $task,array $segment): string
    {
        $parts=['整片共同设定：'.$task['continuity'],'本段画面：'.$segment['prompt'],'起始状态：'.$segment['start_state'],'结束状态：'.$segment['end_state'],'衔接要求：'.$segment['continuity']];
        $neighbors=MicroVideoStore::segments((int)$task['id']);foreach($neighbors as $n){if((int)$n['sort']===(int)$segment['sort']-1)$parts[]='前段结束：'.$n['end_state'];if((int)$n['sort']===(int)$segment['sort']+1)$parts[]='后段开始：'.$n['start_state'];}
        $config=MicroVideoStore::json($task['config_snapshot']);if((int)$segment['attempt_no']>0)$parts[]='补生成要求：'.$config['retry_template'];return implode("\n",$parts);
    }
}
