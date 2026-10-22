CREATE TABLE IF NOT EXISTS `la_aigc_canvas_agent_turn` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `thread_id` int unsigned NOT NULL DEFAULT 0,
  `request_id` varchar(96) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'running',
  `execution_mode` varchar(40) NOT NULL DEFAULT 'agent_loop',
  `iteration_count` int unsigned NOT NULL DEFAULT 0,
  `started_at_ms` bigint unsigned NOT NULL DEFAULT 0,
  `first_status_at_ms` bigint unsigned NOT NULL DEFAULT 0,
  `first_token_at` int unsigned NOT NULL DEFAULT 0,
  `first_token_at_ms` bigint unsigned NOT NULL DEFAULT 0,
  `completed_at` int unsigned NOT NULL DEFAULT 0,
  `completed_at_ms` bigint unsigned NOT NULL DEFAULT 0,
  `fallback_reason` varchar(500) NOT NULL DEFAULT '',
  `input_json` longtext,
  `output_json` longtext,
  `error` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_request` (`tenant_id`,`user_id`,`request_id`,`delete_time`),
  KEY `idx_thread_time` (`tenant_id`,`thread_id`,`create_time`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas Agent turns';

CREATE TABLE IF NOT EXISTS `la_aigc_canvas_agent_turn_event` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `turn_id` bigint unsigned NOT NULL DEFAULT 0,
  `sequence` int unsigned NOT NULL DEFAULT 0,
  `event_type` varchar(80) NOT NULL DEFAULT '',
  `payload_json` longtext,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `create_time_ms` bigint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_turn_sequence` (`turn_id`,`sequence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas Agent turn events';

CREATE TABLE IF NOT EXISTS `la_aigc_canvas_asset` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` varchar(120) NOT NULL DEFAULT '',
  `shot_id` varchar(120) NOT NULL DEFAULT '',
  `asset_type` varchar(40) NOT NULL DEFAULT 'reference_image',
  `title` varchar(120) NOT NULL DEFAULT '',
  `uri` text,
  `cover_uri` text,
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(32) NOT NULL DEFAULT '',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `mime_type` varchar(120) NOT NULL DEFAULT '',
  `file_size` bigint unsigned NOT NULL DEFAULT 0,
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `duration` decimal(10,2) NOT NULL DEFAULT '0.00',
  `checksum` varchar(100) NOT NULL DEFAULT '',
  `meta_json` longtext,
  `status` varchar(30) NOT NULL DEFAULT 'ready',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_project_type` (`tenant_id`,`project_id`,`asset_type`,`delete_time`),
  KEY `idx_user_type` (`tenant_id`,`user_id`,`asset_type`,`delete_time`),
  KEY `idx_task` (`tenant_id`,`task_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas assets';

CREATE TABLE IF NOT EXISTS `la_aigc_canvas_skill_version` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `skill_id` int unsigned NOT NULL DEFAULT 0,
  `version` int unsigned NOT NULL DEFAULT 1,
  `release_status` varchar(24) NOT NULL DEFAULT 'draft',
  `config_json` longtext,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  `published_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_skill_version` (`tenant_id`,`skill_id`,`version`),
  KEY `idx_tenant_skill_release` (`tenant_id`,`skill_id`,`release_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas product Skill versions';

CREATE TABLE IF NOT EXISTS `la_aigc_canvas_skill_evaluation_case` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `skill_key` varchar(120) NOT NULL DEFAULT '',
  `name` varchar(160) NOT NULL DEFAULT '',
  `input_json` longtext,
  `canvas_fixture_json` longtext,
  `expected_route_json` longtext,
  `expected_next_action` varchar(40) NOT NULL DEFAULT '',
  `tags_json` text,
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_skill_status` (`tenant_id`,`skill_key`,`status`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas Skill evaluation cases';

SET @db_name = DATABASE();

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_skill' AND COLUMN_NAME='visibility_policy_json')=0,
  'ALTER TABLE `la_aigc_canvas_skill` ADD COLUMN `visibility_policy_json` longtext NULL COMMENT ''User visibility policy JSON''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_skill' AND COLUMN_NAME='model_policy_json')=0,
  'ALTER TABLE `la_aigc_canvas_skill` ADD COLUMN `model_policy_json` longtext NULL COMMENT ''Model profile policy JSON''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_skill' AND COLUMN_NAME='execution_policy_json')=0,
  'ALTER TABLE `la_aigc_canvas_skill` ADD COLUMN `execution_policy_json` longtext NULL COMMENT ''Agent execution policy JSON''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_skill' AND COLUMN_NAME='quality_policy_json')=0,
  'ALTER TABLE `la_aigc_canvas_skill` ADD COLUMN `quality_policy_json` longtext NULL COMMENT ''Delivery quality policy JSON''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_skill' AND COLUMN_NAME='safety_policy_json')=0,
  'ALTER TABLE `la_aigc_canvas_skill` ADD COLUMN `safety_policy_json` longtext NULL COMMENT ''Safety policy JSON''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_skill' AND COLUMN_NAME='analytics_policy_json')=0,
  'ALTER TABLE `la_aigc_canvas_skill` ADD COLUMN `analytics_policy_json` longtext NULL COMMENT ''Skill analytics policy JSON''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
