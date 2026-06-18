<?php

namespace app\common\service\billing;

use app\common\model\tenant\Tenant;
use think\facade\Db;
use Throwable;

class PackageProvisionService
{
    private const MENU_TIME = 1778000000;

    public static function syncAllTenants(): void
    {
        self::ensureRechargeOrderColumns();
        self::syncMenus(0, 'tenant_system_menu');

        $tenants = Tenant::field('id,sn,tactics')->select()->toArray();
        foreach ($tenants as $tenant) {
            $tenantId = (int)$tenant['id'];
            self::syncTenant($tenantId, (string)($tenant['sn'] ?? ''), (int)($tenant['tactics'] ?? 0) === 1);
        }
    }

    public static function syncTenant(int $tenantId, string $tenantSn = '', bool $isSplitTable = false): void
    {
        if ($tenantId <= 0) {
            return;
        }

        self::ensureRechargeOrderColumns();
        self::syncDefaultPlans($tenantId);
        self::syncRechargePackages($tenantId);

        $table = $isSplitTable && $tenantSn !== '' ? 'tenant_system_menu_' . $tenantSn : 'tenant_system_menu';
        self::syncMenus($tenantId, $table);
    }

    private static function syncDefaultPlans(int $tenantId): void
    {
        if (Db::name('membership_plan')->where('tenant_id', $tenantId)->count() > 0) {
            return;
        }

        $plans = [
            [
                'name' => '免费会员',
                'description' => '系统默认免费会员，默认AIGC应用可直接使用',
                'monthly_price' => '0.00',
                'yearly_price' => '0.00',
                'monthly_market_price' => '0.00',
                'yearly_market_price' => '0.00',
                'monthly_bonus_points' => '0.00',
                'yearly_bonus_points' => '0.00',
                'features' => '["默认AIGC应用永久免费使用","可购买积分继续创作","会员权益可由租户继续调整"]',
                'is_recommend' => 0,
                'sort' => 100,
            ],
            [
                'name' => '基础会员',
                'description' => '适合轻量创作用户，赠送基础积分',
                'monthly_price' => '19.90',
                'yearly_price' => '199.00',
                'monthly_market_price' => '29.90',
                'yearly_market_price' => '299.00',
                'monthly_bonus_points' => '100.00',
                'yearly_bonus_points' => '1500.00',
                'features' => '["每月赠送100积分","按年开通赠送1500积分","适合个人轻量创作"]',
                'is_recommend' => 0,
                'sort' => 90,
            ],
            [
                'name' => '高级会员',
                'description' => '适合高频创作用户，赠送更多积分',
                'monthly_price' => '39.90',
                'yearly_price' => '399.00',
                'monthly_market_price' => '69.90',
                'yearly_market_price' => '699.00',
                'monthly_bonus_points' => '300.00',
                'yearly_bonus_points' => '4200.00',
                'features' => '["每月赠送300积分","按年开通赠送4200积分","适合高频图文与视频创作"]',
                'is_recommend' => 1,
                'sort' => 80,
            ],
        ];

        foreach ($plans as $plan) {
            Db::name('membership_plan')->insert(array_merge($plan, [
                'tenant_id' => $tenantId,
                'status' => 1,
                'create_time' => time(),
                'update_time' => time(),
            ]));
        }
    }

    private static function syncRechargePackages(int $tenantId): void
    {
        if (Db::name('recharge_package')->where('tenant_id', $tenantId)->count() > 0) {
            return;
        }

        $packages = [
            ['name' => '体验包', 'points' => '10.00', 'amount' => '10.00', 'market_amount' => '0.00', 'is_recommend' => 0, 'sort' => 100],
            ['name' => '轻量包', 'points' => '30.00', 'amount' => '30.00', 'market_amount' => '0.00', 'is_recommend' => 0, 'sort' => 90],
            ['name' => '标准包', 'points' => '50.00', 'amount' => '50.00', 'market_amount' => '0.00', 'is_recommend' => 0, 'sort' => 80],
            ['name' => '进阶包', 'points' => '100.00', 'amount' => '100.00', 'market_amount' => '0.00', 'is_recommend' => 1, 'sort' => 70],
            ['name' => '专业包', 'points' => '300.00', 'amount' => '300.00', 'market_amount' => '0.00', 'is_recommend' => 0, 'sort' => 60],
            ['name' => '团队包', 'points' => '500.00', 'amount' => '500.00', 'market_amount' => '0.00', 'is_recommend' => 0, 'sort' => 50],
        ];

        foreach ($packages as $package) {
            Db::name('recharge_package')->insert(array_merge($package, [
                'tenant_id' => $tenantId,
                'status' => 1,
                'create_time' => time(),
                'update_time' => time(),
            ]));
        }
    }

    private static function syncMenus(int $tenantId, string $table): void
    {
        if (!self::tableExists($table)) {
            return;
        }
        self::ensureMenuColumns($table);

        $financeId = self::upsertMenu($table, $tenantId, [
            'pid' => 0,
            'type' => 'M',
            'name' => '财务管理',
            'icon' => 'local-icon-user_gaikuang',
            'sort' => 700,
            'paths' => 'finance',
            'source_menu_key' => 'core_tenant_finance',
        ]);

        $packageId = self::upsertMenu($table, $tenantId, [
            'pid' => $financeId,
            'type' => 'M',
            'name' => '套餐管理',
            'icon' => 'el-icon-Tickets',
            'sort' => 110,
            'paths' => 'package',
            'source_menu_key' => 'core_tenant_package',
        ]);

        $membershipId = self::upsertMenu($table, $tenantId, [
            'pid' => $packageId,
            'type' => 'C',
            'name' => '会员套餐',
            'icon' => 'el-icon-Medal',
            'sort' => 90,
            'perms' => 'finance.membership_plan/lists',
            'paths' => 'membership_plan',
            'component' => 'finance/membership_plan',
            'source_menu_key' => 'core_tenant_membership_plan',
        ]);
        self::upsertMenu($table, $tenantId, ['pid' => $membershipId, 'type' => 'A', 'name' => '新增', 'perms' => 'finance.membership_plan/add', 'source_menu_key' => 'core_tenant_membership_plan_add']);
        self::upsertMenu($table, $tenantId, ['pid' => $membershipId, 'type' => 'A', 'name' => '编辑', 'perms' => 'finance.membership_plan/edit', 'source_menu_key' => 'core_tenant_membership_plan_edit']);
        self::upsertMenu($table, $tenantId, ['pid' => $membershipId, 'type' => 'A', 'name' => '删除', 'perms' => 'finance.membership_plan/delete', 'source_menu_key' => 'core_tenant_membership_plan_delete']);
        self::upsertMenu($table, $tenantId, ['pid' => $membershipId, 'type' => 'A', 'name' => '详情', 'perms' => 'finance.membership_plan/detail', 'source_menu_key' => 'core_tenant_membership_plan_detail']);
        self::upsertMenu($table, $tenantId, ['pid' => $membershipId, 'type' => 'A', 'name' => '可关联应用', 'perms' => 'finance.membership_plan/apps', 'source_menu_key' => 'core_tenant_membership_plan_apps']);

        $rechargeId = self::upsertMenu($table, $tenantId, [
            'pid' => $packageId,
            'type' => 'C',
            'name' => '算力套餐',
            'icon' => 'el-icon-Coin',
            'sort' => 100,
            'perms' => 'finance.recharge_package/lists',
            'paths' => 'recharge_package',
            'component' => 'finance/recharge_package',
            'source_menu_key' => 'core_tenant_recharge_package',
        ]);
        self::upsertMenu($table, $tenantId, ['pid' => $rechargeId, 'type' => 'A', 'name' => '新增', 'perms' => 'finance.recharge_package/add', 'source_menu_key' => 'core_tenant_recharge_package_add']);
        self::upsertMenu($table, $tenantId, ['pid' => $rechargeId, 'type' => 'A', 'name' => '编辑', 'perms' => 'finance.recharge_package/edit', 'source_menu_key' => 'core_tenant_recharge_package_edit']);
        self::upsertMenu($table, $tenantId, ['pid' => $rechargeId, 'type' => 'A', 'name' => '删除', 'perms' => 'finance.recharge_package/delete', 'source_menu_key' => 'core_tenant_recharge_package_delete']);
        self::upsertMenu($table, $tenantId, ['pid' => $rechargeId, 'type' => 'A', 'name' => '详情', 'perms' => 'finance.recharge_package/detail', 'source_menu_key' => 'core_tenant_recharge_package_detail']);

        $orderId = self::upsertMenu($table, $tenantId, [
            'pid' => $financeId,
            'type' => 'C',
            'name' => '订单管理',
            'icon' => 'el-icon-Document',
            'sort' => 105,
            'perms' => 'finance.membership_order/lists',
            'paths' => 'membership_order',
            'component' => 'finance/membership_order',
            'source_menu_key' => 'core_tenant_membership_order',
        ]);
        self::upsertMenu($table, $tenantId, ['pid' => $orderId, 'type' => 'A', 'name' => '详情', 'perms' => 'finance.membership_order/detail', 'source_menu_key' => 'core_tenant_membership_order_detail']);
    }

    private static function upsertMenu(string $table, int $tenantId, array $menu): int
    {
        $time = time();
        $defaults = [
            'pid' => 0,
            'type' => 'C',
            'name' => '',
            'icon' => '',
            'sort' => 0,
            'perms' => '',
            'paths' => '',
            'component' => '',
            'selected' => '',
            'params' => '',
            'is_cache' => 0,
            'is_show' => 1,
            'is_disable' => 0,
            'app_code' => '',
            'source' => 'core',
            'source_menu_key' => '',
            'is_core' => 1,
        ];
        $data = array_merge($defaults, $menu, [
            'tenant_id' => $tenantId,
            'update_time' => $time,
        ]);

        $row = self::findMenu($table, $tenantId, $data);
        if (empty($row)) {
            $data['create_time'] = $data['create_time'] ?? self::MENU_TIME;
            return (int)Db::name($table)->insertGetId($data);
        }

        Db::name($table)->where('id', (int)$row['id'])->update($data);
        return (int)$row['id'];
    }

    private static function findMenu(string $table, int $tenantId, array $menu): array
    {
        if (!empty($menu['source_menu_key'])) {
            $row = Db::name($table)
                ->where(['tenant_id' => $tenantId, 'source_menu_key' => $menu['source_menu_key']])
                ->find();
            if (!empty($row)) {
                return $row;
            }
        }

        $query = Db::name($table)->where('tenant_id', $tenantId)->where('name', $menu['name'])->where('type', $menu['type']);
        if ($menu['perms'] !== '') {
            $query->where('perms', $menu['perms']);
        } elseif ($menu['paths'] !== '') {
            $query->where('paths', $menu['paths']);
        } else {
            $query->where('pid', (int)$menu['pid']);
        }
        return $query->find() ?: [];
    }

    private static function tableExists(string $table): bool
    {
        $full = self::fullTableName($table);
        try {
            return !empty(Db::query("SHOW TABLES LIKE '" . addslashes($full) . "'"));
        } catch (Throwable) {
            return false;
        }
    }

    private static function ensureMenuColumns(string $table): void
    {
        $columns = [
            'app_code' => "varchar(64) NOT NULL DEFAULT '' COMMENT '应用标识'",
            'source' => "varchar(20) NOT NULL DEFAULT 'core' COMMENT '菜单来源'",
            'source_menu_key' => "varchar(120) NOT NULL DEFAULT '' COMMENT '来源菜单key'",
            'is_core' => "tinyint NOT NULL DEFAULT 1 COMMENT '是否核心菜单'",
        ];
        $full = self::fullTableName($table);
        foreach ($columns as $column => $definition) {
            try {
                if (!empty(Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $full) . "` LIKE '" . addslashes($column) . "'"))) {
                    continue;
                }
                Db::execute('ALTER TABLE `' . str_replace('`', '``', $full) . '` ADD COLUMN `' . $column . '` ' . $definition);
            } catch (Throwable) {
            }
        }
    }

    private static function ensureRechargeOrderColumns(): void
    {
        if (!self::tableExists('recharge_order')) {
            return;
        }
        $columns = [
            'recharge_points' => "decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '到账点数' AFTER `order_amount`",
            'package_id' => "int unsigned NOT NULL DEFAULT 0 COMMENT '充值套餐ID' AFTER `recharge_points`",
            'package_name' => "varchar(100) NOT NULL DEFAULT '' COMMENT '充值套餐名称' AFTER `package_id`",
        ];
        $full = self::fullTableName('recharge_order');
        foreach ($columns as $column => $definition) {
            try {
                if (!empty(Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $full) . "` LIKE '" . addslashes($column) . "'"))) {
                    continue;
                }
                Db::execute('ALTER TABLE `' . str_replace('`', '``', $full) . '` ADD COLUMN `' . $column . '` ' . $definition);
            } catch (Throwable) {
            }
        }
    }

    private static function fullTableName(string $table): string
    {
        $prefix = (string)config('database.connections.mysql.prefix', env('database.prefix', 'la_'));
        return str_starts_with($table, $prefix) ? $table : $prefix . $table;
    }
}
