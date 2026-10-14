<?php
declare(strict_types=1);
namespace app\common\service\virtualpay;
use think\facade\Db;
use app\common\service\promotion\PackagePromotionPolicy as Money;
final class VirtualPayAdminService
{
    public static function orders(int $tenant,int $page=1,string $state=''): array
    {
        $query=Db::name('virtual_pay_intent')->where('tenant_id',$tenant);
        if($state==='attention')$query->where(function($q){$q->where('last_error','<>','')->whereOr('refund_status','manual')->whereOr('recovery_status','manual');});
        elseif($state==='pending')$query->where('status','pending');
        elseif($state==='refunded')$query->where('refund_status','success');
        $count=(clone $query)->count();$rows=$query->field('id,user_id,biz_type,order_id,app_id,env,out_trade_no,amount_cents,platform,status,delivery_status,xpay_shipping_status,general_shipping_status,refund_status,recovery_status,created_at,paid_at,last_error,order_type')->order('id desc')->page(max(1,$page),20)->select()->toArray();
        foreach($rows as &$row){$row['order_amount']=Money::amount((int)$row['amount_cents']);unset($row['amount_cents']);$row['can_refund']=$row['status']==='paid'&&(int)$row['order_type']!==7&&in_array($row['refund_status'],['none','manual'],true);$row['can_retry']=$row['refund_status']!=='success';}unset($row);
        $events=Db::name('virtual_pay_event')->where('tenant_id',$tenant)->whereIn('status',['attention','failed','manual'])->field('id,event,intent_id,message,created_at')->order('id desc')->limit(30)->select()->toArray();
        $ordinary=Db::name('package_payment_receipt')->where(['tenant_id'=>$tenant,'status'=>'manual'])->field('id,snapshot_id,channel,amount_cents,message,created_at')->order('id desc')->limit(30)->select()->toArray();
        foreach($ordinary as &$r){$r['amount']=Money::amount((int)$r['amount_cents']);unset($r['amount_cents']);}unset($r);
        return ['lists'=>$rows,'count'=>$count,'events'=>$events,'ordinary_attention'=>$ordinary];
    }
    public static function retry(int $tenant,int $actor,int $id): void
    {
        Db::transaction(function()use($tenant,$actor,$id){$i=VirtualPayRefundService::owned($tenant,$id,true);
            $kind=$i['refund_status']!=='none'?'refund':($i['status']==='pending'?'payment':($i['delivery_status']!=='granted'?'delivery':'xpay_shipping'));
            $kinds=$kind==='xpay_shipping'?['xpay_shipping','general_shipping']:[$kind];
            foreach($kinds as $k){VirtualPayMaintenanceService::enqueue($id,$k);Db::name('virtual_pay_work')->where(['intent_id'=>$id,'kind'=>$k])->where('status','<>','running')->update(['status'=>'pending','attempts'=>0,'next_at'=>time(),'last_error'=>'','updated_at'=>time()]);}
            VirtualPayRefundService::audit($i,'manual_retry',$actor,'已安排按原交易重新核对，核对不会直接修改到账结果');
        });
    }
    public static function resolve(int $tenant,int $actor,int $id,string $note): void
    {
        $note=trim($note);if(mb_strlen($note)<2||mb_strlen($note)>500)throw new \RuntimeException('请填写2至500字的处理说明');
        Db::transaction(function()use($tenant,$actor,$id,$note){$i=VirtualPayRefundService::owned($tenant,$id,true);VirtualPayRefundService::audit($i,'manual_note',$actor,$note);});
    }
}
