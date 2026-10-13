<?php
namespace app\common\service\database;

use RuntimeException;
use think\facade\Db;

/** Only the five reviewed sale-price columns may become nullable; values/types stay unchanged. */
final class RuntimeAppSalePriceInheritance
{
    private const TABLES = ['aigc_local_redraw_config','aigc_one_click_cleanup_config','aigc_product_suite_config',
        'aigc_product_multi_angle_config','aigc_fashion_lookbook_config'];

    public static function target(string $sql): ?string
    {
        if (!preg_match("/^\s*ALTER\s+TABLE\s+`?(la_[a-z0-9_]+)`?\s+MODIFY\s+(?:COLUMN\s+)?`?unit_price`?\s+decimal\(12,2\)\s+NULL\s+DEFAULT\s+NULL\s+COMMENT\s+'用户售价，NULL跟随算力市场，0免费'\s*$/iD", $sql, $match)) return null;
        $table = substr(strtolower($match[1]), 3);
        return in_array($table, self::TABLES, true) ? $table : null;
    }

    public static function apply(string $table, string $prefix, $connection = null): array
    {
        self::assertTarget($table, $prefix);
        $query = static fn(string $sql, array $bindings = []) => $connection ? $connection->query($sql, $bindings) : Db::query($sql, $bindings);
        $tables = $query('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$prefix.$table]);
        if (count($tables) !== 1) throw new RuntimeException('应用售价继承迁移缺少目标表，禁止登记成功');
        $columnQuery = 'SELECT COLUMN_NAME AS column_name, DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, CHARACTER_SET_NAME AS character_set_name, COLLATION_NAME AS collation_name, COLUMN_COMMENT AS column_comment, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?';
        $columns = $query($columnQuery, [$prefix.$table, 'unit_price']);
        if (count($columns) !== 1) throw new RuntimeException('应用售价继承迁移缺少原售价字段');
        $modes = $query('SELECT @@SESSION.sql_mode AS sql_mode');
        if (count($modes) !== 1 || !is_string($modes[0]['sql_mode'] ?? null)) throw new RuntimeException('应用售价继承迁移无法确定字符串语义');
        $noBackslash = in_array('NO_BACKSLASH_ESCAPES', explode(',', strtoupper($modes[0]['sql_mode'])), true);
        $sql = self::columnSql($table, $tables[0], $columns[0], $prefix, $noBackslash);
        if ($sql === null) return ['status'=>'already_nullable'];
        if ($connection) $connection->execute($sql); else Db::execute($sql);
        $after = $query($columnQuery, [$prefix.$table, 'unit_price']);
        if (count($after) !== 1 || self::columnSql($table, $tables[0], $after[0], $prefix, $noBackslash) !== null) {
            throw new RuntimeException('应用售价继承迁移未完成，禁止登记成功');
        }
        foreach (['column_name','data_type','column_type','character_set_name','collation_name','column_comment','extra'] as $key) {
            if ($after[0][$key] !== $columns[0][$key]) throw new RuntimeException('应用售价继承迁移改变了原数值字段语义');
        }
        return ['status'=>'made_nullable'];
    }

    /** Build a lossless ALTER from observed metadata rather than imposing decimal(12,2). */
    public static function columnSql(string $table, array $metadata, array $column, string $prefix, bool $noBackslash = false): ?string
    {
        self::assertTarget($table, $prefix);
        if (strcasecmp((string)($metadata['engine'] ?? ''), 'InnoDB') !== 0) throw new RuntimeException('应用售价继承迁移要求目标表为 InnoDB');
        $type = (string)($column['column_type'] ?? '');
        if (($column['column_name'] ?? null) !== 'unit_price' || strtolower((string)($column['data_type'] ?? '')) !== 'decimal'
            || !preg_match('/^decimal\(([1-9][0-9]?),([0-9]{1,2})\)(?: unsigned)?$/iD', $type, $match)
            || (int)$match[1] > 65 || (int)$match[2] > 30 || (int)$match[2] > (int)$match[1]) {
            throw new RuntimeException('应用售价继承迁移仅支持原 DECIMAL 售价字段');
        }
        if (!array_key_exists('column_default', $column) || ($column['column_default'] !== null && !is_numeric($column['column_default']))
            || ($column['extra'] ?? null) !== '' || ($column['character_set_name'] ?? null) !== null || ($column['collation_name'] ?? null) !== null) {
            throw new RuntimeException('应用售价继承迁移不支持未知默认值或生成字段');
        }
        if (!in_array($column['is_nullable'] ?? null, ['YES','NO'], true) || !is_string($column['column_comment'] ?? null)) {
            throw new RuntimeException('应用售价继承迁移无法确定原字段语义');
        }
        if ($column['is_nullable'] === 'YES' && $column['column_default'] === null) return null;
        $comment = $column['column_comment'];
        if (!$noBackslash) $comment = str_replace('\\', '\\\\', $comment);
        $comment = str_replace("'", "''", $comment);
        return 'ALTER TABLE `'.$prefix.$table.'` MODIFY COLUMN `unit_price` '.$type." NULL DEFAULT NULL COMMENT '".$comment."'";
    }

    private static function assertTarget(string $table, string $prefix): void
    {
        if (!in_array($table, self::TABLES, true) || !preg_match('/^[a-z0-9_]*$/iD', $prefix)) throw new RuntimeException('应用售价继承迁移目标不受支持');
    }
}
