<?php
namespace app\common\service\database;

use RuntimeException;
use think\facade\Db;

/** One reviewed TEXT -> MEDIUMTEXT migration; never a general MODIFY executor. */
final class RuntimeVideoConfigCapacity
{
    public static function apply(string $prefix, $connection = null): array
    {
        self::assertPrefix($prefix);
        $table = $prefix.'aigc_video_config';
        $query = static fn(string $sql, array $bindings = []) => $connection ? $connection->query($sql, $bindings) : Db::query($sql, $bindings);
        $tables = $query('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        // The owning app must prepare install.sql first. Missing schema cannot
        // be skipped: its registry would otherwise record a false success.
        if ($tables === []) throw new RuntimeException('视频配置容量迁移缺少目标表，禁止登记成功');
        if (count($tables) !== 1) throw new RuntimeException('视频配置容量迁移无法确定目标表');
        $columns = $query('SELECT COLUMN_NAME AS column_name, DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, CHARACTER_SET_NAME AS character_set_name, COLLATION_NAME AS collation_name, COLUMN_COMMENT AS column_comment, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table,'config_json']);
        if (count($columns) !== 1) throw new RuntimeException('视频配置容量迁移缺少原配置字段');
        $modes = $query('SELECT @@SESSION.sql_mode AS sql_mode');
        if (count($modes) !== 1 || !is_string($modes[0]['sql_mode'] ?? null)) throw new RuntimeException('视频配置容量迁移无法确定字符串语义');
        $noBackslash = in_array('NO_BACKSLASH_ESCAPES', explode(',', strtoupper($modes[0]['sql_mode'])), true);
        $sql = self::columnSql($tables[0], $columns[0], $prefix, $noBackslash);
        if ($sql === null) return ['status'=>'already_sufficient'];
        if ($connection) $connection->execute($sql); else Db::execute($sql);
        $after=$query('SELECT COLUMN_NAME AS column_name, DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, CHARACTER_SET_NAME AS character_set_name, COLLATION_NAME AS collation_name, COLUMN_COMMENT AS column_comment, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table,'config_json']);
        if (count($after)!==1 || self::columnSql($tables[0],$after[0],$prefix,$noBackslash)!==null) throw new RuntimeException('视频配置容量迁移未完成，禁止登记成功');
        foreach (['is_nullable','column_default','character_set_name','collation_name','column_comment','extra'] as $key) {
            if ($after[0][$key]!==$columns[0][$key]) throw new RuntimeException('视频配置容量迁移改变了原字段语义，禁止登记成功');
        }
        return ['status'=>'widened'];
    }

    /** Pure schema guard, also exercised without opening a database connection. */
    public static function columnSql(array $table, array $column, string $prefix, bool $noBackslash = false): ?string
    {
        self::assertPrefix($prefix);
        if (strcasecmp((string)($table['engine'] ?? ''),'InnoDB') !== 0) throw new RuntimeException('视频配置容量迁移要求目标表为 InnoDB');
        $type = strtolower((string)($column['data_type'] ?? ''));
        if (($column['column_name'] ?? null) !== 'config_json' || !in_array($type,['text','mediumtext'],true)
            || strtolower((string)($column['column_type'] ?? '')) !== $type) throw new RuntimeException('视频配置容量迁移只接受 TEXT 或已完成的 MEDIUMTEXT');
        if (!array_key_exists('column_default',$column) || $column['column_default'] !== null
            || ($column['extra'] ?? null) !== '') throw new RuntimeException('视频配置容量迁移不支持未知默认值或生成字段');
        $nullable = $column['is_nullable'] ?? null;
        if (!in_array($nullable,['YES','NO'],true)) throw new RuntimeException('视频配置容量迁移无法确定 NULL 语义');
        foreach (['character_set_name','collation_name'] as $key) {
            if (!is_string($column[$key] ?? null) || !preg_match('/^[a-z0-9_]+$/iD',$column[$key])) throw new RuntimeException('视频配置容量迁移无法保留字符集与排序规则');
        }
        if (!is_string($column['column_comment'] ?? null)) throw new RuntimeException('视频配置容量迁移无法保留字段注释');
        if ($type === 'mediumtext') return null;
        $comment = $column['column_comment'];
        if (!$noBackslash) $comment = str_replace('\\','\\\\',$comment);
        $comment = str_replace("'","''",$comment);
        return 'ALTER TABLE `'.$prefix.'aigc_video_config` MODIFY COLUMN `config_json` MEDIUMTEXT CHARACTER SET `'.$column['character_set_name'].'` COLLATE `'.$column['collation_name'].'`'
            .($nullable === 'YES' ? ' NULL DEFAULT NULL' : ' NOT NULL')." COMMENT '".$comment."'";
    }

    private static function assertPrefix(string $prefix): void
    {
        if (!preg_match('/^[a-z0-9_]*$/iD',$prefix)) throw new RuntimeException('视频配置容量迁移表前缀不合法');
    }
}
