<?php
/** Inspect an existing authorized synthetic image result; never submit or re-price. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenToolRegistry as R;
use app\common\service\open_platform\OpenToolAdapter as A;
use app\common\service\open_platform\OpenToolTasks as T;
use app\common\service\open_platform\OpenVerification as V;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local result evidence only');
$run=(string)($argv[1]??'');check(preg_match('/^local_text_[a-f0-9]{12}$/D',$run),'Explicit known acceptance run required');
$dir=dirname(__DIR__,2).'/runtime/open-platform-acceptance';$lock=fopen($dir.'/paid.lock','c');check($lock&&flock($lock,LOCK_EX|LOCK_NB),'Acceptance lock busy');
$source=S::json(file_get_contents($dir.'/'.$run.'.json'));check(($source['result']??'')==='passed'&&$source['tenant_id']===1&&$source['user_id']===1&&count($source['cases'])===1,'Completed single synthetic case required');
check(Db::name('user')->where(['tenant_id'=>1,'id'=>1,'account'=>'xhadmin001'])->find(),'Authorized local owner missing');
$wallets=static fn()=>[Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money'),Db::name('tenant')->where('id',1)->value('point_balance')];$before=$wallets();
request()->tenantId=1;request()->userId=1;request()->source=app\common\enum\AdminTerminalEnum::USER;request()->userInfo=['tenant_id'=>1,'user_id'=>1,'terminal'=>4];
$app=array_key_first($source['cases']);$case=$source['cases'][$app];$row=S::owner('request',1,1)->where('request_id',$case['request_id'])->find();check($row&&$row['status']==='success'&&$row['billing_status']==='settled'&&(int)$row['active']===0&&(float)$row['actual_user']===(float)$case['quote'],'Original task or charge changed');
V::assertLedger($row);$contract=app\common\service\open_platform\OpenContracts::detail($case['contract_id']);$contract['config']['verification']=['request_id'=>$row['request_id']];V::assertReceipt($contract);
$op=R::get($app)['operations']['GET tasks/{task_id}']+['app_code'=>$app,'route'=>'GET tasks/{task_id}','params'=>['task_id'=>T::decode($app,$case['task_id'])],'history'=>true,'internal'=>true];
$native=A::call(request(),$op,[]);$result=T::publicResult($app,$op,$native);check($result['status']==='success'&&count($result['results']??[])>0,'Stored image results unavailable');
$repeat=T::publicResult($app,$op,$native);check(array_column($result['results'],'file_id')===array_column($repeat['results'],'file_id'),'Result file IDs changed on repeat');
$output=$dir.'/media-evidence';if(!is_dir($output))mkdir($output,0700,true);$report=['source'=>$run,'verified_at'=>date(DATE_ATOM),'new_submissions'=>0,'items'=>[]];
foreach($result['results'] as $image){
 check(!empty($image['image_url'])&&!empty($image['file_id']),'Stored image URL or reusable ID missing');
 $asset=app\common\service\open_platform\OpenAssets::detail(1,1,$image['file_id']);check($asset['url']===$image['image_url'],'Owned material URL disagrees with result');
 $wire=app\common\service\open_platform\OpenSafeHttp::get($asset['url'],33554432,60);$size=@getimagesizefromstring($wire['body']);check($size&&$size[0]>0&&$size[1]>0,'Result is not a decodable image');
 $file=$app.'-'.(int)$image['id'].'.image';file_put_contents($output.'/'.$file,$wire['body']);
 $report['items'][]=['app_code'=>$app,'request_id'=>$row['request_id'],'task_id'=>$case['task_id'],'file_id'=>$image['file_id'],'http_status'=>$wire['status'],'width'=>$size[0],'height'=>$size[1],'mime'=>$size['mime'],'bytes'=>strlen($wire['body']),'sha256'=>hash('sha256',$wire['body']),'charged'=>(float)$row['actual_user'],'billing_status'=>$row['billing_status'],'storage_scope'=>$image['storage_scope'],'storage_engine'=>$image['storage_engine'],'file'=>'media-evidence/'.$file,'publication_proof'=>'exact_example_and_independent_ledgers'];
}
check($wallets()===$before,'Read-only image verification changed wallets');$report['wallets_unchanged']=true;$report['result']='passed';file_put_contents($dir.'/'.$run.'-media.json',S::encode($report));echo S::encode($report),"\nPASS existing stored image content, owned reusable IDs, unchanged wallets and independent point ledgers\n";
