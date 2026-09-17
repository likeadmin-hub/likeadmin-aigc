-- Short-drama-owned canvas. Additive schema only; tenants with short drama
-- receive this capability by default. No existing rows are changed by schema.
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_workspace` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `request_key` varchar(64) NOT NULL,
  `request_hash` char(64) NOT NULL,
  `title` varchar(120) NOT NULL DEFAULT '',
  `version` int unsigned NOT NULL DEFAULT 1,
  `skill_snapshot_json` mediumtext NOT NULL,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_request` (`tenant_id`,`user_id`,`request_key`),
  UNIQUE KEY `uk_project` (`tenant_id`,`user_id`,`project_id`),
  KEY `idx_owner` (`tenant_id`,`user_id`,`delete_time`,`update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_view` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `workspace_id` bigint unsigned NOT NULL,
  `view_key` varchar(64) NOT NULL,
  `episode_id` bigint unsigned NOT NULL DEFAULT 0,
  `production_project_id` bigint unsigned NOT NULL DEFAULT 0,
  `version` int unsigned NOT NULL DEFAULT 1,
  `layout_json` mediumtext NOT NULL,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_view` (`tenant_id`,`user_id`,`workspace_id`,`view_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_message` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `workspace_id` bigint unsigned NOT NULL,
  `view_key` varchar(64) NOT NULL DEFAULT 'global',
  `run_id` bigint unsigned NOT NULL DEFAULT 0,
  `request_key` varchar(64) NOT NULL,
  `request_hash` char(64) NOT NULL,
  `role` varchar(16) NOT NULL DEFAULT 'user',
  `content` mediumtext NOT NULL,
  `attachments_json` text NOT NULL,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_message` (`tenant_id`,`user_id`,`workspace_id`,`request_key`),
  KEY `idx_history` (`tenant_id`,`user_id`,`workspace_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_draft` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `workspace_id` bigint unsigned NOT NULL,
  `view_key` varchar(64) NOT NULL DEFAULT 'global',
  `draft_key` varchar(64) NOT NULL,
  `kind` varchar(24) NOT NULL,
  `version` int unsigned NOT NULL DEFAULT 1,
  `content_json` mediumtext NOT NULL,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_draft` (`tenant_id`,`user_id`,`workspace_id`,`draft_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_run` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `workspace_id` bigint unsigned NOT NULL,
  `request_key` varchar(64) NOT NULL,
  `request_hash` char(64) NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `active_slot` tinyint unsigned DEFAULT NULL,
  `skill_snapshot_json` mediumtext NOT NULL,
  `context_json` mediumtext NOT NULL,
  `result_json` mediumtext NOT NULL,
  `lease_token` varchar(64) NOT NULL DEFAULT '',
  `lease_expires_at` int unsigned NOT NULL DEFAULT 0,
  `tool_count` tinyint unsigned NOT NULL DEFAULT 0,
  `app_task_id` bigint unsigned NOT NULL DEFAULT 0,
  `error_code` varchar(64) NOT NULL DEFAULT '',
  `error_message` varchar(255) NOT NULL DEFAULT '',
  `started_at` int unsigned NOT NULL DEFAULT 0,
  `finished_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_run_request` (`tenant_id`,`user_id`,`workspace_id`,`request_key`),
  UNIQUE KEY `uk_active` (`tenant_id`,`user_id`,`workspace_id`,`active_slot`),
  KEY `idx_recovery` (`status`,`lease_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_action` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `workspace_id` bigint unsigned NOT NULL,
  `run_id` bigint unsigned NOT NULL DEFAULT 0,
  `request_key` varchar(64) NOT NULL,
  `request_hash` char(64) NOT NULL,
  `kind` varchar(32) NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'proposed',
  `version` int unsigned NOT NULL DEFAULT 1,
  `proposal_json` mediumtext NOT NULL,
  `result_json` mediumtext NOT NULL,
  `confirmed_hash` char(64) NOT NULL DEFAULT '',
  `generation_task_id` varchar(64) DEFAULT NULL,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_action` (`tenant_id`,`user_id`,`workspace_id`,`request_key`),
  UNIQUE KEY `uk_task` (`generation_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_event` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `workspace_id` bigint unsigned NOT NULL,
  `run_id` bigint unsigned NOT NULL DEFAULT 0,
  `event_key` varchar(64) NOT NULL,
  `kind` varchar(32) NOT NULL,
  `payload_json` text NOT NULL,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_event` (`tenant_id`,`user_id`,`workspace_id`,`event_key`),
  KEY `idx_poll` (`tenant_id`,`user_id`,`workspace_id`,`id`),
  KEY `idx_retention` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
