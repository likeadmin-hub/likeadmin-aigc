-- VNext is opt-in. Legacy tasks and receipts are not rewritten.
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_vnext_job` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `task_id` varchar(64) COLLATE utf8mb4_bin NOT NULL,
  `lane_key` varchar(64) COLLATE utf8mb4_bin NOT NULL,
  `active_slot` tinyint unsigned DEFAULT 1,
  `pipeline_version` int unsigned NOT NULL DEFAULT 1,
  `execution_status` varchar(24) NOT NULL DEFAULT 'queued',
  `document_status` varchar(24) NOT NULL DEFAULT 'empty',
  `production_status` varchar(24) NOT NULL DEFAULT 'not_started',
  `input_hash` char(64) NOT NULL,
  `input_json` mediumtext NOT NULL,
  `budget_json` mediumtext NOT NULL,
  `candidate_json` mediumtext,
  `published_revision_id` varchar(64) NOT NULL DEFAULT '',
  `lease_owner` varchar(64) NOT NULL DEFAULT '',
  `lease_until` int unsigned NOT NULL DEFAULT 0,
  `fence` int unsigned NOT NULL DEFAULT 0,
  `deadline_at` int unsigned NOT NULL,
  `event_seq` int unsigned NOT NULL DEFAULT 0,
  `error_code` varchar(64) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scoped_task` (`tenant_id`,`user_id`,`task_id`),
  UNIQUE KEY `active_episode_lane` (`tenant_id`,`user_id`,`lane_key`,`active_slot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_vnext_stage` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `task_id` varchar(64) COLLATE utf8mb4_bin NOT NULL,
  `stage_key` varchar(100) COLLATE utf8mb4_bin NOT NULL,
  `input_hash` char(64) NOT NULL,
  `input_json` mediumtext NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'intent',
  `fence` int unsigned NOT NULL,
  `receipt_json` mediumtext,
  `error_code` varchar(64) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scoped_stage` (`tenant_id`,`user_id`,`task_id`,`stage_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_vnext_revision` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `task_id` varchar(64) COLLATE utf8mb4_bin NOT NULL,
  `revision_id` varchar(64) COLLATE utf8mb4_bin NOT NULL,
  `document_hash` char(64) NOT NULL,
  `content_json` mediumtext NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scoped_revision` (`tenant_id`,`user_id`,`revision_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_vnext_outbox` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `task_id` varchar(64) COLLATE utf8mb4_bin NOT NULL,
  `event_seq` int unsigned NOT NULL,
  `event_type` varchar(32) NOT NULL,
  `payload_json` mediumtext NOT NULL,
  `delivered_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scoped_event` (`tenant_id`,`user_id`,`task_id`,`event_seq`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
