-- Source-only additive schema. Does not enable Agent or submit workers.
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_generation_intent` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL, `canvas_id` int unsigned NOT NULL,
  `request_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `canvas_run_id` int unsigned NOT NULL, `node_id` varchar(64) NOT NULL,
  `snapshot_json` longtext NOT NULL, `state` varchar(32) NOT NULL,
  `fencing_version` int unsigned NOT NULL DEFAULT 0, `claim_token` char(48) NOT NULL DEFAULT '',
  `lease_until` int unsigned NOT NULL DEFAULT 0, `provider_task_id` varchar(100) NOT NULL DEFAULT '',
  `error_code` varchar(64) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL, `update_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_scope_key` (`tenant_id`,`user_id`,`canvas_id`,`request_key`),
  UNIQUE KEY `uk_canvas_run` (`canvas_run_id`), KEY `idx_recovery` (`state`,`lease_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短剧画布生成提交意图';

