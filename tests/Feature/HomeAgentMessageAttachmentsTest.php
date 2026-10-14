<?php
// Local rollback-only fixtures; no providers, uploads or generation tasks.
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
use think\facade\Db;
use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentExecutionService as Agent};
use app\common\service\app\aigc_llm\AigcLlmService as Chat;
use app\common\model\app\aigc_llm\{AigcLlmSession,AigcLlmMessage};
use app\common\service\open_platform\OpenStore;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
Db::startTrans();try{
 $tenant=1999999901;$user=1999999901;$now=time();
 $session=AigcLlmSession::create(['tenant_id'=>$tenant,'user_id'=>$user,'title'=>'attachments fixture','model_code'=>'fixture','status'=>'idle','create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);
 $base=['tenant_id'=>$tenant,'user_id'=>$user,'session_id'=>$session['id'],'create_time'=>$now,'update_time'=>$now,'delete_time'=>0,'status'=>'done','token_usage_json'=>[],'finish_reason'=>''];
 $message=AigcLlmMessage::create($base+['role'=>'user','content'=>'same','seq'=>1,'parent_user_message_id'=>0]);
 $answer=AigcLlmMessage::create($base+['role'=>'assistant','content'=>'answer','seq'=>2,'parent_user_message_id'=>$message['id']]);
 $file='of_'.bin2hex(random_bytes(16));
 OpenStore::table('file')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'file_id'=>$file,'uri'=>'uploads/attachment-fixture.png','name'=>'fixture.png','mime'=>'image/png','size'=>1,'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']+OpenStore::timestamp());
 $snapshot=[['file_id'=>$file,'name'=>'old.png','url'=>'https://stale.invalid/old.png']];
 $baseRun=['tenant_id'=>$tenant,'user_id'=>$user,'session_id'=>$session['id'],'message_id'=>$answer['id'],'payload_hash'=>str_repeat('a',64),'content'=>'same','preferences'=>'{}','attachments'=>S::encode($snapshot),'status'=>'success','reply'=>'answer','create_time'=>$now,'update_time'=>$now];
 $run=S::table('run')->insertGetId($baseRun+['request_id'=>'attachment-'.bin2hex(random_bytes(8))]);
 $detail=Agent::detail($tenant,$user,(int)$run);
 check($detail['user_message_id']===(int)$message['id'],'Wrong user message association');
 check($detail['attachments'][0]['name']==='fixture.png' && $detail['attachments'][0]['url']!==$snapshot[0]['url'],'Attachment not resolved from owned file');
 $context=Agent::conversationAttachments(S::run($tenant,$user,(int)$run));
 check($context[(int)$message['id']][0]['file_id']===$file,'Planner lost owned historical attachment');
 check(Agent::conversationAttachments(array_replace(S::run($tenant,$user,(int)$run),['user_id'=>$user+1]))===[],'Planner leaked another user history');
 // A newer run list can omit this message; full chat history must still include its attachment.
 for($i=0;$i<31;$i++)S::table('run')->insert($baseRun+['request_id'=>'newer-'.bin2hex(random_bytes(8))]);
 $rows=Chat::messageLists($tenant,$user,(int)$session['id']);
 check($rows[0]['attachments'][0]['file_id']===$file,'Historical attachment lost');
 check(!isset($rows[1]['attachments']),'Attachment attached to assistant');
 foreach([[$tenant+1,$user],[$tenant,$user+1]] as [$t,$u]){
  check(Agent::displayAttachments($t,$u,$snapshot)[0]['url']==='','Unowned attachment URL leaked');
  try{Agent::detail($t,$u,(int)$run);throw new RuntimeException('Run ownership bypassed');}catch(InvalidArgumentException){}
 }
 OpenStore::table('file')->where('file_id',$file)->delete();
 check(Agent::detail($tenant,$user,(int)$run)['attachments'][0]['url']==='','Deleted attachment exposes stale URL');
 check(Agent::conversationAttachments(S::run($tenant,$user,(int)$run))===[],'Planner retained deleted image');
 echo "Home Agent message attachments passed: send response, history, current URLs, missing files and ownership; rolled back\n";
}finally{Db::rollback();}
