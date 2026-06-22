CREATE TABLE IF NOT EXISTS `la_aigc_fitting_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_mode` varchar(30) NOT NULL DEFAULT 'single',
  `default_upload_category` varchar(30) NOT NULL DEFAULT 'full',
  `prompt_template` text,
  `negative_prompt` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI试衣配置';

CREATE TABLE IF NOT EXISTS `la_aigc_fitting_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `mode` varchar(30) NOT NULL DEFAULT 'single',
  `upload_category` varchar(30) NOT NULL DEFAULT 'full',
  `model_filter` varchar(80) NOT NULL DEFAULT '',
  `clothes_filter` varchar(80) NOT NULL DEFAULT '',
  `pose_filter` varchar(80) NOT NULL DEFAULT '',
  `garment_images` text,
  `model_images` text,
  `selected_preset_ids` text,
  `prompt` text,
  `negative_prompt` text,
  `user_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
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
  KEY `idx_status` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI试衣任务';

SET @aigc_fitting_task_table_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_fitting_task');
SET @aigc_fitting_task_sql = (SELECT IF(@aigc_fitting_task_table_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_fitting_task` ADD COLUMN `image_task_ids` text AFTER `image_task_id`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_fitting_task' AND COLUMN_NAME = 'image_task_ids');
PREPARE stmt FROM @aigc_fitting_task_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
