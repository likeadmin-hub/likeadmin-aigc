-- Additive current formal-target mapping for existing short-drama canvases.
-- It never rewrites historical free-canvas tasks, assets, or billing records.
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_binding` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `canvas_id` int unsigned NOT NULL,
  `project_id` int unsigned NOT NULL,
  `episode_id` int unsigned NOT NULL DEFAULT 0,
  `production_project_id` int unsigned NOT NULL DEFAULT 0,
  `binding_revision` int unsigned NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_canvas` (`tenant_id`,`user_id`,`canvas_id`),
  KEY `idx_project` (`tenant_id`,`user_id`,`project_id`,`episode_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短剧画布正式项目绑定';
