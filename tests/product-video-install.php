<?php
/** Local fresh-install and repeat-upgrade fixture, isolated by a random table prefix. */
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,'FAIL '.get_class($e).': '.mb_substr($e->getMessage(),0,220).PHP_EOL);exit(1);});
use think\facade\{Db,Config};
use app\common\service\app\{AppRegistryService as R,AppAccessService as A,DefaultAppService as D,AppFrontendManifestService as F};
$original=config('database');$mysql=$original['connections']['mysql'];
if(getenv('PRODUCT_VIDEO_LOCAL_TEST')!=='x_cn'||$mysql['hostname']!=='127.0.0.1'||$mysql['database']!=='x_cn')throw new RuntimeException('Explicit local test opt-in required');
$portableModel=Db::name('power_market_product')->where('model_type','video')->find();
$catalog=sys_get_temp_dir().'/pv_install_builtin_'.bin2hex(random_bytes(8));mkdir($catalog,0700);
$prefix='pvf_'.bin2hex(random_bytes(5)).'_';$code='aigc_product_video';$root=dirname(__DIR__);
$pdo=new PDO('mysql:host=127.0.0.1;port='.($mysql['hostport']?:3306).';dbname=x_cn;charset=utf8mb4',$mysql['username'],$mysql['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
function pvInstallCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
try{\app\common\service\app\aigc_product_video\ProductVideoBuiltinCatalog::withDirectory($catalog,static function()use($pdo,$root,$prefix,$original,$code,$portableModel){
 $pdo->exec(str_replace('la_',$prefix,file_get_contents($root.'/public/install/db/like.sql')));
 $cfg=$original;$cfg['connections']['mysql']['prefix']=$prefix;Config::set($cfg,'database');Db::connect(null,true);
 $selection=['model_id'=>'','quality'=>'720P','duration'=>8,'ratio'=>'9:16'];
 $definition=['schema_version'=>1,'key'=>'builtin-'.bin2hex(random_bytes(12)),'version'=>1,'title'=>'跨站点安装测试','status'=>1,'model_name'=>'Portable model',
 'model_identity'=>array_intersect_key($portableModel,array_flip(['source_code','upstream_resource_key','resource_type','upstream_app_code'])),
 'configuration'=>['images'=>[['uri'=>'shared/product.png','storage_engine'=>'aliyun','storage_domain'=>'https://shared-assets.example.com']],'prompt'=>'自己的商品展示脚本','language'=>'中文','video_selection'=>$selection],
 'media'=>['uri'=>'shared/preview.mp4','storage_engine'=>'aliyun','storage_domain'=>'https://shared-assets.example.com']];
 \app\common\service\app\aigc_product_video\ProductVideoBuiltinCatalog::write($definition);
 \app\common\service\app\aigc_product_video\ProductVideoBuiltins::sync(true);
 $global=Db::name('app_case')->where(['tenant_id'=>0,'app_code'=>$code])->find();pvInstallCheck($global!==null,'Source definition not installed on a clean site without any original tenant/job');
 pvInstallCheck(json_decode($global['config_json'],true)['generation_request']['model_id']==='','Unavailable model waits for market sync');
 $oldModelId=(int)$portableModel['id'];$portableModel['id']=$oldModelId+900000;Db::name('power_market_product')->insert($portableModel);
 \app\common\service\app\aigc_product_video\ProductVideoBuiltins::sync(true);
 $global=\app\common\service\app\aigc_product_video\ProductVideoTemplates::detail(0,(int)$global['id']);
 $expected=$portableModel['resource_type']==='model'?'market_video_model:'.$portableModel['id']:'market_video_app:'.$portableModel['upstream_app_code'].':'.$portableModel['id'];
 pvInstallCheck($global['configuration']['video_selection']['model_id']===$expected,'New installation must map stable resource identity to a different local numeric model ID');
 pvInstallCheck($global['cover_url']==='https://shared-assets.example.com/shared/product.png'&&$global['media_url']==='https://shared-assets.example.com/shared/preview.mp4','Independent sites read the same OSS URLs without storage credentials or files');
 pvInstallCheck(Db::name('tenant_file')->count()===0,'Installing a builtin cannot copy media or user asset ownership rows');
 $app=Db::name('app')->where('code',$code)->find();pvInstallCheck($app && $app['status']==='installed' && (int)$app['is_builtin']===1,'Fresh app registry missing');
 $entries=Db::name('app_frontend_entry')->where('app_code',$code)->select()->toArray();pvInstallCheck(count($entries)===3,'Fresh frontend entry parity');
 foreach(json_decode(file_get_contents($root.'/app/apps/'.$code.'/api_schema.json'),true)['apis'] as $api){$n=Db::name('app_api')->where(['app_code'=>$code,'api_path'=>$api['api_path'],'scene'=>$api['scene'],'permission_key'=>$api['permission_key'],'api_method'=>$api['api_method']])->count();pvInstallCheck($n===1,'Fresh API missing '.$api['scene'].'/'.$api['api_path']);}
 foreach(['config','project','job','quote','builtin'] as $suffix)pvInstallCheck(count(Db::query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$prefix.$code.'_'.$suffix]))===1,'Fresh app table missing');
 foreach([600001,600002] as $id)Db::name('tenant')->insert(['id'=>$id,'sn'=>'pvf_'.$id,'name'=>'Product video fixture','tactics'=>$id===600002?1:0,'create_time'=>time(),'update_time'=>time()]);
 $pdo->exec('CREATE TABLE `'.$prefix.'tenant_system_menu_pvf_600002` LIKE `'.$prefix.'tenant_system_menu`');
 D::syncTenantDefaults(600001,'pvf_600001',false);D::syncTenantDefaults(600002,'pvf_600002',true);
 $checkTenant=function($id,$table)use($code){$r=Db::name('tenant_app')->where(['tenant_id'=>$id,'app_code'=>$code])->find();pvInstallCheck($r && $r['buy_status']==='paid' && $r['enable_status']==='enabled' && $r['shelf_status']==='on','New tenant grant missing');foreach(['pc','tenant','uniapp'] as $terminal)pvInstallCheck(count(array_filter(F::tenantEntries($id,$terminal),fn($e)=>$e['app_code']===$code))===1,'New tenant entry missing '.$terminal);pvInstallCheck(Db::name($table)->where(['tenant_id'=>$id,'app_code'=>$code,'source'=>'app'])->count()===count(array_merge(...array_map(static function($root){$items=[$root];foreach($root['children']??[] as $child){$items[]=$child;foreach($child['children']??[] as $action)$items[]=$action;}return $items;},json_decode(file_get_contents(dirname(__DIR__).'/app/apps/aigc_product_video/menus/tenant.json'),true)))),'New tenant menu/permission parity');};
 $checkTenant(600001,'tenant_system_menu');$checkTenant(600002,'tenant_system_menu_pvf_600002');
 foreach([600001,600002] as $id){$list=\app\common\service\app\aigc_product_video\ProductVideoTemplates::lists($id,[],true);pvInstallCheck(in_array($global['id'],array_column($list['lists'],'id')),'Each independently installed shared/split tenant sees the platform builtin');}
 pvInstallCheck(Db::name($code.'_builtin')->count()===1&&Db::name('app_case')->where('app_code',$code)->count()===1,'New tenants never duplicate global template rows');
 Db::name($code.'_config')->insert(['tenant_id'=>600001,'body_json'=>'{"status":0,"analysis_model_id":"owned-choice"}','create_time'=>1,'update_time'=>2]);
 $before=Db::name($code.'_config')->select()->toArray();
 Db::name('tenant_app')->where(['tenant_id'=>600001,'app_code'=>$code])->update(['shelf_status'=>'off','enable_status'=>'disabled']);
 for($i=0;$i<2;$i++){\app\common\service\database\SqlMigrationExecutor::execute(file_get_contents($root.'/upgrade/upgrade_20261110_aigc_product_video.sql'),$prefix);R::installFromLocal($code);}
 pvInstallCheck(Db::name($code.'_config')->select()->toArray()===$before,'Upgrade changed owned config');
 $grant=Db::name('tenant_app')->where(['tenant_id'=>600001,'app_code'=>$code])->find();pvInstallCheck($grant['shelf_status']==='off' && $grant['enable_status']==='disabled','Upgrade changed tenant shelf choice');
 pvInstallCheck(Db::name('app_api')->where('app_code',$code)->count()===count(json_decode(file_get_contents($root.'/app/apps/'.$code.'/api_schema.json'),true)['apis']),'Repeated install duplicated APIs');
 pvInstallCheck(in_array('aigc_product_video_job',\app\common\service\ai\AiTaskBusinessResultService::businessTables(),true),'Shared result adapter missing');
 Db::name($code.'_config')->insert(['tenant_id'=>600002,'body_json'=>'{"status":1,"analysis_model_id":"keep-model","default_image_model_id":"removed-model"}','create_time'=>1,'update_time'=>2]);
 for($i=0;$i<2;$i++)\app\common\service\database\SqlMigrationExecutor::execute(file_get_contents($root.'/upgrade/upgrade_20261110_product_video_remove_storyboard.sql'),$prefix);
 $clean=json_decode(Db::name($code.'_config')->where('tenant_id',600002)->value('body_json'),true);
 pvInstallCheck(!isset($clean['default_image_model_id'])&&$clean['analysis_model_id']==='keep-model'&&$clean['status']===1,'Retired image setting cleanup must preserve unrelated owned choices');
 $manifest=json_decode(Db::name('app_version')->where(['app_code'=>$code,'version'=>'1.0.0'])->value('manifest_json'),true);
 pvInstallCheck(!in_array('storyboard',$manifest['billing']['actions'],true)&&!in_array('aigc_image',array_column($manifest['dependencies'],'app_code'),true),'Registry metadata must match the text-only workflow');
 pvInstallCheck(Db::name($code.'_builtin')->count()===1,'Repeated updates duplicated a portable builtin');
 echo "PASS portable builtin OSS URLs, different site model IDs, fresh registry/schema/APIs, shared/split new tenants, repeat upgrade and owned configuration\n";
 });
}finally{
 foreach(glob($catalog.'/*') as $file)unlink($file);rmdir($catalog);
 Config::set($original,'database');Db::connect(null,true);
 $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);foreach($tables as $table)if(str_starts_with($table,$prefix))$pdo->exec('DROP TABLE `'.$table.'`');
}
