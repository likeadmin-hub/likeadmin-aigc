<?php
/** Controlled source 403 only: owned loopback bytes, disposable SQL/root, original result workers. No provider calls. */
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();
// Think installs its own handler during initialize; fixture failures must still exit nonzero without raw URLs.
set_exception_handler(static function(Throwable $error): void {fwrite(STDERR,json_encode(['failure_class'=>get_class($error),'failure_fingerprint'=>hash('sha256',$error->getMessage())])."\n");exit(1);});
require __DIR__.'/Support/MarketWorkerFixture.php';
use think\facade\Db;
use app\common\model\ai\{AiAppTask,AiConsumptionLog,AiTaskResultAsset};
use app\common\service\ai\{AiTaskResultAssetService as Assets,AiTaskBusinessResultService as Business,GenerationTaskResultService as View};
use app\common\service\storage\StorageConfigService;
$original=config('database');
if(($argv[1]??'')==='--worker'){
    MarketWorkerFixture::activate($original,$argv[2],$argv[3]);
    // Keep production public-address validation for images, but open only our own loopback socket.
    if($argv[4]==='aigc_image'){putenv('AIGC_HTTP_FIXTURE_DIR='.$argv[3]);require __DIR__.'/Support/MarketDeepHttpTransport.php';}
    $command=new app\common\command\AiTaskWorker();
    exit($command->run(new think\console\Input(['--app='.$argv[4],'--lease=10','--sleep=1','--batch=1']),new think\console\Output('console')));
}
$kind=$argv[1]??'';$mode=$argv[2]??'';
MarketWorkerFixture::check(in_array($kind,['image','video','audio'],true),'Pass image/video/audio');
MarketWorkerFixture::check(in_array($mode,['blocked-then-recover','stored-source-expired'],true),'Pass an explicit controlled-403 mode');
$names=['ai_app_task','ai_consumption_log','ai_consumption_event','ai_task_job','ai_task_result_asset','tenant_file','tenant','user','config','tenant_config','app','tenant_app_config','aigc_image_task','aigc_image_result','aigc_image_billing','aigc_image_quota','aigc_short_drama_config','power_market_product','power_market_sku','tenant_power_market_sku_price','power_market_price_rule','aigc_image_channel','aigc_image_channel_spec','aigc_video_channel','aigc_video_channel_spec','aigc_llm_config','aigc_video_task','aigc_video_result','aigc_video_billing','aigc_video_quota','aigc_video_config','aigc_product_promo_video_task','aigc_music_task','aigc_music_result','aigc_music_billing','aigc_music_quota'];
$fixture=null;$server=null;$stage='setup';$report=['kind'=>$kind,'mode'=>$mode,'scope'=>'Owned loopback controlled 403; not real signature expiry or provider URL refresh','provider_ai_calls'=>0,'actual_wallet_mutations'=>0];
try{
    $fixture=new MarketWorkerFixture($original,$names);$tenant=$fixture->tenant;$user=$fixture->user;$prefix=$fixture->prefix;$dir=$fixture->dir;
    foreach(['enable'=>1,'default'=>'local']as$name=>$value)Db::name('tenant_config')->insert(['tenant_id'=>$tenant,'type'=>'storage','name'=>$name,'value'=>json_encode($value,JSON_THROW_ON_ERROR)]);
    $config=StorageConfigService::getEffectiveConfig($tenant);MarketWorkerFixture::check($config['scope']==='tenant'&&$config['default']==='local','Not isolated tenant local storage');
    $ext=['image'=>'png','video'=>'mp4','audio'=>'wav'][$kind];$mime=['image'=>'image/png','video'=>'video/mp4','audio'=>'audio/wav'][$kind];
    if($kind==='image')$media=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=');
    else{$source=getenv('AIGC_WORKER_MEDIA_DIR').'/synthetic.'.$ext;MarketWorkerFixture::check(is_file($source),'Generate private synthetic media first');$media=file_get_contents($source);}
    MarketWorkerFixture::check(is_string($media)&&$media!=='','Empty synthetic media');file_put_contents($dir.'/source.'.$ext,$media);
    file_put_contents($dir.'/source.json',json_encode(['ext'=>$ext,'mime'=>$mime],JSON_THROW_ON_ERROR));
    file_put_contents($dir.'/router.php', <<<'ROUTER'
<?php
$dir=__DIR__;$owner=trim(file_get_contents($dir.'/owner'));$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(!preg_match('/^b56_[a-f0-9]{12}_$/D',$owner)){http_response_code(404);return;}
if($path==='/'.$owner.'ready'){echo 'owned-ready';return;}
$source=json_decode(file_get_contents($dir.'/source.json'),true);
if($path!=='/'.$owner.'source.'.$source['ext']){http_response_code(404);return;}
clearstatcache(true,$dir.'/expired');$status=is_file($dir.'/expired')?403:200;
file_put_contents($dir.'/requests.log',json_encode(['status'=>$status,'probe'=>($_SERVER['HTTP_X_OWNED_PROBE']??'')==='1'])."\n",FILE_APPEND|LOCK_EX);
http_response_code($status);
if($status===403){header('Content-Type: text/plain');echo 'Synthetic source unavailable';return;}
$file=$dir.'/source.'.$source['ext'];header('Content-Type: '.$source['mime']);header('Content-Length: '.filesize($file));readfile($file);
ROUTER
    );
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$reason);MarketWorkerFixture::check(is_resource($socket),'Cannot reserve loopback port');$address=stream_socket_get_name($socket,false);fclose($socket);
    $pipes=[];$server=proc_open([PHP_BINARY,'-d','disable_functions=','-S',$address,$dir.'/router.php'],[0=>['pipe','r'],1=>['file',$dir.'/http.log','a'],2=>['file',$dir.'/http.log','a']],$pipes,null,array_merge(getenv(),['TMPDIR'=>$dir.'/tmp']));
    MarketWorkerFixture::check(is_resource($server),'Cannot start owned HTTP server');fclose($pipes[0]);$base='http://'.$address;file_put_contents($dir.'/supplier-url',$base);
    $readyContext=stream_context_create(['http'=>['timeout'=>1]]);MarketWorkerFixture::wait(fn()=>@file_get_contents($base.'/'.$prefix.'ready',false,$readyContext)==='owned-ready','Owned HTTP server not ready',5);
    $requests=static fn()=>array_values(array_filter(array_map(static fn($line)=>json_decode($line,true),@file($dir.'/requests.log',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[]),'is_array'));
    $sourceRequests=static fn()=>array_values(array_filter($requests(),static fn($row)=>!$row['probe']));
    $uri=($kind==='image'?'http://private-media.example.test':$base).'/'.$prefix.'source.'.$ext;
    $appCode=['image'=>'aigc_image','video'=>'aigc_video','audio'=>'aigc_music'][$kind];$native=$kind==='audio'?'music':$kind;
    $businessTable='aigc_'.$native.'_task';$resultTable='aigc_'.$native.'_result';$billingTable='aigc_'.$native.'_billing';$key=['image'=>'images','video'=>'videos','audio'=>'items'][$kind];$uriKey=['image'=>'image_uri','video'=>'video_uri','audio'=>'audio_uri'][$kind];
    $summary=[$key=>[[$uriKey=>$uri]+($kind==='video'?['width'=>32,'height'=>32]:['title'=>'Synthetic silence'])]];
    $task=AiAppTask::create(['task_no'=>$prefix.'task','tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>$appCode,'action_code'=>'generate','business_table'=>$businessTable,'business_id'=>0,'status'=>'success','progress'=>100,'idempotency_key'=>$prefix.'task','create_time'=>time(),'update_time'=>time(),'finish_time'=>0]);
    $c=AiConsumptionLog::create(['consume_no'=>$prefix.'consumption','app_task_id'=>$task['id'],'tenant_id'=>$tenant,'user_id'=>$user,'app_code'=>$appCode,'action_code'=>'generate','resource_type'=>'model','provider'=>'power_market','protocol'=>$kind.'_generate','quantity'=>1,'price_snapshot'=>['platform_price'=>0,'tenant_price'=>0],'usage_snapshot'=>[],'request_summary'=>['owned_fixture'=>'source-expiry','original_intent'=>$prefix.'intent'],'response_summary'=>$summary,'run_status'=>'success','billing_status'=>'settled','reserved_tenant_cost'=>0,'reserved_user_price'=>0,'actual_tenant_cost'=>0,'actual_user_price'=>0,'create_time'=>time(),'update_time'=>time(),'finish_time'=>time()]);
    $business=Db::name($businessTable)->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'app_task_id'=>$task['id'],'provider'=>$kind==='audio'?'market_music_generation':'power_market','channel'=>'offline-fixture','quality'=>'fixture','ratio'=>'1:1','quantity'=>1,'prompt'=>'Owned synthetic receipt; no generation','status'=>'running','delete_time'=>0,'create_time'=>time(),'update_time'=>time()]+($kind==='image'?[]:($kind==='audio'?['title'=>'Synthetic silence']:['model_json'=>json_encode(['resource_type'=>'model','duration'=>1])])));
    if($kind!=='image')Db::name($businessTable)->where('id',$business)->update(['consumption_id'=>$c['id']]);$task->save(['business_id'=>$business]);
    $assets=Assets::recordConsumptionAssets((int)$c['id'],true);MarketWorkerFixture::check(count($assets)===1,'Missing original result asset');$id=(int)$assets[0]['id'];$job=Db::name('ai_task_job')->where('result_asset_id',$id)->find();
    MarketWorkerFixture::check($job&&(int)$job['app_task_id']===(int)$task['id']&&(int)$job['consumption_id']===(int)$c['id'],'Missing original app-linked transfer job');
    $frozen=AiConsumptionLog::find($c['id'])->toArray();$initialBusiness=Db::name($businessTable)->where('id',$business)->find();
    if($mode==='blocked-then-recover')touch($dir.'/expired');$stage='initial-worker';$fixture->start(__FILE__,$appCode);
    if($mode==='blocked-then-recover'){
        MarketWorkerFixture::wait(fn()=>Db::name('ai_task_job')->where('id',$job['id'])->value('status')==='retrying'&&count($sourceRequests())>0,'Source 403 did not schedule original transfer retry',45);$fixture->stop();
        $blocked=AiTaskResultAsset::find($id)->toArray();$pending=View::read((int)$task['id'],$tenant,$user);
        MarketWorkerFixture::check($blocked['transfer_status']==='retrying'&&$blocked['local_uri']===''&&empty($blocked['storage_meta']['file_id']),'Failed source was registered as managed');
        MarketWorkerFixture::check(glob($dir.'/public/*')===[]&&(int)Db::name('tenant_file')->count()===0&&(int)Db::name($resultTable)->count()===0,'Failed source exposed a formal or partial result');
        MarketWorkerFixture::check($pending['status']==='running'&&$pending['stage']==='transferring'&&$pending['results']===[],'Read-only history exposed an unavailable result');
        MarketWorkerFixture::check($frozen===AiConsumptionLog::find($c['id'])->toArray()&&$initialBusiness===Db::name($businessTable)->where('id',$business)->find(),'Source failure changed frozen consumption or business');
        MarketWorkerFixture::check(array_unique(array_column($sourceRequests(),'status'))===[403],'Wrong initial source failure');
        $report['blocked_source_retained_original_retry']=true;$report['no_partial_file_or_success_before_recovery']=true;
        unlink($dir.'/expired');$stage='recovery-worker';$fixture->start(__FILE__,$appCode,'recovery');
    }
    MarketWorkerFixture::wait(fn()=>Db::name($businessTable)->where('id',$business)->value('status')==='success'&&(int)Db::name('ai_task_job')->where('status','<>','success')->count()===0,'Original worker did not finish all result jobs',45);
    $fixture->stop($mode==='blocked-then-recover'?'recovery':'worker');$stage='stored-receipt';
    $asset=AiTaskResultAsset::find($id)->toArray();$receipt=$asset['storage_meta'];$result=Db::name($resultTable)->where('task_id',$business)->find();
    $expected='uploads/ai_result/'.$tenant.'/'.$user.'/asset-'.$id.'-'.hash('sha256',$media).'.'.$ext;$path=$dir.'/public/'.$expected;
    MarketWorkerFixture::check($asset['transfer_status']==='stored'&&$asset['external_url']===$uri&&$asset['local_uri']===$expected&&$result&&$result[$uriKey]===$expected,'Original asset/business lost managed receipt');
    MarketWorkerFixture::check(is_file($path)&&!is_link($path)&&hash_file('sha256',$path)===hash('sha256',$media),'Managed bytes differ');
    MarketWorkerFixture::check(($receipt['mime_type']??'')===$mime&&(int)($receipt['file_size']??0)===strlen($media)&&($receipt['checksum']??'')===hash('sha256',$media),'Measured MIME/size/SHA differ');
    $file=Db::name('tenant_file')->where('id',$receipt['file_id'])->find();
    MarketWorkerFixture::check($file&&(int)$file['tenant_id']===$tenant&&(int)$file['source_id']===$user&&(int)$asset['tenant_id']===$tenant&&(int)$asset['user_id']===$user&&(int)$result['tenant_id']===$tenant&&(int)$result['user_id']===$user,'Result ownership differs');
    foreach(['storage_scope','storage_engine','storage_domain']as$field)MarketWorkerFixture::check($result[$field]===$asset[$field]&&$file[$field]===$asset[$field],'Storage receipt metadata differs');
    MarketWorkerFixture::check($asset['storage_scope']==='tenant'&&$asset['storage_engine']==='local','Unexpected result storage');
    if($kind==='image'){$imageInfo=@getimagesize($path);MarketWorkerFixture::check(is_array($imageInfo)&&$imageInfo[0]===1,'Stored PNG cannot decode');}
    if($kind==='video')MarketWorkerFixture::check((int)$result['width']===32&&(int)$result['height']===32,'Video dimensions lost');
    if($kind==='audio')MarketWorkerFixture::check((float)$result['duration']===1.&&$result['mime_type']===$mime,'Measured audio metadata lost');
    $after=AiConsumptionLog::find($c['id'])->toArray();$projection=['response_summary'=>true,'update_time'=>true];
    MarketWorkerFixture::check(array_diff_key($frozen,$projection)===array_diff_key($after,$projection)&&($after['response_summary'][$key][0][$uriKey]??null)===$expected,'Original identity/billing/request changed');
    if($mode==='stored-source-expired'){
        touch($dir.'/expired');$stage='expired-source-probe';
        $context=stream_context_create(['http'=>['timeout'=>2,'ignore_errors'=>true,'header'=>"X-Owned-Probe: 1\r\n"]]);
        $probe=@file_get_contents($base.'/'.$prefix.'source.'.$ext,false,$context);$status=0;foreach($http_response_header??[]as$header)if(preg_match('~^HTTP/\S+\s+(\d+)~',$header,$match))$status=(int)$match[1];
        MarketWorkerFixture::check($probe!==false&&$status===403,'The exact original source did not become unavailable');
        MarketWorkerFixture::check(count(array_filter($requests(),static fn($row)=>$row['probe']&&$row['status']===403))===1,'Missing owned source-expiry probe');
        $report['exact_source_403_probed_after_storage']=true;
    }
    $stage='terminal-replay';$beforeRequests=count($sourceRequests());
    $snapshot=static fn()=>[AiAppTask::find($task['id'])->toArray(),AiConsumptionLog::find($c['id'])->toArray(),AiTaskResultAsset::find($id)->toArray(),Db::name($businessTable)->where('id',$business)->find(),Db::name($resultTable)->order('id')->select()->toArray(),Db::name($billingTable)->order('id')->select()->toArray(),Db::name('tenant_file')->order('id')->select()->toArray(),Db::name('ai_task_job')->order('id')->select()->toArray()];
    $stable=$snapshot();clearstatcache(true,$path);$stat=stat($path);
    for($i=0;$i<2;$i++){
        $view=View::read((int)$task['id'],$tenant,$user);MarketWorkerFixture::check($view['status']==='success'&&count($view['results'])===1&&(int)$view['results'][0]['result_asset_id']===$id&&$view['results'][0]['uri']===$expected,'History did not use the same managed result');
        Assets::transfer($id);MarketWorkerFixture::check(Business::syncTerminalByConsumptionId((int)$c['id']),'Stored business replay failed');
    }
    $fixture->start(__FILE__,$appCode,'repeat');usleep(1200000);$fixture->stop('repeat');
    MarketWorkerFixture::check($stable===$snapshot()&&count($sourceRequests())===$beforeRequests,'Stored replay changed terminal rows or requested original source');
    clearstatcache(true,$path);$replayedStat=stat($path);MarketWorkerFixture::check($stat['ino']===$replayedStat['ino']&&$stat['mtime']===$replayedStat['mtime']&&hash_file('sha256',$path)===hash('sha256',$media),'Stored replay rewrote managed bytes');
    foreach(['ai_app_task','ai_consumption_log','ai_task_result_asset','tenant_file',$resultTable]as$table)MarketWorkerFixture::check((int)Db::name($table)->count()===1,'Duplicate task/consumption/result after replay');
    MarketWorkerFixture::check((float)Db::name('tenant')->where('id',$tenant)->value('point_balance')===100.&&(float)Db::name('user')->where('id',$user)->value('user_money')===100.,'Fixture wallets changed');
    $attempts=(int)Db::name('ai_task_job')->where('id',$job['id'])->value('attempts');if($mode==='blocked-then-recover')MarketWorkerFixture::check($attempts>=2&&in_array(200,array_column($sourceRequests(),'status'),true),'Recovery did not retry the original source/job');
    $report+=['actual_worker_command'=>true,'original_transfer_attempts'=>$attempts,'source_requests'=>$beforeRequests,'source_statuses'=>array_column($sourceRequests(),'status'),'frozen_request_billing_and_owner_retained'=>true,'history_and_replay_no_source_fetch'=>true,'terminal_rows_and_managed_bytes_unchanged'=>true,'one_task_consumption_asset_file_business_result'=>true,'receipt_mime'=>$receipt['mime_type'],'stored_bytes'=>strlen($media),'stored_sha256'=>hash_file('sha256',$path),'wallets_unchanged'=>true];
}catch(Throwable $error){$failure=$error;$report+=['stage_failed'=>$stage,'failure_class'=>get_class($error),'failure_fingerprint'=>hash('sha256',$error->getMessage())];
}finally{
    $stopped=true;if($fixture)foreach(['worker','recovery','repeat']as$role)try{$fixture->stop($role);}catch(Throwable $error){$stopped=false;$failure=$error;$report['worker_stop_failed']=true;}
    if(is_resource($server))try{$state=proc_get_status($server);if($state['running'])proc_terminate($server,SIGTERM);try{MarketWorkerFixture::wait(fn()=>!proc_get_status($server)['running'],'Owned HTTP server did not stop',5);}catch(Throwable){proc_terminate($server,SIGKILL);MarketWorkerFixture::wait(fn()=>!proc_get_status($server)['running'],'Owned HTTP server kill failed',5);}proc_close($server);}catch(Throwable $error){$stopped=false;$failure=$error;$report['http_stop_failed']=true;}
    // Never remove SQL/files while an owned writer or HTTP process may still be alive.
    if($fixture&&$stopped)try{$fixture->cleanup();$report['cleanup_complete']=!is_dir($fixture->dir);}catch(Throwable $error){$failure=$error;$report['cleanup_failed']=true;}
    if($fixture&&empty($report['cleanup_complete'])){$report['retained_owned_fixture']=$fixture->prefix;$failure=$failure??new RuntimeException('Owned cleanup incomplete');}
}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";if(isset($failure))exit(1);
