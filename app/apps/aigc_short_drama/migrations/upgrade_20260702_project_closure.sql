-- AI short drama project closure data model.

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_project' AND COLUMN_NAME = 'target_duration_seconds') = 0,
  'ALTER TABLE `la_aigc_short_drama_project` ADD COLUMN `target_duration_seconds` int unsigned NOT NULL DEFAULT 0 AFTER `episode_count`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_project' AND COLUMN_NAME = 'input_asset_ids') = 0,
  'ALTER TABLE `la_aigc_short_drama_project` ADD COLUMN `input_asset_ids` text AFTER `target_duration_seconds`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_project' AND COLUMN_NAME = 'current_version_id') = 0,
  'ALTER TABLE `la_aigc_short_drama_project` ADD COLUMN `current_version_id` int unsigned NOT NULL DEFAULT 0 AFTER `last_task_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_project' AND COLUMN_NAME = 'current_agent_run_id') = 0,
  'ALTER TABLE `la_aigc_short_drama_project` ADD COLUMN `current_agent_run_id` varchar(64) NOT NULL DEFAULT '''' AFTER `current_version_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_project' AND COLUMN_NAME = 'timeline_json') = 0,
  'ALTER TABLE `la_aigc_short_drama_project` ADD COLUMN `timeline_json` mediumtext AFTER `current_agent_run_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_project' AND COLUMN_NAME = 'final_video_asset_id') = 0,
  'ALTER TABLE `la_aigc_short_drama_project` ADD COLUMN `final_video_asset_id` int unsigned NOT NULL DEFAULT 0 AFTER `timeline_json`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_project' AND COLUMN_NAME = 'publish_id') = 0,
  'ALTER TABLE `la_aigc_short_drama_project` ADD COLUMN `publish_id` int unsigned NOT NULL DEFAULT 0 AFTER `final_video_asset_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_agent_run` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `agent_run_id` varchar(64) NOT NULL DEFAULT '',
  `task_id` varchar(64) NOT NULL DEFAULT '',
  `run_type` varchar(40) NOT NULL DEFAULT 'initial_plan',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `input_summary` varchar(500) NOT NULL DEFAULT '',
  `request_json` mediumtext,
  `output_summary` varchar(500) NOT NULL DEFAULT '',
  `output_version_id` int unsigned NOT NULL DEFAULT 0,
  `model_json` text,
  `error_code` varchar(80) NOT NULL DEFAULT '',
  `error_msg` varchar(500) NOT NULL DEFAULT '',
  `started_at` int unsigned NOT NULL DEFAULT 0,
  `finished_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_agent_run` (`tenant_id`,`agent_run_id`),
  KEY `idx_project` (`tenant_id`,`project_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧Agent运行记录';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_agent_step_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `agent_run_id` varchar(64) NOT NULL DEFAULT '',
  `step_key` varchar(80) NOT NULL DEFAULT '',
  `step_name` varchar(120) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `input_json` mediumtext,
  `output_json` mediumtext,
  `error_msg` varchar(500) NOT NULL DEFAULT '',
  `started_at` int unsigned NOT NULL DEFAULT 0,
  `finished_at` int unsigned NOT NULL DEFAULT 0,
  `sort` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_run_sort` (`tenant_id`,`agent_run_id`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧Agent步骤日志';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_plan_version` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` varchar(64) NOT NULL DEFAULT '',
  `agent_run_id` varchar(64) NOT NULL DEFAULT '',
  `parent_version_id` int unsigned NOT NULL DEFAULT 0,
  `version_no` int unsigned NOT NULL DEFAULT 1,
  `version_type` varchar(40) NOT NULL DEFAULT 'agent_initial',
  `title` varchar(120) NOT NULL DEFAULT '',
  `story_bible_json` mediumtext,
  `continuity_json` mediumtext,
  `plan_json` mediumtext,
  `storyboard_json` mediumtext,
  `is_current` tinyint NOT NULL DEFAULT 0,
  `status` varchar(30) NOT NULL DEFAULT 'ready',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_project_version` (`tenant_id`,`project_id`,`version_no`,`delete_time`),
  KEY `idx_current` (`tenant_id`,`project_id`,`is_current`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧策划版本';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_asset` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` varchar(64) NOT NULL DEFAULT '',
  `shot_id` varchar(40) NOT NULL DEFAULT '',
  `asset_type` varchar(40) NOT NULL DEFAULT 'reference_image',
  `title` varchar(120) NOT NULL DEFAULT '',
  `uri` varchar(500) NOT NULL DEFAULT '',
  `cover_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(30) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `mime_type` varchar(120) NOT NULL DEFAULT '',
  `file_size` bigint unsigned NOT NULL DEFAULT 0,
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `duration` decimal(8,2) NOT NULL DEFAULT 0.00,
  `checksum` varchar(100) NOT NULL DEFAULT '',
  `meta_json` text,
  `status` varchar(30) NOT NULL DEFAULT 'ready',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_project_type` (`tenant_id`,`project_id`,`asset_type`,`delete_time`),
  KEY `idx_task` (`tenant_id`,`task_id`,`shot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧项目资产';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_generation_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `shot_id` varchar(40) NOT NULL DEFAULT '',
  `task_id` varchar(64) NOT NULL DEFAULT '',
  `parent_task_id` varchar(64) NOT NULL DEFAULT '',
  `source_task_id` varchar(64) NOT NULL DEFAULT '',
  `source_app_code` varchar(40) NOT NULL DEFAULT '',
  `task_type` varchar(40) NOT NULL DEFAULT 'shot_image',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `progress` tinyint unsigned NOT NULL DEFAULT 0,
  `provider` varchar(50) NOT NULL DEFAULT 'pending',
  `provider_task_id` varchar(100) NOT NULL DEFAULT '',
  `provider_request_id` varchar(100) NOT NULL DEFAULT '',
  `model_json` text,
  `request_json` mediumtext,
  `result_json` mediumtext,
  `input_asset_ids` text,
  `output_asset_ids` text,
  `pricing_snapshot` text,
  `billing_status` varchar(30) NOT NULL DEFAULT 'none',
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `idempotency_key` varchar(100) NOT NULL DEFAULT '',
  `retry_count` int unsigned NOT NULL DEFAULT 0,
  `error_code` varchar(80) NOT NULL DEFAULT '',
  `error_msg` varchar(500) NOT NULL DEFAULT '',
  `operator_error` text,
  `safety_status` varchar(30) NOT NULL DEFAULT 'pending',
  `started_at` int unsigned NOT NULL DEFAULT 0,
  `finished_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_id` (`tenant_id`,`task_id`),
  KEY `idx_project_type` (`tenant_id`,`project_id`,`task_type`,`status`),
  KEY `idx_shot` (`tenant_id`,`project_id`,`shot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧生成任务';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_published_work` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `final_video_asset_id` int unsigned NOT NULL DEFAULT 0,
  `cover_asset_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(120) NOT NULL DEFAULT '',
  `intro` varchar(500) NOT NULL DEFAULT '',
  `script_description` text,
  `social_link` varchar(500) NOT NULL DEFAULT '',
  `cover_uri` varchar(500) NOT NULL DEFAULT '',
  `video_uri` varchar(500) NOT NULL DEFAULT '',
  `activity_tags_json` text,
  `audit_status` varchar(30) NOT NULL DEFAULT 'reviewing',
  `audit_reason` varchar(500) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 0,
  `submitted_at` int unsigned NOT NULL DEFAULT 0,
  `audited_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_project` (`tenant_id`,`project_id`,`delete_time`),
  KEY `idx_audit` (`tenant_id`,`audit_status`,`status`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧发布作品';
