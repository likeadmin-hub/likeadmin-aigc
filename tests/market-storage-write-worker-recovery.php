<?php
/** No supplier I/O. Disposable table prefix, private public/cache roots and owned worker processes only. */
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\model\ai\{AiAppTask,AiConsumptionLog,AiTaskResultAsset};
use app\common\service\ai\{AiTaskResultAssetService as Assets,AiTaskJobService as Jobs};
use app\common\command\AiTaskWorker;
function check55($ok,$why){if(!$ok)throw new RuntimeException($why);}
function wait55(callable $c,string $why,int $seconds=20): void{$until=microtime(true)+$seconds;do{if($c())return;usleep(20000);}while(microtime(true)<$until);throw new RuntimeException($why);}
$original=config('database');$mysql=$original['connections']['mysql'];
check55(PHP_SAPI==='cli'&&in_array($mysql['hostname'],['127.0.0.1','localhost'],true)&&in_array($mysql['database'],['x_cn','z_cn'],true),'Explicit local fixture only');
function setup55(array $original,string $prefix,string $dir): void{
    check55(preg_match('/^b55_[a-f0-9]{12}_$/D',$prefix)&&is_file($dir.'/owner')&&trim(file_get_contents($dir.'/owner'))===$prefix,'Not an owned fixture');
    $cfg=$original;$cfg['default']='batch55';$cfg['connections']['batch55']=array_replace($original['connections']['mysql'],['prefix'=>$prefix]);$cfg['connections']['mysql']=$cfg['connections']['batch55'];
    app()->config->set($cfg,'database');$config=new think\Config();$config->set($cfg,'database');Db::setConfig($config);
    app()->config->set(['default'=>'b55','stores'=>['b55'=>['type'=>'File','path'=>$dir.'/cache/','prefix'=>$prefix]]],'cache');
    $root=new ReflectionProperty(think\App::class,'rootPath');$root->setAccessible(true);$root->setValue(app(),$dir.'/');request()->setHost('localhost');
}

require __DIR__.'/Support/MarketStorageBarrier.php';
if(($argv[1]??'')==='--worker'){
    [$prefix,$dir,$role,$kind,$mode]=array_slice($argv,2);setup55($original,$prefix,$dir);
    check55(in_array($kind,['video','audio'],true)&&in_array($role,['first','second','repeat'],true),'Explicit worker kind/role only');
    MarketStorageBarrier::install($dir,$role,$mode);
    $command=new AiTaskWorker();exit($command->run(new think\console\Input(['--app='.($kind==='video'?'aigc_video':'aigc_music'),'--lease=10','--sleep=1','--batch=1']),new think\console\Output('console')));
}
$kind=$argv[1]??'';$mode=$argv[2]??'';
check55(in_array($kind,['video','audio'],true)&&in_array($mode,['kill','contend','late-open'],true),'Pass video/audio and kill/contend/late-open');
$appCode=$kind==='video'?'aigc_video':'aigc_music';$prefix='b55_'.bin2hex(random_bytes(6)).'_';$dir='/tmp/'.$prefix.'fixture';mkdir($dir,0700);file_put_contents($dir.'/owner',$prefix);mkdir($dir.'/public',0700);mkdir($dir.'/tmp',0700);
$pdo=new PDO('mysql:host='.$mysql['hostname'].';port='.($mysql['hostport']?:3306).';dbname='.$mysql['database'].';charset=utf8mb4',$mysql['username'],$mysql['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$names=['ai_app_task','ai_consumption_log','ai_consumption_event','ai_task_job','ai_task_result_asset','tenant_file','tenant','user','config','tenant_config','app','tenant_app_config','aigc_image_task','aigc_image_result','aigc_image_billing','aigc_image_quota','aigc_short_drama_config','power_market_product','power_market_sku','tenant_power_market_sku_price','power_market_price_rule','aigc_image_channel','aigc_image_channel_spec','aigc_video_channel','aigc_video_channel_spec','aigc_llm_config','aigc_video_task','aigc_video_result','aigc_video_billing','aigc_video_quota','aigc_video_config','aigc_product_promo_video_task','aigc_music_task','aigc_music_result','aigc_music_billing','aigc_music_quota'];

$tables=[];$children=[];$report=['kind'=>$kind,'mode'=>$mode,'scope'=>'Production workers; test-only stream barrier over real local bytes/locks/rename, disposable SQL/private roots. No provider or real user task.','provider_calls'=>0,'actual_wallet_mutations'=>0];
$spawn=function($role)use(&$children,$prefix,$dir,$kind,$mode){$pipes=[];$p=proc_open([PHP_BINARY,'-d','disable_functions=',__FILE__,'--worker',$prefix,$dir,$role,$kind,$mode],[0=>['pipe','r'],1=>['file',$dir.'/'.$role.'.log','a'],2=>['file',$dir.'/'.$role.'.log','a']],$pipes,null,array_merge(getenv(),['TMPDIR'=>$dir.'/tmp']));check55(is_resource($p),'Cannot start owned process');fclose($pipes[0]);$children[$role]=$p;};
$stop=function($role,$signal=SIGTERM)use(&$children){if(!isset($children[$role]))return;$p=$children[$role];$s=proc_get_status($p);if($s['running'])posix_kill($s['pid'],$signal);wait55(fn()=>!proc_get_status($p)['running'],'Owned process did not stop',5);proc_close($p);unset($children[$role]);};
$finished=function($role,$id)use($dir){return str_contains(file_get_contents($dir.'/'.$role.'.log'),'[job:'.$id.'] success');};
try{
    foreach($names as$name){$source=$mysql['prefix'].$name;$exists=$pdo->prepare('SHOW TABLES LIKE ?');$exists->execute([$source]);if(!$exists->fetchColumn())continue;$table=$prefix.$name;$pdo->exec('CREATE TABLE `'.$table.'` LIKE `'.$source.'`');$tables[]=$table;}
    setup55($original,$prefix,$dir);$tenant=199999955;$user=199999955;
    Db::name('tenant')->insert(['id'=>$tenant,'sn'=>'b55','name'=>'Batch55 fixture','point_balance'=>100,'disable'=>0,'create_time'=>time(),'update_time'=>time()]);
    Db::name('user')->insert(['id'=>$user,'tenant_id'=>$tenant,'sn'=>1999955,'account'=>'batch55','nickname'=>'fixture','user_money'=>100,'create_time'=>time(),'update_time'=>time()]);
    $ext=$kind==='video'?'mp4':'wav';$source=getenv('AIGC_WORKER_MEDIA_DIR').'/synthetic.'.$ext;check55(is_file($source),'Generate private media fixtures first');$media=file_get_contents($source);$uri='data:'.$kind.'/'.$ext.';base64,'.base64_encode($media);
    $key=$kind==='video'?'videos':'items';$uriKey=$kind==='video'?'video_uri':'audio_uri';$businessTable=$kind==='video'?'aigc_video_task':'aigc_music_task';$resultTable=$kind==='video'?'aigc_video_result':'aigc_music_result';
    $summary=[$key=>[[$uriKey=>$uri]+($kind==='video'?['width'=>32,'height'=>32]:['title'=>'Synthetic silence'])]];
    $task=AiAppTask::create(['task_no'=>$prefix.'task','tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>$appCode,'action_code'=>'generate','business_table'=>$businessTable,'business_id'=>0,'status'=>'success','progress'=>100,'idempotency_key'=>$prefix.'task','create_time'=>time(),'update_time'=>time(),'finish_time'=>0]);
    $c=AiConsumptionLog::create(['consume_no'=>$prefix.'consumption','app_task_id'=>$task['id'],'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>$appCode,'action_code'=>'generate','resource_type'=>'model','provider'=>'power_market','protocol'=>$kind.'_generate','quantity'=>1,'price_snapshot'=>['platform_price'=>0,'tenant_price'=>0],'usage_snapshot'=>[],'request_summary'=>[],'response_summary'=>$summary,'run_status'=>'success','billing_status'=>'settled','reserved_tenant_cost'=>0,'reserved_user_price'=>0,'actual_tenant_cost'=>0,'actual_user_price'=>0,'create_time'=>time(),'update_time'=>time(),'finish_time'=>time()]);
    $business=Db::name($businessTable)->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'app_task_id'=>$task['id'],'consumption_id'=>$c['id'],'provider'=>$kind==='video'?'power_market':'market_music_generation','channel'=>'offline-fixture','quality'=>'fixture','ratio'=>'1:1','quantity'=>1,'prompt'=>'Synthetic terminal receipt; no generation','status'=>'running','delete_time'=>0,'create_time'=>time(),'update_time'=>time()]+($kind==='video'?['model_json'=>json_encode(['resource_type'=>'model','duration'=>1])]:['title'=>'Synthetic silence']));$task->save(['business_id'=>$business]);
    $assets=Assets::recordConsumptionAssets((int)$c['id'],true);check55(count($assets)===1,'Missing owned result asset');$id=(int)$assets[0]['id'];$job=Db::name('ai_task_job')->where('result_asset_id',$id)->find();$report['transfer_job_app_linked']=(int)$job['app_task_id']===(int)$task['id'];

    $spawn('first');$marker=$mode==='late-open'?'first-before-open':'first-partial';wait55(fn()=>is_file($dir.'/'.$marker),'First worker did not reach filesystem barrier');
    $held=Db::name('ai_task_job')->where('id',$job['id'])->find();$pending=AiTaskResultAsset::find($id)->toArray();$cp=$pending['storage_meta']['transfer_checkpoint'];$target=$dir.'/public/'.$cp['uri'];
    $report['pending_checkpoint_before_storage']=!isset($pending['storage_meta']['transfer_checkpoint']['receipt'])&&$pending['transfer_status']!=='stored';
    $report['file_records_before_storage']=(int)Db::name('tenant_file')->count();
    $report['canonical_exists_while_first_paused']=is_file($target);
    if($mode!=='late-open'){$partial=json_decode(file_get_contents($dir.'/first-partial'),true);$report['partial_regular_file_bytes']=(int)filesize($dir.'/'.$partial['path']);check55($report['partial_regular_file_bytes']===32,'No real partial file at barrier');}
    if($mode==='kill'){
        $stop('first',SIGKILL);$report['killed_mid_storage_write']=true;$spawn('second');
    }else{
        wait55(fn()=>time()>(int)$held['lease_expire_time'],'Natural lease expiry not reached',15);$spawn('second');
        if($mode==='contend'){
            wait55(fn()=>is_file($dir.'/second-lock-waiting')||Db::name($businessTable)->where('id',$business)->value('status')==='success','Contender did not enter same-key write',20);
            $report['contender_waited_on_same_key_lock']=is_file($dir.'/second-lock-waiting')&&!is_file($dir.'/second-lock-acquired');
            $report['business_pending_while_first_writing']=Db::name($businessTable)->where('id',$business)->value('status')!=='success';
            file_put_contents($dir.'/release-write','1');wait55(fn()=>$finished('first',$job['id']),'First writer did not finish',15);$stop('first');
        }else{
            wait55(fn()=>Db::name($businessTable)->where('id',$business)->value('status')==='success','Second writer did not publish',30);
            $processJob=Db::name('ai_task_job')->where(['consumption_id'=>$c['id'],'job_type'=>'process_result'])->find();wait55(fn()=>$finished('second',$processJob['id']),'Winner did not finish queue event');$stop('second');
            $winner=AiTaskResultAsset::find($id)->toArray();$businessWinner=Db::name($resultTable)->where('task_id',$business)->find();touch($target,time()-60);clearstatcache(true,$target);$winnerStat=stat($target);
            file_put_contents($dir.'/release-open','1');wait55(fn()=>is_file($dir.'/first-partial')||$finished('first',$job['id']),'Late open did not complete or write');
            if(is_file($dir.'/first-partial')){$late=json_decode(file_get_contents($dir.'/first-partial'),true);$report['late_partial_regular_file_bytes']=(int)filesize($dir.'/'.$late['path']);$stop('first',SIGKILL);$report['late_writer_killed_after_partial_write']=true;}else{$stop('first');$report['late_writer_skipped_completed_file']=true;}
            clearstatcache(true,$target);$afterStat=stat($target);$report['winner_file_inode_mtime_preserved']=$winnerStat['ino']===$afterStat['ino']&&$winnerStat['mtime']===$afterStat['mtime'];
            $report['winner_asset_unchanged']=$winner===AiTaskResultAsset::find($id)->toArray();$report['winner_business_unchanged']=$businessWinner===Db::name($resultTable)->where('task_id',$business)->find();
        }
    }
    wait55(fn()=>Db::name($businessTable)->where('id',$business)->value('status')==='success','Business result not completed',35);
    if(isset($children['second'])){$processJob=Db::name('ai_task_job')->where(['consumption_id'=>$c['id'],'job_type'=>'process_result'])->find();wait55(fn()=>Db::name('ai_task_job')->where('id',$processJob['id'])->value('status')==='success','Business job incomplete');$stop('second');}
    $asset=AiTaskResultAsset::find($id)->toArray();$result=Db::name($resultTable)->where('task_id',$business)->find();clearstatcache(true,$target);
    $report+=['stored_checksum_valid'=>is_file($target)&&hash_file('sha256',$target)===hash('sha256',$media),'asset_business_uri_match'=>$asset['local_uri']===$result[$uriKey],
        'result_assets'=>(int)Db::name('ai_task_result_asset')->count(),'physical_canonical_files'=>count(glob(dirname($target).'/asset-*')?:[]),'partial_files_remaining'=>count(glob(dirname($target).'/*.part')?:[])+count(glob(dirname($target).'/.asset-*.part')?:[]),
        'file_records'=>(int)Db::name('tenant_file')->count(),'business_results'=>(int)Db::name($resultTable)->where('task_id',$business)->count(),
        'transfer_attempts'=>(int)Db::name('ai_task_job')->where('id',$job['id'])->value('attempts'),'frozen_price_preserved'=>AiConsumptionLog::find($c['id'])['price_snapshot']===['platform_price'=>0,'tenant_price'=>0],
        'parent_success'=>AiAppTask::find($task['id'])['status']==='success','wallets_unchanged'=>(float)Db::name('tenant')->where('id',$tenant)->value('point_balance')===100.&&(float)Db::name('user')->where('id',$user)->value('user_money')===100.];
    $snapshot=[AiTaskResultAsset::find($id)->toArray(),Db::name('ai_task_job')->order('id')->select()->toArray(),$result];$spawn('repeat');usleep(1200000);$stop('repeat');$report['repeat_restart_preserved_rows']=$snapshot===[AiTaskResultAsset::find($id)->toArray(),Db::name('ai_task_job')->order('id')->select()->toArray(),Db::name($resultTable)->where('task_id',$business)->find()];
    foreach(['stored_checksum_valid','asset_business_uri_match','frozen_price_preserved','parent_success','wallets_unchanged','repeat_restart_preserved_rows']as$key)check55($report[$key],$key.' failed');
    foreach(['result_assets','physical_canonical_files','file_records','business_results']as$key)check55($report[$key]===1,$key.' duplicated');check55($report['partial_files_remaining']===0,'Orphan partial file after recovery');
    if($mode!=='late-open')check55(!$report['canonical_exists_while_first_paused'],'Incomplete storage bytes exposed at canonical URI');
    if($mode==='contend')check55($report['contender_waited_on_same_key_lock']&&$report['business_pending_while_first_writing'],'Contender bypassed unfinished writer');
    if($mode==='late-open')check55($report['winner_file_inode_mtime_preserved']&&$report['winner_asset_unchanged']&&$report['winner_business_unchanged']&&($report['late_writer_skipped_completed_file']??false),'Late writer rewrote a completed file');
    copy($target,'/tmp/market-batch55-worker-result-'.$kind.'.'.$ext);echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
}catch(Throwable $error){$failure=$error;echo json_encode($report+['failure_class'=>get_class($error),'failure'=>$error->getMessage()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n");foreach(glob($dir.'/*.log')?:[]as$log)fwrite(STDERR,basename($log).":\n".substr(file_get_contents($log),-3000)."\n");}
finally{
    foreach(array_keys($children)as$role)$stop($role,SIGKILL);foreach(array_reverse($tables)as$table)$pdo->exec('DROP TABLE `'.$table.'`');
    if(is_dir($dir)&&is_file($dir.'/owner')&&trim(file_get_contents($dir.'/owner'))===$prefix){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as$f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
}
if(isset($failure))exit(1);
