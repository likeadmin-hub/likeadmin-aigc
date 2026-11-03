<?php
/** No supplier I/O. Disposable table prefix, private public/cache roots and owned worker processes only. */
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\model\ai\{AiAppTask,AiConsumptionLog,AiTaskResultAsset};
use app\common\service\ai\{AiTaskResultAssetService as Assets,AiTaskJobService as Jobs};
use app\common\command\AiTaskWorker;
function check54($ok,$why){if(!$ok)throw new RuntimeException($why);}
function wait54(callable $c,string $why,int $seconds=20): void{$until=microtime(true)+$seconds;do{if($c())return;usleep(20000);}while(microtime(true)<$until);throw new RuntimeException($why);}
$original=config('database');$mysql=$original['connections']['mysql'];
check54(PHP_SAPI==='cli'&&in_array($mysql['hostname'],['127.0.0.1','localhost'],true)&&in_array($mysql['database'],['x_cn','z_cn'],true),'Explicit local fixture only');
function setup54(array $original,string $prefix,string $dir): void{
    check54(preg_match('/^b54_[a-f0-9]{12}_$/D',$prefix)&&is_file($dir.'/owner')&&trim(file_get_contents($dir.'/owner'))===$prefix,'Not an owned fixture');
    $cfg=$original;$cfg['default']='batch54';$cfg['connections']['batch54']=array_replace($original['connections']['mysql'],['prefix'=>$prefix]);$cfg['connections']['mysql']=$cfg['connections']['batch54'];
    app()->config->set($cfg,'database');$config=new think\Config();$config->set($cfg,'database');Db::setConfig($config);
    app()->config->set(['default'=>'b54','stores'=>['b54'=>['type'=>'File','path'=>$dir.'/cache/','prefix'=>$prefix]]],'cache');
    $root=new ReflectionProperty(think\App::class,'rootPath');$root->setAccessible(true);$root->setValue(app(),$dir.'/');request()->setHost('localhost');
}

if (($argv[1] ?? '') === '--http') {
    $address=$argv[2]; $dir=$argv[3]; $prefix=trim(file_get_contents($dir.'/owner'));
    check54(preg_match('/^b54_[a-f0-9]{12}_$/D',$prefix) && preg_match('/^127\.0\.0\.1:[0-9]+$/D',$address),'Owned loopback only');
    $listener=stream_socket_server('tcp://'.$address,$errno,$why);check54(is_resource($listener),'Cannot listen');
    stream_set_blocking($listener,false);file_put_contents($dir.'/http-ready','1');$clients=[];$buffers=[];$held=null;$count=0;
    while (true) {
        $read=array_merge([$listener],array_values($clients));$write=$except=[];stream_select($read,$write,$except,0,20000);
        foreach($read as $socket){
            if($socket===$listener){$client=stream_socket_accept($listener,0);if($client){stream_set_blocking($client,false);$clients[(int)$client]=$client;$buffers[(int)$client]='';}continue;}
            $key=(int)$socket;$chunk=fread($socket,8192);
            if($chunk===''&&feof($socket)){fclose($socket);unset($clients[$key],$buffers[$key]);continue;}
            $buffers[$key].=$chunk;if(!str_contains($buffers[$key],"\r\n\r\n"))continue;
            check54(str_starts_with($buffers[$key],'GET /'.$prefix.'source.mp4 HTTP/'),'Unexpected loopback request');
            $count++;file_put_contents($dir.'/request-count',(string)$count);$first=$count===1;
            $bytes=file_get_contents($dir.'/'.($first?'late':'winner').'.mp4');
            fwrite($socket,"HTTP/1.1 200 OK\r\nContent-Type: video/mp4\r\nContent-Length: ".strlen($bytes)."\r\nConnection: close\r\n\r\n".($first?substr($bytes,0,64):$bytes));
            unset($clients[$key],$buffers[$key]);
            if($first){$held=[$socket,substr($bytes,64)];file_put_contents($dir.'/download-started','1');}else fclose($socket);
        }
        if($held!==null && is_file($dir.'/release-download')){fwrite($held[0],$held[1]);fclose($held[0]);$held=null;file_put_contents($dir.'/download-released','1');}
    }
}
if (($argv[1] ?? '') === '--worker') {
    setup54($original,$argv[2],$argv[3]);
    $command=new AiTaskWorker();exit($command->run(new think\console\Input(['--app=aigc_video','--lease=10','--sleep=1','--batch=1']),new think\console\Output('console')));
}
$mode=$argv[1]??'';check54(in_array($mode,['same','changed'],true),'Pass same or changed');
$prefix='b54_'.bin2hex(random_bytes(6)).'_';$dir='/tmp/'.$prefix.'fixture';mkdir($dir,0700);file_put_contents($dir.'/owner',$prefix);mkdir($dir.'/public',0700);mkdir($dir.'/tmp',0700);
$pdo=new PDO('mysql:host='.$mysql['hostname'].';port='.($mysql['hostport']?:3306).';dbname='.$mysql['database'].';charset=utf8mb4',$mysql['username'],$mysql['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$names=['ai_app_task','ai_consumption_log','ai_consumption_event','ai_task_job','ai_task_result_asset','tenant_file','tenant','user','config','tenant_config','app','tenant_app_config','aigc_image_task','aigc_image_result','aigc_image_billing','aigc_image_quota','aigc_short_drama_config','power_market_product','power_market_sku','tenant_power_market_sku_price','power_market_price_rule','aigc_image_channel','aigc_image_channel_spec','aigc_video_channel','aigc_video_channel_spec','aigc_llm_config','aigc_video_task','aigc_video_result','aigc_video_billing','aigc_video_quota','aigc_video_config','aigc_product_promo_video_task','aigc_music_task','aigc_music_result','aigc_music_billing','aigc_music_quota'];

$tables=[];$children=[];$report=['mode'=>$mode,'scope'=>'Disposable prefix, two production workers and owned loopback source only','provider_calls'=>0,'actual_wallet_mutations'=>0];
$spawn=function($role,array $args)use(&$children,$dir){$pipes=[];$p=proc_open(array_merge([PHP_BINARY,'-d','disable_functions=',__FILE__],$args),[0=>['pipe','r'],1=>['file',$dir.'/'.$role.'.log','a'],2=>['file',$dir.'/'.$role.'.log','a']],$pipes,null,array_merge(getenv(),['TMPDIR'=>$dir.'/tmp']));check54(is_resource($p),'Cannot start owned process');fclose($pipes[0]);$children[$role]=$p;};
$stop=function($role,$signal=SIGTERM)use(&$children){if(!isset($children[$role]))return;$p=$children[$role];$s=proc_get_status($p);if($s['running'])posix_kill($s['pid'],$signal);wait54(fn()=>!proc_get_status($p)['running'],'Owned process did not stop',5);proc_close($p);unset($children[$role]);};
try {
    foreach($names as$name){$source=$mysql['prefix'].$name;$exists=$pdo->prepare('SHOW TABLES LIKE ?');$exists->execute([$source]);if(!$exists->fetchColumn())continue;$table=$prefix.$name;$pdo->exec('CREATE TABLE `'.$table.'` LIKE `'.$source.'`');$tables[]=$table;}
    setup54($original,$prefix,$dir);$tenant=199999954;$user=199999954;
    Db::name('tenant')->insert(['id'=>$tenant,'sn'=>'b54','name'=>'Batch54 fixture','point_balance'=>100,'disable'=>0,'create_time'=>time(),'update_time'=>time()]);
    Db::name('user')->insert(['id'=>$user,'tenant_id'=>$tenant,'sn'=>1999954,'account'=>'batch54','nickname'=>'fixture','user_money'=>100,'create_time'=>time(),'update_time'=>time()]);
    $source=getenv('AIGC_WORKER_MEDIA_DIR').'/synthetic.mp4';$late=getenv('AIGC_WORKER_MEDIA_DIR').'/red.mp4';check54(is_file($source)&&is_file($late),'Generate private color fixtures first');
    copy($source,$dir.'/winner.mp4');copy($mode==='same'?$source:$late,$dir.'/late.mp4');
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$why);check54(is_resource($socket),'Cannot allocate loopback port');$address=stream_socket_get_name($socket,false);fclose($socket);
    $spawn('http',['--http',$address,$dir]);wait54(fn()=>is_file($dir.'/http-ready'),'Loopback not ready',5);
    $kind='video';$appCode='aigc_video';$uri='http://'.$address.'/'.$prefix.'source.mp4';
    $key='videos';$uriKey='video_uri';$businessTable='aigc_video_task';$resultTable='aigc_video_result';
    $summary=[$key=>[[$uriKey=>$uri]+['width'=>32,'height'=>32]]];
    $task=AiAppTask::create(['task_no'=>$prefix.'task','tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>$appCode,'action_code'=>'generate','business_table'=>$businessTable,'business_id'=>0,'status'=>'success','progress'=>100,'idempotency_key'=>$prefix.'task','create_time'=>time(),'update_time'=>time(),'finish_time'=>0]);
    $c=AiConsumptionLog::create(['consume_no'=>$prefix.'consumption','app_task_id'=>$task['id'],'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>$appCode,'action_code'=>'generate','resource_type'=>'model','provider'=>'power_market','protocol'=>$kind.'_generate','quantity'=>1,'price_snapshot'=>['platform_price'=>0,'tenant_price'=>0],'usage_snapshot'=>[],'request_summary'=>[],'response_summary'=>$summary,'run_status'=>'success','billing_status'=>'settled','reserved_tenant_cost'=>0,'reserved_user_price'=>0,'actual_tenant_cost'=>0,'actual_user_price'=>0,'create_time'=>time(),'update_time'=>time(),'finish_time'=>time()]);
    $business=Db::name($businessTable)->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'app_task_id'=>$task['id'],'consumption_id'=>$c['id'],'provider'=>'power_market','channel'=>'offline-fixture','quality'=>'fixture','ratio'=>'1:1','quantity'=>1,'prompt'=>'Synthetic terminal receipt; no generation','status'=>'running','delete_time'=>0,'create_time'=>time(),'update_time'=>time()]+['model_json'=>json_encode(['resource_type'=>'model','duration'=>1])]);$task->save(['business_id'=>$business]);
    $assets=Assets::recordConsumptionAssets((int)$c['id'],true);check54(count($assets)===1,'Missing owned result asset');$id=(int)$assets[0]['id'];$job=Db::name('ai_task_job')->where('result_asset_id',$id)->find();$report['transfer_job_app_linked']=(int)$job['app_task_id']===(int)$task['id'];

    $spawn('late',['--worker',$prefix,$dir]);wait54(fn()=>is_file($dir.'/download-started'),'First worker did not download');
    $claimed=Db::name('ai_task_job')->where('id',$job['id'])->find();$lateToken=$claimed['lease_token'];
    wait54(fn()=>time()>(int)$claimed['lease_expire_time'],'Natural lease expiry not reached',15);
    $spawn('winner',['--worker',$prefix,$dir]);
    wait54(fn()=>Db::name($businessTable)->where('id',$business)->value('status')==='success','Replacement worker did not finish business result',30);
    $winner=AiTaskResultAsset::find($id)->toArray();$result=Db::name($resultTable)->where('task_id',$business)->find();$winnerJob=Db::name('ai_task_job')->where('id',$job['id'])->find();
    check54($winner['transfer_status']==='stored'&&$winnerJob['status']==='success'&&(int)$winnerJob['attempts']===2,'Takeover did not own second lease');
    // Business rows become terminal just before the worker records its own completion.
    // Drain that owned process before counting events from the late executor.
    $processJob=Db::name('ai_task_job')->where(['consumption_id'=>$c['id'],'job_type'=>'process_result'])->find();
    wait54(fn()=>str_contains(file_get_contents($dir.'/winner.log'),'[job:'.$processJob['id'].'] success'),'Winner completion not recorded');
    $stop('winner');
    $eventsBefore=(int)Db::name('ai_consumption_event')->where('consumption_id',$c['id'])->whereIn('event_type',['job_success','job_retry','job_waiting'])->count();
    file_put_contents($dir.'/release-download','1');wait54(fn()=>is_file($dir.'/download-released'),'Late response not released');
    wait54(fn()=>preg_match('/\[job:'.(int)$job['id'].'\] (success|[^\n]*转存)/u',file_get_contents($dir.'/late.log'))===1,'Late worker did not finish',15);
    $stop('late');$stop('winner');$stop('http');
    $after=AiTaskResultAsset::find($id)->toArray();$resultAfter=Db::name($resultTable)->where('task_id',$business)->find();
    $files=glob($dir.'/public/uploads/ai_result/'.$tenant.'/'.$user.'/*')?:[];
    $report+=['requests'=>(int)file_get_contents($dir.'/request-count'),'lease_takeover_attempts'=>(int)$winnerJob['attempts'],
        'late_payload_changed'=>$mode==='changed','winner_asset_unchanged'=>$winner===$after,'business_result_unchanged'=>$result===$resultAfter,
        'asset_business_uri_match'=>$after['local_uri']===$resultAfter['video_uri'],'physical_files'=>count($files),
        'file_records'=>(int)Db::name('tenant_file')->count(),'business_results'=>(int)Db::name($resultTable)->where('task_id',$business)->count(),
        'stale_completion_events'=>(int)Db::name('ai_consumption_event')->where('consumption_id',$c['id'])->whereIn('event_type',['job_success','job_retry','job_waiting'])->count()-$eventsBefore,
        'winner_checksum_preserved'=>hash_file('sha256',$dir.'/public/'.$winner['local_uri'])===hash_file('sha256',$source),
        'frozen_price_preserved'=>AiConsumptionLog::find($c['id'])['price_snapshot']===['platform_price'=>0,'tenant_price'=>0],
        'parent_success'=>AiAppTask::find($task['id'])['status']==='success','wallets_unchanged'=>(float)Db::name('tenant')->where('id',$tenant)->value('point_balance')===100.&&(float)Db::name('user')->where('id',$user)->value('user_money')===100.];
    foreach(['winner_asset_unchanged','business_result_unchanged','asset_business_uri_match','winner_checksum_preserved','frozen_price_preserved','parent_success','wallets_unchanged']as$check)check54($report[$check],$check.' failed');
    check54($report['requests']===2&&$report['physical_files']===1&&$report['file_records']===1&&$report['business_results']===1&&$report['stale_completion_events']===0,'Duplicate output or stale lifecycle event');
    copy($dir.'/public/'.$winner['local_uri'],'/tmp/market-batch54-worker-result-video.mp4');
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
} catch(Throwable $error) {
    $failure=$error;echo json_encode($report+['failure_class'=>get_class($error),'failure'=>$error->getMessage()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
    fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n");foreach(glob($dir.'/*.log')?:[]as$log)fwrite(STDERR,basename($log).":\n".substr(file_get_contents($log),-3000)."\n");
} finally {
    foreach(array_keys($children)as$role)$stop($role,SIGKILL);
    foreach(array_reverse($tables)as$table)$pdo->exec('DROP TABLE `'.$table.'`');
    if(is_dir($dir)&&is_file($dir.'/owner')&&trim(file_get_contents($dir.'/owner'))===$prefix){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as$f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($dir);}
}
if(isset($failure))exit(1);
