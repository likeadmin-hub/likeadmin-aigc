<?php

namespace app\common\service\membership;

use app\common\enum\PayEnum;
use app\common\enum\user\AccountLogEnum;
use app\common\logic\AccountLogLogic;
use app\common\model\app\App;
use app\common\model\app\TenantApp;
use app\common\model\membership\MembershipOrder;
use app\common\model\membership\MembershipPlan;
use app\common\model\membership\MembershipPlanApp;
use app\common\model\membership\UserMembership;
use app\common\model\user\User;
use RuntimeException;
use think\facade\Db;

class MembershipService
{
    public const EXCLUDED_PLAN_APP_CODES = ['system_default'];
    public const STATUS_ENABLED = 1;
    public const STATUS_DISABLED = 0;
    public const CYCLE_PACKAGE = 'package';
    public const CYCLE_MONTHLY = 'monthly';
    public const CYCLE_YEARLY = 'yearly';
    public const ORDER_UNPAID = 0;
    public const ORDER_PAID = 1;
    public const MEMBER_ACTIVE = 'active';
    public const MEMBER_EXPIRED = 'expired';
    public const MEMBER_NONE = 'none';

    public static function plans(int $tenantId, bool $onlyEnabled = true): array
    {
        $query = MembershipPlan::where('tenant_id', $tenantId)->order(['sort' => 'desc', 'id' => 'asc']);
        if ($onlyEnabled) {
            $query->where('status', self::STATUS_ENABLED);
        }
        $plans = $query->select()->toArray();
        return self::attachPlanApps($plans);
    }

    public static function detail(int $tenantId, int $planId): array
    {
        $plan = MembershipPlan::where(['tenant_id' => $tenantId, 'id' => $planId])->findOrEmpty();
        if ($plan->isEmpty()) {
            return [];
        }
        $plans = self::attachPlanApps([$plan->toArray()]);
        return $plans[0] ?? [];
    }

    public static function savePlan(int $tenantId, array $params): MembershipPlan
    {
        $name = trim((string)($params['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('请输入套餐名称');
        }
        $features = $params['features'] ?? [];
        if (is_string($features)) {
            $features = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $features))));
        }
        if (!is_array($features)) {
            $features = [];
        }
        $durationMonths = max(1, (int)($params['duration_months'] ?? 1));
        $monthlyPrice = (float)($params['monthly_price'] ?? $params['price'] ?? 0);
        $yearlyPrice = (float)($params['yearly_price'] ?? $monthlyPrice);
        if ($monthlyPrice < 0 || $yearlyPrice < 0) {
            throw new RuntimeException('套餐价格不能小于0');
        }

        $data = [
            'tenant_id' => $tenantId,
            'name' => $name,
            'description' => trim((string)($params['description'] ?? '')),
            'duration_months' => $durationMonths,
            'monthly_price' => self::formatAmount($monthlyPrice),
            'yearly_price' => self::formatAmount($yearlyPrice),
            'monthly_market_price' => self::formatAmount((float)($params['monthly_market_price'] ?? $params['market_price'] ?? 0)),
            'yearly_market_price' => self::formatAmount((float)($params['yearly_market_price'] ?? $params['monthly_market_price'] ?? $params['market_price'] ?? 0)),
            'monthly_bonus_points' => self::formatAmount((float)($params['monthly_bonus_points'] ?? $params['bonus_points'] ?? 0)),
            'yearly_bonus_points' => self::formatAmount((float)($params['yearly_bonus_points'] ?? $params['monthly_bonus_points'] ?? $params['bonus_points'] ?? 0)),
            'features' => array_values($features),
            'is_recommend' => (int)($params['is_recommend'] ?? 0) ? 1 : 0,
            'status' => (int)($params['status'] ?? 1) ? self::STATUS_ENABLED : self::STATUS_DISABLED,
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
        ];
        $appCodes = array_values(array_filter(array_unique(array_map('strval', (array)($params['app_codes'] ?? [])))));
        self::assertTenantApps($tenantId, $appCodes);

        return Db::transaction(function () use ($tenantId, $params, $data, $appCodes) {
            $id = (int)($params['id'] ?? 0);
            if ($id > 0) {
                $plan = MembershipPlan::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
                if ($plan->isEmpty()) {
                    throw new RuntimeException('套餐不存在');
                }
                $plan->save($data);
            } else {
                $data['create_time'] = time();
                $plan = MembershipPlan::create($data);
            }
            self::syncPlanApps($tenantId, (int)$plan['id'], $appCodes);
            return $plan;
        });
    }

    public static function deletePlan(int $tenantId, int $planId): void
    {
        $plan = MembershipPlan::where(['tenant_id' => $tenantId, 'id' => $planId])->findOrEmpty();
        if ($plan->isEmpty()) {
            throw new RuntimeException('套餐不存在');
        }
        $used = MembershipOrder::where(['tenant_id' => $tenantId, 'plan_id' => $planId])->count();
        if ($used > 0) {
            $plan->save(['status' => self::STATUS_DISABLED, 'update_time' => time()]);
            return;
        }
        MembershipPlanApp::where(['tenant_id' => $tenantId, 'plan_id' => $planId])->delete();
        $plan->delete();
    }

    public static function tenantOpenedApps(int $tenantId): array
    {
        $rows = TenantApp::alias('ta')
            ->join('app a', 'a.code = ta.app_code')
            ->where('ta.tenant_id', $tenantId)
            ->where('a.status', 'installed')
            ->field('ta.app_code,a.name,a.icon,a.description,ta.shelf_status,ta.enable_status,ta.expire_time')
            ->order('a.sort', 'desc')
            ->select()
            ->toArray();

        return array_values(array_filter($rows, function ($row) {
            return !in_array((string)($row['app_code'] ?? ''), self::EXCLUDED_PLAN_APP_CODES, true);
        }));
    }

    public static function createOrder(int $tenantId, int $userId, int $terminal, int $planId, string $cycle): array
    {
        if ($cycle === '') {
            $cycle = self::CYCLE_PACKAGE;
        }
        if (!in_array($cycle, [self::CYCLE_PACKAGE, self::CYCLE_MONTHLY, self::CYCLE_YEARLY], true)) {
            throw new RuntimeException('购买周期不正确');
        }
        $plan = MembershipPlan::where(['tenant_id' => $tenantId, 'id' => $planId, 'status' => self::STATUS_ENABLED])->findOrEmpty();
        if ($plan->isEmpty()) {
            throw new RuntimeException('会员套餐不存在或已下架');
        }
        $durationMonths = (int)($plan['duration_months'] ?? 0);
        if ($durationMonths > 0) {
            $cycle = self::CYCLE_PACKAGE;
            $amount = (float)$plan['monthly_price'];
            $bonus = (float)$plan['monthly_bonus_points'];
        } else {
            $amount = (float)($cycle === self::CYCLE_YEARLY ? $plan['yearly_price'] : $plan['monthly_price']);
            $bonus = (float)($cycle === self::CYCLE_YEARLY ? $plan['yearly_bonus_points'] : $plan['monthly_bonus_points']);
            $durationMonths = $cycle === self::CYCLE_YEARLY ? 12 : 1;
        }
        $current = self::currentMembership($tenantId, $userId);
        $beforeExpireTime = (int)($current['expire_time'] ?? 0);
        $afterExpireTime = self::calcExpire($beforeExpireTime, $durationMonths);
        $orderSn = generate_sn(MembershipOrder::class, 'order_sn');

        $order = MembershipOrder::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'order_sn' => $orderSn,
            'order_terminal' => $terminal,
            'plan_id' => (int)$plan['id'],
            'plan_name' => (string)$plan['name'],
            'cycle' => $cycle,
            'duration_months' => $durationMonths,
            'order_amount' => self::formatAmount($amount),
            'bonus_points' => self::formatAmount($bonus),
            'before_expire_time' => $beforeExpireTime,
            'after_expire_time' => $afterExpireTime,
            'pay_status' => PayEnum::UNPAID,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        return [
            'order_id' => (int)$order['id'],
            'order_sn' => $orderSn,
            'from' => 'membership',
        ];
    }

    public static function handlePaid(string $orderSn, array $extra = []): void
    {
        Db::transaction(function () use ($orderSn, $extra) {
            $order = MembershipOrder::where('order_sn', $orderSn)->lock(true)->findOrEmpty();
            if ($order->isEmpty()) {
                throw new RuntimeException('会员订单不存在');
            }
            if ((int)$order['pay_status'] === PayEnum::ISPAID) {
                return;
            }
            $plan = MembershipPlan::where(['tenant_id' => (int)$order['tenant_id'], 'id' => (int)$order['plan_id']])->findOrEmpty();
            if ($plan->isEmpty()) {
                throw new RuntimeException('会员套餐不存在');
            }
            $appCodes = MembershipPlanApp::where([
                'tenant_id' => (int)$order['tenant_id'],
                'plan_id' => (int)$order['plan_id'],
            ])->column('app_code');
            $current = UserMembership::where([
                'tenant_id' => (int)$order['tenant_id'],
                'user_id' => (int)$order['user_id'],
            ])->lock(true)->findOrEmpty();
            $beforeExpireTime = $current->isEmpty() ? 0 : (int)$current['expire_time'];
            $afterExpireTime = self::calcExpire($beforeExpireTime, (int)$order['duration_months']);

            $memberData = [
                'tenant_id' => (int)$order['tenant_id'],
                'user_id' => (int)$order['user_id'],
                'plan_id' => (int)$order['plan_id'],
                'plan_name' => (string)$order['plan_name'],
                'app_codes' => array_values($appCodes),
                'features' => (array)($plan['features'] ?? []),
                'start_time' => time(),
                'expire_time' => $afterExpireTime,
                'status' => self::STATUS_ENABLED,
                'source_order_sn' => $orderSn,
                'update_time' => time(),
            ];
            if ($current->isEmpty()) {
                $memberData['create_time'] = time();
                UserMembership::create($memberData);
            } else {
                $current->save($memberData);
            }

            $bonusPoints = (float)$order['bonus_points'];
            if ($bonusPoints > 0) {
                $user = User::where('id', (int)$order['user_id'])->lock(true)->findOrEmpty();
                if (!$user->isEmpty()) {
                    $user->user_money = self::formatAmount((float)$user['user_money'] + $bonusPoints);
                    $user->save();
                    AccountLogLogic::add(
                        (int)$order['user_id'],
                        AccountLogEnum::UM_INC_MEMBERSHIP_BONUS,
                        AccountLogEnum::INC,
                        $bonusPoints,
                        $orderSn,
                        '会员套餐赠送' . \app\common\service\PointUnitService::unit(),
                        ['plan_id' => (int)$order['plan_id'], 'plan_name' => (string)$order['plan_name']]
                    );
                }
            }

            $order->save([
                'before_expire_time' => $beforeExpireTime,
                'after_expire_time' => $afterExpireTime,
                'transaction_id' => (string)($extra['transaction_id'] ?? ''),
                'pay_status' => PayEnum::ISPAID,
                'pay_time' => time(),
                'update_time' => time(),
            ]);
        });
    }

    public static function status(int $tenantId, int $userId): array
    {
        $current = self::currentMembership($tenantId, $userId);
        if (empty($current)) {
            return [
                'member_status' => self::MEMBER_NONE,
                'member_plan_id' => 0,
                'membership_plan' => '',
                'member_expire_time' => 0,
                'member_apps' => [],
            ];
        }
        $isActive = (int)$current['expire_time'] > time() && (int)$current['status'] === self::STATUS_ENABLED;
        return [
            'member_status' => $isActive ? self::MEMBER_ACTIVE : self::MEMBER_EXPIRED,
            'member_plan_id' => (int)$current['plan_id'],
            'membership_plan' => (string)$current['plan_name'],
            'member_expire_time' => (int)$current['expire_time'],
            'member_apps' => $isActive ? (array)($current['app_codes'] ?? []) : [],
        ];
    }

    public static function appAccess(int $tenantId, int $userId, string $appCode): array
    {
        $needMembership = self::appRequiresMembership($tenantId, $appCode);
        $status = $userId > 0 ? self::status($tenantId, $userId) : self::status($tenantId, 0);
        $allowed = !$needMembership || in_array($appCode, (array)$status['member_apps'], true);
        return [
            'app_code' => $appCode,
            'need_membership' => $needMembership ? 1 : 0,
            'allowed' => $allowed ? 1 : 0,
            'member_status' => $status['member_status'],
        ];
    }

    public static function appRequiresMembership(int $tenantId, string $appCode): bool
    {
        if ($tenantId <= 0 || $appCode === '') {
            return false;
        }
        return MembershipPlanApp::alias('mpa')
            ->join('membership_plan mp', 'mp.id = mpa.plan_id')
            ->where('mpa.tenant_id', $tenantId)
            ->where('mpa.app_code', $appCode)
            ->where('mp.status', self::STATUS_ENABLED)
            ->count() > 0;
    }

    public static function userCanUseApp(int $tenantId, int $userId, string $appCode): bool
    {
        if (!self::appRequiresMembership($tenantId, $appCode)) {
            return true;
        }
        if ($userId <= 0) {
            return false;
        }
        $status = self::status($tenantId, $userId);
        return in_array($appCode, (array)$status['member_apps'], true);
    }

    public static function formatOrder(array $order): array
    {
        $order['pay_time'] = empty($order['pay_time']) ? '' : date('Y-m-d H:i:s', (int)$order['pay_time']);
        $order['before_expire_time_text'] = empty($order['before_expire_time']) ? '无' : date('Y-m-d H:i:s', (int)$order['before_expire_time']);
        $order['after_expire_time_text'] = empty($order['after_expire_time']) ? '无' : date('Y-m-d H:i:s', (int)$order['after_expire_time']);
        if (($order['cycle'] ?? '') === self::CYCLE_PACKAGE) {
            $order['cycle_text'] = (int)($order['duration_months'] ?? 0) > 0 ? (int)$order['duration_months'] . '个月' : '套餐购买';
        } else {
            $order['cycle_text'] = ($order['cycle'] ?? '') === self::CYCLE_YEARLY ? '按年购买' : '按月购买';
        }
        return $order;
    }

    private static function attachPlanApps(array $plans): array
    {
        if (empty($plans)) {
            return [];
        }
        $planIds = array_column($plans, 'id');
        $apps = MembershipPlanApp::whereIn('plan_id', $planIds)->select()->toArray();
        $appNames = App::whereIn('code', array_column($apps, 'app_code') ?: [''])->column('name', 'code');
        $grouped = [];
        foreach ($apps as $app) {
            $grouped[$app['plan_id']][] = [
                'app_code' => $app['app_code'],
                'name' => $appNames[$app['app_code']] ?? $app['app_code'],
            ];
        }
        foreach ($plans as &$plan) {
            $plan['apps'] = $grouped[$plan['id']] ?? [];
            $plan['app_codes'] = array_column($plan['apps'], 'app_code');
        }
        return $plans;
    }

    private static function syncPlanApps(int $tenantId, int $planId, array $appCodes): void
    {
        MembershipPlanApp::where(['tenant_id' => $tenantId, 'plan_id' => $planId])->delete();
        foreach ($appCodes as $appCode) {
            MembershipPlanApp::create([
                'tenant_id' => $tenantId,
                'plan_id' => $planId,
                'app_code' => $appCode,
                'create_time' => time(),
            ]);
        }
    }

    private static function assertTenantApps(int $tenantId, array $appCodes): void
    {
        if (empty($appCodes)) {
            return;
        }
        $opened = array_column(self::tenantOpenedApps($tenantId), 'app_code');
        foreach ($appCodes as $appCode) {
            if (!in_array($appCode, $opened, true)) {
                throw new RuntimeException('只能关联当前租户已开通的应用');
            }
        }
    }

    private static function currentMembership(int $tenantId, int $userId): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            return [];
        }
        $row = UserMembership::where(['tenant_id' => $tenantId, 'user_id' => $userId])
            ->order('id', 'desc')
            ->findOrEmpty();
        return $row->isEmpty() ? [] : $row->toArray();
    }

    private static function calcExpire(int $beforeExpireTime, int $durationMonths): int
    {
        $baseTime = max($beforeExpireTime, time());
        $afterExpireTime = strtotime('+' . $durationMonths . ' months', $baseTime);
        if (!$afterExpireTime) {
            throw new RuntimeException('会员有效期计算失败');
        }
        return $afterExpireTime;
    }

    private static function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
