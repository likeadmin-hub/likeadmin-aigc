<?php

namespace app\common\service\tenant;

use app\common\model\app\App;
use app\common\model\app\AppPlan;
use app\common\model\brand\TenantBrandOrder;
use app\common\model\brand\TenantBrandQuotaOrder;
use app\common\model\tenant\TenantPackage;
use app\common\model\tenant\TenantPackageAppPlan;
use app\common\service\app\AppPlanService;
use app\common\service\app\AppRegistryService;
use app\common\service\brand\BrandOrderPolicy;
use RuntimeException;
use think\facade\Db;

class TenantPackageService
{
    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;

    public static function detail(int $id): array
    {
        $package = TenantPackage::findOrEmpty($id);
        if ($package->isEmpty()) {
            return [];
        }
        return self::formatPackage(self::attachAppPlans($package->toArray()));
    }

    public static function enabledPackages(): array
    {
        $rows = TenantPackage::where('status', self::STATUS_ENABLED)
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();
        return array_map([self::class, 'formatPackage'], self::attachAppPlans($rows));
    }

    public static function savePackage(array $params): TenantPackage
    {
        $name = trim((string)($params['name'] ?? ''));
        $durationDays = (int)($params['duration_days'] ?? 0);
        $salePrice = BrandOrderPolicy::cents((string)($params['sale_price'] ?? $params['price'] ?? '0'));
        $quotaPrice = BrandOrderPolicy::cents((string)($params['quota_price'] ?? '0'));
        if ($name === '') {
            throw new RuntimeException('请输入套餐名称');
        }
        if ($durationDays <= 0) {
            throw new RuntimeException('有效期必须大于0天');
        }
        if ($salePrice < 0 || $quotaPrice < 0) {
            throw new RuntimeException('套餐价格不能小于0');
        }

        $data = [
            'name' => $name,
            'duration_days' => $durationDays,
            'sale_price' => BrandOrderPolicy::amount($salePrice),
            'quota_price' => BrandOrderPolicy::amount($quotaPrice),
            'quota_unit' => trim((string)($params['quota_unit'] ?? '个')),
            'description' => trim((string)($params['description'] ?? '')),
            'sort' => (int)($params['sort'] ?? 0),
            'status' => (int)($params['status'] ?? 1) ? self::STATUS_ENABLED : self::STATUS_DISABLED,
            'update_time' => time(),
        ];
        $appPlans = self::normalizeAppPlans((array)($params['app_plans'] ?? []));
        $data['prices_configured'] = $salePrice > 0 && $quotaPrice > 0 && !empty($appPlans) ? 1 : 0;

        return Db::transaction(function () use ($params, $data, $appPlans) {
            $id = (int)($params['id'] ?? 0);
            if ($id > 0) {
                $package = TenantPackage::where('id', $id)->lock(true)->findOrEmpty();
                if ($package->isEmpty()) {
                    throw new RuntimeException('租户套餐不存在');
                }
                if (!empty($package['package_code']) && (int)$data['status'] === self::STATUS_ENABLED && !$data['prices_configured']) {
                    throw new RuntimeException('请先配置采购价、建议售价和应用权益，再上架套餐');
                }
                $oldPlans = TenantPackageAppPlan::where('package_id', $id)->field('app_code,app_plan_id')->order('app_code,app_plan_id')->select()->toArray();
                $nextPlans = $appPlans;
                usort($nextPlans, static fn($a,$b)=>strcmp($a['app_code'],$b['app_code']) ?: ($a['app_plan_id']<=>$b['app_plan_id']));
                $rightsChanged = (int)$package['duration_days'] !== (int)$data['duration_days'] || $oldPlans != $nextPlans;
                $used = TenantBrandQuotaOrder::where('package_id', $id)->count() + TenantBrandOrder::where('package_id', $id)->count();
                if ($rightsChanged && $used > 0) {
                    // Retain the original SKU for purchased inventory and in-flight legacy orders.
                    $data['package_version'] = (int)$package['package_version'] + 1;
                    $data['family_package_id'] = (int)$package['family_package_id'] ?: $id;
                    $data['create_time'] = time();
                    $package = TenantPackage::create($data);
                } else {
                    $package->save($data);
                }
            } else {
                $data['create_time'] = time();
                $package = TenantPackage::create($data);
            }
            self::syncAppPlans((int)$package['id'], $appPlans);
            return $package;
        });
    }

    public static function deletePackage(int $id): void
    {
        $package = TenantPackage::findOrEmpty($id);
        if ($package->isEmpty()) {
            throw new RuntimeException('租户套餐不存在');
        }
        $used = TenantBrandQuotaOrder::where('package_id', $id)->count() + TenantBrandOrder::where('package_id', $id)->count();
        if ($used > 0) {
            $package->save(['status' => self::STATUS_DISABLED, 'update_time' => time()]);
            return;
        }
        TenantPackageAppPlan::where('package_id', $id)->delete();
        $package->delete();
    }

    public static function appPlanOptions(): array
    {
        return AppPlan::alias('p')
            ->join('app a', 'a.code = p.app_code')
            ->where('a.status', AppRegistryService::STATUS_INSTALLED)
            ->where('p.status', AppPlanService::PLAN_ENABLED)
            ->field('p.id as plan_id,p.app_code,p.name as plan_name,p.duration_months,a.name as app_name,a.icon')
            ->order(['a.sort' => 'desc', 'p.sort' => 'desc', 'p.id' => 'asc'])
            ->select()
            ->toArray();
    }

    public static function grantBoundAppPlans(int $tenantId, int $packageId, int $operatorId = 0, string $sourceSn = ''): void
    {
        $plans = TenantPackageAppPlan::where('package_id', $packageId)->select()->toArray();
        foreach ($plans as $plan) {
            AppPlanService::grantWithoutCharge(
                $tenantId,
                $operatorId,
                (string)$plan['app_code'],
                (int)$plan['app_plan_id'],
                $sourceSn,
                '租户套餐绑定应用'
            );
        }
    }

    public static function attachAppPlans(array $rows): array
    {
        $single = isset($rows['id']);
        $list = $single ? [$rows] : $rows;
        $ids = array_values(array_filter(array_map(fn($row) => (int)($row['id'] ?? 0), $list)));
        if (empty($ids)) {
            return $rows;
        }
        $plans = TenantPackageAppPlan::alias('tp')
            ->leftJoin('app a', 'a.code = tp.app_code')
            ->leftJoin('app_plan p', 'p.id = tp.app_plan_id')
            ->whereIn('tp.package_id', $ids)
            ->field('tp.*,a.name as app_name,a.icon,p.name as app_plan_name,p.duration_months')
            ->select()
            ->toArray();
        $grouped = [];
        foreach ($plans as $plan) {
            $grouped[(int)$plan['package_id']][] = $plan;
        }
        foreach ($list as &$row) {
            $row['app_plans'] = $grouped[(int)$row['id']] ?? [];
        }
        return $single ? ($list[0] ?? []) : $list;
    }

    public static function formatPackage(array $row): array
    {
        $row['sale_price'] = self::formatAmount((float)($row['sale_price'] ?? 0));
        $row['quota_price'] = self::formatAmount((float)($row['quota_price'] ?? 0));
        $row['status_desc'] = (int)($row['status'] ?? 0) === self::STATUS_ENABLED ? '启用' : '停用';
        $row['duration_desc'] = ((int)($row['duration_days'] ?? 0)) . '天';
        return $row;
    }

    private static function syncAppPlans(int $packageId, array $appPlans): void
    {
        TenantPackageAppPlan::where('package_id', $packageId)->delete();
        foreach ($appPlans as $plan) {
            TenantPackageAppPlan::create([
                'package_id' => $packageId,
                'app_code' => (string)$plan['app_code'],
                'app_plan_id' => (int)$plan['app_plan_id'],
                'create_time' => time(),
            ]);
        }
    }

    private static function normalizeAppPlans(array $plans): array
    {
        $normalized = [];
        foreach ($plans as $plan) {
            $appCode = trim((string)($plan['app_code'] ?? ''));
            $planId = (int)($plan['app_plan_id'] ?? $plan['plan_id'] ?? 0);
            if ($appCode === '' || $planId <= 0) {
                continue;
            }
            $exists = AppPlan::where(['id' => $planId, 'app_code' => $appCode, 'status' => AppPlanService::PLAN_ENABLED])->findOrEmpty();
            if ($exists->isEmpty()) {
                throw new RuntimeException('绑定的应用套餐不存在或已禁用');
            }
            if (isset($normalized[$appCode])) throw new RuntimeException('同一应用只能配置一份权益');
            $normalized[$appCode] = [
                'app_code' => $appCode,
                'app_plan_id' => $planId,
            ];
        }
        return array_values($normalized);
    }

    private static function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}

