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
            $remaining = (int)($bucket['remaining_quota'] ?? 0);
            $salePrice = (float)($price['sale_price'] ?? $package['sale_price'] ?? 0);
            $status = (int)($price['status'] ?? 0);
            if ($onlyShelf && ($status !== self::STATUS_ENABLED || $remaining <= 0)) {
                continue;
            }
            $rows[] = array_merge(TenantPackageService::formatPackage($package), [
                'remaining_quota' => $remaining,
                'total_quota' => (int)($bucket['total_quota'] ?? 0),
                'used_quota' => (int)($bucket['used_quota'] ?? 0),
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
        if (self::remainingQuota($tenantId, $packageId) <= 0) {
            throw new RuntimeException('套餐额度不足');
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
            if ($domainAlias === '') {
                throw new RuntimeException('请输入域名别名');
            }
            if ($account === '') {
                throw new RuntimeException('请输入管理员账号');
            }
            if ($password === '') {
                throw new RuntimeException('请输入管理员密码');
            }
        }

        $orderSn = generate_sn(TenantBrandOrder::class, 'order_sn');
        $order = TenantBrandOrder::create([
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
            'create_time' => time(),
            'update_time' => time(),
        ]);
        return [
            'order_id' => (int)$order['id'],
            'order_sn' => $orderSn,
            'from' => self::FROM_ORDER,
        ];
    }

    public static function handleBrandOrderPaid(string $orderSn, array $extra = []): void
    {
        $now = time();
        try {
            Db::transaction(function () use ($orderSn, $extra, $now) {
                $order = TenantBrandOrder::where('order_sn', $orderSn)->lock(true)->findOrEmpty();
                if ($order->isEmpty()) {
                    throw new RuntimeException('贴牌订单不存在');
                }
                if ((int)$order['pay_status'] === PayEnum::ISPAID
                    && in_array((int)$order['open_status'], [self::OPEN_SUCCESS, self::OPEN_FAILED], true)) {
                    return;
                }
                self::decreaseQuota((int)$order['tenant_id'], (int)$order['package_id'], 1, $orderSn, '终端用户购买贴牌套餐');
                $childTenantId = (int)$order['target_tenant_id'];
                if ($childTenantId <= 0) {
                    $childTenantId = self::createChildTenant($order->toArray());
                }
                TenantContractService::open($childTenantId, (int)$order['package_id'], 0, '贴牌订单开通/续费', $orderSn);
                $order->save([
                    'child_tenant_id' => $childTenantId,
                    'transaction_id' => (string)($extra['transaction_id'] ?? ''),
                    'pay_status' => PayEnum::ISPAID,
                    'pay_time' => $now,
                    'open_status' => self::OPEN_SUCCESS,
                    'open_time' => $now,
                    'open_error' => '',
                    'update_time' => $now,
                ]);
            });
        } catch (\Throwable $e) {
            $order = TenantBrandOrder::where('order_sn', $orderSn)->findOrEmpty();
            if ($order->isEmpty()) {
                throw $e;
            }
            $order->save([
                'transaction_id' => (string)($extra['transaction_id'] ?? ($order['transaction_id'] ?? '')),
                'pay_status' => PayEnum::ISPAID,
                'pay_time' => (int)($order['pay_time'] ?? $now) ?: $now,
                'open_status' => self::OPEN_FAILED,
                'open_time' => $now,
                'open_error' => mb_substr($e->getMessage(), 0, 500),
                'update_time' => $now,
            ]);
        }
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
        $row['order_amount'] = self::formatAmount((float)($row['order_amount'] ?? 0));
        $row['unit_price'] = self::formatAmount((float)($row['unit_price'] ?? 0));
        $row['pay_status_desc'] = PayEnum::getPayStatusDesc($row['pay_status'] ?? 0);
        $row['pay_way_desc'] = PayEnum::getPayDesc($row['pay_way'] ?? 0);
        $row['open_status_desc'] = match ((int)($row['open_status'] ?? 0)) {
            self::OPEN_SUCCESS => '已开通',
            self::OPEN_FAILED => '开通失败',
            default => '待开通',
        };
        return $row;
    }

    private static function createChildTenant(array $order): int
    {
        $tenant = TenantLogic::add([
            'name' => (string)$order['child_tenant_name'],
            'avatar' => '',
            'tel' => '',
            'domain_alias' => (string)$order['child_domain_alias'],
            'domain_aliases' => [[
                'domain' => (string)$order['child_domain_alias'],
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

    private static function decreaseQuota(int $tenantId, int $packageId, int $quantity, string $sourceSn, string $remark): void
    {
        $bucket = TenantBrandQuotaBucket::where(['tenant_id' => $tenantId, 'package_id' => $packageId])->lock(true)->findOrEmpty();
        if ($bucket->isEmpty() || (int)$bucket['remaining_quota'] < $quantity) {
            throw new RuntimeException('贴牌额度不足');
        }
        $before = (int)$bucket['remaining_quota'];
        $after = $before - $quantity;
        $bucket->save([
            'remaining_quota' => $after,
            'used_quota' => (int)$bucket['used_quota'] + $quantity,
            'update_time' => time(),
        ]);
        self::quotaLog($tenantId, $packageId, 'decrease', $quantity, $before, $after, $sourceSn, $remark);
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
        return (int)TenantBrandQuotaBucket::where(['tenant_id' => $tenantId, 'package_id' => $packageId])->value('remaining_quota');
    }

    private static function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
