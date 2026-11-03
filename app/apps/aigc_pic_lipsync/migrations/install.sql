CREATE TABLE IF NOT EXISTS `la_aigc_pic_lipsync_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片数字人配置';

CREATE TABLE IF NOT EXISTS `la_aigc_pic_lipsync_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `app_task_id` int unsigned NOT NULL DEFAULT 0,
  `consumption_id` int unsigned NOT NULL DEFAULT 0,
  `provider_task_id` varchar(160) NOT NULL DEFAULT '',
  `market_product_id` int unsigned NOT NULL DEFAULT 0,
  `market_sku_id` int unsigned NOT NULL DEFAULT 0,
  `pricing_snapshot` text,
  `source_url` varchar(1000) NOT NULL DEFAULT '',
  `title` varchar(160) NOT NULL DEFAULT '',
  `quality` varchar(20) NOT NULL DEFAULT 'standard',
  `drive_mode` varchar(20) NOT NULL DEFAULT 'audio',
  `audio_asset_id` int unsigned NOT NULL DEFAULT 0,
  `avatar_id` int unsigned NOT NULL DEFAULT 0,
  `input_seconds` decimal(12,3) NOT NULL DEFAULT 0,
  `request_key` varchar(64) DEFAULT NULL,
  `request_snapshot` text,
  `tenant_cost_points` decimal(18,6) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(18,6) NOT NULL DEFAULT 0.00,
  `actual_tenant_cost` decimal(18,6) NOT NULL DEFAULT 0.00,
  `actual_user_price` decimal(18,6) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_tenant_user` (`tenant_id`,`user_id`), UNIQUE KEY `uk_request` (`tenant_id`,`user_id`,`request_key`), KEY `idx_consumption` (`consumption_id`), KEY `idx_status` (`tenant_id`,`status`), KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片数字人任务';

CREATE TABLE IF NOT EXISTS `la_aigc_pic_lipsync_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `video_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_task_video` (`tenant_id`,`task_id`,`video_uri`), KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片数字人结果';
