<?php
use think\facade\Db;
use app\common\service\app\AppAccessService as Access;
use app\common\service\app\AppRegistryService as Registry;
/** Owning service fields, deliberately independent of the gate's source inventory. */
$configApps = [
 'aigc_image','aigc_video','aigc_digital_human','aigc_llm','aigc_hairstyle','aigc_fitting',
 'aigc_product_image','aigc_style_transfer','aigc_photo_restore','aigc_image_translate',
 'aigc_one_click_cleanup','aigc_product_multi_angle','aigc_product_promo_video','aigc_action_transfer',
 'aigc_outpaint','aigc_product_suite','aigc_local_redraw','aigc_person_replacement','aigc_short_drama',
 'aigc_background_removal','aigc_fashion_lookbook','aigc_geo','aigc_model_wear','aigc_music',
 'aigc_music_cover','aigc_watermark_removal','aigc_pic_lipsync','image_human','smart_clip',
];
foreach ($configApps as $code) {
 $table=$code.'_config';
 if (!preg_match('~^CREATE TABLE(?: IF NOT EXISTS)? `la_'.preg_quote($table,'~').'`\s*\(.*?^\)\s*ENGINE\s*=\s*InnoDB\b[^;]*;~msi',$schema,$m)) throw new RuntimeException('Missing config fixture DDL '.$table);
 $pdo->exec($m[0]);
 if (!in_array($code,$codes,true)) {
  Db::name('app')->insert(['code'=>$code,'name'=>$code,'status'=>'installed','current_version'=>'1.0.0']);
  foreach([9101,9102] as $tenant) Db::name('tenant_app')->insert(['tenant_id'=>$tenant,'app_code'=>$code,'buy_status'=>'paid','shelf_status'=>'on','enable_status'=>'enabled','expire_time'=>time()+3600]);
  foreach(['pc','uniapp','tenant'] as $terminal) Db::name('app_frontend_entry')->insert(['app_code'=>$code,'terminal'=>$terminal,'path'=>'/app/'.$code,'status'=>1,'meta'=>'{}']);
 }
}
// A worker can outlive app installation: absent-table discovery must not remain stale.
$pdo->exec('DROP TABLE la_aigc_action_transfer_config');
check(Access::tenantCanUse(9101,'aigc_image',false),'optional table absence blocked another app');
preg_match('~^CREATE TABLE(?: IF NOT EXISTS)? `la_aigc_action_transfer_config`\s*\(.*?^\)\s*ENGINE\s*=\s*InnoDB\b[^;]*;~msi',$schema,$optionalDdl);
$pdo->exec($optionalDdl[0]);
Db::name('aigc_action_transfer_config')->insert(['tenant_id'=>9101,'status'=>0]);
check(!Access::tenantCanUse(9101,'aigc_action_transfer',false),'worker retained absent optional config schema after install');
Db::name('aigc_action_transfer_config')->where('tenant_id',9101)->delete();
$publishedTools=array_keys(\app\common\service\open_platform\OpenToolRegistry::all());
foreach([0,9101] as $tenant)Db::name('open_platform_setting')->insert(['tenant_id'=>$tenant,'enabled'=>1,'config'=>json_encode(['apps'=>$publishedTools])]);
$matrixMiddleware=new \app\api\http\middleware\AppAccessMiddleware();
$matrixRequest=static fn(string $code,string $controller='Config',string $action='detail',string $method='GET')=>new class($code,$controller,$action,$method) {
 public int $tenantId=9101;public int $userId=0;
 public function __construct(private string $code,private string $c,private string $a,private string $m){}
 public function controller(){return 'App.'.$this->code.'.'.$this->c;} public function action(){return $this->a;} public function method(){return $this->m;}
};
$allConfigCodes=array_values(array_unique(array_merge($codes,$configApps)));
foreach($allConfigCodes as $code) {
 check(Access::tenantCanUse(9101,$code,false),'absent config should default open '.$code);
 foreach([0,9101] as $scope) {
  Db::name('tenant_app_config')->insert(['tenant_id'=>$scope,'app_code'=>$code,'status'=>'0']);
  check(!Access::tenantCanUse(9101,$code,false),'display-hidden usable '.$scope.'/'.$code);
  check(in_array($code,Access::purchasedTenantAppCodes(9101),true),'display-hidden removed purchased '.$scope.'/'.$code);
  check(Access::tenantCanManage(9101,$code),'display-hidden prevents reopen '.$scope.'/'.$code);
  foreach(['pc','uniapp','tenant'] as $terminal) check(!in_array($code,array_column(Registry::frontendEntries(9101,$terminal),'app_code'),true),'display-hidden entry '.$scope.'/'.$code.'/'.$terminal);
  $denied=$matrixMiddleware->handle($matrixRequest($code),fn()=>'bypassed');
  check($denied instanceof \think\Response&&$denied->getData()['msg']==='当前应用已关闭','display hidden direct route '.$scope.'/'.$code);
  check($matrixMiddleware->handle($matrixRequest($code,'Task','detail'),fn()=>'history')==='history','display hidden blocks owned history '.$scope.'/'.$code);
  if($scope===9101)check(Access::tenantCanUse(9102,$code,false),'tenant display closed another tenant '.$code);
  else {
   Db::name('tenant_app_config')->insert(['tenant_id'=>9101,'app_code'=>$code,'status'=>1]);
   check(!Access::tenantCanUse(9101,$code,false),'tenant display reopened platform hidden '.$code);
   Db::name('tenant_app_config')->where(['tenant_id'=>9101,'app_code'=>$code])->delete();
  }
  Db::name('tenant_app_config')->where(['tenant_id'=>$scope,'app_code'=>$code])->delete();
 }
 if(!in_array($code,$configApps,true))continue;
 foreach([0,9101] as $scope) {
  $values=$code==='aigc_micro_video'?['config_json'=>json_encode(['status'=>'0'])]:['status'=>'0'];
  Db::name($code.'_config')->insert(['tenant_id'=>$scope]+$values);
  check(!Access::tenantCanUse(9101,$code,false),'service disabled usable '.$scope.'/'.$code);
  foreach(['pc','uniapp','tenant'] as $terminal)check(!in_array($code,array_column(Registry::frontendEntries(9101,$terminal),'app_code'),true),'service disabled entry '.$scope.'/'.$code.'/'.$terminal);
  $denied=$matrixMiddleware->handle($matrixRequest($code,'Generate','submit','POST'),fn()=>'bypassed');
  check($denied instanceof \think\Response&&$denied->getData()['msg']==='当前应用已关闭','disabled generation direct route '.$scope.'/'.$code);
  if(in_array($code,$publishedTools,true)) {
   try {\app\common\service\open_platform\OpenToolAdapter::authorize(9101,71,$code);check(false,'published tool bypassed config '.$scope.'/'.$code);}
   catch(\app\common\service\open_platform\OpenException $e){check($e->getMessage()==='当前应用已关闭','published tool closure returned wrong reason '.$scope.'/'.$code);}
  }
  if(in_array($code,['aigc_image','aigc_video','aigc_music','aigc_fitting'],true)) {
   try {\app\common\service\app\aigc_llm\agent\AgentCapabilityCatalog::describe(['tenant_id'=>9101,'user_id'=>71],$code);check(false,'Agent tool bypassed config '.$scope.'/'.$code);}
   catch(\Throwable $e){check($e->getMessage()==='当前账号不可使用此工具','Agent closure not checked first '.$scope.'/'.$code);}
  }
  if($scope===9101)check(Access::tenantCanUse(9102,$code,false),'service config leaked tenants '.$code);
  else {
   Db::name($code.'_config')->insert(['tenant_id'=>9101]+($code==='aigc_micro_video'?['config_json'=>'{"status":1}']:['status'=>1]));
   check(!Access::tenantCanUse(9101,$code,false),'tenant service reopened platform disabled '.$code);
   Db::name($code.'_config')->where('tenant_id',9101)->delete();
  }
  Db::name($code.'_config')->where('tenant_id',$scope)->delete();
  check(Access::tenantCanUse(9101,$code,false),'service reopen stale '.$scope.'/'.$code);
 }
}
check(!Access::tenantCanUse(9101,'aigc_micro_video',false),'Retired tool cannot be admitted');
Db::name('aigc_image_config')->insert(['tenant_id'=>9101,'status'=>1,'config_json'=>'{"status":0}']);
check(Access::tenantCanUse(9101,'aigc_image',false),'unowned nested status treated as app switch');
Db::name('aigc_image_config')->where('tenant_id',9101)->delete();
// Real MySQL log verifies a full catalog reads owning service switches in one batch.
$pdo->exec("SET GLOBAL log_output='TABLE'");$pdo->exec('SET GLOBAL general_log=ON');
try {
 $pdo->exec('TRUNCATE TABLE mysql.general_log');
 Access::enabledTenantAppCodes(9101);
 $switchQueries=(int)$pdo->query("SELECT COUNT(*) FROM mysql.general_log WHERE command_type='Query' AND argument LIKE 'SELECT % AS app_code, % AS switch_value FROM %'")->fetchColumn();
 check($switchQueries===1,'service switches queried per app instead of one batch: '.$switchQueries);
} finally {$pdo->exec('SET GLOBAL general_log=OFF');}
Db::name('open_platform_setting')->whereIn('tenant_id',[0,9101])->delete();
// Existing GEO scenarios below create their own market grant.
foreach(array_diff($configApps,$codes) as $code) {
 Db::name('app')->where('code',$code)->delete();Db::name('tenant_app')->where('app_code',$code)->delete();Db::name('app_frontend_entry')->where('app_code',$code)->delete();
}
if($failures)throw new RuntimeException(json_encode(['checks'=>$checks,'failures'=>$failures],JSON_UNESCAPED_UNICODE));
