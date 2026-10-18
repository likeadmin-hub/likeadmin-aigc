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
$original=config('database');$mysql=$original['connections']['mysql'];check(PHP_SAPI==='cli'&&$mysql['hostname']==='127.0.0.1'&&$mysql['database']==='z_cn','Verified local database only');
$name='micro_video_fixture_'.bin2hex(random_bytes(6));
$pdo=new PDO('mysql:host='.$mysql['hostname'].';port='.($mysql['hostport']?:3306).';charset=utf8mb4',$mysql['username'],$mysql['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
$created=false;$prefix='la_';$fixtureTables=[];$root=dirname(__DIR__,2);
try{
 try{$pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');$created=true;$pdo->exec('USE `'.$name.'`');}
 catch(PDOException $e){if((int)($e->errorInfo[1]??0)!==1044)throw $e;$prefix='mvf_'.bin2hex(random_bytes(6)).'_';$pdo->exec('USE `z_cn`');}
 $sql=file_get_contents($root.'/public/install/db/like.sql');check(!preg_match('/^\s*(?:USE\b|(?:DROP|CREATE)\s+DATABASE\b)/im',$sql),'Installer must not change schema selection');$sql=str_replace('la_',$prefix,$sql);
 preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`([^`]+)`/i',$sql,$matches);$fixtureTables=array_values(array_unique($matches[1]));foreach($fixtureTables as $table)check(str_starts_with($table,$prefix)&&preg_match('/^[a-z0-9_]+$/D',$table),'Installer table escaped fixture prefix');
 define('INSTALL_ROOT', $root.'/public/install');
 require INSTALL_ROOT.'/model.php';
 $installer = new installModel();
 foreach (['dbh'=>$pdo,'prefix'=>$prefix] as $property=>$value) (new ReflectionProperty($installer,$property))->setValue($installer,$value);
 check($installer->createTable('8.0',['admin_user'=>'fixture_admin','admin_password'=>'disposable-fixture-only']), 'Actual installer failed');

 $freshApp=$pdo->query("SELECT * FROM `".$prefix."app` WHERE code='aigc_micro_video'")->fetch(PDO::FETCH_ASSOC);
 check($freshApp && $freshApp['status']==='installed' && (int)$freshApp['is_builtin']===1 && $freshApp['expire_policy']==='allow','Fresh installer did not enable micro video');
 $config=$original;$config['connections']['mysql']['database']=$created?$name:'z_cn';$config['connections']['mysql']['prefix']=$prefix;think\facade\Config::set($config,'database');Db::connect(null,true);
 $code='aigc_micro_video';$manifest=Registry::getManifest($code);
 check($freshApp['current_version']===$manifest['version'],'Fresh app version differs');
 check(app\common\service\app\AppAccessService::isDefaultAigcApp($code),'Micro video is not a default AIGC app');
 $menus=json_decode(file_get_contents($root.'/app/apps/'.$code.'/menus/tenant.json'),true);$keys=[];
 $collect=function($items)use(&$collect,&$keys){foreach($items as $menu){$keys[]=$menu['source_menu_key'];$collect($menu['children']??[]);}};$collect($menus);sort($keys);
 foreach(json_decode(file_get_contents($root.'/app/apps/'.$code.'/api_schema.json'),true)['apis'] as $api){
  $rows=Db::name('app_api')->where(['app_code'=>$code,'api_path'=>$api['api_path'],'api_method'=>$api['api_method']??'GET','scene'=>$api['scene']??'tenant_admin'])->select()->toArray();
  check(count($rows)===1 && $rows[0]['permission_key']===$api['permission_key'],'Fresh API permission missing '.$api['api_path']);
 }
 Db::name('tenant')->insert(['id'=>123456,'sn'=>'mvf_shared','name'=>'Shared fixture','tactics'=>0,'create_time'=>time(),'update_time'=>time()]);
 Db::name('tenant')->insert(['id'=>123457,'sn'=>'mvf_split','name'=>'Split fixture','tactics'=>1,'create_time'=>time(),'update_time'=>time()]);
 $splitTable=$prefix.'tenant_system_menu_mvf_split';$pdo->exec('CREATE TABLE `'.$splitTable.'` LIKE `'.$prefix.'tenant_system_menu`');$fixtureTables[]=$splitTable;
 $customId=Db::name('tenant_system_menu')->insertGetId(['tenant_id'=>123456,'name'=>'Owned custom menu','source'=>'tenant','app_code'=>$code,'source_menu_key'=>'owned_custom','type'=>'C']);
 for($i=0;$i<2;$i++){DefaultAppService::syncTenantDefaults(123456,'mvf_shared',false);DefaultAppService::syncTenantDefaults(123457,'mvf_split',true);}
 $assertTenant=function($id,$table)use($code,$keys,$pdo){
  $row=Db::name('tenant_app')->where(['tenant_id'=>$id,'app_code'=>$code])->find();check($row && $row['buy_status']==='paid'&&$row['shelf_status']==='on'&&$row['enable_status']==='enabled'&&(int)$row['expire_time']===0,'Tenant app not ready');
  $q=$pdo->prepare('SELECT source_menu_key FROM `'.$table.'` WHERE tenant_id=? AND app_code=? AND source=?');$q->execute([$id,$code,'app']);$actual=$q->fetchAll(PDO::FETCH_COLUMN);sort($actual);check($actual===$keys,'Tenant menus missing/duplicated');
  foreach(['pc','uniapp','tenant'] as $terminal){$entries=array_values(array_filter(AppFrontendManifestService::tenantEntries($id,$terminal),fn($entry)=>$entry['app_code']===$code));check(count($entries)===1,'Tenant entry missing '.$terminal);}
 };
 $assertTenant(123456,$prefix.'tenant_system_menu');$assertTenant(123457,$splitTable);
 // Simulate an upgraded customer with an old micro-video schema and no app installation.
 foreach(['frame_batch','frame','attempt','segment','task','direction','config'] as $suffix){check(count(Db::query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$prefix.'aigc_micro_video_'.$suffix]))===1,'Fresh schema missing '.$suffix);$pdo->exec('DROP TABLE `'.$prefix.'aigc_micro_video_'.$suffix.'`');}
 app\common\service\database\SqlMigrationExecutor::execute(file_get_contents($root.'/upgrade/20261016_micro_video.sql'),$prefix);
 Db::name('aigc_micro_video_config')->insert(['tenant_id'=>123456,'config_json'=>'{"owned_model":"keep"}','create_time'=>1,'update_time'=>2]);
 $before=Db::name('aigc_micro_video_config')->select()->toArray();
 $shared=file_get_contents($root.'/app/apps/'.$code.'/migrations/zz_20261017_shared_frames.sql');
 $plan=app\common\service\database\RuntimeMigrationPlan::build($shared);check(!str_contains($plan['prepare'],'PREPARE'),'Dynamic SQL escaped reviewed schema adapter');
 for($i=0;$i<2;$i++)app\common\service\database\SqlMigrationExecutor::execute($plan['prepare'],$prefix);
 $index=Db::query('SELECT COLUMN_NAME AS column_name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX',[$prefix.'aigc_micro_video_attempt','uk_execution']);check(array_column($index,'column_name')===['task_id','segment_id','frame_id','kind','attempt_no'],'Frame attempt unique index wrong');
 $rejected=false;try{app\common\service\database\RuntimeMigrationPlan::build(str_replace('SET @micro_sql','SET @untrusted_sql',$shared));}catch(RuntimeException $e){$rejected=true;}check($rejected,'Adapter accepted altered dynamic SQL');
 Db::name('app')->where('code',$code)->delete();Db::name('tenant_app')->where('app_code',$code)->delete();Db::name('app_frontend_entry')->where('app_code',$code)->delete();
 Db::name('tenant_system_menu')->where(['app_code'=>$code,'source'=>'app'])->delete();$pdo->exec("DELETE FROM `".$splitTable."` WHERE app_code='aigc_micro_video' AND source='app'");
 $repair=file_get_contents($root.'/upgrade/20261019_micro_video_workbench_registry.sql');check($repair===file_get_contents($root.'/public/upgrade/20261019_micro_video_workbench_registry.sql'),'Upgrade mirror differs');
 $dml=app\common\service\database\RuntimeMigrationPlan::build($repair);check($dml['prepare']==='','Registration must be transactional DML');
 for($i=0;$i<2;$i++)app\common\service\database\SqlMigrationExecutor::execute($repair,$prefix);
 DefaultAppService::syncAllTenants($code);$assertTenant(123456,$prefix.'tenant_system_menu');$assertTenant(123457,$splitTable);
 for($i=0;$i<2;$i++)Registry::installFromLocal($code,'1.0.404');
 check(Db::name('aigc_micro_video_config')->select()->toArray()===$before,'Update/retry altered tenant configuration');check(Db::name('tenant_system_menu')->where('id',$customId)->value('name')==='Owned custom menu','Tenant-owned menu was overwritten');
 $assertTenant(123456,$prefix.'tenant_system_menu');$assertTenant(123457,$splitTable);
 foreach([fn()=>Registry::uninstall($code),fn()=>Registry::setStatus($code,'disabled')] as $operation){$rejected=false;try{$operation();}catch(Throwable $e){$rejected=$e->getMessage()==='系统应用不允许禁用或卸载';}check($rejected,'Default app lifecycle operation not rejected');}
 echo json_encode(['fresh_install'=>'passed','existing_upgrade'=>'passed','new_shared_and_split_tenants'=>'passed','repeat_sync'=>'passed','protected_settings_and_menu'=>'passed','frame_schema_and_unknown_sql_guard'=>'passed'],JSON_UNESCAPED_UNICODE),PHP_EOL;
}finally{
 think\facade\Config::set($original,'database');Db::connect(null,true);
 if($created)$pdo->exec('DROP DATABASE `'.$name.'`');else{foreach(array_reverse($fixtureTables) as $table)$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');}
}
