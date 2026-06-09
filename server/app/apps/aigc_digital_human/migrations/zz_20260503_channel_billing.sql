CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_channel` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID，0为平台配置',
  `code` varchar(64) NOT NULL DEFAULT '' COMMENT '通道编码',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '通道名称',
  `provider` varchar(50) NOT NULL DEFAULT 'mock' COMMENT '供应商',
  `model` varchar(100) NOT NULL DEFAULT 'mock-digital-human' COMMENT '模型',
  `max_reference_images` int unsigned NOT NULL DEFAULT 1 COMMENT '最大参考图数量',
  `config_json` text COMMENT 'Provider参数预留',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人通道';

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_channel_spec` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID，0为平台配置',
  `channel_code` varchar(64) NOT NULL DEFAULT '' COMMENT '通道编码',
  `quality` varchar(30) NOT NULL DEFAULT '' COMMENT '分辨率档位',
  `quality_label` varchar(50) NOT NULL DEFAULT '' COMMENT '分辨率名称',
  `ratio` varchar(30) NOT NULL DEFAULT '' COMMENT '画面比例',
  `width` int unsigned NOT NULL DEFAULT 0 COMMENT '宽度',
  `height` int unsigned NOT NULL DEFAULT 0 COMMENT '高度',
  `platform_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '平台成本单价',
  `tenant_unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户用户售价',
  `billing_unit` varchar(20) NOT NULL DEFAULT 'second' COMMENT '计费单位 second/count',
  `provider_params_json` text COMMENT 'Provider规格参数预留',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_spec` (`tenant_id`,`channel_code`,`quality`,`ratio`),
  KEY `idx_channel` (`tenant_id`,`channel_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人通道规格';

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_billing` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `result_id` int unsigned NOT NULL DEFAULT 0,
  `channel` varchar(64) NOT NULL DEFAULT '',
  `quality` varchar(30) NOT NULL DEFAULT '',
  `ratio` varchar(30) NOT NULL DEFAULT '',
  `billing_type` varchar(30) NOT NULL DEFAULT 'generate' COMMENT '计费类型 generate/avatar_clone/voice_clone',
  `billing_unit` varchar(20) NOT NULL DEFAULT 'count' COMMENT '计费单位 second/count',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `platform_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '平台成本单价',
  `tenant_unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户用户售价',
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户成本扣点',
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '用户消费扣点',
  `billing_status` varchar(30) NOT NULL DEFAULT 'deducted',
  `tenant_point_sn` varchar(64) NOT NULL DEFAULT '',
  `user_point_sn` varchar(64) NOT NULL DEFAULT '',
  `extra_json` text COMMENT '计费扩展信息',
  `refund_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人扣费明细';

INSERT INTO `la_aigc_digital_human_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'master','大师版','xhadmin','xiaojiayu1.0',1,'{"tts_model":"s2-pro","tts_format":"mp3","lipsync_model":"xiaojiayu1.0"}',1,300,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','全能版','xhadmin','xiaojiayu1.0',1,'{"tts_model":"s2-pro","tts_format":"mp3","lipsync_model":"xiaojiayu1.0"}',1,200,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','体验版','xhadmin','xiaojiayu1.0',1,'{"tts_model":"s2-pro","tts_format":"mp3","lipsync_model":"xiaojiayu1.0"}',1,100,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=IF(`name` IS NULL OR `name` = '', VALUES(`name`), `name`),`provider`=IF(`provider` IS NULL OR `provider` = '', VALUES(`provider`), `provider`),`model`=IF(`model` IS NULL OR `model` = '', VALUES(`model`), `model`),`max_reference_images`=IF(`max_reference_images` <= 0, VALUES(`max_reference_images`), `max_reference_images`),`status`=`status`,`sort`=IF(`sort` <= 0, VALUES(`sort`), `sort`),`update_time`=`update_time`;

INSERT INTO `la_aigc_digital_human_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`billing_unit`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'master','1k','普通1K','16:9',1024,576,0.20,0.30,'second','{}',1,500,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','1k','普通1K','9:16',576,1024,0.20,0.30,'second','{}',1,490,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','1k','普通1K','1:1',1024,1024,0.20,0.30,'second','{}',1,480,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','2k','高清2K','16:9',2048,1152,0.40,0.60,'second','{}',1,470,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'master','2k','高清2K','9:16',1152,2048,0.40,0.60,'second','{}',1,460,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','1k','普通1K','16:9',1024,576,0.20,0.30,'second','{}',1,500,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','1k','普通1K','9:16',576,1024,0.20,0.30,'second','{}',1,490,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','1k','普通1K','1:1',1024,1024,0.20,0.30,'second','{}',1,480,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','2k','高清2K','16:9',2048,1152,0.40,0.60,'second','{}',1,470,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'all','2k','高清2K','9:16',1152,2048,0.40,0.60,'second','{}',1,460,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','1k','普通1K','16:9',1024,576,0.20,0.30,'second','{}',1,500,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','1k','普通1K','9:16',576,1024,0.20,0.30,'second','{}',1,490,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'free','1k','普通1K','1:1',1024,1024,0.20,0.30,'second','{}',1,480,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `quality_label`=IF(`quality_label` IS NULL OR `quality_label` = '', VALUES(`quality_label`), `quality_label`),`width`=IF(`width` <= 0, VALUES(`width`), `width`),`height`=IF(`height` <= 0, VALUES(`height`), `height`),`platform_unit_cost`=IF(`platform_unit_cost` <= 0, VALUES(`platform_unit_cost`), `platform_unit_cost`),`tenant_unit_price`=IF(`tenant_unit_price` <= 0, VALUES(`tenant_unit_price`), `tenant_unit_price`),`billing_unit`=IF(`billing_unit` IS NULL OR `billing_unit` = '', VALUES(`billing_unit`), `billing_unit`),`status`=`status`,`sort`=IF(`sort` <= 0, VALUES(`sort`), `sort`),`update_time`=`update_time`;
