<?php
namespace app\common\service\database;

use RuntimeException;
use think\facade\Db;

/** Read-only checks; never changes a customer's table engine. */
final class RuntimeMetadataTransactionGate
{
    public static function assertMetadata(): void
    {
        $tables = ['app','app_version','tenant_app','app_api','app_frontend_entry','app_install','app_migration',
            'system_menu','tenant_system_menu','system_role_menu','tenant_system_role_menu','app_plan',
            'membership_plan','membership_plan_app','tenant_package_app_plan','recharge_package','update_package','update_task'];
        $prefix = (string)config('database.connections.mysql.prefix');
        $rows = Db::query('SELECT TABLE_NAME AS table_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        foreach ($rows as $row) {
            $name = (string)($row['table_name'] ?? '');
            if (preg_match('/^'.preg_quote($prefix,'/').'(tenant_system_menu|tenant_system_role_menu)_[a-z0-9_]+$/i', $name)) $tables[] = substr($name, strlen($prefix));
        }
        self::assertTables($tables);
    }

    public static function assertTables(array $tables, bool $required = true): void
    {
        $prefix = (string)config('database.connections.mysql.prefix');
        foreach (array_unique($tables) as $table) {
            if (!preg_match('/^[a-z0-9_]+$/i', $table)) throw new RuntimeException('事务目标表格式不受支持');
            $name = str_starts_with($table, 'la_') ? $prefix.substr($table, 3) : $prefix.$table;
            $rows = Db::query('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$name]);
            if (!$rows && !$required) continue;
            if (count($rows) !== 1 || strcasecmp((string)($rows[0]['engine'] ?? ''), 'InnoDB') !== 0) throw new RuntimeException('更新事务要求目标表使用 InnoDB: '.$table);
        }
    }
}
