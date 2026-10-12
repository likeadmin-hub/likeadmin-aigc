<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenAssets as A;
use app\common\service\open_platform\OpenToolTasks as T;
use app\common\service\open_platform\OpenStore as S;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback fixtures only');
Db::startTrans();try{
 request()->tenantId=1;request()->userId=123456788;request()->source=app\common\enum\AdminTerminalEnum::USER;request()->userInfo=['tenant_id'=>1,'user_id'=>123456788,'terminal'=>4];
 $result=['tenant_id'=>request()->tenantId,'user_id'=>request()->userId,'image_uri'=>'uploads/result-'.bin2hex(random_bytes(8)).'.png','storage_scope'=>'tenant','storage_engine'=>'qcloud','storage_domain'=>'https://example.com','file_size'=>2048,'mime_type'=>'image/png'];
 $out=T::publicResult('aigc_image',['route'=>'GET tasks/{task_id}'],['id'=>12,'status'=>'success','results'=>[$result]]);$id=$out['results'][0]['file_id']??'';check(preg_match('/^of_[a-f0-9]{32}$/D',$id)===1,'Stored native result has no reusable material ID');
 $same=T::publicResult('aigc_image',['route'=>'GET tasks/{task_id}'],['id'=>12,'status'=>'success','results'=>[$result]]);check($same['results'][0]['file_id']===$id&&S::table('file')->where('file_id',$id)->count()===1,'Repeated task polling duplicated materials');
 $stored=S::owner('file',request()->tenantId,request()->userId)->where('file_id',$id)->find();check($stored['storage_scope']==='tenant'&&$stored['storage_engine']==='qcloud'&&$stored['storage_domain']==='https://example.com','Result storage identity changed');
 $asset=A::detail(request()->tenantId,request()->userId,$id);check($asset['size']===2048&&$asset['mime']==='image/png'&&$asset['url']==='https://example.com/'.$result['image_uri'],'Result download does not use captured storage domain');
 $denied=false;try{A::detail(request()->tenantId,request()->userId+1,$id);}catch(app\common\service\open_platform\OpenException $e){$denied=$e->httpStatus===404;}check($denied,'Reusable material leaked across users');
 check(A::registerResult(request()->tenantId,request()->userId,'aigc_image',array_replace($result,['stored'=>false]))===null,'Unstored output claimed a persisted material');
 $denied=false;try{A::registerResult(request()->tenantId,request()->userId,'aigc_image',array_replace($result,['user_id'=>request()->userId+1]));}catch(app\common\service\open_platform\OpenException){$denied=true;}check($denied,'Foreign result could be adopted');
 S::table('file')->where('file_id',$id)->update(['user_id'=>request()->userId+1]);$denied=false;try{A::registerResult(request()->tenantId,request()->userId,'aigc_image',$result);}catch(app\common\service\open_platform\OpenException $e){$denied=$e->errorType==='asset_identity_conflict';}check($denied,'Duplicate ID silently adopted conflicting ownership');
 echo "PASS stored tool outputs become stable owned materials without copies; storage metadata and cross-user isolation preserved\n";
}finally{Db::rollback();}
