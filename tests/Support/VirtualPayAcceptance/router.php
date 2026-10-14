<?php
if(getenv('OEM_DISPOSABLE')!=='1'||!str_starts_with((string)getenv('PHP_DATABASE_DATABASE'),'oem_e2e_virtual_')||getenv('PHP_DATABASE_HOSTNAME')!=='oem-mysql'){http_response_code(503);exit('Disposable virtual acceptance only');}
$root=dirname(__DIR__,3);$virtualRequestPath=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';$public=realpath($root.'/public');$file=realpath($public.$virtualRequestPath);
if($file&&str_starts_with($file,$public.DIRECTORY_SEPARATOR)&&is_file($file)&&!str_ends_with($file,'.php'))return false;
require $root.'/runtime-loader.php';$app=new \AigcRuntimeApp($root);$app->setBaseEnvName('oem-disposable')->setEnvName('oem-disposable')->initialize();
if((\think\facade\Db::query('SELECT DATABASE() AS db')[0]['db']??'')!==getenv('PHP_DATABASE_DATABASE'))throw new RuntimeException('Fixture DB mismatch');
require dirname(__DIR__).'/VirtualPayFixture.php';require __DIR__.'/Gateway.php';installVirtualGateway($app);
if($virtualRequestPath==='/__virtual/meta'){header('Content-Type: application/json');readfile($root.'/runtime/virtual-fixture.json');exit;}
if($virtualRequestPath==='/__virtual/refund' && $_SERVER['REQUEST_METHOD']==='POST'){virtualLedger(function(&$d){foreach($d['refunds']??[] as $sn=>$row)$d['refunds'][$sn]['complete']=true;});echo 'External refund confirmed';exit;}
if($virtualRequestPath==='/__virtual/pay'){
 $sn=(string)($_GET['sn']??$_POST['sn']??'');
 if($_SERVER['REQUEST_METHOD']==='POST'){virtualLedger(function(&$d)use($sn){if(isset($d['ordinary'][$sn]))$d['ordinary'][$sn]['state']='SUCCESS';else $d['states'][$sn]=2;});echo 'Disposable gateway receipt only';exit;}
 header('Content-Type: text/html; charset=utf-8');echo '<h1>隔离测试渠道，不发生真实扣款</h1><form method="post"><input name="sn" value="'.htmlspecialchars($sn,ENT_QUOTES).'"><button>模拟渠道付款</button></form>';exit;
}
if(preg_match('#^/(admin|platform|mobile)(?:/.*)?$#D',$virtualRequestPath,$m)){header('Content-Type: text/html; charset=utf-8');readfile($public.'/'.$m[1].'/index.html');exit;}
chdir($public);if(!defined('ROOT_PATH'))define('ROOT_PATH',$public);$http=$app->http;$response=$http->run();$response->send();$http->end($response);
