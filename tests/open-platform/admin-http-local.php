<?php
/** Actual local HTTP auth pipeline with ephemeral non-root cached sessions and native role links. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local HTTP fixtures only');
$report=['provider_calls'=>0,'point_mutations'=>0,'session_source'=>'ephemeral non-root cache fixture, not browser login','scenarios'=>[]];
$wallet=Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money');$tenantWallet=Db::name('tenant')->where('id',1)->value('point_balance');
$originalCache=config('cache');$runtime=app()->getRuntimePath();
foreach(['platform','tenant'] as $scene){
 // MultiApp selects a separate file cache directory for each HTTP application.
 if(($originalCache['default']??'')==='file'){$localCache=$originalCache;$localCache['stores']['file']['path']=$runtime.$scene.'api/cache';app()->config->set($localCache,'cache');app()->cache->forgetDriver();}
 $prefix=$scene==='platform'?'':'tenant_';$adminId=random_int(800000000,899999999);$roleId=random_int(800000000,899999999);$token=bin2hex(random_bytes(32));
 check(!Db::name($prefix.'admin_role')->where('admin_id',$adminId)->find()&&!Db::name($prefix.'system_role_menu')->where('role_id',$roleId)->find(),'Fixture collision');
 $menu=Db::name($prefix.'system_menu')->where(['perms'=>'app.open_platform.management/requests','is_disable'=>0])->find();check($menu,'Installed read permission missing');
 $cache=$scene==='platform'?new app\common\cache\AdminTokenCache():new app\common\cache\TenantAdminTokenCache();$auth=$scene==='platform'?new app\common\cache\AdminAuthCache($adminId):new app\common\cache\TenantAdminAuthCache($adminId);
 $key=($scene==='platform'?'token_admin_':'token_tenant_').$token;$authKey=($scene==='platform'?'admin_auth_':'tenant_auth_').'url_'.$adminId;
 try{
  Db::name($prefix.'admin_role')->insert(['admin_id'=>$adminId,'role_id'=>$roleId]);Db::name($prefix.'system_role_menu')->insert(['role_id'=>$roleId,'menu_id'=>$menu['id']]);
  $cache->set($key,['admin_id'=>$adminId,'tenant_id'=>1,'root'=>0,'name'=>'Open permission fixture','account'=>'open_fixture','role_id'=>[$roleId],'expire_time'=>time()+7200,'login_ip'=>'127.0.0.1'],120);
  $call=static function($action,$method='GET')use($scene,$token){$ch=curl_init('http://127.0.0.1:19874/'.$scene.'api/app.open_platform.management/'.$action.'?tenant_id=1');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['token: '.$token,'Content-Type: application/json']]);if($method==='POST')curl_setopt($ch,CURLOPT_POSTFIELDS,'{}');$body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);check($status===200&&is_string($body),'Local HTTP route unavailable: '.$scene.'/'.$action.' HTTP '.$status.' '.(is_string($body)&&preg_match('/<h1[^>]*>(.*?)<\/h1>/s',$body,$heading)?mb_substr(strip_tags($heading[1]),0,160):''));return json_decode($body,true,64,JSON_THROW_ON_ERROR);};
  $read=$call('requests');check((int)($read['code']??-1)===1,$scene.' read role failed HTTP pipeline: '.($read['msg']??'unknown'));
  $report['scenarios'][]=['scene'=>$scene,'action'=>'requests','allowed'=>true];
  $writes=['savePages','saveDocument','publishDocument','resumeTracking'];if($scene==='platform')$writes=array_merge($writes,['saveContract','publishContract','createVerification']);
  foreach(array_merge(['requestDetail','recoveryPreview'],$writes) as $action){$reply=$call($action,in_array($action,$writes,true)?'POST':'GET');check((int)($reply['code']??-1)===0&&str_contains($reply['msg']??'','权限'),$scene.' unauthorized '.$action.' was not rejected by permission guard');$report['scenarios'][]=['scene'=>$scene,'action'=>$action,'allowed'=>false,'message'=>$reply['msg']];}
 }finally{
  $cache->delete($key);$auth->delete($authKey);Db::name($prefix.'system_role_menu')->where(['role_id'=>$roleId,'menu_id'=>$menu['id']])->delete();Db::name($prefix.'admin_role')->where(['admin_id'=>$adminId,'role_id'=>$roleId])->delete();
 }
 check(!Db::name($prefix.'admin_role')->where('admin_id',$adminId)->find()&&!Db::name($prefix.'system_role_menu')->where('role_id',$roleId)->find()&&!$cache->get($key),'Fixture not cleaned');
}
app()->config->set($originalCache,'cache');app()->cache->forgetDriver();
check(Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money')===$wallet&&Db::name('tenant')->where('id',1)->value('point_balance')===$tenantWallet,'Changed wallets');
$report['passed']=true;file_put_contents(dirname(__DIR__,2).'/runtime/open-platform-acceptance/admin-http.json',S::encode($report));
echo "PASS actual platform and tenant HTTP read roles; protected writes/details rejected, ephemeral sessions and role links removed, no provider/point mutation\n";
