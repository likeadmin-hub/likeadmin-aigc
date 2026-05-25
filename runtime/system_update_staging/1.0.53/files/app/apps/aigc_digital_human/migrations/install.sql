-- Digital human application business tables.
-- Core app-center/update tables belong to system migrations, not this app package.

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `provider_mode` varchar(30) NOT NULL DEFAULT 'platform',
  `provider` varchar(50) NOT NULL DEFAULT 'mock',
  `model` varchar(100) NOT NULL DEFAULT 'mock-digital-human',
  `config_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人配置';

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_avatar` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '0为官方形象',
  `name` varchar(80) NOT NULL DEFAULT '',
  `source` varchar(20) NOT NULL DEFAULT 'mine' COMMENT 'official/mine',
  `gender` varchar(20) NOT NULL DEFAULT '',
  `scene` varchar(50) NOT NULL DEFAULT '',
  `cover_uri` varchar(500) NOT NULL DEFAULT '',
  `media_uri` varchar(500) NOT NULL DEFAULT '',
  `media_type` varchar(20) NOT NULL DEFAULT 'image',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'platform',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `provider` varchar(50) NOT NULL DEFAULT '',
  `provider_asset_id` varchar(120) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'ready',
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`tenant_id`,`user_id`,`source`,`delete_time`),
  KEY `idx_provider` (`tenant_id`,`provider`,`provider_asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人形象资产';

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_voice` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '0为官方声音',
  `name` varchar(80) NOT NULL DEFAULT '',
  `source` varchar(20) NOT NULL DEFAULT 'mine' COMMENT 'official/mine',
  `gender` varchar(20) NOT NULL DEFAULT '',
  `age_group` varchar(20) NOT NULL DEFAULT '',
  `cover_uri` varchar(500) NOT NULL DEFAULT '',
  `audio_uri` varchar(500) NOT NULL DEFAULT '',
  `preview_audio_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '试听音频地址',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'platform',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `duration` int unsigned NOT NULL DEFAULT 0,
  `provider` varchar(50) NOT NULL DEFAULT '',
  `provider_asset_id` varchar(120) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'ready',
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`tenant_id`,`user_id`,`source`,`delete_time`),
  KEY `idx_provider` (`tenant_id`,`provider`,`provider_asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人声音资产';

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `avatar_id` int unsigned NOT NULL DEFAULT 0,
  `voice_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(120) NOT NULL DEFAULT '',
  `script_text` text,
  `prompt` text,
  `channel` varchar(64) NOT NULL DEFAULT '',
  `quality` varchar(30) NOT NULL DEFAULT '',
  `ratio` varchar(30) NOT NULL DEFAULT '',
  `duration` int unsigned NOT NULL DEFAULT 0,
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `provider` varchar(50) NOT NULL DEFAULT '',
  `model` varchar(100) NOT NULL DEFAULT '',
  `provider_task_id` varchar(120) NOT NULL DEFAULT '',
  `provider_stage` varchar(50) NOT NULL DEFAULT '' COMMENT '供应商编排阶段',
  `tts_task_id` varchar(120) NOT NULL DEFAULT '' COMMENT 'TTS供应商任务ID',
  `tts_audio_uri` varchar(500) NOT NULL DEFAULT '' COMMENT 'TTS音频地址',
  `provider_payload_json` text COMMENT '供应商阶段载荷',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `progress` tinyint unsigned NOT NULL DEFAULT 0,
  `error` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`,`delete_time`),
  KEY `idx_provider_task` (`tenant_id`,`provider`,`provider_task_id`),
  KEY `idx_tts_task` (`tenant_id`,`provider`,`tts_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人合成任务';

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `avatar_id` int unsigned NOT NULL DEFAULT 0,
  `voice_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(120) NOT NULL DEFAULT '',
  `cover_uri` varchar(500) NOT NULL DEFAULT '',
  `video_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'platform',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `duration` int unsigned NOT NULL DEFAULT 0,
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `provider_task_id` varchar(120) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人合成结果';

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_quota` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人额度';

CREATE TABLE IF NOT EXISTS `la_aigc_digital_human_sensitive_word` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `word` varchar(100) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数字人敏感词';
