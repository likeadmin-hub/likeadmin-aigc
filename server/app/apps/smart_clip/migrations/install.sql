CREATE TABLE IF NOT EXISTS `la_smart_clip_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `provider_mode` varchar(30) NOT NULL DEFAULT 'platform',
  `provider` varchar(50) NOT NULL DEFAULT 'xhadmin',
  `model` varchar(100) NOT NULL DEFAULT 'smart_clip',
  `config_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI视频剪辑配置';

CREATE TABLE IF NOT EXISTS `la_smart_clip_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `clip_type` varchar(50) NOT NULL DEFAULT '' COMMENT '剪辑类型',
  `scene` varchar(50) NOT NULL DEFAULT '' COMMENT '模板场景',
  `style_id` varchar(120) NOT NULL DEFAULT '' COMMENT '模板ID',
  `title` varchar(255) NOT NULL DEFAULT '',
  `video_url` varchar(500) NOT NULL DEFAULT '',
  `audio_url` varchar(500) NOT NULL DEFAULT '',
  `materials` text COMMENT '素材列表',
  `introduce_card` text COMMENT '身份栏',
  `pack_rules` text COMMENT '包装规则',
  `process_rules` text COMMENT '处理规则',
  `struct_layers` text COMMENT '图层设置',
  `subtitle` text COMMENT '字幕',
  `source_app` varchar(80) NOT NULL DEFAULT '',
  `source_result_id` int unsigned NOT NULL DEFAULT 0,
  `channel` varchar(64) NOT NULL DEFAULT '',
  `quality` varchar(30) NOT NULL DEFAULT '',
  `ratio` varchar(30) NOT NULL DEFAULT 'duration',
  `duration` int unsigned NOT NULL DEFAULT 0 COMMENT '计费时长秒',
  `quantity` int unsigned NOT NULL DEFAULT 1 COMMENT '计费数量',
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `provider` varchar(50) NOT NULL DEFAULT '',
  `model` varchar(100) NOT NULL DEFAULT '',
  `provider_task_id` varchar(120) NOT NULL DEFAULT '',
  `provider_payload` text COMMENT '供应商响应',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `error` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_provider_task` (`provider_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI视频剪辑任务';

CREATE TABLE IF NOT EXISTS `la_smart_clip_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `clip_type` varchar(50) NOT NULL DEFAULT '',
  `style_id` varchar(120) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL DEFAULT '',
  `video_uri` varchar(500) NOT NULL DEFAULT '',
  `cover_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'platform',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `duration` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `provider_task_id` varchar(120) NOT NULL DEFAULT '',
  `result_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI视频剪辑结果';

CREATE TABLE IF NOT EXISTS `la_smart_clip_channel` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID，0为平台配置',
  `code` varchar(64) NOT NULL DEFAULT '' COMMENT '通道编码',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '通道名称',
  `provider` varchar(50) NOT NULL DEFAULT 'xhadmin' COMMENT '供应商',
  `model` varchar(100) NOT NULL DEFAULT 'smart_clip' COMMENT '模型',
  `max_reference_images` int unsigned NOT NULL DEFAULT 0,
  `config_json` text COMMENT 'Provider参数预留',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI视频剪辑通道';

CREATE TABLE IF NOT EXISTS `la_smart_clip_channel_spec` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID，0为平台配置',
  `channel_code` varchar(64) NOT NULL DEFAULT '' COMMENT '通道编码',
  `quality` varchar(30) NOT NULL DEFAULT '1' COMMENT '计费单位秒',
  `quality_label` varchar(50) NOT NULL DEFAULT '1秒计费',
  `ratio` varchar(30) NOT NULL DEFAULT 'duration',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `upstream_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '每单位上游成本',
  `platform_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '每单位平台供给价',
  `tenant_unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '每单位用户售价',
  `upstream_cost_text` varchar(500) NOT NULL DEFAULT '' COMMENT '上游成本说明',
  `cost_source_url` varchar(500) NOT NULL DEFAULT '' COMMENT '成本来源链接',
  `provider_params_json` text COMMENT 'Provider规格参数预留',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_spec` (`tenant_id`,`channel_code`,`quality`,`ratio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI视频剪辑计费规格';

CREATE TABLE IF NOT EXISTS `la_smart_clip_billing` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `result_id` int unsigned NOT NULL DEFAULT 0,
  `channel` varchar(64) NOT NULL DEFAULT '',
  `quality` varchar(30) NOT NULL DEFAULT '',
  `ratio` varchar(30) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `platform_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tenant_unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `billing_status` varchar(30) NOT NULL DEFAULT 'deducted',
  `tenant_point_sn` varchar(64) NOT NULL DEFAULT '',
  `user_point_sn` varchar(64) NOT NULL DEFAULT '',
  `refund_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`tenant_id`,`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI视频剪辑扣费明细';

CREATE TABLE IF NOT EXISTS `la_smart_clip_sensitive_word` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `word` varchar(100) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI视频剪辑敏感词';

INSERT INTO `la_smart_clip_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'smart_clip','智能剪辑','xhadmin','smart_clip',0,'{"task_path":"/api/v1/tasks/{task_id}","timeout":30}',1,100,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `la_smart_clip_channel`.`name`=VALUES(`name`),`la_smart_clip_channel`.`provider`=VALUES(`provider`),`la_smart_clip_channel`.`model`=VALUES(`model`),`la_smart_clip_channel`.`config_json`=VALUES(`config_json`),`la_smart_clip_channel`.`status`=VALUES(`status`),`la_smart_clip_channel`.`sort`=VALUES(`sort`),`la_smart_clip_channel`.`update_time`=VALUES(`update_time`);

INSERT INTO `la_smart_clip_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'smart_clip','1','1秒计费','duration',0,0,0.02,0.02,'{"unit_seconds":1}',1,100,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `la_smart_clip_channel_spec`.`quality_label`=VALUES(`quality_label`),`la_smart_clip_channel_spec`.`platform_unit_cost`=VALUES(`platform_unit_cost`),`la_smart_clip_channel_spec`.`tenant_unit_price`=VALUES(`tenant_unit_price`),`la_smart_clip_channel_spec`.`provider_params_json`=VALUES(`provider_params_json`),`la_smart_clip_channel_spec`.`status`=VALUES(`status`),`la_smart_clip_channel_spec`.`sort`=VALUES(`sort`),`la_smart_clip_channel_spec`.`update_time`=VALUES(`update_time`);
