<?php
declare(strict_types=1);
/** Executes the genuine 390 updater; a separate observer survives the terminal handoff. */
function legacyCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function legacyWrite(string $path,string $content):void {if(!is_dir(dirname($path)))mkdir(dirname($path),0755,true);file_put_contents($path,$content);}
function legacyCopy(string $from,string $to):void {
    if(is_dir($from)){if(!is_dir($to))mkdir($to,0755,true);foreach(new FilesystemIterator($from,FilesystemIterator::SKIP_DOTS) as $file){legacyCheck(!$file->isLink(),'Baseline symlink');legacyCopy($file->getPathname(),$to.'/'.$file->getFilename());}}
    else {if(!is_dir(dirname($to)))mkdir(dirname($to),0755,true);legacyCheck(copy($from,$to),'Baseline copy failed');}
}
function legacyHashes(string $path):array {
    $result=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile())$result[substr($file->getPathname(),strlen($path)+1)]=hash_file('sha256',$file->getPathname());ksort($result);return $result;
}
function legacySignature(string $path):void { $hashes=legacyHashes($path);unset($hashes['signature.json']);legacyWrite($path.'/signature.json',json_encode(['sha256'=>$hashes],JSON_UNESCAPED_SLASHES)); }
function legacyMetadataRows(string $table):array {
    $indexes=think\facade\Db::query("SHOW INDEX FROM la_".$table." WHERE Key_name='PRIMARY'");
    usort($indexes,static fn(array $a,array $b):int=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
    $columns=array_column($indexes,'Column_name');legacyCheck($columns!==[],'Metadata table needs deterministic primary-key ordering: '.$table);
    return think\facade\Db::name($table)->order(array_fill_keys($columns,'asc'))->select()->toArray();
}
function legacySeed(string $table,array $values):void {
    $columns=think\facade\Db::query('SHOW COLUMNS FROM `la_'.$table.'`');$fields=array_column($columns,'Field');$values=array_intersect_key($values,array_flip($fields));
    foreach($columns as $c){if(isset($values[$c['Field']]) || $c['Null']==='YES' || $c['Default']!==null || str_contains($c['Extra'],'auto_increment'))continue;$values[$c['Field']]=preg_match('/int|decimal|float|double/',$c['Type'])?0:'';}
    think\facade\Db::name($table)->insert($values);
}
function legacyCustomerConfiguration():array {
    $snapshots=[];
    foreach(['aigc_image','aigc_video','aigc_digital_human'] as $app) {
        // Include edited official channels as well as tenant-owned channels: migrations must preserve both.
        $channel=think\facade\Db::name($app.'_channel')->where('tenant_id',0)->order('id')->find();
        legacyCheck($channel!==null,'390 default channel missing: '.$app);
        think\facade\Db::name($app.'_channel')->where('id',$channel['id'])->update(['provider'=>'customer-provider','model'=>'customer-'.$app,'config_json'=>json_encode(['endpoint'=>'https://customer.invalid/v1','lipsync_model'=>'customer-lipsync','customer_key'=>'KEEP_CHANNEL']),'status'=>0,'update_time'=>123456]);
        legacySeed($app.'_channel',['tenant_id'=>901,'code'=>'customer-channel','provider'=>'customer-provider','model'=>'customer-tenant-model','config_json'=>'{"customer_key":"KEEP_TENANT_CHANNEL"}','status'=>0,'update_time'=>123456]);
        $snapshots[$app.'_channel']=think\facade\Db::name($app.'_channel')->where('id',$channel['id'])->whereOr('tenant_id',901)->order('id')->select()->toArray();
        $spec=think\facade\Db::name($app.'_channel_spec')->where('tenant_id',0)->order('id')->find();
        legacyCheck($spec!==null,'390 default channel price missing: '.$app);
        think\facade\Db::name($app.'_channel_spec')->where('id',$spec['id'])->update(['platform_unit_cost'=>'7.25','tenant_unit_price'=>'19.75','provider_params_json'=>'{"customer_price_rule":"KEEP_PRICE"}','status'=>0,'update_time'=>123456]);
        legacySeed($app.'_channel_spec',['tenant_id'=>901,'channel_code'=>'customer-channel','quality'=>'customer-quality','ratio'=>'16:9','platform_unit_cost'=>'8.50','tenant_unit_price'=>'21.25','provider_params_json'=>'{"customer_price_rule":"KEEP_TENANT_PRICE"}','status'=>0,'update_time'=>123456]);
        $snapshots[$app.'_channel_spec']=think\facade\Db::name($app.'_channel_spec')->where('id',$spec['id'])->whereOr('tenant_id',901)->order('id')->select()->toArray();
    }
    // The historical digital-human pricing repair copied this global model into every platform channel.
    think\facade\Db::name('aigc_digital_human_config')->where('tenant_id',0)->update(['provider'=>'distinct-global-provider','model'=>'distinct-global-model','config_json'=>'{"customer_global":"KEEP_CONFIG"}','update_time'=>123456]);
    $snapshots['aigc_digital_human_config']=think\facade\Db::name('aigc_digital_human_config')->where('tenant_id',0)->order('id')->select()->toArray();
    legacyCheck($snapshots['aigc_digital_human_config']!==[],'390 digital-human global config missing');
    legacySeed('power_market_product',['id'=>99001,'product_code'=>'customer-product','source_code'=>'customer','upstream_resource_key'=>'customer-resource','source_payload'=>'{"customer_product":"KEEP_PRODUCT"}','status'=>0,'update_time'=>123456]);
    legacySeed('power_market_sku',['id'=>99001,'product_id'=>99001,'sku_key'=>'customer-sku','upstream_price'=>'3.125','sale_points'=>'17.875','locked_params'=>'{"quality":"customer-quality"}','source_payload'=>'{"customer_sku":"KEEP_SKU"}','sale_status'=>0,'update_time'=>123456]);
    legacySeed('tenant_power_market_sku_price',['id'=>99001,'tenant_id'=>901,'sku_id'=>99001,'sale_points'=>'31.625','sale_status'=>0,'update_time'=>123456]);
    foreach(['power_market_product','power_market_sku','tenant_power_market_sku_price'] as $table)$snapshots[$table]=think\facade\Db::name($table)->where('id',99001)->select()->toArray();
    return $snapshots;
}
legacyCheck(getenv('SYSTEM_SERVICE_DISPOSABLE')==='1','Disposable fixture required');
$phase=$argv[1]??'';$case=$argv[2]??'';$site='/fixture/site';$stateFile='/fixture/state.json';
legacyCheck(in_array($phase,['prepare','apply','observe','retry','peek'],true),'Invalid phase');
if($phase==='prepare') {
    foreach(['app','config','extend','route','scripts','vendor','upgrade'] as $path)if(is_dir('/baseline/'.$path))legacyCopy('/baseline/'.$path,$site.'/'.$path);
    foreach(['composer.json','composer.lock','think'] as $path)if(is_file('/baseline/'.$path))legacyCopy('/baseline/'.$path,$site.'/'.$path);
    legacyWrite($site.'/config/install.lock','fixture installed');legacyWrite($site.'/upgrade/version.json','{"version":"1.0.390"}');
    legacyWrite($site.'/.env',"[DATABASE]\nHOSTNAME=mysql\nDATABASE=".getenv('PHP_DATABASE_DATABASE')."\nUSERNAME=root\nPASSWORD=\nPREFIX=la_\nHOSTPORT=3306\n[CACHE]\nDRIVER=file\n");
    legacyWrite($site.'/public/uploads/business.bin','KEEP_UPLOAD');legacyWrite($site.'/runtime/task-lease.json','{"lease":"existing-owner","attempts":2}');
    legacyWrite($site.'/public/pc/legacy-protected-artifact.txt','original 390 frontend');
    legacyWrite($site.'/oem/brand.json',json_encode(['schema_version'=>1,'name'=>'Fixture brand','slug'=>'fixture-brand','logo'=>'oem-assets/v1/logo.png']));
    legacyWrite($site.'/runtime/bin/ffmpeg/bin/ffmpeg',"#!/bin/sh\necho 'ffmpeg version fixture-site'\n");chmod($site.'/runtime/bin/ffmpeg/bin/ffmpeg',0755);
}
if(in_array($phase,['observe','peek'],true) && is_file($site.'/runtime-loader.php')) {require $site.'/runtime-loader.php';(new AigcRuntimeApp($site))->initialize();}
else {require $site.'/vendor/autoload.php';(new think\App($site.'/'))->initialize();}
set_exception_handler(static function(Throwable $error):void {fwrite(STDERR,(string)$error."\n");exit(1);});
use think\facade\Db;
use app\common\service\update\SystemPackageUpdateService;
use app\common\service\update\UpdateLicenseService;
use app\common\service\update\UpdateSourceClient;
use app\common\service\update\SystemUpdateProtocol;
use app\common\service\license\SignedLicenseProtocol;
legacyCheck(root_path()===$site.'/','Fixture escaped site root');legacyCheck(str_starts_with((string)config('database.connections.mysql.database'),'fixture_'),'Fixture escaped database');
request()->withServer(array_merge(request()->server(),['HTTP_HOST'=>'system.fixture','SERVER_NAME'=>'system.fixture']));
if($phase==='prepare') {
    legacyCheck(UpdateSourceClient::currentCoreVersion()==='1.0.390','Baseline is not genuine 390');
    legacyCheck(!class_exists('NeuronAI\\Agent\\Agent'),'Old vendor unexpectedly has new SDK');
    // Pure genuine-390 install: never pre-run historical app migrations to hide upgrade schema gaps.
    legacyCheck(Db::query("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_short_drama_generation_task' AND COLUMN_NAME='canvas_id'")[0]['n']==0,'390 fixture was pre-repaired');
    legacySeed('tenant',['id'=>901,'sn'=>'legacy901','name'=>'preserve-tenant','point_balance'=>'128.50','create_time'=>1]);
    legacySeed('user',['id'=>901,'tenant_id'=>901,'sn'=>901,'account'=>'legacy901','user_money'=>'77.25']);
    legacySeed('user_account_log',['tenant_id'=>901,'user_id'=>901,'sn'=>'fixture-ledger','change_amount'=>'2.50','left_amount'=>'77.25']);
    legacySeed('recharge_order',['tenant_id'=>901,'user_id'=>901,'sn'=>'fixture-order','order_amount'=>'3.00','pay_status'=>1]);
    legacySeed('aigc_short_drama_episode_task',['tenant_id'=>901,'user_id'=>901,'status'=>'running','heartbeat_at'=>123456,'attempt_number'=>2]);
    legacySeed('aigc_short_drama_generation_task',['tenant_id'=>901,'user_id'=>901,'status'=>'running','task_id'=>'customer-running-generation','tenant_cost_points'=>'6.25','user_charge_points'=>'9.75','request_json'=>'{"customer_request":"KEEP_RUNNING_TASK"}']);
    legacySeed('tenant_system_menu',['id'=>99001,'tenant_id'=>901,'name'=>'Customer custom menu','app_code'=>'','source'=>'custom','is_core'=>0,'perms'=>'fixture:custom','paths'=>'fixture/custom','create_time'=>1]);
    legacySeed('tenant_app',['tenant_id'=>901,'app_code'=>'aigc_music','version'=>'customer-purchased-version','buy_status'=>'paid','shelf_status'=>'off','enable_status'=>'disabled','expire_time'=>4294967295,'create_time'=>1]);
    $customerConfiguration=legacyCustomerConfiguration();
    $business=[];foreach(['tenant','user','user_account_log','recharge_order','aigc_short_drama_episode_task','aigc_short_drama_generation_task'] as $table)$business[$table]=Db::name($table)->order('id')->select()->toArray();
    $metadata=[];foreach(['app','app_version','tenant_app','app_api','app_frontend_entry','app_install','system_menu','tenant_system_menu','system_role_menu','tenant_system_role_menu','app_plan','membership_plan','membership_plan_app','tenant_package_app_plan'] as $table)$metadata[$table]=legacyMetadataRows($table);
    $key=openssl_pkey_new(['private_key_bits'=>2048]);$public=openssl_pkey_get_details($key)['key'];
    Db::name('update_source')->where('status',1)->update(['status'=>0]);
    Db::name('update_source')->insert(['name'=>'disposable legacy fixture','base_url'=>'http://fixture.invalid','online_base_url'=>'http://fixture.invalid','license_key'=>'fixture','online_license_key'=>'fixture','dev_mode'=>1,'public_key'=>$public,'status'=>1]);
    $license=new UpdateLicenseService();$machine=$license->machineCode();
    $payload=(object)['schema_version'=>3,'license_no'=>'LIC-legacy-fixture','version'=>1,'product_code'=>UpdateSourceClient::PRODUCT_CODE,'domains'=>[$machine['domain']],'machine_fingerprint_hash'=>$machine['machine_fingerprint_hash'],'license_type'=>'commercial','is_perpetual'=>true,'expires_at'=>0,'update_mode'=>'lifetime','issuer'=>(object)['copyright'=>[]]];
    openssl_sign(SignedLicenseProtocol::json($payload),$signature,$key,OPENSSL_ALGO_SHA256);$license->storeCertificate(SignedLicenseProtocol::json((object)['payload'=>$payload,'signature'=>base64_encode($signature),'algorithm'=>'RSA-SHA256']));
    $archive='/official.zip';
    if(in_array($case,['dependency-failure','committed-reply-failure'],true)) {
        $variant='/fixture/package-variant';$zip=new ZipArchive();legacyCheck($zip->open($archive)===true,'Variant zip open failed');$zip->extractTo($variant);$zip->close();
        if($case==='dependency-failure') {
            $autoload=$variant.'/bootstrap-payload/composer-runtime/vendor/autoload.php';legacyWrite($autoload,str_replace('<?php',"<?php\nthrow new \\RuntimeException('fixture dependency initialization failed');",file_get_contents($autoload)));
        } else {
            $runner=$variant.'/bootstrap-payload/files/scripts/composer-runtime-runner.php';$code=file_get_contents($runner);
            $needle='$result=(new \\app\\common\\service\\update\\SystemRuntimeBootstrapService())->install((int)$argv[2],(int)$argv[3],$context);';
            legacyCheck(substr_count($code,$needle)===1,'Committed-child fault insertion point changed');
            legacyWrite($runner,str_replace($needle,$needle."\n        file_put_contents('/fixture/committed-reply-lost.json',json_encode(\$result));exit(77);",$code));
        }
        legacySignature($variant.'/bootstrap-payload');legacySignature($variant);$archive='/fixture/variant.zip';$zip=new ZipArchive();$zip->open($archive,ZipArchive::CREATE|ZipArchive::OVERWRITE);
        foreach(legacyHashes($variant) as $path=>$hash)$zip->addFile($variant.'/'.$path,$path);legacyCheck($zip->close(),'Variant zip close failed');
    }
    $id=Db::name('update_package')->insertGetId(['package_id'=>'legacy-fixture-'.$case,'type'=>'system','source'=>'cloud','app_code'=>'','version'=>'1.0.391','format'=>'zip','local_path'=>$archive,'sha256'=>hash_file('sha256',$archive),'package_size'=>filesize($archive),'manifest_json'=>'{}','status'=>'downloaded','create_time'=>time(),'update_time'=>time()]);
    $context=$license->verifiedSiteContext();$now=time();$response=(object)['code'=>1,'msg'=>'fixture','server_time'=>$now,'data'=>['product_code'=>UpdateSourceClient::PRODUCT_CODE,'version'=>'1.0.391','target_version'=>'1.0.391','sha256'=>hash_file('sha256',$archive),'format'=>'zip','site_grant'=>['license_no'=>$context['license_no'],'license_version'=>$context['license_version'],'domains'=>[$context['domain']],'machine_fingerprint_hash'=>$context['machine_fingerprint_hash'],'version_id'=>391,'issued_at'=>$now,'expires_at'=>$now+3600]]];
    openssl_sign(SignedLicenseProtocol::json($response),$signature,$key,OPENSSL_ALGO_SHA256);$response->signature=base64_encode($signature);
    Db::name('system_package_grant')->insert(['package_id'=>$id,'context_key'=>SystemUpdateProtocol::contextKey($context,UpdateSourceClient::getSource()),'response_json'=>SignedLicenseProtocol::json($response),'create_time'=>$now,'update_time'=>$now]);
    if($case==='metadata-failure')Db::execute("CREATE TRIGGER fixture_metadata_failure BEFORE UPDATE ON la_update_task FOR EACH ROW BEGIN IF NEW.type='system' AND NEW.action='apply' AND NEW.status='success' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture final metadata failure'; END IF; END");
    if($case==='paused-complete-kill')Db::execute("CREATE TRIGGER fixture_complete_pause BEFORE UPDATE ON la_update_task FOR EACH ROW BEGIN IF NEW.type='system' AND NEW.action='apply' AND NEW.status='success' THEN SET @fixture_pause=GET_LOCK('fixture_complete_pause',120); SET @fixture_release=RELEASE_LOCK('fixture_complete_pause'); END IF; END");
    $protected=[];foreach(['.env','config/install.lock','public/uploads/business.bin','runtime/task-lease.json','oem/brand.json','runtime/bin/ffmpeg/bin/ffmpeg'] as $path)$protected[$path]=file_get_contents($site.'/'.$path);
    $state=['package_id'=>$id,'case'=>$case,'business'=>$business,'metadata'=>$metadata,'customer_configuration'=>$customerConfiguration,'custom_menu'=>Db::name('tenant_system_menu')->where('id',99001)->find(),'protected'=>$protected,'vendor'=>legacyHashes($site.'/vendor'),'code'=>legacyHashes($site.'/app'),'config'=>legacyHashes($site.'/config'),'project'=>file_get_contents($site.'/config/project.php'),'version'=>file_get_contents($site.'/upgrade/version.json'),'archive_sha256'=>hash_file('sha256',$archive)];
    legacyWrite($stateFile,json_encode($state,JSON_UNESCAPED_SLASHES));echo "PASS prepare genuine 390 source, old vendor, signed package/grant and six protected business tables\n";exit(0);
}
$state=json_decode(file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR);$id=$state['package_id'];
if($phase==='peek') {
    $task=Db::name('update_task')->where(['package_id'=>$id,'action'=>'apply'])->order('id','desc')->find();
    // currentCoreVersion reads the mutable, staged site marker; config identifies the code actually served by this interpreter.
    $version=(string)config('project.version');$committed=Db::name('update_package')->where('id',$id)->value('status')==='applied' && $task['status']==='success';
    legacyCheck(($version==='1.0.390' && !$committed) || ($version==='1.0.391' && $committed),'Concurrent entry mixed old runtime and committed new metadata');
    echo 'PASS concurrent fresh site entry: config='.$version.', site_marker='.UpdateSourceClient::currentCoreVersion().', committed='.($committed?'yes':'no').PHP_EOL;exit(0);
}
if($phase==='apply' || $phase==='retry') {
    $file=(new ReflectionClass(SystemPackageUpdateService::class))->getFileName();
    legacyCheck(hash_file('sha256',$file)===hash_file('sha256','/baseline/app/common/service/update/SystemPackageUpdateService.php'),'Not executing genuine 390 SystemPackageUpdateService');
    if($phase==='retry') {
        Db::execute('DROP TRIGGER fixture_metadata_failure');$state['retry_journals']=[];
        foreach(glob($site.'/.composer-runtime/journals/*.json') as $path){$journal=json_decode(file_get_contents($path),true);legacyCheck($journal['sql_state']==='done' && $journal['status']==='rolled_back','Failed update did not journal completed SQL before rollback');$state['retry_journals'][basename($path)]=$journal;}
        legacyCheck($state['retry_journals']!==[],'SQL retry journal missing');$state['retry_migrations']=Db::name('app_migration')->order('id')->select()->toArray();legacyWrite($stateFile,json_encode($state));
    }
    $service=new SystemPackageUpdateService();$preflight=$service->preflight($id);legacyCheck($preflight['passed'],'Old 390 preflight failed: '.json_encode($preflight['errors']));
    echo 'PASS genuine 390 preflight; PHP '.PHP_VERSION.'; unchanged old service class hash'.PHP_EOL;
    if($case==='cached-hook')class_exists(app\common\service\database\PcWechatMigration::class);
    ob_start();register_shutdown_function(static function():void {
        $reply=(string)ob_get_contents();legacyWrite('/fixture/apply-response.json',$reply);$json=json_decode($reply,true);
        if(is_array($json) && isset($json['code']) && $json['code']!==1)legacyWrite('/fixture/apply-error.txt',(string)($json['msg']??'Terminal failure without reason'));
    });
    try {$service->apply($id);legacyWrite('/fixture/apply-returned.txt','old apply unexpectedly resumed');throw new RuntimeException('Terminal bootstrap did not exit');}
    catch(Throwable $error){legacyWrite('/fixture/apply-error.txt',$error->getMessage());echo 'Observed real old updater failure: '.$error->getMessage().PHP_EOL;exit(0);}
}
$success=in_array($case,['success','committed-reply-failure','paused-complete-kill'],true) || ($argv[3]??'')==='after-retry';
foreach($state['business'] as $table=>$rows) {
    $actual=Db::name($table)->order('id')->select()->toArray();$fields=$rows?array_keys($rows[0]):[];
    $actual=array_map(static fn(array $row):array=>array_intersect_key($row,array_flip($fields)),$actual);
    legacyCheck($actual===$rows,'Existing business rows changed: '.$table);
}
foreach($state['customer_configuration'] as $table=>$rows) {
    $actual=Db::name($table)->whereIn('id',array_column($rows,'id'))->order('id')->select()->toArray();
    $fields=array_flip(array_keys($rows[0]));$actual=array_map(static fn(array $row):array=>array_intersect_key($row,$fields),$actual);
    legacyCheck($actual===$rows,'Customer channel/configuration/price/SKU changed: '.$table.'; before='.json_encode($rows).'; after='.json_encode($actual));
}
foreach($state['protected'] as $path=>$bytes)legacyCheck(file_get_contents($site.'/'.$path)===$bytes,'Protected site bytes changed: '.$path);
legacyCheck(legacyHashes($site.'/vendor')===$state['vendor'],'Original 390 vendor modified');
legacyCheck(Db::name('tenant_system_menu')->where('id',99001)->find()===$state['custom_menu'],'Customer custom menu changed');
$paid=array_values(array_filter($state['metadata']['tenant_app'],static fn(array $row):bool=>(int)$row['tenant_id']===901 && $row['app_code']==='aigc_music'));
legacyCheck(count($paid)===1 && Db::name('tenant_app')->where(['tenant_id'=>901,'app_code'=>'aigc_music'])->find()===$paid[0],'Purchased nondefault app authorization changed');
$package=Db::name('update_package')->where('id',$id)->find();$task=Db::name('update_task')->where(['package_id'=>$id,'action'=>'apply'])->order('id','desc')->find();
if($success) {
    $replyError=(string)@file_get_contents('/fixture/apply-error.txt');
    $uncertainKilledParent=$case==='paused-complete-kill' && str_contains($replyError,'更新子进程状态待核验');
    legacyCheck($replyError==='' || ($argv[3]??'')==='after-retry' || $uncertainKilledParent,'Real apply failed: '.$replyError);
    legacyCheck(!is_file('/fixture/apply-returned.txt'),'Old completion tail resumed');
    legacyCheck($package['status']==='applied' && $task['status']==='success','Terminal child did not persist outer success');
    legacyCheck(!is_file($site.'/.composer-runtime/active.json'),'Fresh entry did not settle the completed update journal');
    $pointer=json_decode(file_get_contents($site.'/.composer-runtime/current.json'),true,512,JSON_THROW_ON_ERROR);
    legacyCheck($pointer['generation']===basename($GLOBALS['AIGC_RUNTIME_GENERATION']),'Fresh entry did not serve the final current generation');
    legacyCheck(UpdateSourceClient::currentCoreVersion()==='1.0.391','Fresh runtime config did not switch to 391');
    legacyCheck(config('project.version')==='1.0.391','Fresh served generation config did not switch to 391');
    legacyCheck(json_decode(file_get_contents($site.'/upgrade/version.json'),true)['version']==='1.0.391','Site version marker did not switch');
    legacyCheck(class_exists('NeuronAI\\Agent\\Agent'),'New SDK missing after fresh bootstrap');
    $sdk=(new ReflectionClass('NeuronAI\\Agent\\Agent'))->getFileName();legacyCheck(str_contains($sdk,'/.composer-runtime/generations/'),'SDK not loaded from active immutable generation');
    legacyCheck(str_contains((new ReflectionClass(SystemPackageUpdateService::class))->getFileName(),'/.composer-runtime/generations/'),'New application code not pinned');
    legacyCheck(app\common\service\OemBrandService::read()['name']==='Fixture brand','Fresh loader lost site OEM');
    $ffmpeg=new ReflectionMethod(app\common\service\app\aigc_short_drama\AigcShortDramaService::class,'resolveFfmpegBinary');$ffmpeg->setAccessible(true);legacyCheck($ffmpeg->invoke(null)===$site.'/runtime/bin/ffmpeg/bin/ffmpeg','Fresh loader lost site-only FFmpeg');
    foreach(['asset','storyboard','generation_task','script_task','plan_version'] as $table) {
        $columns=['episode_id','episode_number'];if(in_array($table,['asset','generation_task'],true))$columns[]='canvas_id';
        // Actual SQL must succeed even when the table currently has no rows.
        Db::name('aigc_short_drama_'.$table)->field($columns)->limit(1)->select();
    }
    $generationList=app\common\service\app\aigc_short_drama\AigcShortDramaService::adminGenerationTaskLists(901,['keyword'=>'customer-running-generation']);
    legacyCheck($generationList['count']===1,'Real 391 generation-task API cannot read the preserved 390 task through canvas join');
    if($case==='committed-reply-failure')legacyCheck(is_file('/fixture/committed-reply-lost.json') && json_decode(file_get_contents('/fixture/committed-reply-lost.json'),true)['passed']===true,'Committed child did not reach the exit77 fault');
    echo 'PASS fresh observer: terminal 390 → 391, outer metadata, immutable code/new SDK, original vendor bytes and protected business/site state; SDK='.$sdk.PHP_EOL;
} else {
    $error=(string)@file_get_contents('/fixture/apply-error.txt');$expected=['php80'=>'PHP 8.2','metadata-failure'=>'fixture final metadata failure','dependency-failure'=>'fixture dependency initialization failed','cached-hook'=>'la_system_runtime_bootstrap_not_completed_391'][$case]??'';
    legacyCheck($expected!=='' && str_contains($error,$expected),'Wrong failure reason: '.$error);
    legacyCheck($package['status']==='apply_failed' && $task['status']==='failed','Old catch did not persist failure');
    legacyCheck(file_get_contents($site.'/config/project.php')===$state['project'],'Failure changed 390 project marker');
    legacyCheck(file_get_contents($site.'/upgrade/version.json')===$state['version'],'Failure changed 390 upgrade marker');
    legacyCheck(!class_exists('NeuronAI\\Agent\\Agent'),'Failed update activated new SDK');
    if(is_file($site.'/.composer-runtime/current.json')) {
        $pointer=json_decode(file_get_contents($site.'/.composer-runtime/current.json'),true);legacyCheck(str_starts_with($pointer['generation'],'baseline-'),'Failure left an active candidate generation');
        $oldGeneration=$site.'/.composer-runtime/generations/'.$pointer['generation'];$oldProject=require $oldGeneration.'/config/project.php';
        legacyCheck($oldProject['version']==='1.0.390','Failure pointer does not resolve to old 390 code/config');legacyCheck(!is_file($site.'/.composer-runtime/active.json'),'Failure left unresolved active journal');
    }
    $code=legacyHashes($site.'/app');$before=$state['code'];unset($code['common/service/database/PcWechatMigration.php'],$before['common/service/database/PcWechatMigration.php']);legacyCheck($code===$before,'Failure changed 390 executable code beyond the outer compatibility hook');
    legacyCheck(file_get_contents($site.'/public/pc/legacy-protected-artifact.txt')==='original 390 frontend','Failure did not restore old frontend');
    if($case==='metadata-failure')foreach($state['metadata'] as $table=>$rows) {
        $actual=legacyMetadataRows($table);
        // Additive DDL is intentionally journaled and retained; every preexisting metadata cell and row must roll back.
        if($rows){$fields=array_flip(array_keys($rows[0]));$actual=array_map(static fn(array $row):array=>array_intersect_key($row,$fields),$actual);}
        if($actual!==$rows){$difference=[];foreach($rows as $index=>$row)if(($actual[$index]??null)!==$row){$difference=['before'=>$row,'after'=>$actual[$index]??null];break;}legacyCheck(false,'Finalization metadata not rolled back: '.$table.' '.json_encode($difference,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));}
    }
    echo 'PASS failed observer: '.$case.', 390 files/marker/vendor, outer failure status, six business tables; outer PcWechat compatibility hook retained'.PHP_EOL;
}
if(($argv[3]??'')==='after-retry') {
    // Completed real migrations must remain journaled, rather than running again on retry.
    foreach($state['retry_journals'] as $name=>$before){$after=json_decode(file_get_contents($site.'/.composer-runtime/journals/'.$name),true);legacyCheck($after['sql_state']==='done' && $after['status']==='committed' && $before['digest']===$after['digest'],'Retry lost completed SQL journal binding');}
    $after=Db::name('app_migration')->order('id')->select()->toArray();$beforeById=array_column($state['retry_migrations'],null,'id');$baseline=config('runtime_migration_baseline')?:[];
    legacyCheck(array_diff(array_keys($beforeById),array_column($after,'id'))===[],'Retry deleted existing migration journal rows');
    foreach($after as $migration) {
        $before=$beforeById[$migration['id']]??null;
        if($before!==null) {
            if($before['status']==='success')legacyCheck($migration===$before,'Retry changed a previously completed app migration');
            else {
                legacyCheck($before['status']==='prepared' && $migration['status']==='success','Retry did not complete a prepared migration once');
                foreach(['scope','app_code','tenant_id','migration_key','create_time'] as $field)legacyCheck($migration[$field]===$before[$field],'Retry changed prepared migration identity: '.$field);
            }
        } else {
            $path='app/apps/'.$migration['app_code'].'/migrations/'.$migration['migration_key'];
            legacyCheck(isset($baseline[$path]) && $migration['status']==='success' && $migration['error']==='baseline-adopted:'.$baseline[$path],'Retry introduced a migration outside the signed historical baseline');
        }
    }
    echo "PASS same package retry after dropping only disposable metadata trigger\n";
}
