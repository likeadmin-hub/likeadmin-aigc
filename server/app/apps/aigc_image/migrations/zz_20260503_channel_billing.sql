CREATE TABLE IF NOT EXISTS `la_aigc_image_channel` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID，0为平台配置',
  `code` varchar(64) NOT NULL DEFAULT '' COMMENT '通道编码',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '通道名称',
  `provider` varchar(50) NOT NULL DEFAULT 'mock' COMMENT '供应商',
  `model` varchar(100) NOT NULL DEFAULT 'mock-image' COMMENT '模型',
  `max_reference_images` int unsigned NOT NULL DEFAULT 4 COMMENT '最大参考图数量',
  `config_json` text COMMENT 'Provider参数预留',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC生图通道';

CREATE TABLE IF NOT EXISTS `la_aigc_image_channel_spec` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID，0为平台配置',
  `channel_code` varchar(64) NOT NULL DEFAULT '' COMMENT '通道编码',
  `quality` varchar(30) NOT NULL DEFAULT '' COMMENT '分辨率档位',
  `quality_label` varchar(50) NOT NULL DEFAULT '' COMMENT '分辨率名称',
  `ratio` varchar(30) NOT NULL DEFAULT '' COMMENT '图片比例',
  `width` int unsigned NOT NULL DEFAULT 0 COMMENT '宽度',
  `height` int unsigned NOT NULL DEFAULT 0 COMMENT '高度',
  `platform_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '平台成本单价',
  `tenant_unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户用户售价',
  `provider_params_json` text COMMENT 'Provider规格参数预留',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_spec` (`tenant_id`,`channel_code`,`quality`,`ratio`),
  KEY `idx_channel` (`tenant_id`,`channel_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC生图通道规格';

CREATE TABLE IF NOT EXISTS `la_aigc_image_billing` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `result_id` int unsigned NOT NULL DEFAULT 0,
  `channel` varchar(64) NOT NULL DEFAULT '',
  `quality` varchar(30) NOT NULL DEFAULT '',
  `ratio` varchar(30) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `platform_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '平台成本单价',
  `tenant_unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户用户售价',
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户成本扣点',
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '用户消费扣点',
  `billing_status` varchar(30) NOT NULL DEFAULT 'deducted',
  `tenant_point_sn` varchar(64) NOT NULL DEFAULT '',
  `user_point_sn` varchar(64) NOT NULL DEFAULT '',
  `refund_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC生图扣费明细';

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

INSERT INTO `la_aigc_image_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'master','大师版','mock','mock-image',4,'{}',1,300,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','全能版','mock','mock-image',4,'{}',1,200,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','免费image-2','mock','mock-image',4,'{}',1,100,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`provider`=VALUES(`provider`),`model`=VALUES(`model`),`max_reference_images`=VALUES(`max_reference_images`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_image_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'master','1k','普通1K','1:1',1024,1024,4.00,4.00,'{}',1,500,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','1k','普通1K','3:4',768,1024,4.00,4.00,'{}',1,490,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','1k','普通1K','4:3',1024,768,4.00,4.00,'{}',1,480,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','1k','普通1K','9:16',576,1024,4.00,4.00,'{}',1,470,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','1k','普通1K','16:9',1024,576,4.00,4.00,'{}',1,460,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','2k','高清2K','1:1',2048,2048,8.00,8.00,'{}',1,450,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','2k','高清2K','3:4',1536,2048,8.00,8.00,'{}',1,440,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','2k','高清2K','4:3',2048,1536,8.00,8.00,'{}',1,430,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','2k','高清2K','9:16',1152,2048,8.00,8.00,'{}',1,420,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','2k','高清2K','16:9',2048,1152,8.00,8.00,'{}',1,410,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','4k','超清4K','1:1',4096,4096,16.00,16.00,'{}',1,400,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','4k','超清4K','3:4',3072,4096,16.00,16.00,'{}',1,390,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','4k','超清4K','4:3',4096,3072,16.00,16.00,'{}',1,380,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','4k','超清4K','9:16',2304,4096,16.00,16.00,'{}',1,370,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','4k','超清4K','16:9',4096,2304,16.00,16.00,'{}',1,360,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','1k','普通1K','1:1',1024,1024,4.00,4.00,'{}',1,500,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','1k','普通1K','3:4',768,1024,4.00,4.00,'{}',1,490,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','1k','普通1K','4:3',1024,768,4.00,4.00,'{}',1,480,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','1k','普通1K','9:16',576,1024,4.00,4.00,'{}',1,470,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','1k','普通1K','16:9',1024,576,4.00,4.00,'{}',1,460,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','2k','高清2K','1:1',2048,2048,8.00,8.00,'{}',1,450,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','2k','高清2K','3:4',1536,2048,8.00,8.00,'{}',1,440,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','2k','高清2K','4:3',2048,1536,8.00,8.00,'{}',1,430,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','2k','高清2K','9:16',1152,2048,8.00,8.00,'{}',1,420,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','2k','高清2K','16:9',2048,1152,8.00,8.00,'{}',1,410,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','4k','超清4K','1:1',4096,4096,16.00,16.00,'{}',1,400,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','4k','超清4K','3:4',3072,4096,16.00,16.00,'{}',1,390,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','4k','超清4K','4:3',4096,3072,16.00,16.00,'{}',1,380,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','4k','超清4K','9:16',2304,4096,16.00,16.00,'{}',1,370,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','4k','超清4K','16:9',4096,2304,16.00,16.00,'{}',1,360,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','1k','普通1K','1:1',1024,1024,4.00,4.00,'{}',1,500,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','1k','普通1K','3:4',768,1024,4.00,4.00,'{}',1,490,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','1k','普通1K','4:3',1024,768,4.00,4.00,'{}',1,480,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','1k','普通1K','9:16',576,1024,4.00,4.00,'{}',1,470,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','1k','普通1K','16:9',1024,576,4.00,4.00,'{}',1,460,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','2k','高清2K','1:1',2048,2048,8.00,8.00,'{}',1,450,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','2k','高清2K','3:4',1536,2048,8.00,8.00,'{}',1,440,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','2k','高清2K','4:3',2048,1536,8.00,8.00,'{}',1,430,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','2k','高清2K','9:16',1152,2048,8.00,8.00,'{}',1,420,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','2k','高清2K','16:9',2048,1152,8.00,8.00,'{}',1,410,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','4k','超清4K','1:1',4096,4096,16.00,16.00,'{}',1,400,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','4k','超清4K','3:4',3072,4096,16.00,16.00,'{}',1,390,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','4k','超清4K','4:3',4096,3072,16.00,16.00,'{}',1,380,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','4k','超清4K','9:16',2304,4096,16.00,16.00,'{}',1,370,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','4k','超清4K','16:9',4096,2304,16.00,16.00,'{}',1,360,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `quality_label`=VALUES(`quality_label`),`width`=VALUES(`width`),`height`=VALUES(`height`),`platform_unit_cost`=VALUES(`platform_unit_cost`),`tenant_unit_price`=VALUES(`tenant_unit_price`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_image_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'gpt_image_2','GPT Image 2','xhadmin','gpt-image-2',4,'{"poll_interval":2,"poll_attempts":30,"upstream_channel":"OpenaiM"}',1,400,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`provider`=VALUES(`provider`),`model`=VALUES(`model`),`max_reference_images`=VALUES(`max_reference_images`),`config_json`=VALUES(`config_json`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_image_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'gpt_image_2','1k','标准1K','1:1',1024,1024,30.00,30.00,'{"resolution":"1k","aspect_ratio":"1:1"}',1,1000,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','16:9',1024,576,30.00,30.00,'{"resolution":"1k","aspect_ratio":"16:9"}',1,990,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','9:16',576,1024,30.00,30.00,'{"resolution":"1k","aspect_ratio":"9:16"}',1,980,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','4:3',1024,768,30.00,30.00,'{"resolution":"1k","aspect_ratio":"4:3"}',1,970,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','3:4',768,1024,30.00,30.00,'{"resolution":"1k","aspect_ratio":"3:4"}',1,960,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','3:2',1024,682,30.00,30.00,'{"resolution":"1k","aspect_ratio":"3:2"}',1,950,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','2:3',682,1024,30.00,30.00,'{"resolution":"1k","aspect_ratio":"2:3"}',1,940,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','5:4',1024,819,30.00,30.00,'{"resolution":"1k","aspect_ratio":"5:4"}',1,930,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','4:5',819,1024,30.00,30.00,'{"resolution":"1k","aspect_ratio":"4:5"}',1,920,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','2:1',1024,512,30.00,30.00,'{"resolution":"1k","aspect_ratio":"2:1"}',1,910,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','1:2',512,1024,30.00,30.00,'{"resolution":"1k","aspect_ratio":"1:2"}',1,900,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','21:9',1024,439,30.00,30.00,'{"resolution":"1k","aspect_ratio":"21:9"}',1,890,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','1k','标准1K','9:21',439,1024,30.00,30.00,'{"resolution":"1k","aspect_ratio":"9:21"}',1,880,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','1:1',2048,2048,60.00,60.00,'{"resolution":"2k","aspect_ratio":"1:1"}',1,870,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','16:9',2048,1152,60.00,60.00,'{"resolution":"2k","aspect_ratio":"16:9"}',1,860,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','9:16',1152,2048,60.00,60.00,'{"resolution":"2k","aspect_ratio":"9:16"}',1,850,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','4:3',2048,1536,60.00,60.00,'{"resolution":"2k","aspect_ratio":"4:3"}',1,840,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','3:4',1536,2048,60.00,60.00,'{"resolution":"2k","aspect_ratio":"3:4"}',1,830,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','3:2',2048,1365,60.00,60.00,'{"resolution":"2k","aspect_ratio":"3:2"}',1,820,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','2:3',1365,2048,60.00,60.00,'{"resolution":"2k","aspect_ratio":"2:3"}',1,810,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','5:4',2048,1638,60.00,60.00,'{"resolution":"2k","aspect_ratio":"5:4"}',1,800,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','4:5',1638,2048,60.00,60.00,'{"resolution":"2k","aspect_ratio":"4:5"}',1,790,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','2:1',2048,1024,60.00,60.00,'{"resolution":"2k","aspect_ratio":"2:1"}',1,780,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','1:2',1024,2048,60.00,60.00,'{"resolution":"2k","aspect_ratio":"1:2"}',1,770,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','21:9',2048,878,60.00,60.00,'{"resolution":"2k","aspect_ratio":"21:9"}',1,760,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','2k','高清2K','9:21',878,2048,60.00,60.00,'{"resolution":"2k","aspect_ratio":"9:21"}',1,750,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','1:1',4096,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"1:1"}',1,745,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','16:9',4096,2304,120.00,120.00,'{"resolution":"4k","aspect_ratio":"16:9"}',1,740,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','9:16',2304,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"9:16"}',1,735,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','4:3',4096,3072,120.00,120.00,'{"resolution":"4k","aspect_ratio":"4:3"}',1,730,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','3:4',3072,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"3:4"}',1,725,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','3:2',4096,2731,120.00,120.00,'{"resolution":"4k","aspect_ratio":"3:2"}',1,720,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','2:3',2731,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"2:3"}',1,715,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','5:4',4096,3277,120.00,120.00,'{"resolution":"4k","aspect_ratio":"5:4"}',1,712,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','2:1',4096,2048,120.00,120.00,'{"resolution":"4k","aspect_ratio":"2:1"}',1,710,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','4:5',3277,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"4:5"}',1,705,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','21:9',4096,1755,120.00,120.00,'{"resolution":"4k","aspect_ratio":"21:9"}',1,700,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','9:21',1755,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"9:21"}',1,695,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','1:2',2048,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"1:2"}',1,690,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `quality_label`=VALUES(`quality_label`),`width`=VALUES(`width`),`height`=VALUES(`height`),`platform_unit_cost`=VALUES(`platform_unit_cost`),`tenant_unit_price`=VALUES(`tenant_unit_price`),`provider_params_json`=VALUES(`provider_params_json`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);
