<?php

namespace app\common\service\distribution;

use RuntimeException;
use think\facade\Db;

/**
 * Tenant-scoped distribution domain service. Monetary amounts here are RMB,
 * intentionally separate from the user's point balance.
 */
class DistributionService
{
    public const ORDER_RECHARGE = 'recharge';
    public const ORDER_MEMBERSHIP = 'membership';
    public const RULE_INHERIT = 'inherit';
    public const RULE_CUSTOM = 'custom';
    public const RULE_DISABLED = 'disabled';
    public const COMMISSION_PENDING = 'pending';
    public const COMMISSION_AVAILABLE = 'available';
    public const COMMISSION_REVERSED = 'reversed';
    public const COMMISSION_FROZEN = 'frozen';
    public const WITHDRAW_PENDING = 'pending';
    public const WITHDRAW_APPROVED = 'approved';
    public const WITHDRAW_PAID = 'paid';
    public const WITHDRAW_REJECTED = 'rejected';
    public const WITHDRAW_FAILED = 'failed';

    private static function table(string $name)
    {
        return Db::name('distribution_' . $name);
    }

    public static function defaultConfig(int $tenantId): array
    {
        return [
            'tenant_id' => $tenantId,
            'status' => 0,
            'level_count' => 3,
            'level1_rate' => '10.0000',
            'level2_rate' => '5.0000',
            'level3_rate' => '2.0000',
            'settle_days' => 15,
            'min_withdraw_amount' => '100.00',
            'withdraw_notice' => '',
        ];
    }

    public static function config(int $tenantId): array
    {
        $row = self::table('config')->where('tenant_id', $tenantId)->find();
        return $row ?: self::defaultConfig($tenantId);
    }

    public static function saveConfig(int $tenantId, array $input): array
    {
        $config = array_merge(self::config($tenantId), [
            'status' => !empty($input['status']) ? 1 : 0,
            'level_count' => max(1, min(3, (int)($input['level_count'] ?? 3))),
            'level1_rate' => self::rate($input['level1_rate'] ?? 0),
            'level2_rate' => self::rate($input['level2_rate'] ?? 0),
            'level3_rate' => self::rate($input['level3_rate'] ?? 0),
            'settle_days' => max(0, (int)($input['settle_days'] ?? 0)),
            'min_withdraw_amount' => self::amount($input['min_withdraw_amount'] ?? 0),
            'withdraw_notice' => trim((string)($input['withdraw_notice'] ?? '')),
            'update_time' => time(),
        ]);
        self::assertRates($config);
        $exists = self::table('config')->where('tenant_id', $tenantId)->lock(true)->find();
        if ($exists) {
            self::table('config')->where('id', $exists['id'])->update($config);
        } else {
            $config['create_time'] = time();
            self::table('config')->insert($config);
        }
        return self::config($tenantId);
    }

    public static function savePackageRule(int $tenantId, string $orderType, int $packageId, array $input): array
    {
        self::assertOrderType($orderType);
        if ($packageId <= 0) {
            throw new RuntimeException('套餐不能为空');
        }
        $mode = (string)($input['rule_mode'] ?? self::RULE_INHERIT);
        if (!in_array($mode, [self::RULE_INHERIT, self::RULE_CUSTOM, self::RULE_DISABLED], true)) {
            throw new RuntimeException('套餐分销规则不正确');
        }
        $row = [
            'tenant_id' => $tenantId,
            'order_type' => $orderType,
            'package_id' => $packageId,
            'rule_mode' => $mode,
            'level_count' => max(1, min(3, (int)($input['level_count'] ?? 3))),
            'level1_rate' => self::rate($input['level1_rate'] ?? 0),
            'level2_rate' => self::rate($input['level2_rate'] ?? 0),
            'level3_rate' => self::rate($input['level3_rate'] ?? 0),
            'update_time' => time(),
        ];
        self::assertRates($row);
        $old = self::table('package_rule')->where([
            'tenant_id' => $tenantId, 'order_type' => $orderType, 'package_id' => $packageId,
        ])->find();
        if ($old) {
            self::table('package_rule')->where('id', $old['id'])->update($row);
        } else {
            $row['create_time'] = time();
            self::table('package_rule')->insert($row);
        }
        return self::packageRule($tenantId, $orderType, $packageId);
    }

    public static function packageRule(int $tenantId, string $orderType, int $packageId): array
    {
        $row = self::table('package_rule')->where([
            'tenant_id' => $tenantId, 'order_type' => $orderType, 'package_id' => $packageId,
        ])->find();
        return $row ?: [
            'tenant_id' => $tenantId, 'order_type' => $orderType, 'package_id' => $packageId,
            'rule_mode' => self::RULE_INHERIT, 'level_count' => 3,
            'level1_rate' => '0.0000', 'level2_rate' => '0.0000', 'level3_rate' => '0.0000',
        ];
    }

    /** Returns every payable package with explicit and inherited distribution rules. */
    public static function packageRulesForTenant(int $tenantId, string $orderType = ''): array
    {
        $types = $orderType === '' ? [self::ORDER_RECHARGE, self::ORDER_MEMBERSHIP] : [$orderType];
        $result = [];
        foreach ($types as $type) {
            self::assertOrderType($type);
            $packages = $type === self::ORDER_RECHARGE
                ? Db::name('recharge_package')->where('tenant_id', $tenantId)->field('id,name,amount,status,sort')->order('sort desc,id asc')->select()->toArray()
                : Db::name('membership_plan')->where('tenant_id', $tenantId)->field('id,name,monthly_price,yearly_price,status,sort')->order('sort desc,id asc')->select()->toArray();
            foreach ($packages as $package) {
                $id = (int)$package['id'];
                $result[] = array_merge($package, [
                    'order_type' => $type,
                    'rule' => self::packageRule($tenantId, $type, $id),
                    'effective_rule' => self::effectiveRule($tenantId, $type, $id),
                ]);
            }
        }
        return $result;
    }

    /** Rule snapshot. A disabled package never falls through to global settings. */
    public static function effectiveRule(int $tenantId, string $orderType, int $packageId): array
    {
        $config = self::config($tenantId);
        $package = self::packageRule($tenantId, $orderType, $packageId);
        if ($package['rule_mode'] === self::RULE_DISABLED) {
            return ['enabled' => false, 'level_count' => 0, 'rates' => []];
        }
        $source = $package['rule_mode'] === self::RULE_CUSTOM ? $package : $config;
        $count = max(1, min(3, (int)$source['level_count'], (int)$config['level_count']));
        $rates = [];
        for ($level = 1; $level <= $count; $level++) {
            $rates[$level] = self::rate($source['level' . $level . '_rate'] ?? 0);
        }
        return [
            'enabled' => (int)$config['status'] === 1,
            'level_count' => $count,
            'rates' => $rates,
            'settle_days' => (int)$config['settle_days'],
            'source' => $package['rule_mode'] === self::RULE_CUSTOM ? 'package' : 'global',
        ];
    }

    public static function ensurePromoter(int $tenantId, int $userId): array
    {
        if ($tenantId <= 0 || $userId <= 0) {
            throw new RuntimeException('推广用户不存在');
        }
        $row = self::table('relation')->where(['tenant_id' => $tenantId, 'user_id' => $userId])->lock(true)->find();
        if ($row) {
            return $row;
        }
        for ($i = 0; $i < 10; $i++) {
            $code = strtoupper(substr(base_convert((string)$userId . random_int(100000, 999999), 10, 36), -10));
            try {
                self::table('relation')->insert([
                    'tenant_id' => $tenantId, 'user_id' => $userId, 'invite_code' => $code,
                    'level1_user_id' => 0, 'level2_user_id' => 0, 'level3_user_id' => 0,
                    'is_frozen' => 0, 'bind_time' => 0, 'create_time' => time(), 'update_time' => time(),
                ]);
                return self::table('relation')->where(['tenant_id' => $tenantId, 'user_id' => $userId])->find();
            } catch (\Throwable $e) {
                if ($i === 9) {
                    throw new RuntimeException('推广码生成失败');
                }
            }
        }
        throw new RuntimeException('推广码生成失败');
    }

    /** Bind once on registration; callers must pass the newly-created user ID. */
    public static function bindInviteCode(int $tenantId, int $userId, string $inviteCode, string $source = 'register'): array
    {
        $relation = self::ensurePromoter($tenantId, $userId);
        if ((int)$relation['bind_time'] > 0) {
            return $relation;
        }
        $inviteCode = strtoupper(trim($inviteCode));
        if ($inviteCode === '') {
            return $relation;
        }
        $parent = self::table('relation')->where(['tenant_id' => $tenantId, 'invite_code' => $inviteCode])->lock(true)->find();
        if (!$parent) {
            throw new RuntimeException('推广码不存在或不属于当前租户');
        }
        if ((int)$parent['user_id'] === $userId) {
            throw new RuntimeException('不能绑定自己的推广码');
        }
        $chain = [(int)$parent['user_id'], (int)$parent['level1_user_id'], (int)$parent['level2_user_id']];
        if (in_array($userId, $chain, true)) {
            throw new RuntimeException('推广关系不能形成循环');
        }
        self::table('relation')->where('id', $relation['id'])->update([
            'level1_user_id' => $chain[0], 'level2_user_id' => $chain[1], 'level3_user_id' => $chain[2],
            'bind_time' => time(), 'bind_source' => $source, 'update_time' => time(),
        ]);
        return self::table('relation')->where('id', $relation['id'])->find();
    }

    public static function adjustRelation(int $tenantId, int $userId, int $parentUserId, int $operatorId, string $reason): array
    {
        if (trim($reason) === '') {
            throw new RuntimeException('请填写调整原因');
        }
        return Db::transaction(function () use ($tenantId, $userId, $parentUserId, $operatorId, $reason) {
            $relation = self::ensurePromoter($tenantId, $userId);
            $before = self::chain($relation);
            $parent = $parentUserId > 0 ? self::ensurePromoter($tenantId, $parentUserId) : null;
            if ($parent && ($parentUserId === $userId || in_array($userId, self::chain($parent), true))) {
                throw new RuntimeException('调整后的关系形成循环');
            }
            $after = $parent ? [(int)$parent['user_id'], (int)$parent['level1_user_id'], (int)$parent['level2_user_id']] : [0, 0, 0];
            self::table('relation')->where('id', $relation['id'])->update([
                'level1_user_id' => $after[0], 'level2_user_id' => $after[1], 'level3_user_id' => $after[2],
                'bind_time' => $after[0] ? time() : 0, 'bind_source' => 'admin', 'update_time' => time(),
            ]);
            self::table('relation_log')->insert([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'operator_id' => $operatorId,
                'before_chain' => json_encode($before, JSON_UNESCAPED_UNICODE),
                'after_chain' => json_encode($after, JSON_UNESCAPED_UNICODE),
                'reason' => trim($reason), 'create_time' => time(),
            ]);
            return self::table('relation')->where('id', $relation['id'])->find();
        });
    }

    /** Resolve an ID/account/mobile/invite code within the current tenant only. */
    public static function resolveUserId(int $tenantId, $identifier): int
    {
        if (is_string($identifier) && str_starts_with(trim($identifier), '{')) {
            $decoded = json_decode($identifier, true);
            if (is_array($decoded)) {
                $identifier = $decoded;
            }
        }
        if (is_array($identifier)) {
            foreach (['user_id', 'id', 'account', 'mobile', 'invite_code'] as $key) {
                if (!empty($identifier[$key])) {
                    $identifier = $identifier[$key];
                    break;
                }
            }
        }
        $value = trim((string)$identifier);
        if ($value === '') {
            return 0;
        }
        $user = is_numeric($value)
            ? Db::name('user')->where(['tenant_id' => $tenantId, 'id' => (int)$value])->find()
            : Db::name('user')->where('tenant_id', $tenantId)->where('account|mobile', $value)->find();
        if ($user) {
            return (int)$user['id'];
        }
        $relation = self::table('relation')->where(['tenant_id' => $tenantId, 'invite_code' => strtoupper($value)])->find();
        return (int)($relation['user_id'] ?? 0);
    }

    public static function onRechargePaid(string $orderSn): void
    {
        $order = Db::name('recharge_order')->where('sn', $orderSn)->find();
        if (!$order || (int)$order['pay_status'] !== 1) {
            return;
        }
        self::createOrderCommission(self::ORDER_RECHARGE, $order);
    }

    public static function onMembershipPaid(string $orderSn): void
    {
        $order = Db::name('membership_order')->where('order_sn', $orderSn)->find();
        if (!$order || (int)$order['pay_status'] !== 1) {
            return;
        }
        self::createOrderCommission(self::ORDER_MEMBERSHIP, $order);
    }

    private static function createOrderCommission(string $orderType, array $order): void
    {
        $tenantId = (int)$order['tenant_id'];
        $userId = (int)$order['user_id'];
        $orderSn = (string)($orderType === self::ORDER_RECHARGE ? $order['sn'] : $order['order_sn']);
        $packageId = (int)($orderType === self::ORDER_RECHARGE ? ($order['package_id'] ?? 0) : ($order['plan_id'] ?? 0));
        $paidAmount = (float)($order['order_amount'] ?? 0);
        if ($tenantId <= 0 || $userId <= 0 || $paidAmount <= 0) {
            return;
        }
        Db::transaction(function () use ($tenantId, $userId, $orderType, $orderSn, $packageId, $paidAmount) {
            $rule = self::effectiveRule($tenantId, $orderType, $packageId);
            if (!$rule['enabled']) {
                return;
            }
            $relation = self::table('relation')->where(['tenant_id' => $tenantId, 'user_id' => $userId])->find();
            if (!$relation || (int)$relation['is_frozen'] === 1) {
                return;
            }
            $settleAt = (int)$rule['settle_days'] === 0 ? time() : time() + ((int)$rule['settle_days'] * 86400);
            for ($level = 1; $level <= (int)$rule['level_count']; $level++) {
                $beneficiaryId = (int)($relation['level' . $level . '_user_id'] ?? 0);
                $rate = (float)($rule['rates'][$level] ?? 0);
                if ($beneficiaryId <= 0 || $rate <= 0) {
                    continue;
                }
                $frozen = self::table('relation')->where(['tenant_id' => $tenantId, 'user_id' => $beneficiaryId])->value('is_frozen');
                if ((int)$frozen === 1) {
                    continue;
                }
                $amount = self::amount($paidAmount * $rate / 100);
                if ((float)$amount <= 0) {
                    continue;
                }
                $exists = self::table('commission')->where([
                    'tenant_id' => $tenantId, 'order_type' => $orderType, 'order_sn' => $orderSn,
                    'beneficiary_user_id' => $beneficiaryId, 'level' => $level,
                ])->find();
                if ($exists) {
                    continue;
                }
                $status = (int)$rule['settle_days'] === 0 ? self::COMMISSION_AVAILABLE : self::COMMISSION_PENDING;
                self::table('commission')->insert([
                    'tenant_id' => $tenantId, 'order_type' => $orderType, 'order_sn' => $orderSn,
                    'order_user_id' => $userId, 'package_id' => $packageId, 'beneficiary_user_id' => $beneficiaryId,
                    'level' => $level, 'paid_amount' => self::amount($paidAmount), 'rate' => self::rate($rate),
                    'commission_amount' => $amount, 'status' => $status, 'settle_time' => $settleAt,
                    'rule_source' => $rule['source'], 'create_time' => time(), 'update_time' => time(),
                ]);
                self::changeAccount($tenantId, $beneficiaryId, $status === self::COMMISSION_AVAILABLE ? 'available' : 'pending', (float)$amount,
                    'commission_' . $status, $orderType . ':' . $orderSn . ':' . $level, '订单分销佣金');
            }
        });
    }

    public static function settleDue(int $limit = 500): int
    {
        $rows = self::table('commission')->where('status', self::COMMISSION_PENDING)->where('settle_time', '<=', time())
            ->order('id asc')->limit(max(1, min(2000, $limit)))->select()->toArray();
        $count = 0;
        foreach ($rows as $row) {
            Db::transaction(function () use ($row, &$count) {
                $locked = self::table('commission')->where('id', $row['id'])->lock(true)->find();
                if (!$locked || $locked['status'] !== self::COMMISSION_PENDING || (int)$locked['settle_time'] > time()) {
                    return;
                }
                self::table('commission')->where('id', $locked['id'])->update(['status' => self::COMMISSION_AVAILABLE, 'update_time' => time()]);
                self::changeAccount((int)$locked['tenant_id'], (int)$locked['beneficiary_user_id'], 'pending', -(float)$locked['commission_amount'],
                    'commission_settle_out', 'commission:' . $locked['id'], '待结算佣金到期');
                self::changeAccount((int)$locked['tenant_id'], (int)$locked['beneficiary_user_id'], 'available', (float)$locked['commission_amount'],
                    'commission_settle_in', 'commission:' . $locked['id'], '佣金结算到账');
                $count++;
            });
        }
        return $count;
    }

    public static function reverseRechargeByOrderId(int $orderId): void
    {
        $order = Db::name('recharge_order')->where('id', $orderId)->find();
        if ($order) {
            self::reverseOrder(self::ORDER_RECHARGE, (int)$order['tenant_id'], (string)$order['sn']);
        }
    }

    public static function reverseMembershipByOrderId(int $orderId): void
    {
        $order = Db::name('membership_order')->where('id', $orderId)->find();
        if ($order) {
            self::reverseOrder(self::ORDER_MEMBERSHIP, (int)$order['tenant_id'], (string)$order['order_sn']);
        }
    }

    public static function reverseOrder(string $orderType, int $tenantId, string $orderSn): void
    {
        self::assertOrderType($orderType);
        if ($tenantId <= 0 || $orderSn === '') {
            return;
        }
        $rows = self::table('commission')->where(['tenant_id' => $tenantId, 'order_type' => $orderType, 'order_sn' => $orderSn])
            ->whereIn('status', [self::COMMISSION_PENDING, self::COMMISSION_AVAILABLE, self::COMMISSION_FROZEN])->select()->toArray();
        foreach ($rows as $row) {
            Db::transaction(function () use ($row, $orderType, $orderSn) {
                $locked = self::table('commission')->where('id', $row['id'])->lock(true)->find();
                if (!$locked || !in_array($locked['status'], [self::COMMISSION_PENDING, self::COMMISSION_AVAILABLE, self::COMMISSION_FROZEN], true)) {
                    return;
                }
                $amount = (float)$locked['commission_amount'];
                $accountBucket = $locked['status'] === self::COMMISSION_PENDING ? 'pending' : ($locked['status'] === self::COMMISSION_FROZEN ? 'frozen' : 'available');
                self::table('commission')->where('id', $locked['id'])->update(['status' => self::COMMISSION_REVERSED, 'reverse_time' => time(), 'update_time' => time()]);
                $account = self::account((int)$locked['tenant_id'], (int)$locked['beneficiary_user_id'], true);
                $actual = min((float)$account[$accountBucket . '_amount'], $amount);
                if ($actual > 0) {
                    self::changeAccount((int)$locked['tenant_id'], (int)$locked['beneficiary_user_id'], $accountBucket, -$actual,
                        'commission_reverse', 'commission:' . $locked['id'], '订单退款冲销佣金');
                }
                if ($actual < $amount) {
                    self::changeAccount((int)$locked['tenant_id'], (int)$locked['beneficiary_user_id'], 'negative', $amount - $actual,
                        'commission_reverse_debt', 'commission:' . $locked['id'], '退款冲销待抵扣佣金');
                }
            });
        }
    }

    public static function account(int $tenantId, int $userId, bool $lock = false): array
    {
        $query = self::table('account')->where(['tenant_id' => $tenantId, 'user_id' => $userId]);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if ($row) {
            return $row;
        }
        self::table('account')->insert([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'pending_amount' => '0.00', 'available_amount' => '0.00',
            'frozen_amount' => '0.00', 'negative_amount' => '0.00', 'total_income' => '0.00', 'total_withdrawn' => '0.00',
            'create_time' => time(), 'update_time' => time(),
        ]);
        return self::table('account')->where(['tenant_id' => $tenantId, 'user_id' => $userId])->lock($lock)->find();
    }

    private static function changeAccount(int $tenantId, int $userId, string $bucket, float $delta, string $type, string $source, string $remark): void
    {
        if (!in_array($bucket, ['pending', 'available', 'frozen', 'negative', 'withdrawn'], true)) {
            throw new RuntimeException('佣金账户类型错误');
        }
        $existing = self::table('account_log')->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'change_type' => $type, 'source_sn' => $source])->find();
        if ($existing) {
            return;
        }
        $account = self::account($tenantId, $userId, true);
        $column = $bucket === 'withdrawn' ? 'total_withdrawn' : $bucket . '_amount';
        $after = round((float)$account[$column] + $delta, 2);
        if ($bucket !== 'negative' && $after < -0.0001) {
            throw new RuntimeException('佣金账户余额不足');
        }
        // New income first repays historical refund debt.
        if ($bucket === 'available' && $delta > 0 && (float)$account['negative_amount'] > 0) {
            $offset = min($delta, (float)$account['negative_amount']);
            self::table('account')->where('id', $account['id'])->dec('negative_amount', $offset)->inc('total_income', $delta)->update(['update_time' => time()]);
            self::table('account_log')->insert([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'change_type' => $type, 'bucket' => 'negative',
                'change_amount' => self::amount(-$offset), 'balance_after' => self::amount((float)$account['negative_amount'] - $offset),
                'source_sn' => $source, 'remark' => '抵扣退款负佣金', 'create_time' => time(),
            ]);
            $delta -= $offset;
            $after = round((float)$account['available_amount'] + $delta, 2);
        }
        self::table('account')->where('id', $account['id'])->update([$column => self::amount($after), 'total_income' => $bucket === 'available' && $delta > 0 ? self::amount((float)$account['total_income'] + $delta) : $account['total_income'], 'update_time' => time()]);
        self::table('account_log')->insert([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'change_type' => $type, 'bucket' => $bucket,
            'change_amount' => self::amount($delta), 'balance_after' => self::amount($after),
            'source_sn' => $source, 'remark' => $remark, 'create_time' => time(),
        ]);
    }

    public static function saveWithdrawAccount(int $tenantId, int $userId, array $input): array
    {
        $type = (string)($input['type'] ?? $input['account_type'] ?? '');
        if (!in_array($type, ['alipay', 'wechat', 'bank'], true)) {
            throw new RuntimeException('收款方式不正确');
        }
        $name = trim((string)($input['real_name'] ?? ''));
        $mobile = trim((string)($input['contact_mobile'] ?? $input['mobile'] ?? ''));
        if ($name === '' || $mobile === '') {
            throw new RuntimeException('真实姓名和联系电话不能为空');
        }
        $data = [
            'tenant_id' => $tenantId, 'user_id' => $userId, 'type' => $type, 'real_name' => $name,
            'contact_mobile' => $mobile, 'contact_email' => trim((string)($input['contact_email'] ?? $input['email'] ?? '')),
            'alipay_account' => trim((string)($input['alipay_account'] ?? '')),
            'collection_qr' => trim((string)($input['collection_qr'] ?? $input['qrcode'] ?? '')),
            'bank_card_no' => trim((string)($input['bank_card_no'] ?? '')),
            'bank_name' => trim((string)($input['bank_name'] ?? '')),
            'bank_branch' => trim((string)($input['bank_branch'] ?? '')),
            'bank_province' => trim((string)($input['bank_province'] ?? '')),
            'bank_city' => trim((string)($input['bank_city'] ?? '')),
            'remark' => trim((string)($input['remark'] ?? '')), 'is_default' => !empty($input['is_default']) ? 1 : 0,
            'status' => 1, 'update_time' => time(),
        ];
        if ($type === 'alipay' && $data['alipay_account'] === '') {
            throw new RuntimeException('请输入支付宝账号');
        }
        if ($type === 'wechat' && $data['collection_qr'] === '') {
            throw new RuntimeException('请上传微信收款码');
        }
        if ($type === 'bank' && ($data['bank_card_no'] === '' || $data['bank_name'] === '' || $data['bank_branch'] === '' || $data['bank_province'] === '' || $data['bank_city'] === '')) {
            throw new RuntimeException('请填写完整银行卡开户信息');
        }
        return Db::transaction(function () use ($tenantId, $userId, $input, $data) {
            if ($data['is_default']) {
                self::table('withdraw_account')->where(['tenant_id' => $tenantId, 'user_id' => $userId])->update(['is_default' => 0, 'update_time' => time()]);
            }
            $id = (int)($input['id'] ?? 0);
            $old = $id > 0 ? self::table('withdraw_account')->where(['id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId])->find() : null;
            if ($old) {
                self::table('withdraw_account')->where('id', $id)->update($data);
            } else {
                $data['create_time'] = time();
                self::table('withdraw_account')->insert($data);
                $id = (int)self::table('withdraw_account')->getLastInsID();
            }
            return self::withdrawAccount($tenantId, $userId, $id, false);
        });
    }

    public static function withdrawAccounts(int $tenantId, int $userId, bool $full = false): array
    {
        $rows = self::table('withdraw_account')->where(['tenant_id' => $tenantId, 'user_id' => $userId])->where('status', 1)->order('is_default desc,id desc')->select()->toArray();
        return array_map(static fn ($row) => self::maskWithdrawAccount($row, $full), $rows);
    }

    private static function withdrawAccount(int $tenantId, int $userId, int $id, bool $full): array
    {
        $row = self::table('withdraw_account')->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'id' => $id])->find();
        if (!$row) {
            throw new RuntimeException('收款账户不存在');
        }
        return self::maskWithdrawAccount($row, $full);
    }

    public static function applyWithdrawal(int $tenantId, int $userId, int $withdrawAccountId, float $amount): array
    {
        return Db::transaction(function () use ($tenantId, $userId, $withdrawAccountId, $amount) {
            $config = self::config($tenantId);
            if ((int)$config['status'] !== 1) {
                throw new RuntimeException('分销功能未开启');
            }
            $amount = (float)self::amount($amount);
            if ($amount <= 0 || $amount < (float)$config['min_withdraw_amount']) {
                throw new RuntimeException('提现金额未达到最低提现金额');
            }
            $pending = self::table('withdrawal')->where(['tenant_id' => $tenantId, 'user_id' => $userId])->whereIn('status', [self::WITHDRAW_PENDING, self::WITHDRAW_APPROVED])->find();
            if ($pending) {
                throw new RuntimeException('存在未处理的提现申请');
            }
            $account = self::account($tenantId, $userId, true);
            if ((float)$account['available_amount'] < $amount) {
                throw new RuntimeException('可提现佣金不足');
            }
            $withdrawAccount = self::withdrawAccount($tenantId, $userId, $withdrawAccountId, true);
            $sn = 'DW' . date('YmdHis') . random_int(100000, 999999);
            self::table('withdrawal')->insert([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'sn' => $sn, 'amount' => self::amount($amount),
                'status' => self::WITHDRAW_PENDING, 'withdraw_account_id' => $withdrawAccountId,
                'withdraw_snapshot' => json_encode($withdrawAccount, JSON_UNESCAPED_UNICODE), 'create_time' => time(), 'update_time' => time(),
            ]);
            self::changeAccount($tenantId, $userId, 'available', -$amount, 'withdraw_apply_out', $sn, '申请提现冻结佣金');
            self::changeAccount($tenantId, $userId, 'frozen', $amount, 'withdraw_apply_in', $sn, '申请提现冻结佣金');
            return self::table('withdrawal')->where('sn', $sn)->find();
        });
    }

    public static function reviewWithdrawal(int $tenantId, int $withdrawalId, int $operatorId, string $action, string $reason = '', array $payment = []): array
    {
        if (!in_array($action, ['approve', 'reject', 'paid', 'failed'], true)) {
            throw new RuntimeException('提现操作不正确');
        }
        return Db::transaction(function () use ($tenantId, $withdrawalId, $operatorId, $action, $reason, $payment) {
            $row = self::table('withdrawal')->where(['tenant_id' => $tenantId, 'id' => $withdrawalId])->lock(true)->find();
            if (!$row) {
                throw new RuntimeException('提现申请不存在');
            }
            $amount = (float)$row['amount'];
            if ($action === 'approve' && $row['status'] === self::WITHDRAW_PENDING) {
                self::table('withdrawal')->where('id', $row['id'])->update(['status' => self::WITHDRAW_APPROVED, 'audit_admin_id' => $operatorId, 'audit_time' => time(), 'audit_remark' => $reason, 'update_time' => time()]);
            } elseif ($action === 'reject' && in_array($row['status'], [self::WITHDRAW_PENDING, self::WITHDRAW_APPROVED], true)) {
                self::rejectWithdrawal($row, $operatorId, $reason, self::WITHDRAW_REJECTED);
            } elseif ($action === 'failed' && $row['status'] === self::WITHDRAW_APPROVED) {
                self::rejectWithdrawal($row, $operatorId, $reason, self::WITHDRAW_FAILED);
            } elseif ($action === 'paid' && $row['status'] === self::WITHDRAW_APPROVED) {
                self::table('withdrawal')->where('id', $row['id'])->update([
                    'status' => self::WITHDRAW_PAID, 'pay_admin_id' => $operatorId, 'pay_time' => time(),
                    'payment_sn' => trim((string)($payment['payment_sn'] ?? '')), 'payment_proof' => trim((string)($payment['payment_proof'] ?? '')),
                    'pay_remark' => $reason, 'update_time' => time(),
                ]);
                self::changeAccount($tenantId, (int)$row['user_id'], 'frozen', -$amount, 'withdraw_paid_out', (string)$row['sn'], '人工打款完成');
                self::changeAccount($tenantId, (int)$row['user_id'], 'withdrawn', $amount, 'withdraw_paid_in', (string)$row['sn'], '累计已提现');
            } else {
                throw new RuntimeException('当前提现状态不允许该操作');
            }
            return self::table('withdrawal')->where('id', $row['id'])->find();
        });
    }

    private static function rejectWithdrawal(array $row, int $operatorId, string $reason, string $status): void
    {
        self::table('withdrawal')->where('id', $row['id'])->update([
            'status' => $status, 'audit_admin_id' => $operatorId, 'audit_time' => time(), 'audit_remark' => $reason, 'update_time' => time(),
        ]);
        self::changeAccount((int)$row['tenant_id'], (int)$row['user_id'], 'frozen', -(float)$row['amount'], 'withdraw_return_out', (string)$row['sn'], '提现退回');
        self::changeAccount((int)$row['tenant_id'], (int)$row['user_id'], 'available', (float)$row['amount'], 'withdraw_return_in', (string)$row['sn'], '提现退回可提现余额');
    }

    public static function dashboard(int $tenantId, int $userId = 0): array
    {
        $where = ['tenant_id' => $tenantId];
        if ($userId > 0) {
            $where['beneficiary_user_id'] = $userId;
        }
        $commission = self::table('commission')->where($where);
        $account = $userId > 0 ? self::account($tenantId, $userId) : [];
        return [
            'order_count' => (clone $commission)->count(),
            'commission_total' => (string)((clone $commission)->sum('commission_amount') ?: '0.00'),
            'pending_commission' => (string)((clone $commission)->where('status', self::COMMISSION_PENDING)->sum('commission_amount') ?: '0.00'),
            'available_commission' => (string)((clone $commission)->where('status', self::COMMISSION_AVAILABLE)->sum('commission_amount') ?: '0.00'),
            'account' => $account,
        ];
    }

    public static function userOverview(int $tenantId, int $userId): array
    {
        $relation = self::ensurePromoter($tenantId, $userId);
        $team = [];
        for ($level = 1; $level <= 3; $level++) {
            $team['level' . $level] = self::table('relation')->where(['tenant_id' => $tenantId, 'level' . $level . '_user_id' => $userId])->count();
        }
        return ['relation' => $relation, 'team' => $team, 'dashboard' => self::dashboard($tenantId, $userId), 'config' => self::config($tenantId)];
    }

    public static function commissionRows(int $tenantId, array $filters = [], int $page = 1, int $size = 20, int $userId = 0): array
    {
        $query = self::table('commission')->alias('c')
            ->leftJoin('user buyer', 'buyer.id = c.order_user_id')
            ->leftJoin('user beneficiary', 'beneficiary.id = c.beneficiary_user_id')
            ->where('c.tenant_id', $tenantId)
            ->field('c.*,buyer.nickname as order_user_nickname,buyer.mobile as order_user_mobile,beneficiary.nickname as beneficiary_nickname,beneficiary.mobile as beneficiary_mobile');
        if ($userId > 0) { $query->where('c.beneficiary_user_id', $userId); }
        foreach (['status', 'order_type', 'beneficiary_user_id', 'order_user_id'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') { $query->where('c.' . $key, $filters[$key]); }
        }
        if (($filters['order_sn'] ?? '') !== '') { $query->whereLike('c.order_sn', '%' . trim((string)$filters['order_sn']) . '%'); }
        if (($filters['level'] ?? '') !== '') { $query->where('c.level', (int)$filters['level']); }
        $keyword = trim((string)($filters['user_info'] ?? $filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('buyer.nickname|buyer.account|buyer.mobile|beneficiary.nickname|beneficiary.account|beneficiary.mobile', '%' . $keyword . '%');
        }
        $start = self::filterTime($filters['start_time'] ?? 0);
        $end = self::filterTime($filters['end_time'] ?? 0);
        if ($start > 0) { $query->where('c.create_time', '>=', $start); }
        if ($end > 0) { $query->where('c.create_time', '<=', $end); }
        $count = (clone $query)->count();
        $rows = $query->order('c.id desc')->page(max(1, $page), max(1, min(100, $size)))->select()->toArray();
        foreach ($rows as &$row) {
            $row['order_user_mobile'] = self::mask((string)($row['order_user_mobile'] ?? ''));
            $row['beneficiary_mobile'] = self::mask((string)($row['beneficiary_mobile'] ?? ''));
            $row['nickname'] = $row['beneficiary_nickname'] ?? '';
            $row['order_amount'] = $row['paid_amount'] ?? '0.00';
            $row['pay_amount'] = $row['paid_amount'] ?? '0.00';
            $row['order_type_text'] = ($row['order_type'] ?? '') === self::ORDER_RECHARGE ? '算力充值' : '会员套餐';
            $row['status_text'] = [
                self::COMMISSION_PENDING => '待结算', self::COMMISSION_AVAILABLE => '可提现',
                self::COMMISSION_FROZEN => '冻结', self::COMMISSION_REVERSED => '已冲销',
            ][$row['status'] ?? ''] ?? '';
        }
        return ['count' => $count, 'lists' => $rows];
    }

    public static function withdrawalRows(int $tenantId, int $userId = 0, bool $full = false, int $page = 1, int $size = 20, array $filters = []): array
    {
        $query = self::table('withdrawal')->alias('w')->leftJoin('user u', 'u.id = w.user_id')
            ->where('w.tenant_id', $tenantId)->field('w.*,u.nickname,u.mobile,u.account,u.avatar as user_avatar,u.nickname as user_nickname,u.mobile as user_mobile');
        if ($userId > 0) { $query->where('w.user_id', $userId); }
        if (($filters['status'] ?? '') !== '') { $query->where('w.status', (string)$filters['status']); }
        if (($filters['withdraw_type'] ?? '') !== '') {
            $query->whereLike('w.withdraw_snapshot', '%\"type\":\"' . addslashes((string)$filters['withdraw_type']) . '\"%');
        }
        $keyword = trim((string)($filters['user_info'] ?? $filters['keyword'] ?? ''));
        if ($keyword !== '') { $query->whereLike('u.nickname|u.account|u.mobile|w.sn', '%' . $keyword . '%'); }
        $start = self::filterTime($filters['start_time'] ?? 0);
        $end = self::filterTime($filters['end_time'] ?? 0);
        if ($start > 0) { $query->where('w.create_time', '>=', $start); }
        if ($end > 0) { $query->where('w.create_time', '<=', $end); }
        $count = (clone $query)->count();
        $rows = $query->order('w.id desc')->page(max(1, $page), max(1, min(100, $size)))->select()->toArray();
        foreach ($rows as &$row) {
            $snapshot = json_decode((string)$row['withdraw_snapshot'], true) ?: [];
            $row['withdraw_snapshot'] = self::maskWithdrawAccount($snapshot, $full);
            if (!$full) { $row['user_mobile'] = self::mask((string)($row['user_mobile'] ?? '')); }
        }
        return ['count' => $count, 'lists' => $rows];
    }

    private static function filterTime($value): int
    {
        if (is_numeric($value)) {
            return (int)$value;
        }
        $time = strtotime((string)$value);
        return $time === false ? 0 : $time;
    }

    private static function maskWithdrawAccount(array $row, bool $full): array
    {
        if ($full) {
            $row['mobile'] = $row['contact_mobile'] ?? '';
            $row['email'] = $row['contact_email'] ?? '';
            $row['qrcode'] = $row['collection_qr'] ?? '';
            return $row;
        }
        foreach (['alipay_account', 'bank_card_no', 'contact_mobile', 'contact_email'] as $field) {
            if (!empty($row[$field])) { $row[$field] = self::mask((string)$row[$field]); }
        }
        if (!empty($row['collection_qr'])) { $row['collection_qr'] = ''; $row['has_collection_qr'] = 1; }
        return $row;
    }

    private static function mask(string $value): string
    {
        $length = mb_strlen($value);
        if ($length <= 4) { return str_repeat('*', $length); }
        return mb_substr($value, 0, 2) . str_repeat('*', max(1, $length - 4)) . mb_substr($value, -2);
    }

    private static function chain(array $relation): array
    {
        return [(int)($relation['level1_user_id'] ?? 0), (int)($relation['level2_user_id'] ?? 0), (int)($relation['level3_user_id'] ?? 0)];
    }

    private static function assertOrderType(string $type): void
    {
        if (!in_array($type, [self::ORDER_RECHARGE, self::ORDER_MEMBERSHIP], true)) {
            throw new RuntimeException('不支持的分销订单类型');
        }
    }

    private static function assertRates(array $data): void
    {
        for ($i = 1; $i <= 3; $i++) {
            if ((float)($data['level' . $i . '_rate'] ?? 0) < 0 || (float)($data['level' . $i . '_rate'] ?? 0) > 100) {
                throw new RuntimeException('佣金比例必须在0到100之间');
            }
        }
    }

    private static function amount($value): string { return number_format(round((float)$value, 2), 2, '.', ''); }
    private static function rate($value): string { return number_format(round((float)$value, 4), 4, '.', ''); }
}
