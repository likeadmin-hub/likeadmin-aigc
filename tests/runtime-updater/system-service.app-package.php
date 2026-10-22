<?php
declare(strict_types=1);
/** Real app packages after runtime activation; fresh processes verify the served code. */
function appCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function appWrite(string $path,string $bytes):void {if(!is_dir(dirname($path)))mkdir(dirname($path),0755,true);file_put_contents($path,$bytes);}
function appHashes(string $path):array {$result=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS)) as $f)if($f->isFile())$result[substr($f->getPathname(),strlen($path)+1)]=hash_file('sha256',$f->getPathname());ksort($result);return $result;}
function appChild(string $site,string $action,array $data=[]):array {
    $immutable=[];if(in_array($action,['apply','retry'],true))foreach(glob($site.'/.composer-runtime/generations/*') as $path)if(is_dir($path))$immutable[$path]=appHashes($path);
    $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--operation',$site,$action,json_encode($data)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    appCheck($exit===0,'App operation '.$action.' failed: '.$err.' '.$out);foreach($immutable as $path=>$hashes)appCheck(appHashes($path)===$hashes,'Existing immutable generation changed during '.$action.': '.$path);
    $marker='APP_RESULT ';$at=strrpos($out,$marker);appCheck($at!==false,'App operation exited without verification: '.$out);return json_decode(substr($out,$at+strlen($marker)),true,512,JSON_THROW_ON_ERROR);
}
appCheck(getenv('SYSTEM_SERVICE_DISPOSABLE')==='1','Disposable app fixture required');
if(($argv[1]??'')!=='--operation') {
    $site=$argv[1]??'/fixture/site';$packages=[];
    foreach(['a','b'] as $suffix)foreach(['1.0.0','1.0.1'] as $version) {
        $code='aigc_fixture_'.$suffix;$dir='/fixture/app-packages/'.$code.'-'.$version;
        appWrite($dir.'/manifest.json',json_encode(['code'=>$code,'name'=>'Runtime canary '.$suffix,'version'=>$version,'require_core'=>'>=1.0.391 <2.0.0','is_builtin'=>0,'frontends'=>[],'frontend_entries'=>[],'public_assets'=>[]]));
        appWrite($dir.'/RuntimeCanary.php','<?php namespace app\\apps\\'.$code.'; class RuntimeCanary { const VERSION="'.$version.'"; }');
        appWrite($dir.'/api_schema.json','[]');appWrite($dir.'/menus/tenant.json','[]');appWrite($dir.'/permissions/tenant.json','[]');
        appWrite($dir.'/menus/platform.json',json_encode([['source_menu_key'=>'fixture-'.$suffix,'name'=>'Runtime canary '.$suffix,'type'=>'C','paths'=>'fixture/'.$suffix,'perms'=>'fixture:'.$suffix]]));
        appWrite($dir.'/frontend/README','No public artifact needed for PHP generation canary');
        appWrite($dir.'/migrations/install.sql',"INSERT INTO la_config (type,name,value) VALUES ('app_runtime_fixture','".$code."','once');\n");
        appWrite($dir.'/signature.json',json_encode(['sha256'=>appHashes($dir)]));
        $archive=$dir.'.tar';$tar=new PharData($archive);$tar->buildFromDirectory($dir);unset($tar);$packages[$suffix][$version]=$archive;
    }
    appChild($site,'preflight-rejections',['archive'=>$packages['a']['1.0.0'],'code'=>'aigc_fixture_a']);
    $initial=appChild($site,'snapshot');$pointer=$initial['generation'];$canonical=$pointer===rtrim($site,'/');$generations=[];
    foreach(glob($site.'/.composer-runtime/generations/*') as $path)if(is_dir($path))$generations[$path]=appHashes($path);
    $a=appChild($site,'apply',['archive'=>$packages['a']['1.0.0'],'code'=>'aigc_fixture_a']);appCheck($a['status']==='applied','A install metadata missing');
    $seen=appChild($site,'observe',['a'=>'1.0.0']);appCheck($canonical?$seen['generation']===$pointer:$seen['generation']!==$pointer,'A install selected an invalid runtime');
    appChild($site,'apply',['archive'=>$packages['b']['1.0.0'],'code'=>'aigc_fixture_b']);appChild($site,'observe',['a'=>'1.0.0','b'=>'1.0.0']);
    appChild($site,'remove',['code'=>'aigc_fixture_a']);
    appChild($site,'apply',['archive'=>$packages['b']['1.0.1'],'code'=>'aigc_fixture_b']);$beforeReinstall=appChild($site,'observe',['b'=>'1.0.1','removed'=>'aigc_fixture_a']);
    // Exact same archive hash, but a new authorized install operation after removal.
    $aReinstalled=appChild($site,'apply',['archive'=>$packages['a']['1.0.0'],'code'=>'aigc_fixture_a']);
    appCheck($aReinstalled['id']!==$a['id'] && $aReinstalled['sha256']===$a['sha256'],'Reinstall did not use same bytes under a new package id');
    $seen=appChild($site,'observe',['a'=>'1.0.0','b'=>'1.0.1']);appCheck($canonical?$seen['generation']===$pointer:$seen['generation']!==$beforeReinstall['generation'],'Same-ZIP reinstall selected an obsolete runtime');
    appCheck($seen['b_app']===$beforeReinstall['b_app'],'A reinstall changed B metadata');
    $beforeFailure=appChild($site,'snapshot');
    $blocked=appChild($site,'apply',['archive'=>$packages['a']['1.0.1'],'code'=>'aigc_fixture_a','precheck_fail'=>true]);
    appCheck($blocked['status']==='apply_failed','Candidate validation failure not recorded');
    appCheck(appChild($site,'snapshot')===$beforeFailure,'Read-only candidate validation failure changed app/business state');
    $failed=appChild($site,'retry',['id'=>$blocked['id'],'fail'=>true]);appCheck($failed['status']==='apply_failed','App failure metadata missing');
    $afterFailure=appChild($site,'snapshot');appCheck($beforeFailure===$afterFailure,'App final metadata failure changed pointer, app/version/tenant/menu metadata');
    appChild($site,'observe',['a'=>'1.0.0','b'=>'1.0.1']);
    $retry=appChild($site,'retry',['id'=>$failed['id']]);appCheck($retry['status']==='applied','App retry not completed');
    appChild($site,'observe',['a'=>'1.0.1','b'=>'1.0.1']);
    $counts=appChild($site,'migration-counts');appCheck($counts===['a'=>1,'b'=>1],'App SQL migrations replayed during update/reinstall/retry');
    foreach($generations as $path=>$hashes)appCheck(appHashes($path)===$hashes,'Initial immutable generation mutated: '.$path);
    $final=appChild($site,'business');appCheck($final===$initial['business'],'App operations modified protected business records/site bytes');
    echo "PASS actual AppPackage APIs: A install/remove/same-ZIP reinstall, intervening B update retained, fresh canary code and menus, new package/task success, preflight rejection, candidate validation safe retry, metadata failure rollback/retry, migrations once, protected business state intact\n";exit(0);
}
$site=$argv[2];$action=$argv[3];$data=json_decode($argv[4]??'{}',true,512,JSON_THROW_ON_ERROR);
$GLOBALS['app_operation_completed']=false;register_shutdown_function(static function(){if(empty($GLOBALS['app_operation_completed'])){fwrite(STDERR,"App operation did not complete\n");exit(1);}});
require $site.'/runtime-loader.php';(new AigcRuntimeApp($site))->initialize();set_exception_handler(static function(Throwable $e):void {fwrite(STDERR,(string)$e."\n");exit(1);});
use think\facade\Db;
use app\common\service\update\AppPackageUpdateService;
use app\common\service\app\AppRegistryService;
appCheck(root_path()===$site.'/' && str_starts_with((string)config('database.connections.mysql.database'),'fixture_'),'App fixture escaped site/database');
request()->withServer(array_merge(request()->server(),['HTTP_HOST'=>'system.fixture','SERVER_NAME'=>'system.fixture']));
function appBusiness(string $site):array {
    $result=[];foreach(['tenant','user','user_account_log','recharge_order','aigc_short_drama_episode_task','aigc_short_drama_generation_task'] as $table)$result[$table]=Db::name($table)->order('id')->select()->toArray();
    $result['paid_nondefault_app']=Db::name('tenant_app')->where(['tenant_id'=>901,'app_code'=>'aigc_music'])->find();
    $state=is_file('/fixture/state.json')?json_decode(file_get_contents('/fixture/state.json'),true,512,JSON_THROW_ON_ERROR):['customer_configuration'=>[]];
    foreach($state['customer_configuration'] as $table=>$rows)$result['customer_configuration'][$table]=Db::name($table)->whereIn('id',array_column($rows,'id'))->order('id')->select()->toArray();
    foreach(['.env','config/install.lock','upgrade/version.json','public/uploads/business.bin','runtime/task-lease.json','oem/brand.json'] as $path)$result[$path]=is_file($site.'/'.$path)?hash_file('sha256',$site.'/'.$path):null;return $result;
}
$service=new AppPackageUpdateService();$result=[];
if($action==='apply' || $action==='retry') {
    if($action==='apply'){$package=$service->saveUploadedPackage($data['archive'],'tar');$id=(int)$package['id'];$preflight=$service->preflight($id,$data['code']);appCheck($preflight['passed'],'Canary preflight failed: '.json_encode($preflight['errors']));}
    else {$id=(int)$data['id'];Db::execute('DROP TRIGGER IF EXISTS fixture_app_metadata_failure');}
    if(!empty($data['fail']))Db::execute("CREATE TRIGGER fixture_app_metadata_failure BEFORE UPDATE ON la_update_task FOR EACH ROW BEGIN IF NEW.type='app' AND NEW.action='apply' AND NEW.status='success' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture app final metadata failure'; END IF; END");
    if(!empty($data['precheck_fail']))Db::execute('ALTER TABLE la_app_version ENGINE=MyISAM');
    try{$service->apply($id);appCheck(empty($data['fail']) && empty($data['precheck_fail']),'App failure gate was bypassed');}
    catch(Throwable $error){appCheck((!empty($data['fail']) && str_contains($error->getMessage(),'fixture app final metadata failure')) || (!empty($data['precheck_fail']) && str_contains($error->getMessage(),'InnoDB')),'Wrong app apply failure: '.$error->getMessage());}
    finally {if(!empty($data['precheck_fail']))Db::execute('ALTER TABLE la_app_version ENGINE=InnoDB');}
    $result=Db::name('update_package')->where('id',$id)->find();$task=Db::name('update_task')->where(['package_id'=>$id,'action'=>'apply'])->order('id','desc')->find();
    appCheck($task['status']===(empty($data['fail']) && empty($data['precheck_fail'])?'success':'failed'),'App task status inconsistent');
} elseif($action==='preflight-rejections') {
    $package=$service->saveUploadedPackage($data['archive'],'tar');
    Db::execute('ALTER TABLE la_app_version ENGINE=MyISAM');
    try {
        $preflight=$service->preflight((int)$package['id'],$data['code']);
        appCheck(!$preflight['passed'] && str_contains(implode(';',$preflight['errors']),'InnoDB'),'Nontransactional metadata was green in preflight');
        appCheck(Db::name('app_migration')->where('app_code',$data['code'])->count()===0,'Preflight wrote migration markers');
    } finally {Db::execute('ALTER TABLE la_app_version ENGINE=InnoDB');}
    $result=['passed'=>true];
} elseif($action==='remove'){AppRegistryService::uninstall($data['code'],false);appCheck(Db::name('app')->where('code',$data['code'])->value('status')==='removed','App remove failed');}
elseif($action==='snapshot') {
    $result=['generation'=>$GLOBALS['AIGC_RUNTIME_GENERATION'],'business'=>appBusiness($site)];
    foreach(['app','app_version','tenant_app','system_menu'] as $table){$field=$table==='app'?'code':'app_code';$result[$table]=Db::name($table)->where($field,'like','aigc_fixture_%')->order('id')->select()->toArray();}
} elseif($action==='business')$result=appBusiness($site);
elseif($action==='migration-counts'){$result=['a'=>Db::name('config')->where(['type'=>'app_runtime_fixture','name'=>'aigc_fixture_a'])->count(),'b'=>Db::name('config')->where(['type'=>'app_runtime_fixture','name'=>'aigc_fixture_b'])->count()];}
elseif($action==='observe') {
    $result=['generation'=>$GLOBALS['AIGC_RUNTIME_GENERATION'],'b_app'=>Db::name('app')->where('code','aigc_fixture_b')->find()];
    foreach(['a','b'] as $suffix)if(isset($data[$suffix])) {
        $code='aigc_fixture_'.$suffix;$class='app\\apps\\'.$code.'\\RuntimeCanary';$app=Db::name('app')->where('code',$code)->find();
        appCheck($app['current_version']===$data[$suffix] && $app['status']==='installed','Canary metadata not installed: '.$code);
        appCheck(class_exists($class) && constant($class.'::VERSION')===$data[$suffix],'Fresh canary PHP version mismatch: '.$code);
        appCheck(str_starts_with((new ReflectionClass($class))->getFileName(),$GLOBALS['AIGC_RUNTIME_GENERATION'].'/app/apps/'),'Canary PHP escaped active generation');
        appCheck(Db::name('system_menu')->where(['app_code'=>$code,'source_menu_key'=>'fixture-'.$suffix])->count()===1,'Canary menu metadata missing');
    }
    if(isset($data['removed']))appCheck(Db::name('app')->where('code',$data['removed'])->value('status')==='removed','Removed A state was lost during B update');
} else throw new RuntimeException('Unknown app operation');
$GLOBALS['app_operation_completed']=true;echo 'APP_RESULT '.json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
