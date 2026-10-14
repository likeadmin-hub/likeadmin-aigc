<?php
declare(strict_types=1);
namespace app\common\service\brand;
use app\common\service\brand\BrandUserException as RuntimeException;
use think\facade\Db;

/** Only signed gateway notifications or authenticated server queries call recordVerified. */
final class BrandPaymentService
{
    public static function gateway(): BrandPaymentGateway
    {
        return app()->make(BrandChannelGateway::class);
    }

    public static function prepay(string $from,array $order,int $channel,string $redirect): array
    {
        $table=self::table($from);
        $intent=Db::transaction(function()use($table,$from,$order,$channel) {
            $current=Db::name($table)->where(['id'=>$order['id'],'tenant_id'=>$order['tenant_id'],'flow_version'=>2])->whereNull('delete_time')->lock(true)->find();
            if (!$current || (int)$current['pay_status']===1) throw new RuntimeException('订单已付款，请查看办理进度');
            $expires=$from===TenantBrandService::FROM_ORDER?(int)$current['reserve_expire_time']:(int)$current['create_time']+1800;
            if ((int)$current['closed_time']>0 || $expires<=time()) throw new RuntimeException('订单已过期，请重新提交申请');
            $context=self::gateway()->context($from,$current,$channel);
            $intent=Db::name('tenant_brand_payment_intent')->where(['order_from'=>$from,'order_id'=>$current['id'],'channel'=>$channel,'merchant_id'=>$context['merchant_id']])->where('expires_at','>',time())->order('id','desc')->find();
            if ($intent && json_decode($intent['context'],true)!=$context) throw new RuntimeException('收款配置已变更，请联系服务商核对未完成的付款');
            if (!$intent) {
                $intent=['order_from'=>$from,'order_id'=>$current['id'],'tenant_id'=>$current['tenant_id'],'pay_sn'=>'OB'.bin2hex(random_bytes(12)),
                    'channel'=>$channel,'merchant_id'=>$context['merchant_id'],'amount_cents'=>BrandOrderPolicy::cents((string)$current['order_amount']),
                    'currency'=>'CNY','context'=>json_encode($context,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'expires_at'=>$expires,'create_time'=>time(),'update_time'=>time()];
                $intent['id']=Db::name('tenant_brand_payment_intent')->insertGetId($intent);
            }
            Db::name($table)->where('id',$current['id'])->update(['pay_way'=>$channel,'pay_sn'=>$intent['pay_sn'],'update_time'=>time()]);
            return $intent;
        });
        $result=self::gateway()->prepay($intent,$redirect);
        $result['order_amount']=BrandOrderPolicy::amount((int)$intent['amount_cents']);
        $result['expires_at']=(int)$intent['expires_at'];
        return $result;
    }

    public static function reconcile(string $from,int $orderId,int $limit=20): void
    {
        self::table($from);
        $intents=Db::name('tenant_brand_payment_intent')->where(['order_from'=>$from,'order_id'=>$orderId])->order('last_query_time,id')->limit(max(1,min(20,$limit)))->select()->toArray();
        foreach ($intents as $intent) {
            // Gateway timeout is five seconds; this atomic claim prevents concurrent status calls.
            $claimed=Db::name('tenant_brand_payment_intent')->where('id',$intent['id'])->where('last_query_time','<=',time()-10)->update(['last_query_time'=>time()]);
            if (!$claimed) continue;
            try {
                $receipt=self::gateway()->query($intent);
                if ($receipt) self::recordVerified($receipt);
            } catch (\Throwable $e) {
                \think\facade\Log::error('OEM payment reconciliation '.$intent['id'].': '.$e->getMessage());
            }
        }
    }

    public static function recordVerified(array $receipt): void
    {
        $intent=Db::name('tenant_brand_payment_intent')->where('pay_sn',(string)($receipt['pay_sn']??''))->find();
        if (!$intent) throw new RuntimeException('支付订单需要核对');
        $context=json_decode((string)$intent['context'],true)?:[];
        if (($receipt['status']??'')!=='success' || !hash_equals((string)$intent['merchant_id'],(string)($receipt['merchant_id']??''))
            || !hash_equals((string)($context['app_id']??''),(string)($receipt['app_id']??''))
            || (int)($receipt['channel']??0)!==(int)$intent['channel'] || (string)($receipt['currency']??'')!==$intent['currency']
            || !is_int($receipt['amount_cents']??null) || $receipt['amount_cents']!==(int)$intent['amount_cents']
            || !preg_match('/^[A-Za-z0-9_\-.]{1,128}$/D',(string)($receipt['transaction_id']??''))) throw new RuntimeException('支付结果校验未通过，请联系服务商核对');
        Db::transaction(function()use($intent,$receipt) {
            $table=self::table((string)$intent['order_from']);
            // Linked purchases use the same customer -> procurement -> bucket lock order as createQuota.
            $linked=null;
            if ($intent['order_from']===TenantBrandService::FROM_QUOTA) {
                $link=(int)Db::name($table)->where(['id'=>$intent['order_id'],'tenant_id'=>$intent['tenant_id'],'flow_version'=>2])->value('brand_order_id');
                if ($link>0) $linked=Db::name('tenant_brand_order')->where(['id'=>$link,'tenant_id'=>$intent['tenant_id'],'flow_version'=>2])->lock(true)->find();
            }
            $order=Db::name($table)->where(['id'=>$intent['order_id'],'tenant_id'=>$intent['tenant_id'],'flow_version'=>2])->whereNull('delete_time')->lock(true)->find();
            if (!$order || BrandOrderPolicy::cents((string)$order['order_amount'])!==(int)$intent['amount_cents']) throw new RuntimeException('支付金额或订单归属需要核对');
            $existing=Db::name('tenant_brand_payment')->where(['channel'=>$intent['channel'],'merchant_id'=>$intent['merchant_id'],'transaction_id'=>$receipt['transaction_id']])->find();
            if ($existing) {
                if ($existing['order_from']!==$intent['order_from'] || (int)$existing['order_id']!==(int)$order['id']) throw new RuntimeException('交易号已关联其他订单');
                return;
            }
            $duplicate=(int)$order['pay_status']===1;
            $closed=(int)$order['closed_time']>0;
            $paidTime=(int)($receipt['paid_time']??time());
            if (!$duplicate && !$closed && $paidTime>((int)($order['reserve_expire_time']??((int)$order['create_time']+1800)))) {
                if ($intent['order_from']===TenantBrandService::FROM_ORDER) BrandWorkflowService::closeLocked($order,'超时订单收到付款');
                else Db::name($table)->where('id',$order['id'])->update(['closed_time'=>time()]);
                $closed=true;
            }
            $paymentId=Db::name('tenant_brand_payment')->insertGetId([
                'intent_id'=>$intent['id'],'order_from'=>$intent['order_from'],'order_id'=>$order['id'],'tenant_id'=>$order['tenant_id'],
                'channel'=>$intent['channel'],'merchant_id'=>$intent['merchant_id'],'transaction_id'=>$receipt['transaction_id'],
                'amount_cents'=>$intent['amount_cents'],'currency'=>$intent['currency'],'disposition'=>$duplicate?'duplicate':($closed?'late':'primary'),
                'paid_time'=>$paidTime,'context'=>$intent['context'],'create_time'=>time(),'update_time'=>time(),
            ]);
            if ($duplicate || $closed) self::queueRefund($paymentId,$intent,$duplicate?'重复付款退还多付交易':'已关闭订单原路退款');
            if (!$duplicate) {
                $changes=['pay_status'=>1,'pay_way'=>$intent['channel'],'transaction_id'=>$receipt['transaction_id'],'pay_time'=>$paidTime,'update_time'=>time()];
                if ($intent['order_from']===TenantBrandService::FROM_ORDER) {
                    $changes['workflow_state']=$closed?'refunding':'tenant_review';
                    if ($closed) $changes['refund_status']=2;
                    BrandWorkflowService::event($order,'system',0,'payment',$changes['workflow_state'],'付款已核验');
                } elseif (!$closed) {
                    BrandQuotaService::increase($order);
                    if ($linked) BrandWorkflowService::reserveLinked($linked);
                }
                Db::name($table)->where('id',$order['id'])->update($changes);
            }
        });
    }

    public static function queueRefund(int $paymentId,array $intent,string $reason): int
    {
        $existing=Db::name('tenant_brand_refund')->where('payment_id',$paymentId)->find();
        if ($existing) return (int)$existing['id'];
        return Db::name('tenant_brand_refund')->insertGetId(['payment_id'=>$paymentId,'order_from'=>$intent['order_from'],'order_id'=>$intent['order_id'],
            'tenant_id'=>$intent['tenant_id'],'refund_sn'=>'OER'.str_pad((string)$paymentId,12,'0',STR_PAD_LEFT),'amount_cents'=>$intent['amount_cents'],
            'reason'=>mb_substr($reason,0,255),'status'=>'queued','create_time'=>time(),'update_time'=>time()]);
    }

    public static function table(string $from): string
    {
        return match($from) { TenantBrandService::FROM_ORDER=>'tenant_brand_order',TenantBrandService::FROM_QUOTA=>'tenant_brand_quota_order',default=>throw new RuntimeException('不支持的贴牌订单类型') };
    }
}
