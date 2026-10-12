<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenWebhooks as W;
use app\common\service\open_platform\OpenRecovery as R;
use app\common\service\open_platform\OpenVault as V;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$c=config('database.connections.mysql');check($c['hostname']==='127.0.0.1'&&$c['database']==='z_cn','Local rollback fixture only');
Db::startTrans();try{
 $rid='req_fixture_'.bin2hex(random_bytes(16));$request=['request_id'=>$rid,'tenant_id'=>987654326,'user_id'=>123456786,'key_id'=>0,'method'=>'POST','path'=>'/api/v1/tools/aigc_llm/tasks','kind'=>'tool','app_code'=>'aigc_llm','status'=>'success','billing_status'=>'none','active'=>0,'consumption_ids'=>'[]','payload_hash'=>hash('sha256','fixture'),'response_blob'=>'','snapshot'=>S::encode(['customer_callback'=>['url'=>'https://example.com/callback','secret_blob'=>V::seal(str_repeat('s',32))]])]+S::timestamp();$request['id']=S::table('request')->insertGetId($request);
 W::enqueue($request,['status'=>'success']);W::enqueue($request,['status'=>'success']);
 check(S::table('webhook')->where('request_id',$rid)->count()===1,'Repeated terminal event created duplicate delivery');
 $event=S::table('webhook')->where('request_id',$rid)->find();check(strlen($event['event_id'])<=64,'Event exceeds persisted identifier size');
 // Vault corruption must retry through webhook columns, with no live network request.
 S::table('webhook')->where('id',$event['id'])->update(['payload_blob'=>'invalid','next_run_time'=>time()-10000]);R::tick(1);
 $held=S::table('webhook')->where('id',$event['id'])->find();check($held['status']==='pending'&&$held['last_error']==='通知恢复处理失败'&&(int)$held['next_run_time']>time()&&$held['lease_token']===''&&(int)$held['lease_expire']===0,'Delivery recovery did not persist safe retry or release lease');
 S::table('webhook')->where('id',$event['id'])->update(['status'=>'failed','lease_token'=>'obsolete','lease_expire'=>time()+300,'attempts'=>7]);
 W::replay($request['tenant_id'],$request['user_id'],$event['id']);$replay=S::table('webhook')->where('id',$event['id'])->find();check($replay['status']==='pending'&&(int)$replay['attempts']===0&&$replay['lease_token']===''&&(int)$replay['lease_expire']===0,'Replay retained obsolete worker lease');
 try{W::replay($request['tenant_id'],$request['user_id']+1,$event['id']);throw new RuntimeException('Cross-user callback replay allowed');}catch(app\common\service\open_platform\OpenException $e){check($e->httpStatus===404,'Wrong callback ownership rejection');}
 echo "PASS terminal notification deduplication, persisted delivery error retry, replay lease reset and ownership\n";
}finally{Db::rollback();}
