CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_episode_job` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
  `project_id` int unsigned NOT NULL, `episode_id` int unsigned NOT NULL, `job_type` varchar(32) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending', `attempt` int unsigned NOT NULL DEFAULT 0,
  `payload_json` longtext, `result_json` longtext, `error_code` varchar(64) NOT NULL DEFAULT '', `error_msg` text,
  `next_run_time` int unsigned NOT NULL DEFAULT 0, `heartbeat_at` int unsigned NOT NULL DEFAULT 0,
  `lease_token` varchar(100) NOT NULL DEFAULT '', `idempotency_key` varchar(160) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0, `finished_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_episode_job_attempt` (`tenant_id`,`episode_id`,`job_type`,`attempt`), KEY `idx_job_ready` (`status`,`next_run_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_episode_attempt` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT, `job_id` bigint unsigned NOT NULL, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
  `episode_id` int unsigned NOT NULL, `attempt` int unsigned NOT NULL, `session_id` varchar(100) NOT NULL, `task_id` varchar(100) NOT NULL DEFAULT '',
  `request_hash` varchar(64) NOT NULL DEFAULT '', `submit_status` varchar(24) NOT NULL DEFAULT 'created', `provider_request_id` varchar(255) NOT NULL DEFAULT '',
  `heartbeat_at` int unsigned NOT NULL DEFAULT 0, `deadline_at` int unsigned NOT NULL DEFAULT 0, `error_code` varchar(64) NOT NULL DEFAULT '',
  `consumption_id` bigint unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_episode_attempt` (`tenant_id`,`episode_id`,`attempt`), KEY `idx_attempt_job` (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_short_drama_episode_task' AND COLUMN_NAME='queue_job_id')=0, 'ALTER TABLE `la_aigc_short_drama_episode_task` ADD COLUMN `queue_job_id` bigint unsigned NOT NULL DEFAULT 0', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_short_drama_episode_task' AND COLUMN_NAME='session_id')=0, 'ALTER TABLE `la_aigc_short_drama_episode_task` ADD COLUMN `session_id` varchar(100) NOT NULL DEFAULT ''''', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_short_drama_episode_task' AND COLUMN_NAME='attempt_number')=0, 'ALTER TABLE `la_aigc_short_drama_episode_task` ADD COLUMN `attempt_number` int unsigned NOT NULL DEFAULT 0', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_short_drama_episode_task' AND COLUMN_NAME='heartbeat_at')=0, 'ALTER TABLE `la_aigc_short_drama_episode_task` ADD COLUMN `heartbeat_at` int unsigned NOT NULL DEFAULT 0', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_short_drama_episode_task' AND COLUMN_NAME='error_code')=0, 'ALTER TABLE `la_aigc_short_drama_episode_task` ADD COLUMN `error_code` varchar(64) NOT NULL DEFAULT ''''', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_short_drama_episode_task' AND COLUMN_NAME='next_retry_at')=0, 'ALTER TABLE `la_aigc_short_drama_episode_task` ADD COLUMN `next_retry_at` int unsigned NOT NULL DEFAULT 0', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
