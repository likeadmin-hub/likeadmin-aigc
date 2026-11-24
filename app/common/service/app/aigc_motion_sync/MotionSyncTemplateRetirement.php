<?php
declare(strict_types=1);

namespace app\common\service\app\aigc_motion_sync;

use InvalidArgumentException;
use think\facade\Db;

final class MotionSyncTemplateRetirement
{
    public static function apply(string $prefix, $connection = null): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) {
            throw new InvalidArgumentException('Invalid database table prefix');
        }

        $db = $connection ?? Db::connect();
        $app = MotionSyncService::APP;
        $route = 'app.aigc_motion_sync.case/%';
        $tables = array_column($db->query(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()'
        ), 'name');

        $db->transaction(static function () use ($db, $prefix, $app, $route, $tables): void {
            $db->execute('DELETE FROM `' . $prefix . 'app_api` WHERE app_code=? AND api_path LIKE ?', [$app, $route]);
            $db->execute('UPDATE `' . $prefix . 'app_case` SET status=0 WHERE app_code=? AND status<>0', [$app]);

            foreach ($tables as $menu) {
                if (!in_array($menu, [$prefix . 'system_menu', $prefix . 'tenant_system_menu'], true)
                    && !str_starts_with($menu, $prefix . 'tenant_system_menu_')) {
                    continue;
                }
                if (!preg_match('/^[a-zA-Z0-9_]+$/D', $menu)) {
                    continue;
                }
                $columns = array_column($db->query(
                    'SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
                    [$menu]
                ), 'name');
                if (array_diff(['app_code', 'source', 'perms'], $columns)) {
                    continue;
                }
                $filter = " WHERE app_code=? AND source='app' AND perms LIKE ?";
                $params = [$app, $route];
                $role = $menu === $prefix . 'system_menu'
                    ? $prefix . 'system_role_menu'
                    : $prefix . 'tenant_system_role_menu' . substr($menu, strlen($prefix . 'tenant_system_menu'));
                if (in_array($role, $tables, true)) {
                    $db->execute('DELETE FROM `' . $role . '` WHERE menu_id IN (SELECT id FROM `' . $menu . '`' . $filter . ')', $params);
                }
                $db->execute('DELETE FROM `' . $menu . '`' . $filter, $params);
            }
        });
    }
}
