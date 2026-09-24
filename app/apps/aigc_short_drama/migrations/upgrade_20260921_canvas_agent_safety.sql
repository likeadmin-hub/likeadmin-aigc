-- P2 canvas Agent content safety: hashed/auditable decisions only, no raw text.
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_agent_safety_audit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL, `canvas_id` int unsigned NOT NULL,
  `thread_id` bigint unsigned NOT NULL, `run_id` bigint unsigned NOT NULL,
  `app_code` varchar(50) NOT NULL DEFAULT 'aigc_short_drama',
  `request_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `direction` varchar(16) NOT NULL, `policy_version` varchar(64) NOT NULL,
  `decision` varchar(24) NOT NULL, `reason_code` varchar(64) NOT NULL DEFAULT '',
  `content_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `content_length` int unsigned NOT NULL DEFAULT 0, `provider_submitted` tinyint unsigned NOT NULL DEFAULT 0,
  `expires_at` int unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_decision` (`tenant_id`,`user_id`,`canvas_id`,`thread_id`,`run_id`,`direction`,`request_key`),
  KEY `idx_expiry` (`tenant_id`,`expires_at`,`id`),
  KEY `idx_scope_run` (`tenant_id`,`user_id`,`canvas_id`,`run_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短剧画布Agent最小内容安全审计';
