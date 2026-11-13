<?php
/** Isolated random-prefix full install, new shared/split tenants and repeat installation. */
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,'FAIL '.get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);});
use think\facade\{Db,Config};
use app\common\service\app\{AppRegistryService as R,DefaultAppService as D,AppFrontendManifestService as F};
$original=config('database');$mysql=$original['connections']['mysql'];$code='aigc_store_visit';$root=dirname(__DIR__);
if(getenv('STORE_VISIT_LOCAL_TEST')!=='x_cn'||$mysql['hostname']!=='127.0.0.1'||$mysql['database']!=='x_cn')throw new RuntimeException('Explicit local opt-in required');
$prefix='scf_'.bin2hex(random_bytes(5)).'_';
$pdo=new PDO('mysql:host=127.0.0.1;port='.($mysql['hostport']?:3306).';dbname=x_cn;charset=utf8mb4',$mysql['username'],$mysql['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
function msiCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
try{
    $pdo->exec(str_replace('la_',$prefix,file_get_contents($root.'/public/install/db/like.sql')));
    $cfg=$original;$cfg['connections']['mysql']['prefix']=$prefix;Config::set($cfg,'database');Db::connect(null,true);
    foreach(['config','project','job','quote'] as $suffix)msiCheck(count(Db::query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$prefix.$code.'_'.$suffix]))===1,'Missing fresh schema: '.$suffix);
    msiCheck(Db::name('app')->where(['code'=>$code,'status'=>'installed'])->count()===1,'Fresh registry missing');
    $apiCount=count(json_decode(file_get_contents($root.'/app/apps/'.$code.'/api_schema.json'),true)['apis']);
    msiCheck(Db::name('app_api')->where('app_code',$code)->count()===$apiCount,'Fresh API parity');
    msiCheck(Db::name('app_frontend_entry')->where('app_code',$code)->count()===4,'Fresh frontend parity');
    foreach([600801,600802] as $id)Db::name('tenant')->insert(['id'=>$id,'sn'=>'msf_'.$id,'name'=>'Story commerce fixture','tactics'=>$id===600802?1:0,'create_time'=>time(),'update_time'=>time()]);
    $pdo->exec('CREATE TABLE `'.$prefix.'tenant_system_menu_msf_600802` LIKE `'.$prefix.'tenant_system_menu`');
    D::syncTenantDefaults(600801,'msf_600801',false);D::syncTenantDefaults(600802,'msf_600802',true);
    foreach([600801,600802] as $id){
        $app=Db::name('tenant_app')->where(['tenant_id'=>$id,'app_code'=>$code])->find();msiCheck($app&&$app['enable_status']==='enabled'&&$app['shelf_status']==='on','New tenant access missing');
        foreach(['pc','uniapp'] as $terminal){$entries=array_values(array_filter(F::tenantEntries($id,$terminal),static fn($e)=>$e['app_code']==='aigc_store_visit'));msiCheck(count($entries)===1,'New tenant entry missing');if($terminal==='uniapp')msiCheck(count($entries[0]['meta']['pages'])===2,'Mobile decoration pages missing');}
        $table=$id===600802?'tenant_system_menu_msf_600802':'tenant_system_menu';msiCheck(Db::name($table)->where(['tenant_id'=>$id,'app_code'=>$code,'source'=>'app'])->count()>10,'New tenant menu/permission missing');
    }
    Db::name('tenant_app')->where(['tenant_id'=>600801,'app_code'=>$code])->update(['shelf_status'=>'off']);
    R::installFromLocal($code);R::installFromLocal($code);
    msiCheck(Db::name('app_api')->where('app_code',$code)->count()===$apiCount,'Repeated install duplicated API contracts');
    msiCheck(Db::name('app_frontend_entry')->where('app_code',$code)->count()===4,'Repeated install duplicated entries');
    msiCheck(Db::name('tenant_app')->where(['tenant_id'=>600801,'app_code'=>$code])->value('shelf_status')==='off','Install overwrote tenant shelf choice');
    msiCheck(file_get_contents($root.'/upgrade/upgrade_20261113_aigc_store_visit.sql')===file_get_contents($root.'/public/upgrade/upgrade_20261113_aigc_store_visit.sql'),'Upgrade source drift');
    R::setStatus($code,R::STATUS_DISABLED);msiCheck(!R::isInstalled($code),'Disable lifecycle');
    R::setStatus($code,R::STATUS_INSTALLED);msiCheck(R::isInstalled($code),'Enable lifecycle');
    $project=Db::name($code.'_project')->insertGetId(['tenant_id'=>600801,'user_id'=>1,'body_json'=>'{}','revision'=>1,'create_time'=>time(),'update_time'=>time()]);
    $protected=false;try{R::uninstall($code,false);}catch(RuntimeException $e){$protected=str_contains($e->getMessage(),'系统应用');}
    msiCheck($protected,'Built-in default app must retain uninstall protection');
    msiCheck(Db::name($code.'_project')->where('id',$project)->count()===1,'Protected uninstall must preserve drafts');
    msiCheck(Db::name('app_frontend_entry')->where('app_code',$code)->count()===4,'Protected uninstall retains declared entries');
    echo "PASS fresh full install, new shared/split tenant grants, menus, mobile pages, repeat install, shelf preservation, enable/disable and protected built-in uninstall\n";
}finally{
    Config::set($original,'database');Db::connect(null,true);
    $statement=$pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,?)=?');$statement->execute([strlen($prefix),$prefix]);
    foreach($statement->fetchAll(PDO::FETCH_COLUMN) as $table)$pdo->exec('DROP TABLE `'.$table.'`');
}
