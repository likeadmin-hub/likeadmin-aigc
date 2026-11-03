-- Core user-site submission receipts. Never create another generation or billing task.
CREATE TABLE IF NOT EXISTS `la_ai_generation_submission` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `operation` varchar(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `key_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payload_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `owner_token` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'submitting',
  `task_links` mediumtext NOT NULL,
  `response_json` mediumtext NOT NULL,
  `create_time` int unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owned_request` (`tenant_id`,`user_id`,`operation`,`key_hash`),
  KEY `idx_owner_time` (`tenant_id`,`user_id`,`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户生成提交回执';
