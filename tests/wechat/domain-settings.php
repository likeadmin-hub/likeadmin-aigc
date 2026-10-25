<?php
require_once (getenv('WECHAT_DOMAIN_SOURCE')?:dirname(__DIR__,2).'/app/common/service/wechat').'/MiniProgramServerDomainService.php';
require_once (getenv('WECHAT_DOMAIN_SOURCE')?:dirname(__DIR__,2).'/app/common/service/wechat').'/WechatDomainSettings.php';
use app\common\service\wechat\MiniProgramServerDomainService as Sync;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
$state=['requestdomain'=>['https://existing.example.com']];$calls=[];
$result=Sync::ensure('https://tenant.example.com/',function($payload)use(&$state,&$calls){$calls[]=$payload;if($payload['action']==='add')foreach($payload as $k=>$v)if($k!=='action')$state[$k]=array_values(array_unique(array_merge($state[$k]??[],$v)));return $state;},['download_file_domain'=>"https://assets.example.com\nhttps://archive.example.com",'socket_domain'=>'wss://socket.example.com']);
check(in_array('https://assets.example.com',$result['downloaddomain'],true)&&in_array('https://archive.example.com',$result['downloaddomain'],true),'custom cloud download domains are really assigned to WeChat');
check(in_array('https://existing.example.com',$result['requestdomain'],true),'incremental update preserves existing WeChat domains');
check(in_array('https://tenant.example.com',$result['downloaddomain'],true)&&in_array('wss://socket.example.com',$result['wsrequestdomain'],true),'mandatory tenant domain remains alongside custom domains');
$before=count($calls);Sync::ensure('https://tenant.example.com/',function($p)use(&$calls,$result){$calls[]=$p;return $result;},['download_file_domain'=>"https://assets.example.com\nhttps://archive.example.com",'socket_domain'=>'wss://socket.example.com']);check(count($calls)===$before+1,'repeat save only reads when all domains already exist');
$failed=false;try{Sync::ensure('https://tenant.example.com/',fn($p)=>[],['download_file_domain'=>'https://assets.example.com']);}catch(Throwable $e){$failed=str_contains($e->getMessage(),'回读校验失败');}check($failed,'unverified remote success cannot be reported as applied');
require_once (getenv('WECHAT_DOMAIN_SOURCE')?:dirname(__DIR__,2).'/app/common/service/wechat').'/WechatDomainSettings.php';
use app\common\service\wechat\WechatDomainSettings as Domains;
check(Domains::normalize(['download_file_domain'=>"https://Assets.Example.com/;https://assets.example.com\nhttps://archive.example.com"],'miniprogram')['download_file_domain']==="https://assets.example.com\nhttps://archive.example.com",'domain text is normalized and deduplicated');
check(Domains::normalize(['business_domain'=>'https://H5.Example.com/','web_auth_domain'=>'login.example.com'],'official')===['business_domain'=>'h5.example.com','web_auth_domain'=>'login.example.com'],'official domains use bare hosts');
foreach(['http://assets.example.com','https://127.0.0.1','https://assets.example.com:8443','https://assets.example.com/a','https://assets.example.com?q=a','https://user:pass@assets.example.com','https://*.example.com', ['bad'=>[]]] as $bad){$failed=false;try{Domains::normalize(['download_file_domain'=>$bad],'miniprogram');}catch(InvalidArgumentException $e){$failed=true;}check($failed,'invalid or structured domain value is rejected');}
check(Domains::normalize(['download_file_domain'=>'','app_secret'=>'untrusted'],'miniprogram')===['download_file_domain'=>''],'domain-only update excludes credentials and supports clearing extras');
$web=['webviewdomain'=>['existing.example.com']];$webCalls=[];
$r=Sync::ensureWebview(['h5.example.com'],function($p)use(&$web,&$webCalls){$webCalls[]=$p;if($p['action']==='add')$web['webviewdomain']=array_merge($web['webviewdomain'],$p['webviewdomain']);return $web;});check($r['webviewdomain']===['existing.example.com','h5.example.com']&&array_column($webCalls,'action')===['get','add','get'],'business domains use the separate webview API and preserve existing entries');
$state=['testing_wxa_server_domain'=>'tenant.example.com','published_wxa_server_domain'=>'tenant.example.com'];$calls=[];
Sync::ensurePlatform(['tenant.example.com','assets.example.com'],function($p)use(&$state,&$calls){$calls[]=$p;if($p['action']==='add')foreach($state as &$v)$v.=';'.$p['wxa_server_domain'];return $state;});check($calls[1]['wxa_server_domain']==='tenant.example.com;assets.example.com','cloud domain is registered in third-party platform whitelist before tenant assignment');
$failed=false;try{Sync::ensureWebview(['h5.example.com'],fn($p)=>['webviewdomain'=>[]]);}catch(Throwable $e){$failed=str_contains($e->getMessage(),'回读校验失败');}check($failed,'business domain readback failure is not reported as success');
echo "All WeChat domain settings checks passed\n";
