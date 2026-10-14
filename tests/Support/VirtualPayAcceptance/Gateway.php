<?php
/** Only this explicitly started disposable harness loads external transport doubles. */
use think\facade\Db;
use app\common\service\virtualpay\VirtualPayGateway;
use app\common\service\promotion\PackagePaymentGateway;
function virtualLedger(callable $callback):mixed{
 $path=root_path().'runtime/virtual-gateway.json';$file=fopen($path,'c+');flock($file,LOCK_EX);try{$data=json_decode(stream_get_contents($file),true)?:[];$result=$callback($data);rewind($file);ftruncate($file,0);fwrite($file,json_encode($data,JSON_THROW_ON_ERROR));return $result;}finally{flock($file,LOCK_UN);fclose($file);}
}
function installVirtualGateway($app):void{
 $app->instance(VirtualPayGateway::class,new VirtualPayGateway(static function($method,$path,$query,$body){
  $p=json_decode($body,true)?:[];
  if($path==='/sns/jscode2session'){$user=(int)substr($query['js_code'],strlen('fixture-user-'));return ['openid'=>Db::name('user_auth')->where(['user_id'=>$user,'terminal'=>1])->value('openid'),'session_key'=>'fixture-session'];}
  if($path==='/wxa/sec/order/is_trade_managed')return ['errcode'=>0,'is_trade_managed'=>true];
  if($path==='/wxa/sec/order/get_order')return ['errcode'=>0,'order'=>['transaction_id'=>$p['transaction_id'],'order_state'=>virtualLedger(fn(&$d)=>($d['shipping'][$p['transaction_id']]??false)?2:1)]];
  if($path==='/wxa/sec/order/upload_shipping_info'){virtualLedger(function(&$d)use($p){$d['shipping'][$p['order_key']['transaction_id']]=true;});return ['errcode'=>0];}
  $sn=$p['order_id']??'';$i=Db::name('virtual_pay_intent')->where('out_trade_no',$sn)->find();
  if($path==='/xpay/notify_provide_goods'){virtualLedger(function(&$d)use($sn){$d['states'][$sn]=4;});return ['errcode'=>0];}
  if($path==='/xpay/refund_order'){virtualLedger(function(&$d)use($p,$i){$d['refunds'][$p['refund_order_id']]=['intent'=>$i['id'],'amount'=>$p['refund_fee'],'complete'=>false];});return ['errcode'=>0];}
  if($path==='/xpay/query_order'){
   if($i){$state=virtualLedger(fn(&$d)=>$d['states'][$sn]??1);$r=virtualQuery($i,$state,$i['platform']==='ios');if($state===8)$r['order']['left_fee']=0;return $r;}
   $refund=virtualLedger(fn(&$d)=>$d['refunds'][$sn]??null);if($refund){$i=Db::name('virtual_pay_intent')->find($refund['intent']);$r=virtualQuery($i,$refund['complete']?8:5);$r['order']['order_id']=$sn;$r['order']['order_type']=1;$r['order']['refund_fee']=$refund['amount'];return $r;}
   return ['errcode'=>1];
  }
  throw new RuntimeException('Unmapped external endpoint '.$path);
 }));
 $app->instance(PackagePaymentGateway::class,new PackagePaymentGateway(static function($action,$channel,$terminal,$user,$args){
  $context=['app_id'=>'fixture-package-app','merchant'=>'fixture-package-merchant','fingerprint'=>'fixture-stable'];
  if($action==='context')return $context;
  if($action==='prepay'){$o=$args['order'];virtualLedger(function(&$d)use($o){$d['ordinary'][$o['pay_sn']]=['amount'=>(int)round((float)$o['order_amount']*100),'state'=>'NOTPAY'];});$url=request()->domain().'/__virtual/pay?sn='.rawurlencode($o['pay_sn']);return ['pay_way'=>$channel,'order_amount'=>$o['order_amount'],'config'=>$channel===2?'weixin://wxpay/bizpayurl?pr='.$o['pay_sn']:'<form action="'.htmlspecialchars($url).'" method="get"><input name="sn" value="'.htmlspecialchars($o['pay_sn']).'"></form><script>document.forms[0].submit();</script>'];}
  $sn=$args['pay_sn'];if($action==='close'){virtualLedger(function(&$d)use($sn){if(($d['ordinary'][$sn]['state']??'')==='NOTPAY')$d['ordinary'][$sn]['state']='CLOSED';});return [];}
  $o=virtualLedger(fn(&$d)=>$d['ordinary'][$sn]??null);return ['order_sn'=>$sn,'state'=>$o['state']??'UNKNOWN','transaction'=>'fixture-'.$sn,'amount_cents'=>$o['amount']??0,'currency'=>'CNY']+$context;
 }));
}
