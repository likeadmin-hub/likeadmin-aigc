<?php
/** Local fresh-install and repeat-upgrade fixture, isolated by a random table prefix. */
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,'FAIL '.get_class($e).': '.mb_substr($e->getMessage(),0,220).PHP_EOL);exit(1);});
use think\facade\{Db,Config};
use app\common\service\app\{AppRegistryService as R,AppAccessService as A,DefaultAppService as D,AppFrontendManifestService as F};
$original=config('database');$mysql=$original['connections']['mysql'];
if(getenv('PRODUCT_VIDEO_LOCAL_TEST')!=='x_cn'||$mysql['hostname']!=='127.0.0.1'||$mysql['database']!=='x_cn')throw new RuntimeException('Explicit local test opt-in required');
$prefix='pvf_'.bin2hex(random_bytes(5)).'_';$code='aigc_product_video';$root=dirname(__DIR__);
$pdo=new PDO('mysql:host=127.0.0.1;port='.($mysql['hostport']?:3306).';dbname=x_cn;charset=utf8mb4',$mysql['username'],$mysql['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
function pvInstallCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
try{
 $pdo->exec(str_replace('`la_','`'.$prefix,file_get_contents($root.'/public/install/db/like.sql')));
 $cfg=$original;$cfg['connections']['mysql']['prefix']=$prefix;Config::set($cfg,'database');Db::connect(null,true);
 $app=Db::name('app')->where('code',$code)->find();pvInstallCheck($app && $app['status']==='installed' && (int)$app['is_builtin']===1,'Fresh app registry missing');
 $entries=Db::name('app_frontend_entry')->where('app_code',$code)->select()->toArray();pvInstallCheck(count($entries)===3,'Fresh frontend entry parity');
 foreach(json_decode(file_get_contents($root.'/app/apps/'.$code.'/api_schema.json'),true)['apis'] as $api){$n=Db::name('app_api')->where(['app_code'=>$code,'api_path'=>$api['api_path'],'scene'=>$api['scene'],'permission_key'=>$api['permission_key'],'api_method'=>$api['api_method']])->count();pvInstallCheck($n===1,'Fresh API missing '.$api['scene'].'/'.$api['api_path']);}
 foreach(['config','project','job','quote'] as $suffix)pvInstallCheck(count(Db::query('SHOW TABLES LIKE ?',[$prefix.$code.'_'.$suffix]))===1,'Fresh app table missing');
 foreach([600001,600002] as $id)Db::name('tenant')->insert(['id'=>$id,'sn'=>'pvf_'.$id,'name'=>'Product video fixture','tactics'=>$id===600002?1:0,'create_time'=>time(),'update_time'=>time()]);
 $pdo->exec('CREATE TABLE `'.$prefix.'tenant_system_menu_pvf_600002` LIKE `'.$prefix.'tenant_system_menu`');
 D::syncTenantDefaults(600001,'pvf_600001',false);D::syncTenantDefaults(600002,'pvf_600002',true);
 $checkTenant=function($id,$table)use($code){$r=Db::name('tenant_app')->where(['tenant_id'=>$id,'app_code'=>$code])->find();pvInstallCheck($r && $r['buy_status']==='paid' && $r['enable_status']==='enabled' && $r['shelf_status']==='on','New tenant grant missing');foreach(['pc','tenant','uniapp'] as $terminal)pvInstallCheck(count(array_filter(F::tenantEntries($id,$terminal),fn($e)=>$e['app_code']===$code))===1,'New tenant entry missing '.$terminal);pvInstallCheck(Db::name($table)->where(['tenant_id'=>$id,'app_code'=>$code,'source'=>'app'])->count()===6,'New tenant menu/permission parity');};
 $checkTenant(600001,'tenant_system_menu');$checkTenant(600002,'tenant_system_menu_pvf_600002');
 Db::name($code.'_config')->insert(['tenant_id'=>600001,'body_json'=>'{"status":0,"analysis_model_id":"owned-choice"}','create_time'=>1,'update_time'=>2]);
 $before=Db::name($code.'_config')->select()->toArray();
 Db::name('tenant_app')->where(['tenant_id'=>600001,'app_code'=>$code])->update(['shelf_status'=>'off','enable_status'=>'disabled']);
 for($i=0;$i<2;$i++){\app\common\service\database\SqlMigrationExecutor::execute(file_get_contents($root.'/upgrade/upgrade_20261110_aigc_product_video.sql'),$prefix);R::installFromLocal($code);}
 pvInstallCheck(Db::name($code.'_config')->select()->toArray()===$before,'Upgrade changed owned config');
 $grant=Db::name('tenant_app')->where(['tenant_id'=>600001,'app_code'=>$code])->find();pvInstallCheck($grant['shelf_status']==='off' && $grant['enable_status']==='disabled','Upgrade changed tenant shelf choice');
 pvInstallCheck(Db::name('app_api')->where('app_code',$code)->count()===count(json_decode(file_get_contents($root.'/app/apps/'.$code.'/api_schema.json'),true)['apis']),'Repeated install duplicated APIs');
 pvInstallCheck(in_array('aigc_product_video_job',\app\common\service\ai\AiTaskBusinessResultService::businessTables(),true),'Shared result adapter missing');
 echo "PASS product video fresh registry/schema/APIs, shared/split new tenants, repeat upgrade and owned configuration\n";
}finally{
 Config::set($original,'database');Db::connect(null,true);
 $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);foreach($tables as $table)if(str_starts_with($table,$prefix))$pdo->exec('DROP TABLE `'.$table.'`');
}
