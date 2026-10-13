<?php
declare(strict_types=1);
namespace app\common\service\brand;
use app\common\service\brand\BrandUserException as RuntimeException;
use think\facade\Db;

/** Caller holds an order lock and transaction; lock order is order -> bucket. */
final class BrandQuotaService
{
    public static function reserve(array $order): bool
    {
        if ((int)$order['reserve_status']===TenantBrandService::RESERVE_ACTIVE) return true;
        if ((int)$order['reserve_status']!==TenantBrandService::RESERVE_NONE) throw new RuntimeException('订单额度状态需要核对');
        $bucket=self::bucket($order);
        if (!$bucket || (int)$bucket['remaining_quota']-(int)$bucket['reserved_quota']<1) return false;
        $available=(int)$bucket['remaining_quota']-(int)$bucket['reserved_quota'];
        Db::name('tenant_brand_quota_bucket')->where('id',$bucket['id'])->update(['reserved_quota'=>(int)$bucket['reserved_quota']+1,'update_time'=>time()]);
        Db::name('tenant_brand_order')->where('id',$order['id'])->update(['reserve_status'=>1,'reserve_time'=>time()]);
        self::log($order,'reserve',1,$available,$available-1,'预占贴牌额度');
        return true;
    }

    public static function release(array $order, string $reason): void
    {
        if ((int)$order['reserve_status']!==TenantBrandService::RESERVE_ACTIVE) return;
        $bucket=self::bucket($order);
        if (!$bucket || (int)$bucket['reserved_quota']<1) throw new RuntimeException('冻结额度需要人工核对');
        $available=(int)$bucket['remaining_quota']-(int)$bucket['reserved_quota'];
        Db::name('tenant_brand_quota_bucket')->where('id',$bucket['id'])->update(['reserved_quota'=>(int)$bucket['reserved_quota']-1,'update_time'=>time()]);
        Db::name('tenant_brand_order')->where('id',$order['id'])->update(['reserve_status'=>TenantBrandService::RESERVE_RELEASED]);
        self::log($order,'rollback',1,$available,$available+1,$reason);
    }

    public static function consume(array $order): void
    {
        if ((int)$order['reserve_status']===TenantBrandService::RESERVE_CONSUMED) return;
        $bucket=self::bucket($order);
        if ((int)$order['reserve_status']!==1 || !$bucket || (int)$bucket['reserved_quota']<1 || (int)$bucket['remaining_quota']<1) throw new RuntimeException('交付额度需要人工核对');
        Db::name('tenant_brand_quota_bucket')->where('id',$bucket['id'])->update(['reserved_quota'=>(int)$bucket['reserved_quota']-1,'remaining_quota'=>(int)$bucket['remaining_quota']-1,'used_quota'=>(int)$bucket['used_quota']+1,'update_time'=>time()]);
        Db::name('tenant_brand_order')->where('id',$order['id'])->update(['reserve_status'=>2]);
        self::log($order,'decrease',1,(int)$bucket['remaining_quota'],(int)$bucket['remaining_quota']-1,'交付成功消耗贴牌额度');
    }

    public static function increase(array $order): void
    {
        $quantity=(int)$order['quantity'];
        if ($quantity<1 || $quantity>10000) throw new RuntimeException('采购数量异常');
        $table=Db::name('tenant_brand_quota_bucket')->getTable();
        Db::execute('INSERT INTO `'.$table.'` (tenant_id,package_id,total_quota,remaining_quota,used_quota,reserved_quota,create_time,update_time) VALUES (?,?,0,0,0,0,?,?) ON DUPLICATE KEY UPDATE id=id',[$order['tenant_id'],$order['package_id'],time(),time()]);
        $bucket=self::bucket($order);
        Db::name('tenant_brand_quota_bucket')->where('id',$bucket['id'])->update(['total_quota'=>(int)$bucket['total_quota']+$quantity,'remaining_quota'=>(int)$bucket['remaining_quota']+$quantity,'update_time'=>time()]);
        self::log($order,'increase',$quantity,(int)$bucket['remaining_quota'],(int)$bucket['remaining_quota']+$quantity,'采购到账增加贴牌额度');
    }

    private static function bucket(array $order): ?array
    {
        return Db::name('tenant_brand_quota_bucket')->where(['tenant_id'=>$order['tenant_id'],'package_id'=>$order['package_id']])->lock(true)->find();
    }
    private static function log(array $order,string $type,int $quantity,int $before,int $after,string $remark): void
    {
        Db::name('tenant_brand_quota_log')->insert(['tenant_id'=>$order['tenant_id'],'package_id'=>$order['package_id'],'change_type'=>$type,'change_quota'=>$quantity,'before_quota'=>$before,'after_quota'=>$after,'source_sn'=>$order['order_sn'],'remark'=>$remark,'create_time'=>time()]);
    }
}
