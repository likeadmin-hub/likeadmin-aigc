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

SET @micro_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_micro_video_task' AND COLUMN_NAME='generation_mode')=0, 'ALTER TABLE `la_aigc_micro_video_task` ADD COLUMN `generation_mode` varchar(20) NOT NULL DEFAULT ''reference''', 'SELECT 1');
PREPARE micro_stmt FROM @micro_sql;
EXECUTE micro_stmt;
DEALLOCATE PREPARE micro_stmt;
SET @micro_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_micro_video_task' AND COLUMN_NAME='frame_status')=0, 'ALTER TABLE `la_aigc_micro_video_task` ADD COLUMN `frame_status` varchar(30) NOT NULL DEFAULT ''off''', 'SELECT 1');
PREPARE micro_stmt FROM @micro_sql;
EXECUTE micro_stmt;
DEALLOCATE PREPARE micro_stmt;
SET @micro_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_micro_video_task' AND COLUMN_NAME='frame_model_id')=0, 'ALTER TABLE `la_aigc_micro_video_task` ADD COLUMN `frame_model_id` varchar(100) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE micro_stmt FROM @micro_sql;
EXECUTE micro_stmt;
DEALLOCATE PREPARE micro_stmt;
SET @micro_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_micro_video_task' AND COLUMN_NAME='frame_quality')=0, 'ALTER TABLE `la_aigc_micro_video_task` ADD COLUMN `frame_quality` varchar(40) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE micro_stmt FROM @micro_sql;
EXECUTE micro_stmt;
DEALLOCATE PREPARE micro_stmt;
SET @micro_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_micro_video_task' AND COLUMN_NAME='frames_revision')=0, 'ALTER TABLE `la_aigc_micro_video_task` ADD COLUMN `frames_revision` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE micro_stmt FROM @micro_sql;
EXECUTE micro_stmt;
DEALLOCATE PREPARE micro_stmt;
SET @micro_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_micro_video_task' AND COLUMN_NAME='frame_quote_json')=0, 'ALTER TABLE `la_aigc_micro_video_task` ADD COLUMN `frame_quote_json` longtext NULL', 'SELECT 1');
PREPARE micro_stmt FROM @micro_sql;
EXECUTE micro_stmt;
DEALLOCATE PREPARE micro_stmt;
SET @micro_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_micro_video_task' AND COLUMN_NAME='frame_quote_token')=0, 'ALTER TABLE `la_aigc_micro_video_task` ADD COLUMN `frame_quote_token` varchar(64) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE micro_stmt FROM @micro_sql;
EXECUTE micro_stmt;
DEALLOCATE PREPARE micro_stmt;
SET @micro_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_micro_video_task' AND COLUMN_NAME='frame_quote_expires')=0, 'ALTER TABLE `la_aigc_micro_video_task` ADD COLUMN `frame_quote_expires` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE micro_stmt FROM @micro_sql;
EXECUTE micro_stmt;
DEALLOCATE PREPARE micro_stmt;
SET @micro_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_micro_video_task' AND COLUMN_NAME='frame_batch_id')=0, 'ALTER TABLE `la_aigc_micro_video_task` ADD COLUMN `frame_batch_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE micro_stmt FROM @micro_sql;
EXECUTE micro_stmt;
DEALLOCATE PREPARE micro_stmt;
SET @micro_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_micro_video_segment' AND COLUMN_NAME='first_frame_id')=0, 'ALTER TABLE `la_aigc_micro_video_segment` ADD COLUMN `first_frame_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE micro_stmt FROM @micro_sql;
EXECUTE micro_stmt;
DEALLOCATE PREPARE micro_stmt;
SET @micro_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_micro_video_segment' AND COLUMN_NAME='last_frame_id')=0, 'ALTER TABLE `la_aigc_micro_video_segment` ADD COLUMN `last_frame_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE micro_stmt FROM @micro_sql;
EXECUTE micro_stmt;
DEALLOCATE PREPARE micro_stmt;
SET @micro_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_micro_video_attempt' AND COLUMN_NAME='frame_id')=0, 'ALTER TABLE `la_aigc_micro_video_attempt` ADD COLUMN `frame_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE micro_stmt FROM @micro_sql;
EXECUTE micro_stmt;
DEALLOCATE PREPARE micro_stmt;
SET @micro_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_micro_video_attempt' AND INDEX_NAME='uk_execution' AND COLUMN_NAME='frame_id')=0, 'ALTER TABLE `la_aigc_micro_video_attempt` DROP INDEX `uk_execution`, ADD UNIQUE KEY `uk_execution` (`task_id`,`segment_id`,`frame_id`,`kind`,`attempt_no`)', 'SELECT 1');
PREPARE micro_stmt FROM @micro_sql;
EXECUTE micro_stmt;
DEALLOCATE PREPARE micro_stmt;
