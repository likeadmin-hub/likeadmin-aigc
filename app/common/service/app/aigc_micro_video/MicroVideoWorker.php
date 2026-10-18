<?php
namespace app\common\service\app\aigc_micro_video;

use think\facade\Db;
use RuntimeException;

/** One leased stage per tick. No SQL lock spans provider HTTP or media processing. */
final class MicroVideoWorker
{
    public static function tick(int $id=0): int
    {
        $token=bin2hex(random_bytes(16));$task=null;$processLock=null;
        $q=MicroVideoStore::table('task')->where('delete_time',0)->where(function($q){$q->whereIn('status',['planning','running','merging'])->whereOr(function($f){$f->where('status','awaiting_confirmation')->where('frame_status','generating');});})->where('next_run_at','<=',time())->where('lease_until','<',time());if($id)$q->where('id',$id);
        $candidates=$q->order(['next_run_at'=>'asc','id'=>'asc'])->limit(10)->column('id');
        foreach($candidates as $candidate){
            // This worker is a same-runtime supervised service; flock remains held
            // even during long FFmpeg/HTTP operations and is released on death.
            $processLock=fopen(runtime_path().'micro_video_task_'.(int)$candidate.'.lock','c');if(!$processLock||!flock($processLock,LOCK_EX|LOCK_NB)){if($processLock)fclose($processLock);$processLock=null;continue;}
            $task=Db::transaction(function()use($candidate,$token){$row=MicroVideoStore::table('task')->where(['id'=>$candidate,'delete_time'=>0])->where(function($q){$q->whereIn('status',['planning','running','merging'])->whereOr(function($f){$f->where('status','awaiting_confirmation')->where('frame_status','generating');});})->where('next_run_at','<=',time())->where('lease_until','<',time())->lock(true)->find();if(!$row)return null;$lease=['lease_token'=>$token,'lease_until'=>time()+600];MicroVideoStore::update((int)$row['id'],$lease);return array_replace($row,$lease);});
            if($task)break;flock($processLock,LOCK_UN);fclose($processLock);$processLock=null;
        }
        if(!$task)return 0;
        try{
            if($task['status']==='awaiting_confirmation')MicroVideoFrameWorker::run($task);elseif($task['status']==='planning')self::plan($task);else self::video($task);
        }catch(\Throwable $e){
            MicroVideoStore::update((int)$task['id'],($task['status']==='awaiting_confirmation'?['frame_status'=>'waiting_continue']:['status'=>'waiting_continue'])+['error'=>mb_substr($e->getMessage(),0,1000),'next_run_at'=>time()+10]);
            \think\facade\Log::warning('Micro video task '.$task['id'].': '.$e->getMessage());
        }finally{
            MicroVideoStore::table('task')->where(['id'=>$task['id'],'lease_token'=>$token])->update(['lease_token'=>'','lease_until'=>0,'update_time'=>time()]);
            flock($processLock,LOCK_UN);fclose($processLock);
        }
        return 1;
    }
    private static function planningContract(array $durations): array
    {
        if(!$durations||array_filter($durations,fn($duration)=>!is_int($duration)||$duration<1))throw new \InvalidArgumentException('规划时长必须为正整数');
        return ['output_schema'=>['prompt'=>'完整整片提示词','continuity'=>'整片主体与风格共同设定','segments'=>[['title'=>'本段标题','duration'=>$durations[0],'prompt'=>'可独立调用的完整视频提示词，包含主体设定和衔接','image_indices'=>[0],'start_state'=>'起始状态','end_state'=>'结束状态','continuity'=>'前后衔接与必须保持项']]],'requirements'=>'严格按durations数量和顺序生成每段。每段duration必须逐段使用durations对应位置的JSON整数，禁止字符串和秒单位（如"5"、"5秒"），不得自行改写时长。不输出解释。遵循提示词模式保留用户故事与要求，不擅自换题材。每段引用实际图片；角色/商品/场景不得突变。'];
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
        $specs=MicroVideoCatalog::specs($model,$p['quality'],$p['ratio']);$supported=array_column($specs,'duration');$adaptive=MicroVideoContentPlanning::required($c);
        if($adaptive)$contract=MicroVideoContentPlanning::contract($p,$supported,(int)$c['max_segments'],(int)$c['content_planning_version']);
        else{$durations=MicroVideoPolicy::durations($p['target_duration'],$supported,(int)$c['max_segments']);$contract=['durations'=>$durations]+self::planningContract($durations);}
        $input=['mode'=>$p['mode'],'theme'=>$p['theme'],'confirmed_prompt'=>$p['prompt'],'image_analysis'=>$task['analysis'],'direction'=>$direction['prompt'],'shared_continuity'=>$c['continuity_template'].' '.$direction['continuity_prompt'],'image_count'=>count($images),'image_index_base'=>0,'max_reference_images'=>(int)$model['max_reference_images'],'ratio'=>$p['ratio']]+$contract;
        $timeline=MicroVideoTimeline::required($c);
        if($timeline){$input['output_schema']['segments'][0]['prompt']='共同主体设定与逐行镜头时间轴，每行格式为开始秒-结束秒：具体画面与运镜。每段从0秒开始，连续覆盖该段duration秒，至少两个镜头。';$input['requirements'].=MicroVideoTimeline::instructions();}
        $parseSegments=function(array $parsed)use($adaptive,$contract,$supported,$images,$model,$c,$timeline){
            if($adaptive)return MicroVideoContentPlanning::validate($parsed,$contract,count($images),(int)$model['max_reference_images'],$timeline);
            $segments=MicroVideoPolicy::segments($parsed['segments'],$supported,count($images),(int)$model['max_reference_images'],(int)$c['max_segments'],$timeline);if(array_column($segments,'duration')!==$contract['durations'])throw new \InvalidArgumentException('模型分段时长与已规划规格不一致');if(trim((string)($parsed['continuity']??''))==='')throw new \InvalidArgumentException('方案缺少整片一致性设定');return $segments;
        };
        $validate=function(array $r)use($parseSegments){$parseSegments(MicroVideoText::parse((string)$r['content']));};
        $system=$c['planning_template'].($timeline?"\n".MicroVideoTimeline::instructions():'').($adaptive?"\n".MicroVideoContentPlanning::instructions((int)$c['content_planning_version']):'');
        $r=MicroVideoText::run($task,'script',$c['script_model_id'],$system,MicroVideoStore::encode($input),[],$validate);$parsed=MicroVideoText::parse((string)$r['content']);$segments=$parseSegments($parsed);
        Db::transaction(function()use($task,$parsed,$segments,$p,$c){$fresh=MicroVideoStore::owned((int)$task['tenant_id'],(int)$task['user_id'],(int)$task['id'],true);if($fresh['status']!=='planning')return;MicroVideoService::replaceSegments($fresh,$segments);MicroVideoStore::update((int)$task['id'],['status'=>'awaiting_confirmation','prompt'=>$p['prompt']!==''?$p['prompt']:(string)($parsed['prompt']??$p['theme']),'continuity'=>$c['continuity_template'].' '.(string)$parsed['continuity'],'planning_result'=>MicroVideoStore::encode($parsed),'plan_revision'=>(int)$fresh['plan_revision']+1,'error'=>'']);});
    }
    private static function video(array $task): void
    {
        $segments=MicroVideoStore::segments((int)$task['id']);if(!$segments)throw new RuntimeException('作品缺少已确认方案');
        // Submit the entire confirmed plan before polling older accepted requests.
        // Each segment keeps its own immutable attempt, reservation and provider task.
        $pending=array_filter($segments,fn($s)=>$s['status']==='pending');
        $accepted=array_filter($segments,fn($s)=>!in_array($s['status'],['pending','success','failed'],true));
        foreach(array_merge($pending,$accepted) as $segment){
            // The process flock stays held; renew this owner's durable lease between I/O calls.
            MicroVideoStore::table('task')->where(['id'=>$task['id'],'lease_token'=>$task['lease_token']])->update(['lease_until'=>time()+600]);
            try{self::segment($task,$segment);}catch(\Throwable $e){
                // A rejected/unstorable segment must not strand other paid requests.
                \think\facade\Log::warning('Micro video segment '.$segment['id'].': '.get_class($e));
            }
        }
        $segments=MicroVideoStore::segments((int)$task['id']);
        if(array_filter($segments,fn($s)=>!in_array($s['status'],['success','failed','storing'],true))){
            MicroVideoStore::update((int)$task['id'],['next_run_at'=>time()+5]);return;
        }
        $blocked=array_values(array_filter($segments,fn($s)=>$s['status']!=='success'));
        if($blocked){MicroVideoStore::update((int)$task['id'],['status'=>'waiting_continue','error'=>$blocked[0]['error']?:'部分片段未完成，请核对后继续']);return;}
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
                $mode=$task['generation_mode']??'reference';$spec=MicroVideoCatalog::spec($model,$p['quality'],$p['ratio'],(int)$segment['duration'],$mode);
                $frozen=MicroVideoStore::json($task['confirmed_quote'])['segments'][(string)$segment['id']]??null;if(!$frozen)throw new RuntimeException('当前片段缺少已确认报价，请重新确认费用');
                if($mode==='start_end'){$selected=[];foreach(['first_frame_id','last_frame_id'] as $key){$f=MicroVideoStore::table('frame')->where(['id'=>(int)$segment[$key],'task_id'=>$task['id'],'tenant_id'=>$tenant,'user_id'=>$user,'status'=>'success','confirmed'=>1])->find();if(!$f)throw new RuntimeException('已确认首尾帧不存在');$selected[]=MicroVideoMedia::validateImages($tenant,$user,[['uri'=>$f['image_uri']]],1)[0];}}
                // Capture references as actual bytes/public storage URLs, not browser localhost URLs.
                $uris=MicroVideoMedia::visionInputs($selected);$assets=MicroVideoFramePolicy::assets($mode,$uris,$spec['generation_method']);
                $request=$frozen['selection']+['model_id'=>$p['model_id'],'channel'=>$p['model_id'],'resolution'=>$p['quality'],'quantity'=>1,'prompt'=>self::segmentPrompt($task,$segment),'reference_images'=>$uris,'reference_assets'=>$assets];
                $attempt=Db::transaction(function()use($task,$segment,$request,$tenant,$user,$frozen,$mode){
                    $current=MicroVideoStore::table('segment')->where(['id'=>$segment['id'],'task_id'=>$task['id']])->lock(true)->find();if($current['status']!=='pending')return null;
                    $no=(int)$current['attempt_no']+1;$id=MicroVideoStore::table('attempt')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$task['id'],'segment_id'=>$segment['id'],'kind'=>'video','attempt_no'=>$no,'status'=>'prepared','request_json'=>MicroVideoStore::encode(['selection'=>$frozen['selection'],'request'=>$request,'generation_mode'=>$mode,'frame_ids'=>$mode==='start_end'?[(int)$segment['first_frame_id'],(int)$segment['last_frame_id']]:[]]),'result_json'=>'{}','user_charge_points'=>$frozen['user_charge_points'],'create_time'=>time(),'update_time'=>time()]);
                    MicroVideoStore::table('segment')->where('id',$segment['id'])->update(['status'=>'submitting','attempt_no'=>$no,'attempt_id'=>$id,'error'=>'','update_time'=>time()]);return MicroVideoStore::table('attempt')->where('id',$id)->find();
                });
                if(!$attempt)return;
            }
            if(!$attempt)throw new RuntimeException('片段执行记录缺失，需核对');
            $frozenRequest=MicroVideoStore::json($attempt['request_json']);$selection=$frozenRequest['selection'];$request=$frozenRequest['request'];$runtime=MicroVideoCatalog::runtime($selection);
            if((int)$attempt['consumption_id']===0)$attempt=MicroVideoReservation::reserve($task,$segment,$attempt,$selection,$request);
            $receipt=MicroVideoStore::json($attempt['result_json']);
            $billing=Db::name('ai_consumption_log')->where('id',$attempt['consumption_id'])->value('billing_status');
            if(!empty($receipt['videos'])&&$billing==='settled')$response=$receipt;
            elseif(Db::name('ai_consumption_log')->where('id',$attempt['consumption_id'])->value('run_status')==='reserved'){
                MicroVideoStore::table('attempt')->where('id',$attempt['id'])->update(['status'=>'submitting','update_time'=>time()]);
                $response=$runtime::submit((int)$attempt['consumption_id'],$request);
            }else $response=$runtime::refresh((int)$attempt['consumption_id']);
            $consumption=Db::name('ai_consumption_log')->where('id',$attempt['consumption_id'])->find();
            // A retained provider receipt is not deliverable until settlement commits.
            if(($consumption['billing_status']??'')!=='settled')$response['videos']=[];
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
        $config=MicroVideoStore::json($task['config_snapshot']);$header='';
        if(MicroVideoTimeline::required($config)){MicroVideoTimeline::validate((string)$segment['prompt'],(int)$segment['duration']);$header='本次生成片段总时长'.$segment['duration'].'秒。以下镜头时间均从本段0秒开始，按时间轴依次拍摄并自然衔接；不提前结束，不延长时间轴。'."\n";}
        $parts=[$header.'整片共同设定：'.$task['continuity'],'本段画面：'.$segment['prompt'],'起始状态：'.$segment['start_state'],'结束状态：'.$segment['end_state'],'衔接要求：'.$segment['continuity']];
        $neighbors=MicroVideoStore::segments((int)$task['id']);foreach($neighbors as $n){if((int)$n['sort']===(int)$segment['sort']-1)$parts[]='前段结束：'.$n['end_state'];if((int)$n['sort']===(int)$segment['sort']+1)$parts[]='后段开始：'.$n['start_state'];}
        if(($task['generation_mode']??'reference')==='start_end'){foreach(['first_frame_id'=>'首帧静态画面','last_frame_id'=>'尾帧静态画面'] as $key=>$label){$f=MicroVideoStore::table('frame')->where(['id'=>$segment[$key],'task_id'=>$task['id']])->find();if($f)$parts[]=$label.'：'.$f['prompt'];}$parts[]='从首帧图的确切静态状态开始，严格到达尾帧图状态；服装、人物、商品、空间、光线、构图与比例以图为准，段内按时间轴完成动作。';}
        if((int)$segment['attempt_no']>0)$parts[]='补生成要求：'.$config['retry_template'];return implode("\n",$parts);
    }
}
