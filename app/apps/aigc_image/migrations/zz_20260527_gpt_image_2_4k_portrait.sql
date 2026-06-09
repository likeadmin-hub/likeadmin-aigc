CREATE TABLE IF NOT EXISTS `la_aigc_image_channel_spec` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID，0为平台配置',
  `channel_code` varchar(64) NOT NULL DEFAULT '' COMMENT '通道编码',
  `quality` varchar(30) NOT NULL DEFAULT '' COMMENT '分辨率档位',
  `quality_label` varchar(50) NOT NULL DEFAULT '' COMMENT '分辨率名称',
  `ratio` varchar(30) NOT NULL DEFAULT '' COMMENT '图片比例',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC生图通道规格';

INSERT INTO `la_aigc_image_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'gpt_image_2','4k','超清4K','1:1',4096,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"1:1"}',1,745,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','9:16',2304,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"9:16"}',1,735,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','3:4',3072,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"3:4"}',1,725,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','2:3',2731,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"2:3"}',1,715,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','5:4',4096,3277,120.00,120.00,'{"resolution":"4k","aspect_ratio":"5:4"}',1,712,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','4:5',3277,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"4:5"}',1,705,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','9:21',1755,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"9:21"}',1,695,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'gpt_image_2','4k','超清4K','1:2',2048,4096,120.00,120.00,'{"resolution":"4k","aspect_ratio":"1:2"}',1,690,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `quality_label`=IF(`quality_label` IS NULL OR `quality_label` = '', VALUES(`quality_label`), `quality_label`),`width`=IF(`width` <= 0, VALUES(`width`), `width`),`height`=IF(`height` <= 0, VALUES(`height`), `height`),`platform_unit_cost`=IF(`platform_unit_cost` <= 0, VALUES(`platform_unit_cost`), `platform_unit_cost`),`tenant_unit_price`=IF(`tenant_unit_price` <= 0, VALUES(`tenant_unit_price`), `tenant_unit_price`),`provider_params_json`=IF(`provider_params_json` IS NULL OR `provider_params_json` = '' OR `provider_params_json` = '{}', VALUES(`provider_params_json`), `provider_params_json`),`status`=`status`,`sort`=IF(`sort` <= 0, VALUES(`sort`), `sort`),`update_time`=`update_time`;
