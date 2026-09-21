-- Conversation-only persistence. No provider submission or graph mutation on message acceptance.
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_agent_thread` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL, `canvas_id` int unsigned NOT NULL,
  `request_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `title` varchar(160) NOT NULL DEFAULT '', `settings_json` longtext NOT NULL,
  `settings_revision` int unsigned NOT NULL DEFAULT 1,
  `next_message_sequence` int unsigned NOT NULL DEFAULT 1, `active_run_id` bigint unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL, `update_time` int unsigned NOT NULL, `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_create` (`tenant_id`,`user_id`,`canvas_id`,`request_key`),
  KEY `idx_threads` (`tenant_id`,`user_id`,`canvas_id`,`delete_time`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短剧画布Agent会话';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_agent_message` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL, `canvas_id` int unsigned NOT NULL,
  `thread_id` bigint unsigned NOT NULL, `run_id` bigint unsigned NOT NULL,
  `sequence` int unsigned NOT NULL, `role` varchar(20) NOT NULL,
  `content_json` longtext NOT NULL, `attachments_json` longtext NOT NULL,
  `create_time` int unsigned NOT NULL, `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_sequence` (`thread_id`,`sequence`),
  KEY `idx_messages` (`tenant_id`,`user_id`,`thread_id`,`delete_time`,`sequence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短剧画布Agent消息';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_agent_run` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL, `canvas_id` int unsigned NOT NULL,
  `thread_id` bigint unsigned NOT NULL,
  `request_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(32) NOT NULL, `version` int unsigned NOT NULL DEFAULT 1,
  `context_snapshot` longtext NOT NULL, `skill_snapshot` longtext NOT NULL, `settings_snapshot` longtext NOT NULL,
  `ack_json` longtext NOT NULL, `error_code` varchar(64) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL, `update_time` int unsigned NOT NULL, `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_send` (`tenant_id`,`user_id`,`canvas_id`,`request_key`),
  KEY `idx_run_state` (`tenant_id`,`status`,`update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短剧画布Agent运行';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_agent_event` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL, `canvas_id` int unsigned NOT NULL,
  `thread_id` bigint unsigned NOT NULL, `run_id` bigint unsigned NOT NULL,
  `sequence` int unsigned NOT NULL, `kind` varchar(64) NOT NULL, `payload_json` longtext NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_run_sequence` (`run_id`,`sequence`),
  KEY `idx_cursor` (`tenant_id`,`user_id`,`canvas_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短剧画布Agent事件';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_agent_outbox` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL, `canvas_id` int unsigned NOT NULL,
  `run_id` bigint unsigned NOT NULL, `event_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `state` varchar(32) NOT NULL DEFAULT 'pending', `available_at` int unsigned NOT NULL,
  `attempts` int unsigned NOT NULL DEFAULT 0, `lease_token` char(48) NOT NULL DEFAULT '',
  `lease_until` int unsigned NOT NULL DEFAULT 0, `fencing_version` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL, `update_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_event` (`tenant_id`,`user_id`,`canvas_id`,`event_key`),
  KEY `idx_delivery` (`state`,`available_at`,`lease_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短剧画布Agent持久化投递';
