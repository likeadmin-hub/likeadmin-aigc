<?php
namespace app\common\service\database;

use RuntimeException;
use think\facade\Db;

/** Only updater JSON metadata; never changes business tables or truncates diagnostics. */
final class RuntimeUpdateRecordCapacity
{
    private const COLUMNS = [
        'update_task' => ['preflight_json', 'result_json'],
        'update_package' => ['manifest_json'],
    ];

    public static function target(string $sql): ?array
    {
        if (!preg_match('/^\s*ALTER\s+TABLE\s+`?la_(update_task|update_package)`?\s+MODIFY\s+(?:COLUMN\s+)?`?([a-z0-9_]+)`?\s+MEDIUMTEXT\s*$/iD', $sql, $match)) return null;
        $table = strtolower($match[1]); $column = strtolower($match[2]);
        return in_array($column, self::COLUMNS[$table], true) ? [$table, $column] : null;
    }

    /** Called only by explicit update writes, before entering completion transactions. */
    public static function ensure(): void
    {
        self::all((string)config('database.connections.mysql.prefix'));
    }

    public static function all(string $prefix, $connection = null, bool $execute = true): array
    {
        $result = [];
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) $result[$table.'.'.$column] = self::apply($table, $column, $prefix, $connection, $execute);
        }
        return $result;
    }

    public static function apply(string $suffix, string $name, string $prefix, $connection = null, bool $execute = true): array
    {
        self::assertTarget($suffix, $name, $prefix);
        $connection = $connection ?? Db::connect();
        $query = static function (string $sql, array $bindings = []) use ($connection): array {
            return array_map(static fn(array $row): array => array_change_key_case($row, CASE_LOWER), $connection->query($sql, $bindings));
        };
        $table = $prefix.$suffix;
        $tables = $query('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        if (count($tables) !== 1) throw new RuntimeException('更新记录容量修复缺少目标表，请检查安装结构');
        $read = static fn() => $query('SELECT COLUMN_NAME AS column_name, DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, CHARACTER_SET_NAME AS character_set_name, COLLATION_NAME AS collation_name, COLUMN_COMMENT AS column_comment, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $name]);
        $columns = $read();
        if (count($columns) !== 1) throw new RuntimeException('更新记录容量修复缺少原字段，请检查安装结构');
        $modes = $query('SELECT @@SESSION.sql_mode AS sql_mode');
        if (count($modes) !== 1 || !is_string($modes[0]['sql_mode'] ?? null)) throw new RuntimeException('更新记录容量修复无法确定字符串语义');
        $noBackslash = in_array('NO_BACKSLASH_ESCAPES', explode(',', strtoupper($modes[0]['sql_mode'])), true);
        $sql = self::columnSql($suffix, $name, $tables[0], $columns[0], $prefix, $noBackslash);
        if ($sql === null) return ['status' => 'already_sufficient'];
        if (!$execute) return ['status' => 'requires_widening'];
        // MySQL ALTER implicitly commits. Never let a capacity repair commit application work.
        $pdo = $connection->getPdo();
        if (!$pdo || $pdo->inTransaction()) throw new RuntimeException('更新记录容量修复必须在事务外执行，请先运行升级前修复工具');
        $connection->execute($sql);
        $after = $read();
        if (count($after) !== 1 || self::columnSql($suffix, $name, $tables[0], $after[0], $prefix, $noBackslash) !== null) throw new RuntimeException('更新记录容量修复未完成');
        foreach (['column_name', 'is_nullable', 'column_default', 'character_set_name', 'collation_name', 'column_comment', 'extra'] as $key) {
            if ($after[0][$key] !== $columns[0][$key]) throw new RuntimeException('更新记录容量修复改变了原字段语义');
        }
        return ['status' => 'widened'];
    }

    public static function columnSql(string $suffix, string $name, array $table, array $column, string $prefix, bool $noBackslash = false): ?string
    {
        self::assertTarget($suffix, $name, $prefix);
        if (strcasecmp((string)($table['engine'] ?? ''), 'InnoDB') !== 0) throw new RuntimeException('更新记录容量修复要求目标表使用 InnoDB');
        $type = strtolower((string)($column['data_type'] ?? ''));
        $actual = $column['column_name'] ?? null;
        if (!is_string($actual) || strcasecmp($actual, $name) !== 0 || !in_array($type, ['text', 'mediumtext', 'longtext'], true)
            || strtolower((string)($column['column_type'] ?? '')) !== $type || ($column['extra'] ?? null) !== '') throw new RuntimeException('更新记录容量修复字段类型或属性不受支持');
        if (!in_array($column['is_nullable'] ?? null, ['YES', 'NO'], true) || !array_key_exists('column_default', $column)
            || ($column['column_default'] !== null && !is_string($column['column_default']))) throw new RuntimeException('更新记录容量修复无法保留原默认值');
        foreach (['character_set_name', 'collation_name'] as $key) {
            if (!is_string($column[$key] ?? null) || !preg_match('/^[a-z0-9_]+$/iD', $column[$key])) throw new RuntimeException('更新记录容量修复无法保留字符集与排序规则');
        }
        if (!is_string($column['column_comment'] ?? null)) throw new RuntimeException('更新记录容量修复无法保留原注释');
        if ($type !== 'text') return null; // Never downgrade an existing LONGTEXT.
        $quote = static function (string $value) use ($noBackslash): string {
            if (!$noBackslash) $value = str_replace('\\', '\\\\', $value);
            return "'".str_replace("'", "''", $value)."'";
        };
        $sql = 'ALTER TABLE `'.$prefix.$suffix.'` MODIFY COLUMN `'.$actual.'` MEDIUMTEXT CHARACTER SET `'.$column['character_set_name'].'` COLLATE `'.$column['collation_name'].'`'
            .($column['is_nullable'] === 'YES' ? ' NULL' : ' NOT NULL');
        if ($column['column_default'] !== null) $sql .= ' DEFAULT '.$quote($column['column_default']);
        elseif ($column['is_nullable'] === 'YES') $sql .= ' DEFAULT NULL';
        return $sql.' COMMENT '.$quote($column['column_comment']);
    }

    private static function assertTarget(string $table, string $column, string $prefix): void
    {
        if (!in_array($column, self::COLUMNS[$table] ?? [], true) || !preg_match('/^[a-z0-9_]*$/iD', $prefix)) throw new RuntimeException('更新记录容量修复目标或表前缀不合法');
    }
}
