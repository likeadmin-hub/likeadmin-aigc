<?php

namespace app\common\service\database;

use think\facade\Db;

/** Explicit upgrade only. Registration must never perform schema changes. */
class TenantUserAccountMigration
{
    public static function run(string $prefix, $connection = null, bool $dryRun = false): array
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) throw new \RuntimeException('Invalid database prefix');
        $db = $connection ?: Db::connect();
        $userTables = [$prefix.'user'=>true];
        foreach ($db->query("SELECT `sn` FROM `{$prefix}tenant` WHERE `tactics`=1") as $tenant) {
            $sn = (string)$tenant['sn'];
            if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $sn)) throw new \RuntimeException('Invalid tenant serial');
            $userTables[$prefix.'user_'.$sn] = true;
        }
        $plans = [];
        $matched = 0;
        foreach ($db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $row) {
            $table = $row['TABLE_NAME'];
            // Match actual tenant serials, never unrelated user_* or admin tables.
            if (!isset($userTables[$table])) continue;
            $matched++;
            // Include soft-deleted users: an account remains reserved in its tenant.
            if ($db->query("SELECT 1 FROM `{$table}` GROUP BY `tenant_id`,`account` HAVING COUNT(*) > 1 LIMIT 1")) {
                throw new \RuntimeException('同一租户存在重复用户账号，迁移停止：'.$table);
            }
            $indexes = [];
            foreach ($db->query("SHOW INDEX FROM `{$table}`") as $index) $indexes[$index['Key_name']][] = $index;
            $alter = [];
            $hasTenantAccount = false;
            foreach ($indexes as $name => $parts) {
                usort($parts, fn($a, $b) => $a['Seq_in_index'] <=> $b['Seq_in_index']);
                $columns = array_column($parts, 'Column_name');
                $fullColumns = !array_filter($parts, fn($part) => ($part['Sub_part'] ?? null) !== null);
                $isExpected = $columns === ['tenant_id','account'] && (int)$parts[0]['Non_unique'] === 0 && $fullColumns;
                if ($name === 'uk_tenant_account' && !$isExpected) throw new \RuntimeException('用户账号索引定义冲突：'.$table);
                $hasTenantAccount = $hasTenantAccount || $isExpected;
                if ($columns === ['account'] && (int)$parts[0]['Non_unique'] === 0) {
                    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) throw new \RuntimeException('Unexpected index name');
                    $alter[] = "DROP INDEX `{$name}`";
                }
            }
            if (!$hasTenantAccount) array_unshift($alter, 'ADD UNIQUE INDEX `uk_tenant_account` (`tenant_id`,`account`)');
            if ($alter) $plans[] = "ALTER TABLE `{$table}` ".implode(', ', $alter);
        }
        if (!$matched) throw new \RuntimeException('未找到用户表');
        // Preflight every table first. Each ALTER replaces its indexes atomically.
        if (!$dryRun) foreach ($plans as $sql) $db->execute($sql);
        return $plans;
    }
}
