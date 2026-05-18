-- Full-drive digital human application business tables.

CREATE TABLE IF NOT EXISTS `la_image_human_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `provider` varchar(50) NOT NULL DEFAULT 'xhadmin',
  `model` varchar(100) NOT NULL DEFAULT 'image_human',
  `config_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全驱动数字人配置';

CREATE TABLE IF NOT EXISTS `la_image_human_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `avatar_id` int unsigned NOT NULL DEFAULT 0,
  `voice_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(120) NOT NULL DEFAULT '',
  `image_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '人物图片',
  `audio_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '驱动音频',
  `prompt` text,
  `mode` varchar(30) NOT NULL DEFAULT 'fast',
  `duration` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `provider` varchar(50) NOT NULL DEFAULT 'xhadmin',
  `model` varchar(100) NOT NULL DEFAULT 'image_human',
  `provider_task_id` varchar(120) NOT NULL DEFAULT '',
  `provider_payload_json` text COMMENT '供应商提交/查询载荷',
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
  KEY `idx_status` (`tenant_id`,`status`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全驱动数字人生成任务';

CREATE TABLE IF NOT EXISTS `la_image_human_avatar` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '0为官方形象',
  `name` varchar(80) NOT NULL DEFAULT '',
  `source` varchar(20) NOT NULL DEFAULT 'mine' COMMENT 'official/mine',
  `gender` varchar(20) NOT NULL DEFAULT '',
  `scene` varchar(50) NOT NULL DEFAULT '',
  `cover_uri` varchar(500) NOT NULL DEFAULT '',
  `image_uri` varchar(500) NOT NULL DEFAULT '',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全驱动数字人图片形象';

CREATE TABLE IF NOT EXISTS `la_image_human_voice` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '0为官方参考音频',
  `name` varchar(80) NOT NULL DEFAULT '',
  `source` varchar(20) NOT NULL DEFAULT 'mine' COMMENT 'official/mine',
  `gender` varchar(20) NOT NULL DEFAULT '',
  `age_group` varchar(20) NOT NULL DEFAULT '',
  `cover_uri` varchar(500) NOT NULL DEFAULT '',
  `audio_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'platform',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `duration` decimal(10,2) NOT NULL DEFAULT 0.00,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全驱动数字人参考音频';

CREATE TABLE IF NOT EXISTS `la_image_human_result` (
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
  `duration` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `provider_task_id` varchar(120) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全驱动数字人生成结果';

CREATE TABLE IF NOT EXISTS `la_image_human_billing` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `result_id` int unsigned NOT NULL DEFAULT 0,
  `mode` varchar(30) NOT NULL DEFAULT '',
  `duration` decimal(10,2) NOT NULL DEFAULT 0.00,
  `platform_unit_cost` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `tenant_unit_price` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `billing_status` varchar(30) NOT NULL DEFAULT 'deducted',
  `tenant_point_sn` varchar(64) NOT NULL DEFAULT '',
  `user_point_sn` varchar(64) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全驱动数字人计费记录';

INSERT INTO `la_image_human_config` (`tenant_id`,`provider`,`model`,`config_json`,`status`,`create_time`,`update_time`)
VALUES (
  0,
  'xhadmin',
  'image_human',
  JSON_OBJECT(
    'pricing',
    JSON_OBJECT(
      'platform_unit_cost', 1.666667,
      'tenant_unit_price', 2.000000,
      'billing_unit', 'second'
    ),
    'provider',
    JSON_OBJECT(
      'submit_path', '/api/v1/apps/image_human/submit',
      'query_path', '/api/v1/apps/image_human/query',
      'timeout', 60
    )
  ),
  1,
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
)
ON DUPLICATE KEY UPDATE
`config_json` = JSON_SET(
  COALESCE(NULLIF(`config_json`, ''), '{}'),
  '$.pricing',
  COALESCE(JSON_EXTRACT(`config_json`, '$.pricing'), JSON_OBJECT('platform_unit_cost', 1.666667, 'tenant_unit_price', 2.000000, 'billing_unit', 'second')),
  '$.provider',
  COALESCE(JSON_EXTRACT(`config_json`, '$.provider'), JSON_OBJECT('submit_path', '/api/v1/apps/image_human/submit', 'query_path', '/api/v1/apps/image_human/query', 'timeout', 60))
),
`update_time` = UNIX_TIMESTAMP();
