<?php

namespace app\common\service\brand;

use app\common\enum\PayEnum;
use app\common\model\auth\TenantAdmin;
use app\common\model\brand\TenantBrandOrder;
use app\common\model\brand\TenantBrandPackagePrice;
use app\common\model\brand\TenantBrandQuotaBucket;
use app\common\model\brand\TenantBrandQuotaLog;
use app\common\model\brand\TenantBrandQuotaOrder;
use app\common\model\dept\TenantDept;
use app\common\model\tenant\Tenant;
use app\common\model\tenant\TenantPackage;
use app\common\service\app\DefaultAppService;
use app\common\service\billing\PackageProvisionService;
use app\common\service\tenant\TenantContractService;
use app\common\service\tenant\TenantPackageService;
use app\common\service\tenant\TenantUrlService;
use app\platformapi\logic\setting\pay\PayConfigLogic;
use app\platformapi\logic\setting\pay\PayWayLogic;
use app\platformapi\logic\tenant\TenantAdminLogic;
use app\platformapi\logic\tenant\TenantLogic;
use app\platformapi\logic\tenant\TenantSystemMenuLogic;
use app\tenantapi\logic\article\ArticleLogic;
use app\tenantapi\logic\decorate\DecorateDataLogic;
use app\tenantapi\logic\notice\NoticeLogic;
use RuntimeException;
use think\facade\Db;

class TenantBrandService
{
    public const FROM_QUOTA = 'tenant_brand_quota';
    public const FROM_ORDER = 'tenant_brand_order';
    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;
    public const OPEN_PENDING = 0;
    public const OPEN_SUCCESS = 1;
    public const OPEN_FAILED = 2;
    public const RESERVE_NONE = 0;
    public const RESERVE_ACTIVE = 1;
    public const RESERVE_CONSUMED = 2;
    public const RESERVE_RELEASED = 3;
    public const RESERVE_EXPIRED = 4;
    public const RESERVE_SECONDS = 1800;

    public static function packageRows(int $tenantId, bool $onlyShelf = false): array
    {
        $packages = TenantPackage::where('status', TenantPackageService::STATUS_ENABLED)
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();
        $packageIds = array_column($packages, 'id');
        $buckets = empty($packageIds) ? [] : TenantBrandQuotaBucket::where('tenant_id', $tenantId)
            ->whereIn('package_id', $packageIds)
            ->column('*', 'package_id');
        $prices = empty($packageIds) ? [] : TenantBrandPackagePrice::where('tenant_id', $tenantId)
            ->whereIn('package_id', $packageIds)
            ->column('*', 'package_id');

        $rows = [];
        foreach ($packages as $package) {
            $packageId = (int)$package['id'];
            $bucket = $buckets[$packageId] ?? [];
            $price = $prices[$packageId] ?? [];
            $remaining = max(0, (int)($bucket['remaining_quota'] ?? 0) - (int)($bucket['reserved_quota'] ?? 0));
            $salePrice = (float)($price['sale_price'] ?? $package['sale_price'] ?? 0);
            $status = (int)($price['status'] ?? 0);
            if ($onlyShelf && ($status !== self::STATUS_ENABLED || $remaining <= 0)) {
                continue;
            }
            $rows[] = array_merge(TenantPackageService::formatPackage($package), [
                'remaining_quota' => $remaining,
                'total_quota' => (int)($bucket['total_quota'] ?? 0),
                'used_quota' => (int)($bucket['used_quota'] ?? 0),
                'reserved_quota' => (int)($bucket['reserved_quota'] ?? 0),
                'tenant_sale_price' => self::formatAmount($salePrice),
                'shelf_status' => $status,
                'shelf_status_desc' => $status === self::STATUS_ENABLED ? '上架' : '下架',
            ]);
        }
        return $rows;
    }

    public static function savePrice(int $tenantId, array $params): void
    {
        $packageId = (int)($params['package_id'] ?? 0);
        $status = (int)($params['status'] ?? 0) ? self::STATUS_ENABLED : self::STATUS_DISABLED;
        $salePrice = (float)($params['sale_price'] ?? 0);
        if ($tenantId <= 0 || $packageId <= 0) {
            throw new RuntimeException('请选择套餐');
        }
        if ($salePrice < 0) {
            throw new RuntimeException('销售价不能小于0');
        }
        $package = TenantPackage::where(['id' => $packageId, 'status' => TenantPackageService::STATUS_ENABLED])->findOrEmpty();
        if ($package->isEmpty()) {
            throw new RuntimeException('平台套餐不存在或已停用');
        }
        if ($status === self::STATUS_ENABLED && self::remainingQuota($tenantId, $packageId) <= 0) {
            throw new RuntimeException('当前套餐额度不足，不能上架');
        }
        $row = TenantBrandPackagePrice::where(['tenant_id' => $tenantId, 'package_id' => $packageId])->findOrEmpty();
        $data = [
            'tenant_id' => $tenantId,
            'package_id' => $packageId,
            'sale_price' => self::formatAmount($salePrice),
            'status' => $status,
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            TenantBrandPackagePrice::create($data);
        } else {
            $row->save($data);
        }
    }

    public static function createQuotaOrder(int $tenantId, int $adminId, int $terminal, int $packageId, int $quantity): array
    {
        $quantity = max(1, $quantity);
        $package = TenantPackage::where(['id' => $packageId, 'status' => TenantPackageService::STATUS_ENABLED])->findOrEmpty();
        if ($package->isEmpty()) {
            throw new RuntimeException('平台套餐不存在或已停用');
        }
        $quotaPrice = (float)$package['quota_price'];
        if ($quotaPrice < 0) {
            throw new RuntimeException('额度进货价异常');
        }
        $orderSn = generate_sn(TenantBrandQuotaOrder::class, 'order_sn');
        $amount = $quotaPrice * $quantity;
        $order = TenantBrandQuotaOrder::create([
            'tenant_id' => $tenantId,
            'admin_id' => $adminId,
            'order_sn' => $orderSn,
            'order_terminal' => $terminal,
            'package_id' => (int)$package['id'],
            'package_name' => (string)$package['name'],
            'quantity' => $quantity,
            'unit_price' => self::formatAmount($quotaPrice),
            'order_amount' => self::formatAmount($amount),
            'pay_status' => PayEnum::UNPAID,
            'create_time' => time(),
            'update_time' => time(),
        ]);
        return [
            'order_id' => (int)$order['id'],
            'order_sn' => $orderSn,
            'from' => self::FROM_QUOTA,
        ];
    }

    public static function handleQuotaPaid(string $orderSn, array $extra = []): void
    {
        Db::transaction(function () use ($orderSn, $extra) {
            $order = TenantBrandQuotaOrder::where('order_sn', $orderSn)->lock(true)->findOrEmpty();
            if ($order->isEmpty()) {
                throw new RuntimeException('额度订单不存在');
            }
            if ((int)$order['pay_status'] === PayEnum::ISPAID) {
                return;
            }
            $quantity = (int)$order['quantity'];
            if ($quantity <= 0) {
                throw new RuntimeException('额度数量异常');
            }
            self::increaseQuota((int)$order['tenant_id'], (int)$order['package_id'], $quantity, $orderSn, '租户采购贴牌额度');
            $order->save([
                'transaction_id' => (string)($extra['transaction_id'] ?? ''),
                'pay_status' => PayEnum::ISPAID,
                'pay_time' => time(),
                'update_time' => time(),
            ]);
        });
    }

    public static function createBrandOrder(int $tenantId, int $userId, int $terminal, array $params): array
    {
        $packageId = (int)($params['package_id'] ?? 0);
        if ($packageId <= 0) {
            throw new RuntimeException('请选择套餐');
        }
        $price = TenantBrandPackagePrice::where([
            'tenant_id' => $tenantId,
            'package_id' => $packageId,
            'status' => self::STATUS_ENABLED,
        ])->findOrEmpty();
        if ($price->isEmpty()) {
            throw new RuntimeException('套餐未上架');
        }
        $package = TenantPackage::where(['id' => $packageId, 'status' => TenantPackageService::STATUS_ENABLED])->findOrEmpty();
        if ($package->isEmpty()) {
            throw new RuntimeException('平台套餐不存在或已停用');
        }
        $tenantName = trim((string)($params['tenant_name'] ?? ''));
        $domainAlias = TenantUrlService::normalizeHost((string)($params['domain_alias'] ?? ''));
        $account = trim((string)($params['account'] ?? ''));
        $password = (string)($params['password'] ?? '');
        $targetTenantId = (int)($params['target_tenant_id'] ?? 0);
        if ($targetTenantId <= 0) {
            if ($tenantName === '') {
                throw new RuntimeException('请输入租户名称');
            }
            if ($account === '') {
                throw new RuntimeException('请输入管理员账号');
            }
            if ($password === '') {
                throw new RuntimeException('请输入管理员密码');
            }
        }

        $orderSn = generate_sn(TenantBrandOrder::class, 'order_sn');
        $now = time();
        $order = Db::transaction(function () use ($tenantId, $userId, $terminal, $packageId, $package, $price, $targetTenantId, $tenantName, $domainAlias, $account, $password, $orderSn, $now) {
            self::reserveQuota($tenantId, $packageId, 1, $orderSn);
            return TenantBrandOrder::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'order_sn' => $orderSn,
                'order_terminal' => $terminal,
                'package_id' => $packageId,
                'package_name' => (string)$package['name'],
                'quantity' => 1,
                'unit_price' => self::formatAmount((float)$price['sale_price']),
                'order_amount' => self::formatAmount((float)$price['sale_price']),
                'target_tenant_id' => $targetTenantId,
                'child_tenant_name' => $tenantName,
                'child_domain_alias' => $domainAlias,
                'admin_account' => $account,
                'admin_password_hash' => $password !== '' ? TenantAdminLogic::createPassword($password) : '',
                'pay_status' => PayEnum::UNPAID,
                'open_status' => self::OPEN_PENDING,
                'reserve_status' => self::RESERVE_ACTIVE,
                'reserve_time' => $now,
                'reserve_expire_time' => $now + self::RESERVE_SECONDS,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        });
        return [
            'order_id' => (int)$order['id'],
            'order_sn' => $orderSn,
            'from' => self::FROM_ORDER,
        ];
    }

    public static function handleBrandOrderPaid(string $orderSn, array $extra = []): void
    {
        $now = time();
        Db::transaction(function () use ($orderSn, $extra, $now) {
            $order = TenantBrandOrder::where('order_sn', $orderSn)->lock(true)->findOrEmpty();
            if ($order->isEmpty()) throw new RuntimeException('贴牌订单不存在');
            if ((int)$order['pay_status'] !== PayEnum::ISPAID) {
                if ((int)$order['reserve_status'] !== self::RESERVE_ACTIVE) {
                    throw new RuntimeException('订单额度预占已失效');
                }
                self::consumeReservedQuota((int)$order['tenant_id'], (int)$order['package_id'], 1, $orderSn);
                $order->save([
                    'transaction_id' => (string)($extra['transaction_id'] ?? ''),
                    'pay_status' => PayEnum::ISPAID,
                    'pay_time' => $now,
                    'reserve_status' => self::RESERVE_CONSUMED,
                    'update_time' => $now,
                ]);
            }
        });
        self::provisionBrandOrder($orderSn);
    }

    /** Retry only provisioning. The paid order and its consumed quota are never charged again. */
    public static function retryProvision(string $orderSn): void
    {
        $order = TenantBrandOrder::where('order_sn', $orderSn)->findOrEmpty();
        if ($order->isEmpty() || (int)$order['pay_status'] !== PayEnum::ISPAID) {
            throw new RuntimeException('仅已支付订单可重试开通');
        }
        self::provisionBrandOrder($orderSn);
    }

    public static function expirePendingOrders(): int
    {
        $expired = 0;
        $rows = TenantBrandOrder::where('pay_status', PayEnum::UNPAID)
            ->where('reserve_status', self::RESERVE_ACTIVE)
            ->where('reserve_expire_time', '<=', time())
            ->column('order_sn');
        foreach ($rows as $orderSn) {
            $changed = Db::transaction(function () use ($orderSn) {
                $order = TenantBrandOrder::where('order_sn', $orderSn)->lock(true)->findOrEmpty();
                if ($order->isEmpty() || (int)$order['pay_status'] !== PayEnum::UNPAID || (int)$order['reserve_status'] !== self::RESERVE_ACTIVE || (int)$order['reserve_expire_time'] > time()) return false;
                self::releaseReservedQuota((int)$order['tenant_id'], (int)$order['package_id'], 1, $orderSn, '贴牌订单超时释放额度');
                $order->save(['reserve_status' => self::RESERVE_EXPIRED, 'update_time' => time()]);
                return true;
            });
            if ($changed) $expired++;
        }
        return $expired;
    }

    public static function formatQuotaOrder(array $row): array
    {
        $row['order_amount'] = self::formatAmount((float)($row['order_amount'] ?? 0));
        $row['unit_price'] = self::formatAmount((float)($row['unit_price'] ?? 0));
        $row['pay_status_desc'] = PayEnum::getPayStatusDesc($row['pay_status'] ?? 0);
        $row['pay_way_desc'] = PayEnum::getPayDesc($row['pay_way'] ?? 0);
        return $row;
    }

    public static function formatBrandOrder(array $row): array
    {
        unset($row['admin_password_hash']);
        $row['order_amount'] = self::formatAmount((float)($row['order_amount'] ?? 0));
        $row['unit_price'] = self::formatAmount((float)($row['unit_price'] ?? 0));
        $row['pay_status_desc'] = PayEnum::getPayStatusDesc($row['pay_status'] ?? 0);
        $row['pay_way_desc'] = PayEnum::getPayDesc($row['pay_way'] ?? 0);
        $row['open_status_desc'] = match ((int)($row['open_status'] ?? 0)) {
            self::OPEN_SUCCESS => '已开通',
            self::OPEN_FAILED => '开通失败',
            default => '待开通',
        };
        $row['order_status'] = match (true) {
            (int)($row['pay_status'] ?? 0) !== PayEnum::ISPAID && (int)($row['reserve_status'] ?? 0) === self::RESERVE_EXPIRED => '已过期',
            (int)($row['pay_status'] ?? 0) !== PayEnum::ISPAID => '待支付',
            (int)($row['open_status'] ?? 0) === self::OPEN_SUCCESS => '已开通',
            (int)($row['open_status'] ?? 0) === self::OPEN_FAILED => '开通失败',
            default => '已支付开通中',
        };
        if ((int)($row['child_tenant_id'] ?? 0) > 0) {
            $row['login_path'] = '/t/' . (int)$row['child_tenant_id'] . '/admin/login';
        }
        return $row;
    }

    private static function createChildTenant(array $order): int
    {
        $domainAlias = (string)$order['child_domain_alias'];
        if ($domainAlias === '') {
            $domainAlias = 'tenant-' . strtolower((string)$order['order_sn']) . '.local';
        }
        $tenant = TenantLogic::add([
            'name' => (string)$order['child_tenant_name'],
            'avatar' => '',
            'tel' => '',
            'domain_alias' => $domainAlias,
            'domain_aliases' => [[
                'domain' => $domainAlias,
                'is_primary' => 1,
                'status' => 1,
            ]],
            'disable' => 0,
            'notes' => '贴牌订单开通：' . (string)$order['order_sn'],
            'account' => (string)$order['admin_account'],
            'parent_tenant_id' => (int)$order['tenant_id'],
            'source_tenant_id' => (int)$order['tenant_id'],
        ]);
        $tenantId = (int)$tenant['id'];
        // White-label orders start with the guaranteed tenant-id route; a custom domain is optional and bound later.
        $tenant->save(['access_mode' => TenantUrlService::ACCESS_ID]);
        ArticleLogic::initialization($tenantId);
        $admin = TenantAdmin::create([
            'tenant_id' => $tenantId,
            'account' => (string)$order['admin_account'],
            'name' => '超级管理员',
            'password' => (string)$order['admin_password_hash'],
            'avatar' => '',
            'disable' => 0,
            'root' => 1,
            'create_time' => time(),
        ]);
        TenantDept::initialization($tenantId, (int)$admin['id']);
        TenantSystemMenuLogic::initialization($tenantId);
        PayConfigLogic::initialization($tenantId);
        PayWayLogic::initialization($tenantId);
        NoticeLogic::initialization($tenantId);
        DecorateDataLogic::initialization($tenantId);
        DefaultAppService::syncTenantDefaults($tenantId, (string)$tenant['sn'], false);
        PackageProvisionService::syncTenant($tenantId, (string)$tenant['sn'], false);
        return $tenantId;
    }

    private static function increaseQuota(int $tenantId, int $packageId, int $quantity, string $sourceSn, string $remark): void
    {
        $bucket = TenantBrandQuotaBucket::where(['tenant_id' => $tenantId, 'package_id' => $packageId])->lock(true)->findOrEmpty();
        $before = $bucket->isEmpty() ? 0 : (int)$bucket['remaining_quota'];
        $data = [
            'tenant_id' => $tenantId,
            'package_id' => $packageId,
            'total_quota' => ($bucket->isEmpty() ? 0 : (int)$bucket['total_quota']) + $quantity,
            'remaining_quota' => $before + $quantity,
            'used_quota' => $bucket->isEmpty() ? 0 : (int)$bucket['used_quota'],
            'reserved_quota' => $bucket->isEmpty() ? 0 : (int)$bucket['reserved_quota'],
            'update_time' => time(),
        ];
        if ($bucket->isEmpty()) {
            $data['create_time'] = time();
            TenantBrandQuotaBucket::create($data);
        } else {
            $bucket->save($data);
        }
        self::quotaLog($tenantId, $packageId, 'increase', $quantity, $before, $before + $quantity, $sourceSn, $remark);
    }

    private static function reserveQuota(int $tenantId, int $packageId, int $quantity, string $sourceSn): void
    {
        $bucket = TenantBrandQuotaBucket::where(['tenant_id' => $tenantId, 'package_id' => $packageId])->lock(true)->findOrEmpty();
        if ($bucket->isEmpty() || (int)$bucket['remaining_quota'] - (int)$bucket['reserved_quota'] < $quantity) {
            throw new RuntimeException('贴牌额度不足');
        }
        $before = (int)$bucket['remaining_quota'] - (int)$bucket['reserved_quota'];
        $bucket->save([
            'reserved_quota' => (int)$bucket['reserved_quota'] + $quantity,
            'update_time' => time(),
        ]);
        self::quotaLog($tenantId, $packageId, 'reserve', $quantity, $before, $before - $quantity, $sourceSn, '贴牌订单预占额度');
    }

    private static function consumeReservedQuota(int $tenantId, int $packageId, int $quantity, string $sourceSn): void
    {
        $bucket = TenantBrandQuotaBucket::where(['tenant_id' => $tenantId, 'package_id' => $packageId])->lock(true)->findOrEmpty();
        if ($bucket->isEmpty() || (int)$bucket['reserved_quota'] < $quantity || (int)$bucket['remaining_quota'] < $quantity) throw new RuntimeException('贴牌额度预占异常');
        $before = (int)$bucket['remaining_quota'];
        $bucket->save(['remaining_quota' => $before - $quantity, 'reserved_quota' => (int)$bucket['reserved_quota'] - $quantity, 'used_quota' => (int)$bucket['used_quota'] + $quantity, 'update_time' => time()]);
        self::quotaLog($tenantId, $packageId, 'decrease', $quantity, $before, $before - $quantity, $sourceSn, '支付确认消耗贴牌额度');
    }

    private static function releaseReservedQuota(int $tenantId, int $packageId, int $quantity, string $sourceSn, string $remark): void
    {
        $bucket = TenantBrandQuotaBucket::where(['tenant_id' => $tenantId, 'package_id' => $packageId])->lock(true)->findOrEmpty();
        if ($bucket->isEmpty() || (int)$bucket['reserved_quota'] < $quantity) return;
        $available = (int)$bucket['remaining_quota'] - (int)$bucket['reserved_quota'];
        $bucket->save(['reserved_quota' => (int)$bucket['reserved_quota'] - $quantity, 'update_time' => time()]);
        self::quotaLog($tenantId, $packageId, 'rollback', $quantity, $available, $available + $quantity, $sourceSn, $remark);
    }

    private static function provisionBrandOrder(string $orderSn): void
    {
        try {
            Db::transaction(function () use ($orderSn) {
                $order = TenantBrandOrder::where('order_sn', $orderSn)->lock(true)->findOrEmpty();
                if ($order->isEmpty() || (int)$order['pay_status'] !== PayEnum::ISPAID) throw new RuntimeException('订单未支付');
                if ((int)$order['open_status'] === self::OPEN_SUCCESS) return;
                $childTenantId = (int)$order['child_tenant_id'] ?: (int)$order['target_tenant_id'];
                if ($childTenantId <= 0) $childTenantId = self::createChildTenant($order->toArray());
                TenantContractService::open($childTenantId, (int)$order['package_id'], 0, '贴牌订单开通/续费', $orderSn);
                $order->save(['child_tenant_id' => $childTenantId, 'open_status' => self::OPEN_SUCCESS, 'open_time' => time(), 'open_error' => '', 'update_time' => time()]);
            });
        } catch (\Throwable $e) {
            $order = TenantBrandOrder::where('order_sn', $orderSn)->findOrEmpty();
            if ($order->isEmpty()) throw $e;
            $order->save(['open_status' => self::OPEN_FAILED, 'open_time' => time(), 'open_error' => mb_substr($e->getMessage(), 0, 500), 'update_time' => time()]);
        }
    }

    private static function quotaLog(int $tenantId, int $packageId, string $changeType, int $quantity, int $before, int $after, string $sourceSn, string $remark): void
    {
        TenantBrandQuotaLog::create([
            'tenant_id' => $tenantId,
            'package_id' => $packageId,
            'change_type' => $changeType,
            'change_quota' => $quantity,
            'before_quota' => $before,
            'after_quota' => $after,
            'source_sn' => $sourceSn,
            'remark' => $remark,
            'create_time' => time(),
        ]);
    }

    private static function remainingQuota(int $tenantId, int $packageId): int
    {
        $bucket = TenantBrandQuotaBucket::where(['tenant_id' => $tenantId, 'package_id' => $packageId])->findOrEmpty();
        return $bucket->isEmpty() ? 0 : max(0, (int)$bucket['remaining_quota'] - (int)$bucket['reserved_quota']);
    }

    private static function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
