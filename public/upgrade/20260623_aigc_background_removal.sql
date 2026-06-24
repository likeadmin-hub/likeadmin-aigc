CREATE TABLE IF NOT EXISTS `la_aigc_background_removal_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_channel` varchar(80) NOT NULL DEFAULT '',
  `default_quality` varchar(80) NOT NULL DEFAULT '',
  `default_ratio` varchar(80) NOT NULL DEFAULT '',
  `prompt_template` text,
  `negative_prompt` text,
  `price_config` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片去背景配置';

CREATE TABLE IF NOT EXISTS `la_aigc_background_removal_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `price_package_code` varchar(80) NOT NULL DEFAULT '',
  `price_package_name` varchar(100) NOT NULL DEFAULT '',
  `price_package_snapshot` text,
  `size_key` varchar(80) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `negative_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
  `quality_label` varchar(80) NOT NULL DEFAULT '',
  `ratio` varchar(80) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'running',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_image_task` (`image_task_id`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片去背景任务';

CREATE TABLE IF NOT EXISTS `la_aigc_background_removal_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_uri` varchar(500) NOT NULL DEFAULT '',
  `mime_type` varchar(80) NOT NULL DEFAULT 'image/png',
  `has_alpha` tinyint NOT NULL DEFAULT 1,
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_image_result` (`tenant_id`,`image_result_id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片去背景结果';

DELETE FROM `la_membership_plan_app`
WHERE `app_code`='aigc_background_removal';

INSERT INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)
SELECT `id`,'aigc_background_removal','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant`
WHERE EXISTS (
  SELECT 1 FROM `la_app`
  WHERE `code`='aigc_background_removal' AND `status`='installed'
)
UNION ALL
SELECT 0,'aigc_background_removal','1.0.0','paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE EXISTS (
  SELECT 1 FROM `la_app`
  WHERE `code`='aigc_background_removal' AND `status`='installed'
)
ON DUPLICATE KEY UPDATE
  `version`=VALUES(`version`),
  `buy_status`=VALUES(`buy_status`),
  `shelf_status`=VALUES(`shelf_status`),
  `enable_status`=VALUES(`enable_status`),
  `expire_time`=VALUES(`expire_time`),
  `update_time`=VALUES(`update_time`);
