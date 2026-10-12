<?php
/** No transport: actual acceptance gate and rollback-only frozen character ledger. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\{OpenGateway as G,OpenStore as S,OpenCharacterInputPricing as P,OpenMarketResponse as R,OpenMarketBilling as B,OpenCallContext as C,OpenVerification};
function audioCheck($ok,$why){if(!$ok)throw new RuntimeException($why);}
$accept=new ReflectionMethod(G::class,'marketAccepted');
// Published legacy schemas need not have a URI format or required result fields.
$op=['kind'=>'market','executor'=>'json','role'=>'submit','billable'=>true,'native_pricing'=>P::ADAPTER,'response_adapter'=>R::ADAPTER,'responses'=>['200'=>['content'=>['application/json'=>['schema'=>['type'=>'object']]]]]];
$body=fn($result)=>S::encode(['code'=>1,'msg'=>'success','data'=>['result'=>$result,'usage'=>['points_cost'=>100,'actual_points'=>100]]]);
$wire=fn($result)=>['status'=>200,'headers'=>['content-type'=>'application/json'],'body'=>$body($result)];
$good=['audio_url'=>'https://media.example.com/generated.mp3?signature=owned','format'=>'mp3'];
$invalid=[];foreach(['','relative.mp3','/audio.mp3','//media.example.com/audio.mp3','not-a-url','javascript:alert(1)','ftp://media.example.com/a.mp3','https:///audio.mp3','https://user:password@media.example.com/a.mp3',"https://media.example.com/a.mp3\n",' https://media.example.com/a.mp3'] as $url)$invalid[]=array_replace($good,['audio_url'=>$url]);
foreach(['pending','processing','failed','hidden','completed','SUCCESS','',null,1,[]] as $status)$invalid[]=$good+['transfer_status'=>$status];
$invalid[]=['format'=>'mp3'];$invalid[]=['audio_url'=>['https://media.example.com/a.mp3'],'format'=>'mp3'];
foreach($invalid as $result)audioCheck(!$accept->invoke(null,$op,$wire($result)),'Untrusted character audio accepted: '.S::encode($result));
foreach([$good,$good+['transfer_status'=>'success'],array_replace($good,['audio_url'=>'http://media.example.com/a.mp3'])] as $result)audioCheck($accept->invoke(null,$op,$wire($result)),'Real synchronous default-voice success refused');
audioCheck($accept->invoke(null,$op,['status'=>200,'headers'=>['content-type'=>'application/json'],'body'=>'{"code":1,"data":{"task_id":"known-async"}}']),'Known asynchronous acceptance incorrectly requires delivered audio');
$other=$op;unset($other['native_pricing']);audioCheck($accept->invoke(null,$other,$wire(['text'=>'Other original application'])),'Character gate affected other adapters');
$query=$op;$query['role']='query';$query['billable']=false;audioCheck($accept->invoke(null,$query,$wire(['text'=>'Original query result'])),'Character submit gate affected query');
$db=config('database.connections.mysql');audioCheck($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback only');
$wallets=fn()=>[(float)Db::name('user')->where('id',1)->value('user_money'),(float)Db::name('tenant')->where('id',1)->value('point_balance')];$before=$wallets();
Db::startTrans();try{
 request()->tenantId=1;request()->userId=1;
 $snapshot=['billing_owner'=>'gateway','contract'=>$op,'product_id'=>61,'character_input'=>['quantity'=>2],'rules'=>[['sku_id'=>504,'basis'=>'character_input','units'=>2,'max_units'=>2,'unit_size'=>1000,'prices'=>['user'=>50,'tenant'=>50]]]];
 $rid='req_audio_'.bin2hex(random_bytes(12));$row=['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'market','method'=>'POST','path'=>'/api/v1/apps/voice_tts/tts','status'=>'accepted','billing_status'=>'pending','active'=>1,'consumption_ids'=>'[]','snapshot'=>S::encode($snapshot),'payload_hash'=>hash('sha256',$rid),'response_blob'=>'','budget_user'=>.1,'budget_tenant'=>.1]+S::timestamp();$row['id']=S::table('request')->insertGetId($row);C::enter($rid);B::reserve($row,['user'=>.1,'tenant'=>.1,'snapshot'=>$snapshot]);$reserved=$wallets();
 // Execute uses this defer branch after a dispatched untrusted synchronous receipt.
 G::deferMarketAcceptance($row);$pending=S::table('request')->where('id',$row['id'])->find();audioCheck($pending['status']==='pending_confirmation'&&$pending['billing_status']==='pending_confirmation'&&(int)$pending['active']===1&&$pending['upstream_task_id']===''&&$wallets()===$reserved,'Unknown audio was refunded, settled or resubmitted');
 $c=Db::name('ai_consumption_log')->where('consume_no',$rid)->find();audioCheck($c['billing_status']!=='settled'&&(float)$c['reserved_user_price']===.1,'Unknown receipt lost reservation');
 B::settle($row,json_decode($body($good),true));$done=S::table('request')->where('id',$row['id'])->find();audioCheck($done['billing_status']==='settled'&&(float)$done['actual_user']===.1,'Frozen two-character charge changed');OpenVerification::assertLedger($done);$logs=Db::name('user_account_log')->where('source_sn',$rid)->count();B::settle($row,json_decode($body($good),true));audioCheck(Db::name('user_account_log')->where('source_sn',$rid)->count()===$logs&&$wallets()===$reserved,'Repeated trusted receipt charged again');
 $projected=json_decode(R::project($body($good),R::amounts($done)));audioCheck((float)$projected->data->usage->actual_points===.1&&(float)$projected->data->usage->points_cost===.1,'Supplier money replaced local character price');
 echo "PASS legacy character submit audio URL/credential/transfer gates, async and other-adapter isolation, unknown reservation retained, frozen two-character .10 and independent idempotent ledger; no transport\n";
}finally{C::leave();Db::rollback();}
audioCheck($wallets()===$before,'Rollback changed wallets');
