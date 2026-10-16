-- Retain the first pre-adaptation snapshot; never overwrite it on retries.
CREATE TABLE IF NOT EXISTS `la_aigc_canvas_project_compat_backup` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `project_id` int unsigned NOT NULL,
  `snapshot_json` longtext NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_owner` (`tenant_id`,`user_id`,`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Original canvas project snapshots before compatibility adaptation';
