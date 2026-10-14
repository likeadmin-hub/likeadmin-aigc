<?php
// The gateway flips this test-only runtime marker during I/O; production has no bypass.
$GLOBALS['virtual_review_generation_changed']=false;
function aigc_runtime_generation_changed(): bool {return $GLOBALS['virtual_review_generation_changed'];}
require __DIR__.'/Support/OemDisposable.php';require __DIR__.'/Support/OemFixtures.php';require __DIR__.'/Support/VirtualPayFixture.php';
use think\facade\Db;
use app\common\service\virtualpay\VirtualPayService as Pay;
use app\common\service\virtualpay\VirtualPayGateway as Gateway;
use app\common\service\virtualpay\VirtualPaySessionService as Session;
use app\common\service\virtualpay\VirtualPayRefundService as Refund;
use app\common\service\virtualpay\VirtualPayNotifyService as Notify;
use app\common\service\virtualpay\VirtualPayDeliveryService as Delivery;
$cases=['later_refund','refund_before_grant','payer_identity','monotonic_refund','generation_request','generation_event','generation_status','generation_notify'];$failed=[];
foreach($cases as $case){Db::startTrans();$GLOBALS['virtual_review_generation_changed']=false;try{
 $f=virtualFixture();$query=[];$hook=null;$openid=$f['openid'];
 $g=new Gateway(static function($method,$path,$params,$body)use(&$query,&$hook,&$openid){
  if($path==='/sns/jscode2session')return ['openid'=>$openid,'session_key'=>'key-'.$openid];
  if($path==='/xpay/query_order'){$result=$query;if($hook){$once=$hook;$hook=null;$once();}return $result;}
  return ['errcode'=>0];
 });$pay=new Pay($g);$refund=new Refund($pay);$sessions=new Session($g);$session=$sessions->exchange($f['tenant'],$f['user'],'code');
 $pre=$pay->prepay($f['tenant'],$f['user'],1,'recharge',$f['order']['order_id'],$session,'android');$i=Db::name('virtual_pay_intent')->where('out_trade_no',$pre['out_trade_no'])->find();$query=virtualQuery($i);
 $notice=['Event'=>'xpay_refund_notify','MchOrderId'=>$i['out_trade_no'],'OpenId'=>$i['openid'],'RefundFee'=>990,'RetCode'=>0];
 if($case==='payer_identity'){
  $openid='second-'.$f['openid'];Db::name('user_auth')->insert(['tenant_id'=>$f['tenant'],'user_id'=>$f['user'],'terminal'=>1,'openid'=>$openid,'create_time'=>time()]);$second=$sessions->exchange($f['tenant'],$f['user'],'second-code');
  oemReject(fn()=>$pay->prepay($f['tenant'],$f['user'],1,'recharge',$f['order']['order_id'],$second,'android'),'a second valid WeChat identity must not re-sign the original attempt');
  oemCheck(Db::name('virtual_pay_intent')->where('id',$i['id'])->value('openid')===$i['openid'],'original payer remains immutable');
 }elseif($case==='refund_before_grant'){
  $user=Db::name('user')->where('id',$f['user'])->find();Db::name('user')->where('id',$f['user'])->delete();oemReject(fn()=>$pay->reconcile($i),'fault before grant');Db::name('user')->insert($user);
  $query['order']['status']=5;$pay->reconcile($i);oemReject(fn()=>(new Delivery($pay))->grant((int)$i['id']),'refunding payment cannot finish stale delivery');
  oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='0.00','refunded before delivery never grants');
 }else{
  $pay->reconcile($i);$i=Db::name('virtual_pay_intent')->where('id',$i['id'])->find();
  if($case==='later_refund'){
   $query['order']['status']=8;$query['order']['left_fee']=0;$pay->reconcile($i);
   oemCheck(Db::name('virtual_pay_intent')->where('id',$i['id'])->value('refund_status')==='processing','known payment later channel refund queues recovery');
   oemCheck((bool)Db::name('virtual_pay_work')->where(['intent_id'=>$i['id'],'kind'=>'refund'])->find(),'lost refund notification has durable work');
   $refund->process((int)$i['id']);oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='0.00','later refund recovers unused rights');
  }elseif($case==='monotonic_refund'){
   $query['order']['status']=8;$query['order']['left_fee']=0;
   $hook=function()use($refund,$i,&$query){$refund->complete($i,$query['order']);};$refund->event($f['tenant'],$f['app'],$notice);
   $current=Db::name('virtual_pay_intent')->where('id',$i['id'])->find();oemCheck($current['refund_status']==='success'&&$current['recovery_status']==='recovered','notification returning after worker completion preserves both terminal latches');
   $refund->process((int)$i['id']);oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='0.00','interleaved completion does not reclaim twice');
  }else{
   $before=Db::name('virtual_pay_intent')->where('id',$i['id'])->find();$work=Db::name('virtual_pay_work')->where('intent_id',$i['id'])->order('id')->select()->toArray();
   $hook=static function(){$GLOBALS['virtual_review_generation_changed']=true;};
   if($case==='generation_request')oemReject(fn()=>$refund->request($f['tenant'],1,(int)$i['id']),'old refund requester must retire');
   elseif($case==='generation_event'){$query['order']['status']=8;$query['order']['left_fee']=0;oemReject(fn()=>$refund->event($f['tenant'],$f['app'],$notice),'old refund notification must retire');}
   elseif($case==='generation_status')oemReject(fn()=>$pay->status($f['tenant'],$f['user'],'recharge',$f['order']['order_id']),'status must not swallow generation retirement');
   else{oemReject(fn()=>(new Notify($pay))->event($f['tenant'],$f['app'],virtualNotice($i)),'old notice must retire');oemCheck(Db::name('virtual_pay_event')->where('intent_id',$i['id'])->where('status','failed')->count()===0,'old notice cannot write a failure after generation change');}
   oemCheck(Db::name('virtual_pay_intent')->where('id',$i['id'])->find()===$before,'no stale intent mutation after I/O');oemCheck(Db::name('virtual_pay_work')->where('intent_id',$i['id'])->order('id')->select()->toArray()===$work,'no stale work mutation');
  }
 }
 echo "PASS $case\n";
}catch(Throwable $e){$failed[]=$case;fwrite(STDERR,"FAIL $case: ".$e->getMessage()."\n");}finally{$GLOBALS['virtual_review_generation_changed']=false;Db::rollback();}}
exit($failed?1:0);
