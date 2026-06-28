<?php

namespace app\common\service\power;

use app\common\enum\PayEnum;
use app\common\model\power\TenantPointBucket;
use app\common\model\power\TenantPowerOrder;
use app\common\model\power\TenantPowerPackage;
use app\common\model\tenant\Tenant;
use app\common\model\tenant\TenantPointLog;
use RuntimeException;
use think\facade\Db;

class TenantPowerMallService
{
    public const FROM = 'tenant_power';
    public const PACKAGE_MEMBER = 'member';
    public const PACKAGE_POINTS = 'points';
    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;
    public const BUCKET_ACTIVE = 1;
    public const BUCKET_EXPIRED = 2;

    public static function packageTypes(): array
    {
        return [
            self::PACKAGE_MEMBER => '会员套餐',
            self::PACKAGE_POINTS => '点数套餐',
        ];
    }

    public static function packageDetail(int $id): array
    {
        $package = TenantPowerPackage::findOrEmpty($id);
        return $package->isEmpty() ? [] : self::formatPackage($package->toArray());
    }

    public static function savePackage(array $params): TenantPowerPackage
    {
        $type = (string)($params['type'] ?? self::PACKAGE_POINTS);
        if (!isset(self::packageTypes()[$type])) {
            throw new RuntimeException('套餐类型错误');
        }
        $name = trim((string)($params['name'] ?? ''));
        $amount = (float)($params['amount'] ?? 0);
        $points = (float)($params['points'] ?? 0);
        $durationMonths = (int)($params['duration_months'] ?? 0);
        if ($name === '') {
            throw new RuntimeException('请输入套餐名称');
        }
        if ($amount < 0) {
            throw new RuntimeException('购买金额不能小于0');
        }
        if ($points <= 0) {
            throw new RuntimeException('套餐点数必须大于0');
        }
        if ($type === self::PACKAGE_MEMBER && $durationMonths <= 0) {
            throw new RuntimeException('会员套餐月数必须大于0');
        }
        if ($type === self::PACKAGE_POINTS) {
            $durationMonths = 0;
        }

        $data = [
            'type' => $type,
            'name' => $name,
            'description' => trim((string)($params['description'] ?? '')),
            'duration_months' => $durationMonths,
            'amount' => self::formatAmount($amount),
            'points' => self::formatAmount($points),
            'status' => (int)($params['status'] ?? 1) ? self::STATUS_ENABLED : self::STATUS_DISABLED,
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
        ];

        return Db::transaction(function () use ($params, $data) {
            $id = (int)($params['id'] ?? 0);
            if ($id > 0) {
                $package = TenantPowerPackage::findOrEmpty($id);
                if ($package->isEmpty()) {
                    throw new RuntimeException('算力套餐不存在');
                }
                $package->save($data);
                return $package;
            }
            $data['create_time'] = time();
            return TenantPowerPackage::create($data);
        });
    }

    public static function deletePackage(int $id): void
    {
        $package = TenantPowerPackage::findOrEmpty($id);
        if ($package->isEmpty()) {
            throw new RuntimeException('算力套餐不存在');
        }
        $used = TenantPowerOrder::where('package_id', $id)->count();
        if ($used > 0) {
            $package->save(['status' => self::STATUS_DISABLED, 'update_time' => time()]);
            return;
        }
        $package->delete();
    }

    public static function enabledPackages(): array
    {
        $lists = TenantPowerPackage::where('status', self::STATUS_ENABLED)
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();
        return array_map([self::class, 'formatPackage'], $lists);
    }

    public static function stats(int $tenantId): array
    {
        self::expireBuckets($tenantId);
        $todayStart = strtotime('today');
        $balance = (float)Tenant::withoutGlobalScope()->where('id', $tenantId)->value('point_balance');
        $consumeQuery = TenantPointLog::where([
            'tenant_id' => $tenantId,
            'action' => 2,
            'change_type' => 'business_consume',
        ]);
        $rechargeQuery = TenantPointLog::where([
            'tenant_id' => $tenantId,
            'action' => 1,
            'change_type' => 'package_recharge',
        ]);
        $activeBucketQuery = TenantPointBucket::where([
            'tenant_id' => $tenantId,
            'status' => self::BUCKET_ACTIVE,
        ])->where('remaining_points', '>', 0);
        $memberPoints = (float)(clone $activeBucketQuery)
            ->where('package_type', self::PACKAGE_MEMBER)
            ->sum('remaining_points');
        $permanentBucketPoints = (float)(clone $activeBucketQuery)
            ->where('package_type', self::PACKAGE_POINTS)
            ->sum('remaining_points');
        $foreverBucketPoints = (float)(clone $activeBucketQuery)
            ->where('expire_time', 0)
            ->sum('remaining_points');
        $bucketBalance = self::tenantBucketBalance($tenantId);
        $legacyBalance = max(0, $balance - $bucketBalance);
        $memberExpireTime = (int)(clone $activeBucketQuery)
            ->where('package_type', self::PACKAGE_MEMBER)
            ->where('expire_time', '>', 0)
            ->max('expire_time');
        $permanentPoints = max($permanentBucketPoints, $foreverBucketPoints) + $legacyBalance;

        return [
            'time' => date('Y-m-d H:i:s'),
            'point_balance' => self::formatAmount($balance),
            'total_remaining_points' => self::formatAmount($balance),
            'bucket_balance' => self::formatAmount($bucketBalance),
            'legacy_balance' => self::formatAmount($legacyBalance),
            'member_points' => self::formatAmount($memberPoints),
            'member_expire_time' => $memberExpireTime,
            'member_expire_time_text' => $memberExpireTime > 0 ? date('Y-m-d H:i:s', $memberExpireTime) : '暂无有效会员套餐',
            'permanent_points' => self::formatAmount($permanentPoints),
            'today_consume_points' => self::formatAmount((float)(clone $consumeQuery)->where('create_time', '>=', $todayStart)->sum('change_amount')),
            'total_consume_points' => self::formatAmount((float)(clone $consumeQuery)->sum('change_amount')),
            'today_recharge_points' => self::formatAmount((float)(clone $rechargeQuery)->where('create_time', '>=', $todayStart)->sum('change_amount')),
            'total_recharge_points' => self::formatAmount((float)(clone $rechargeQuery)->sum('change_amount')),
        ];
    }

    public static function createOrder(int $tenantId, int $adminId, int $terminal, int $packageId): array
    {
        $package = TenantPowerPackage::where(['id' => $packageId, 'status' => self::STATUS_ENABLED])->findOrEmpty();
        if ($package->isEmpty()) {
            throw new RuntimeException('算力套餐不存在或已下架');
        }
        $orderSn = generate_sn(TenantPowerOrder::class, 'order_sn');
        $expireTime = self::calcExpireTime((string)$package['type'], (int)$package['duration_months']);
        $order = TenantPowerOrder::create([
            'tenant_id' => $tenantId,
            'admin_id' => $adminId,
            'order_sn' => $orderSn,
            'order_terminal' => $terminal,
            'package_id' => (int)$package['id'],
            'package_type' => (string)$package['type'],
            'package_name' => (string)$package['name'],
            'duration_months' => (int)$package['duration_months'],
            'order_amount' => self::formatAmount((float)$package['amount']),
            'points' => self::formatAmount((float)$package['points']),
            'expire_time' => $expireTime,
            'pay_status' => PayEnum::UNPAID,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        return [
            'order_id' => (int)$order['id'],
            'order_sn' => $orderSn,
            'from' => self::FROM,
        ];
    }

    public static function handlePaid(string $orderSn, array $extra = []): void
    {
        $order = TenantPowerOrder::where('order_sn', $orderSn)->lock(true)->findOrEmpty();
        if ($order->isEmpty()) {
            throw new RuntimeException('算力订单不存在');
        }
        if ((int)$order['pay_status'] === PayEnum::ISPAID) {
            return;
        }
        $points = (float)$order['points'];
        if ($points <= 0) {
            throw new RuntimeException('套餐到账点数异常');
        }
        self::grantTenantPoints(
            (int)$order['tenant_id'],
            $points,
            (string)$order['order_sn'],
            (string)$order['package_name'],
            (string)$order['package_type'],
            (int)$order['expire_time']
        );
        $order->save([
            'transaction_id' => (string)($extra['transaction_id'] ?? ''),
            'pay_status' => PayEnum::ISPAID,
            'pay_time' => time(),
            'update_time' => time(),
        ]);
    }

    public static function expireBuckets(int $tenantId = 0): void
    {
        $now = time();
        $query = TenantPointBucket::where('status', self::BUCKET_ACTIVE)
            ->where('expire_time', '>', 0)
            ->where('expire_time', '<=', $now)
            ->where('remaining_points', '>', 0)
            ->order('id', 'asc');
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        $query->chunk(100, function ($buckets) {
            foreach ($buckets as $bucket) {
                Db::transaction(function () use ($bucket) {
                    $locked = TenantPointBucket::where('id', (int)$bucket['id'])->lock(true)->findOrEmpty();
                    if ($locked->isEmpty() || (int)$locked['status'] !== self::BUCKET_ACTIVE || (float)$locked['remaining_points'] <= 0) {
                        return;
                    }
                    $tenant = Tenant::where('id', (int)$locked['tenant_id'])->lock(true)->findOrEmpty();
                    if ($tenant->isEmpty()) {
                        return;
                    }
                    $expired = min((float)$tenant['point_balance'], (float)$locked['remaining_points']);
                    $tenant->point_balance = self::formatAmount(max(0, (float)$tenant['point_balance'] - $expired));
                    $tenant->save();
                    $locked->remaining_points = '0.00';
                    $locked->status = self::BUCKET_EXPIRED;
                    $locked->update_time = time();
                    $locked->save();
                    if ($expired > 0) {
                        self::pointLog((int)$locked['tenant_id'], 'package_expire', 2, $expired, (float)$tenant['point_balance'], (string)$locked['source_order_sn'], '算力套餐点数过期', [
                            'bucket_id' => (int)$locked['id'],
                        ]);
                    }
                });
            }
        });
    }

    public static function consumeBuckets(int $tenantId, float $points, string $sourceSn, string $remark = '', array $extra = [], bool $expireFirst = true): void
    {
        if ($points <= 0) {
            throw new RuntimeException('点数必须大于0');
        }
        if ($expireFirst) {
            self::expireBuckets($tenantId);
        }

        $remain = round($points, 2);
        $buckets = TenantPointBucket::where(['tenant_id' => $tenantId, 'status' => self::BUCKET_ACTIVE])
            ->where('remaining_points', '>', 0)
            ->orderRaw('CASE WHEN expire_time = 0 THEN 1 ELSE 0 END ASC, expire_time ASC, id ASC')
            ->lock(true)
            ->select();
        foreach ($buckets as $bucket) {
            if ($remain <= 0) {
                break;
            }
            $deduct = min($remain, (float)$bucket['remaining_points']);
            if ($deduct <= 0) {
                continue;
            }
            $bucket->remaining_points = self::formatAmount((float)$bucket['remaining_points'] - $deduct);
            $bucket->update_time = time();
            $bucket->save();
            $remain = round($remain - $deduct, 2);
        }
    }

    public static function tenantBucketBalance(int $tenantId): float
    {
        self::expireBuckets($tenantId);
        return (float)TenantPointBucket::where(['tenant_id' => $tenantId, 'status' => self::BUCKET_ACTIVE])
            ->where('remaining_points', '>', 0)
            ->sum('remaining_points');
    }

    public static function formatOrder(array $order): array
    {
        $order['package_type_text'] = self::packageTypes()[$order['package_type'] ?? ''] ?? '';
        $order['pay_way_text'] = PayEnum::getPayDesc($order['pay_way'] ?? 0);
        $order['pay_status_text'] = PayEnum::getPayStatusDesc($order['pay_status'] ?? 0);
        $order['expire_time_text'] = empty($order['expire_time']) ? '永久有效' : date('Y-m-d H:i:s', (int)$order['expire_time']);
        $order['pay_time'] = empty($order['pay_time']) ? '' : date('Y-m-d H:i:s', (int)$order['pay_time']);
        return $order;
    }

    public static function formatPackage(array $package): array
    {
        $package['type_text'] = self::packageTypes()[$package['type'] ?? ''] ?? '';
        $package['validity_text'] = ($package['type'] ?? '') === self::PACKAGE_MEMBER
            ? ((int)($package['duration_months'] ?? 0) . '个月')
            : '永久有效';
        return $package;
    }

    public static function calcExpireTime(string $type, int $durationMonths): int
    {
        if ($type !== self::PACKAGE_MEMBER || $durationMonths <= 0) {
            return 0;
        }
        return strtotime('+' . $durationMonths . ' month') ?: 0;
    }

    public static function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private static function grantTenantPoints(int $tenantId, float $points, string $orderSn, string $packageName, string $packageType, int $expireTime): void
    {
        if (TenantPointBucket::where(['tenant_id' => $tenantId, 'source_order_sn' => $orderSn])->count() > 0) {
            return;
        }
        $tenant = Tenant::where('id', $tenantId)->lock(true)->findOrEmpty();
        if ($tenant->isEmpty()) {
            throw new RuntimeException('租户不存在');
        }
        if (TenantPointBucket::where(['tenant_id' => $tenantId, 'source_order_sn' => $orderSn])->count() > 0) {
            return;
        }
        $tenant->point_balance = self::formatAmount((float)$tenant['point_balance'] + $points);
        $tenant->save();
        $bucket = TenantPointBucket::create([
            'tenant_id' => $tenantId,
            'source_order_sn' => $orderSn,
            'package_type' => $packageType,
            'total_points' => self::formatAmount($points),
            'remaining_points' => self::formatAmount($points),
            'expire_time' => $expireTime,
            'status' => self::BUCKET_ACTIVE,
            'create_time' => time(),
            'update_time' => time(),
        ]);
        self::pointLog($tenantId, 'package_recharge', 1, $points, (float)$tenant['point_balance'], $orderSn, '购买算力套餐到账', [
            'bucket_id' => (int)$bucket['id'],
            'package_name' => $packageName,
            'package_type' => $packageType,
            'expire_time' => $expireTime,
        ]);
    }

    private static function pointLog(
        int $tenantId,
        string $changeType,
        int $action,
        float $changeAmount,
        float $leftAmount,
        string $sourceSn,
        string $remark,
        array $extra = []
    ): void {
        TenantPointLog::create([
            'sn' => generate_sn(TenantPointLog::class, 'sn', 20),
            'tenant_id' => $tenantId,
            'change_type' => $changeType,
            'action' => $action,
            'change_amount' => self::formatAmount($changeAmount),
            'left_amount' => self::formatAmount($leftAmount),
            'source_sn' => $sourceSn,
            'remark' => $remark,
            'extra' => $extra,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }
}
