<?php
namespace app\common\service\database;

use RuntimeException;
use think\facade\Db;

/** Exact compatibility for the original shared-frame schema; never enables arbitrary dynamic SQL. */
final class MicroVideoFrameSchema
{
    private const LEGACY_SHA256 = '81b2146dd8ec574758d208871ecb8d76646f2f9bd780ad7740f8f42888a7d516';
    public const OWNER_SQL = 'ALTER TABLE `la_aigc_micro_video_attempt` DROP INDEX `uk_execution`, ADD UNIQUE KEY `uk_execution` (`task_id`,`segment_id`,`frame_id`,`kind`,`attempt_no`)';

    public static function expand(string $content): string
    {
        if (!hash_equals(self::LEGACY_SHA256, hash('sha256', trim($content)))) return $content;
        return <<<'SQL'
CREATE TABLE IF NOT EXISTS `la_aigc_micro_video_frame` (
 `id` int unsigned NOT NULL AUTO_INCREMENT,
 `tenant_id` int unsigned NOT NULL,
 `user_id` int unsigned NOT NULL,
 `task_id` int unsigned NOT NULL,
 `boundary_index` int unsigned NOT NULL,
 `version` int unsigned NOT NULL,
 `is_latest` tinyint unsigned NULL DEFAULT 1,
 `prompt` longtext NOT NULL,
 `reference_indices` text NOT NULL,
 `prompt_fingerprint` varchar(64) NOT NULL DEFAULT '',
 `dependency_fingerprint` varchar(64) NOT NULL DEFAULT '',
 `source_fingerprint` varchar(64) NOT NULL DEFAULT '',
 `status` varchar(30) NOT NULL DEFAULT 'pending',
 `confirmed` tinyint NOT NULL DEFAULT 0,
 `image_uri` varchar(1000) NOT NULL DEFAULT '',
 `storage_scope` varchar(20) NOT NULL DEFAULT '',
 `storage_engine` varchar(30) NOT NULL DEFAULT '',
 `storage_domain` varchar(255) NOT NULL DEFAULT '',
 `attempt_id` int unsigned NOT NULL DEFAULT 0,
 `user_charge_points` decimal(16,4) NOT NULL DEFAULT 0,
 `error` varchar(1000) NOT NULL DEFAULT '',
 `create_time` int unsigned NOT NULL DEFAULT 0,
 `update_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_boundary_version` (`task_id`,`boundary_index`,`version`),
 UNIQUE KEY `uk_current_boundary` (`task_id`,`boundary_index`,`is_latest`), KEY `idx_owner` (`tenant_id`,`user_id`,`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微视频共享边界版本';
CREATE TABLE IF NOT EXISTS `la_aigc_micro_video_frame_batch` (
 `id` int unsigned NOT NULL AUTO_INCREMENT,
 `tenant_id` int unsigned NOT NULL,
 `user_id` int unsigned NOT NULL,
 `task_id` int unsigned NOT NULL,
 `idempotency_key` varchar(100) NOT NULL,
 `request_hash` varchar(64) NOT NULL,
 `quote_json` longtext NOT NULL,
 `frame_ids` text NOT NULL,
 `status` varchar(30) NOT NULL DEFAULT 'pending',
 `create_time` int unsigned NOT NULL DEFAULT 0,
 `update_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_admission` (`tenant_id`,`user_id`,`task_id`,`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微视频帧图幂等受理';

ALTER TABLE `la_aigc_micro_video_task` ADD COLUMN `generation_mode` varchar(20) NOT NULL DEFAULT 'reference';
ALTER TABLE `la_aigc_micro_video_task` ADD COLUMN `frame_status` varchar(30) NOT NULL DEFAULT 'off';
ALTER TABLE `la_aigc_micro_video_task` ADD COLUMN `frame_model_id` varchar(100) NOT NULL DEFAULT '';
ALTER TABLE `la_aigc_micro_video_task` ADD COLUMN `frame_quality` varchar(40) NOT NULL DEFAULT '';
ALTER TABLE `la_aigc_micro_video_task` ADD COLUMN `frames_revision` int unsigned NOT NULL DEFAULT 0;
ALTER TABLE `la_aigc_micro_video_task` ADD COLUMN `frame_quote_json` longtext NULL;
ALTER TABLE `la_aigc_micro_video_task` ADD COLUMN `frame_quote_token` varchar(64) NOT NULL DEFAULT '';
ALTER TABLE `la_aigc_micro_video_task` ADD COLUMN `frame_quote_expires` int unsigned NOT NULL DEFAULT 0;
ALTER TABLE `la_aigc_micro_video_task` ADD COLUMN `frame_batch_id` int unsigned NOT NULL DEFAULT 0;
ALTER TABLE `la_aigc_micro_video_segment` ADD COLUMN `first_frame_id` int unsigned NOT NULL DEFAULT 0;
ALTER TABLE `la_aigc_micro_video_segment` ADD COLUMN `last_frame_id` int unsigned NOT NULL DEFAULT 0;
ALTER TABLE `la_aigc_micro_video_attempt` ADD COLUMN `frame_id` int unsigned NOT NULL DEFAULT 0;

SQL
        . self::OWNER_SQL . ';';
    }

    public static function isOwnerSql(string $sql): bool { return trim($sql) === self::OWNER_SQL; }

    public static function ensureOwner(string $prefix, $connection = null): void
    {
        if (!preg_match('/^[a-z0-9_]*$/iD', $prefix)) throw new RuntimeException('Invalid micro-video schema prefix');
        $table = $prefix.'aigc_micro_video_attempt';
        $query = static fn(string $sql, array $bindings = []) => $connection ? $connection->query($sql, $bindings) : Db::query($sql, $bindings);
        $column = $query('SELECT DATA_TYPE AS data_type, IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', [$table, 'frame_id']);
        if (count($column)!==1 || $column[0]['data_type']!=='int' || $column[0]['nullable']!=='NO') throw new RuntimeException('Micro-video frame column is incompatible');
        $read = static fn() => $query('SELECT COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique, SUB_PART AS sub_part FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX', [$table, 'uk_execution']);
        $index = $read();
        foreach ($index as $part) if ((int)$part['non_unique']!==0 || $part['sub_part']!==null) throw new RuntimeException('Micro-video execution index is incompatible');
        $names = array_column($index, 'column_name');
        $expected = ['task_id','segment_id','frame_id','kind','attempt_no'];
        if ($names === $expected) return;
        if ($names !== ['task_id','segment_id','kind','attempt_no']) throw new RuntimeException('Unknown micro-video execution index; original data retained');
        $sql = str_replace('`la_', '`'.$prefix, self::OWNER_SQL);
        if ($connection) $connection->execute($sql); else Db::execute($sql);
        if (array_column($read(), 'column_name') !== $expected) throw new RuntimeException('Micro-video execution index upgrade incomplete');
    }
}
