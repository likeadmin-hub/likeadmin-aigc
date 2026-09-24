-- Local acceptance fixture only. Never include this table in an app release migration.
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_test_provider_receipt` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `canvas_id` int unsigned NOT NULL,
  `intent_id` bigint unsigned NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fixture_canvas` (`tenant_id`,`user_id`,`canvas_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短剧画布本地验收 Provider 回执夹具';
