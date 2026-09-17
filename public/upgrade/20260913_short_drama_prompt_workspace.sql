-- AI short drama prompt workspace: append-only tenant revisions, source-only migration.
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_prompt_revision` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `revision` int unsigned NOT NULL DEFAULT 0,
  `admin_id` int unsigned NOT NULL DEFAULT 0,
  `action` varchar(32) NOT NULL DEFAULT 'save',
  `snapshot_json` longtext NOT NULL,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_revision` (`tenant_id`, `revision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧提示词版本';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_prompt_request` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` varchar(100) NOT NULL DEFAULT '',
  `stage` varchar(40) NOT NULL DEFAULT '',
  `revision` int unsigned NOT NULL DEFAULT 0,
  `audit_json` longtext NOT NULL,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `tenant_created` (`tenant_id`, `id`),
  KEY `tenant_task` (`tenant_id`, `task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧提示词实发记录';
