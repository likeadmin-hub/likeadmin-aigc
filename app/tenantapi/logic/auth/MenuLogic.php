<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | 欢迎阅读学习系统程序代码，建议反馈是我们前进的动力
// | 开源版本可自由商用，可去除界面版权logo
// | gitee下载：https://gitee.com/likeshop_gitee/likeadmin
// | github下载：https://github.com/likeshop-github/likeadmin
// | 访问官网：https://www.likeadmin.cn
// | likeadmin团队 版权所有 拥有最终解释权
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------

namespace app\tenantapi\logic\auth;


use app\common\enum\YesNoEnum;
use app\common\logic\BaseLogic;
use app\common\model\auth\TenantAdmin;
use app\common\model\auth\TenantSystemMenu;
use app\common\model\auth\TenantSystemRoleMenu;
use app\common\model\tenant\Tenant;
use app\common\service\billing\PackageProvisionService;
use think\facade\Db;
use Throwable;


/**
 * 系统菜单
 * Class MenuLogic
 * @package app\tenantapi\logic\auth
 */
class MenuLogic extends BaseLogic
{


    /**
     * @notes 获取管理员对应的角色菜单
     * @param $adminId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/7/1 10:50
     */
    public static function getMenuByAdminId($adminId)
    {
        $admin = TenantAdmin::findOrEmpty($adminId);
        $tenantId = 0;
        if (!$admin->isEmpty()) {
            $tenantId = (int)$admin['tenant_id'];
            self::ensurePackageMenus($tenantId);
            self::ensureSystemDefaultMenu($tenantId);
            self::ensureCaseGalleryMenu($tenantId);
        }

        $where = [];
        if ($tenantId > 0) {
            $where[] = ['tenant_id', '=', $tenantId];
        }
        $where[] = ['type', 'in', ['M', 'C']];
        $where[] = ['is_disable', '=', 0];

        if ($admin['root'] != 1) {
            $roleMenu = TenantSystemRoleMenu::whereIn('role_id', $admin['role_id'])->column('menu_id');
            $where[] = ['id', 'in', $roleMenu];
        }

        $menu = TenantSystemMenu::where($where)
            ->order(['sort' => 'desc', 'id' => 'asc'])
            ->select()
            ->toArray();

        return self::sortTopLevelTailMenus(linear_to_tree($menu, 'children'));
    }

    private static function ensurePackageMenus(int $tenantId): void
    {
        if ($tenantId <= 0) {
            return;
        }
        $exists = TenantSystemMenu::where([
            'tenant_id' => $tenantId,
            'source_menu_key' => 'core_tenant_package',
        ])->count();
        if ($exists) {
            return;
        }

        $tenant = Tenant::where('id', $tenantId)->field('id,sn,tactics')->findOrEmpty();
        if ($tenant->isEmpty()) {
            return;
        }
        PackageProvisionService::syncTenant(
            $tenantId,
            (string)$tenant['sn'],
            (int)$tenant['tactics'] === 1
        );
    }

    private static function ensureSystemDefaultMenu(int $tenantId): void
    {
        if ($tenantId <= 0) {
            return;
        }

        $tables = self::tenantMenuTables($tenantId);
        $menuTable = $tables['menu'];
        $roleMenuTable = $tables['role_menu'];
        if (!self::tableExists($menuTable)) {
            return;
        }
        self::ensureMenuSourceColumns($menuTable);

        try {
            $appCenterId = self::upsertSystemMenu($menuTable, $tenantId, [
                'pid' => 0,
                'type' => 'M',
                'name' => '应用管理',
                'icon' => 'el-icon-Grid',
                'sort' => 60,
                'paths' => 'apps',
                'app_code' => '',
                'source_menu_key' => 'core_tenant_app_center',
            ], 9000);

            $systemId = self::upsertSystemMenu($menuTable, $tenantId, [
                'pid' => $appCenterId,
                'type' => 'M',
                'name' => '系统应用',
                'icon' => 'el-icon-Setting',
                'sort' => 10,
                'paths' => 'system-default',
                'app_code' => 'system_default',
                'source_menu_key' => 'core_tenant_system_default',
            ], 158);
            self::grantChildMenuToParentRoles($roleMenuTable, $appCenterId, $systemId);
            self::grantParentMenuToChildRoles($roleMenuTable, $systemId, $appCenterId);

            foreach (self::systemDefaultMenuTree($systemId) as $menu) {
                self::upsertSystemMenuTree($menuTable, $roleMenuTable, $tenantId, $menu, $systemId);
            }
        } catch (Throwable) {
        }
    }

    private static function ensureCaseGalleryMenu(int $tenantId): void
    {
        if ($tenantId <= 0) {
            return;
        }

        $tables = self::tenantMenuTables($tenantId);
        $menuTable = $tables['menu'];
        $roleMenuTable = $tables['role_menu'];
        if (!self::tableExists($menuTable)) {
            return;
        }
        self::ensureMenuSourceColumns($menuTable);

        try {
            foreach (['aigc_image_case', 'aigc_video_case', 'aigc_digital_human_case'] as $legacyKey) {
                Db::name($menuTable)
                    ->where(['tenant_id' => $tenantId, 'source_menu_key' => $legacyKey])
                    ->where('source', '<>', 'tenant')
                    ->delete();
            }

            $caseGalleryId = self::upsertSystemMenu($menuTable, $tenantId, [
                'pid' => 0,
                'type' => 'C',
                'name' => '案例广场',
                'icon' => 'el-icon-PictureFilled',
                'sort' => 98,
                'perms' => 'case_gallery.case/lists',
                'paths' => 'case-gallery',
                'component' => 'case_gallery/index',
                'selected' => '/case-gallery',
                'app_code' => '',
                'source_menu_key' => 'core_tenant_case_gallery',
            ], 9102);
            self::grantMenuToTenantRoles($roleMenuTable, $tenantId, $caseGalleryId);

            foreach (self::caseGalleryPermissionMenus() as $menu) {
                $menu['pid'] = $caseGalleryId;
                $childId = self::upsertSystemMenu($menuTable, $tenantId, $menu, (int)($menu['legacy_id'] ?? 0));
                self::grantChildMenuToParentRoles($roleMenuTable, $caseGalleryId, $childId);
                self::grantMenuToTenantRoles($roleMenuTable, $tenantId, $childId);
            }
        } catch (Throwable) {
        }
    }

    private static function upsertSystemMenuTree(string $menuTable, string $roleMenuTable, int $tenantId, array $menu, int $parentId): int
    {
        $children = $menu['children'] ?? [];
        unset($menu['children']);
        $menu['pid'] = $parentId;
        $id = self::upsertSystemMenu($menuTable, $tenantId, $menu, (int)($menu['legacy_id'] ?? 0));
        self::grantChildMenuToParentRoles($roleMenuTable, $parentId, $id);

        foreach ($children as $child) {
            self::upsertSystemMenuTree($menuTable, $roleMenuTable, $tenantId, $child, $id);
        }
        return $id;
    }

    private static function upsertSystemMenu(string $table, int $tenantId, array $menu, int $legacyId = 0): int
    {
        unset($menu['legacy_id']);
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
            'app_code' => 'system_default',
            'source' => 'core',
            'source_menu_key' => '',
            'is_core' => 1,
        ];
        $data = array_merge($defaults, $menu, [
            'tenant_id' => $tenantId,
            'update_time' => $time,
        ]);

        $row = self::findSystemMenu($table, $tenantId, $data, $legacyId);
        if (empty($row)) {
            $data['create_time'] = $time;
            return (int)Db::name($table)->insertGetId($data);
        }

        Db::name($table)->where('id', (int)$row['id'])->update($data);
        return (int)$row['id'];
    }

    private static function findSystemMenu(string $table, int $tenantId, array $menu, int $legacyId = 0): array
    {
        if (!empty($menu['source_menu_key'])) {
            $row = Db::name($table)
                ->where(['tenant_id' => $tenantId, 'source_menu_key' => $menu['source_menu_key']])
                ->where('source', '<>', 'tenant')
                ->find();
            if (!empty($row)) {
                return $row;
            }
        }

        if ($legacyId > 0) {
            $row = Db::name($table)
                ->where(['tenant_id' => $tenantId, 'id' => $legacyId])
                ->where('source', '<>', 'tenant')
                ->find();
            if (!empty($row)) {
                return $row;
            }
        }

        $query = Db::name($table)
            ->where('tenant_id', $tenantId)
            ->where('name', (string)$menu['name'])
            ->where('type', (string)$menu['type'])
            ->where('source', '<>', 'tenant');
        if ((string)$menu['perms'] !== '') {
            $query->where('perms', (string)$menu['perms']);
        } elseif ((string)$menu['component'] !== '') {
            $query->where('component', (string)$menu['component']);
        } elseif ((string)$menu['paths'] !== '') {
            $query->where('paths', (string)$menu['paths']);
        } else {
            $query->where('pid', (int)$menu['pid']);
        }
        return $query->find() ?: [];
    }

    private static function systemDefaultMenuTree(int $systemId): array
    {
        return [
            [
                'legacy_id' => 159,
                'type' => 'C',
                'name' => '用户充值',
                'icon' => 'local-icon-fukuan',
                'sort' => 10,
                'perms' => 'recharge.recharge/getConfig',
                'paths' => 'recharge',
                'component' => 'app/recharge/index',
                'source_menu_key' => 'core_tenant_recharge_config',
                'children' => [
                    [
                        'legacy_id' => 160,
                        'type' => 'A',
                        'name' => '保存',
                        'perms' => 'recharge.recharge/setConfig',
                        'source_menu_key' => 'core_tenant_recharge_config_save',
                    ],
                ],
            ],
            [
                'legacy_id' => 70,
                'type' => 'M',
                'name' => '文章资讯',
                'icon' => 'el-icon-ChatLineSquare',
                'sort' => 20,
                'paths' => 'article',
                'source_menu_key' => 'core_tenant_article',
                'children' => [
                    [
                        'legacy_id' => 71,
                        'type' => 'C',
                        'name' => '文章管理',
                        'icon' => 'el-icon-ChatDotSquare',
                        'perms' => 'article.article/lists',
                        'paths' => 'lists',
                        'component' => 'article/lists/index',
                        'source_menu_key' => 'core_tenant_article_lists',
                        'children' => [
                            ['legacy_id' => 74, 'type' => 'A', 'name' => '新增', 'perms' => 'article.article/add', 'source_menu_key' => 'core_tenant_article_add'],
                            ['legacy_id' => 75, 'type' => 'A', 'name' => '详情', 'perms' => 'article.article/detail', 'source_menu_key' => 'core_tenant_article_detail'],
                            ['legacy_id' => 76, 'type' => 'A', 'name' => '删除', 'perms' => 'article.article/delete', 'source_menu_key' => 'core_tenant_article_delete'],
                            ['legacy_id' => 77, 'type' => 'A', 'name' => '修改状态', 'perms' => 'article.article/updateStatus', 'source_menu_key' => 'core_tenant_article_status'],
                            ['legacy_id' => 105, 'type' => 'A', 'name' => '编辑', 'perms' => 'article.article/edit', 'source_menu_key' => 'core_tenant_article_edit'],
                        ],
                    ],
                    [
                        'legacy_id' => 72,
                        'type' => 'C',
                        'name' => '文章添加/编辑',
                        'perms' => 'article.article/add:edit',
                        'paths' => 'lists/edit',
                        'component' => 'article/lists/edit',
                        'selected' => '/article/lists',
                        'is_show' => 0,
                        'source_menu_key' => 'core_tenant_article_lists_edit',
                    ],
                    [
                        'legacy_id' => 73,
                        'type' => 'C',
                        'name' => '文章栏目',
                        'icon' => 'el-icon-CollectionTag',
                        'perms' => 'article.articleCate/lists',
                        'paths' => 'column',
                        'component' => 'article/column/index',
                        'is_cache' => 1,
                        'source_menu_key' => 'core_tenant_article_column',
                        'children' => [
                            ['legacy_id' => 78, 'type' => 'A', 'name' => '添加', 'perms' => 'article.articleCate/add', 'source_menu_key' => 'core_tenant_article_column_add'],
                            ['legacy_id' => 79, 'type' => 'A', 'name' => '删除', 'perms' => 'article.articleCate/delete', 'source_menu_key' => 'core_tenant_article_column_delete'],
                            ['legacy_id' => 80, 'type' => 'A', 'name' => '详情', 'perms' => 'article.articleCate/detail', 'source_menu_key' => 'core_tenant_article_column_detail'],
                            ['legacy_id' => 81, 'type' => 'A', 'name' => '修改状态', 'perms' => 'article.articleCate/updateStatus', 'source_menu_key' => 'core_tenant_article_column_status'],
                        ],
                    ],
                ],
            ],
            [
                'legacy_id' => 101,
                'type' => 'M',
                'name' => '消息管理',
                'icon' => 'el-icon-ChatDotRound',
                'sort' => 30,
                'paths' => 'message',
                'source_menu_key' => 'core_tenant_message',
                'children' => [
                    [
                        'legacy_id' => 102,
                        'type' => 'C',
                        'name' => '通知设置',
                        'perms' => 'notice.notice/settingLists',
                        'paths' => 'notice',
                        'component' => 'message/notice/index',
                        'source_menu_key' => 'core_tenant_notice',
                        'children' => [
                            ['legacy_id' => 103, 'type' => 'A', 'name' => '详情', 'perms' => 'notice.notice/detail', 'source_menu_key' => 'core_tenant_notice_detail'],
                        ],
                    ],
                    [
                        'legacy_id' => 104,
                        'type' => 'C',
                        'name' => '通知设置编辑',
                        'perms' => 'notice.notice/set',
                        'paths' => 'notice/edit',
                        'component' => 'message/notice/edit',
                        'selected' => '/message/notice',
                        'is_show' => 0,
                        'source_menu_key' => 'core_tenant_notice_edit',
                    ],
                    [
                        'legacy_id' => 107,
                        'type' => 'C',
                        'name' => '短信设置',
                        'perms' => 'notice.sms_config/getConfig',
                        'paths' => 'short_letter',
                        'component' => 'message/short_letter/index',
                        'source_menu_key' => 'core_tenant_sms',
                        'children' => [
                            ['legacy_id' => 108, 'type' => 'A', 'name' => '设置', 'perms' => 'notice.sms_config/setConfig', 'source_menu_key' => 'core_tenant_sms_setup'],
                            ['legacy_id' => 109, 'type' => 'A', 'name' => '详情', 'perms' => 'notice.sms_config/detail', 'source_menu_key' => 'core_tenant_sms_detail'],
                        ],
                    ],
                ],
            ],
            [
                'legacy_id' => 63,
                'type' => 'M',
                'name' => '素材管理',
                'icon' => 'el-icon-Picture',
                'sort' => 40,
                'paths' => 'material',
                'source_menu_key' => 'core_tenant_material',
                'children' => [
                    [
                        'legacy_id' => 64,
                        'type' => 'C',
                        'name' => '素材中心',
                        'icon' => 'el-icon-PictureRounded',
                        'paths' => 'index',
                        'component' => 'material/index',
                        'source_menu_key' => 'core_tenant_material_index',
                    ],
                ],
            ],
        ];
    }

    private static function caseGalleryPermissionMenus(): array
    {
        return [
            [
                'legacy_id' => 9300,
                'type' => 'A',
                'name' => '应用选项',
                'perms' => 'case_gallery.case/apps',
                'is_show' => 0,
                'app_code' => '',
                'source_menu_key' => 'core_tenant_case_gallery_apps',
            ],
            [
                'legacy_id' => 9301,
                'type' => 'A',
                'name' => '详情',
                'perms' => 'case_gallery.case/detail',
                'is_show' => 0,
                'app_code' => '',
                'source_menu_key' => 'core_tenant_case_gallery_detail',
            ],
            [
                'legacy_id' => 9302,
                'type' => 'A',
                'name' => '保存',
                'perms' => 'case_gallery.case/save',
                'is_show' => 0,
                'app_code' => '',
                'source_menu_key' => 'core_tenant_case_gallery_save',
            ],
            [
                'legacy_id' => 9303,
                'type' => 'A',
                'name' => '任务加入',
                'perms' => 'case_gallery.case/fromTask',
                'is_show' => 0,
                'app_code' => '',
                'source_menu_key' => 'core_tenant_case_gallery_from_task',
            ],
            [
                'legacy_id' => 9304,
                'type' => 'A',
                'name' => '修改状态',
                'perms' => 'case_gallery.case/status',
                'is_show' => 0,
                'app_code' => '',
                'source_menu_key' => 'core_tenant_case_gallery_status',
            ],
            [
                'legacy_id' => 9305,
                'type' => 'A',
                'name' => '删除',
                'perms' => 'case_gallery.case/delete',
                'is_show' => 0,
                'app_code' => '',
                'source_menu_key' => 'core_tenant_case_gallery_delete',
            ],
        ];
    }

    private static function tenantMenuTables(int $tenantId): array
    {
        $menuTable = 'tenant_system_menu';
        $roleMenuTable = 'tenant_system_role_menu';
        try {
            $tenant = Tenant::where('id', $tenantId)->field('sn,tactics')->findOrEmpty();
            $sn = $tenant->isEmpty() ? '' : (string)($tenant['sn'] ?? '');
            if (!$tenant->isEmpty() && (int)$tenant['tactics'] === 1 && $sn !== '') {
                $splitMenuTable = 'tenant_system_menu_' . $sn;
                if (self::tableExists($splitMenuTable)) {
                    $menuTable = $splitMenuTable;
                }
                $splitRoleMenuTable = 'tenant_system_role_menu_' . $sn;
                if (self::tableExists($splitRoleMenuTable)) {
                    $roleMenuTable = $splitRoleMenuTable;
                }
            }
        } catch (Throwable) {
        }
        return [
            'menu' => $menuTable,
            'role_menu' => $roleMenuTable,
        ];
    }

    private static function grantChildMenuToParentRoles(string $roleMenuTable, int $parentId, int $childId): void
    {
        if ($parentId <= 0 || $childId <= 0 || !self::tableExists($roleMenuTable)) {
            return;
        }
        try {
            $table = self::quoteTable(self::fullTableName($roleMenuTable));
            Db::execute(
                'INSERT IGNORE INTO ' . $table . ' (`role_id`, `menu_id`) ' .
                'SELECT `role_id`, ' . (int)$childId . ' FROM ' . $table . ' WHERE `menu_id` = ' . (int)$parentId
            );
        } catch (Throwable) {
        }
    }

    private static function grantParentMenuToChildRoles(string $roleMenuTable, int $childId, int $parentId): void
    {
        if ($parentId <= 0 || $childId <= 0 || !self::tableExists($roleMenuTable)) {
            return;
        }
        try {
            $table = self::quoteTable(self::fullTableName($roleMenuTable));
            Db::execute(
                'INSERT IGNORE INTO ' . $table . ' (`role_id`, `menu_id`) ' .
                'SELECT `role_id`, ' . (int)$parentId . ' FROM ' . $table . ' WHERE `menu_id` = ' . (int)$childId
            );
        } catch (Throwable) {
        }
    }

    private static function grantMenuToTenantRoles(string $roleMenuTable, int $tenantId, int $menuId): void
    {
        if ($menuId <= 0 || !self::tableExists($roleMenuTable)) {
            return;
        }
        try {
            $table = self::quoteTable(self::fullTableName($roleMenuTable));
            $roleTable = self::quoteTable(self::fullTableName('tenant_system_role'));
            Db::execute(
                'INSERT IGNORE INTO ' . $table . ' (`role_id`, `menu_id`) ' .
                'SELECT DISTINCT role_menu.`role_id`, ' . (int)$menuId . ' FROM ' . $table . ' role_menu ' .
                'JOIN ' . $roleTable . ' role ON role.`id` = role_menu.`role_id` ' .
                'WHERE role.`tenant_id` = ' . (int)$tenantId . ' AND (role.`delete_time` IS NULL OR role.`delete_time` = 0)'
            );
        } catch (Throwable) {
        }
    }

    private static function ensureMenuSourceColumns(string $table): void
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
                if (!empty(Db::query('SHOW COLUMNS FROM ' . self::quoteTable($full) . " LIKE '" . addslashes($column) . "'"))) {
                    continue;
                }
                Db::execute('ALTER TABLE ' . self::quoteTable($full) . ' ADD COLUMN `' . $column . '` ' . $definition);
            } catch (Throwable) {
            }
        }
    }

    private static function tableExists(string $table): bool
    {
        try {
            return !empty(Db::query("SHOW TABLES LIKE '" . addslashes(self::fullTableName($table)) . "'"));
        } catch (Throwable) {
            return false;
        }
    }

    private static function fullTableName(string $table): string
    {
        $prefix = (string)config('database.connections.mysql.prefix', env('database.prefix', 'la_'));
        return str_starts_with($table, $prefix) ? $table : $prefix . $table;
    }

    private static function quoteTable(string $table): string
    {
        return '`' . str_replace('`', '``', $table) . '`';
    }


    /**
     * @notes 固定租户端核心菜单尾部顺序
     * @param array $menus
     * @return array
     */
    private static function sortTopLevelTailMenus(array $menus): array
    {
        $tailOrders = [
            'finance' => 1,
            'decoration' => 2,
            'channel' => 3,
            'setting' => 4,
        ];

        $indexedMenus = [];
        foreach ($menus as $index => $menu) {
            $tailKey = self::getTailMenuKey($menu);
            $indexedMenus[] = [
                'index' => $index,
                'tail_order' => $tailOrders[$tailKey] ?? 0,
                'menu' => $menu,
            ];
        }

        usort($indexedMenus, function ($left, $right) {
            if ($left['tail_order'] && $right['tail_order']) {
                return $left['tail_order'] <=> $right['tail_order'];
            }
            if ($left['tail_order']) {
                return 1;
            }
            if ($right['tail_order']) {
                return -1;
            }
            return $left['index'] <=> $right['index'];
        });

        return array_column($indexedMenus, 'menu');
    }


    /**
     * @notes 获取需要固定尾部排序的菜单标识
     * @param array $menu
     * @return string
     */
    private static function getTailMenuKey(array $menu): string
    {
        $paths = (string)($menu['paths'] ?? '');
        if (in_array($paths, ['finance', 'decoration', 'channel', 'setting'], true)) {
            return $paths;
        }

        $nameMap = [
            '财务管理' => 'finance',
            '装修管理' => 'decoration',
            '渠道设置' => 'channel',
            '系统设置' => 'setting',
        ];
        return $nameMap[(string)($menu['name'] ?? '')] ?? '';
    }


    /**
     * @notes 添加菜单
     * @param array $params
     * @return TenantSystemMenu|\think\Model
     * @author 段誉
     * @date 2022/6/30 10:06
     */
    public static function add(array $params)
    {
        return TenantSystemMenu::create([
            'pid' => $params['pid'],
            'type' => $params['type'],
            'name' => $params['name'],
            'icon' => $params['icon'] ?? '',
            'sort' => $params['sort'],
            'perms' => $params['perms'] ?? '',
            'paths' => $params['paths'] ?? '',
            'component' => $params['component'] ?? '',
            'selected' => $params['selected'] ?? '',
            'params' => $params['params'] ?? '',
            'is_cache' => $params['is_cache'],
            'is_show' => $params['is_show'],
            'is_disable' => $params['is_disable'],
        ]);
    }


    /**
     * @notes 编辑菜单
     * @param array $params
     * @return TenantSystemMenu
     * @author 段誉
     * @date 2022/6/30 10:07
     */
    public static function edit(array $params)
    {
        return TenantSystemMenu::update([
            'pid' => $params['pid'],
            'type' => $params['type'],
            'name' => $params['name'],
            'icon' => $params['icon'] ?? '',
            'sort' => $params['sort'],
            'perms' => $params['perms'] ?? '',
            'paths' => $params['paths'] ?? '',
            'component' => $params['component'] ?? '',
            'selected' => $params['selected'] ?? '',
            'params' => $params['params'] ?? '',
            'is_cache' => $params['is_cache'],
            'is_show' => $params['is_show'],
            'is_disable' => $params['is_disable'],
        ], ['id' => $params['id']]);
    }


    /**
     * @notes 详情
     * @param $params
     * @return array
     * @author 段誉
     * @date 2022/6/30 9:54
     */
    public static function detail($params)
    {
        return TenantSystemMenu::findOrEmpty($params['id'])->toArray();
    }


    /**
     * @notes 删除菜单
     * @param $params
     * @author 段誉
     * @date 2022/6/30 9:47
     */
    public static function delete($params)
    {
        // 删除菜单
        TenantSystemMenu::destroy($params['id']);
        // 删除角色-菜单表中 与该菜单关联的记录
        TenantSystemRoleMenu::where(['menu_id' => $params['id']])->delete();
    }


    /**
     * @notes 更新状态
     * @param array $params
     * @return TenantSystemMenu
     * @author 段誉
     * @date 2022/7/6 17:02
     */
    public static function updateStatus(array $params)
    {
        return TenantSystemMenu::update([
            'is_disable' => $params['is_disable']
        ], ['id' => $params['id']]);
    }


    /**
     * @notes 全部数据
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/10/13 11:03
     */
    public static function getAllData()
    {
        $data = TenantSystemMenu::where(['is_disable' => YesNoEnum::NO])
            ->field('id,pid,name')
            ->order(['sort' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();

        return linear_to_tree($data, 'children');
    }

}
