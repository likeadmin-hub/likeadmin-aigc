<?php
declare(strict_types=1);
namespace app\common\service\brand;
use think\facade\Db;
use think\facade\Log;
final class BrandMaintenanceService
{
    public static function remind(): int {
        $today=strtotime('today'); $count=0;
        $ids=Db::name('tenant_brand_order')->where(['flow_version'=>2,'pay_status'=>1])->where('pay_time','<=',time()-86400)
            ->whereNotIn('workflow_state',['delivered','refunded','closed'])->where('last_remind_time','<',$today)->order('pay_time')->limit(100)->column('id');
        foreach($ids as $id) $count+=Db::transaction(function()use($id,$today) {
            $o=BrandPlatformService::order((int)$id,true);
            if ((int)$o['pay_time']>time()-86400 || (int)$o['last_remind_time']>=$today || in_array($o['workflow_state'],['delivered','refunded','closed'],true)) return 0;
            Db::name('tenant_brand_order')->where('id',$id)->update(['last_remind_time'=>time(),'remind_count'=>(int)$o['remind_count']+1,'update_time'=>time()]);
            BrandWorkflowService::event($o,'system',0,'overdue_reminder',$o['workflow_state'],'付款已超过24小时，请服务商或平台人工跟进；当天仅提醒一次'); return 1;
        }); return $count;
    }
    public static function statistics(int $tenant): array {
        if($tenant<1) throw new BrandUserException('租户身份无效');
        $order=Db::name('tenant_brand_order')->where(['tenant_id'=>$tenant,'flow_version'=>2])->whereNull('delete_time');
        $money=static fn(string $from,?string $disposition=null): int => (int)Db::name('tenant_brand_payment')->where(['tenant_id'=>$tenant,'order_from'=>$from])->when($disposition!==null,fn($q)=>$q->where('disposition',$disposition))->sum('amount_cents');
        $refund=(int)Db::name('tenant_brand_refund')->where(['tenant_id'=>$tenant,'order_from'=>TenantBrandService::FROM_ORDER,'status'=>'success'])->sum('amount_cents');
        $quota=Db::name('tenant_brand_quota_bucket')->where('tenant_id',$tenant)->field('COALESCE(SUM(remaining_quota-reserved_quota),0) AS available,COALESCE(SUM(reserved_quota),0) AS frozen,COALESCE(SUM(used_quota),0) AS used')->find();
        $received=$money(TenantBrandService::FROM_ORDER);
        return ['order_count'=>(int)(clone $order)->count(),'delivered_count'=>(int)(clone $order)->where('workflow_state','delivered')->count(),
            'overdue_count'=>(int)(clone $order)->where('pay_status',1)->where('pay_time','<=',time()-86400)->whereNotIn('workflow_state',['delivered','refunded','closed'])->count(),
            'customer_received'=>BrandOrderPolicy::amount($received),'refund_amount'=>BrandOrderPolicy::amount($refund),'customer_net_received'=>BrandOrderPolicy::amount(max(0,$received-$refund)),
            'procurement_amount'=>BrandOrderPolicy::amount($money(TenantBrandService::FROM_QUOTA,'primary')),'available_quota'=>(int)$quota['available'],'frozen_quota'=>(int)$quota['frozen'],'used_quota'=>(int)$quota['used']];
    }
    public static function run(): array {
        $started=microtime(true); $result=['expired'=>TenantBrandService::expirePendingOrders(),'reminded'=>self::remind(),'payment_checked'=>0,'refund_checked'=>0];
        $refunds=Db::name('tenant_brand_refund')->whereIn('status',['queued','processing','unknown'])->where('next_check_time','<=',time())->where('lease_until','<=',time())->order('next_check_time,id')->limit(2)->column('id');
        foreach($refunds as $id) {
            if(microtime(true)-$started>=35 || self::changed()) break;
            try { BrandRefundService::process((int)$id); $result['refund_checked']++; }
            catch(\Throwable $e) { Log::error('OEM maintenance refund '.$id.': '.$e->getMessage()); }
        }
        // Both unpaid and paid intents are queried: a second transaction can arrive after the first payment.
        $intents=Db::name('tenant_brand_payment_intent')->where('last_query_time','<=',time()-60)->where('create_time','>=',time()-7*86400)->order('last_query_time,id')->limit(2)->select()->toArray();
        foreach($intents as $intent) {
            if(microtime(true)-$started>=35 || self::changed()) break;
            BrandPaymentService::reconcile($intent['order_from'],(int)$intent['order_id'],1); $result['payment_checked']++;
        }
        return $result;
    }
    private static function changed(): bool { return function_exists('aigc_runtime_generation_changed') && \aigc_runtime_generation_changed(); }
}
