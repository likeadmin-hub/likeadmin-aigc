CREATE TABLE IF NOT EXISTS `la_tenant_app_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `title` varchar(80) NOT NULL DEFAULT '' COMMENT '展示标题',
  `description` varchar(500) NOT NULL DEFAULT '' COMMENT '展示描述',
  `cover_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '封面资源',
  `icon_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '图标资源',
  `virtual_use_count` varchar(50) NOT NULL DEFAULT '' COMMENT '虚拟使用数',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态',
  `extra` json DEFAULT NULL COMMENT '扩展配置',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_app` (`tenant_id`,`app_code`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户应用展示配置';

SET @aigc_image_task_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_image_task'
);

SET @aigc_image_sql = (
  SELECT IF(@aigc_image_task_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_image_task` ADD COLUMN `channel` varchar(64) NOT NULL DEFAULT '''' COMMENT ''通道'' AFTER `style`', 'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_image_task'
    AND COLUMN_NAME = 'channel'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;

SET @aigc_image_sql = (
  SELECT IF(@aigc_image_task_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_image_task` ADD COLUMN `quality` varchar(30) NOT NULL DEFAULT '''' COMMENT ''分辨率档位'' AFTER `channel`', 'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_image_task'
    AND COLUMN_NAME = 'quality'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;

SET @aigc_image_sql = (
  SELECT IF(@aigc_image_task_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_image_task` ADD COLUMN `reference_images` text COMMENT ''参考图'' AFTER `negative_prompt`', 'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_image_task'
    AND COLUMN_NAME = 'reference_images'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;

SET @aigc_image_sql = (
  SELECT IF(@aigc_image_task_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_image_task` ADD COLUMN `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT ''租户成本扣点'' AFTER `quantity`', 'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_image_task'
    AND COLUMN_NAME = 'tenant_cost_points'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;

SET @aigc_image_sql = (
  SELECT IF(@aigc_image_task_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_image_task` ADD COLUMN `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT ''用户消费扣点'' AFTER `tenant_cost_points`', 'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_image_task'
    AND COLUMN_NAME = 'user_charge_points'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;

SET @aigc_image_sql = (
  SELECT IF(@aigc_image_task_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_image_task` ADD COLUMN `provider` varchar(50) NOT NULL DEFAULT '''' COMMENT ''供应商'' AFTER `user_charge_points`', 'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_image_task'
    AND COLUMN_NAME = 'provider'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;

SET @aigc_image_sql = (
  SELECT IF(@aigc_image_task_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_image_task` ADD COLUMN `model` varchar(100) NOT NULL DEFAULT '''' COMMENT ''模型'' AFTER `provider`', 'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_image_task'
    AND COLUMN_NAME = 'model'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;

SET @aigc_image_sql = (
  SELECT IF(@aigc_image_task_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_image_task` ADD COLUMN `provider_task_id` varchar(120) NOT NULL DEFAULT '''' COMMENT ''供应商任务ID'' AFTER `model`', 'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_image_task'
    AND COLUMN_NAME = 'provider_task_id'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;

SET @aigc_image_result_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_image_result'
);

SET @aigc_image_result_sql = (
  SELECT IF(@aigc_image_result_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_image_result` ADD COLUMN `channel` varchar(64) NOT NULL DEFAULT '''' COMMENT ''通道'' AFTER `user_id`', 'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_image_result'
    AND COLUMN_NAME = 'channel'
);
PREPARE aigc_image_result_stmt FROM @aigc_image_result_sql;
EXECUTE aigc_image_result_stmt;
DEALLOCATE PREPARE aigc_image_result_stmt;

SET @aigc_image_result_sql = (
  SELECT IF(@aigc_image_result_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_image_result` ADD COLUMN `quality` varchar(30) NOT NULL DEFAULT '''' COMMENT ''分辨率档位'' AFTER `channel`', 'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_image_result'
    AND COLUMN_NAME = 'quality'
);
PREPARE aigc_image_result_stmt FROM @aigc_image_result_sql;
EXECUTE aigc_image_result_stmt;
DEALLOCATE PREPARE aigc_image_result_stmt;

SET @aigc_image_result_sql = (
  SELECT IF(@aigc_image_result_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_image_result` ADD COLUMN `ratio` varchar(30) NOT NULL DEFAULT '''' COMMENT ''图片比例'' AFTER `quality`', 'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_image_result'
    AND COLUMN_NAME = 'ratio'
);
PREPARE aigc_image_result_stmt FROM @aigc_image_result_sql;
EXECUTE aigc_image_result_stmt;
DEALLOCATE PREPARE aigc_image_result_stmt;

SET @aigc_image_result_sql = (
  SELECT IF(@aigc_image_result_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_image_result` ADD COLUMN `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT ''租户成本扣点'' AFTER `height`', 'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_image_result'
    AND COLUMN_NAME = 'tenant_cost_points'
);
PREPARE aigc_image_result_stmt FROM @aigc_image_result_sql;
EXECUTE aigc_image_result_stmt;
DEALLOCATE PREPARE aigc_image_result_stmt;

SET @aigc_image_result_sql = (
  SELECT IF(@aigc_image_result_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_image_result` ADD COLUMN `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT ''用户消费扣点'' AFTER `tenant_cost_points`', 'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_image_result'
    AND COLUMN_NAME = 'user_charge_points'
);
PREPARE aigc_image_result_stmt FROM @aigc_image_result_sql;
EXECUTE aigc_image_result_stmt;
DEALLOCATE PREPARE aigc_image_result_stmt;

SET @aigc_image_result_sql = (
  SELECT IF(@aigc_image_result_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_image_result` ADD COLUMN `provider_task_id` varchar(120) NOT NULL DEFAULT '''' COMMENT ''供应商任务ID'' AFTER `user_charge_points`', 'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_image_result'
    AND COLUMN_NAME = 'provider_task_id'
);
PREPARE aigc_image_result_stmt FROM @aigc_image_result_sql;
EXECUTE aigc_image_result_stmt;
DEALLOCATE PREPARE aigc_image_result_stmt;

SET @aigc_video_task_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_video_task'
);

SET @aigc_video_sql = (
  SELECT IF(@aigc_video_task_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_video_task` ADD COLUMN `reference_assets` text COMMENT ''参考素材'' AFTER `reference_images`', 'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_video_task'
    AND COLUMN_NAME = 'reference_assets'
);
PREPARE aigc_video_stmt FROM @aigc_video_sql;
EXECUTE aigc_video_stmt;
DEALLOCATE PREPARE aigc_video_stmt;

SET @aigc_video_sql = (
  SELECT IF(@aigc_video_task_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_video_task` ADD COLUMN `duration` int unsigned NOT NULL DEFAULT 0 COMMENT ''生成时长秒数'' AFTER `ratio`', 'SELECT 1')
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_video_task'
    AND COLUMN_NAME = 'duration'
);
PREPARE aigc_video_stmt FROM @aigc_video_sql;
EXECUTE aigc_video_stmt;
DEALLOCATE PREPARE aigc_video_stmt;
