CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_episode_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `project_id` int unsigned NOT NULL,
  `episode_number` int unsigned NOT NULL,
  `production_project_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` varchar(80) NOT NULL DEFAULT '',
  `outline_task_id` varchar(80) NOT NULL DEFAULT '',
  `title` varchar(120) NOT NULL DEFAULT '',
  `outline_json` longtext,
  `series_json` longtext,
  `result_json` longtext,
  `continuity_json` longtext,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `progress` int unsigned NOT NULL DEFAULT 0,
  `error` text,
  `provider` varchar(80) NOT NULL DEFAULT '',
  `provider_request_id` varchar(255) NOT NULL DEFAULT '',
  `provider_task_id` varchar(255) NOT NULL DEFAULT '',
  `retry_count` int unsigned NOT NULL DEFAULT 0,
  `completed_once` tinyint unsigned NOT NULL DEFAULT 0,
  `cancel_requested` tinyint unsigned NOT NULL DEFAULT 0,
  `started_at` int unsigned NOT NULL DEFAULT 0,
  `finished_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_project_episode` (`tenant_id`,`project_id`,`episode_number`,`delete_time`),
  KEY `idx_production` (`production_project_id`,`delete_time`),
  KEY `idx_queue` (`status`,`project_id`,`episode_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_asset' AND COLUMN_NAME = 'episode_id') = 0, 'ALTER TABLE `la_aigc_short_drama_asset` ADD COLUMN `episode_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_episode_task' AND COLUMN_NAME = 'cancel_requested') = 0, 'ALTER TABLE `la_aigc_short_drama_episode_task` ADD COLUMN `cancel_requested` tinyint unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_episode_task' AND COLUMN_NAME = 'series_json') = 0, 'ALTER TABLE `la_aigc_short_drama_episode_task` ADD COLUMN `series_json` longtext', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_asset' AND COLUMN_NAME = 'episode_number') = 0, 'ALTER TABLE `la_aigc_short_drama_asset` ADD COLUMN `episode_number` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'episode_id') = 0, 'ALTER TABLE `la_aigc_short_drama_storyboard` ADD COLUMN `episode_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'episode_number') = 0, 'ALTER TABLE `la_aigc_short_drama_storyboard` ADD COLUMN `episode_number` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_generation_task' AND COLUMN_NAME = 'episode_id') = 0, 'ALTER TABLE `la_aigc_short_drama_generation_task` ADD COLUMN `episode_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_generation_task' AND COLUMN_NAME = 'episode_number') = 0, 'ALTER TABLE `la_aigc_short_drama_generation_task` ADD COLUMN `episode_number` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_script_task' AND COLUMN_NAME = 'episode_id') = 0, 'ALTER TABLE `la_aigc_short_drama_script_task` ADD COLUMN `episode_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_script_task' AND COLUMN_NAME = 'episode_number') = 0, 'ALTER TABLE `la_aigc_short_drama_script_task` ADD COLUMN `episode_number` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_plan_version' AND COLUMN_NAME = 'episode_id') = 0, 'ALTER TABLE `la_aigc_short_drama_plan_version` ADD COLUMN `episode_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_plan_version' AND COLUMN_NAME = 'episode_number') = 0, 'ALTER TABLE `la_aigc_short_drama_plan_version` ADD COLUMN `episode_number` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;
