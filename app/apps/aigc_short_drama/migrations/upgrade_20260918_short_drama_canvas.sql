-- Short-drama canvas is an internal short-drama feature with isolated storage.
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(40) NOT NULL DEFAULT '无标题空间',
  `nodes_json` longtext,
  `edges_json` longtext,
  `viewport_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`tenant_id`,`user_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧画布';

CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_run` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `canvas_id` int unsigned NOT NULL DEFAULT 0,
  `node_id` varchar(64) NOT NULL DEFAULT '',
  `node_type` varchar(16) NOT NULL DEFAULT '',
  `provider_task_id` varchar(100) NOT NULL DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'running',
  `progress` tinyint unsigned NOT NULL DEFAULT 0,
  `request_json` longtext,
  `result_json` longtext,
  `error` varchar(500) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_canvas_node` (`tenant_id`,`user_id`,`canvas_id`,`node_id`,`delete_time`),
  KEY `idx_status` (`tenant_id`,`status`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧画布生成任务';
