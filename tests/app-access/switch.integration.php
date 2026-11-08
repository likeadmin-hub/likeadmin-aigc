<?php
/** Run only in the disposable Docker DB, with the workspace .env masked. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new think\App())->initialize();
set_exception_handler(static function(Throwable $e): void { fwrite(STDERR, 'FAIL '.$e->getMessage().PHP_EOL); exit(1); });
use think\facade\Db;
use think\facade\Config;
use app\common\service\app\AppAccessService as Access;
use app\common\service\app\DefaultAppService as Defaults;
use app\common\service\app\AppRegistryService as Registry;
use app\api\http\middleware\AppAccessMiddleware;
if (getenv('APP_ACCESS_DISPOSABLE_TEST') !== '1') throw new RuntimeException('Disposable runner required');
$config=config('database');
$config['connections']['mysql']=array_replace($config['connections']['mysql'], ['hostname'=>'127.0.0.1','hostport'=>3306,'database'=>'app_access_fixture','username'=>'root','password'=>'','trigger_sql'=>false]);
Config::set($config,'database'); Db::connect(null,true);
$pdo=new PDO('mysql:host=127.0.0.1;dbname=app_access_fixture;charset=utf8mb4','root', '');
if (!preg_match('/^[a-f0-9-]{36}$/iD', (string)getenv('APP_ACCESS_DB_UUID')) || $pdo->query('SELECT @@server_uuid')->fetchColumn() !== getenv('APP_ACCESS_DB_UUID')) throw new RuntimeException('Private MySQL identity differs');
$schema=file_get_contents(dirname(__DIR__,2).'/public/install/db/like.sql');
foreach(['app','tenant_app','tenant_app_order','tenant','tenant_system_menu','tenant_system_role_menu','app_frontend_entry','app_install','app_api','app_version','membership_plan','membership_plan_app','tenant_app_config','app_migration','app_plan','aigc_llm_session','aigc_llm_agent_run','aigc_llm_agent_event','tenant_brand_order','tenant_brand_job','tenant_brand_event','open_platform_setting'] as $name) {
 if (!preg_match('~^CREATE TABLE(?: IF NOT EXISTS)? `la_'.preg_quote($name,'~').'`\s*\(.*?^\)\s*ENGINE\s*=\s*InnoDB\b[^;]*;~msi',$schema,$m)) throw new RuntimeException('Missing fixture DDL '.$name);
 $pdo->exec('DROP TABLE IF EXISTS `la_'.$name.'`'); $pdo->exec($m[0]);
}
// The installer keeps OEM v2 columns additive; replay only that table's source DDL.
preg_match_all('/^ALTER TABLE `la_tenant_brand_(?:order|event)` ADD COLUMN [^;]+;/m',$schema,$oemColumns);
foreach($oemColumns[0] as $ddl)$pdo->exec($ddl);
$checks=0;$failures=[];
function check($ok,string $why):void {global $checks,$failures;$checks++;if(!$ok)$failures[]=$why;}
foreach([9101,9102] as $tenant) Db::name('tenant')->insert(['id'=>$tenant,'sn'=>'access_'.$tenant,'name'=>'fixture','tactics'=>0,'create_time'=>time()]);
$codes=Access::DEFAULT_APP_CODES;
foreach($codes as $code) {
 Db::name('app')->insert(['code'=>$code,'name'=>$code,'status'=>'installed','is_builtin'=>1,'current_version'=>'1.0.0']);
 foreach([9101,9102] as $tenant) Db::name('tenant_app')->insert(['tenant_id'=>$tenant,'app_code'=>$code,'version'=>'1.0.0','buy_status'=>'paid','shelf_status'=>'on','enable_status'=>'enabled']);
 foreach(['pc','uniapp','tenant'] as $terminal) Db::name('app_frontend_entry')->insert(['app_code'=>$code,'terminal'=>$terminal,'path'=>'/app/'.$code,'status'=>1,'meta'=>'{}']);
}
require __DIR__.'/configuration-matrix.php';
$upsert=new ReflectionMethod(Defaults::class,'upsertTenantApp');$upsert->setAccessible(true);
foreach(array_diff($codes,['system_default']) as $code) {
 Db::name('tenant_app')->where(['tenant_id'=>9101,'app_code'=>$code])->update(['shelf_status'=>'off','enable_status'=>'disabled','expire_time'=>123]);
 check(!Access::tenantCanUse(9101,$code,false),'closed default usable: '.$code);
 check(Access::tenantCanUse(9102,$code,false),'tenant isolation: '.$code);
 foreach(['pc','uniapp','tenant'] as $terminal) check(!in_array($code,array_column(Registry::frontendEntries(9101,$terminal),'app_code'),true),'closed frontend entry: '.$code.'/'.$terminal);
 Defaults::ensureTenantDefaultApp(9101,$code);$upsert->invoke(null,9101,$code,'1.0.1');
 $row=Db::name('tenant_app')->where(['tenant_id'=>9101,'app_code'=>$code])->find();
 check($row['shelf_status']==='off'&&$row['enable_status']==='disabled'&&(int)$row['expire_time']===123,'default ensure/update reopened: '.$code);
 check(Access::tenantCanManage(9101,$code),'closed tenant cannot manage '.$code);
 Db::name('tenant_app')->where(['tenant_id'=>9101,'app_code'=>$code])->update(['shelf_status'=>'on','enable_status'=>'enabled']);
 check(Access::tenantCanUse(9101,$code,false),'reopened default unavailable: '.$code);
 try {Registry::setStatus($code,'disabled');check(true,'platform close');}catch(Throwable $e){check(false,'platform cannot close '.$code);Db::name('app')->where('code',$code)->update(['status'=>'disabled']);}
 check(!Access::tenantCanUse(9101,$code,false),'platform closed usable: '.$code);
 check(Access::tenantCanManage(9101,$code),'platform closed prevents management: '.$code);
 $normalized=Defaults::normalizeAppData($code,['status'=>'installed']);check($normalized['status']==='disabled','update normalized disabled app to installed: '.$code);
 try {Registry::setStatus($code,'installed');} catch(Throwable $e) {Db::name('app')->where('code',$code)->update(['status'=>'installed']);}
 check(Access::tenantCanUse(9101,$code,false),'platform reopen unavailable: '.$code);
}
try{Registry::setStatus('system_default','disabled');check(false,'system default disabled');}catch(RuntimeException $e){check(true,'system default protected');}
Db::name('tenant_app')->where(['tenant_id'=>9101,'app_code'=>'aigc_image'])->update(['shelf_status'=>'off']);
function requestFor(string $controller,string $action,string $method='GET'):object {return new class($controller,$action,$method) {public int $tenantId=9101;public int $userId=0; public function __construct(private string $c,private string $a,private string $m){}public function controller(){return $this->c;}public function action(){return $this->a;}public function method(){return $this->m;}public function post($key,$default=''){return $key==='app_code'?'aigc_product_image':$default;}};}
$middleware=new AppAccessMiddleware();
foreach([['Config','detail','GET'],['Generate','submit','POST'],['Task','retry','POST']] as [$controller,$action,$method]) {
 $response=$middleware->handle(requestFor('App.aigc_image.'.$controller,$action,$method),fn()=>null);
 check($response instanceof think\Response && $response->getData()['msg']==='当前应用已关闭','closed API allowed/wrong error: '.$controller.'/'.$action);
}
foreach(['lists','detail'] as $action) check($middleware->handle(requestFor('App.aigc_image.Task',$action),fn()=>'historical')==='historical','historical polling blocked '.$action);
$controller=(new ReflectionClass(\app\tenantapi\controller\AppController::class))->newInstanceWithoutConstructor();
$tenantProperty=new ReflectionProperty($controller,'tenantId');$tenantProperty->setAccessible(true);$tenantProperty->setValue($controller,9101);
$requestProperty=new ReflectionProperty($controller,'request');$requestProperty->setAccessible(true);
foreach(array_diff($codes,['system_default']) as $code) {
 check(Access::isShelfableDefaultApp($code),'default app not shelfable: '.$code);
 foreach(['off','on'] as $state) {
  $request=(new think\Request())->withPost(['app_code'=>$code,'shelf_status'=>$state]);$request->setMethod('POST');$requestProperty->setValue($controller,$request);
  check($controller->shelf()->getData()['code']===1,'shelf controller rejected '.$code.'/'.$state);
  check(Db::name('tenant_app')->where(['tenant_id'=>9101,'app_code'=>$code])->value('shelf_status')===$state,'shelf controller ignored '.$code.'/'.$state);
 }
}
// Exercise the updater's actual SQL path, including the older unconditional seed pattern.
Registry::setStatus('aigc_image','disabled');
Db::name('tenant_app')->where(['tenant_id'=>9101,'app_code'=>'aigc_image'])->update(['shelf_status'=>'off','enable_status'=>'disabled','expire_time'=>123]);
$migrationDirectory=sys_get_temp_dir().'/app-access-'.bin2hex(random_bytes(8));mkdir($migrationDirectory);
try {
 file_put_contents($migrationDirectory.'/fixture.sql', "UPDATE la_app SET status='installed' WHERE code='aigc_image'; UPDATE la_tenant_app SET shelf_status='on',enable_status='enabled',expire_time=0 WHERE app_code='aigc_image';");
 $method=new ReflectionMethod(\app\common\service\update\AppPackageUpdateService::class,'runSqlMigrations');$method->setAccessible(true);
 $method->invoke(new \app\common\service\update\AppPackageUpdateService(),$migrationDirectory,'aigc_image','fixture');
 check(Db::name('app')->where('code','aigc_image')->value('status')==='disabled','SQL updater reopened disabled platform app');
 $row=Db::name('tenant_app')->where(['tenant_id'=>9101,'app_code'=>'aigc_image'])->find();
 check($row['shelf_status']==='off'&&$row['enable_status']==='disabled'&&(int)$row['expire_time']===123,'SQL updater overwrote existing tenant policy');
} finally {unlink($migrationDirectory.'/fixture.sql');rmdir($migrationDirectory);}
// The legacy GEO market card is a display-only entry; it never grants generation.
Db::name('app')->insert(['code'=>'aigc_geo','name'=>'GEO fixture','status'=>'installed','current_version'=>'1.0.2']);
Db::name('tenant_app_config')->insert(['tenant_id'=>9101,'app_code'=>'aigc_geo','status'=>1]);
check(in_array('aigc_geo',array_column(Registry::frontendEntries(9101,'pc'),'app_code'),true),'legacy GEO display entry disappeared');
check(!Access::tenantCanUse(9101,'aigc_geo',false),'display-only GEO granted execution');
Db::name('tenant_app')->insert(['tenant_id'=>9101,'app_code'=>'aigc_geo','buy_status'=>'paid','shelf_status'=>'off','enable_status'=>'enabled','expire_time'=>time()+3600]);
check(!in_array('aigc_geo',array_column(Registry::frontendEntries(9101,'pc'),'app_code'),true),'GEO display preview bypassed existing tenant shelf off');
Db::name('tenant_app')->where(['tenant_id'=>9101,'app_code'=>'aigc_geo'])->update(['shelf_status'=>'on']);
check(Access::tenantCanUse(9101,'aigc_geo',false),'valid GEO execution grant unavailable');
Db::name('tenant_app_config')->where(['tenant_id'=>9101,'app_code'=>'aigc_geo'])->update(['status'=>0]);
check(!in_array('aigc_geo',array_column(Registry::frontendEntries(9101,'pc'),'app_code'),true),'closed GEO display entry visible');
Db::name('tenant_app_config')->where(['tenant_id'=>9101,'app_code'=>'aigc_geo'])->update(['status'=>1]);Registry::setStatus('aigc_geo','disabled');
check(!in_array('aigc_geo',array_column(Registry::frontendEntries(9101,'pc'),'app_code'),true),'platform closed GEO display entry visible');
Db::name('tenant_app')->where(['tenant_id'=>9101,'app_code'=>'aigc_product_image'])->update(['shelf_status'=>'off']);
$zipResponse=$middleware->handle(requestFor('App.ai_tool_download','zip','POST'),fn()=>'zip-created');
check($zipResponse instanceof think\Response && $zipResponse->getData()['msg']==='当前应用已关闭','download ZIP bypassed owner app switch');
check($middleware->handle(requestFor('App.ai_tool_download','export'),fn()=>'existing-download')==='existing-download','existing download token blocked');
$lock='app-access:'.substr(hash('sha256','app_access_fixture:la_'),0,40);
$statement=$pdo->prepare('SELECT GET_LOCK(?,0)');$statement->execute([$lock]);check((int)$statement->fetchColumn()===1,'second-session lock unavailable');
try {
 $pdo->exec('CREATE TABLE app_access_ddl_barrier (id int PRIMARY KEY) ENGINE=InnoDB');
 $pdo->exec('ALTER TABLE app_access_ddl_barrier ADD COLUMN marker int NOT NULL DEFAULT 0');
 foreach([
  fn()=>\app\common\service\app\AppPlanService::openOrRenew(9101,0,'aigc_geo',0),
  fn()=>\app\common\service\app\AppPlanService::grantWithoutCharge(9101,0,'aigc_geo',0,'fixture'),
 ] as $purchase) {
  try {$purchase();check(false,'concurrent entitlement writer bypassed update lock');}catch(RuntimeException $e){check($e->getMessage()==='应用状态正在更新，请稍后重试','purchase did not acquire lock before entitlement work');}
 }
 try {Registry::setStatus('aigc_image','installed');check(false,'concurrent platform writer ignored updater lock');} catch(RuntimeException $e) {check($e->getMessage()==='应用状态正在更新，请稍后重试','wrong lock error');}
 $requestProperty->setValue($controller,(new think\Request())->withPost(['app_code'=>'aigc_image','shelf_status'=>'on']));
 check($controller->shelf()->getData()['code']===0,'concurrent tenant writer ignored updater lock');
 check(Db::name('app')->where('code','aigc_image')->value('status')==='disabled','locked writer altered platform switch');
} finally {$pdo->exec('DROP TABLE IF EXISTS app_access_ddl_barrier');$statement=$pdo->prepare('SELECT RELEASE_LOCK(?)');$statement->execute([$lock]);}
\app\common\service\app\AppAccessStateService::withSwitchLock(fn()=>\app\common\service\app\AppAccessStateService::withSwitchLock(fn()=>null));
$statement=$pdo->prepare('SELECT IS_FREE_LOCK(?)');$statement->execute([$lock]);check((int)$statement->fetchColumn()===1,'nested same-session lock leaked');
// Closed apps remain listed truthfully in the owner management center.
$requestProperty->setValue($controller,new think\Request());
foreach(['my','market'] as $action) {
 $items=$controller->$action()->getData()['data'];
 $byCode=[];foreach($items as $item)$byCode[$item['app_code']??$item['code']]=$item;
 check(isset($byCode['aigc_image']) && $byCode['aigc_image']['can_shelf']===1,'closed default missing owner switch: '.$action);
 check(($byCode['aigc_image']['platform_status']??'')==='disabled','owner catalog masked platform switch: '.$action);
 check(($byCode['aigc_image']['shelf_status']??'')==='off','owner catalog masked tenant switch: '.$action);
}
foreach([
 ['App.aigc_short_drama.Generation','detail','GET'],['App.aigc_short_drama.Generation','lists','GET'],
 ['App.aigc_music.Task','query','GET'],['App.aigc_copywriting.Task','events','GET'],
 ['App.aigc_video.Task','refresh','POST'],['App.aigc_llm.Agent','detail','GET'],['App.aigc_llm.Agent','events','GET'],
] as [$route,$action,$method]) {
 $code=explode('.',strtolower($route))[1];Db::name('tenant_app')->where(['tenant_id'=>9101,'app_code'=>$code])->update(['shelf_status'=>'off']);
 check($middleware->handle(requestFor($route,$action,$method),fn()=>'historical')==='historical','historical route blocked: '.$route.'/'.$action);
}
// A real paid-order record and its renewed expiry survive later seed SQL and DDL.
Db::name('app')->where('code','aigc_geo')->update(['status'=>'installed']);
Db::name('tenant_app_config')->where(['tenant_id'=>9101,'app_code'=>'aigc_geo'])->update(['status'=>1]);
$plan=Db::name('app_plan')->insertGetId(['app_code'=>'aigc_geo','name'=>'fixture renewal','duration_months'=>1,'open_points'=>0,'renew_points'=>0,'status'=>1]);
$renewal=\app\common\service\app\AppPlanService::openOrRenew(9101,0,'aigc_geo',$plan);
check($renewal['after_expire_time']>$renewal['before_expire_time'],'real entitlement renewal did not extend expiry');
check(Db::name('tenant_app_order')->where(['tenant_id'=>9101,'app_code'=>'aigc_geo','order_sn'=>$renewal['order_sn'],'pay_status'=>1])->count()===1,'real renewal order missing');
\app\common\service\app\AppAccessStateService::preserveExisting(function() use($pdo,$lock) {
 Db::execute('CREATE TABLE app_access_ddl_barrier (id int PRIMARY KEY) ENGINE=InnoDB');
 try {
  Db::execute('ALTER TABLE app_access_ddl_barrier ADD COLUMN marker int NOT NULL DEFAULT 0');
  $statement=$pdo->prepare('SELECT GET_LOCK(?,0)');$statement->execute([$lock]);check((int)$statement->fetchColumn()===0,'DDL released updater session lock');
  Db::name('tenant_app')->where(['tenant_id'=>9101,'app_code'=>'aigc_geo'])->update(['expire_time'=>0,'shelf_status'=>'off']);
 } finally {Db::execute('DROP TABLE app_access_ddl_barrier');}
});
check((int)Db::name('tenant_app')->where(['tenant_id'=>9101,'app_code'=>'aigc_geo'])->value('expire_time')===$renewal['after_expire_time'],'DDL/seed restore swallowed renewed paid entitlement');
$session=Db::name('aigc_llm_session')->insertGetId(['tenant_id'=>9101,'user_id'=>71,'workspace_key'=>'home']);
$run=Db::name('aigc_llm_agent_run')->insertGetId(['tenant_id'=>9101,'user_id'=>71,'session_id'=>$session,'message_id'=>0,'request_id'=>'historical-fixture','payload_hash'=>str_repeat('a',64),'content'=>'fixture','preferences'=>'{}','attachments'=>'[]','reply'=>'','create_time'=>1,'update_time'=>1]);
$agent=(new ReflectionClass(\app\api\controller\app\aigc_llm\AgentController::class))->newInstanceWithoutConstructor();
$agentUser=new ReflectionProperty($agent,'userId');$agentUser->setAccessible(true);$agentUser->setValue($agent,71);
$agentRequest=new ReflectionProperty($agent,'request');$agentRequest->setAccessible(true);
$readRequest=(new think\Request())->withGet(['run_id'=>$run]);$readRequest->setMethod('GET');$readRequest->setAction('events');$readRequest->tenantId=9101;$agentRequest->setValue($agent,$readRequest);
check($agent->events()->getData()['code']===1,'owned Agent event history rejected after close');
$agentUser->setValue($agent,72);check($agent->events()->getData()['code']===0,'Agent historical event read crossed user ownership');
// Real OEM worker steps: a transient update lock must requeue, never fail a paid order.
$order=Db::name('tenant_brand_order')->insertGetId(['tenant_id'=>9101,'user_id'=>71,'order_sn'=>'switch-oem-paid','flow_version'=>2,'order_kind'=>'renew','target_tenant_id'=>9102,'child_tenant_id'=>9102,'workflow_state'=>'opening','tenant_review'=>'approved','platform_review'=>'approved','pay_status'=>1,'reserve_status'=>1]);
$lease=str_repeat('b',48);$completed=['create_tenant'=>['completed_at'=>1],'initialize_admin'=>['completed_at'=>1]];
$jobId=Db::name('tenant_brand_job')->insertGetId(['order_id'=>$order,'status'=>'running','lease_token'=>$lease,'lease_until'=>time()+180,'steps'=>json_encode($completed)]);
$job=Db::name('tenant_brand_job')->where('id',$jobId)->find();$beforeEntitlement=Db::name('tenant_app')->where(['tenant_id'=>9101,'app_code'=>'aigc_geo'])->find();
$statement=$pdo->prepare('SELECT GET_LOCK(?,0)');$statement->execute([$lock]);
try {
 check(\app\common\service\brand\BrandProvisionService::advance($job)===false,'busy OEM worker did not yield');
 check(Db::name('tenant_brand_job')->where('id',$jobId)->value('status')==='queued','update contention permanently failed OEM job');
 check(Db::name('tenant_brand_order')->where('id',$order)->value('workflow_state')==='opening','update contention marked paid OEM order open_failed');
 check(Db::name('tenant_app')->where(['tenant_id'=>9101,'app_code'=>'aigc_geo'])->find()===$beforeEntitlement,'busy OEM worker lost existing entitlement');
} finally {$statement=$pdo->prepare('SELECT RELEASE_LOCK(?)');$statement->execute([$lock]);}
$retry=\app\common\service\brand\BrandProvisionService::claim('switch-fixture');
check($retry!==null,'busy OEM job was not claimable after update');
if($retry) {
 $advanced=\app\common\service\brand\BrandProvisionService::advance($retry);
 check($advanced===true,'OEM retry did not complete real initialize_services step: '.Db::name('tenant_brand_job')->where('id',$jobId)->value('last_error'));
 $steps=json_decode(Db::name('tenant_brand_job')->where('id',$jobId)->value('steps'),true);
 check(isset($steps['initialize_services']),'OEM retry did not persist completed step');
}
check((int)Db::name('tenant_brand_order')->where('id',$order)->value('pay_status')===1,'OEM retry lost paid order');
// A genuinely invalid target retains the original failure path.
$badOrder=Db::name('tenant_brand_order')->insertGetId(['tenant_id'=>9101,'order_sn'=>'switch-oem-invalid','flow_version'=>2,'order_kind'=>'renew','target_tenant_id'=>999999,'workflow_state'=>'opening','tenant_review'=>'approved','platform_review'=>'approved','pay_status'=>1,'reserve_status'=>1]);
$badJob=Db::name('tenant_brand_job')->insertGetId(['order_id'=>$badOrder,'status'=>'running','lease_token'=>str_repeat('c',48),'lease_until'=>time()+180,'steps'=>'{}']);
check(\app\common\service\brand\BrandProvisionService::advance(Db::name('tenant_brand_job')->where('id',$badJob)->find())===false,'genuine OEM error continued');
check(Db::name('tenant_brand_job')->where('id',$badJob)->value('status')==='failed','genuine OEM error did not fail job');
check(Db::name('tenant_brand_order')->where('id',$badOrder)->value('workflow_state')==='open_failed','genuine OEM error did not fail order');
if($failures)throw new RuntimeException(json_encode(['checks'=>$checks,'failures'=>$failures],JSON_UNESCAPED_UNICODE));
echo json_encode(['checks'=>$checks,'result'=>'passed'],JSON_UNESCAPED_UNICODE).PHP_EOL;
