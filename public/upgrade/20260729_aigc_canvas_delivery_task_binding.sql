CREATE TABLE IF NOT EXISTS `la_aigc_canvas_delivery_task_binding` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `delivery_item_id` int unsigned NOT NULL DEFAULT 0,
  `generation_task_id` varchar(160) NOT NULL DEFAULT '',
  `canvas_run_id` varchar(160) NOT NULL DEFAULT '',
  `provider_task_id` varchar(160) NOT NULL DEFAULT '',
  `idempotency_key` varchar(160) NOT NULL DEFAULT '',
  `status` varchar(40) NOT NULL DEFAULT 'queued',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_delivery_task_attempt` (`delivery_item_id`,`idempotency_key`,`delete_time`),
  KEY `idx_delivery_task_generation` (`tenant_id`,`user_id`,`generation_task_id`,`delete_time`),
  KEY `idx_delivery_task_provider` (`tenant_id`,`provider_task_id`,`delete_time`),
  KEY `idx_delivery_task_status` (`tenant_id`,`status`,`update_time`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas delivery task bindings';
