<?php
namespace app\common\service\database;

use RuntimeException;
use think\facade\Db;

/** Reviewed bridge for the published canvas scope SQL; no general dynamic-SQL allowance. */
final class CanvasConversationSchema
{
    private const LEGACY_SHA256 = '430d3ee2b2a8a262ea12a68fa7e7c965d0bea09e5159d6e8252f6109f59f0f1c';
    public const OWNER_SQL = 'ALTER TABLE `la_aigc_llm_agent_preference` DROP INDEX `owner`, ADD UNIQUE KEY `owner` (`tenant_id`,`user_id`,`workspace_key`)';

    public static function expand(string $content): string
    {
        if (!hash_equals(self::LEGACY_SHA256, hash('sha256', trim($content)))) return $content;
        return "ALTER TABLE `la_aigc_llm_session` ADD COLUMN `workspace_key` varchar(160) NOT NULL DEFAULT 'home';\n"
            . "ALTER TABLE `la_aigc_llm_agent_preference` ADD COLUMN `workspace_key` varchar(160) NOT NULL DEFAULT 'aigc_llm';\n"
            . self::OWNER_SQL . ';';
    }

    public static function isOwnerSql(string $sql): bool
    {
        return trim($sql) === self::OWNER_SQL;
    }

    /** Widen only the known unique owner index, atomically, without rewriting any record. */
    public static function ensureOwner(string $prefix, $connection = null): void
    {
        if (!preg_match('/^[a-z0-9_]*$/iD', $prefix)) throw new RuntimeException('Invalid canvas schema prefix');
        $table = $prefix.'aigc_llm_agent_preference';
        $query = static fn(string $sql, array $bindings = []) => $connection ? $connection->query($sql, $bindings) : Db::query($sql, $bindings);
        $columns = $query('SELECT DATA_TYPE AS data_type, CHARACTER_MAXIMUM_LENGTH AS capacity, IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, 'workspace_key']);
        if (count($columns) !== 1 || $columns[0]['data_type'] !== 'varchar' || (int)$columns[0]['capacity'] < 160 || $columns[0]['nullable'] !== 'NO') {
            throw new RuntimeException('Canvas preference workspace column is incompatible');
        }
        $read = static fn() => $query('SELECT COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique, SUB_PART AS sub_part FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX', [$table, 'owner']);
        $index = $read();
        foreach ($index as $part) if ((int)$part['non_unique'] !== 0 || $part['sub_part'] !== null) throw new RuntimeException('Canvas preference owner index is incompatible');
        $names = array_column($index, 'column_name');
        if ($names === ['tenant_id','user_id','workspace_key']) return;
        if ($names !== ['tenant_id','user_id']) throw new RuntimeException('Unknown canvas preference owner index; original data retained');
        $sql = str_replace('`la_', '`'.$prefix, self::OWNER_SQL);
        if ($connection) $connection->execute($sql); else Db::execute($sql);
        if (array_column($read(), 'column_name') !== ['tenant_id','user_id','workspace_key']) throw new RuntimeException('Canvas preference owner index upgrade incomplete');
    }
}
