<?php
declare(strict_types=1);
namespace app\common\service\brand;

use app\common\model\tenant\Tenant;
use app\common\service\brand\BrandUserException as RuntimeException;
use think\facade\Db;

final class BrandCatalogService
{
    public static function flowEnabled(int $tenantId): bool
    {
        // 0 = legacy, 1 = V2 accepting applications, 2 = V2 entry paused.
        // A paused V2 seller must never fall back to legacy automatic provisioning.
        return (int)Tenant::withoutGlobalScope()->where('id', $tenantId)->value('oem_flow_enabled', 0) >= 1;
    }

    /** Entry gate only. Processing paid orders must never depend on this flag. */
    public static function assertSeller(int $tenantId): void
    {
        $tenant = Tenant::withoutGlobalScope()->where('id', $tenantId)->findOrEmpty();
        if ($tenant->isEmpty() || (int)$tenant['disable'] !== 0 || (int)$tenant['oem_sales_enabled'] !== 1 || (int)$tenant['oem_flow_enabled'] !== 1) {
            throw new RuntimeException('该服务商暂未开放贴牌申请，请联系服务商');
        }
    }

    public static function assertLegacySeller(int $tenantId): void
    {
        $tenant=Tenant::withoutGlobalScope()->where('id',$tenantId)->findOrEmpty();
        if ($tenant->isEmpty() || (int)$tenant['disable']!==0 || (int)$tenant['oem_sales_enabled']!==1) throw new RuntimeException('该租户尚未获得贴牌销售资格，请联系平台');
    }

    public static function tenantTable(string $base,int $tenantId): string
    {
        $row=Db::name('tenant')->where('id',$tenantId)->whereNull('delete_time')->find();
        if (!$row) throw new RuntimeException('租户资料不存在，请联系平台核对');
        if ((int)$row['tactics']!==1) return $base;
        if (!preg_match('/^[A-Za-z0-9_]+$/D',(string)$row['sn'])) throw new RuntimeException('租户数据配置异常');
        return $base.'_'.$row['sn'];
    }

    public static function snapshot(int $packageId): array
    {
        $package = Db::name('tenant_package')->where('id', $packageId)->whereNull('delete_time')->find();
        if (!$package || (int)$package['status'] !== 1 || (int)$package['prices_configured'] !== 1) {
            throw new RuntimeException('套餐尚未完成价格与权益配置，请联系服务商');
        }
        $sale = BrandOrderPolicy::cents((string)$package['sale_price']);
        $quota = BrandOrderPolicy::cents((string)$package['quota_price']);
        if ($sale <= 0 || $quota <= 0 || (int)$package['duration_days'] <= 0) throw new RuntimeException('套餐价格或期限尚未配置');
        $plans = Db::name('tenant_package_app_plan')->alias('b')
            ->join('app_plan p', 'p.id=b.app_plan_id AND p.app_code=b.app_code')
            ->join('app a', 'a.code=b.app_code')
            ->where('b.package_id', $packageId)->field('p.*,a.name as app_name,a.status as app_status')->order('b.id')->select()->toArray();
        $bindingCount = Db::name('tenant_package_app_plan')->where('package_id', $packageId)->count();
        if (!$plans || count($plans) !== $bindingCount) throw new RuntimeException('套餐应用权益尚未完整配置');
        $seen = [];
        foreach ($plans as &$plan) {
            if ((int)$plan['status'] !== 1 || $plan['app_status'] !== 'installed' || (int)$plan['duration_months'] <= 0) throw new RuntimeException('套餐中有不可用的应用权益，请联系服务商');
            if (isset($seen[$plan['app_code']])) throw new RuntimeException('同一应用只能配置一份权益');
            $seen[$plan['app_code']] = true;
            $plan['duration_months'] = (int)$plan['duration_months'];
            $plan['id'] = (int)$plan['id'];
            unset($plan['app_status']);
        }
        unset($plan);
        return [
            'id'=>(int)$package['id'], 'name'=>(string)$package['name'], 'package_code'=>$package['package_code'],
            'package_version'=>(int)$package['package_version'], 'duration_days'=>(int)$package['duration_days'],
            'sale_price'=>BrandOrderPolicy::amount($sale), 'quota_price'=>BrandOrderPolicy::amount($quota),
            'description'=>(string)$package['description'], 'app_plans'=>$plans,
        ];
    }

    public static function publicPackage(array $snapshot, string $salePrice): array
    {
        return [
            'id'=>(int)$snapshot['id'], 'name'=>$snapshot['name'], 'description'=>$snapshot['description'],
            'duration_days'=>(int)$snapshot['duration_days'], 'duration_desc'=>$snapshot['duration_days'].'天',
            'tenant_sale_price'=>BrandOrderPolicy::amount(BrandOrderPolicy::cents($salePrice)),
            'package_version'=>(int)$snapshot['package_version'],
            'app_plans'=>array_map(fn($plan)=>[
                'app_code'=>$plan['app_code'],'app_name'=>$plan['app_name'],'app_plan_name'=>$plan['name'],
                'duration_months'=>(int)$plan['duration_months'],
            ],$snapshot['app_plans']),
        ];
    }
}
