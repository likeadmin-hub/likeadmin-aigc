CREATE TABLE IF NOT EXISTS `la_aigc_video_channel` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID，0为平台配置',
  `code` varchar(64) NOT NULL DEFAULT '' COMMENT '通道编码',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '通道名称',
  `provider` varchar(50) NOT NULL DEFAULT 'mock' COMMENT '供应商',
  `model` varchar(100) NOT NULL DEFAULT 'mock-video' COMMENT '模型',
  `max_reference_images` int unsigned NOT NULL DEFAULT 4 COMMENT '最大参考图数量',
  `config_json` text COMMENT 'Provider参数预留',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC视频通道';

CREATE TABLE IF NOT EXISTS `la_aigc_video_channel_spec` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID，0为平台配置',
  `channel_code` varchar(64) NOT NULL DEFAULT '' COMMENT '通道编码',
  `quality` varchar(30) NOT NULL DEFAULT '' COMMENT '视频时长档位',
  `quality_label` varchar(50) NOT NULL DEFAULT '' COMMENT '视频时长名称',
  `ratio` varchar(30) NOT NULL DEFAULT '' COMMENT '视频比例',
  `width` int unsigned NOT NULL DEFAULT 0 COMMENT '宽度',
  `height` int unsigned NOT NULL DEFAULT 0 COMMENT '高度',
  `upstream_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '上游成本单价',
  `platform_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '平台供给单价',
  `tenant_unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户用户售价',
  `upstream_cost_text` varchar(500) NOT NULL DEFAULT '' COMMENT '上游成本说明',
  `cost_source_url` varchar(500) NOT NULL DEFAULT '' COMMENT '成本来源链接',
  `provider_params_json` text COMMENT 'Provider规格参数预留',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_spec` (`tenant_id`,`channel_code`,`quality`,`ratio`),
  KEY `idx_channel` (`tenant_id`,`channel_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC视频通道规格';

CREATE TABLE IF NOT EXISTS `la_aigc_video_billing` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC视频扣费明细';

INSERT INTO `la_aigc_video_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'grok_video_xaiq','Grok Video（xAIQ）','xhadmin','grok-video',7,'{"poll_interval":2,"poll_attempts":30,"quantity_options":[1],"quality":"720p"}',1,400,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`provider`=VALUES(`provider`),`model`=VALUES(`model`),`max_reference_images`=VALUES(`max_reference_images`),`config_json`=VALUES(`config_json`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'grok_video_xaiq','6','6秒','16:9',1280,720,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"16:9"}',1,1000,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','6','6秒','9:16',720,1280,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"9:16"}',1,990,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','6','6秒','1:1',720,720,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"1:1"}',1,980,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','6','6秒','2:3',720,1080,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"2:3"}',1,970,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','6','6秒','3:2',1080,720,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"3:2"}',1,960,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','16:9',1280,720,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"16:9"}',1,950,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','9:16',720,1280,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"9:16"}',1,940,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','1:1',720,720,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"1:1"}',1,930,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','2:3',720,1080,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"2:3"}',1,920,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','3:2',1080,720,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"3:2"}',1,910,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','16:9',1280,720,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"16:9"}',1,900,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','9:16',720,1280,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"9:16"}',1,890,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','1:1',720,720,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"1:1"}',1,880,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','2:3',720,1080,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"2:3"}',1,870,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','3:2',1080,720,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"3:2"}',1,860,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','16:9',1280,720,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"16:9"}',1,850,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','9:16',720,1280,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"9:16"}',1,840,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','1:1',720,720,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"1:1"}',1,830,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','2:3',720,1080,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"2:3"}',1,820,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','3:2',1080,720,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"3:2"}',1,810,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','16:9',1280,720,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"16:9"}',1,800,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','9:16',720,1280,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"9:16"}',1,790,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','1:1',720,720,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"1:1"}',1,780,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','2:3',720,1080,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"2:3"}',1,770,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','3:2',1080,720,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"3:2"}',1,760,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','16:9',1280,720,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"16:9"}',1,750,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','9:16',720,1280,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"9:16"}',1,740,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','1:1',720,720,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"1:1"}',1,730,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','2:3',720,1080,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"2:3"}',1,720,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','3:2',1080,720,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"3:2"}',1,710,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `quality_label`=VALUES(`quality_label`),`width`=VALUES(`width`),`height`=VALUES(`height`),`platform_unit_cost`=VALUES(`platform_unit_cost`),`tenant_unit_price`=VALUES(`tenant_unit_price`),`provider_params_json`=VALUES(`provider_params_json`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);

UPDATE `la_aigc_video_channel`
SET `status` = 0, `sort` = 0, `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `code` <> 'grok_video_xaiq';

UPDATE `la_aigc_video_channel_spec`
SET `status` = 0, `sort` = 0, `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `channel_code` <> 'grok_video_xaiq';
