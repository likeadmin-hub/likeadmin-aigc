<?php
/** Full installer and repeated upgrade validation in a disposable local schema. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
// ThinkPHP's web exception renderer can return exit 0 even on CLI assertion failures.
set_exception_handler(static function(Throwable $error): void { fwrite(STDERR, 'FAIL '.$error->getMessage().PHP_EOL); exit(1); });
use think\facade\Db;


use app\common\service\app\AppRegistryService as Registry;
use app\common\service\app\DefaultAppService;
use app\common\service\app\AppFrontendManifestService;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$original=config('database');$mysql=$original['connections']['mysql'];check(PHP_SAPI==='cli'&&$mysql['hostname']==='127.0.0.1'&&in_array($mysql['database'],['x_cn','z_cn'],true)&&getenv('RETIRED_VIDEO_LOCAL_TEST')===$mysql['database'],'Verified local database only');
$name='retired_video_fixture_'.bin2hex(random_bytes(6));
$pdo=new PDO('mysql:host='.$mysql['hostname'].';port='.($mysql['hostport']?:3306).';charset=utf8mb4',$mysql['username'],$mysql['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
$created=false;$prefix='la_';$fixtureTables=[];$root=dirname(__DIR__,2);
try{
 try{$pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');$created=true;$pdo->exec('USE `'.$name.'`');}
 catch(PDOException $e){if((int)($e->errorInfo[1]??0)!==1044)throw $e;$prefix='mvf_'.bin2hex(random_bytes(6)).'_';$pdo->exec('USE `'.$mysql['database'].'`');}
 $sql=file_get_contents($root.'/public/install/db/like.sql');check(!preg_match('/^\s*(?:USE\b|(?:DROP|CREATE)\s+DATABASE\b)/im',$sql),'Installer must not change schema selection');$sql=str_replace('la_',$prefix,$sql);
 preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`([^`]+)`/i',$sql,$matches);$fixtureTables=array_values(array_unique($matches[1]));foreach($fixtureTables as $table)check(str_starts_with($table,$prefix)&&preg_match('/^[a-z0-9_]+$/D',$table),'Installer table escaped fixture prefix');
 define('INSTALL_ROOT', $root.'/public/install');
 require INSTALL_ROOT.'/model.php';
 $installer = new installModel();
 foreach (['dbh'=>$pdo,'prefix'=>$prefix] as $property=>$value) (new ReflectionProperty($installer,$property))->setValue($installer,$value);
 check($installer->createTable('8.0',['admin_user'=>'fixture_admin','admin_password'=>'disposable-fixture-only']), 'Actual installer failed');

 $bundled = app\common\service\app\AppAccessService::SHELFABLE_DEFAULT_APP_CODES;
 foreach (glob($root.'/app/apps/*/manifest.json') as $manifestFile) {
  $manifest=json_decode(file_get_contents($manifestFile),true,512,JSON_THROW_ON_ERROR);
  if (!empty($manifest['is_builtin'])) check(app\common\service\app\AppAccessService::isDefaultApp($manifest['code']), 'Built-in manifest missing default: '.$manifest['code']);
 }
 $rows=$pdo->query("SELECT code,status,is_builtin FROM `".$prefix."app`")->fetchAll(PDO::FETCH_ASSOC);
 $map=[];foreach($rows as $row)$map[$row['code']]=$row;
 $missing=[];foreach($bundled as $item)if(!isset($map[$item]) || $map[$item]['status']!=='installed' || (int)$map[$item]['is_builtin']!==1)$missing[]=[$item,$map[$item]??null];
 if($missing) throw new RuntimeException('Fresh bundled registrations missing: '.json_encode($missing,JSON_UNESCAPED_UNICODE));
 $allMissing=[];foreach (glob($root.'/app/apps/*/manifest.json') as $file) {
  $manifest=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
  if (empty($manifest['is_builtin'])) continue;
  $item=$manifest['code'];
  if (!isset($map[$item]) || $map[$item]['status']!=='installed' || (int)$map[$item]['is_builtin']!==1) $allMissing[]=[$item,$map[$item]??null];
 }
 check(!$allMissing,'Fresh built-in registry missing: '.json_encode($allMissing,JSON_UNESCAPED_UNICODE));
 check(!isset($map['aigc_micro_video']),'Fresh install must not register the retired tool');
 check(!$pdo->query("SHOW TABLES LIKE '".$prefix."aigc_micro_video_%'")->fetchColumn(),'Fresh install must not create retired business tables');
 $config=$original;$config['connections']['mysql']['database']=$created?$name:$mysql['database'];$config['connections']['mysql']['prefix']=$prefix;think\facade\Config::set($config,'database');Db::connect(null,true);
 $code='aigc_product_video';$manifest=Registry::getManifest($code);
 $menus=json_decode(file_get_contents($root.'/app/apps/'.$code.'/menus/tenant.json'),true);$keys=[];
 $collect=function($items)use(&$collect,&$keys){foreach($items as $menu){$keys[]=$menu['source_menu_key'];$collect($menu['children']??[]);}};$collect($menus);sort($keys);
 foreach(json_decode(file_get_contents($root.'/app/apps/'.$code.'/api_schema.json'),true)['apis'] as $api){
  $rows=Db::name('app_api')->where(['app_code'=>$code,'api_path'=>$api['api_path'],'api_method'=>$api['api_method']??'GET','scene'=>$api['scene']??'tenant_admin'])->select()->toArray();
  check(count($rows)===1 && $rows[0]['permission_key']===$api['permission_key'],'Fresh API permission missing '.$api['api_path']);
 }
 Db::name('tenant')->insert(['id'=>123456,'sn'=>'mvf_shared','name'=>'Shared fixture','tactics'=>0,'create_time'=>time(),'update_time'=>time()]);
 Db::name('tenant')->insert(['id'=>123457,'sn'=>'mvf_split','name'=>'Split fixture','tactics'=>1,'create_time'=>time(),'update_time'=>time()]);
 $splitTable=$prefix.'tenant_system_menu_mvf_split';$pdo->exec('CREATE TABLE `'.$splitTable.'` LIKE `'.$prefix.'tenant_system_menu`');$fixtureTables[]=$splitTable;
 $ownedCollision=Db::name('tenant_system_menu')->insertGetId(['tenant_id'=>123456,'name'=>'Tenant owned smart clip','source'=>'tenant','app_code'=>'smart_clip','source_menu_key'=>'smart_clip','type'=>'M']);
 $repairCode='aigc_product_image';
 Db::name('app')->where('code',$repairCode)->update(['is_builtin'=>0]);
 $upgrade=file_get_contents($root.'/upgrade/20261027_bundled_app_defaults.sql');
 check($upgrade===file_get_contents($root.'/public/upgrade/20261027_bundled_app_defaults.sql'),'Upgrade mirror differs');
 $plan=app\common\service\database\RuntimeMigrationPlan::build($upgrade);
 check($plan['prepare']==='','Bundled registration must be transactional DML');
 for($i=0;$i<2;$i++) app\common\service\database\SqlMigrationExecutor::execute($upgrade,$prefix);
 check((int)Db::name('app')->where('code',$repairCode)->value('is_builtin')===1, 'Legacy default flag was not repaired');
 foreach($bundled as $item) {
  for($i=0;$i<2;$i++) Registry::installFromLocal($item,'1.0.418');
  $app=Db::name('app')->where('code',$item)->find();
  check($app && $app['status']==='installed' && (int)$app['is_builtin']===1, 'Install or retry failed: '.$item);
  $entry=Db::name('tenant_app')->where(['tenant_id'=>123456,'app_code'=>$item])->find();
  check($entry && $entry['shelf_status']==='on' && $entry['enable_status']==='enabled', 'Shared tenant default missing: '.$item);
  check(Db::name('tenant_app')->where(['tenant_id'=>123457,'app_code'=>$item])->count()===1, 'Split tenant default missing: '.$item);
  $manifest=Registry::getManifest($item);
  if (in_array('uniapp',$manifest['frontends']??[],true)) {
   $entries=AppFrontendManifestService::tenantEntries(123456,'uniapp');
   check(in_array($item,array_column($entries,'app_code'),true), 'Mobile link missing: '.$item);
  }
 }
 check(Db::name('tenant_system_menu')->where('id',$ownedCollision)->value('name')==='Tenant owned smart clip','Owned menu was overwritten');
 Db::name('tenant_app')->where(['tenant_id'=>123456,'app_code'=>'smart_clip'])->update(['buy_status'=>'unpaid','shelf_status'=>'off','expire_time'=>123]);
 DefaultAppService::syncAllTenants('smart_clip');
 $preserved=Db::name('tenant_app')->where(['tenant_id'=>123456,'app_code'=>'smart_clip'])->find();
 check($preserved['buy_status']==='unpaid' && $preserved['shelf_status']==='off' && (int)$preserved['expire_time']===123,'Existing tenant policy was overwritten');
 check(!app\common\service\app\AppAccessService::tenantCanUse(123456,'smart_clip'), 'Unshelved default must not be usable');
 Db::name('tenant_app')->where(['tenant_id'=>123456,'app_code'=>'smart_clip'])->update(['shelf_status'=>'on']);
 check(app\common\service\app\AppAccessService::tenantCanUse(123456,'smart_clip'), 'Shelved default should be usable');
 $customId=Db::name('tenant_system_menu')->insertGetId(['tenant_id'=>123456,'name'=>'Owned custom menu','source'=>'tenant','app_code'=>$code,'source_menu_key'=>'owned_custom','type'=>'C']);
 for($i=0;$i<2;$i++){DefaultAppService::syncTenantDefaults(123456,'mvf_shared',false);DefaultAppService::syncTenantDefaults(123457,'mvf_split',true);}
 $assertTenant=function($id,$table)use($code,$keys,$pdo){
  $row=Db::name('tenant_app')->where(['tenant_id'=>$id,'app_code'=>$code])->find();check($row && $row['buy_status']==='paid'&&$row['shelf_status']==='on'&&$row['enable_status']==='enabled'&&(int)$row['expire_time']===0,'Tenant app not ready');
  $q=$pdo->prepare('SELECT source_menu_key FROM `'.$table.'` WHERE tenant_id=? AND app_code=? AND source=?');$q->execute([$id,$code,'app']);$actual=$q->fetchAll(PDO::FETCH_COLUMN);sort($actual);check($actual===$keys,'Tenant menus missing/duplicated');
  foreach(['pc','uniapp','tenant'] as $terminal){$entries=array_values(array_filter(AppFrontendManifestService::tenantEntries($id,$terminal),fn($entry)=>$entry['app_code']===$code));check(count($entries)===1,'Tenant entry missing '.$terminal);}
 };
 $assertTenant(123456,$prefix.'tenant_system_menu');$assertTenant(123457,$splitTable);
 // Simulate an upgraded customer archive without executing any retired generator.
 $archive=$prefix.'aigc_micro_video_task';$pdo->exec('CREATE TABLE `'.$archive.'` (id INT PRIMARY KEY, tenant_id INT, user_id INT, payload TEXT)');$fixtureTables[]=$archive;
 $pdo->exec("INSERT INTO `".$archive."` VALUES (1,123456,1,'paid historical receipt')");
 Db::name('app')->insert(['code'=>'aigc_micro_video','name'=>'Legacy archived tool','status'=>'installed','is_builtin'=>1]);
 Db::name('tenant_app')->insert(['tenant_id'=>123456,'app_code'=>'aigc_micro_video','buy_status'=>'paid','shelf_status'=>'on','enable_status'=>'enabled']);
 $oldMenu=Db::name('tenant_system_menu')->insertGetId(['tenant_id'=>123456,'name'=>'Retired app menu','source'=>'app','app_code'=>'aigc_micro_video','source_menu_key'=>'legacy','type'=>'C']);
 $tenantMenu=Db::name('tenant_system_menu')->insertGetId(['tenant_id'=>123456,'name'=>'Owned archive link','source'=>'tenant','app_code'=>'aigc_micro_video','source_menu_key'=>'custom','type'=>'C']);
 $retire=file_get_contents($root.'/upgrade/upgrade_20261111_retire_micro_video.sql');check($retire===file_get_contents($root.'/public/upgrade/upgrade_20261111_retire_micro_video.sql'),'Retirement upgrade mirror differs');
 $retirementPlan=app\common\service\database\RuntimeMigrationPlan::build($retire);
 check(app\common\service\database\SqlMigrationExecutor::split($retire)!==[], 'System updater would skip comment-only retirement');
 $migrationRoot=sys_get_temp_dir().'/retired-system-sql-'.bin2hex(random_bytes(6));mkdir($migrationRoot.'/sql/before',0700,true);
 try{
  file_put_contents($migrationRoot.'/sql/before/retire.sql',$retire);
  $runner=new ReflectionMethod(app\common\service\update\SystemPackageUpdateService::class,'applySqlGroup');$runner->setAccessible(true);
  for($i=0;$i<2;$i++)$runner->invoke(new app\common\service\update\SystemPackageUpdateService(),$migrationRoot,['package_mode'=>'incremental','sql_order'=>['sql/before/retire.sql']],'before');
 }finally{unlink($migrationRoot.'/sql/before/retire.sql');rmdir($migrationRoot.'/sql/before');rmdir($migrationRoot.'/sql');rmdir($migrationRoot);}
 check(Db::name('app')->where('code','aigc_micro_video')->value('status')==='removed','Upgraded app was not retired');
 check(Db::table($archive)->value('payload')==='paid historical receipt','Retirement changed paid history');
 check(!Db::name('tenant_system_menu')->where('id',$oldMenu)->find(),'Retired app menu remains');
 check((bool)Db::name('tenant_system_menu')->where('id',$tenantMenu)->find(),'Tenant-owned menu was removed');
 DefaultAppService::syncTenantDefaults(123456,'mvf_shared',false);
 check(Db::name('tenant_app')->where(['tenant_id'=>123456,'app_code'=>'aigc_micro_video'])->value('shelf_status')==='off','New tenant sync resurrected retired tool');
 $blocked=false;try{Registry::installFromLocal('aigc_micro_video');}catch(Throwable){$blocked=true;}check($blocked,'Retired local package was reinstallable');
 $assertTenant(123456,$prefix.'tenant_system_menu');$assertTenant(123457,$splitTable);
 foreach([fn()=>Registry::uninstall($code)] as $operation){$rejected=false;try{$operation();}catch(Throwable $e){$rejected=$e->getMessage()==='系统应用不允许禁用或卸载';}check($rejected,'Default app lifecycle operation not rejected');}
 Registry::setStatus($code,'disabled');check(!app\common\service\app\AppAccessService::tenantCanUse(123456,$code),'Disabled default app remained usable');check(app\common\service\app\AppAccessService::tenantCanManage(123456,$code),'Disabled default app prevented management');Registry::setStatus($code,'installed');
 echo json_encode(['bundled_apps_installed_twice'=>count($bundled),'fresh_install'=>'passed','existing_upgrade'=>'passed','new_shared_and_split_tenants'=>'passed','mobile_entries'=>'passed','repeat_sync'=>'passed','protected_settings_and_menu'=>'passed','retirement_archive_and_no_resurrection'=>'passed'],JSON_UNESCAPED_UNICODE),PHP_EOL;
}finally{
 think\facade\Config::set($original,'database');Db::connect(null,true);
 if($created)$pdo->exec('DROP DATABASE `'.$name.'`');else{foreach(array_reverse($fixtureTables) as $table)$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');}
}
