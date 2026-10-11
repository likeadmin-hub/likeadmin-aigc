<?php
/** Verify existing authorized synthetic outputs; never submit or re-price a task. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenRecovery as Recovery;
use app\common\service\open_platform\OpenToolRegistry as Registry;
use app\common\service\open_platform\OpenToolAdapter as Adapter;
use app\common\service\open_platform\OpenToolTasks as Tasks;
use app\common\service\open_platform\OpenContracts as Contracts;
use app\common\service\open_platform\OpenVerification as Verification;
use app\common\service\open_platform\OpenSafeHttp as Http;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local evidence only');
check(Db::name('user')->where(['id'=>1,'tenant_id'=>1,'account'=>'xhadmin001'])->find(),'Authorized identity missing');
$dir=dirname(__DIR__,2).'/runtime/open-platform-acceptance';$lock=fopen($dir.'/paid.lock','c');check($lock&&flock($lock,LOCK_EX|LOCK_NB),'Acceptance lock busy');
$balances=fn()=>[(string)Db::name('user')->where(['id'=>1,'tenant_id'=>1])->value('user_money'),(string)Db::name('tenant')->where('id',1)->value('point_balance')];$before=$balances();
$output=$dir.'/media-evidence';if(!is_dir($output))mkdir($output,0700,true);$report=['verified_at'=>date(DATE_ATOM),'tenant_id'=>1,'user_id'=>1,'new_submissions'=>0,'items'=>[],'result'=>'pending'];
try{
 foreach(['aigc_image'=>'local_text_1bd7b6a0ba3c','aigc_music'=>'local_text_8e766ab3ca3d'] as $app=>$run){
  $source=S::json(file_get_contents($dir.'/'.$run.'.json'));check($source['tenant_id']===1&&$source['user_id']===1,'Unexpected report owner');$case=$source['cases'][$app];$row=S::owner('request',1,1)->where('request_id',$case['request_id'])->find();check($row&&$row['status']==='success'&&(float)$row['actual_user']===(float)$case['quote']&&(float)$row['budget_user']===0.,'Known task charge/state changed');
  if((int)$row['active']===1)Recovery::refresh($row);$row=S::owner('request',1,1)->where('id',$row['id'])->find();check($row['billing_status']==='settled'&&(int)$row['active']===0,'Recovered billing unresolved');
  Verification::assertLedger($row);$contract=Contracts::detail($case['contract_id']);$contract['config']['verification']=['request_id'=>$row['request_id']];Verification::assertReceipt($contract);
  request()->tenantId=1;request()->userId=1;request()->userInfo=['tenant_id'=>1,'user_id'=>1,'terminal'=>4];request()->source=app\common\enum\AdminTerminalEnum::USER;
  $op=Registry::get($app)['operations']['GET tasks/{task_id}']+['app_code'=>$app,'route'=>'GET tasks/{task_id}','params'=>['task_id'=>Tasks::decode($app,$case['task_id'])],'history'=>true,'internal'=>true];
  $native=Adapter::call(request(),$op,[]);$data=Tasks::publicResult($app,$op,$native);check($data['status']==='success'&&count($data['results']??[])>0,'Completed results unavailable');$same=Tasks::publicResult($app,$op,$native);check(array_column($data['results'],'file_id')===array_column($same['results'],'file_id'),'Material IDs changed on repeat query');
  foreach($data['results'] as $result){$type=$app==='aigc_image'?'image':'audio';$url=$result[$type.'_url']??'';check($url!==''&&!empty($result['file_id']),'Result lacks owned reusable stored media');$wire=Http::get($url,33554432,60);check($wire['status']===200&&strlen($wire['body'])>0,'Stored media download failed');$name=$app.'-'.$result['id'].($type==='image'?'.image':'.audio');$path=$output.'/'.$name;file_put_contents($path,$wire['body']);
   $item=['app_code'=>$app,'source'=>$run,'request_id'=>$row['request_id'],'task_id'=>$case['task_id'],'result_id'=>$result['id'],'file_id'=>$result['file_id'],'storage_scope'=>$result['storage_scope'],'storage_engine'=>$result['storage_engine'],'charged'=>(float)$row['actual_user'],'billing_status'=>$row['billing_status'],'publication_proof'=>'exact_example_and_independent_ledgers','http_status'=>$wire['status'],'bytes'=>strlen($wire['body']),'sha256'=>hash('sha256',$wire['body']),'file'=>'media-evidence/'.$name];
   if($type==='image'){$size=getimagesize($path);check($size!==false&&$size[0]>0&&$size[1]>0,'Image cannot be decoded');$item+=['width'=>$size[0],'height'=>$size[1],'mime'=>$size['mime']];}
   else $item['content_validation']='pending_host_ffprobe';$report['items'][]=$item;
  }
 }
 check($balances()===$before,'Evidence query unexpectedly changed wallets');$report['wallets_unchanged']=true;$report['result']='pending_host_audio_probe';
}catch(Throwable $e){$report['result']='failed';$report['failure']=$e->getMessage();}
file_put_contents($dir.'/multimodal-reconciliation.json',S::encode($report));echo S::encode($report),"\n";exit($report['result']==='failed'?1:0);
