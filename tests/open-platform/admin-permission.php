<?php
/** Local rollback roles exercise actual controller guards and native permission caches. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenAdminPermission as Permission;
use app\common\service\open_platform\OpenException;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function denied(callable $fn,$why){try{$fn();}catch(OpenException $e){check($e->httpStatus===403,$why.' wrong code');return;}throw new RuntimeException($why);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback fixtures only');
$wallet=Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money');$tenantWallet=Db::name('tenant')->where('id',1)->value('point_balance');
$caches=[];Db::startTrans();try{
 foreach(['platform','tenant'] as $scene){
  $prefix=$scene==='platform'?'':'tenant_';$adminId=random_int(800000000,899999999);$roleId=random_int(800000000,899999999);
  check(!Db::name($prefix.'admin_role')->where('admin_id',$adminId)->find()&&!Db::name($prefix.'system_role_menu')->where('role_id',$roleId)->find(),'Fixture collision');
  $menu=Db::name($prefix.'system_menu')->where(['perms'=>'app.open_platform.management/requests','is_disable'=>0])->find();check($menu,'Installed read permission missing');
  Db::name($prefix.'admin_role')->insert(['admin_id'=>$adminId,'role_id'=>$roleId]);Db::name($prefix.'system_role_menu')->insert(['role_id'=>$roleId,'menu_id'=>$menu['id']]);
  $cache=$scene==='platform'?new app\common\cache\AdminAuthCache($adminId):new app\common\cache\TenantAdminAuthCache($adminId);$caches[]=[$cache,($scene==='platform'?'admin_auth_':'tenant_auth_').'url_'.$adminId];
  $actor=['admin_id'=>$adminId,'root'=>0,'tenant_id'=>1];Permission::assert($scene.'_admin',$actor,'requests');
  foreach(['setup','savePages','saveDocument','publishDocument','saveContract','publishContract','createVerification','resumeTracking','requestDetail','unregisteredAction'] as $action)denied(fn()=>Permission::assert($scene.'_admin',$actor,$action),$scene.' read role gained '.$action);
  $class='app\\'.$scene.'api\\controller\\app\\open_platform\\ManagementController';$controller=(new ReflectionClass($class))->newInstanceWithoutConstructor();
  foreach(['request'=>(new app\Request())->setAction('requests'),'adminInfo'=>$actor,'adminId'=>$adminId] as $name=>$value)(new ReflectionProperty($class,$name))->setValue($controller,$value);
  $run=new ReflectionMethod($class,'run');$called=0;$fn=function()use(&$called){++$called;return ['fixture'=>true];};
  check($run->invoke($controller,$fn)->getData()['code']===1&&$called===1,'Allowed controller action blocked');
  foreach(['setup','requestDetail','resumeTracking'] as $action){(new ReflectionProperty($class,'request'))->setValue($controller,(new app\Request())->setAction($action));check($run->invoke($controller,$fn)->getData()['code']===0&&$called===1,'Controller executed unauthorized '.$action);}
  Permission::assert($scene.'_admin',['admin_id'=>$adminId,'root'=>1],'requests');denied(fn()=>Permission::assert($scene.'_admin',['admin_id'=>$adminId,'root'=>1],'unregisteredAction'),'Root gained unregistered action');
  denied(fn()=>Permission::assert($scene.'_admin',['admin_id'=>0,'root'=>1],'requests'),'Missing actor authorized');
 }
 denied(fn()=>Permission::assert('user',['admin_id'=>1,'root'=>1],'requests'),'User scene authorized');
 denied(fn()=>Permission::assert('tenant_admin',['admin_id'=>1,'root'=>1],'publishContract'),'Tenant gained platform contract publish');
 $grant=new ReflectionMethod(Permission::class,'granted');foreach(['app.open_platform.Management/savePages','app.openPlatform.management/savePages'] as $uri)check($grant->invoke(null,'savePages',[$uri]),'ThinkPHP URI normalization failed');
 foreach([[],['*'],['app.other.management/savePages'],['app.open_platform.management/pages']] as $uris)check(!$grant->invoke(null,'savePages',$uris),'Unrelated permission granted write');
 echo "PASS platform and tenant native read roles allowed; controller write/detail callbacks blocked; unknown actions, wrong scenes and missing actors denied; URI normalization preserved\n";
}finally{Db::rollback();foreach($caches as [$cache,$key])$cache->delete($key);}
check(Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money')===$wallet&&Db::name('tenant')->where('id',1)->value('point_balance')===$tenantWallet,'Changed wallet');
