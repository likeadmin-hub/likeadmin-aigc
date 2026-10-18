-- Home creation Agent. Additive and safe to repeat.
CREATE TABLE IF NOT EXISTS `la_aigc_llm_agent_preference` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `preferences` mediumtext NOT NULL,
  `create_time` int unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  UNIQUE KEY `owner` (`tenant_id`,`user_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `la_aigc_llm_agent_run` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `session_id` int unsigned NOT NULL,
  `message_id` int unsigned NOT NULL,
  `request_id` varchar(96) NOT NULL,
  `payload_hash` char(64) NOT NULL,
  `content` mediumtext NOT NULL,
  `preferences` text NOT NULL,
  `attachments` text NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'queued',
  `phase` varchar(16) NOT NULL DEFAULT 'chat',
  `reply` mediumtext NOT NULL,
  `error` varchar(500) NOT NULL DEFAULT '',
  `approval_hash` char(64) NOT NULL DEFAULT '',
  `cancel_requested` tinyint NOT NULL DEFAULT 0,
  `lease_token` varchar(64) NOT NULL DEFAULT '',
  `lease_until` int unsigned NOT NULL DEFAULT 0,
  `next_run_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  UNIQUE KEY `request_owner` (`tenant_id`,`user_id`,`request_id`),
  KEY `session_owner` (`tenant_id`,`user_id`,`session_id`,`id`),
  KEY `worker` (`status`,`next_run_time`,`lease_until`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `la_aigc_llm_agent_step` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `run_id` bigint unsigned NOT NULL,
  `step_key` char(64) NOT NULL,
  `title` varchar(200) NOT NULL,
  `tool_code` varchar(100) NOT NULL,
  `prepared` mediumtext NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'proposed',
  `task_id` bigint unsigned NOT NULL DEFAULT 0,
  `result` mediumtext NOT NULL,
  `error` varchar(500) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  UNIQUE KEY `run_step` (`run_id`,`step_key`),
  KEY `owner_run` (`tenant_id`,`user_id`,`run_id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `la_aigc_llm_agent_event` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `run_id` bigint unsigned NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `payload` mediumtext NOT NULL,
  `create_time` int unsigned NOT NULL,
  KEY `owner_run` (`tenant_id`,`user_id`,`run_id`,`id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `la_aigc_llm_agent_artifact` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `run_id` bigint unsigned NOT NULL,
  `session_id` int unsigned NOT NULL,
  `step_id` bigint unsigned NOT NULL DEFAULT 0,
  `identity_hash` char(64) NOT NULL,
  `title` varchar(200) NOT NULL,
  `kind` varchar(20) NOT NULL,
  `data` mediumtext NOT NULL,
  `storage_scope` varchar(30) NOT NULL DEFAULT '',
  `storage_engine` varchar(30) NOT NULL DEFAULT '',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `favorite` tinyint NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL,
  UNIQUE KEY `result_identity` (`tenant_id`,`user_id`,`identity_hash`),
  KEY `session_owner` (`tenant_id`,`user_id`,`session_id`,`id`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
