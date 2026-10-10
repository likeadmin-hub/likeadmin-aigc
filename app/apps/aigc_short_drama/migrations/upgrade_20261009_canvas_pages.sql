-- Page documents keep existing generation, graph revisions and ownership contracts.
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_page` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `project_id` int unsigned NOT NULL,
  `canvas_id` int unsigned NOT NULL,
  `page_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `title` varchar(80) NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_canvas` (`canvas_id`),
  UNIQUE KEY `uk_project_page` (`tenant_id`,`user_id`,`project_id`,`page_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短剧画布项目页面';
