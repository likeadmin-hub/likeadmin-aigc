<?php

namespace app\common\service\app;

use app\common\model\app\App;
use app\common\model\app\AppPlan;
use app\common\model\app\TenantApp;
use app\common\model\app\TenantAppOrder;
use app\common\service\point\TenantPointService;
use RuntimeException;
use think\facade\Db;

class AppPlanService
{
    public const EXPIRE_BLOCK = 'block';
    public const EXPIRE_ALLOW = 'allow';
    public const PLAN_ENABLED = 1;
    public const PLAN_DISABLED = 0;
    public const ORDER_OPEN = 'open';
    public const ORDER_RENEW = 'renew';
    public const PAY_PAID = 1;

    public static function plans(string $appCode, bool $onlyEnabled = false): array
    {
        if (self::isBuiltinOrDefaultApp($appCode)) {
            return [];
        }
        $query = AppPlan::where('app_code', $appCode)->order(['sort' => 'desc', 'id' => 'asc']);
        if ($onlyEnabled) {
            $query->where('status', self::PLAN_ENABLED);
        }
        return $query->select()->toArray();
    }

    public static function savePlan(array $params): AppPlan
    {
        $appCode = trim((string)($params['app_code'] ?? ''));
        self::assertEditableApp($appCode);

        $name = trim((string)($params['name'] ?? ''));
        $durationMonths = (int)($params['duration_months'] ?? 0);
        $openPoints = (float)($params['open_points'] ?? 0);
        $renewPoints = (float)($params['renew_points'] ?? 0);
        if ($name === '') {
            throw new RuntimeException('请输入套餐名称');
        }
        if ($durationMonths <= 0) {
            throw new RuntimeException('套餐时长必须大于0个月');
        }
        if ($openPoints < 0 || $renewPoints < 0) {
            throw new RuntimeException('开通点数和续费点数不能小于0');
        }

        $data = [
            'app_code' => $appCode,
            'name' => $name,
            'duration_months' => $durationMonths,
            'open_points' => self::formatPoints($openPoints),
            'renew_points' => self::formatPoints($renewPoints),
            'status' => (int)($params['status'] ?? self::PLAN_ENABLED) === self::PLAN_ENABLED ? self::PLAN_ENABLED : self::PLAN_DISABLED,
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
        ];

        $id = (int)($params['id'] ?? 0);
        if ($id > 0) {
            $plan = AppPlan::where(['id' => $id, 'app_code' => $appCode])->findOrEmpty();
            if ($plan->isEmpty()) {
                throw new RuntimeException('套餐不存在');
            }
            $plan->save($data);
            return $plan;
        }

        $data['create_time'] = time();
        return AppPlan::create($data);
    }

    public static function deletePlan(string $appCode, int $planId): void
    {
        self::assertEditableApp($appCode);
        $plan = AppPlan::where(['id' => $planId, 'app_code' => $appCode])->findOrEmpty();
        if ($plan->isEmpty()) {
            throw new RuntimeException('套餐不存在');
        }
        $used = TenantAppOrder::where('plan_id', $planId)->count();
        if ($used > 0) {
            $plan->save([
                'status' => self::PLAN_DISABLED,
                'update_time' => time(),
            ]);
            return;
        }
        $plan->delete();
    }

    public static function saveExpirePolicy(string $appCode, string $policy): void
    {
        $app = self::assertEditableApp($appCode);
        if (!in_array($policy, [self::EXPIRE_BLOCK, self::EXPIRE_ALLOW], true)) {
            throw new RuntimeException('过期策略不正确');
        }
        $app->expire_policy = $policy;
        $app->update_time = time();
        $app->save();
    }

    public static function enrichApp(array $app, array $tenantApp = [], bool $includePlans = true): array
    {
        $appCode = (string)($app['code'] ?? $app['app_code'] ?? '');
        $isBuiltin = DefaultAppService::isDefaultApp($appCode);
        $expireTime = (int)($tenantApp['expire_time'] ?? 0);
        $isExpired = !$isBuiltin && $expireTime > 0 && $expireTime <= time();
        $policy = (string)($app['expire_policy'] ?? self::EXPIRE_BLOCK);

        $app['is_builtin'] = $isBuiltin ? 1 : 0;
        $app['expire_policy'] = $isBuiltin ? self::EXPIRE_ALLOW : (in_array($policy, [self::EXPIRE_BLOCK, self::EXPIRE_ALLOW], true) ? $policy : self::EXPIRE_BLOCK);
        $app['expire_time'] = $expireTime;
        $app['is_expired'] = $isExpired ? 1 : 0;
        $app['can_renew'] = (!$isBuiltin && !empty($tenantApp)) ? 1 : 0;
        if ($includePlans) {
            $app['plans'] = $isBuiltin ? [] : self::plans($appCode, true);
        }
        return $app;
    }

    public static function openOrRenew(int $tenantId, int $operatorId, string $appCode, int $planId): array
    {
        if ($appCode === 'system_default') {
            throw new RuntimeException('系统应用无需开通');
        }
        $app = App::where(['code' => $appCode, 'status' => AppRegistryService::STATUS_INSTALLED])->findOrEmpty();
        if ($app->isEmpty()) {
            throw new RuntimeException('应用不存在或未安装');
        }
        if (DefaultAppService::isDefaultApp($appCode)) {
            throw new RuntimeException('内置应用无需购买套餐');
        }

        $plan = AppPlan::where(['id' => $planId, 'app_code' => $appCode, 'status' => self::PLAN_ENABLED])->findOrEmpty();
        if ($plan->isEmpty()) {
            throw new RuntimeException('套餐不存在或已禁用');
        }

        return Db::transaction(function () use ($tenantId, $operatorId, $appCode, $app, $plan) {
            $tenantApp = TenantApp::where(['tenant_id' => $tenantId, 'app_code' => $appCode])->lock(true)->findOrEmpty();
            $hasPaidOrder = TenantAppOrder::where([
                'tenant_id' => $tenantId,
                'app_code' => $appCode,
                'pay_status' => self::PAY_PAID,
            ])->count() > 0;
            $isRenew = !$tenantApp->isEmpty() && ((int)$tenantApp['expire_time'] > 0 || $hasPaidOrder);
            $orderType = $isRenew ? self::ORDER_RENEW : self::ORDER_OPEN;
            $points = (float)($isRenew ? $plan['renew_points'] : $plan['open_points']);
            $beforeExpireTime = $isRenew ? (int)$tenantApp['expire_time'] : 0;
            $baseTime = max($beforeExpireTime, time());
            $afterExpireTime = strtotime('+' . (int)$plan['duration_months'] . ' months', $baseTime);
            if (!$afterExpireTime) {
                throw new RuntimeException('套餐时长计算失败');
            }

            $orderSn = generate_sn(TenantAppOrder::class, 'order_sn', 'AO', 6);
            $remark = $orderType === self::ORDER_OPEN ? '应用开通' : '应用续签';
            if ($points > 0) {
                TenantPointService::consume($tenantId, $points, $orderSn, $remark, [
                    'app_code' => $appCode,
                    'plan_id' => (int)$plan['id'],
                    'plan_name' => (string)$plan['name'],
                    'order_type' => $orderType,
                    'operator_type' => 'tenant_admin',
                    'operator_id' => $operatorId,
                ]);
            }

            $tenantAppData = [
                'tenant_id' => $tenantId,
                'app_code' => $appCode,
                'version' => (string)$app['current_version'],
                'buy_status' => AppAccessService::BUY_PAID,
                'shelf_status' => $isRenew ? (string)$tenantApp['shelf_status'] : AppAccessService::SHELF_ON,
                'enable_status' => AppAccessService::ENABLED,
                'expire_time' => $afterExpireTime,
                'update_time' => time(),
            ];
            if ($tenantApp->isEmpty()) {
                $tenantAppData['create_time'] = time();
                TenantApp::create($tenantAppData);
            } else {
                $tenantApp->save($tenantAppData);
            }

            TenantAppOrder::create([
                'tenant_id' => $tenantId,
                'app_code' => $appCode,
                'order_sn' => $orderSn,
                'plan_id' => (int)$plan['id'],
                'plan_name' => (string)$plan['name'],
                'duration_months' => (int)$plan['duration_months'],
                'order_type' => $orderType,
                'amount' => self::formatPoints($points),
                'points_amount' => self::formatPoints($points),
                'before_expire_time' => $beforeExpireTime,
                'after_expire_time' => $afterExpireTime,
                'operator_id' => $operatorId,
                'pay_status' => self::PAY_PAID,
                'pay_time' => time(),
                'create_time' => time(),
            ]);

            AppMenuService::syncTenantMenus($tenantId, $appCode);

            return [
                'order_sn' => $orderSn,
                'order_type' => $orderType,
                'points_amount' => self::formatPoints($points),
                'before_expire_time' => $beforeExpireTime,
                'after_expire_time' => $afterExpireTime,
            ];
        });
    }

    private static function assertEditableApp(string $appCode): App
    {
        if ($appCode === '') {
            throw new RuntimeException('请选择应用');
        }
        $app = App::where('code', $appCode)->findOrEmpty();
        if ($app->isEmpty()) {
            throw new RuntimeException('应用不存在');
        }
        if ($appCode === 'system_default' || DefaultAppService::isDefaultApp($appCode)) {
            throw new RuntimeException('内置应用不支持套餐设置');
        }
        return $app;
    }

    private static function isBuiltinOrDefaultApp(string $appCode): bool
    {
        return $appCode === '' || $appCode === 'system_default' || DefaultAppService::isDefaultApp($appCode);
    }

    private static function formatPoints(float $points): string
    {
        return number_format($points, 2, '.', '');
    }
}
