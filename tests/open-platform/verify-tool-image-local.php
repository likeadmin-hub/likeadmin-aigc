<?php
/** Verify only completed, owned synthetic image outputs; no submission or price changes. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenToolRegistry as Registry;
use app\common\service\open_platform\OpenToolAdapter as Adapter;
use app\common\service\open_platform\OpenToolTasks as Tasks;
use app\common\service\open_platform\OpenContracts as Contracts;
use app\common\service\open_platform\OpenVerification as Verification;
use app\common\service\open_platform\OpenSafeHttp as Http;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local evidence only');
check(Db::name('user')->where(['id'=>1,'tenant_id'=>1,'account'=>'xhadmin001'])->find(),'Authorized local identity missing');
$app=(string)($argv[1]??'');$run=(string)($argv[2]??'');
check(in_array($app,array_merge(['aigc_image','aigc_hairstyle','aigc_fitting'],app\common\service\open_platform\OpenImagePricing::COMPOSITES),true)&&preg_match('/^local_text_[a-f0-9]{12}$/D',$run),'Only explicitly accepted synthetic image cases');
$dir=dirname(__DIR__,2).'/runtime/open-platform-acceptance';$source=S::json(file_get_contents($dir.'/'.$run.'.json'));
check(($source['result']??'')==='passed'&&$source['tenant_id']===1&&$source['user_id']===1&&isset($source['cases'][$app]),'No completed acceptance report');
$lock=fopen($dir.'/paid.lock','c');check($lock&&flock($lock,LOCK_EX|LOCK_NB),'Another paid test is active');
$wallets=fn()=>[(string)Db::name('user')->where(['id'=>1,'tenant_id'=>1])->value('user_money'),(string)Db::name('tenant')->where('id',1)->value('point_balance')];$before=$wallets();
$case=$source['cases'][$app];$row=S::owner('request',1,1)->where('request_id',$case['request_id'])->find();
check($row&&$row['status']==='success'&&$row['billing_status']==='settled'&&(int)$row['active']===0&&(float)$row['actual_user']===(float)$case['charged']&&(float)$row['budget_user']===0.,'Receipt or charge changed');
Verification::assertLedger($row);$contract=Contracts::detail($case['contract_id']);$contract['config']['verification']=['request_id'=>$row['request_id']];Verification::assertReceipt($contract);
request()->tenantId=1;request()->userId=1;request()->userInfo=['tenant_id'=>1,'user_id'=>1,'terminal'=>4];request()->source=app\common\enum\AdminTerminalEnum::USER;
$op=Registry::get($app)['operations']['GET tasks/{task_id}']+['app_code'=>$app,'route'=>'GET tasks/{task_id}','params'=>['task_id'=>Tasks::decode($app,$case['task_id'])],'history'=>true,'internal'=>true];
$native=Adapter::call(request(),$op,[]);$data=Tasks::publicResult($app,$op,$native);$same=Tasks::publicResult($app,$op,$native);
check($data['status']==='success'&&count($data['results']??[])===$case['result_count']&&count($data['results'])>0,'Completed result missing');
check(array_column($data['results'],'file_id')===array_column($same['results'],'file_id'),'Owned material IDs changed on query');
$output=$dir.'/media-evidence';if(!is_dir($output))mkdir($output,0700,true);
$report=['verified_at'=>date(DATE_ATOM),'source'=>$run,'tenant_id'=>1,'user_id'=>1,'new_submissions'=>0,'items'=>[]];
foreach($data['results'] as $result){
    check(!empty($result['image_url'])&&!empty($result['file_id'])&&!empty($result['storage_scope'])&&!empty($result['storage_engine']),'Result lacks owned stored material');
    $owned=S::owner('file',1,1)->where('file_id',$result['file_id'])->find();check($owned,'Material owner mismatch');
    $asset=app\common\service\open_platform\OpenAssets::detail(1,1,$result['file_id']);check($asset['url']===$result['image_url'],'Owned material URL disagrees with result');
    $wire=Http::get($asset['url'],33554432,60);check($wire['status']===200&&strlen($wire['body'])>0,'Stored output download failed');
    $name=$app.'-'.(int)$result['id'].'.image';$path=$output.'/'.$name;file_put_contents($path,$wire['body']);$size=getimagesize($path);
    check($size!==false&&$size[0]>0&&$size[1]>0&&str_starts_with($size['mime'],'image/'),'Output cannot be decoded as image');
    $report['items'][]=['app_code'=>$app,'request_id'=>$row['request_id'],'task_id'=>$case['task_id'],'result_id'=>$result['id'],'file_id'=>$result['file_id'],'storage_scope'=>$result['storage_scope'],'storage_engine'=>$result['storage_engine'],'charged'=>(float)$row['actual_user'],'billing_status'=>$row['billing_status'],'publication_proof'=>'exact_example_and_independent_ledgers','http_status'=>$wire['status'],'width'=>$size[0],'height'=>$size[1],'mime'=>$size['mime'],'bytes'=>strlen($wire['body']),'sha256'=>hash('sha256',$wire['body']),'file'=>'media-evidence/'.$name];
}
check($wallets()===$before,'Result verification changed wallets');$report['wallets_unchanged']=true;$report['result']='content_and_ledger_passed';
file_put_contents($dir.'/'.$app.'-content.json',S::encode($report));echo S::encode($report),"\nPASS completed synthetic image content, owned reusable file IDs and independent ledgers, no new charges\n";
