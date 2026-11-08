<?php
namespace app\common\service\database;

use RuntimeException;
use think\facade\Db;

/** One reviewed billing column: losslessly widen historical unsigned INT seconds. */
final class RuntimeAvatarBillingQuantity
{
    public static function target(string $sql): bool
    {
        return preg_match('/^\s*ALTER\s+TABLE\s+`?la_aigc_digital_human_billing`?\s+MODIFY\s+(?:COLUMN\s+)?`?quantity`?\s+DECIMAL\(16,6\)\s+UNSIGNED\s*$/iD', $sql) === 1;
    }

    public static function apply(string $prefix, $connection = null): array
    {
        if (!preg_match('/^[a-z0-9_]*$/iD', $prefix)) throw new RuntimeException('数字人账务数量迁移表前缀无效');
        $table = $prefix.'aigc_digital_human_billing';
        $query = static fn(string $sql, array $bindings = []) => $connection ? $connection->query($sql, $bindings) : Db::query($sql, $bindings);
        $tables = $query('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        if ($tables === []) return ['status'=>'optional_table_absent'];
        if (count($tables) !== 1 || strcasecmp((string)$tables[0]['engine'], 'InnoDB') !== 0) throw new RuntimeException('数字人账务数量迁移要求 InnoDB');
        $read = static fn() => $query('SELECT COLUMN_NAME AS column_name, DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, CHARACTER_SET_NAME AS character_set_name, COLLATION_NAME AS collation_name, COLUMN_COMMENT AS column_comment, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, 'quantity']);
        $columns = $read();
        if (count($columns) !== 1) throw new RuntimeException('数字人账务数量迁移缺少原数量字段');
        $column = $columns[0];
        $type = strtolower((string)$column['column_type']);
        if ($column['extra'] !== '' || $column['character_set_name'] !== null || $column['collation_name'] !== null
            || !in_array($column['is_nullable'], ['YES','NO'], true) || !is_string($column['column_comment'])
            || ($column['column_default'] !== null && !preg_match('/^\d+(?:\.\d+)?$/D', (string)$column['column_default']))) throw new RuntimeException('数字人账务数量迁移无法保留原字段属性');
        if ($column['data_type'] === 'decimal' && preg_match('/^decimal\((\d+),(\d+)\) unsigned$/D', $type, $matches)
            && (int)$matches[1]-(int)$matches[2] >= 10 && (int)$matches[2] >= 6) return ['status'=>'already_sufficient'];
        if ($column['data_type'] !== 'int' || !preg_match('/^int(?:\(\d+\))? unsigned$/D', $type)) throw new RuntimeException('数字人账务数量迁移仅支持原 unsigned INT 或足够容量 DECIMAL');
        $modes = $query('SELECT @@SESSION.sql_mode AS sql_mode');
        if (count($modes) !== 1 || !is_string($modes[0]['sql_mode'] ?? null)) throw new RuntimeException('数字人账务数量迁移无法确定字符串语义');
        $comment = $column['column_comment'];
        if (!in_array('NO_BACKSLASH_ESCAPES', explode(',', strtoupper($modes[0]['sql_mode'])), true)) $comment = str_replace('\\', '\\\\', $comment);
        $sql = 'ALTER TABLE `'.$table.'` MODIFY COLUMN `quantity` DECIMAL(16,6) UNSIGNED'.($column['is_nullable'] === 'YES' ? ' NULL' : ' NOT NULL');
        if ($column['column_default'] !== null) $sql .= ' DEFAULT '.$column['column_default'];
        elseif ($column['is_nullable'] === 'YES') $sql .= ' DEFAULT NULL';
        $sql .= " COMMENT '".str_replace("'", "''", $comment)."'";
        if ($connection) $connection->execute($sql); else Db::execute($sql);
        $after = $read();
        if (count($after) !== 1 || strtolower($after[0]['column_type']) !== 'decimal(16,6) unsigned') throw new RuntimeException('数字人账务数量迁移未完成');
        foreach (['column_name','is_nullable','character_set_name','collation_name','column_comment','extra'] as $key) {
            if ($column[$key] !== $after[0][$key]) throw new RuntimeException('数字人账务数量迁移改变了原字段属性');
        }
        if (($column['column_default'] === null) !== ($after[0]['column_default'] === null)
            || ($column['column_default'] !== null && bccomp((string)$column['column_default'], (string)$after[0]['column_default'], 6) !== 0)) throw new RuntimeException('数字人账务数量迁移改变了原默认值');
        return ['status'=>'widened'];
    }
}
