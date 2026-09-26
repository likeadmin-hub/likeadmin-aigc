<?php

namespace app\common\service\database;

use think\facade\Db;

/** Explicit migration: never called by a normal login/configuration request. */
class PcWechatMigration
{
    public static function run(string $prefix, $connection = null, bool $dryRun = false): array
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) throw new \RuntimeException('Invalid database prefix');
        $db = $connection ?: Db::connect();
        $rows = $db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        $plans = [];
        $matched = 0;
        foreach ($rows as $row) {
            $table = $row['TABLE_NAME'];
            if (!preg_match('/^' . preg_quote($prefix . 'user_auth', '/') . '(?:_[a-zA-Z0-9]+)?$/D', $table)) continue;
            $matched++;
            $columns = $db->query("SHOW COLUMNS FROM `{$table}`");
            $hasAppid = in_array('appid', array_column($columns, 'Field'), true);
            $appidExpr = $hasAppid ? '`appid`' : "''";
            $duplicates = $db->query("SELECT 1 FROM `{$table}` GROUP BY tenant_id, terminal, {$appidExpr}, openid HAVING COUNT(*) > 1 LIMIT 1");
            if ($duplicates) throw new \RuntimeException('微信身份存在冲突，迁移停止：' . $table);
            $indexes = [];
            foreach ($db->query("SHOW INDEX FROM `{$table}`") as $idx) $indexes[$idx['Key_name']][] = $idx;
            $alter = [];
            if (!$hasAppid) $alter[] = "ADD COLUMN `appid` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'PC网站应用AppID，历史记录待重新授权'";
            $expected = ['tenant_id', 'terminal', 'appid', 'openid'];
            if (isset($indexes['uk_wechat_identity'])) {
                $parts = $indexes['uk_wechat_identity'];
                usort($parts, fn($a, $b) => $a['Seq_in_index'] <=> $b['Seq_in_index']);
                if (array_column($parts, 'Column_name') !== $expected || (int)$parts[0]['Non_unique'] !== 0) throw new \RuntimeException('微信身份索引定义冲突：' . $table);
            } else {
                $alter[] = 'ADD UNIQUE INDEX `uk_wechat_identity` (`tenant_id`,`terminal`,`appid`,`openid`)';
            }
            foreach ($indexes as $name => $parts) {
                if (count($parts) === 1 && (int)$parts[0]['Non_unique'] === 0 && $parts[0]['Column_name'] === 'openid') {
                    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) throw new \RuntimeException('Unexpected index name');
                    $alter[] = "DROP INDEX `{$name}`";
                }
            }
            if ($alter) $plans[] = "ALTER TABLE `{$table}` " . implode(', ', $alter);
        }
        if (!$matched) throw new \RuntimeException('未找到微信身份表');
        // Preflight every table before performing any DDL. Each table changes atomically.
        if (!$dryRun) foreach ($plans as $sql) $db->execute($sql);
        return $plans;
    }
}
