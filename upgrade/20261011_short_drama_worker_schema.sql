-- Fresh-install and existing-site short-drama worker schema parity.
-- Additive and repeatable: retain all tasks, leases, app state and tenant data.
SET NAMES utf8mb4;

-- Source: app/apps/aigc_short_drama/migrations/upgrade_20260910_redis_episode_queue.sql
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

-- Source: app/apps/aigc_short_drama/migrations/upgrade_20260919_canvas_video_poster.sql
-- Durable, tenant-scoped first-frame generation for short-drama canvas videos.
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_poster_job` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `canvas_id` int unsigned NOT NULL DEFAULT 0,
  `node_id` varchar(64) NOT NULL DEFAULT '',
  `video_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(30) NOT NULL DEFAULT '',
  `storage_engine` varchar(30) NOT NULL DEFAULT '',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `attempts` int unsigned NOT NULL DEFAULT 0,
  `max_attempts` int unsigned NOT NULL DEFAULT 4,
  `next_run_time` int unsigned NOT NULL DEFAULT 0,
  `lease_token` varchar(120) NOT NULL DEFAULT '',
  `lease_expire_time` int unsigned NOT NULL DEFAULT 0,
  `poster_uri` varchar(500) NOT NULL DEFAULT '',
  `poster_scope` varchar(30) NOT NULL DEFAULT '',
  `poster_engine` varchar(30) NOT NULL DEFAULT '',
  `poster_domain` varchar(255) NOT NULL DEFAULT '',
  `last_error` varchar(500) NOT NULL DEFAULT '',
  `idempotency_key` char(40) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_idempotency` (`idempotency_key`),
  KEY `idx_claim` (`status`,`next_run_time`,`lease_expire_time`),
  KEY `idx_canvas` (`tenant_id`,`user_id`,`canvas_id`,`node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧画布视频首帧任务';

SET @poster_schema_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_short_drama_canvas_poster_job' AND COLUMN_NAME='job_kind')=0, 'ALTER TABLE `la_aigc_short_drama_canvas_poster_job` ADD COLUMN `job_kind` varchar(20) NOT NULL DEFAULT ''poster''', 'SELECT 1');
PREPARE poster_schema_stmt FROM @poster_schema_sql;
EXECUTE poster_schema_stmt;
DEALLOCATE PREPARE poster_schema_stmt;

SET @poster_schema_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_short_drama_canvas_poster_job' AND COLUMN_NAME='capture_time')=0, 'ALTER TABLE `la_aigc_short_drama_canvas_poster_job` ADD COLUMN `capture_time` decimal(12,3) NOT NULL DEFAULT 0.001', 'SELECT 1');
PREPARE poster_schema_stmt FROM @poster_schema_sql;
EXECUTE poster_schema_stmt;
DEALLOCATE PREPARE poster_schema_stmt;

SET @poster_schema_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_short_drama_canvas_poster_job' AND COLUMN_NAME='result_json')=0, 'ALTER TABLE `la_aigc_short_drama_canvas_poster_job` ADD COLUMN `result_json` mediumtext NULL', 'SELECT 1');
PREPARE poster_schema_stmt FROM @poster_schema_sql;
EXECUTE poster_schema_stmt;
DEALLOCATE PREPARE poster_schema_stmt;

-- Source: app/apps/aigc_short_drama/migrations/upgrade_20261009_canvas_pages.sql
-- Page documents keep existing generation, graph revisions and ownership contracts.
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_page` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `project_id` int unsigned NOT NULL,
  `canvas_id` int unsigned NOT NULL,
  `page_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `title` varchar(80) NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_canvas` (`canvas_id`),
  UNIQUE KEY `uk_project_page` (`tenant_id`,`user_id`,`project_id`,`page_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短剧画布项目页面';

-- Source: app/apps/aigc_short_drama/migrations/upgrade_20261009_canvas_production.sql
-- Internal production bindings are excluded from the conventional project list.
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_production` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `canvas_id` int unsigned NOT NULL,
  `thread_id` bigint unsigned NOT NULL,
  `stage_key` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `project_id` int unsigned NOT NULL,
  `task_id` varchar(64) NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_stage` (`tenant_id`,`user_id`,`canvas_id`,`thread_id`,`stage_key`),
  KEY `idx_project` (`tenant_id`,`user_id`,`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短剧画布内部制作任务';
