<?php
declare(strict_types=1);
namespace app\common\service\virtualpay;
use think\facade\Db;
use app\common\service\promotion\PackagePricingService as Price;
/** Bounded outbox processing. A process lock and a bounded per-item lease protect slow calls. */
final class VirtualPayMaintenanceService
{
    private VirtualPayService $pay;
    public function __construct(?VirtualPayService $pay=null){$this->pay=$pay??new VirtualPayService();}
    public static function enqueue(int $id,string $kind): void
    {
        if(!in_array($kind,['payment','delivery','refund','xpay_shipping','general_shipping'],true))throw new \InvalidArgumentException('核对任务类型不正确');
        Db::name('virtual_pay_work')->extra('IGNORE')->insert(['intent_id'=>$id,'kind'=>$kind,'next_at'=>time(),'updated_at'=>time()]);
    }
    public function cancel(int $tenant,int $user,string $from,int $order): array
    {
        VirtualPayPolicy::assertRuntime();$o=VirtualPayService::order($tenant,$user,$from,$order);$s=Price::snapshot($tenant,$from,$order);
        if(!$s)throw new \RuntimeException('该历史订单请使用原入口处理');if($s['status']==='closed')return ['status'=>'closed','message'=>'订单已关闭'];
        if((int)$o['pay_status']||$s['status']!=='pending')throw new \RuntimeException('订单已付款，请查看到账结果');
        if((int)$o['pay_way']===0&&trim((string)$o['pay_sn'])==='')Price::closeConfirmed($tenant,$user,$from,$order,'never_started');
        elseif((int)$o['pay_way']===4){
            $i=VirtualPayService::intent($tenant,(string)Db::name('virtual_pay_intent')->where(['tenant_id'=>$tenant,'out_trade_no'=>$o['pay_sn']])->value('app_id'),(string)$o['pay_sn']);
            $result=$this->pay->reconcile($i);if((int)$result['status']!==6)throw new \RuntimeException('支付渠道尚未确认关闭，请稍后查询原订单，优惠资格会继续保留');
        }else{(new \app\common\service\promotion\PackagePaymentService())->cancel($tenant,$user,$from,$order);}
        return ['status'=>'closed','message'=>'订单已关闭，未使用的优惠资格已释放'];
    }
    public function run(int $limit=10,int $seconds=40): array
    {
        $deadline=microtime(true)+max(1,min(45,$seconds));$done=0;$pending=0;
        for($n=0;$n<max(1,min(20,$limit))&&microtime(true)<$deadline;$n++){
            VirtualPayPolicy::assertRuntime();
            $job=Db::transaction(function(){
                $q=Db::name('virtual_pay_work')->where('next_at','<=',time())->where(function($q){$q->where('status','pending')->whereOr(function($q){$q->where('status','running')->where('lease_until','<',time());});})->order('next_at,id')->lock(true)->find();
                if(!$q)return null;$token=bin2hex(random_bytes(16));$generation=function_exists('aigc_runtime_generation')?(string)\aigc_runtime_generation():'';
                Db::name('virtual_pay_work')->where('id',$q['id'])->update(['status'=>'running','lease_token'=>$token,'lease_until'=>time()+180,'generation'=>$generation,'attempts'=>(int)$q['attempts']+1,'updated_at'=>time()]);return $q+['token'=>$token];
            });if(!$job)break;
            $error='';$complete=false;
            try{
                $i=Db::name('virtual_pay_intent')->where('id',$job['intent_id'])->find();if(!$i)throw new \RuntimeException('核对订单不存在');
                // Named lock survives a lease timeout while a live owner is still in an external call.
                $database=(string)(Db::query('SELECT DATABASE() AS db')[0]['db']??'');$key='vp-work:'.substr(hash('sha256',$database.'|'.$job['id']),0,48);
                if((int)(Db::query('SELECT GET_LOCK(?,0) AS n',[$key])[0]['n']??0)!==1)throw new \RuntimeException('该订单正在核对，请稍后再试');
                try{
                    switch($job['kind']){
                        case 'payment':$this->pay->reconcile($i);$current=Db::name('virtual_pay_intent')->where('id',$i['id'])->find();$complete=$current['status']!=='pending';break;
                        case 'delivery':if($i['refund_status']!=='none'){$complete=true;break;}(new VirtualPayDeliveryService($this->pay))->grant((int)$i['id']);$complete=true;break;
                        case 'refund':$complete=(new VirtualPayRefundService($this->pay))->process((int)$i['id']);break;
                        case 'xpay_shipping':$complete=$i['refund_status']!=='none'||(new VirtualPayDeliveryService($this->pay))->syncXpay((int)$i['id']);break;
                        case 'general_shipping':$complete=$i['refund_status']!=='none'||(new MiniappShippingService($this->pay))->sync((int)$i['id']);break;
                        default:throw new \RuntimeException('任务类型需要人工核对');
                    }
                }finally{Db::query('SELECT RELEASE_LOCK(?)',[$key]);}
            }catch(\Throwable $e){$error=VirtualPayPolicy::error($e);}
            VirtualPayPolicy::assertRuntime();$attempt=(int)$job['attempts']+1;$manual=!$complete&&$attempt>=20;
            Db::name('virtual_pay_work')->where(['id'=>$job['id'],'lease_token'=>$job['token']])->update(['status'=>$complete?'done':($manual?'manual':'pending'),'lease_token'=>'','lease_until'=>0,'next_at'=>time()+min(3600,15*(2**min(8,$attempt))),'last_error'=>$error,'updated_at'=>time()]);
            if($error!=='')Db::name('virtual_pay_intent')->where('id',$job['intent_id'])->update(['last_error'=>$error,'updated_at'=>time()]);
            $complete?$done++:$pending++;
        }
        // Never-started orders can close locally. Launched ordinary orders use their original gateway context.
        if(microtime(true)<$deadline){
            $expired=Db::name('tenant_package_price_snapshot')->where(['status'=>'pending'])->where('expires_at','<=',time())->where('next_check_at','<=',time())->order('next_check_at,id')->limit(5)->select()->toArray();
            foreach($expired as $s){if(microtime(true)>=$deadline)break;VirtualPayPolicy::assertRuntime();try{Db::name('tenant_package_price_snapshot')->where('id',$s['id'])->update(['next_check_at'=>time()+300]);$this->cancel((int)$s['tenant_id'],(int)$s['user_id'],$s['biz_type'],(int)$s['order_id']);}catch(\Throwable $e){\think\facade\Log::warning('Package expiry retained: '.VirtualPayPolicy::error($e));}}
        }
        return ['done'=>$done,'pending'=>$pending];
    }
}
