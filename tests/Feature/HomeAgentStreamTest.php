<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(\Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentStreamService as Stream};
use think\facade\Db;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
$r=request();$r->tenantId=1;$r->userId=1;$r->userInfo=['tenant_id'=>1,'user_id'=>1];
Db::startTrans();try{
 $base=S::owner('run',1,1)->order('id','desc')->find();check((bool)$base,'An owned run fixture is required');
 $id=(int)$base['id'];$prefs=S::json($base['preferences']);unset($prefs['_reply_stages'],$prefs['_sequence']);
 S::owner('run',1,1)->where('id',$id)->update(['reply'=>'你','status'=>'running','phase'=>'chat','preferences'=>S::encode($prefs)]);
 $events=[];
 Stream::follow(1,1,$id,function($event,$data)use(&$events,$id){
  $events[]=[$event,$data];
  if($event==='run.snapshot'&&$data['run']['reply']==='你')S::owner('run',1,1)->where('id',$id)->update(['reply'=>'你好','status'=>'success']);
 },static fn()=>true,1);
 check(array_column($events,0)===['run.snapshot','run.snapshot','done'],'Live updates or terminal event missing');
 check($events[1][1]['run']['reply']==='你好','Final reply lost');
 check(!isset($events[0][1]['run']['preferences'],$events[0][1]['run']['lease_token']),'Private run data leaked');
 $replay=[];Stream::follow(1,1,$id,function($e,$d)use(&$replay){$replay[]=[$e,$d];},static fn()=>true);
 check($replay[0][1]['run']['reply']==='你好'&&count($replay)===2,'Reconnect did not send authoritative snapshot');
 foreach([[2,1],[1,999999]] as [$tenant,$user]){
  $leaked=false;try{Stream::follow($tenant,$user,$id,function()use(&$leaked){$leaked=true;},static fn()=>true,0);throw new RuntimeException('Ownership was not checked');}catch(InvalidArgumentException){}
  check(!$leaked,'Foreign run emitted');
 }
 Stream::follow(1,1,$id,static function(){throw new RuntimeException('Disconnected stream emitted');},static fn()=>false);
 S::owner('run',1,1)->where('id',$id)->update(['status'=>'running']);
 $bounded=[];Stream::follow(1,1,$id,function($e)use(&$bounded){$bounded[]=$e;},static fn()=>true,0);
 check($bounded===['run.snapshot','reconnect'],'Connection must be bounded');
}finally{Db::rollback();}
echo "Home Agent stream passed: live/final snapshots, reconnect, ownership, disconnect and bounded connection; rolled back\n";
