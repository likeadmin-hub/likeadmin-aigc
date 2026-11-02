<?php

namespace app\common\service\auth;

use think\facade\Db;

/** Restore missing built-in pages without changing tenant settings or role grants. */
final class TenantAuthorizationMenus
{
    public static function ensureTenant(int $tenantId): void
    {
        if ($tenantId <= 0) return;
        $tenant = Db::name('tenant')->where('id', $tenantId)->field('sn,tactics')->find();
        if (!$tenant) return;
        $table = 'tenant_system_menu';
        if ((int)$tenant['tactics'] === 1) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', (string)$tenant['sn'])) return;
            $table .= '_' . $tenant['sn'];
        }
        Db::transaction(static function () use ($tenantId, $table): void {
            // Serialize missing-record creation across simultaneous admin requests.
            Db::name('tenant')->where('id', $tenantId)->lock(true)->find();
            self::syncTable($tenantId, $table);
        });
    }

    private static function syncTable(int $tenantId, string $table): void
    {
        $groups = json_decode(file_get_contents(__DIR__ . '/tenant-authorization-menus.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($groups as $group) {
            $root = Db::name($table)->where('tenant_id', $tenantId)
                ->where('type', 'M')->where('paths', $group['paths'])
                ->where('source', '<>', 'tenant')->order('id')->find();
            // Do not create a new administration entry where the tenant has none.
            if (!$root || (int)$root['is_disable'] !== 0) continue;
            foreach ($group['children'] as $page) {
                $row = Db::name($table)->where('tenant_id', $tenantId)->where('type', 'C')
                    ->where('component', $page['component'])->order('id')->find();
                if ($row && ($row['source'] === 'tenant' || (int)$row['is_disable'] !== 0)) continue;
                $pageId = $row ? (int)$row['id'] : self::insert($table, $tenantId, (int)$root['id'], $page);
                // Existing menu IDs, visibility, hierarchy and grants stay intact.
                foreach ($page['children'] ?? [] as $action) {
                    $exists = Db::name($table)->where('tenant_id', $tenantId)
                        ->where('perms', $action['perms'])->count();
                    if (!$exists) self::insert($table, $tenantId, $pageId, $action);
                }
            }
        }
    }

    private static function insert(string $table, int $tenantId, int $parentId, array $menu): int
    {
        unset($menu['children']);
        return (int)Db::name($table)->insertGetId($menu + [
            'tenant_id' => $tenantId,
            'pid' => $parentId,
            'app_code' => '',
            'source' => 'core',
            'source_menu_key' => 'core_tenant_auth_' . str_replace(['/', '.'], '_', $menu['perms']),
            'is_core' => 1,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }
}
