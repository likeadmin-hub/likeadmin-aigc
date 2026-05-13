-- AIGC video application business tables.
-- Core app-center/update tables belong to system migrations, not this app package.

CREATE TABLE IF NOT EXISTS `la_aigc_video_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `provider_mode` varchar(30) NOT NULL DEFAULT 'platform',
  `provider` varchar(50) NOT NULL DEFAULT 'mock',
  `model` varchar(100) NOT NULL DEFAULT 'mock-video',
  `config_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC视频配置';

CREATE TABLE IF NOT EXISTS `la_aigc_video_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `negative_prompt` text,
  `reference_images` text COMMENT '参考图',
  `style` varchar(50) NOT NULL DEFAULT '',
  `channel` varchar(64) NOT NULL DEFAULT '' COMMENT '通道',
  `quality` varchar(30) NOT NULL DEFAULT '' COMMENT '视频时长档位',
  `ratio` varchar(30) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户成本扣点',
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '用户消费扣点',
  `provider` varchar(50) NOT NULL DEFAULT '' COMMENT '供应商',
  `model` varchar(100) NOT NULL DEFAULT '' COMMENT '模型',
  `provider_task_id` varchar(120) NOT NULL DEFAULT '' COMMENT '供应商任务ID',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `error` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC视频任务';

CREATE TABLE IF NOT EXISTS `la_aigc_video_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `channel` varchar(64) NOT NULL DEFAULT '' COMMENT '通道',
  `quality` varchar(30) NOT NULL DEFAULT '' COMMENT '视频时长档位',
  `ratio` varchar(30) NOT NULL DEFAULT '' COMMENT '视频比例',
  `video_uri` varchar(255) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'platform',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户成本扣点',
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '用户消费扣点',
  `provider_task_id` varchar(120) NOT NULL DEFAULT '' COMMENT '供应商任务ID',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC视频结果';

CREATE TABLE IF NOT EXISTS `la_aigc_video_quota` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `total_quota` int unsigned NOT NULL DEFAULT 0,
  `used_quota` int unsigned NOT NULL DEFAULT 0,
  `expire_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC视频额度';

CREATE TABLE IF NOT EXISTS `la_aigc_video_sensitive_word` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `word` varchar(100) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC视频敏感词';
