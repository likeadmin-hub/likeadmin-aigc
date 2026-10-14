<?php
require __DIR__.'/Support/OemDisposable.php';require __DIR__.'/Support/OemFixtures.php';require __DIR__.'/Support/VirtualPayFixture.php';
use think\facade\Db;
use app\common\service\virtualpay\VirtualPayService as Pay;
use app\common\service\virtualpay\VirtualPayGateway as Gateway;
use app\common\service\virtualpay\VirtualPaySessionService as Session;
use app\common\service\virtualpay\VirtualPayRefundService as Refund;
use app\common\service\promotion\PackagePricingService as Price;
Db::startTrans();try{
 $f=virtualFixture();$original=[];$refundOrder=[];$refundCalls=0;$loseResponse=true;
 $g=new Gateway(static function($method,$path,$query,$body)use(&$f,&$original,&$refundOrder,&$refundCalls,&$loseResponse){
  if($path==='/sns/jscode2session')return ['openid'=>$f['openid'],'session_key'=>'fixture-session'];$p=json_decode($body,true);
  if($path==='/xpay/query_order')return str_starts_with($p['order_id'],'R')?['errcode'=>0,'order'=>$refundOrder]:$original;
  if($path==='/xpay/refund_order'){$refundCalls++;$refundOrder=['order_id'=>$p['refund_order_id'],'env_type'=>1,'status'=>5,'order_type'=>1,'refund_fee'=>$p['refund_fee'],'wx_order_id'=>'refund-'.$p['refund_order_id']];if($loseResponse)throw new RuntimeException('Lost external refund response');return ['errcode'=>0];}
  return ['errcode'=>0];
 });
 $pay=new Pay($g);$session=(new Session($g))->exchange($f['tenant'],$f['user'],'code');$pre=$pay->prepay($f['tenant'],$f['user'],1,'recharge',$f['order']['order_id'],$session,'android');$i=Db::name('virtual_pay_intent')->where('out_trade_no',$pre['out_trade_no'])->find();$original=virtualQuery($i);$pay->reconcile($i);
 $refund=new Refund($pay);$requested=$refund->request($f['tenant'],9,$i['id']);$sn=$requested['refund_order_id'];oemCheck(str_starts_with($sn,'R'),'stable refund reference');
 oemReject(fn()=>$refund->process($i['id']),'external unknown refund');oemCheck($refundCalls===1,'first submission');
 $refund->process($i['id']);oemCheck($refundCalls===1,'query-first unknown never resubmits');oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='100.00','debit-state5 is not user refund success');
 $refundOrder['status']=8;$refund->process($i['id']);$refund->process($i['id']);oemCheck($refundCalls===1,'completed refund never issues another request');
 oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='0.00','proven unused points recovered after channel completion');
 oemCheck(Db::name('virtual_pay_intent')->where('id',$i['id'])->value('refund_status')==='success','confirmed financial completion');
 oemCheck(Price::quote($f['tenant'],$f['user'],'recharge',$f['package'])['reason']==='used','refund never restores successful discount');
 $duplicate=['Event'=>'xpay_refund_notify','MchOrderId'=>$i['out_trade_no'],'MchRefundId'=>$sn,'OpenId'=>$i['openid'],'RefundFee'=>990,'RetCode'=>0];$refund->event($f['tenant'],$f['app'],$duplicate);$refund->process($i['id']);
 oemCheck(Db::name('user_account_log')->where(['tenant_id'=>$f['tenant'],'user_id'=>$f['user'],'change_type'=>101])->count()===1,'duplicate refund notification does not reset completion or reclaim twice');
 // Consumed/frozen/running rights are not removed and no running task is canceled.
 $f=virtualFixture();$session=(new Session($g))->exchange($f['tenant'],$f['user'],'code');$pre=$pay->prepay($f['tenant'],$f['user'],1,'recharge',$f['order']['order_id'],$session,'android');$i2=Db::name('virtual_pay_intent')->where('out_trade_no',$pre['out_trade_no'])->find();$original=virtualQuery($i2);$pay->reconcile($i2);
 $task=Db::name('ai_app_task')->insertGetId(['task_no'=>'vp-running-'.$i2['id'],'tenant_id'=>$f['tenant'],'user_id'=>$f['user'],'app_code'=>'system_default','status'=>'running','create_time'=>time()]);$snapshot=Db::name('ai_app_task')->where('id',$task)->find();$count=$refundCalls;
 $manual=$refund->request($f['tenant'],9,$i2['id']);oemCheck($manual['refund_status']==='manual'&&$refundCalls===$count,'active task requires human refund handling');
 oemCheck(Db::name('ai_app_task')->where('id',$task)->find()===$snapshot,'running task unchanged');oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='100.00','running rights untouched');
 // Apple query must be fast and local; the developer must not initiate Apple refund.
 Db::name('virtual_pay_intent')->where('id',$i2['id'])->update(['order_type'=>7,'platform'=>'ios','refund_status'=>'none']);$original=virtualQuery($i2,2,true);oemReject(fn()=>$refund->request($f['tenant'],9,$i2['id']),'Apple developer refund prohibited');
 $apple=$refund->event($f['tenant'],$f['app'],['Event'=>'xpay_subscribe_ios_refund_query_notify','pay_order_id'=>$i2['out_trade_no']]);oemCheck($apple['result_code']===1&&!empty($apple['evidence']),'active Apple rights produce audited local decision');

 // First discovery after channel refund still consumes the original successful discount.
 $f=virtualFixture();$session=(new Session($g))->exchange($f['tenant'],$f['user'],'code');$pre=$pay->prepay($f['tenant'],$f['user'],1,'recharge',$f['order']['order_id'],$session,'android');$late=Db::name('virtual_pay_intent')->where('out_trade_no',$pre['out_trade_no'])->find();$original=virtualQuery($late);$original['order']['status']=8;$original['order']['left_fee']=0;$pay->reconcile($late);$beforeCalls=$refundCalls;$refund->process($late['id']);
 oemCheck($beforeCalls===$refundCalls,'externally refunded Android transaction never initiates another refund');oemCheck(Price::quote($f['tenant'],$f['user'],'recharge',$f['package'])['reason']==='used','first discovered refunded payment still consumes once discount');
 // A task beginning after the refund request must remain intact even if the channel refunds.
 $f=virtualFixture();$session=(new Session($g))->exchange($f['tenant'],$f['user'],'code');$pre=$pay->prepay($f['tenant'],$f['user'],1,'recharge',$f['order']['order_id'],$session,'android');$moving=Db::name('virtual_pay_intent')->where('out_trade_no',$pre['out_trade_no'])->find();$original=virtualQuery($moving);$pay->reconcile($moving);$loseResponse=false;$refund->request($f['tenant'],9,$moving['id']);$refund->process($moving['id']);
 $taskId=Db::name('ai_app_task')->insertGetId(['task_no'=>'vp-refund-inflight-'.$moving['id'],'tenant_id'=>$f['tenant'],'user_id'=>$f['user'],'app_code'=>'system_default','status'=>'running','create_time'=>time()]);$taskBefore=Db::name('ai_app_task')->where('id',$taskId)->find();$refundOrder['status']=8;$refund->process($moving['id']);
 oemCheck(Db::name('virtual_pay_intent')->where('id',$moving['id'])->value('recovery_status')==='manual','concurrent use records refund but defers recovery');oemCheck(Db::name('ai_app_task')->where('id',$taskId)->find()===$taskBefore,'inflight task remains byte-equal');oemCheck(Db::name('user')->where('id',$f['user'])->value('user_money')==='100.00','does not seize active task rights');
 echo "PASS fixed query-first refunds, unknown/debit-state retention, unused recovery, once discount and running-task/Apple protection\n";
}finally{Db::rollback();}
