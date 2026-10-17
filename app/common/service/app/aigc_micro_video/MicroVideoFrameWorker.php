<?php
namespace app\common\service\app\aigc_micro_video;
use app\common\service\power\MarketImageModelRuntimeService as R;
use think\facade\Db;
use RuntimeException;

/** Called under the parent task flock/lease. Each tick advances one boundary. */
final class MicroVideoFrameWorker
{
    public static function run(array $task): void
    {
        $batch=MicroVideoStore::table('frame_batch')->where(['id'=>$task['frame_batch_id'],'task_id'=>$task['id'],'tenant_id'=>$task['tenant_id'],'user_id'=>$task['user_id']])->find();if(!$batch)throw new RuntimeException('首尾帧执行记录缺失');$ids=MicroVideoStore::json($batch['frame_ids']);
        $frames=MicroVideoFrames::rows((int)$task['id']);$by=array_column($frames,null,'id');
        foreach($ids as $id){$frame=$by[$id]??null;if(!$frame)throw new RuntimeException('首尾帧选中版本已变化');if($frame['status']==='success')continue;if($frame['status']==='failed'){self::finish($task,$batch,'failed');return;}self::frame($task,$frame,$batch);MicroVideoStore::update((int)$task['id'],['next_run_at'=>time()+5]);return;}
        self::finish($task,$batch,'success');
    }
    private static function finish(array $task,array $batch,string $status): void
    {
        Db::transaction(function()use($task,$batch,$status){MicroVideoStore::table('frame_batch')->where('id',$batch['id'])->update(['status'=>$status,'update_time'=>time()]);MicroVideoStore::table('task')->where(['id'=>$task['id'],'frame_batch_id'=>$batch['id']])->update(['frame_status'=>$status==='failed'?'waiting_continue':'review','update_time'=>time()]);});
    }
    private static function frame(array $task,array $frame,array $batch): void
    {
        $attempt=null;$tenant=(int)$task['tenant_id'];$user=(int)$task['user_id'];
        try{
            if((int)$frame['attempt_id']>0)$attempt=MicroVideoStore::table('attempt')->where(['id'=>$frame['attempt_id'],'frame_id'=>$frame['id'],'task_id'=>$task['id']])->find();
            $quote=MicroVideoStore::json($batch['quote_json']);$selection=$quote['selection'];
            if(!$attempt){
                MicroVideoService::available($tenant);$params=MicroVideoStore::json($task['params_json']);$config=MicroVideoStore::json($task['config_snapshot']);$images=MicroVideoMedia::validateImages($tenant,$user,$params['images'],15);$refs=MicroVideoStore::json($frame['reference_indices']);$selected=array_map(fn($i)=>$images[$i],$refs);$dependency='';
                if((int)$frame['boundary_index']>0){$previous=MicroVideoStore::table('frame')->where(['task_id'=>$task['id'],'boundary_index'=>(int)$frame['boundary_index']-1,'is_latest'=>1])->find();if(!$previous||$previous['status']!=='success')throw new RuntimeException('请先完成上一张连续性锚图');$anchor=MicroVideoMedia::validateImages($tenant,$user,[['uri'=>$previous['image_uri']]],1)[0];$selected[]=$anchor;$dependency=MicroVideoPolicy::fingerprint([(int)$previous['id'],(int)$previous['version'],$previous['image_uri']]);}
                $uris=MicroVideoMedia::visionInputs($selected);$request=$selection+['quantity'=>1,'prompt'=>($config['frame_template']??MicroVideoSettings::defaults()['frame_template'])."\n".$frame['prompt']."\n原始参考图按所选顺序排列。".((int)$frame['boundary_index']>0?'最后一张为上一边界锚图，继承其镜头几何和身份。':''),'reference_images'=>$uris];
                $attempt=Db::transaction(function()use($task,$frame,$request,$selection,$quote,$dependency){$current=MicroVideoStore::table('frame')->where(['id'=>$frame['id'],'is_latest'=>1])->lock(true)->find();if(!$current)throw new RuntimeException('首尾帧选中版本已变化');if((int)$current['attempt_id']>0)return MicroVideoStore::table('attempt')->where('id',$current['attempt_id'])->find();
                    $id=MicroVideoStore::table('attempt')->insertGetId(['tenant_id'=>$task['tenant_id'],'user_id'=>$task['user_id'],'task_id'=>$task['id'],'segment_id'=>0,'frame_id'=>$frame['id'],'kind'=>'frame','attempt_no'=>1,'status'=>'prepared','request_json'=>MicroVideoStore::encode(['selection'=>$selection,'request'=>$request,'quote'=>$quote['per_frame']]),'result_json'=>'{}','user_charge_points'=>$quote['per_frame']['user_charge_points'],'create_time'=>time(),'update_time'=>time()]);MicroVideoStore::table('frame')->where('id',$frame['id'])->update(['status'=>'running','attempt_id'=>$id,'dependency_fingerprint'=>$dependency,'confirmed'=>0,'error'=>'','update_time'=>time()]);return MicroVideoStore::table('attempt')->where('id',$id)->find();});
            }
            $frozen=MicroVideoStore::json($attempt['request_json']);$request=$frozen['request'];$selection=$frozen['selection'];
            if((int)$attempt['consumption_id']===0)$attempt=self::reserve($task,$frame,$attempt,$frozen);
            $c=Db::name('ai_consumption_log')->where('id',$attempt['consumption_id'])->find();if(!$c)throw new RuntimeException('图片计费记录缺失');
            $response=$c['run_status']==='reserved'?R::submit((int)$attempt['consumption_id'],$request):R::refresh((int)$attempt['consumption_id']);$c=Db::name('ai_consumption_log')->where('id',$attempt['consumption_id'])->find();if($c['billing_status']!=='settled')$response['images']=[];
            $knownFailed=in_array($c['run_status'],['failed','canceled'],true);MicroVideoStore::table('attempt')->where('id',$attempt['id'])->update(['status'=>$knownFailed?'failed':(!empty($response['images'])?'success':'running'),'provider_task_id'=>(string)($response['provider_task_id']??''),'result_json'=>MicroVideoStore::encode($response),'billing_status'=>$c['billing_status'],'user_charge_points'=>(float)$c['actual_user_price'],'update_time'=>time()]);if($knownFailed)throw new RuntimeException('图片生成未完成，请重试此图片');
            if(empty($response['images']))return;$image=$response['images'][0];$owned=MicroVideoMedia::validateImages($tenant,$user,[['uri'=>$image['image_uri']]],1)[0];
            MicroVideoStore::table('frame')->where(['id'=>$frame['id'],'is_latest'=>1,'attempt_id'=>$attempt['id']])->update(['status'=>'success','image_uri'=>$owned['uri'],'storage_scope'=>$owned['storage_scope'],'storage_engine'=>$owned['storage_engine'],'storage_domain'=>$owned['storage_domain'],'user_charge_points'=>(float)$c['actual_user_price'],'confirmed'=>0,'error'=>'','update_time'=>time()]);
        }catch(\Throwable $e){
            $c=$attempt&&(int)$attempt['consumption_id']>0?Db::name('ai_consumption_log')->where('id',$attempt['consumption_id'])->find():null;$known=!$c||in_array($c['run_status'],['failed','canceled'],true);
            MicroVideoStore::table('frame')->where(['id'=>$frame['id'],'is_latest'=>1])->update(['status'=>$known?'failed':'running','error'=>mb_substr($e->getMessage(),0,1000),'update_time'=>time()]);if($attempt&&$known)MicroVideoStore::table('attempt')->where('id',$attempt['id'])->update(['status'=>'failed','error'=>mb_substr($e->getMessage(),0,1000),'update_time'=>time()]);if($known)self::finish($task,$batch,'failed');else MicroVideoStore::update((int)$task['id'],['next_run_at'=>time()+15]);\think\facade\Log::warning('Micro frame '.$frame['id'].': '.$e->getMessage());
        }
    }
    public static function reserve(array $task,array $frame,array $attempt,array $frozen): array
    {
        return Db::transaction(function()use($task,$frame,$attempt,$frozen){$a=MicroVideoStore::table('attempt')->where(['id'=>$attempt['id'],'task_id'=>$task['id'],'frame_id'=>$frame['id'],'tenant_id'=>$task['tenant_id'],'user_id'=>$task['user_id'],'kind'=>'frame'])->lock(true)->find();if(!$a)throw new RuntimeException('图片执行记录缺失');if((int)$a['consumption_id']>0)return $a;if($a['status']!=='prepared')throw new RuntimeException('图片受理状态待核对');
            // The shared reservation and micro attempt link share this transaction.
            $r=R::reserve((int)$task['tenant_id'],(int)$task['user_id'],'frame_generate',(string)$frame['id'],$frozen['selection'],$frozen['request'],1,MicroVideoStore::APP,'aigc_micro_video_frame');$c=Db::name('ai_consumption_log')->where('id',$r['consumption_id'])->find();$q=$frozen['quote'];if((int)$c['sku_id']!==(int)$q['market_sku_id']||abs((float)$c['reserved_user_price']-(float)$q['user_charge_points'])>0.000001||abs((float)$c['reserved_tenant_cost']-(float)$q['tenant_cost_points'])>0.000001)throw new RuntimeException('图片模型售价已变化，请重新估价');
            $values=['consumption_id'=>(int)$r['consumption_id'],'app_task_id'=>(int)$r['app_task_id'],'billing_status'=>'reserved','update_time'=>time()];MicroVideoStore::table('attempt')->where('id',$a['id'])->update($values);return array_replace($a,$values);
        });
    }
}
