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
                'duration_months' => 1,
                'monthly_price' => '0.00',
                'yearly_price' => '0.00',
                'monthly_market_price' => '0.00',
                'yearly_market_price' => '0.00',
                'monthly_bonus_points' => '0.00',
                'yearly_bonus_points' => '0.00',
                'features' => '["默认AIGC应用永久免费使用","可购买算力继续创作","会员权益可由租户继续调整"]',
                'is_recommend' => 0,
                'sort' => 100,
            ],
            [
                'name' => '基础会员',
                'description' => '适合轻量创作用户，赠送基础算力',
                'duration_months' => 1,
                'monthly_price' => '19.90',
                'yearly_price' => '19.90',
                'monthly_market_price' => '29.90',
                'yearly_market_price' => '29.90',
                'monthly_bonus_points' => '100.00',
                'yearly_bonus_points' => '100.00',
                'features' => '["开通赠送100算力","会员有效期1个月","适合个人轻量创作"]',
                'is_recommend' => 0,
                'sort' => 90,
            ],
            [
                'name' => '高级会员',
                'description' => '适合高频创作用户，赠送更多算力',
                'duration_months' => 1,
                'monthly_price' => '39.90',
                'yearly_price' => '39.90',
                'monthly_market_price' => '69.90',
                'yearly_market_price' => '69.90',
                'monthly_bonus_points' => '300.00',
                'yearly_bonus_points' => '300.00',
                'features' => '["开通赠送300算力","会员有效期1个月","适合高频图文与视频创作"]',
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

        self::syncDistributionMenus($table, $tenantId);

        $brandId = self::upsertMenu($table, $tenantId, [
            'pid' => 0,
            'type' => 'M',
            'name' => '贴牌管理',
            'icon' => 'el-icon-Connection',
            'sort' => 650,
            'paths' => 'brand',
            'source_menu_key' => 'core_tenant_brand',
        ]);
        $brandPackageId = self::upsertMenu($table, $tenantId, [
            'pid' => $brandId,
            'type' => 'C',
            'name' => '贴牌套餐',
            'icon' => 'el-icon-PriceTag',
            'sort' => 100,
            'perms' => 'brand.package/lists',
            'paths' => 'package',
            'component' => 'brand/package',
            'source_menu_key' => 'core_tenant_brand_package',
        ]);
        self::upsertMenu($table, $tenantId, ['pid' => $brandPackageId, 'type' => 'A', 'name' => '保存定价', 'perms' => 'brand.package/savePrice', 'source_menu_key' => 'core_tenant_brand_package_save']);

        $brandQuotaId = self::upsertMenu($table, $tenantId, [
            'pid' => $brandId,
            'type' => 'C',
            'name' => '额度购买',
            'icon' => 'el-icon-ShoppingCart',
            'sort' => 90,
            'perms' => 'brand.quota/orders',
            'paths' => 'quota',
            'component' => 'brand/quota',
            'source_menu_key' => 'core_tenant_brand_quota',
        ]);
        self::upsertMenu($table, $tenantId, ['pid' => $brandQuotaId, 'type' => 'A', 'name' => '套餐列表', 'perms' => 'brand.quota/packages', 'source_menu_key' => 'core_tenant_brand_quota_packages']);
        self::upsertMenu($table, $tenantId, ['pid' => $brandQuotaId, 'type' => 'A', 'name' => '创建订单', 'perms' => 'brand.quota/createOrder', 'source_menu_key' => 'core_tenant_brand_quota_create']);
        self::upsertMenu($table, $tenantId, ['pid' => $brandQuotaId, 'type' => 'A', 'name' => '支付方式', 'perms' => 'brand.pay/payWay', 'source_menu_key' => 'core_tenant_brand_pay_way']);
        self::upsertMenu($table, $tenantId, ['pid' => $brandQuotaId, 'type' => 'A', 'name' => '预支付', 'perms' => 'brand.pay/prepay', 'source_menu_key' => 'core_tenant_brand_pay_prepay']);
        self::upsertMenu($table, $tenantId, ['pid' => $brandQuotaId, 'type' => 'A', 'name' => '支付状态', 'perms' => 'brand.pay/payStatus', 'source_menu_key' => 'core_tenant_brand_pay_status']);

        self::upsertMenu($table, $tenantId, [
            'pid' => $brandId,
            'type' => 'C',
            'name' => '订单管理',
            'icon' => 'el-icon-Document',
            'sort' => 80,
            'perms' => 'brand.order/lists',
            'paths' => 'order',
            'component' => 'brand/order',
            'source_menu_key' => 'core_tenant_brand_order',
        ]);

        $officialSiteId = self::upsertMenu($table, $tenantId, [
            'pid' => 0,
            'type' => 'M',
            'name' => '官方网站',
            'icon' => 'el-icon-Monitor',
            'sort' => 110,
            'paths' => 'official-site',
            'source_menu_key' => 'core_tenant_official_site',
        ]);
        $officialConfigId = self::upsertMenu($table, $tenantId, [
            'pid' => $officialSiteId,
            'type' => 'C',
            'name' => '官网配置',
            'sort' => 100,
            'perms' => 'setting.web.official_site/get',
            'paths' => 'official-site',
            'component' => 'official_website/index',
            'source_menu_key' => 'core_tenant_official_site_config',
        ]);
        self::upsertMenu($table, $tenantId, [
            'pid' => $officialConfigId,
            'type' => 'A',
            'name' => '保存',
            'perms' => 'setting.web.official_site/save',
            'source_menu_key' => 'core_tenant_official_site_save',
        ]);
    }

    /**
     * Keep the distribution menu tree stable across upgrades. Earlier releases
     * put all business pages directly below the top-level module; their stable
     * source keys are reused here so role grants remain attached to the pages.
     */
    private static function syncDistributionMenus(string $table, int $tenantId): void
    {
        $distributionId = self::upsertMenu($table, $tenantId, [
            'pid' => 0,
            'type' => 'M',
            'name' => '分销管理',
            'icon' => 'el-icon-Share',
            'sort' => 640,
            'paths' => 'distribution',
            'source_menu_key' => 'core_tenant_distribution',
        ]);

        $groups = [
            'config' => ['name' => '配置', 'icon' => 'el-icon-Setting', 'sort' => 100, 'paths' => 'configuration'],
            'promotion' => ['name' => '推广', 'icon' => 'el-icon-Share', 'sort' => 90, 'paths' => 'promotion'],
            'finance' => ['name' => '财务', 'icon' => 'el-icon-Wallet', 'sort' => 80, 'paths' => 'finance'],
        ];
        $groupIds = [];
        foreach ($groups as $key => $group) {
            $groupIds[$key] = self::upsertMenu($table, $tenantId, [
                'pid' => $distributionId,
                'type' => 'M',
                'name' => $group['name'],
                'icon' => $group['icon'],
                'sort' => $group['sort'],
                'paths' => $group['paths'],
                'source_menu_key' => 'core_tenant_distribution_group_' . $key,
            ]);
        }

        $distributionMenus = [
            ['group' => 'config', 'name' => '分销设置', 'icon' => 'el-icon-Setting', 'sort' => 100, 'perms' => 'distribution.distribution/config', 'paths' => 'settings', 'component' => 'distribution/settings', 'key' => 'settings'],
            ['group' => 'config', 'name' => '套餐分销', 'icon' => 'el-icon-Tickets', 'sort' => 90, 'perms' => 'distribution.distribution/packageRules', 'paths' => 'package-rule', 'component' => 'distribution/package_rule', 'key' => 'package_rule'],
            ['group' => 'promotion', 'name' => '分销概览', 'icon' => 'el-icon-DataAnalysis', 'sort' => 100, 'perms' => 'distribution.distribution/overview', 'paths' => 'overview', 'component' => 'distribution/overview', 'key' => 'overview'],
            ['group' => 'promotion', 'name' => '推广员管理', 'icon' => 'el-icon-User', 'sort' => 90, 'perms' => 'distribution.distribution/promoters', 'paths' => 'promoter', 'component' => 'distribution/promoter', 'key' => 'promoter'],
            ['group' => 'promotion', 'name' => '分销关系', 'icon' => 'el-icon-Connection', 'sort' => 80, 'perms' => 'distribution.distribution/relations', 'paths' => 'relation', 'component' => 'distribution/relation', 'key' => 'relation'],
            ['group' => 'finance', 'name' => '分销订单', 'icon' => 'el-icon-Document', 'sort' => 100, 'perms' => 'distribution.distribution/orders', 'paths' => 'order', 'component' => 'distribution/order', 'key' => 'order'],
            ['group' => 'finance', 'name' => '佣金明细', 'icon' => 'el-icon-Coin', 'sort' => 90, 'perms' => 'distribution.distribution/commissions', 'paths' => 'commission', 'component' => 'distribution/commission', 'key' => 'commission'],
            ['group' => 'finance', 'name' => '提现管理', 'icon' => 'el-icon-Wallet', 'sort' => 80, 'perms' => 'distribution.distribution/withdrawals', 'paths' => 'withdrawal', 'component' => 'distribution/withdrawal', 'key' => 'withdrawal'],
        ];
        $pageIdsByGroup = ['config' => [], 'promotion' => [], 'finance' => []];
        foreach ($distributionMenus as $distributionMenu) {
            $menuId = self::upsertMenu($table, $tenantId, [
                'pid' => $groupIds[$distributionMenu['group']],
                'type' => 'C',
                'name' => $distributionMenu['name'],
                'icon' => $distributionMenu['icon'],
                'sort' => $distributionMenu['sort'],
                'perms' => $distributionMenu['perms'],
                'paths' => $distributionMenu['paths'],
                'component' => $distributionMenu['component'],
                'source_menu_key' => 'core_tenant_distribution_' . $distributionMenu['key'],
            ]);
            $pageIdsByGroup[$distributionMenu['group']][] = $menuId;
            if ($distributionMenu['key'] === 'settings') {
                self::upsertMenu($table, $tenantId, ['pid' => $menuId, 'type' => 'A', 'name' => '保存', 'perms' => 'distribution.distribution/saveConfig', 'source_menu_key' => 'core_tenant_distribution_save_config']);
            } elseif ($distributionMenu['key'] === 'package_rule') {
                self::upsertMenu($table, $tenantId, ['pid' => $menuId, 'type' => 'A', 'name' => '保存规则', 'perms' => 'distribution.distribution/savePackageRule', 'source_menu_key' => 'core_tenant_distribution_save_package_rule']);
            } elseif ($distributionMenu['key'] === 'relation') {
                self::upsertMenu($table, $tenantId, ['pid' => $menuId, 'type' => 'A', 'name' => '调整关系', 'perms' => 'distribution.distribution/adjustRelation', 'source_menu_key' => 'core_tenant_distribution_adjust_relation']);
            } elseif ($distributionMenu['key'] === 'withdrawal') {
                self::upsertMenu($table, $tenantId, ['pid' => $menuId, 'type' => 'A', 'name' => '审核提现', 'perms' => 'distribution.distribution/review', 'source_menu_key' => 'core_tenant_distribution_review']);
                self::upsertMenu($table, $tenantId, ['pid' => $menuId, 'type' => 'A', 'name' => '审核提现接口', 'perms' => 'distribution.distribution/reviewWithdrawal', 'source_menu_key' => 'core_tenant_distribution_review_withdrawal']);
                self::upsertMenu($table, $tenantId, ['pid' => $menuId, 'type' => 'A', 'name' => '查看收款资料', 'perms' => 'distribution.distribution/withdrawalDetail', 'source_menu_key' => 'core_tenant_distribution_withdrawal_detail']);
            }
        }
        self::grantDistributionTreeRoles($table, $distributionId, $groupIds, $pageIdsByGroup);
        self::clearStaleDistributionDirectMenus($table, $tenantId, $distributionId, array_values($groupIds));
    }

    /** Preserve non-root role visibility after pages are moved under new groups. */
    private static function grantDistributionTreeRoles(string $menuTable, int $distributionId, array $groupIds, array $pageIdsByGroup): void
    {
        $roleTable = str_replace('tenant_system_menu', 'tenant_system_role_menu', $menuTable);
        if (!self::tableExists($roleTable)) {
            return;
        }
        $roleTargets = [];
        foreach ($pageIdsByGroup as $group => $pageIds) {
            if (empty($pageIds)) {
                continue;
            }
            foreach (Db::name($roleTable)->whereIn('menu_id', $pageIds)->column('role_id') as $roleId) {
                $roleTargets[(int)$roleId][$distributionId] = true;
                $roleTargets[(int)$roleId][(int)$groupIds[$group]] = true;
            }
        }
        // Roles that already owned the former root retain all new parent groups.
        foreach (Db::name($roleTable)->where('menu_id', $distributionId)->column('role_id') as $roleId) {
            $roleTargets[(int)$roleId][$distributionId] = true;
            foreach ($groupIds as $groupId) {
                $roleTargets[(int)$roleId][(int)$groupId] = true;
            }
        }
        foreach ($roleTargets as $roleId => $menuIds) {
            foreach (array_keys($menuIds) as $menuId) {
                if (Db::name($roleTable)->where(['role_id' => $roleId, 'menu_id' => $menuId])->count() === 0) {
                    Db::name($roleTable)->insert(['role_id' => $roleId, 'menu_id' => $menuId]);
                }
            }
        }
    }

    /** Remove legacy core pages still directly hanging from 分销管理, never tenant-owned menus. */
    private static function clearStaleDistributionDirectMenus(string $table, int $tenantId, int $distributionId, array $groupIds): void
    {
        $staleIds = Db::name($table)
            ->where('tenant_id', $tenantId)
            ->where('pid', $distributionId)
            ->where('source', '<>', 'tenant')
            ->whereNotIn('id', $groupIds)
            ->column('id');
        if (empty($staleIds)) {
            return;
        }
        $allIds = $staleIds;
        $children = $staleIds;
        while (!empty($children)) {
            $children = Db::name($table)->where('tenant_id', $tenantId)->whereIn('pid', $children)->column('id');
            if (empty($children)) {
                break;
            }
            $allIds = array_merge($allIds, $children);
        }
        $roleTable = str_replace('tenant_system_menu', 'tenant_system_role_menu', $table);
        if (self::tableExists($roleTable)) {
            Db::name($roleTable)->whereIn('menu_id', $allIds)->delete();
        }
        Db::name($table)->whereIn('id', $allIds)->delete();
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
