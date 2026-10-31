<?php
/** No supplier I/O. Disposable table prefix, private public/cache roots and owned worker processes only. */
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\model\ai\{AiAppTask,AiConsumptionLog,AiTaskResultAsset};
use app\common\service\ai\{AiTaskResultAssetService as Assets,AiTaskJobService as Jobs};
use app\common\command\AiTaskWorker;
function check52($ok,$why){if(!$ok)throw new RuntimeException($why);}
function wait52(callable $c,string $why,int $seconds=20): void{$until=microtime(true)+$seconds;do{if($c())return;usleep(20000);}while(microtime(true)<$until);throw new RuntimeException($why);}
$original=config('database');$mysql=$original['connections']['mysql'];
check52(PHP_SAPI==='cli'&&in_array($mysql['hostname'],['127.0.0.1','localhost'],true)&&in_array($mysql['database'],['x_cn','z_cn'],true),'Explicit local fixture only');
function setup52(array $original,string $prefix,string $dir): void{
    check52(preg_match('/^b52_[a-f0-9]{12}_$/D',$prefix)&&is_file($dir.'/owner')&&trim(file_get_contents($dir.'/owner'))===$prefix,'Not an owned fixture');
    $cfg=$original;$cfg['default']='batch52';$cfg['connections']['batch52']=array_replace($original['connections']['mysql'],['prefix'=>$prefix]);$cfg['connections']['mysql']=$cfg['connections']['batch52'];
    app()->config->set($cfg,'database');$config=new think\Config();$config->set($cfg,'database');Db::setConfig($config);
    app()->config->set(['default'=>'b52','stores'=>['b52'=>['type'=>'File','path'=>$dir.'/cache/','prefix'=>$prefix]]],'cache');
    $root=new ReflectionProperty(think\App::class,'rootPath');$root->setAccessible(true);$root->setValue(app(),$dir.'/');request()->setHost('localhost');
}
if(($argv[1]??'')==='--worker'){
    $prefix=$argv[2]??'';$dir=$argv[3]??'';setup52($original,$prefix,$dir);$role=$argv[4]??'';
    check52(in_array($role,['kill','restart','repeat'],true),'Invalid fixture role');
    if($role==='kill')Db::event('after_insert',function($query)use($dir){if(str_ends_with($query->getTable(),'tenant_file')){file_put_contents($dir.'/uploaded-kill',(string)getmypid());while(!is_file($dir.'/release-kill'))usleep(20000);}});
    $lateFailure=$role==='restart';Db::event('after_update',function($query)use(&$lateFailure,$dir){if($lateFailure&&str_ends_with($query->getTable(),'ai_consumption_log')&&array_key_exists('response_summary',$query->getOptions('data')?:[])){$lateFailure=false;file_put_contents($dir.'/late-projection-failure','1');throw new RuntimeException('Synthetic projection failure after stored asset');}});
    $out=new think\console\Output('console');$command=new AiTaskWorker();exit($command->run(new think\console\Input(['--app=aigc_image','--lease=10','--sleep=1','--batch=1']),$out));
}
$probe=($argv[1]??'')==='--probe';$prefix='b52_'.bin2hex(random_bytes(6)).'_';$dir='/tmp/'.$prefix.'fixture';mkdir($dir,0700);file_put_contents($dir.'/owner',$prefix);mkdir($dir.'/public',0700);mkdir($dir.'/tmp',0700);
$pdo=new PDO('mysql:host='.$mysql['hostname'].';port='.($mysql['hostport']?:3306).';dbname='.$mysql['database'].';charset=utf8mb4',$mysql['username'],$mysql['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$names=['ai_app_task','ai_consumption_log','ai_consumption_event','ai_task_job','ai_task_result_asset','tenant_file','tenant','user','config','tenant_config','app','tenant_app_config','aigc_image_task','aigc_image_result','aigc_image_billing','aigc_image_quota','aigc_short_drama_config','power_market_product','power_market_sku','tenant_power_market_sku_price','power_market_price_rule','aigc_image_channel','aigc_image_channel_spec','aigc_video_channel','aigc_video_channel_spec','aigc_llm_config'];
$tables=[];$children=[];$report=['scope'=>'Disposable SQL prefix and private local file/cache roots. Production result worker command, no HTTP authentication or supplier submission/query.','provider_calls'=>0,'actual_wallet_mutations'=>0];
$spawn=function($role)use(&$children,$prefix,$dir){$pipes=[];$p=proc_open([PHP_BINARY,'-d','disable_functions=',__FILE__,'--worker',$prefix,$dir,$role],[0=>['pipe','r'],1=>['file',$dir.'/'.$role.'.log','a'],2=>['file',$dir.'/'.$role.'.log','a']],$pipes,null,array_merge(getenv(),['TMPDIR'=>$dir.'/tmp']));check52(is_resource($p),'Cannot start owned worker');fclose($pipes[0]);$children[$role]=$p;return $p;};
$stop=function($role,$signal=SIGTERM)use(&$children){if(!isset($children[$role]))return;$p=$children[$role];$s=proc_get_status($p);if($s['running'])posix_kill($s['pid'],$signal);wait52(fn()=>!proc_get_status($p)['running'],'Owned worker did not stop',5);proc_close($p);unset($children[$role]);};
try{
    foreach($names as$name){$source=$mysql['prefix'].$name;$exists=$pdo->prepare('SHOW TABLES LIKE ?');$exists->execute([$source]);if(!$exists->fetchColumn())continue;$table=$prefix.$name;$pdo->exec('CREATE TABLE `'.$table.'` LIKE `'.$source.'`');$tables[]=$table;}
    setup52($original,$prefix,$dir);
    $tenant=199999952;$user=199999952;Db::name('tenant')->insert(['id'=>$tenant,'sn'=>'b52','name'=>'Batch52 fixture','point_balance'=>100,'disable'=>0,'create_time'=>time(),'update_time'=>time()]);Db::name('user')->insert(['id'=>$user,'tenant_id'=>$tenant,'sn'=>1999952,'account'=>'batch52','nickname'=>'fixture','user_money'=>100,'create_time'=>time(),'update_time'=>time()]);
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=');$uri='data:image/png;base64,'.base64_encode($png);
    $task=AiAppTask::create(['task_no'=>$prefix.'task','tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>'aigc_image','action_code'=>'generate','business_table'=>'aigc_image_task','business_id'=>0,'status'=>'running','progress'=>90,'idempotency_key'=>$prefix.'task','create_time'=>time(),'update_time'=>time(),'finish_time'=>0]);
    $c=AiConsumptionLog::create(['consume_no'=>$prefix.'consumption','app_task_id'=>$task['id'],'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>'aigc_image','action_code'=>'generate','resource_type'=>'model','provider'=>$probe?'offline-fixture':'power_market','protocol'=>'image_generate','quantity'=>1,'price_snapshot'=>[],'usage_snapshot'=>[],'request_summary'=>[],'response_summary'=>['images'=>[['image_uri'=>$uri,'width'=>1,'height'=>1]]],'run_status'=>'success','billing_status'=>'settled','reserved_tenant_cost'=>0,'reserved_user_price'=>0,'actual_tenant_cost'=>0,'actual_user_price'=>0,'create_time'=>time(),'update_time'=>time(),'finish_time'=>time()]);
    $business=Db::name('aigc_image_task')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'app_task_id'=>$task['id'],'provider'=>'power_market','channel'=>'offline-fixture','quality'=>'1K','ratio'=>'1:1','quantity'=>1,'prompt'=>'Synthetic result receipt; no generation','status'=>'running','delete_time'=>0,'create_time'=>time(),'update_time'=>time()]);$task->save(['business_id'=>$business]);
    $assets=Assets::recordConsumptionAssets((int)$c['id'],true);check52(count($assets)===1,'Missing owned result asset');$id=(int)$assets[0]['id'];$job=Db::name('ai_task_job')->where('result_asset_id',$id)->find();$report['transfer_job_app_linked']=(int)$job['app_task_id']===(int)$task['id'];
    if($probe){
        try{Assets::transfer($id);$report['transfer']='unexpected_success';}catch(Throwable $e){$report['transfer_error_class']=get_class($e);$report['transfer_error']=str_contains($e->getMessage(),'Argument #4')?'storage_config_argument_type_mismatch':mb_substr($e->getMessage(),0,200);}
        echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";}else{
    check52($report['transfer_job_app_linked'],'Transfer job is invisible to an app-scoped worker');
    Db::name('ai_task_job')->where('id',$job['id'])->update(['app_task_id'=>0,'consumption_id'=>0]);Assets::recordConsumptionAssets((int)$c['id'],true);$repaired=Db::name('ai_task_job')->where('id',$job['id'])->find();check52((int)$repaired['app_task_id']===(int)$task['id']&&(int)$repaired['consumption_id']===(int)$c['id'],'Legacy pending transfer linkage not restored');$report['legacy_pending_job_relinked']=true;
    $spawn('kill');wait52(fn()=>is_file($dir.'/uploaded-kill'),'Worker did not reach upload-before-receipt barrier');$held=Db::name('ai_task_job')->where('id',$job['id'])->find();check52($held['status']==='running'&&(int)$held['lease_expire_time']>time(),'No durable lease');$pendingAsset=Db::name('ai_task_result_asset')->where('id',$id)->find();$cp=json_decode($pendingAsset['storage_meta'],true)['transfer_checkpoint'];$uploadedPath=$dir.'/public/'.$cp['uri'];check52(is_file($uploadedPath)&&hash_file('sha256',$uploadedPath)===hash('sha256',$png),'No valid bytes before receipt commit');$uploadedMtime=filemtime($uploadedPath);check52((int)Db::name('tenant_file')->count()===0,'Receipt was already committed');Assets::recordConsumptionAssets((int)$c['id'],true);check52(Db::name('ai_task_job')->where('id',$job['id'])->value('lease_token')===$held['lease_token'],'Repeated enqueue stole active transfer lease');$stop('kill',SIGKILL);check52(Db::name('ai_task_result_asset')->where('id',$id)->value('transfer_status')==='external','Killed worker already committed the asset');
    $spawn('restart');usleep(200000);check52(Db::name('ai_task_job')->where('id',$job['id'])->value('lease_token')===$held['lease_token'],'Unexpired lease stolen');
    wait52(fn()=>is_file($dir.'/late-projection-failure'),'Late projection fault was not exercised',30);wait52(fn()=>Db::name('ai_task_job')->where('id',$job['id'])->value('status')==='retrying','Transfer did not retain retry scheduling',5);check52(Db::name('ai_task_result_asset')->where('id',$id)->value('transfer_status')==='stored','Late projection error regressed an already stored asset');
    wait52(fn()=>Db::name('aigc_image_task')->where('id',$business)->value('status')==='success','Result/business recovery incomplete; logs:'.$dir,30);
    $stored=Db::name('ai_task_result_asset')->where('id',$id)->find();$result=Db::name('aigc_image_result')->where('task_id',$business)->find();
    check52($stored['transfer_status']==='stored'&&str_starts_with($stored['local_uri'],'uploads/'),'No managed transfer receipt');check52($result&&$result['image_uri']===$stored['local_uri'],'Business result still uses supplier URI');
    foreach(['storage_scope','storage_engine','storage_domain']as$field)check52($result[$field]===$stored[$field],'Business storage receipt changed');
    $path=$dir.'/public/'.$stored['local_uri'];check52(is_file($path)&&hash_file('sha256',$path)===hash('sha256',$png),'Stored bytes changed');check52((int)$stored['tenant_id']===$tenant&&(int)$stored['user_id']===$user,'Storage owner changed');
    clearstatcache(true,$path);check52(filemtime($path)===$uploadedMtime,'Recovered worker rewrote complete local bytes');check52((int)Db::name('ai_task_result_asset')->count()===1,'Duplicated result asset');$stop('restart');$snapshot=Db::name('ai_task_job')->order('id')->select()->toArray();$count=(int)Db::name('tenant_file')->count();$spawn('repeat');usleep(1200000);$stop('repeat');check52($snapshot===Db::name('ai_task_job')->order('id')->select()->toArray()&&(int)Db::name('tenant_file')->count()===$count,'Repeated worker rewrote terminal work');
    check52((float)Db::name('tenant')->where('id',$tenant)->value('point_balance')===100.&&(float)Db::name('user')->where('id',$user)->value('user_money')===100.,'Fixture wallet changed');
    copy($path,'/tmp/market-batch52-worker-result.png');$report+=['actual_worker_command'=>true,'killed_after_upload_before_receipt_commit'=>true,'complete_local_bytes_not_rewritten'=>true,'late_projection_failure_retained_stored_asset'=>true,'natural_lease_expiry_recovered'=>true,'repeat_restart_idempotent'=>true,'managed_uri_matches_business_result'=>true,'file_rows'=>$count,'stored_bytes_sha256'=>hash_file('sha256',$path),'storage_scope'=>$stored['storage_scope'],'storage_engine'=>$stored['storage_engine'],'result_dimensions'=>[(int)$result['width'],(int)$result['height']],'claimed_attempts'=>(int)Db::name('ai_task_job')->where('id',$job['id'])->value('attempts')];
    echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
    }
}catch(Throwable $error){$failure=$error;fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n".$error->getTraceAsString()."\n");foreach(glob($dir.'/*.log')?:[]as$log)fwrite(STDERR,basename($log).":\n".substr(file_get_contents($log),-4000)."\n");}finally{
    foreach(array_keys($children)as$role)$stop($role,SIGKILL);
    foreach(array_reverse($tables)as$table)$pdo->exec('DROP TABLE `'.$table.'`');
    if(is_dir($dir)&&is_file($dir.'/owner')&&trim(file_get_contents($dir.'/owner'))===$prefix){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as$f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
}

if(isset($failure))exit(1);
