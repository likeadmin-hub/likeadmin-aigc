<?php
declare(strict_types=1);
/** Genuine391 old updater + actual392 bytes; only an isolated test-site signing authority. */
function release391Check(bool $value,string $label): void { if(!$value)throw new RuntimeException($label); }
function release391Write(string $path,string $value): void { if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);file_put_contents($path,$value); }
function release391Copy(string $source,string $target): void {
 if(is_dir($source)){if(!is_dir($target))mkdir($target,0755,true);foreach(new FilesystemIterator($source,FilesystemIterator::SKIP_DOTS) as $item){release391Check(!$item->isLink(),'Fixture source symlink');release391Copy($item->getPathname(),$target.'/'.$item->getFilename());}}
 else {if(!is_dir(dirname($target)))mkdir(dirname($target),0755,true);release391Check(copy($source,$target),'Fixture source copy failed');}
}
function release391Hashes(string $directory): array {
 $hashes=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS)) as $file){release391Check(!$file->isLink(),'Fixture state symlink');if($file->isFile())$hashes[substr($file->getPathname(),strlen($directory)+1)]=hash_file('sha256',$file->getPathname());}ksort($hashes);return $hashes;
}
function release391JsonHash($value): string { return hash('sha256',json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }
function release391Rows(string $table): array { return think\facade\Db::name($table)->order('id')->select()->toArray(); }
function release391Seed(string $table,array $values): void {
 $columns=think\facade\Db::query('SHOW COLUMNS FROM `la_'.$table.'`');$values=array_intersect_key($values,array_flip(array_column($columns,'Field')));
 foreach($columns as $column){if(array_key_exists($column['Field'],$values)||$column['Null']==='YES'||$column['Default']!==null||str_contains($column['Extra'],'auto_increment')||str_contains($column['Extra'],'GENERATED'))continue;$values[$column['Field']]=preg_match('/int|decimal|float|double/',$column['Type'])?0:($column['Type']==='json'?'{}':'');}
 think\facade\Db::name($table)->insert($values);
}
function release391Result(array $result): void { echo 'RESULT '.json_encode(['passed'=>true]+$result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL; }
release391Check(getenv('SYSTEM_SERVICE_DISPOSABLE')==='1','Disposable fixture required');
$phase=$argv[1]??'';$site='/fixture/site';$stateFile='/fixture/release391-state.json';
release391Check(in_array($phase,['prepare','fail-original','authorize-recovery','apply','observe','http','inspect'],true),'Invalid fixture phase');
set_exception_handler(static function(Throwable $error): void {fwrite(STDERR,'FAIL '.($GLOBALS['release391_phase']??'fixture').': '.$error->getMessage().PHP_EOL);exit(1);});$GLOBALS['release391_phase']=$phase;
if($phase==='http'){
 $raw=false;$status=0;for($i=0;$i<50;$i++){$curl=curl_init('http://127.0.0.1:9014/platformapi/config/getConfig');curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['Host: system.fixture']]);$raw=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);if($raw!==false)break;usleep(100000);}
 $data=json_decode((string)$raw,true);release391Check($status===200&&is_array($data)&&($data['code']??null)===1&&!empty($data['data']['is_oem']),'Actual private site HTTP did not serve OEM configuration');release391Result(['phase'=>'http','http_status'=>$status,'business_code'=>1,'oem'=>true]);exit;
}
if($phase==='prepare'){
 release391Check(!is_dir($site),'Fixture site must start new');
 foreach(['app','config','extend','route','scripts','vendor','upgrade','public'] as $path)if(is_dir('/baseline/'.$path))release391Copy('/baseline/'.$path,$site.'/'.$path);
 foreach(['composer.json','composer.lock','runtime-loader.php','think'] as $path)release391Copy('/baseline/'.$path,$site.'/'.$path);
 release391Write($site.'/config/install.lock','disposable fixture installed');
 release391Write($site.'/.env',"[DATABASE]\nHOSTNAME=mysql\nDATABASE=".getenv('PHP_DATABASE_DATABASE')."\nUSERNAME=root\nPASSWORD=\nPREFIX=la_\nHOSTPORT=3306\n[CACHE]\nDRIVER=file\n");
 release391Write($site.'/public/uploads/protected.bin','KEEP_PRIVATE_FIXTURE_UPLOAD');
 release391Write($site.'/runtime/task-lease.json','{"lease":"existing-fixture-owner","attempts":2}');
 release391Write($site.'/oem/brand.json','{"schema_version":1,"name":"Fixture brand","slug":"fixture-brand","logo":"oem-assets/v1/logo.png"}');
}
try { require $site.'/runtime-loader.php';(new AigcRuntimeApp($site))->initialize(); }
catch (Throwable $error) { fwrite(STDERR,'FAIL '.$phase.' initialization: '.$error->getMessage().PHP_EOL);exit(1); }
// ThinkPHP initializes its exception handler above. Restore explicit fixture
// failure reporting so no exit-zero framework response can mask a failed phase.
set_exception_handler(static function(Throwable $error): void {fwrite(STDERR,'FAIL '.($GLOBALS['release391_phase']??'fixture').': '.$error->getMessage().PHP_EOL);exit(1);});
use think\facade\Db;
use app\common\service\app\AppRegistryService;
use app\common\service\update\ComposerRuntimeUpdateService;
use app\common\service\update\SystemPackageUpdateService;
use app\common\service\update\UpdateLicenseService;
use app\common\service\update\UpdateSourceClient;
use app\common\service\update\SystemUpdateProtocol;
use app\common\service\license\SignedLicenseProtocol;
release391Check(root_path()===$site.'/'&&(string)config('database.connections.mysql.hostname')==='mysql'&&str_starts_with((string)config('database.connections.mysql.database'),'fixture_'),'Fixture escaped private site/database');
request()->withServer(array_merge(request()->server(),['HTTP_HOST'=>'system.fixture','SERVER_NAME'=>'system.fixture']));
$zip=new ZipArchive();release391Check($zip->open('/release392.zip')===true,'Actual392 ZIP cannot open');$manifest=json_decode($zip->getFromName('update.json'),true,512,JSON_THROW_ON_ERROR);$coverage=json_decode($zip->getFromName('release-change-manifest.json'),true,512,JSON_THROW_ON_ERROR);$zip->close();
release391Check(($manifest['base_version']??'')==='1.0.391'&&($manifest['target_version']??'')==='1.0.392'&&($coverage['base_ref']??'')==='a99837c01d9e78ced1e7ac56bf872f509c57ea6d','Actual package baseline/span differs');
$migrations=$coverage['migrations'];$appMigrations=array_values(array_filter($migrations,fn($row)=>$row['execution_owner']==='app/runtime'));
release391Check(count($migrations)===13&&count($appMigrations)===10&&count(array_filter($migrations,fn($row)=>$row['execution_owner']==='system'))===3,'Actual package migration ownership differs');
if($phase==='prepare'){
 release391Check(UpdateSourceClient::currentCoreVersion()==='1.0.391'&&config('project.version')==='1.0.391','Old station is not391');
 release391Check(hash_file('sha256',(new ReflectionClass(SystemPackageUpdateService::class))->getFileName())==='3857377ab20a5806c280dad2ace544fe96f0e2be493ae953c291825dcba32844','Old service is not genuine391');
 // These are real old-file executions on a fresh391 database, not manufactured journal rows.
 $oldApps=array_unique(array_merge(app\common\service\app\AppAccessService::DEFAULT_APP_CODES,['image_human']));$installed=[];
 foreach(getenv('RELEASE391_NO_HISTORY')==='1'?[]:$oldApps as $code){if(!is_file(AppRegistryService::manifestPath($code)))continue;AppRegistryService::installFromLocal($code,'1.0.391');$installed[]=$code;}
 foreach($appMigrations as $row)release391Check(Db::name('app_migration')->where(['app_code'=>$row['app_code'],'migration_key'=>basename($row['package_path'])])->count()===0,'A new migration was pre-registered');
 release391Check(Db::query("SELECT DATA_TYPE AS data_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_video_config' AND COLUMN_NAME='config_json'")[0]['data_type']==='text','Genuine391 video column was pre-widened');
 release391Check((int)Db::query("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME LIKE 'la_open_platform_%' OR TABLE_NAME IN ('la_user_asset_labels','la_user_asset_state'))")[0]['n']===0,'New feature schema was pre-installed');
 release391Seed('tenant',['id'=>901,'sn'=>'release901','name'=>'Protected fixture tenant','point_balance'=>'128.50','create_time'=>1]);
 release391Seed('user',['id'=>901,'tenant_id'=>901,'sn'=>901,'account'=>'release901','user_money'=>'77.25']);
 release391Seed('user_account_log',['tenant_id'=>901,'user_id'=>901,'sn'=>'fixture-ledger','change_amount'=>'2.50','left_amount'=>'77.25']);
 release391Seed('recharge_order',['tenant_id'=>901,'user_id'=>901,'sn'=>'fixture-order','order_amount'=>'3.00','pay_status'=>1]);
 release391Seed('aigc_short_drama_episode_task',['tenant_id'=>901,'user_id'=>901,'status'=>'running','heartbeat_at'=>123456,'attempt_number'=>2]);
 release391Seed('aigc_short_drama_generation_task',['tenant_id'=>901,'user_id'=>901,'status'=>'running','task_id'=>'preserved-release-task','tenant_cost_points'=>'6.25','user_charge_points'=>'9.75','request_json'=>'{"owned_request":"KEEP_EXISTING_TASK"}']);
 // The full391 installer does not eagerly seed every app configuration/channel.
 // Create legitimate old-schema customer records for preservation assertions;
 // this never creates new schema or manufactures migration journal entries.
 if(!Db::name('aigc_video_config')->where('tenant_id',0)->find())release391Seed('aigc_video_config',['tenant_id'=>0,'config_json'=>'{}','create_time'=>1]);
 $video=Db::name('aigc_video_config')->where('tenant_id',0)->order('id')->find();release391Check($video!==null,'Old video configuration missing');
 Db::name('aigc_video_config')->where('id',$video['id'])->update(['config_json'=>'{"customer_config":"KEEP_VIDEO_CONFIG","effects_enabled":false}','update_time'=>123456]);
 release391Seed('tenant_system_menu',['id'=>99001,'tenant_id'=>901,'name'=>'Owned custom menu','source'=>'custom','is_core'=>0,'perms'=>'fixture:custom','paths'=>'fixture/custom','create_time'=>1]);
 foreach(['aigc_image','aigc_video','aigc_digital_human'] as $code){
  if(!Db::name($code.'_channel')->where('tenant_id',0)->find())release391Seed($code.'_channel',['tenant_id'=>0,'provider'=>'fixture-customer','model'=>'fixture-owned-model','status'=>0,'create_time'=>1]);
  $row=Db::name($code.'_channel')->where('tenant_id',0)->order('id')->find();release391Check($row!==null,'Old channel missing: '.$code);Db::name($code.'_channel')->where('id',$row['id'])->update(['provider'=>'fixture-customer','model'=>'fixture-owned-model','config_json'=>'{"owned_endpoint":"https://fixture.invalid/v1","owned":"KEEP_CHANNEL"}','status'=>0,'update_time'=>123456]);
  if(!Db::name($code.'_channel_spec')->where('tenant_id',0)->find())release391Seed($code.'_channel_spec',['tenant_id'=>0,'channel_id'=>$row['id'],'status'=>0,'create_time'=>1]);
  $price=Db::name($code.'_channel_spec')->where('tenant_id',0)->order('id')->find();release391Check($price!==null,'Old price missing: '.$code);Db::name($code.'_channel_spec')->where('id',$price['id'])->update(['platform_unit_cost'=>'7.25','tenant_unit_price'=>'19.75','provider_params_json'=>'{"owned_price":"KEEP_PRICE"}','status'=>0,'update_time'=>123456]);
 }
 $protectedRows=[];foreach(['tenant','user','user_account_log','recharge_order','aigc_short_drama_episode_task','aigc_short_drama_generation_task','aigc_video_config','aigc_image_channel','aigc_image_channel_spec','aigc_video_channel','aigc_video_channel_spec','aigc_digital_human_channel','aigc_digital_human_channel_spec'] as $table)$protectedRows[$table]=release391Rows($table);
 // Local fixture authority. This signing key never leaves this process and cannot authorize online releases.
 $key=openssl_pkey_new(['private_key_bits'=>2048]);release391Check($key!==false,'Test signing key creation failed');$public=openssl_pkey_get_details($key)['key'];
 Db::name('update_source')->where('status',1)->update(['status'=>0]);Db::name('update_source')->insert(['name'=>'private release fixture authority','base_url'=>'http://fixture.invalid','online_base_url'=>'http://fixture.invalid','license_key'=>'fixture-only','online_license_key'=>'fixture-only','dev_mode'=>1,'public_key'=>$public,'status'=>1]);
 $license=new UpdateLicenseService();$machine=$license->machineCode();$payload=(object)['schema_version'=>3,'license_no'=>'LIC-private-release-fixture','version'=>1,'product_code'=>UpdateSourceClient::PRODUCT_CODE,'domains'=>[$machine['domain']],'machine_fingerprint_hash'=>$machine['machine_fingerprint_hash'],'license_type'=>'commercial','is_perpetual'=>true,'expires_at'=>0,'update_mode'=>'lifetime','issuer'=>(object)['copyright'=>[]]];
 release391Check(openssl_sign(SignedLicenseProtocol::json($payload),$signature,$key,OPENSSL_ALGO_SHA256),'Test license signing failed');$license->storeCertificate(SignedLicenseProtocol::json((object)['payload'=>$payload,'signature'=>base64_encode($signature),'algorithm'=>'RSA-SHA256']));
 $archive='/release392.zip';$sha=hash_file('sha256',$archive);$now=time();$id=Db::name('update_package')->insertGetId(['package_id'=>'private-actual392-fixture','type'=>'system','source'=>'cloud','app_code'=>'','version'=>'1.0.392','format'=>'zip','local_path'=>$archive,'sha256'=>$sha,'package_size'=>filesize($archive),'manifest_json'=>'{}','status'=>'downloaded','create_time'=>$now,'update_time'=>$now]);
 $context=$license->verifiedSiteContext();$response=(object)['code'=>1,'msg'=>'isolated test grant','server_time'=>$now,'data'=>['product_code'=>UpdateSourceClient::PRODUCT_CODE,'version'=>'1.0.392','target_version'=>'1.0.392','sha256'=>$sha,'format'=>'zip','site_grant'=>['license_no'=>$context['license_no'],'license_version'=>$context['license_version'],'domains'=>[$context['domain']],'machine_fingerprint_hash'=>$context['machine_fingerprint_hash'],'version_id'=>392,'issued_at'=>$now,'expires_at'=>$now+3600]]];
 release391Check(openssl_sign(SignedLicenseProtocol::json($response),$signature,$key,OPENSSL_ALGO_SHA256),'Test package signing failed');$response->signature=base64_encode($signature);Db::name('system_package_grant')->insert(['package_id'=>$id,'context_key'=>SystemUpdateProtocol::contextKey($context,UpdateSourceClient::getSource()),'response_json'=>SignedLicenseProtocol::json($response),'create_time'=>$now,'update_time'=>$now]);
 $brokenId=0;
 if(is_file('/broken392.zip')){
  $brokenSha=hash_file('sha256','/broken392.zip');
  $brokenId=Db::name('update_package')->insertGetId(['package_id'=>'private-original392-fixture','type'=>'system','source'=>'cloud','app_code'=>'','version'=>'1.0.392','format'=>'zip','local_path'=>'/broken392.zip','sha256'=>$brokenSha,'package_size'=>filesize('/broken392.zip'),'manifest_json'=>'{}','status'=>'downloaded','create_time'=>$now,'update_time'=>$now]);
  $response->data['sha256']=$brokenSha;unset($response->signature);openssl_sign(SignedLicenseProtocol::json($response),$signature,$key,OPENSSL_ALGO_SHA256);$response->signature=base64_encode($signature);
  Db::name('system_package_grant')->insert(['package_id'=>$brokenId,'context_key'=>SystemUpdateProtocol::contextKey($context,UpdateSourceClient::getSource()),'response_json'=>SignedLicenseProtocol::json($response),'create_time'=>$now,'update_time'=>$now]);
 }
 (new ComposerRuntimeUpdateService($site))->ensureBaseline();$pointer=json_decode(file_get_contents($site.'/.composer-runtime/current.json'),true,512,JSON_THROW_ON_ERROR);
 $protectedFiles=[];foreach(['.env','config/install.lock','public/uploads/protected.bin','runtime/task-lease.json','oem/brand.json'] as $file)$protectedFiles[$file]=hash_file('sha256',$site.'/'.$file);
 $canonical=[];foreach(['app','route','extend','vendor'] as $directory)$canonical[$directory]=release391JsonHash(release391Hashes($site.'/'.$directory));
 release391Write($stateFile,json_encode(['package_id'=>$id,'broken_package_id'=>$brokenId,'archive_sha256'=>$sha,'pointer'=>$pointer,'protected_files'=>$protectedFiles,'canonical'=>$canonical,'protected_rows'=>$protectedRows,'old_migrations'=>release391Rows('app_migration'),'custom_menu'=>Db::name('tenant_system_menu')->where('id',99001)->find(),'video_config_sha256'=>release391JsonHash(release391Rows('aigc_video_config'))],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
 release391Result(['phase'=>'prepare','core'=>'1.0.391','old_apps_installed'=>$installed,'new_migrations_pre_registered'=>0,'archive_sha256'=>$sha,'fixture_authority'=>true]);exit;
}
$state=json_decode(file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR);$id=(int)$state['package_id'];
if($phase==='fail-original'){
 release391Check(!is_file($site.'/config/runtime_migration_baseline.php')&&Db::name('app_migration')->where('app_code','aigc_image')->count()===0,'Failure fixture already has history');
 try{(new SystemPackageUpdateService())->apply((int)$state['broken_package_id']);throw new LogicException('Original392 did not reproduce failure');}
 catch(RuntimeException $e){release391Check(str_contains($e->getMessage(),'新迁移不支持会话变量或动态 SQL'),'Wrong original failure: '.$e->getMessage());}
 $journals=glob($site.'/.composer-runtime/journals/*.json');release391Check(count($journals)===1,'Original journal missing');$j=json_decode(file_get_contents($journals[0]),true);
 release391Check($j['status']==='rolled_back'&&$j['sql_state']==='running','Original uncertain SQL state was changed');
 $state['original_digest']=$j['digest'];release391Write($stateFile,json_encode($state));release391Result(['phase'=>$phase,'original_journal'=>$j['digest'],'historical_image_rows'=>0]);exit;
}
if($phase==='authorize-recovery'){
 $service=new SystemPackageUpdateService();release391Check($service->preflight($id)['passed'],'Replacement preflight failed');
 $package=Db::name('update_package')->where('id',$id)->find();$extract=$package['extract_path'];
 require_once $extract.'/files/app/common/service/update/System392RecoveryService.php';
 $class=app\common\service\update\System392RecoveryService::class;
 $proof=$class::authorize($id,$state['original_digest'],false);release391Check(count($proof['system_sql'])===3,'Not all prior system SQL was verified');
 // A missing previously completed table must never be accepted as resumable.
 Db::execute('RENAME TABLE la_user_asset_labels TO la_fixture_hidden_labels');
 try{$class::authorize($id,$state['original_digest'],false);throw new LogicException('Partial SQL state was accepted');}catch(RuntimeException $e){}finally{Db::execute('RENAME TABLE la_fixture_hidden_labels TO la_user_asset_labels');}
 Db::execute('ALTER TABLE la_user_asset_state MODIFY id bigint unsigned NOT NULL');
 try{$class::authorize($id,$state['original_digest'],false);throw new LogicException('Missing auto increment was accepted');}catch(RuntimeException $e){}finally{Db::execute('ALTER TABLE la_user_asset_state MODIFY id bigint unsigned NOT NULL AUTO_INCREMENT');}
 Db::execute('ALTER TABLE la_user_asset_state MODIFY favorite tinyint unsigned NULL DEFAULT 0');
 try{$class::authorize($id,$state['original_digest'],false);throw new LogicException('Changed default was accepted');}catch(RuntimeException $e){}finally{Db::execute('ALTER TABLE la_user_asset_state MODIFY favorite tinyint unsigned NULL DEFAULT NULL');}
 $originalTask=Db::name('update_task')->where('package_id',(int)$state['broken_package_id'])->where('action','apply')->find();$error=$originalTask['error'];
 Db::name('update_task')->where('id',$originalTask['id'])->update(['error'=>'Unknown database interruption']);
 try{$class::authorize($id,$state['original_digest'],false);throw new LogicException('Unknown original error was accepted');}catch(RuntimeException $e){}finally{Db::name('update_task')->where('id',$originalTask['id'])->update(['error'=>$error]);}
 $class::authorize($id,$state['original_digest'],true);
 release391Result(['phase'=>$phase,'system_sql_verified'=>3,'partial_schema_rejected'=>true,'missing_autoincrement_rejected'=>true,'changed_default_rejected'=>true,'unknown_error_rejected'=>true]);exit;
}
if($phase==='inspect'){
 $package=Db::name('update_package')->where('id',$id)->find();$tasks=Db::name('update_task')->where(['package_id'=>$id,'action'=>'apply'])->field('id,status,error')->select()->toArray();
 release391Result(['phase'=>'inspect','package_status'=>$package['status']??null,'package_error'=>$package['error']??null,'apply_tasks'=>$tasks,'pointer'=>json_decode((string)file_get_contents($site.'/.composer-runtime/current.json'),true),'active_journal_exists'=>is_file($site.'/.composer-runtime/active.json')]);exit;
}
if($phase==='apply'){
 release391Check(config('project.version')==='1.0.391'&&hash_file('sha256',(new ReflectionClass(SystemPackageUpdateService::class))->getFileName())==='3857377ab20a5806c280dad2ace544fe96f0e2be493ae953c291825dcba32844','Apply is not running genuine391 old service');
 $service=new SystemPackageUpdateService();$preflight=$service->preflight($id);release391Check($preflight['passed'],'Genuine391 preflight rejected actual392: '.json_encode($preflight['errors'],JSON_UNESCAPED_UNICODE));$parent=getmypid();$result=$service->apply($id);
 release391Check(($result['version']??null)==='1.0.392','Genuine391 apply did not return actual392');release391Result(['phase'=>'apply','old_service_sha256'=>'3857377ab20a5806c280dad2ace544fe96f0e2be493ae953c291825dcba32844','preflight'=>true,'parent_pid'=>$parent,'version'=>$result['version']]);exit;
}
release391Check($phase==='observe','Unknown observation');
release391Check(config('project.version')==='1.0.392'&&UpdateSourceClient::currentCoreVersion()==='1.0.392','Fresh observer did not switch runtime/marker');
$pointer=json_decode(file_get_contents($site.'/.composer-runtime/current.json'),true,512,JSON_THROW_ON_ERROR);release391Check($pointer['generation']!==$state['pointer']['generation']&&basename($GLOBALS['AIGC_RUNTIME_GENERATION'])===$pointer['generation'],'New generation pointer is not serving');
release391Check(str_contains((new ReflectionClass(SystemPackageUpdateService::class))->getFileName(),'/.composer-runtime/generations/'),'Observer loaded canonical old service');
release391Check(json_decode(file_get_contents($site.'/upgrade/version.json'),true)['version']==='1.0.392','Upgrade marker wrong');
release391Check(class_exists('NeuronAI\\Agent\\Agent')&&str_contains((new ReflectionClass('NeuronAI\\Agent\\Agent'))->getFileName(),'/.composer-runtime/generations/'),'SDK is not served by final generation');
$package=Db::name('update_package')->where('id',$id)->find();$tasks=Db::name('update_task')->where(['package_id'=>$id,'action'=>'apply'])->select()->toArray();release391Check($package['status']==='applied'&&count($tasks)===1&&$tasks[0]['status']==='success','Apply owner/task/package is not unique committed success');
$journals=glob($site.'/.composer-runtime/journals/*.json');release391Check(count($journals)===(empty($state['broken_package_id'])?1:2)&&!is_file($site.'/.composer-runtime/active.json'),'Runtime writer journal is not unique finalized');$journal=null;foreach($journals as $jp){$j=json_decode(file_get_contents($jp),true,512,JSON_THROW_ON_ERROR);if(($j['status']??'')==='committed')$journal=$j;else release391Check($j['digest']===$state['original_digest']&&$j['status']==='rolled_back'&&$j['sql_state']==='running','Original uncertain journal was rewritten');}release391Check(is_array($journal),'Committed journal missing');release391Check($journal['status']==='committed'&&$journal['sql_state']==='done'&&$journal['generation']===$pointer['generation'],'Final writer journal does not match served generation');
foreach($state['protected_files'] as $file=>$hash)release391Check(hash_file('sha256',$site.'/'.$file)===$hash,'Protected fixture file changed: '.$file);
foreach($state['canonical'] as $directory=>$hash)release391Check(release391JsonHash(release391Hashes($site.'/'.$directory))===$hash,'Canonical code/vendor changed: '.$directory);
foreach($state['protected_rows'] as $table=>$rows)release391Check(release391Rows($table)===$rows,'Protected business/price/config records changed: '.$table);
release391Check(Db::name('tenant_system_menu')->where('id',99001)->find()===$state['custom_menu'],'Customer menu changed');
foreach($state['old_migrations'] as $row)release391Check(Db::name('app_migration')->where('id',$row['id'])->find()===$row,'Old successful migration journal was changed');
$newRows=[];foreach($appMigrations as $source){$key=basename($source['package_path']);$rows=Db::name('app_migration')->where(['scope'=>'platform','tenant_id'=>0,'app_code'=>$source['app_code'],'migration_key'=>$key])->select()->toArray();release391Check(count($rows)===1&&$rows[0]['status']==='success','New migration is not genuinely finalized once: '.$key);$actual=base_path().'apps/'.$source['app_code'].'/migrations/'.$key;release391Check(hash_file('sha256',$actual)===$source['sha256'],'Served app migration source differs: '.$key);$newRows[]=$rows[0];}
release391Check(Db::query("SELECT DATA_TYPE AS data_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_video_config' AND COLUMN_NAME='config_json'")[0]['data_type']==='mediumtext','Actual capacity DDL not applied');
release391Check((int)Db::query("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('la_user_asset_labels','la_user_asset_state')")[0]['n']===2,'Workspace DDL missing');
release391Check((int)Db::query("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_file' AND COLUMN_NAME='upload_origin'")[0]['n']===1,'Workspace upload provenance column missing');
release391Check((int)Db::query("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'la_open_platform_%'")[0]['n']===9,'OpenPlatform schema incomplete');
release391Check(Db::name('app')->where(['code'=>'open_platform','status'=>'installed','is_builtin'=>1])->count()===1,'Builtin OpenPlatform metadata missing');
$apis=json_decode(file_get_contents(base_path().'apps/open_platform/api_schema.json'),true,512,JSON_THROW_ON_ERROR)['apis'];release391Check(Db::name('app_api')->where('app_code','open_platform')->count()===count($apis),'OpenPlatform API metadata incomplete');
release391Check(Db::name('app_frontend_entry')->where('app_code','open_platform')->count()===3,'OpenPlatform frontend metadata incomplete');
$docs=Db::name('open_platform_document')->where(['tenant_id'=>0,'status'=>'published'])->order('id')->select()->toArray();release391Check(count($docs)===7,'OpenPlatform public guide seed coverage wrong');
foreach(['quickstart','auth-limits','tasks-billing','assets-webhooks','model-sdk','image-tools','music-tools'] as $slug){$row=Db::name('open_platform_document')->where(['tenant_id'=>0,'slug'=>'open-platform-'.$slug,'status'=>'published'])->find();release391Check($row&&trim($row['content'])===trim(file_get_contents(base_path().'apps/open_platform/docs/'.$slug.'.md')),'Public guide source differs: '.$slug);}
foreach(['open_platform_key','open_platform_contract','open_platform_request','open_platform_setting'] as $table)release391Check(Db::name($table)->count()===0,'Upgrade created live credentials/contracts/paid requests or enabled tenant policy: '.$table);
release391Result(['phase'=>'observe','core'=>'1.0.392','generation'=>$pointer['generation'],'app_migrations_completed'=>count($newRows),'system_migrations_observed'=>3,'protected_rows_sha256'=>release391JsonHash($state['protected_rows']),'migration_rows_sha256'=>release391JsonHash(release391Rows('app_migration')),'document_rows_sha256'=>release391JsonHash(release391Rows('open_platform_document')),'guide_count'=>count($docs),'apply_tasks'=>1,'committed_runtime_journals'=>1,'paid_calls'=>0]);
