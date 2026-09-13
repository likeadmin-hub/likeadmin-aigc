CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_planning_unit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `task_id` varchar(64) NOT NULL,
  `unit_key` varchar(100) NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `attempt` int unsigned NOT NULL DEFAULT 0,
  `request_json` mediumtext,
  `result_json` mediumtext,
  `error` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_task_unit` (`tenant_id`,`task_id`,`unit_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
