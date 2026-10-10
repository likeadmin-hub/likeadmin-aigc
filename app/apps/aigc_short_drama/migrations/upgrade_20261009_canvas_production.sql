-- Internal production bindings are excluded from the conventional project list.
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_production` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `canvas_id` int unsigned NOT NULL,
  `thread_id` bigint unsigned NOT NULL,
  `stage_key` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `project_id` int unsigned NOT NULL,
  `task_id` varchar(64) NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_stage` (`tenant_id`,`user_id`,`canvas_id`,`thread_id`,`stage_key`),
  KEY `idx_project` (`tenant_id`,`user_id`,`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短剧画布内部制作任务';
