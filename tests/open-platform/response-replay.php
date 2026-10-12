<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenGateway as G;
use app\common\service\open_platform\OpenVault as V;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback tests only');
Db::startTrans();try{
 request()->tenantId=1;request()->userId=1;request()->source=app\common\enum\AdminTerminalEnum::USER;request()->userInfo=['tenant_id'=>1,'user_id'=>1,'terminal'=>4];
 $id='req_wire_fixture_'.bin2hex(random_bytes(12));$row=['request_id'=>$id,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'market','app_code'=>'','method'=>'POST','path'=>'/api/v1/messages','status'=>'failed','billing_status'=>'none','active'=>0,'consumption_ids'=>'[]','snapshot'=>S::encode(['point_events'=>['fixture'=>['user'=>0]],'rules'=>[]]),'payload_hash'=>hash('sha256',$id),'response_blob'=>'','budget_user'=>0,'budget_tenant'=>0]+S::timestamp();$row['id']=S::table('request')->insertGetId($row);
 $replay=new ReflectionMethod(G::class,'replayResponse');
 $closed=$replay->invoke(null,$row,$row['path']);check($closed->getCode()===503&&S::json($closed->getContent())['error']['type']==='request_failed'&&$closed->getHeader('X-Request-Id')===$id,'Closed interrupted receipt presented as running');
 foreach([['active'=>1],['billing_status'=>'pending_confirmation'],['status'=>'running']] as $override){$pending=$replay->invoke(null,array_replace($row,$override),$row['path']);check($pending->getCode()===409&&S::json($pending->getContent())['error']['type']==='request_in_progress','Unresolved receipt classified as terminal failure');}
 $cancelled=$replay->invoke(null,array_replace($row,['status'=>'cancelled']),'/api/v1/tools/aigc_fitting/tasks');check($cancelled->getCode()===409&&S::json($cancelled->getContent())['error']['type']==='request_cancelled','Cancelled receipt presented as running');
 check(S::table('request')->where('id',$row['id'])->value('response_blob')===''&&S::table('request')->where('id',$row['id'])->value('status')==='failed','Empty receipt replay wrote execution state');
 $headers=['retry-after'=>'5','anthropic-ratelimit-requests-remaining'=>'0','content-type'=>'application/json'];$body='{"error":{"type":"rate_limit_error"}}';G::recordWire($id,['response_blob'=>V::seal($body),'response_code'=>429,'response_type'=>'application/json'],$headers);
 $saved=S::table('request')->where('request_id',$id)->find();check(isset(S::json($saved['snapshot'])['point_events']['fixture']),'Response metadata overwrote billing snapshot');
 $response=$replay->invoke(null,$saved,$saved['path']);check($response->getCode()===429&&$response->getContent()===$body&&$response->getHeader('retry-after')==='5','Protocol replay dropped status, body or Retry-After');
 $file='of_'.bin2hex(random_bytes(16));S::table('file')->insert(['tenant_id'=>1,'user_id'=>1,'file_id'=>$file,'uri'=>'uploads/fixture.png','name'=>'fixture','mime'=>'image/png','size'=>1,'storage_scope'=>'tenant','storage_engine'=>'qcloud','storage_domain'=>'https://example.com']+S::timestamp());
 $assetRow=array_replace($row,['request_id'=>$id.'_asset','kind'=>'asset','app_code'=>'assets','method'=>'GET','path'=>'/api/v1/assets/'.$file.'/download','status'=>'accepted','active'=>1,'snapshot'=>'{}']);unset($assetRow['id']);$assetRow['id']=S::table('request')->insertGetId($assetRow);
 $op=['kind'=>'asset','role'=>'download','params'=>['file_id'=>$file]];$response=(new ReflectionMethod(G::class,'execute'))->invoke(null,request(),$assetRow,$op,[],['user'=>0,'tenant'=>0,'snapshot'=>[]],false);$saved=S::table('request')->where('id',$assetRow['id'])->find();$again=$replay->invoke(null,$saved,$saved['path']);
 check($response->getCode()===302&&(int)$saved['response_code']===302&&$again->getCode()===302&&$again->getHeader('Location')===$response->getHeader('Location')&&$again->getHeader('X-Request-Id')===$assetRow['request_id'],'Owned download replay became a JSON response');
 echo "PASS replay retains protocol headers/status/body and owned asset redirects; durable billing snapshots preserved\n";
}finally{app\common\service\open_platform\OpenCallContext::leave();Db::rollback();}
