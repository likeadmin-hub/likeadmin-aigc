<?php
/** Start explicitly with php -S; this router is excluded from all release packages. */
if(getenv('OEM_DISPOSABLE')!=='1' || !str_starts_with((string)getenv('PHP_DATABASE_DATABASE'),'oem_e2e_') || getenv('PHP_DATABASE_HOSTNAME')!=='oem-mysql') { http_response_code(503); exit('Disposable OEM acceptance only'); }
$root=dirname(__DIR__,3); $oemRequestPath=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
$public=realpath($root.'/public'); $file=realpath($public.$oemRequestPath);
if ($file && str_starts_with($file,$public.DIRECTORY_SEPARATOR) && is_file($file) && !str_ends_with($file,'.php')) return false;
require $root.'/runtime-loader.php';
$app=new \AigcRuntimeApp($root); $app->setBaseEnvName('oem-disposable')->setEnvName('oem-disposable')->initialize();
if ((\think\facade\Db::query('SELECT DATABASE() AS db')[0]['db']??'')!==getenv('PHP_DATABASE_DATABASE')) { http_response_code(503); exit('Fixture DB mismatch'); }
require __DIR__.'/Gateway.php'; $app->instance(\app\common\service\brand\BrandChannelGateway::class,new OemAcceptanceGateway());
if(str_starts_with($oemRequestPath,'/__gateway/')) {
    $sn=(string)($_GET['sn']??$_POST['sn']??'');
    if ($oemRequestPath==='/__gateway/pay' && $_SERVER['REQUEST_METHOD']==='POST') {
        $ok=OemAcceptanceGateway::ledger(function(&$data)use($sn) { if(!isset($data['intents'][$sn])) return false; $data['paid'][$sn]=['transaction_id'=>'mock-'.$sn,'paid_time'=>time()]; return true; });
        header('Content-Type: text/html; charset=utf-8'); echo $ok?'<h1>测试渠道付款已确认</h1><p>仅模拟渠道，不发生真实扣款。返回业务页面查询到账。</p>':'未知测试交易'; exit;
    }
    if ($oemRequestPath==='/__gateway/pay') {
        $intent=OemAcceptanceGateway::ledger(fn(&$data)=>$data['intents'][$sn]??null);
        header('Content-Type: text/html; charset=utf-8'); echo '<h1>隔离测试支付渠道</h1><p>不发生真实扣款。业务金额：'.htmlspecialchars(\app\common\service\brand\BrandOrderPolicy::amount((int)($intent['amount_cents']??0))).'</p><form method="post"><input type="hidden" name="sn" value="'.htmlspecialchars($sn,ENT_QUOTES).'"><button>模拟渠道付款</button></form>'; exit;
    }
    if ($oemRequestPath==='/__gateway/refund' && $_SERVER['REQUEST_METHOD']==='POST') {
        OemAcceptanceGateway::ledger(function(&$data)use($sn) { if(isset($data['refunds'][$sn])) $data['refunds'][$sn]['status']='success'; }); header('Content-Type: application/json'); echo json_encode(['gateway_only'=>'refund confirmed']); exit;
    }
    http_response_code(404); exit;
}
if($oemRequestPath==='/__fixture/meta') {
    header('Content-Type: application/json'); echo file_get_contents($root.'/runtime/oem-fixture.json'); exit;
}
if(!defined('ROOT_PATH')) define('ROOT_PATH',$public);
$http=$app->http; $response=$http->run(); $response->send(); $http->end($response);
