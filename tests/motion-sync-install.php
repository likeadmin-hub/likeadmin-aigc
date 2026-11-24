<?php
/** Isolated random-prefix full install, new shared/split tenants and repeat installation. */
require dirname(__DIR__).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,'FAIL '.get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);});
use think\facade\{Db,Config};
use app\common\service\app\{AppRegistryService as R,DefaultAppService as D,AppFrontendManifestService as F};
use app\common\service\database\SqlMigrationExecutor;
$original=config('database');$mysql=$original['connections']['mysql'];$code='aigc_motion_sync';$root=dirname(__DIR__);
if(getenv('MOTION_SYNC_LOCAL_TEST')!=='x_cn'||$mysql['hostname']!=='127.0.0.1'||$mysql['database']!=='x_cn')throw new RuntimeException('Explicit local opt-in required');
$prefix='msf_'.bin2hex(random_bytes(5)).'_';
$pdo=new PDO('mysql:host=127.0.0.1;port='.($mysql['hostport']?:3306).';dbname=x_cn;charset=utf8mb4',$mysql['username'],$mysql['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
function msiCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
try{
    $pdo->exec(str_replace('la_',$prefix,file_get_contents($root.'/public/install/db/like.sql')));
    $cfg=$original;$cfg['connections']['mysql']['prefix']=$prefix;Config::set($cfg,'database');Db::connect(null,true);
    foreach(['config','project','task','quote','result'] as $suffix)msiCheck(count(Db::query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$prefix.$code.'_'.$suffix]))===1,'Missing fresh schema: '.$suffix);
    msiCheck(Db::name('app')->where(['code'=>$code,'status'=>'installed'])->count()===1,'Fresh registry missing');
    $apiCount=count(json_decode(file_get_contents($root.'/app/apps/'.$code.'/api_schema.json'),true)['apis']);
    msiCheck(Db::name('app_api')->where('app_code',$code)->count()===$apiCount,'Fresh API parity');
    msiCheck(Db::name('app_frontend_entry')->where('app_code',$code)->count()===4,'Fresh frontend parity');
    foreach([600801,600802] as $id)Db::name('tenant')->insert(['id'=>$id,'sn'=>'msf_'.$id,'name'=>'Motion sync fixture','tactics'=>$id===600802?1:0,'create_time'=>time(),'update_time'=>time()]);
    $pdo->exec('CREATE TABLE `'.$prefix.'tenant_system_menu_msf_600802` LIKE `'.$prefix.'tenant_system_menu`');
    D::syncTenantDefaults(600801,'msf_600801',false);D::syncTenantDefaults(600802,'msf_600802',true);
    foreach([600801,600802] as $id){
        $app=Db::name('tenant_app')->where(['tenant_id'=>$id,'app_code'=>$code])->find();msiCheck($app&&$app['enable_status']==='enabled'&&$app['shelf_status']==='on','New tenant access missing');
        foreach(['pc','uniapp'] as $terminal){$entries=array_values(array_filter(F::tenantEntries($id,$terminal),static fn($e)=>$e['app_code']==='aigc_motion_sync'));msiCheck(count($entries)===1,'New tenant entry missing');if($terminal==='uniapp')msiCheck(count($entries[0]['meta']['pages'])===2,'Mobile decoration pages missing');}
        $table=$id===600802?'tenant_system_menu_msf_600802':'tenant_system_menu';
        $menuKeys=Db::name($table)->where(['tenant_id'=>$id,'app_code'=>$code,'source'=>'app'])->column('source_menu_key');
        msiCheck(in_array($code.'_config',$menuKeys,true)&&in_array($code.'_job',$menuKeys,true),'New tenant configuration or history menu missing');
        msiCheck(!array_filter($menuKeys,static fn($key)=>str_starts_with($key,$code.'_case')),'New tenant received a retired menu');
    }
    $route='app.aigc_motion_sync.case/lists';
    $migration=file_get_contents($root.'/app/apps/'.$code.'/migrations/20261124_retire_motion_sync_templates.sql');
    Db::name('app_api')->insert(['app_code'=>$code,'api_path'=>$route,'api_method'=>'GET','scene'=>'user']);
    $case=Db::name('app_case')->insertGetId(['app_code'=>$code,'title'=>'Retired fixture','status'=>1]);
    foreach (['system_menu'=>null,'tenant_system_menu'=>600801,'tenant_system_menu_msf_600802'=>600802] as $menu=>$tenant) {
        $row=['app_code'=>$code,'perms'=>$route,'name'=>'Retired fixture','source'=>'app'];
        if ($tenant!==null) $row['tenant_id']=$tenant;
        Db::name($menu)->insert($row);
        Db::name($menu)->insert(array_replace($row,['source'=>'tenant']));
    }
    SqlMigrationExecutor::execute($migration,$prefix);
    SqlMigrationExecutor::execute($migration,$prefix);
    msiCheck(!Db::name('app_api')->where(['app_code'=>$code,'api_path'=>$route])->count(),'Retired API survived upgrade');
    msiCheck((int)Db::name('app_case')->where('id',$case)->value('status')===0,'Template record must be unpublished and retained');
    foreach (['system_menu','tenant_system_menu','tenant_system_menu_msf_600802'] as $menu) {
        $rows=Db::name($menu)->where(['app_code'=>$code,'perms'=>$route])->column('source');
        msiCheck($rows===['tenant'],'Upgrade must prune only app-owned template menus');
    }
    Db::name('tenant_app')->where(['tenant_id'=>600801,'app_code'=>$code])->update(['shelf_status'=>'off']);
    R::installFromLocal($code);R::installFromLocal($code);
    msiCheck(Db::name('app_api')->where('app_code',$code)->count()===$apiCount,'Repeated install duplicated API contracts');
    msiCheck(Db::name('app_frontend_entry')->where('app_code',$code)->count()===4,'Repeated install duplicated entries');
    msiCheck(Db::name('tenant_app')->where(['tenant_id'=>600801,'app_code'=>$code])->value('shelf_status')==='off','Install overwrote tenant shelf choice');
    msiCheck(file_get_contents($root.'/upgrade/upgrade_20261110_aigc_motion_sync.sql')===file_get_contents($root.'/public/upgrade/upgrade_20261110_aigc_motion_sync.sql'),'Upgrade source drift');
    echo "PASS fresh install, shared/split tenant grants, idempotent template retirement, mobile pages and preserved tenant shelf state\n";
}finally{
    Config::set($original,'database');Db::connect(null,true);
    $statement=$pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,?)=?');$statement->execute([strlen($prefix),$prefix]);
    foreach($statement->fetchAll(PDO::FETCH_COLUMN) as $table)$pdo->exec('DROP TABLE `'.$table.'`');
}
