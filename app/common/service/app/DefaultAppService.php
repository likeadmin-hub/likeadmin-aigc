<?php

namespace app\common\service\app;

use app\common\model\app\App;
use app\common\model\app\TenantApp;
use app\common\model\auth\TenantSystemRoleMenu;
use app\common\model\tenant\Tenant;
use think\facade\Db;
use Throwable;

class DefaultAppService
{
    public static function isDefaultApp(string $appCode): bool
    {
        return AppAccessService::isDefaultAigcApp($appCode);
    }

    public static function normalizeAppData(string $appCode, array $data): array
    {
        if (!self::isDefaultApp($appCode)) {
            return $data;
        }
        $data['is_builtin'] = 1;
        $data['expire_policy'] = AppPlanService::EXPIRE_ALLOW;
        $data['status'] = AppRegistryService::STATUS_INSTALLED;
        return $data;
    }

    public static function syncAllTenants(?string $appCode = null): void
    {
        $query = App::where('status', AppRegistryService::STATUS_INSTALLED)
            ->whereIn('code', AppAccessService::DEFAULT_AIGC_APP_CODES);
        if ($appCode !== null && $appCode !== '') {
            if (!self::isDefaultApp($appCode)) {
                return;
            }
            $query->where('code', $appCode);
        }
        $apps = $query->select()->toArray();
        if (empty($apps)) {
            return;
        }

        foreach ($apps as $app) {
            self::syncTenant(0, (string)$app['code'], (string)$app['current_version'], null, false);
        }

        $tenants = Tenant::field('id,sn,tactics')->select()->toArray();
        foreach ($tenants as $tenant) {
            foreach ($apps as $app) {
                self::syncTenant(
                    (int)$tenant['id'],
                    (string)$app['code'],
                    (string)$app['current_version'],
                    (string)($tenant['sn'] ?? ''),
                    (int)($tenant['tactics'] ?? 0) === 1
                );
            }
        }
    }

    public static function syncTenantDefaults(int $tenantId, string $tenantSn = '', bool $isSplitTable = false): void
    {
        $apps = App::where('status', AppRegistryService::STATUS_INSTALLED)
            ->whereIn('code', AppAccessService::DEFAULT_AIGC_APP_CODES)
            ->select()
            ->toArray();
        foreach ($apps as $app) {
            self::syncTenant(
                $tenantId,
                (string)$app['code'],
                (string)$app['current_version'],
                $tenantSn,
                $isSplitTable
            );
        }
    }

    public static function ensureTenantDefaultApp(int $tenantId, string $appCode): void
    {
        if ($tenantId <= 0 || !self::isDefaultApp($appCode)) {
            return;
        }
        $app = App::where(['code' => $appCode, 'status' => AppRegistryService::STATUS_INSTALLED])->findOrEmpty();
        if ($app->isEmpty()) {
            return;
        }
        self::syncTenant($tenantId, $appCode, (string)$app['current_version'], '', false);
    }

    private static function syncTenant(int $tenantId, string $appCode, string $version, ?string $tenantSn, bool $isSplitTable): void
    {
        self::upsertTenantApp($tenantId, $appCode, $version);
        $table = $isSplitTable && $tenantSn ? 'tenant_system_menu_' . $tenantSn : 'tenant_system_menu';
        self::syncTenantMenusToTable($tenantId, $appCode, $table);
    }

    private static function upsertTenantApp(int $tenantId, string $appCode, string $version): void
    {
        $time = time();
        $data = [
            'tenant_id' => $tenantId,
            'app_code' => $appCode,
            'version' => $version,
            'buy_status' => AppAccessService::BUY_PAID,
            'shelf_status' => AppAccessService::SHELF_ON,
            'enable_status' => AppAccessService::ENABLED,
            'expire_time' => 0,
            'update_time' => $time,
        ];
        $row = TenantApp::where(['tenant_id' => $tenantId, 'app_code' => $appCode])->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = $time;
            TenantApp::create($data);
            return;
        }
        $row->save($data);
    }

    private static function syncTenantMenusToTable(int $tenantId, string $appCode, string $table): void
    {
        $path = root_path() . 'app/apps/' . $appCode . '/menus/tenant.json';
        if (!is_file($path)) {
            return;
        }
        $menus = json_decode((string)file_get_contents($path), true);
        if (!is_array($menus)) {
            return;
        }
        self::pruneTenantMenusInTable($tenantId, $appCode, $table, self::collectMenuKeys($menus));
        foreach ($menus as $menu) {
            self::saveTenantMenu($tenantId, $appCode, $table, $menu, 0);
        }
    }

    private static function collectMenuKeys(array $menus): array
    {
        $keys = [];
        foreach ($menus as $menu) {
            $key = (string)($menu['source_menu_key'] ?? '');
            if ($key !== '') {
                $keys[] = $key;
            }
            $children = $menu['children'] ?? [];
            if (is_array($children) && !empty($children)) {
                $keys = array_merge($keys, self::collectMenuKeys($children));
            }
        }
        return array_values(array_unique($keys));
    }

    private static function pruneTenantMenusInTable(int $tenantId, string $appCode, string $table, array $sourceMenuKeys): void
    {
        if (empty($sourceMenuKeys)) {
            return;
        }
        try {
            $staleIds = Db::name($table)
                ->where(['tenant_id' => $tenantId, 'app_code' => $appCode, 'source' => 'app'])
                ->where('source_menu_key', 'not in', $sourceMenuKeys)
                ->column('id');
            if (empty($staleIds)) {
                return;
            }
            Db::name($table)->whereIn('id', $staleIds)->delete();
            if ($table === 'tenant_system_menu') {
                TenantSystemRoleMenu::whereIn('menu_id', $staleIds)->delete();
            }
        } catch (Throwable) {
        }
    }

    private static function saveTenantMenu(int $tenantId, string $appCode, string $table, array $menu, int $pid): void
    {
        $key = (string)($menu['source_menu_key'] ?? '');
        if ($key === '') {
            return;
        }
        $time = time();
        $data = [
            'tenant_id' => $tenantId,
            'pid' => $pid,
            'type' => $menu['type'] ?? 'C',
            'name' => $menu['name'] ?? '',
            'icon' => $menu['icon'] ?? '',
            'sort' => $menu['sort'] ?? 0,
            'perms' => $menu['perms'] ?? '',
            'paths' => $menu['paths'] ?? '',
            'component' => $menu['component'] ?? '',
            'selected' => $menu['selected'] ?? '',
            'params' => $menu['params'] ?? '',
            'is_cache' => $menu['is_cache'] ?? 0,
            'is_show' => $menu['is_show'] ?? 1,
            'is_disable' => $menu['is_disable'] ?? 0,
            'app_code' => $appCode,
            'source' => 'app',
            'source_menu_key' => $key,
            'is_core' => 0,
            'update_time' => $time,
        ];

        try {
            $query = Db::name($table)->where([
                'tenant_id' => $tenantId,
                'app_code' => $appCode,
                'source_menu_key' => $key,
            ]);
            $row = $query->find();
            if (empty($row)) {
                $data['create_time'] = $time;
                $id = (int)Db::name($table)->insertGetId($data);
            } else {
                Db::name($table)->where('id', (int)$row['id'])->update($data);
                $id = (int)$row['id'];
            }
            foreach (($menu['children'] ?? []) as $child) {
                self::saveTenantMenu($tenantId, $appCode, $table, $child, $id);
            }
        } catch (Throwable) {
        }
    }
}
