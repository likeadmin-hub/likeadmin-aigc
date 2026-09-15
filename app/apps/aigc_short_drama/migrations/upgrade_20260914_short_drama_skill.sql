CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_skill_category` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(60) NOT NULL DEFAULT '',
  `icon` varchar(120) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_status_sort` (`tenant_id`,`status`,`sort`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧Skill分类';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_skill` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `creator_admin_id` int unsigned NOT NULL DEFAULT 0,
  `skill_key` varchar(80) NOT NULL DEFAULT '',
  `name` varchar(120) NOT NULL DEFAULT '',
  `description` varchar(600) NOT NULL DEFAULT '',
  `invocation_rule` varchar(200) NOT NULL DEFAULT '',
  `category_ids_json` text,
  `cover_asset_id` int unsigned NOT NULL DEFAULT 0,
  `cover_url` varchar(500) NOT NULL DEFAULT '',
  `cover_type` varchar(12) NOT NULL DEFAULT 'image',
  `definition_json` longtext,
  `model_policy_json` text,
  `execution_policy_json` text,
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `release_status` varchar(24) NOT NULL DEFAULT 'draft',
  `version` int unsigned NOT NULL DEFAULT 1,
  `published_version` int unsigned NOT NULL DEFAULT 0,
  `published_at` int unsigned NOT NULL DEFAULT 0,
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_key_deleted` (`tenant_id`,`skill_key`,`delete_time`),
  KEY `idx_tenant_visible` (`tenant_id`,`status`,`release_status`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧Skill主表';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_skill_version` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `skill_id` int unsigned NOT NULL DEFAULT 0,
  `version` int unsigned NOT NULL DEFAULT 1,
  `release_status` varchar(24) NOT NULL DEFAULT 'draft',
  `snapshot_json` longtext,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  `published_by` int unsigned NOT NULL DEFAULT 0,
  `published_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_skill_version` (`tenant_id`,`skill_id`,`version`),
  KEY `idx_tenant_skill_release` (`tenant_id`,`skill_id`,`release_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧Skill版本';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_user_skill` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `skill_id` int unsigned NOT NULL DEFAULT 0,
  `enabled` tinyint unsigned NOT NULL DEFAULT 0,
  `last_enabled_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_user_skill` (`tenant_id`,`user_id`,`skill_id`),
  KEY `idx_tenant_user_enabled` (`tenant_id`,`user_id`,`enabled`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧用户Skill设置';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_skill_usage` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` varchar(64) NOT NULL DEFAULT '',
  `skill_id` int unsigned NOT NULL DEFAULT 0,
  `skill_version` int unsigned NOT NULL DEFAULT 0,
  `skill_source` varchar(16) NOT NULL DEFAULT 'manual',
  `skill_name` varchar(120) NOT NULL DEFAULT '',
  `status` varchar(24) NOT NULL DEFAULT 'submitted',
  `snapshot_json` longtext,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user_time` (`tenant_id`,`user_id`,`create_time`,`delete_time`),
  UNIQUE KEY `idx_task` (`tenant_id`,`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧Skill使用记录';

ALTER TABLE `la_aigc_short_drama_script_task`
  ADD COLUMN `skill_id` int unsigned NOT NULL DEFAULT 0,
  ADD COLUMN `skill_version` int unsigned NOT NULL DEFAULT 0,
  ADD COLUMN `skill_source` varchar(16) NOT NULL DEFAULT 'none',
  ADD COLUMN `skill_snapshot_json` longtext;
ALTER TABLE `la_aigc_short_drama_generation_task`
  ADD COLUMN `skill_id` int unsigned NOT NULL DEFAULT 0,
  ADD COLUMN `skill_version` int unsigned NOT NULL DEFAULT 0,
  ADD COLUMN `skill_source` varchar(16) NOT NULL DEFAULT 'none',
  ADD COLUMN `skill_snapshot_json` longtext;
ALTER TABLE `la_aigc_short_drama_agent_run`
  ADD COLUMN `skill_id` int unsigned NOT NULL DEFAULT 0,
  ADD COLUMN `skill_version` int unsigned NOT NULL DEFAULT 0,
  ADD COLUMN `skill_source` varchar(16) NOT NULL DEFAULT 'none',
  ADD COLUMN `skill_snapshot_json` longtext;
ALTER TABLE `la_aigc_short_drama_agent_step_log`
  ADD COLUMN `skill_id` int unsigned NOT NULL DEFAULT 0,
  ADD COLUMN `skill_version` int unsigned NOT NULL DEFAULT 0,
  ADD COLUMN `skill_source` varchar(16) NOT NULL DEFAULT 'none',
  ADD COLUMN `skill_snapshot_json` longtext;
