<?php
declare(strict_types=1);
namespace app\common\service\promotion;
use think\facade\Db;
use app\common\model\user\User;
use app\common\model\recharge\RechargePackage;
use app\common\model\recharge\RechargeOrder;
use app\common\model\membership\MembershipPlan;
use app\common\model\membership\MembershipPlanApp;
use app\common\model\membership\MembershipOrder;

/** Authoritative quotes and immutable order rights; every namespace includes its tenant. */
final class PackagePricingService
{
    public static function catalog(int $tenant,string $from,int $id,string $cycle='package',bool $active=true,bool $lock=false): array
    {
        if($tenant<=0||$id<=0||!in_array($from,['recharge','membership'],true))throw new \InvalidArgumentException('套餐类型或归属不正确');
        $query=$from==='recharge'?RechargePackage::where(['tenant_id'=>$tenant,'id'=>$id]):MembershipPlan::where(['tenant_id'=>$tenant,'id'=>$id]);
        if($active)$query->where('status',1);if($lock)$query->lock(true);$p=$query->findOrEmpty();if($p->isEmpty())throw new \RuntimeException('套餐不存在或已下架');
        if($from==='recharge')return ['cycle'=>'package','name'=>(string)$p['name'],'regular_cents'=>PackagePromotionPolicy::cents($p['amount']),'rights'=>['recharge_points'=>(string)$p['points']]];
        if(!in_array($cycle,['package','monthly','yearly'],true))throw new \InvalidArgumentException('会员购买周期不正确');
        $duration=(int)$p['duration_months'];$cycle=$duration>0?'package':($cycle==='yearly'?'yearly':'monthly');$year=$cycle==='yearly';
        return ['cycle'=>$cycle,'name'=>(string)$p['name'],'regular_cents'=>PackagePromotionPolicy::cents($p[$year?'yearly_price':'monthly_price']),
            'rights'=>['duration_months'=>$duration>0?$duration:($year?12:1),'bonus_points'=>(string)$p[$year?'yearly_bonus_points':'monthly_bonus_points'],'features'=>(array)$p['features'],'app_codes'=>MembershipPlanApp::where(['tenant_id'=>$tenant,'plan_id'=>$id])->column('app_code')]];
    }
    private static function scope(int $tenant,int $user,string $from,int $package): array {return ['tenant_id'=>$tenant,'user_id'=>$user,'biz_type'=>$from,'package_id'=>$package];}
    public static function quote(int $tenant,int $user,string $from,int $package,string $cycle='package',?array $catalog=null): array
    {
        $base=$catalog??self::catalog($tenant,$from,$package,$cycle);$now=time();$registered=0;
        if($user>0){$person=User::where(['tenant_id'=>$tenant,'id'=>$user])->field('id,create_time')->findOrEmpty();if($person->isEmpty())throw new \RuntimeException('用户身份不正确');$registered=(int)$person->getData('create_time');}
        $p=PackagePromotionService::get($tenant,$from,$package);$prices=json_decode($p['discount_prices']??'{}',true)?:[];$p['discount_cents']=(int)($prices[$base['cycle']]??0);
        $claim=$user>0?(Db::name('tenant_package_discount_claim')->where(self::scope($tenant,$user,$from,$package))->find()?:[]):[];
        // Guests cannot reserve or be promised a user-specific one-use price.
        $state=PackagePromotionPolicy::quote($user>0?$p:[], $base['regular_cents'],$registered,$now,(string)($claim['status']??''));
        $version=(int)($p['version']??0);$hash=hash('sha256',json_encode([$tenant,$user,$from,$package,$base,$version,$state],JSON_THROW_ON_ERROR));
        return ['package_id'=>$package,'from'=>$from,'cycle'=>$base['cycle'],'regular_price'=>PackagePromotionPolicy::amount($state['regular_cents']),'payable_price'=>PackagePromotionPolicy::amount($state['payable_cents']),'discount_amount'=>PackagePromotionPolicy::amount($state['discount_cents']),
            'is_discount'=>$state['is_discount'],'matched_rules'=>$state['matched_rules'],'eligible_end_at'=>$state['eligible_end_at'],'server_time'=>$now,'reason'=>$user<=0?'login_required':$state['reason'],'promotion_version'=>$version,'quote_hash'=>$hash,'pending_order_id'=>(int)(($claim['status']??'')==='reserved'?($claim['order_id']??0):0)];
    }
    public static function create(int $tenant,int $user,int $terminal,string $from,int $package,string $cycle='',string $quoteHash=''): array
    {
        if ($user <= 0) throw new \RuntimeException('请先登录后购买套餐');
        return Db::transaction(function()use($tenant,$user,$terminal,$from,$package,$cycle,$quoteHash){
            $identity=User::where(['tenant_id'=>$tenant,'id'=>$user])->lock(true)->findOrEmpty();if($identity->isEmpty()||!empty($identity['is_disable']))throw new \RuntimeException('用户身份已变化，请重新登录');
            $base=self::catalog($tenant,$from,$package,$cycle?:'package',true,true);$p=PackagePromotionService::get($tenant,$from,$package);$scope=self::scope($tenant,$user,$from,$package);$claim=[];
            if(!empty($p['enabled'])){
                Db::name('tenant_package_discount_claim')->extra('IGNORE')->insert($scope+['status'=>'available','create_time'=>time()]);
                $claim=Db::name('tenant_package_discount_claim')->where($scope)->lock(true)->find();
                if($claim['status']==='reserved')throw new \RuntimeException('您已有该套餐的优惠待付款订单，请先处理原订单');
            }
            $quote=self::quote($tenant,$user,$from,$package,$base['cycle'],$base);
            if($quoteHash!==''&&!hash_equals($quote['quote_hash'],$quoteHash))throw new \RuntimeException('套餐价格或优惠资格已变化，请刷新并确认当前价格');
            $amount=$quote['payable_price'];$rights=$base['rights'];$now=time();
            if($from==='recharge'){
                $sn=generate_sn(RechargeOrder::class,'sn');$order=RechargeOrder::create(['tenant_id'=>$tenant,'user_id'=>$user,'order_terminal'=>$terminal,'sn'=>$sn,'order_amount'=>$amount,'recharge_points'=>$rights['recharge_points'],'package_id'=>$package,'package_name'=>$base['name'],'pay_way'=>0,'pay_status'=>0]);
            }else{
                $sn=generate_sn(MembershipOrder::class,'order_sn');$order=MembershipOrder::create(['tenant_id'=>$tenant,'user_id'=>$user,'order_terminal'=>$terminal,'order_sn'=>$sn,'order_amount'=>$amount,'plan_id'=>$package,'plan_name'=>$base['name'],'cycle'=>$base['cycle'],'duration_months'=>$rights['duration_months'],'bonus_points'=>$rights['bonus_points'],'before_expire_time'=>0,'after_expire_time'=>0,'pay_way'=>0,'pay_status'=>0,'create_time'=>$now,'update_time'=>$now]);
            }
            $id=(int)$order->id;
            Db::name('tenant_package_price_snapshot')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'biz_type'=>$from,'order_id'=>$id,'order_sn'=>$sn,'package_id'=>$package,'cycle'=>$base['cycle'],'regular_cents'=>$base['regular_cents'],'payable_cents'=>PackagePromotionPolicy::cents($amount),'is_discount'=>$quote['is_discount']?1:0,'promotion_version'=>$quote['promotion_version'],'matched_rules'=>implode(',',$quote['matched_rules']),'eligible_end_at'=>$quote['eligible_end_at'],'expires_at'=>$now+1800,'rights'=>json_encode($rights,JSON_THROW_ON_ERROR),'created_at'=>$now]);
            if($quote['is_discount'])Db::name('tenant_package_discount_claim')->where('id',$claim['id'])->update(['status'=>'reserved','order_id'=>$id,'reserved_until'=>$now+1800,'update_time'=>$now]);
            return ['order_id'=>$id,'order_sn'=>$sn,'from'=>$from,'order_amount'=>$amount,'expires_at'=>$now+1800,'price_info'=>$quote];
        });
    }
    public static function snapshot(int $tenant,string $from,int $id): ?array
    {
        return Db::name('tenant_package_price_snapshot')->where(['tenant_id'=>$tenant,'biz_type'=>$from,'order_id'=>$id])->find();
    }
    /** Confirmed funds consume a discount even when entitlement delivery must retry. */
    public static function confirmPaymentCoupon(int $tenant, string $from, int $id): void
    {
        $s = self::snapshot($tenant, $from, $id);
        if (!$s || !(int)$s['is_discount'] || $s['status'] === 'closed') return;
        $claim = Db::name('tenant_package_discount_claim')->where(self::scope($tenant, (int)$s['user_id'], $from, (int)$s['package_id']))->lock(true)->find();
        if (!$claim || (int)$claim['order_id'] !== $id || !in_array($claim['status'], ['reserved', 'used'], true)) throw new \RuntimeException('订单优惠资格需要核对');
        if ($claim['status'] === 'used') return;
        Db::name('tenant_package_discount_claim')->where('id', $claim['id'])->update(['status'=>'used','consumed_at'=>time(),'reserved_until'=>0,'update_time'=>time()]);
    }
    public static function consume(int $tenant,string $from,int $id): void
    {
        $s=Db::name('tenant_package_price_snapshot')->where(['tenant_id'=>$tenant,'biz_type'=>$from,'order_id'=>$id])->lock(true)->find();if(!$s||$s['status']==='paid')return;
        if($s['status']!=='pending')throw new \RuntimeException('订单已关闭，到账款项需要核对处理');
        if((int)$s['is_discount']){
            $claim=Db::name('tenant_package_discount_claim')->where(self::scope($tenant,(int)$s['user_id'],$from,(int)$s['package_id']))->lock(true)->find();
            if(!$claim||!in_array($claim['status'],['reserved','used'],true)||(int)$claim['order_id']!==$id)throw new \RuntimeException('订单优惠资格需要核对');
            if ($claim['status']==='reserved') Db::name('tenant_package_discount_claim')->where('id',$claim['id'])->update(['status'=>'used','consumed_at'=>time(),'reserved_until'=>0,'update_time'=>time()]);
        }
        Db::name('tenant_package_price_snapshot')->where('id',$s['id'])->update(['status'=>'paid','paid_at'=>time()]);
    }
    /** Only domain adapters may pass a confirmed terminal channel state; never trust a client flag. */
    public static function closeConfirmed(int $tenant,int $user,string $from,int $id,string $channelState): void
    {
        if(!in_array($channelState,['never_started','closed'],true))throw new \RuntimeException('支付结果仍需核对，暂不能释放优惠资格');
        Db::transaction(function()use($tenant,$user,$from,$id,$channelState){
            $model=$from==='recharge'?RechargeOrder::withoutGlobalScope()->where(['tenant_id'=>$tenant,'user_id'=>$user,'id'=>$id]):MembershipOrder::withoutGlobalScope()->where(['tenant_id'=>$tenant,'user_id'=>$user,'id'=>$id]);$order=$model->lock(true)->findOrEmpty();
            $s=Db::name('tenant_package_price_snapshot')->where(['tenant_id'=>$tenant,'user_id'=>$user,'biz_type'=>$from,'order_id'=>$id])->lock(true)->find();if(!$s)throw new \RuntimeException('订单不存在');if($s['status']==='closed')return;if($s['status']!=='pending')throw new \RuntimeException('已付款订单不能取消');
            if($order->isEmpty()||(int)$order['pay_status'])throw new \RuntimeException('订单状态已变化，请刷新');
            if($channelState==='never_started'&&((int)$order['pay_way']>0||trim((string)$order['pay_sn'])!==''||(int)$s['channel']>0))throw new \RuntimeException('支付已发起，需先查询渠道结果');
            Db::name('tenant_package_price_snapshot')->where('id',$s['id'])->update(['status'=>'closed','closed_at'=>time()]);
            Db::name('tenant_package_discount_claim')->where(self::scope($tenant,$user,$from,(int)$s['package_id']))->where(['status'=>'reserved','order_id'=>$id])->update(['status'=>'available','order_id'=>0,'reserved_until'=>0,'update_time'=>time()]);
        });
    }
}
