<?php
/** Full installer and repeated upgrade validation in a disposable local schema. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenDocuments as D;
use app\common\service\app\AppRegistryService as Registry;
use app\common\service\app\DefaultAppService;
use app\common\service\app\AppFrontendManifestService;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$original=config('database');$mysql=$original['connections']['mysql'];check(PHP_SAPI==='cli'&&$mysql['hostname']==='127.0.0.1'&&$mysql['database']==='z_cn','Verified local database only');
$name='open_platform_fixture_'.bin2hex(random_bytes(6));
$pdo=new PDO('mysql:host='.$mysql['hostname'].';port='.($mysql['hostport']?:3306).';charset=utf8mb4',$mysql['username'],$mysql['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
$created=false;$prefix='la_';$fixtureTables=[];$root=dirname(__DIR__,2);
try{
 try{$pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');$created=true;$pdo->exec('USE `'.$name.'`');}
 catch(PDOException $e){if((int)($e->errorInfo[1]??0)!==1044)throw $e;$prefix='opf_'.bin2hex(random_bytes(6)).'_';$pdo->exec('USE `z_cn`');}
 $sql=file_get_contents($root.'/public/install/db/like.sql');check(!preg_match('/^\s*(?:USE\b|(?:DROP|CREATE)\s+DATABASE\b)/im',$sql),'Installer must not change schema selection');$sql=str_replace('la_',$prefix,$sql);
 preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`([^`]+)`/i',$sql,$matches);$fixtureTables=array_values(array_unique($matches[1]));foreach($fixtureTables as $table)check(str_starts_with($table,$prefix)&&preg_match('/^[a-z0-9_]+$/D',$table),'Installer table escaped fixture prefix');
 $pdo->exec($sql);
 $tables=$pdo->query('SHOW TABLES LIKE '.$pdo->quote($prefix.'open_platform_%'))->fetchAll(PDO::FETCH_COLUMN);sort($tables);check(count($tables)===9,'Fresh installer is missing open-platform tables');
 $before=[];foreach($tables as $table)$before[$table]=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
 $migrations=glob($root.'/app/apps/open_platform/migrations/*.sql');sort($migrations);check(count($migrations)===8,'Migration baseline changed; update acceptance coverage');
 for($i=0;$i<2;$i++)foreach($migrations as $file)$pdo->exec(str_replace('la_',$prefix,file_get_contents($file)));
 $schema=static fn($ddl)=>preg_replace('/ AUTO_INCREMENT=\d+/','',$ddl);
 foreach($tables as $table)check($schema($pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1])===$schema($before[$table]),'Repeated migration changed installed schema: '.$table);
 foreach(['quickstart','auth-limits','tasks-billing','assets-webhooks','model-sdk','image-tools','music-tools'] as $slug){$q=$pdo->prepare('SELECT content FROM `'.$prefix.'open_platform_document` WHERE tenant_id=0 AND slug=? AND status=?');$q->execute(['open-platform-'.$slug,'published']);$rows=$q->fetchAll(PDO::FETCH_COLUMN);check(count($rows)===1&&trim($rows[0])===trim(file_get_contents($root.'/app/apps/open_platform/docs/'.$slug.'.md')),'Guide seed changed or duplicated: '.$slug);}
 $test=$original;$test['default']='open_platform_fixture';$test['connections']['open_platform_fixture']=array_replace($mysql,['database'=>$created?$name:'z_cn','prefix'=>$prefix]);$test['connections']['mysql']=$test['connections']['open_platform_fixture'];$fixtureConfig=new think\Config();$fixtureConfig->set($test,'database');app()->config->set($test,'database');Db::setConfig($fixtureConfig);
 check(config('database.connections.mysql.prefix')===$prefix&&($created||$prefix!=='la_'),'Registry migrations escaped disposable fixture');
 $manifest=Registry::getManifest('open_platform');check(empty($manifest['public_assets']),'Fixture cannot modify installed public assets');
 for($i=0;$i<2;$i++)Registry::installFromLocal('open_platform','1.0.391');
 $app=Db::name('app')->where('code','open_platform')->find();check($app&&$app['status']==='installed'&&(int)$app['is_builtin']===1&&$app['expire_policy']==='allow','Fresh registry lost built-in lifecycle protections');
 $schemaApis=json_decode(file_get_contents($root.'/app/apps/open_platform/api_schema.json'),true,512,JSON_THROW_ON_ERROR)['apis'];check(Db::name('app_api')->where('app_code','open_platform')->count()===count($schemaApis),'Registry APIs missing or duplicated');
 check(Db::name('app_frontend_entry')->where('app_code','open_platform')->count()===3,'Registry frontend entries missing or duplicated');
 $menuKeys=static function(array $menus)use(&$menuKeys){$keys=[];foreach($menus as $menu){$keys[]=$menu['source_menu_key'];$keys=array_merge($keys,$menuKeys($menu['children']??[]));}return $keys;};
 $platformKeys=$menuKeys(json_decode(file_get_contents($root.'/app/apps/open_platform/menus/platform.json'),true));$tenantKeys=$menuKeys(json_decode(file_get_contents($root.'/app/apps/open_platform/menus/tenant.json'),true));
 $actual=Db::name('system_menu')->where('app_code','open_platform')->column('source_menu_key');sort($actual);sort($platformKeys);check($actual===$platformKeys,'Platform menus or permission actions missing or duplicated');
 Db::name('tenant')->insert(['id'=>123456,'sn'=>'opf_fixture','name'=>'opf fixture','tactics'=>0,'create_time'=>time(),'update_time'=>time()]);
 $customId=Db::name('tenant_system_menu')->insertGetId(['tenant_id'=>123456,'app_code'=>'open_platform','source'=>'tenant','source_menu_key'=>'opf_custom','name'=>'Owned custom menu','type'=>'C','create_time'=>time(),'update_time'=>time()]);
 for($i=0;$i<2;$i++)DefaultAppService::syncTenantDefaults(123456,'opf_fixture',false);
 $opened=Db::name('tenant_app')->where(['tenant_id'=>123456,'app_code'=>'open_platform'])->select()->toArray();check(count($opened)===1&&$opened[0]['buy_status']==='paid'&&$opened[0]['shelf_status']==='on'&&$opened[0]['enable_status']==='enabled','New tenant module not installed, enabled and shelved');
 $actual=Db::name('tenant_system_menu')->where(['tenant_id'=>123456,'app_code'=>'open_platform','source'=>'app'])->column('source_menu_key');sort($actual);sort($tenantKeys);check($actual===$tenantKeys,'New tenant menus or action permissions missing or duplicated');
 check(Db::name('tenant_system_menu')->where('id',$customId)->value('name')==='Owned custom menu','Default sync overwrote tenant-owned menu');
 $entries=array_values(array_filter(AppFrontendManifestService::tenantEntries(123456,'pc'),fn($entry)=>$entry['app_code']==='open_platform'));check(count($entries)===1&&$entries[0]['path']==='/user/developer/overview'&&!$entries[0]['need_membership']&&$entries[0]['membership_allowed'],'New tenant developer entry imposed independent API membership');
 check(S::settings(123456)['enabled']===false,'New tenant unexpectedly enabled API calling');check(S::settings(123456)['apps']===[]&&S::settings(123456)['models']===[],'New tenant inherited callable abilities');
 check(count(D::publicLists(123456))===7,'New tenant did not inherit published integration guides');
 echo "PASS full fresh installer, 9 tables, repeated 8 migrations, registry/API/frontend/menu sync, preserved tenant menus, same membership and closed new tenant (",$created?'isolated schema':'isolated table prefix',")\n";
}finally{
 app()->config->set($original,'database');
 $restoreConfig=new think\Config();$restoreConfig->set($original,'database');Db::setConfig($restoreConfig);
 if($created)$pdo->exec('DROP DATABASE `'.$name.'`');
 elseif($prefix!=='la_'){foreach(array_reverse($fixtureTables) as $table)$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');}
}
