<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\app\aigc_llm\agent\AgentWorkerHealth as H;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$dir=sys_get_temp_dir().'/agent-heartbeat-'.bin2hex(random_bytes(8));
try{
 $h=new H($dir);$rows=H::processes($dir);check(count($rows)===1&&$rows[0]['healthy'],'Live worker not recognized');
 $h->beat('busy');$file=glob($dir.'/*.json')[0];$row=json_decode(file_get_contents($file),true);$row['updated_at']=time()-100;file_put_contents($file,json_encode($row));check(H::processes($dir)[0]['healthy'],'Valid synchronous turn marked unhealthy');
 $row['state']='idle';file_put_contents($file,json_encode($row));check(!H::processes($dir)[0]['healthy'],'Stale idle worker marked healthy');
 $row['state']='busy';$row['updated_at']=time()-1600;file_put_contents($file,json_encode($row));check(!H::processes($dir)[0]['healthy'],'Expired busy worker marked healthy');
 $h->close();check(H::processes($dir)===[],'Stopped worker retained live heartbeat');
 $base=['processes'=>[['healthy'=>true]],'media_dependencies'=>['ffmpeg'=>true], 'receipt_storage_writable'=>true,
  'queue'=>['oldest_ready_age_seconds'=>0,'expired_leases'=>0,'oldest_expired_lease_age_seconds'=>0,'review_runs'=>0],
  'stt_review'=>['count'=>0],'speech_review'=>['count'=>0]];
 check(H::evaluate($base)['healthy']&&!H::evaluate($base)['attention_required'],'Clean health false alarm');
 foreach(['worker_unavailable','media_dependency_unavailable','receipt_storage_unwritable','queue_stalled','lease_recovery_stalled'] as $code){
  $sample=$base;
  if($code==='worker_unavailable')$sample['processes']=[];
  if($code==='media_dependency_unavailable')$sample['media_dependencies']['ffmpeg']=false;
  if($code==='receipt_storage_unwritable')$sample['receipt_storage_writable']=false;
  if($code==='queue_stalled')$sample['queue']['oldest_ready_age_seconds']=300;
  if($code==='lease_recovery_stalled'){$sample['queue']['expired_leases']=1;$sample['queue']['oldest_expired_lease_age_seconds']=60;}
  $result=H::evaluate($sample);check(!$result['healthy']&&$result['issues'][0]['code']===$code,'Missed fault '.$code);
 }
 foreach(['stt_review','speech_review'] as $kind){$sample=$base;$sample[$kind]['count']=1;$result=H::evaluate($sample);check($result['healthy']&&$result['attention_required']&&$result['issues'][0]['code']==='manual_review_required','Review should alert without declaring worker dead');}
 $sample=$base;$sample['queue']['expired_leases']=1;$sample['queue']['oldest_expired_lease_age_seconds']=59;check(H::evaluate($sample)['healthy'],'No recovery grace period');
 // Verify real query semantics: a one-hour-old supplier job due now is not an hour of queue starvation.
 $db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
 \think\facade\Db::startTrans();try{
  $table=\app\common\service\app\aigc_llm\agent\AgentStore::table('run');
  check((clone $table)->whereIn('status',['queued','waiting_tasks'])->count()===0,'Needs idle fixture queue');
  $now=time();$id=$table->insertGetId(['tenant_id'=>1,'user_id'=>1,'session_id'=>0,'message_id'=>0,'request_id'=>'health-'.bin2hex(random_bytes(12)),'payload_hash'=>str_repeat('a',64),'content'=>'health fixture','preferences'=>'{}','attachments'=>'[]','status'=>'waiting_tasks','phase'=>'tools','reply'=>'','next_run_time'=>$now,'create_time'=>$now-3600,'update_time'=>$now]);
  check(H::report()['queue']['oldest_ready_age_seconds']<5,'Long supplier job incorrectly marked stalled');
  (clone $table)->where('id',$id)->update(['next_run_time'=>$now-301]);check(in_array('queue_stalled',array_column(H::report()['issues'],'code'),true),'Overdue query missed');
  (clone $table)->where('id',$id)->update(['status'=>'queued','next_run_time'=>0]);check(H::report()['queue']['oldest_ready_age_seconds']>=3600,'New queue wait must use creation time');
 }finally{\think\facade\Db::rollback();}
 echo "Agent health passed: live/idle/busy/stale heartbeat and graceful cleanup\n";
}finally{foreach(glob($dir.'/*')?:[] as $f)unlink($f);@rmdir($dir);}
