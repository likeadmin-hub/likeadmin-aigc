<?php
declare(strict_types=1);
/** Genuine392 old updater + actual393 bytes; only an isolated test-site signing authority. */
function release392Check(bool $value,string $label): void { if(!$value)throw new RuntimeException($label); }
function release392Write(string $path,string $value): void { if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);file_put_contents($path,$value); }
function release392Copy(string $source,string $target): void {
 if(is_dir($source)){if(!is_dir($target))mkdir($target,0755,true);foreach(new FilesystemIterator($source,FilesystemIterator::SKIP_DOTS) as $item){release392Check(!$item->isLink(),'Fixture source symlink');release392Copy($item->getPathname(),$target.'/'.$item->getFilename());}}
 else {if(!is_dir(dirname($target)))mkdir(dirname($target),0755,true);release392Check(copy($source,$target),'Fixture source copy failed');}
}
function release392Hashes(string $directory): array {
 $hashes=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS)) as $file){release392Check(!$file->isLink(),'Fixture state symlink');if($file->isFile())$hashes[substr($file->getPathname(),strlen($directory)+1)]=hash_file('sha256',$file->getPathname());}ksort($hashes);return $hashes;
}
function release392JsonHash($value): string { return hash('sha256',json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }
function release392Rows(string $table): array { return think\facade\Db::name($table)->order('id')->select()->toArray(); }
function release392Seed(string $table,array $values): void {
 $columns=think\facade\Db::query('SHOW COLUMNS FROM `la_'.$table.'`');$values=array_intersect_key($values,array_flip(array_column($columns,'Field')));
 foreach($columns as $column){if(array_key_exists($column['Field'],$values)||$column['Null']==='YES'||$column['Default']!==null||str_contains($column['Extra'],'auto_increment')||str_contains($column['Extra'],'GENERATED'))continue;$values[$column['Field']]=preg_match('/int|decimal|float|double/',$column['Type'])?0:($column['Type']==='json'?'{}':'');}
 think\facade\Db::name($table)->insert($values);
}
function release392Result(array $result): void { echo 'RESULT '.json_encode(['passed'=>true]+$result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL; }
release392Check(getenv('SYSTEM_SERVICE_DISPOSABLE')==='1','Disposable fixture required');
$phase=$argv[1]??'';$site='/fixture/site';$stateFile='/fixture/release392-state.json';
release392Check(in_array($phase,['prepare','apply','observe','http','inspect'],true),'Invalid fixture phase');
set_exception_handler(static function(Throwable $error): void {fwrite(STDERR,'FAIL '.($GLOBALS['release392_phase']??'fixture').': '.$error->getMessage().PHP_EOL);exit(1);});$GLOBALS['release392_phase']=$phase;
if($phase==='http'){
 $raw=false;$status=0;for($i=0;$i<50;$i++){$curl=curl_init('http://127.0.0.1:9014/platformapi/config/getConfig');curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['Host: system.fixture']]);$raw=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);if($raw!==false)break;usleep(100000);}
 $data=json_decode((string)$raw,true);release392Check($status===200&&is_array($data)&&($data['code']??null)===1&&!empty($data['data']['is_oem']),'Actual private site HTTP did not serve OEM configuration');release392Result(['phase'=>'http','http_status'=>$status,'business_code'=>1,'oem'=>true]);exit;
}
if($phase==='prepare'){
 release392Check(!is_dir($site),'Fixture site must start new');
 foreach(['app','config','extend','route','scripts','vendor','upgrade','public'] as $path)if(is_dir('/baseline/'.$path))release392Copy('/baseline/'.$path,$site.'/'.$path);
 foreach(['composer.json','composer.lock','runtime-loader.php','think'] as $path)release392Copy('/baseline/'.$path,$site.'/'.$path);
 release392Write($site.'/config/install.lock','disposable fixture installed');
 release392Write($site.'/.env',"[DATABASE]\nHOSTNAME=mysql\nDATABASE=".getenv('PHP_DATABASE_DATABASE')."\nUSERNAME=root\nPASSWORD=\nPREFIX=la_\nHOSTPORT=3306\n[CACHE]\nDRIVER=file\n");
 release392Write($site.'/public/uploads/protected.bin','KEEP_PRIVATE_FIXTURE_UPLOAD');
 release392Write($site.'/runtime/task-lease.json','{"lease":"existing-fixture-owner","attempts":2}');
 release392Write($site.'/oem/brand.json','{"schema_version":1,"name":"Fixture brand","slug":"fixture-brand","logo":"oem-assets/v1/logo.png"}');
}
try { require $site.'/runtime-loader.php';(new AigcRuntimeApp($site))->initialize(); }
catch (Throwable $error) { fwrite(STDERR,'FAIL '.$phase.' initialization: '.$error->getMessage().PHP_EOL);exit(1); }
// ThinkPHP initializes its exception handler above. Restore explicit fixture
// failure reporting so no exit-zero framework response can mask a failed phase.
set_exception_handler(static function(Throwable $error): void {fwrite(STDERR,'FAIL '.($GLOBALS['release392_phase']??'fixture').': '.$error->getMessage().PHP_EOL);exit(1);});
use think\facade\Db;
use app\common\service\app\AppRegistryService;
use app\common\service\update\ComposerRuntimeUpdateService;
use app\common\service\update\SystemPackageUpdateService;
use app\common\service\update\UpdateLicenseService;
use app\common\service\update\UpdateSourceClient;
use app\common\service\update\SystemUpdateProtocol;
use app\common\service\license\SignedLicenseProtocol;
release392Check(root_path()===$site.'/'&&(string)config('database.connections.mysql.hostname')==='mysql'&&str_starts_with((string)config('database.connections.mysql.database'),'fixture_'),'Fixture escaped private site/database');
request()->withServer(array_merge(request()->server(),['HTTP_HOST'=>'system.fixture','SERVER_NAME'=>'system.fixture']));
$zip=new ZipArchive();release392Check($zip->open('/release393.zip')===true,'Actual393 ZIP cannot open');$manifest=json_decode($zip->getFromName('update.json'),true,512,JSON_THROW_ON_ERROR);$coverage=json_decode($zip->getFromName('release-change-manifest.json'),true,512,JSON_THROW_ON_ERROR);$zip->close();
release392Check(($manifest['base_version']??'')==='1.0.392'&&($manifest['target_version']??'')==='1.0.393'&&($coverage['base_ref']??'')==='d7eef36d4cd6a0be7e7569ae07776ed42ad703dc','Actual package baseline/span differs');
$migrations=$coverage['migrations'];$appMigrations=array_values(array_filter($migrations,fn($row)=>$row['execution_owner']==='app/runtime'));
release392Check(count($appMigrations)>=7,'Actual package lacks required app migration ownership');
$priceApps=['aigc_local_redraw','aigc_one_click_cleanup','aigc_product_suite','aigc_product_multi_angle','aigc_fashion_lookbook'];
if($phase==='prepare'){
 release392Check(UpdateSourceClient::currentCoreVersion()==='1.0.392'&&config('project.version')==='1.0.392','Old station is not392');
 release392Check(hash_file('sha256',(new ReflectionClass(SystemPackageUpdateService::class))->getFileName())==='58541a437ab39ce556011c8f375a23aef0a4f168229e694693478a45c4a92ef3','Old service is not genuine392');
 // These are real old-file executions on a fresh392 database, not manufactured journal rows.
 // Pic's old manifest forbids a new392 install; its explicit old compatibility version
 // models an existing390 purchase carried into392, without changing core/updater bytes.
 $oldApps=array_unique(array_merge(app\common\service\app\AppAccessService::DEFAULT_APP_CODES,array_merge(['image_human','aigc_pic_lipsync'],$priceApps)));$installed=[];
 foreach($oldApps as $code){
  if(!is_file(AppRegistryService::manifestPath($code)))continue;
  $oldManifest=AppRegistryService::installFromLocal($code,$code==='aigc_pic_lipsync'?'1.0.390':'1.0.392');$installed[]=$code;
  // Its historical app package shipped core runtime assets. The subsequent392
  // system release already superseded these bytes: restore the genuine392 Git
  // files so this setup cannot accidentally downgrade the real old updater.
  if($code==='aigc_pic_lipsync')foreach($oldManifest['public_assets']??[] as $asset){
   $target=$asset['target']??'';
   if(str_starts_with($target,'app/')){release392Check(is_file('/baseline/'.$target),'Historical runtime target missing from392');release392Copy('/baseline/'.$target,$site.'/'.$target);}
  }
 }
 release392Check(hash_file('sha256',$site.'/app/common/service/app/AppRegistryService.php')===hash_file('sha256','/baseline/app/common/service/app/AppRegistryService.php'),'Fixture app setup downgraded392 registry');
 foreach($appMigrations as $row)release392Check(Db::name('app_migration')->where(['app_code'=>$row['app_code'],'migration_key'=>basename($row['package_path'])])->count()===0,'A new migration was pre-registered');
 release392Check(Db::query("SELECT DATA_TYPE AS data_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_video_config' AND COLUMN_NAME='config_json'")[0]['data_type']==='mediumtext','Genuine392 video schema differs');
 release392Check((int)Db::query("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME LIKE 'la_open_platform_%' OR TABLE_NAME IN ('la_user_asset_labels','la_user_asset_state'))")[0]['n']===11,'Genuine392 feature schema differs');
 release392Seed('tenant',['id'=>901,'sn'=>'release901','name'=>'Protected fixture tenant','point_balance'=>'128.50','create_time'=>1]);
 release392Seed('user',['id'=>901,'tenant_id'=>901,'sn'=>901,'account'=>'release901','user_money'=>'77.25']);
 release392Seed('user_account_log',['tenant_id'=>901,'user_id'=>901,'sn'=>'fixture-ledger','change_amount'=>'2.50','left_amount'=>'77.25']);
 release392Seed('recharge_order',['tenant_id'=>901,'user_id'=>901,'sn'=>'fixture-order','order_amount'=>'3.00','pay_status'=>1]);
 release392Seed('aigc_short_drama_episode_task',['tenant_id'=>901,'user_id'=>901,'status'=>'running','heartbeat_at'=>123456,'attempt_number'=>2]);
 release392Seed('aigc_short_drama_generation_task',['tenant_id'=>901,'user_id'=>901,'status'=>'running','task_id'=>'preserved-release-task','tenant_cost_points'=>'6.25','user_charge_points'=>'9.75','request_json'=>'{"owned_request":"KEEP_EXISTING_TASK"}']);
 // The full392 installer does not eagerly seed every app configuration/channel.
 // Create legitimate old-schema customer records for preservation assertions;
 // this never creates new schema or manufactures migration journal entries.
 if(!Db::name('aigc_video_config')->where('tenant_id',0)->find())release392Seed('aigc_video_config',['tenant_id'=>0,'config_json'=>'{}','create_time'=>1]);
 $video=Db::name('aigc_video_config')->where('tenant_id',0)->order('id')->find();release392Check($video!==null,'Old video configuration missing');
 Db::name('aigc_video_config')->where('id',$video['id'])->update(['config_json'=>'{"customer_config":"KEEP_VIDEO_CONFIG","effects_enabled":false}','update_time'=>123456]);
 release392Seed('tenant_system_menu',['id'=>99001,'tenant_id'=>901,'name'=>'Owned custom menu','source'=>'custom','is_core'=>0,'perms'=>'fixture:custom','paths'=>'fixture/custom','create_time'=>1]);
 foreach(['aigc_image','aigc_video','aigc_digital_human'] as $code){
  if(!Db::name($code.'_channel')->where('tenant_id',0)->find())release392Seed($code.'_channel',['tenant_id'=>0,'provider'=>'fixture-customer','model'=>'fixture-owned-model','status'=>0,'create_time'=>1]);
  $row=Db::name($code.'_channel')->where('tenant_id',0)->order('id')->find();release392Check($row!==null,'Old channel missing: '.$code);Db::name($code.'_channel')->where('id',$row['id'])->update(['provider'=>'fixture-customer','model'=>'fixture-owned-model','config_json'=>'{"owned_endpoint":"https://fixture.invalid/v1","owned":"KEEP_CHANNEL"}','status'=>0,'update_time'=>123456]);
  if(!Db::name($code.'_channel_spec')->where('tenant_id',0)->find())release392Seed($code.'_channel_spec',['tenant_id'=>0,'channel_id'=>$row['id'],'status'=>0,'create_time'=>1]);
  $price=Db::name($code.'_channel_spec')->where('tenant_id',0)->order('id')->find();release392Check($price!==null,'Old price missing: '.$code);Db::name($code.'_channel_spec')->where('id',$price['id'])->update(['platform_unit_cost'=>'7.25','tenant_unit_price'=>'19.75','provider_params_json'=>'{"owned_price":"KEEP_PRICE"}','status'=>0,'update_time'=>123456]);
 }
 // Explicit zero, positive and higher-precision legacy prices must survive NULL-default migration.
 $priceColumns=[];
 foreach($priceApps as $index=>$code){
  if($index===0)Db::execute('ALTER TABLE `la_'.$code.'_config` MODIFY COLUMN `unit_price` decimal(14,4) NOT NULL DEFAULT 0.0000 COMMENT \'legacy precision sentinel\'');
  foreach([901=>0,902=>($index===0?'19.7531':'19.75')] as $tenant=>$amount){
   if(!Db::name($code.'_config')->where('tenant_id',$tenant)->find())release392Seed($code.'_config',['tenant_id'=>$tenant,'unit_price'=>$amount,'config_json'=>'{"owned":"KEEP_PRICE_CONFIG"}','update_time'=>123456]);
   else Db::name($code.'_config')->where('tenant_id',$tenant)->update(['unit_price'=>$amount,'config_json'=>'{"owned":"KEEP_PRICE_CONFIG"}','update_time'=>123456]);
  }
  $priceColumns[$code]=Db::query("SELECT COLUMN_TYPE AS column_type,COLUMN_COMMENT AS column_comment,IS_NULLABLE AS is_nullable,COLUMN_DEFAULT AS column_default FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='unit_price'",['la_'.$code.'_config'])[0];
  release392Check($priceColumns[$code]['is_nullable']==='NO','Old price unexpectedly nullable');
 }
 Db::name('app')->where('code','aigc_pic_lipsync')->update(['is_builtin'=>0,'expire_policy'=>'deny']);
 release392Seed('app_plan',['app_code'=>'aigc_pic_lipsync','name'=>'Preserved paid legacy plan','status'=>1]);
 release392Seed('tenant_app',['tenant_id'=>901,'app_code'=>'aigc_pic_lipsync','version'=>'1.0.1','buy_status'=>'trial','shelf_status'=>'off','enable_status'=>'disabled','expire_time'=>123456]);
 $protectedRows=[];foreach(['tenant','user','user_account_log','recharge_order','aigc_short_drama_episode_task','aigc_short_drama_generation_task','aigc_video_config','aigc_image_channel','aigc_image_channel_spec','aigc_video_channel','aigc_video_channel_spec','aigc_digital_human_channel','aigc_digital_human_channel_spec'] as $table)$protectedRows[$table]=release392Rows($table);foreach($priceApps as $code)$protectedRows[$code.'_config']=release392Rows($code.'_config');
 // Local fixture authority. This signing key never leaves this process and cannot authorize online releases.
 $key=openssl_pkey_new(['private_key_bits'=>2048]);release392Check($key!==false,'Test signing key creation failed');$public=openssl_pkey_get_details($key)['key'];
 Db::name('update_source')->where('status',1)->update(['status'=>0]);Db::name('update_source')->insert(['name'=>'private release fixture authority','base_url'=>'http://fixture.invalid','online_base_url'=>'http://fixture.invalid','license_key'=>'fixture-only','online_license_key'=>'fixture-only','dev_mode'=>1,'public_key'=>$public,'status'=>1]);
 $license=new UpdateLicenseService();$machine=$license->machineCode();$payload=(object)['schema_version'=>3,'license_no'=>'LIC-private-release-fixture','version'=>1,'product_code'=>UpdateSourceClient::PRODUCT_CODE,'domains'=>[$machine['domain']],'machine_fingerprint_hash'=>$machine['machine_fingerprint_hash'],'license_type'=>'commercial','is_perpetual'=>true,'expires_at'=>0,'update_mode'=>'lifetime','issuer'=>(object)['copyright'=>[]]];
 release392Check(openssl_sign(SignedLicenseProtocol::json($payload),$signature,$key,OPENSSL_ALGO_SHA256),'Test license signing failed');$license->storeCertificate(SignedLicenseProtocol::json((object)['payload'=>$payload,'signature'=>base64_encode($signature),'algorithm'=>'RSA-SHA256']));
 $archive='/release393.zip';$sha=hash_file('sha256',$archive);$now=time();$id=Db::name('update_package')->insertGetId(['package_id'=>'private-actual393-fixture','type'=>'system','source'=>'cloud','app_code'=>'','version'=>'1.0.393','format'=>'zip','local_path'=>$archive,'sha256'=>$sha,'package_size'=>filesize($archive),'manifest_json'=>'{}','status'=>'downloaded','create_time'=>$now,'update_time'=>$now]);
 $context=$license->verifiedSiteContext();$response=(object)['code'=>1,'msg'=>'isolated test grant','server_time'=>$now,'data'=>['product_code'=>UpdateSourceClient::PRODUCT_CODE,'version'=>'1.0.393','target_version'=>'1.0.393','sha256'=>$sha,'format'=>'zip','site_grant'=>['license_no'=>$context['license_no'],'license_version'=>$context['license_version'],'domains'=>[$context['domain']],'machine_fingerprint_hash'=>$context['machine_fingerprint_hash'],'version_id'=>393,'issued_at'=>$now,'expires_at'=>$now+3600]]];
 release392Check(openssl_sign(SignedLicenseProtocol::json($response),$signature,$key,OPENSSL_ALGO_SHA256),'Test package signing failed');$response->signature=base64_encode($signature);Db::name('system_package_grant')->insert(['package_id'=>$id,'context_key'=>SystemUpdateProtocol::contextKey($context,UpdateSourceClient::getSource()),'response_json'=>SignedLicenseProtocol::json($response),'create_time'=>$now,'update_time'=>$now]);
 (new ComposerRuntimeUpdateService($site))->ensureBaseline();$pointer=json_decode(file_get_contents($site.'/.composer-runtime/current.json'),true,512,JSON_THROW_ON_ERROR);
 $protectedFiles=[];foreach(['.env','config/install.lock','public/uploads/protected.bin','runtime/task-lease.json','oem/brand.json'] as $file)$protectedFiles[$file]=hash_file('sha256',$site.'/'.$file);
 $canonical=[];foreach(['app','route','extend','vendor'] as $directory)$canonical[$directory]=release392JsonHash(release392Hashes($site.'/'.$directory));
 release392Write($stateFile,json_encode(['price_columns'=>$priceColumns,'package_id'=>$id,'archive_sha256'=>$sha,'pointer'=>$pointer,'protected_files'=>$protectedFiles,'canonical'=>$canonical,'protected_rows'=>$protectedRows,'old_migrations'=>release392Rows('app_migration'),'custom_menu'=>Db::name('tenant_system_menu')->where('id',99001)->find(),'video_config_sha256'=>release392JsonHash(release392Rows('aigc_video_config'))],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
 release392Result(['phase'=>'prepare','core'=>'1.0.392','old_apps_installed'=>$installed,'new_migrations_pre_registered'=>0,'archive_sha256'=>$sha,'fixture_authority'=>true]);exit;
}
$state=json_decode(file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR);$id=(int)$state['package_id'];
if($phase==='inspect'){
 $package=Db::name('update_package')->where('id',$id)->find();$tasks=Db::name('update_task')->where(['package_id'=>$id,'action'=>'apply'])->field('id,status,error')->select()->toArray();
 release392Result(['phase'=>'inspect','package_status'=>$package['status']??null,'package_error'=>$package['error']??null,'apply_tasks'=>$tasks,'pointer'=>json_decode((string)file_get_contents($site.'/.composer-runtime/current.json'),true),'active_journal_exists'=>is_file($site.'/.composer-runtime/active.json')]);exit;
}
if($phase==='apply'){
 release392Check(config('project.version')==='1.0.392'&&hash_file('sha256',(new ReflectionClass(SystemPackageUpdateService::class))->getFileName())==='58541a437ab39ce556011c8f375a23aef0a4f168229e694693478a45c4a92ef3','Apply is not running genuine392 old service');
 $service=new SystemPackageUpdateService();$preflight=$service->preflight($id);release392Check($preflight['passed'],'Genuine392 preflight rejected actual393: '.json_encode($preflight['errors'],JSON_UNESCAPED_UNICODE));$parent=getmypid();$result=$service->apply($id);
 release392Check(($result['version']??null)==='1.0.393','Genuine392 apply did not return actual393');release392Result(['phase'=>'apply','old_service_sha256'=>'58541a437ab39ce556011c8f375a23aef0a4f168229e694693478a45c4a92ef3','preflight'=>true,'parent_pid'=>$parent,'version'=>$result['version']]);exit;
}
release392Check($phase==='observe','Unknown observation');
release392Check(config('project.version')==='1.0.393'&&UpdateSourceClient::currentCoreVersion()==='1.0.393','Fresh observer did not switch runtime/marker');
$pointer=json_decode(file_get_contents($site.'/.composer-runtime/current.json'),true,512,JSON_THROW_ON_ERROR);release392Check($pointer['generation']!==$state['pointer']['generation']&&basename($GLOBALS['AIGC_RUNTIME_GENERATION'])===$pointer['generation'],'New generation pointer is not serving');
release392Check(str_contains((new ReflectionClass(SystemPackageUpdateService::class))->getFileName(),'/.composer-runtime/generations/'),'Observer loaded canonical old service');
release392Check(json_decode(file_get_contents($site.'/upgrade/version.json'),true)['version']==='1.0.393','Upgrade marker wrong');
release392Check(class_exists('NeuronAI\\Agent\\Agent')&&str_contains((new ReflectionClass('NeuronAI\\Agent\\Agent'))->getFileName(),'/.composer-runtime/generations/'),'SDK is not served by final generation');
$package=Db::name('update_package')->where('id',$id)->find();$tasks=Db::name('update_task')->where(['package_id'=>$id,'action'=>'apply'])->select()->toArray();release392Check($package['status']==='applied'&&count($tasks)===1&&$tasks[0]['status']==='success','Apply owner/task/package is not unique committed success');
$journals=glob($site.'/.composer-runtime/journals/*.json');release392Check(count($journals)===1&&!is_file($site.'/.composer-runtime/active.json'),'Runtime writer journal is not unique finalized');$journal=json_decode(file_get_contents($journals[0]),true,512,JSON_THROW_ON_ERROR);release392Check($journal['status']==='committed'&&$journal['sql_state']==='done'&&$journal['generation']===$pointer['generation'],'Final writer journal does not match served generation');
foreach($state['protected_files'] as $file=>$hash)release392Check(hash_file('sha256',$site.'/'.$file)===$hash,'Protected fixture file changed: '.$file);
foreach($state['canonical'] as $directory=>$hash)release392Check(release392JsonHash(release392Hashes($site.'/'.$directory))===$hash,'Canonical code/vendor changed: '.$directory);
foreach($state['protected_rows'] as $table=>$rows)release392Check(release392Rows($table)===$rows,'Protected business/price/config records changed: '.$table);
release392Check(Db::name('tenant_system_menu')->where('id',99001)->find()===$state['custom_menu'],'Customer menu changed');
foreach($state['old_migrations'] as $row)release392Check(Db::name('app_migration')->where('id',$row['id'])->find()===$row,'Old successful migration journal was changed');
$newRows=[];foreach($appMigrations as $source){$key=basename($source['package_path']);$rows=Db::name('app_migration')->where(['scope'=>'platform','tenant_id'=>0,'app_code'=>$source['app_code'],'migration_key'=>$key])->select()->toArray();release392Check(count($rows)===1&&$rows[0]['status']==='success','New migration is not genuinely finalized once: '.$key);$actual=base_path().'apps/'.$source['app_code'].'/migrations/'.$key;release392Check(hash_file('sha256',$actual)===$source['sha256'],'Served app migration source differs: '.$key);$newRows[]=$rows[0];}
release392Check(Db::query("SELECT DATA_TYPE AS data_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_video_config' AND COLUMN_NAME='config_json'")[0]['data_type']==='mediumtext','Actual capacity DDL not applied');
release392Check((int)Db::query("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('la_user_asset_labels','la_user_asset_state')")[0]['n']===2,'Workspace DDL missing');
release392Check((int)Db::query("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_file' AND COLUMN_NAME='upload_origin'")[0]['n']===1,'Workspace upload provenance column missing');
release392Check((int)Db::query("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'la_open_platform_%'")[0]['n']===9,'OpenPlatform schema incomplete');
release392Check(Db::name('app')->where(['code'=>'open_platform','status'=>'installed','is_builtin'=>1])->count()===1,'Builtin OpenPlatform metadata missing');
$apis=json_decode(file_get_contents(base_path().'apps/open_platform/api_schema.json'),true,512,JSON_THROW_ON_ERROR)['apis'];release392Check(Db::name('app_api')->where('app_code','open_platform')->count()===count($apis),'OpenPlatform API metadata incomplete');
release392Check(Db::name('app_frontend_entry')->where('app_code','open_platform')->count()===3,'OpenPlatform frontend metadata incomplete');
$docs=Db::name('open_platform_document')->where(['tenant_id'=>0,'status'=>'published'])->order('id')->select()->toArray();release392Check(count($docs)>=7,'OpenPlatform public guide seed coverage wrong');
foreach(['open_platform_key','open_platform_contract','open_platform_request','open_platform_setting'] as $table)release392Check(Db::name($table)->count()===0,'Upgrade created live credentials/contracts/paid requests or enabled tenant policy: '.$table);
foreach($state['price_columns'] as $code=>$before){
 $after=Db::query("SELECT COLUMN_TYPE AS column_type,COLUMN_COMMENT AS column_comment,IS_NULLABLE AS is_nullable,COLUMN_DEFAULT AS column_default FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='unit_price'",['la_'.$code.'_config'])[0];
 release392Check($after['is_nullable']==='YES'&&$after['column_default']===null,'Price does not inherit by NULL: '.$code);
 release392Check($after['column_type']===$before['column_type']&&$after['column_comment']===$before['column_comment'],'Legacy precision/comment changed: '.$code);
}
release392Check(Db::name('app')->where(['code'=>'aigc_pic_lipsync','is_builtin'=>1,'status'=>'installed','expire_policy'=>'allow'])->count()===1,'Picture builtin app metadata missing');
release392Check(Db::name('tenant_app')->where(['tenant_id'=>901,'app_code'=>'aigc_pic_lipsync','buy_status'=>'paid','shelf_status'=>'on','enable_status'=>'enabled','expire_time'=>0])->count()===1,'Picture builtin tenant authorization missing');
release392Check(Db::name('app_plan')->where(['app_code'=>'aigc_pic_lipsync','status'=>1])->count()===0,'Legacy picture paid plan still enabled');
$picManifest=json_decode(file_get_contents(AppRegistryService::manifestPath('aigc_pic_lipsync')),true,512,JSON_THROW_ON_ERROR);
release392Check(Db::name('app')->where('code','aigc_pic_lipsync')->value('current_version')===$picManifest['version'],'Picture metadata version differs from serving generation');
$menuCount=0;
foreach(['platform'=>'system_menu','tenant'=>'tenant_system_menu'] as $surface=>$table){
 $menus=json_decode(file_get_contents(base_path().'apps/aigc_pic_lipsync/menus/'.$surface.'.json'),true,512,JSON_THROW_ON_ERROR);
 $checkMenus=function(array $nodes)use(&$checkMenus,&$menuCount,$surface,$table):void{
  foreach($nodes as $node){
   $query=Db::name($table)->where(['app_code'=>'aigc_pic_lipsync','source'=>'app','source_menu_key'=>$node['source_menu_key']]);if($surface==='tenant')$query->where('tenant_id',0);
   $row=$query->find();release392Check($row!==null&&$row['perms']===($node['perms']??'')&&$row['component']===($node['component']??''),'Picture menu metadata differs: '.$surface.'/'.$node['source_menu_key']);$menuCount++;
   $checkMenus($node['children']??[]);
  }
 };$checkMenus($menus);
}
release392Check(Db::name('tenant_system_menu')->where(['app_code'=>'aigc_image','source'=>'app','source_menu_key'=>'aigc_image_channel_price','is_show'=>1,'is_disable'=>0])->count()===0,'Retired image price menu remains enabled');
release392Result(['phase'=>'observe','core'=>'1.0.393','nullable_price_columns'=>count($state['price_columns']),'picture_builtin'=>true,'picture_menu_nodes'=>$menuCount,'generation'=>$pointer['generation'],'app_migrations_completed'=>count($newRows),'system_migrations_observed'=>count($migrations)-count($appMigrations),'protected_rows_sha256'=>release392JsonHash($state['protected_rows']),'migration_rows_sha256'=>release392JsonHash(release392Rows('app_migration')),'document_rows_sha256'=>release392JsonHash(release392Rows('open_platform_document')),'guide_count'=>count($docs),'apply_tasks'=>1,'committed_runtime_journals'=>1,'paid_calls'=>0]);
