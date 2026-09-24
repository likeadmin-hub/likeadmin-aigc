-- Additive, source-only quote confirmation boundary for existing short-drama canvases.
-- It creates no task, reservation, Provider request, or point movement.
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_quote` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL, `canvas_id` int unsigned NOT NULL,
  `node_id` varchar(64) NOT NULL, `request_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `quote_token` char(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `input_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_json` longtext NOT NULL, `quote_json` longtext NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'quoted', `expires_at` int unsigned NOT NULL,
  `confirmed_at` int unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL, `update_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_token` (`quote_token`),
  KEY `idx_scope` (`tenant_id`,`user_id`,`canvas_id`,`node_id`,`request_key`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短剧画布视频报价确认';
