<?php
namespace app\common\service\database;

use RuntimeException;
use think\facade\Db;

/** Reviewed snapshot columns only: repair legacy omissions or preserve live semantics when widening. */
final class RuntimeGenerationSnapshotCapacity
{
    private const COLUMNS = [
        'aigc_video_task'=>'pricing_snapshot', 'aigc_digital_human_task'=>'pricing_snapshot',
        'aigc_product_promo_video_task'=>'pricing_snapshot', 'aigc_action_transfer_task'=>'pricing_snapshot',
        'aigc_person_replacement_task'=>'pricing_snapshot', 'aigc_short_drama_script_task'=>'pricing_snapshot',
        'aigc_short_drama_generation_task'=>'pricing_snapshot', 'aigc_music_cover_task'=>'pricing_snapshot',
        'aigc_music_task'=>'pricing_snapshot', 'aigc_watermark_removal_task'=>'pricing_snapshot',
        'aigc_pic_lipsync_task'=>'pricing_snapshot', 'image_human_task'=>'pricing_snapshot',
        'ai_consumption_log'=>'price_snapshot',
    ];

    public static function target(string $sql): ?array
    {
        if (!preg_match('/^\s*ALTER\s+TABLE\s+`?la_([a-z0-9_]+)`?\s+MODIFY\s+(?:COLUMN\s+)?`?([a-z0-9_]+)`?\s+MEDIUMTEXT\s*$/iD', $sql, $match)) return null;
        $table = strtolower($match[1]); $column = strtolower($match[2]);
        return (self::COLUMNS[$table] ?? null) === $column ? [$table, $column] : null;
    }

    public static function apply(string $suffix, string $column, string $prefix, $connection = null): array
    {
        self::assertTarget($suffix, $column, $prefix);
        $table = $prefix.$suffix;
        // PDO::ATTR_CASE may change aliases too. Normalize metadata keys only;
        // keep the actual identifiers and all field semantics from the server.
        $query = static function (string $sql, array $bindings = []) use ($connection): array {
            $rows = $connection ? $connection->query($sql, $bindings) : Db::query($sql, $bindings);
            return array_map(static function (array $row): array {
                $normalized = array_change_key_case($row, CASE_LOWER);
                if (count($normalized) !== count($row)) throw new RuntimeException('生成快照容量迁移元数据键不明确');
                return $normalized;
            }, $rows);
        };
        $tables = $query('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        // The core upgrade also visits optional apps. Their own install creates
        // the original table before running this same capacity upgrade.
        if ($tables === []) return ['status'=>'optional_table_absent'];
        if (count($tables) !== 1) throw new RuntimeException('生成快照容量迁移无法确定目标表');
        $read = static fn() => $query('SELECT COLUMN_NAME AS column_name, DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, CHARACTER_SET_NAME AS character_set_name, COLLATION_NAME AS collation_name, COLUMN_COMMENT AS column_comment, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, $column]);
        $columns = $read();
        // CREATE TABLE IF NOT EXISTS cannot repair older app tables. Historical
        // install migrations may already be adopted, so this reviewed intent
        // must also add the nullable snapshot when the original column is absent.
        if ($columns === []) {
            if (strcasecmp((string)($tables[0]['engine'] ?? ''), 'InnoDB') !== 0) throw new RuntimeException('生成快照容量迁移要求 InnoDB');
            $sql = 'ALTER TABLE `'.$table.'` ADD COLUMN `'.$column.'` MEDIUMTEXT NULL DEFAULT NULL';
            if ($connection) $connection->execute($sql); else Db::execute($sql);
            $after = $read();
            if (count($after) !== 1 || strtolower((string)$after[0]['data_type']) !== 'mediumtext'
                || $after[0]['is_nullable'] !== 'YES' || $after[0]['column_default'] !== null
                || self::columnSql($suffix, $column, $tables[0], $after[0], $prefix) !== null) {
                throw new RuntimeException('生成快照容量迁移补齐字段未完成: '.$table.'.'.$column);
            }
            return ['status'=>'added'];
        }
        if (count($columns) !== 1) throw new RuntimeException('生成快照容量迁移无法确定原字段: '.$table.'.'.$column);
        $modes = $query('SELECT @@SESSION.sql_mode AS sql_mode');
        if (count($modes) !== 1 || !is_string($modes[0]['sql_mode'] ?? null)) throw new RuntimeException('生成快照容量迁移无法确定字符串语义');
        $noBackslash = in_array('NO_BACKSLASH_ESCAPES', explode(',', strtoupper($modes[0]['sql_mode'])), true);
        $sql = self::columnSql($suffix, $column, $tables[0], $columns[0], $prefix, $noBackslash);
        if ($sql === null) return ['status'=>'already_sufficient'];
        if ($connection) $connection->execute($sql); else Db::execute($sql);
        $after = $read();
        if (count($after) !== 1 || strtolower((string)$after[0]['data_type']) !== 'mediumtext') throw new RuntimeException('生成快照容量迁移未完成');
        foreach (['column_name','is_nullable','column_default','character_set_name','collation_name','column_comment','extra'] as $key) {
            if ($after[0][$key] !== $columns[0][$key]) throw new RuntimeException('生成快照容量迁移改变了原字段语义');
        }
        return ['status'=>'widened'];
    }

    public static function columnSql(string $suffix, string $name, array $table, array $column, string $prefix, bool $noBackslash = false): ?string
    {
        self::assertTarget($suffix, $name, $prefix);
        if (strcasecmp((string)($table['engine'] ?? ''), 'InnoDB') !== 0) throw new RuntimeException('生成快照容量迁移要求 InnoDB');
        $type = strtolower((string)($column['data_type'] ?? ''));
        $actualName = $column['column_name'] ?? null;
        // MySQL column names are case-insensitive, but information_schema
        // preserves the original spelling. Do not rename a legacy column.
        if (!is_string($actualName) || strcasecmp($actualName, $name) !== 0 || !in_array($type, ['text','mediumtext','longtext'], true)
            || strtolower((string)($column['column_type'] ?? '')) !== $type || ($column['extra'] ?? null) !== '') throw new RuntimeException('生成快照容量迁移字段类型或属性不受支持');
        if (!in_array($column['is_nullable'] ?? null, ['YES','NO'], true) || !array_key_exists('column_default', $column)
            || ($column['column_default'] !== null && !is_string($column['column_default']))) throw new RuntimeException('生成快照容量迁移无法保留原默认值');
        foreach (['character_set_name','collation_name'] as $key) {
            if (!is_string($column[$key] ?? null) || !preg_match('/^[a-z0-9_]+$/iD', $column[$key])) throw new RuntimeException('生成快照容量迁移字符集或排序规则不受支持');
        }
        if (!is_string($column['column_comment'] ?? null)) throw new RuntimeException('生成快照容量迁移无法保留原注释');
        if ($type !== 'text') return null;
        $quote = static function (string $value) use ($noBackslash): string {
            if (!$noBackslash) $value = str_replace('\\', '\\\\', $value);
            return "'".str_replace("'", "''", $value)."'";
        };
        $sql = 'ALTER TABLE `'.$prefix.$suffix.'` MODIFY COLUMN `'.$actualName.'` MEDIUMTEXT CHARACTER SET `'.$column['character_set_name'].'` COLLATE `'.$column['collation_name'].'`'
            .($column['is_nullable'] === 'YES' ? ' NULL' : ' NOT NULL');
        if ($column['column_default'] !== null) $sql .= ' DEFAULT '.$quote($column['column_default']);
        elseif ($column['is_nullable'] === 'YES') $sql .= ' DEFAULT NULL';
        return $sql.' COMMENT '.$quote($column['column_comment']);
    }

    private static function assertTarget(string $table, string $column, string $prefix): void
    {
        if ((self::COLUMNS[$table] ?? null) !== $column || !preg_match('/^[a-z0-9_]*$/iD', $prefix)) throw new RuntimeException('生成快照容量迁移目标或表前缀不合法');
    }
}
