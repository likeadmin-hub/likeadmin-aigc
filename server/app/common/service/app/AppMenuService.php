<?php

namespace app\common\service\app;

use app\common\model\auth\TenantSystemMenu;
use app\common\model\auth\SystemMenu;
use app\common\model\auth\TenantSystemRoleMenu;
use app\common\model\auth\SystemRoleMenu;

class AppMenuService
{
    public static function syncPlatformMenus(string $appCode): void
    {
        $path = root_path() . 'app/apps/' . $appCode . '/menus/platform.json';
        if (!is_file($path)) {
            return;
        }
        $menus = json_decode((string)file_get_contents($path), true);
        if (!is_array($menus)) {
            return;
        }
        self::prunePlatformMenus($appCode, self::collectMenuKeys($menus));
        foreach ($menus as $menu) {
            self::savePlatformMenu($appCode, $menu, (int)($menu['pid'] ?? 0));
        }
    }

    public static function syncTenantMenus(int $tenantId, string $appCode): void
    {
        $path = root_path() . 'app/apps/' . $appCode . '/menus/tenant.json';
        if (!is_file($path)) {
            return;
        }
        $menus = json_decode((string)file_get_contents($path), true);
        if (!is_array($menus)) {
            return;
        }
        self::pruneTenantMenus($tenantId, $appCode, self::collectMenuKeys($menus));
        foreach ($menus as $menu) {
            self::saveTenantMenu($tenantId, $appCode, $menu, (int)($menu['pid'] ?? 0));
        }
    }

    private static function saveTenantMenu(int $tenantId, string $appCode, array $menu, int $pid): void
    {
        $key = $menu['source_menu_key'] ?? '';
        if ($key === '') {
            return;
        }
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
            'update_time' => time(),
        ];
        $row = TenantSystemMenu::where([
            'tenant_id' => $tenantId,
            'app_code' => $appCode,
            'source_menu_key' => $key,
        ])->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            $row = TenantSystemMenu::create($data);
        } else {
            $row->save($data);
        }
        foreach (($menu['children'] ?? []) as $child) {
            self::saveTenantMenu($tenantId, $appCode, $child, (int)$row['id']);
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

    private static function pruneTenantMenus(int $tenantId, string $appCode, array $sourceMenuKeys): void
    {
        if (empty($sourceMenuKeys)) {
            return;
        }
        $staleIds = TenantSystemMenu::where([
            'tenant_id' => $tenantId,
            'app_code' => $appCode,
            'source' => 'app',
        ])->where('source_menu_key', 'not in', $sourceMenuKeys)->column('id');
        if (empty($staleIds)) {
            return;
        }
        TenantSystemMenu::whereIn('id', $staleIds)->delete();
        TenantSystemRoleMenu::whereIn('menu_id', $staleIds)->delete();
    }

    private static function savePlatformMenu(string $appCode, array $menu, int $pid): void
    {
        $key = $menu['source_menu_key'] ?? '';
        if ($key === '') {
            return;
        }
        $data = [
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
            'update_time' => time(),
        ];
        $row = SystemMenu::where([
            'app_code' => $appCode,
            'source_menu_key' => $key,
        ])->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            $row = SystemMenu::create($data);
        } else {
            $row->save($data);
        }
        foreach (($menu['children'] ?? []) as $child) {
            self::savePlatformMenu($appCode, $child, (int)$row['id']);
        }
    }

    private static function prunePlatformMenus(string $appCode, array $sourceMenuKeys): void
    {
        if (empty($sourceMenuKeys)) {
            return;
        }
        $staleIds = SystemMenu::where([
            'app_code' => $appCode,
            'source' => 'app',
        ])->where('source_menu_key', 'not in', $sourceMenuKeys)->column('id');
        if (empty($staleIds)) {
            return;
        }
        SystemMenu::whereIn('id', $staleIds)->delete();
        SystemRoleMenu::whereIn('menu_id', $staleIds)->delete();
    }
}
