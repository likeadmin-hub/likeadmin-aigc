CREATE TABLE IF NOT EXISTS `la_aigc_music_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `provider_mode` varchar(30) NOT NULL DEFAULT 'platform',
  `provider` varchar(50) NOT NULL DEFAULT 'mock',
  `model` varchar(100) NOT NULL DEFAULT 'music_generation',
  `config_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI音乐配置';

CREATE TABLE IF NOT EXISTS `la_aigc_music_channel` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `code` varchar(64) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `provider` varchar(50) NOT NULL DEFAULT 'mock',
  `model` varchar(100) NOT NULL DEFAULT 'music_generation',
  `config_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI音乐通道';

CREATE TABLE IF NOT EXISTS `la_aigc_music_channel_spec` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `channel_code` varchar(64) NOT NULL DEFAULT '',
  `quality` varchar(30) NOT NULL DEFAULT '30',
  `quality_label` varchar(50) NOT NULL DEFAULT '30秒音乐',
  `ratio` varchar(30) NOT NULL DEFAULT 'duration',
  `unit_seconds` int unsigned NOT NULL DEFAULT 30,
  `upstream_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `platform_unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tenant_unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `upstream_cost_text` varchar(500) NOT NULL DEFAULT '',
  `cost_source_url` varchar(500) NOT NULL DEFAULT '',
  `provider_params_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_spec` (`tenant_id`,`channel_code`,`quality`,`ratio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI音乐计费规格';

CREATE TABLE IF NOT EXISTS `la_aigc_music_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `app_code` varchar(50) NOT NULL DEFAULT 'aigc_music',
  `action` varchar(50) NOT NULL DEFAULT 'music_generation',
  `title` varchar(255) NOT NULL DEFAULT '',
  `prompt` text,
  `lyrics` mediumtext,
  `genre` varchar(100) NOT NULL DEFAULT '',
  `mood` varchar(100) NOT NULL DEFAULT '',
  `instruments` varchar(255) NOT NULL DEFAULT '',
  `style_id` int unsigned NOT NULL DEFAULT 0,
  `persona_id` int unsigned NOT NULL DEFAULT 0,
  `voice_clone_id` int unsigned NOT NULL DEFAULT 0,
  `reference_asset_id` int unsigned NOT NULL DEFAULT 0,
  `channel` varchar(64) NOT NULL DEFAULT '',
  `quality` varchar(30) NOT NULL DEFAULT '',
  `ratio` varchar(30) NOT NULL DEFAULT 'duration',
  `duration` int unsigned NOT NULL DEFAULT 0,
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `provider` varchar(50) NOT NULL DEFAULT '',
  `model` varchar(100) NOT NULL DEFAULT '',
  `provider_task_id` varchar(120) NOT NULL DEFAULT '',
  `provider_payload` text,
  `idempotency_key` varchar(100) NOT NULL DEFAULT '',
  `billing_status` varchar(30) NOT NULL DEFAULT 'none',
  `safety_status` varchar(30) NOT NULL DEFAULT 'not_required',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `progress` tinyint unsigned NOT NULL DEFAULT 0,
  `error_code` varchar(50) NOT NULL DEFAULT '',
  `error` text,
  `result_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_provider_task` (`provider_task_id`),
  KEY `idx_idempotency` (`tenant_id`,`user_id`,`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI音乐任务';

CREATE TABLE IF NOT EXISTS `la_aigc_music_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL DEFAULT '',
  `audio_uri` varchar(500) NOT NULL DEFAULT '',
  `wav_uri` varchar(500) NOT NULL DEFAULT '',
  `mp4_uri` varchar(500) NOT NULL DEFAULT '',
  `midi_uri` varchar(500) NOT NULL DEFAULT '',
  `timing_uri` varchar(500) NOT NULL DEFAULT '',
  `vox_uri` varchar(500) NOT NULL DEFAULT '',
  `cover_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'platform',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `mime_type` varchar(100) NOT NULL DEFAULT '',
  `file_size` bigint unsigned NOT NULL DEFAULT 0,
  `duration` decimal(10,2) NOT NULL DEFAULT 0.00,
  `lyrics` mediumtext,
  `timing_json` text,
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `provider_task_id` varchar(120) NOT NULL DEFAULT '',
  `result_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI音乐结果';

CREATE TABLE IF NOT EXISTS `la_aigc_music_asset` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `asset_type` varchar(30) NOT NULL DEFAULT 'reference_audio',
  `source_action` varchar(50) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL DEFAULT '',
  `uri` varchar(500) NOT NULL DEFAULT '',
  `url` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'platform',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `mime_type` varchar(100) NOT NULL DEFAULT '',
  `file_size` bigint unsigned NOT NULL DEFAULT 0,
  `duration` decimal(10,2) NOT NULL DEFAULT 0.00,
  `checksum` varchar(80) NOT NULL DEFAULT '',
  `auth_status` varchar(30) NOT NULL DEFAULT 'pending',
  `audit_status` varchar(30) NOT NULL DEFAULT 'pending',
  `audit_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_asset_type` (`tenant_id`,`asset_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI音乐素材';

CREATE TABLE IF NOT EXISTS `la_aigc_music_style` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL DEFAULT '',
  `description` varchar(500) NOT NULL DEFAULT '',
  `prompt` text,
  `preset_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_name` (`tenant_id`,`name`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI音乐风格';

CREATE TABLE IF NOT EXISTS `la_aigc_music_persona` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL DEFAULT '',
  `description` varchar(500) NOT NULL DEFAULT '',
  `reference_asset_id` int unsigned NOT NULL DEFAULT 0,
  `lyrics_style` varchar(100) NOT NULL DEFAULT '',
  `prompt_json` text,
  `auth_status` varchar(30) NOT NULL DEFAULT 'pending',
  `audit_status` varchar(30) NOT NULL DEFAULT 'pending',
  `audit_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI音乐Persona';

CREATE TABLE IF NOT EXISTS `la_aigc_music_voice_clone` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL DEFAULT '',
  `reference_asset_id` int unsigned NOT NULL DEFAULT 0,
  `provider_voice_id` varchar(120) NOT NULL DEFAULT '',
  `auth_status` varchar(30) NOT NULL DEFAULT 'pending',
  `audit_status` varchar(30) NOT NULL DEFAULT 'pending',
  `auth_json` text,
  `provider_payload` text,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI音乐声音克隆';

CREATE TABLE IF NOT EXISTS `la_aigc_music_export` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `result_id` int unsigned NOT NULL DEFAULT 0,
  `export_type` varchar(30) NOT NULL DEFAULT '',
  `file_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'platform',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'success',
  `error` text,
  `result_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_result` (`tenant_id`,`result_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI音乐导出记录';

CREATE TABLE IF NOT EXISTS `la_aigc_music_billing` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI音乐扣费明细';

CREATE TABLE IF NOT EXISTS `la_aigc_music_safety_audit` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `target_type` varchar(50) NOT NULL DEFAULT '',
  `target_id` int unsigned NOT NULL DEFAULT 0,
  `action` varchar(80) NOT NULL DEFAULT '',
  `decision` varchar(30) NOT NULL DEFAULT 'pending',
  `policy_hit` varchar(255) NOT NULL DEFAULT '',
  `summary` varchar(500) NOT NULL DEFAULT '',
  `audit_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_target` (`tenant_id`,`target_type`,`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI音乐安全审计';

INSERT INTO `la_aigc_music_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'music_generation','音乐生成','xhadmin','music_generation','{"timeout":180,"poll_attempts":2,"poll_interval":5,"supports":["audio","wav","mp4","midi","timing","vox"]}',1,100,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `la_aigc_music_channel`.`name`=VALUES(`name`),`la_aigc_music_channel`.`provider`=VALUES(`provider`),`la_aigc_music_channel`.`model`=VALUES(`model`),`la_aigc_music_channel`.`config_json`=VALUES(`config_json`),`la_aigc_music_channel`.`status`=VALUES(`status`),`la_aigc_music_channel`.`sort`=VALUES(`sort`),`la_aigc_music_channel`.`update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_music_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`unit_seconds`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'music_generation','30','30秒音乐','duration',30,65.00,65.00,'{"unit_seconds":30,"type":"generate"}',1,100,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `la_aigc_music_channel_spec`.`quality_label`=VALUES(`quality_label`),`la_aigc_music_channel_spec`.`unit_seconds`=VALUES(`unit_seconds`),`la_aigc_music_channel_spec`.`platform_unit_cost`=VALUES(`platform_unit_cost`),`la_aigc_music_channel_spec`.`tenant_unit_price`=VALUES(`tenant_unit_price`),`la_aigc_music_channel_spec`.`provider_params_json`=VALUES(`provider_params_json`),`la_aigc_music_channel_spec`.`status`=VALUES(`status`),`la_aigc_music_channel_spec`.`sort`=VALUES(`sort`),`la_aigc_music_channel_spec`.`update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_music_style` (`tenant_id`,`name`,`description`,`prompt`,`preset_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'流行电子','适合短视频和商业宣传的流行电子风格','pop electronic upbeat','{"genre":"pop","mood":"upbeat","instruments":"synth,drum,bass"}',1,100,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `la_aigc_music_style`.`description`=VALUES(`description`),`la_aigc_music_style`.`prompt`=VALUES(`prompt`),`la_aigc_music_style`.`preset_json`=VALUES(`preset_json`),`la_aigc_music_style`.`status`=VALUES(`status`),`la_aigc_music_style`.`sort`=VALUES(`sort`),`la_aigc_music_style`.`update_time`=VALUES(`update_time`);
