-- IKJF3N: fresh-install schema and core permission parity.
-- Idempotent repair; no application activation, user data, or custom menus are overwritten.
SET NAMES utf8mb4;

-- Source: app/apps/aigc_background_removal/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_background_removal_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_channel` varchar(80) NOT NULL DEFAULT '',
  `default_quality` varchar(80) NOT NULL DEFAULT '',
  `default_ratio` varchar(80) NOT NULL DEFAULT '',
  `prompt_template` text,
  `negative_prompt` text,
  `price_config` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片去背景配置';

-- Source: app/apps/aigc_background_removal/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_background_removal_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_uri` varchar(500) NOT NULL DEFAULT '',
  `mime_type` varchar(80) NOT NULL DEFAULT 'image/png',
  `has_alpha` tinyint NOT NULL DEFAULT 1,
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_image_result` (`tenant_id`,`image_result_id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片去背景结果';

-- Source: app/apps/aigc_background_removal/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_background_removal_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `source_image` varchar(500) NOT NULL DEFAULT '',
  `price_package_code` varchar(80) NOT NULL DEFAULT '',
  `price_package_name` varchar(100) NOT NULL DEFAULT '',
  `price_package_snapshot` text,
  `size_key` varchar(80) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `negative_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
  `quality_label` varchar(80) NOT NULL DEFAULT '',
  `ratio` varchar(80) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'running',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_image_task` (`image_task_id`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='图片去背景任务';

-- Source: app/apps/aigc_fashion_lookbook/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_fashion_lookbook_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_channel` varchar(80) NOT NULL DEFAULT '',
  `default_quality` varchar(80) NOT NULL DEFAULT '',
  `default_ratio` varchar(80) NOT NULL DEFAULT '',
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `max_clothes_images` int unsigned NOT NULL DEFAULT 6,
  `prompt_template` text,
  `negative_prompt` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='服饰套图配置';

-- Source: app/apps/aigc_fashion_lookbook/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_fashion_lookbook_model` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL DEFAULT '',
  `model_image` varchar(500) NOT NULL DEFAULT '',
  `cover_image` varchar(500) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='服饰套图模特预设';

-- Source: app/apps/aigc_fashion_lookbook/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_fashion_lookbook_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_image_result` (`tenant_id`,`image_result_id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='服饰套图结果';

-- Source: app/apps/aigc_fashion_lookbook/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_fashion_lookbook_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `clothes_images` text,
  `model_image` varchar(500) NOT NULL DEFAULT '',
  `model_snapshot` text,
  `size_key` varchar(80) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `user_prompt` varchar(1000) NOT NULL DEFAULT '',
  `negative_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
  `quality_label` varchar(80) NOT NULL DEFAULT '',
  `ratio` varchar(80) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'running',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_image_task` (`image_task_id`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='服饰套图任务';

-- Source: app/apps/aigc_geo/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_geo_article` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `keyword_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL DEFAULT '',
  `question` varchar(500) NOT NULL DEFAULT '',
  `content` mediumtext,
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `score` decimal(6,2) NOT NULL DEFAULT 0.00,
  `metadata_json` text,
  `published_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_tenant_status` (`tenant_id`,`status`), KEY `idx_tenant_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='GEO内容文章';

-- Source: app/apps/aigc_geo/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_geo_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `brand_name` varchar(120) NOT NULL DEFAULT '',
  `website` varchar(500) NOT NULL DEFAULT '',
  `industry` varchar(120) NOT NULL DEFAULT '',
  `company_intro` text,
  `profile_json` text,
  `settings_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='GEO品牌配置';

-- Source: app/apps/aigc_geo/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_geo_diagnosis` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `question` varchar(500) NOT NULL DEFAULT '',
  `platform` varchar(80) NOT NULL DEFAULT '',
  `rank_value` int unsigned NOT NULL DEFAULT 0,
  `mentioned` tinyint NOT NULL DEFAULT 0,
  `answer` text,
  `sources_json` text,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `error` varchar(500) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_tenant_platform` (`tenant_id`,`platform`), KEY `idx_tenant_status` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='GEO品牌检测记录';

-- Source: app/apps/aigc_geo/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_geo_keyword` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `question` varchar(500) NOT NULL DEFAULT '',
  `intent` varchar(80) NOT NULL DEFAULT '',
  `platform` varchar(80) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `source` varchar(30) NOT NULL DEFAULT 'manual',
  `platforms_json` text,
  `metadata_json` text,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_tenant_status` (`tenant_id`,`status`), KEY `idx_tenant_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='GEO问题关键词';

-- Source: app/apps/aigc_geo/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_geo_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `task_type` varchar(40) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `progress` tinyint unsigned NOT NULL DEFAULT 0,
  `payload_json` text,
  `result_json` text,
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_tenant_type` (`tenant_id`,`task_type`), KEY `idx_tenant_status` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='GEO任务记录';

-- Source: app/apps/aigc_model_wear/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_model_wear_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `default_channel` varchar(80) NOT NULL DEFAULT '',
  `default_quality` varchar(80) NOT NULL DEFAULT '',
  `default_ratio` varchar(80) NOT NULL DEFAULT '',
  `prompt_template` text,
  `negative_prompt` text,
  `price_config` text,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='模特穿戴配置';

-- Source: app/apps/aigc_model_wear/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_model_wear_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_result_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_image_result` (`tenant_id`,`image_result_id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='模特穿戴结果';

-- Source: app/apps/aigc_model_wear/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_model_wear_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_id` int unsigned NOT NULL DEFAULT 0,
  `image_task_ids` text,
  `model_image` varchar(500) NOT NULL DEFAULT '',
  `wear_image` varchar(500) NOT NULL DEFAULT '',
  `price_package_code` varchar(80) NOT NULL DEFAULT '',
  `price_package_name` varchar(100) NOT NULL DEFAULT '',
  `price_package_snapshot` text,
  `size_key` varchar(80) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `negative_prompt` text,
  `user_prompt` text,
  `channel` varchar(80) NOT NULL DEFAULT '',
  `quality` varchar(80) NOT NULL DEFAULT '',
  `quality_label` varchar(80) NOT NULL DEFAULT '',
  `ratio` varchar(80) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'running',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_image_task` (`image_task_id`),
  KEY `idx_status` (`tenant_id`,`status`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='模特穿戴任务';

-- Source: app/apps/aigc_music_cover/migrations/install.sql
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

-- Source: app/apps/aigc_music/migrations/install.sql
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

-- Source: app/apps/aigc_music/migrations/install.sql
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

-- Source: app/apps/aigc_music/migrations/install.sql
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

-- Source: app/apps/aigc_music/migrations/install.sql
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

-- Source: app/apps/aigc_music_cover/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_music_cover_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='音乐翻唱配置';

-- Source: app/apps/aigc_music_cover/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_music_cover_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `audio_uri` varchar(500) NOT NULL DEFAULT '',
  `audio_url` varchar(1000) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `mime_type` varchar(100) NOT NULL DEFAULT '',
  `file_size` bigint unsigned NOT NULL DEFAULT 0,
  `duration` decimal(10,2) NOT NULL DEFAULT 0.00,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_task_audio` (`tenant_id`,`task_id`,`audio_uri`), KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='音乐翻唱结果';

-- Source: app/apps/aigc_music_cover/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_music_cover_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `app_task_id` int unsigned NOT NULL DEFAULT 0,
  `consumption_id` int unsigned NOT NULL DEFAULT 0,
  `provider_task_id` varchar(160) NOT NULL DEFAULT '',
  `market_product_id` int unsigned NOT NULL DEFAULT 0,
  `market_sku_id` int unsigned NOT NULL DEFAULT 0,
  `pricing_snapshot` text,
  `source_asset_id` int unsigned NOT NULL DEFAULT 0,
  `source_uri` varchar(500) NOT NULL DEFAULT '',
  `source_url` varchar(1000) NOT NULL DEFAULT '',
  `reference_asset_id` int unsigned NOT NULL DEFAULT 0,
  `reference_uri` varchar(500) NOT NULL DEFAULT '',
  `reference_url` varchar(1000) NOT NULL DEFAULT '',
  `request_snapshot` text,
  `title` varchar(255) NOT NULL DEFAULT '音乐翻唱',
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `actual_tenant_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `actual_user_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_tenant_user` (`tenant_id`,`user_id`), KEY `idx_consumption` (`consumption_id`), KEY `idx_status` (`tenant_id`,`status`), KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='音乐翻唱任务';

-- Source: app/apps/aigc_music/migrations/install.sql
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

-- Source: app/apps/aigc_music/migrations/install.sql
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

-- Source: app/apps/aigc_music/migrations/install.sql
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

-- Source: app/apps/aigc_music/migrations/install.sql
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

-- Source: app/apps/aigc_music/migrations/install.sql
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

-- Source: app/apps/aigc_music/migrations/install.sql
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
  `app_task_id` int unsigned NOT NULL DEFAULT 0,
  `consumption_id` int unsigned NOT NULL DEFAULT 0,
  `market_product_id` int unsigned NOT NULL DEFAULT 0,
  `market_sku_id` int unsigned NOT NULL DEFAULT 0,
  `pricing_snapshot` text,
  `market_request_id` varchar(120) NOT NULL DEFAULT '',
  `market_retry_count` tinyint unsigned NOT NULL DEFAULT 0,
  `market_error_code` varchar(80) NOT NULL DEFAULT '',
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
  KEY `idx_market_app_task` (`app_task_id`),
  KEY `idx_market_consumption` (`consumption_id`),
  KEY `idx_market_request` (`market_request_id`),
  KEY `idx_idempotency` (`tenant_id`,`user_id`,`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI音乐任务';

-- Source: app/apps/aigc_music/migrations/install.sql
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

-- Source: app/apps/aigc_short_drama/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_quote` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL, `canvas_id` int unsigned NOT NULL,
  `node_id` varchar(64) NOT NULL, `request_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `quote_token` char(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `input_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_json` longtext NOT NULL, `quote_json` longtext NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'quoted', `expires_at` int unsigned NOT NULL,
  `confirmed_at` int unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL, `update_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_token` (`quote_token`),
  KEY `idx_scope` (`tenant_id`,`user_id`,`canvas_id`,`node_id`,`request_key`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短剧画布视频报价确认';

-- Source: app/apps/aigc_short_drama/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_run` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `canvas_id` int unsigned NOT NULL DEFAULT 0,
  `node_id` varchar(64) NOT NULL DEFAULT '',
  `node_type` varchar(16) NOT NULL DEFAULT '',
  `provider_task_id` varchar(100) NOT NULL DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'running',
  `progress` tinyint unsigned NOT NULL DEFAULT 0,
  `request_json` longtext,
  `result_json` longtext,
  `error` varchar(500) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_canvas_node` (`tenant_id`,`user_id`,`canvas_id`,`node_id`,`delete_time`),
  KEY `idx_status` (`tenant_id`,`status`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧画布生成任务';

-- Source: public/upgrade/20260915_short_drama_canvas_v2.sql
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_v2_action` (`id` bigint unsigned NOT NULL AUTO_INCREMENT,`tenant_id` int unsigned NOT NULL,`user_id` int unsigned NOT NULL,`workspace_id` bigint unsigned NOT NULL,`node_key` varchar(64) NOT NULL DEFAULT '',`request_key` varchar(64) NOT NULL,`kind` varchar(32) NOT NULL,`status` varchar(24) NOT NULL DEFAULT 'proposed',`version` int unsigned NOT NULL DEFAULT 1,`request_json` mediumtext NOT NULL,`result_json` mediumtext NOT NULL,`generation_task_id` varchar(64) NOT NULL DEFAULT '',`provider_task_id` varchar(128) NOT NULL DEFAULT '',`error_code` varchar(64) NOT NULL DEFAULT '',`error_message` varchar(255) NOT NULL DEFAULT '',`create_time` int unsigned NOT NULL DEFAULT 0,`update_time` int unsigned NOT NULL DEFAULT 0,PRIMARY KEY (`id`),UNIQUE KEY `uk_action_request` (`tenant_id`,`user_id`,`workspace_id`,`request_key`),KEY `idx_workspace_node` (`tenant_id`,`user_id`,`workspace_id`,`node_key`,`update_time`),KEY `idx_generation_task` (`generation_task_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Source: public/upgrade/20260915_short_drama_canvas_v2.sql
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_v2_edge` (`id` bigint unsigned NOT NULL AUTO_INCREMENT,`tenant_id` int unsigned NOT NULL,`user_id` int unsigned NOT NULL,`workspace_id` bigint unsigned NOT NULL,`edge_key` varchar(64) NOT NULL,`from_node_key` varchar(64) NOT NULL,`to_node_key` varchar(64) NOT NULL,`edge_type` varchar(24) NOT NULL DEFAULT 'default',`version` int unsigned NOT NULL DEFAULT 1,`delete_time` int unsigned NOT NULL DEFAULT 0,`create_time` int unsigned NOT NULL DEFAULT 0,`update_time` int unsigned NOT NULL DEFAULT 0,PRIMARY KEY (`id`),UNIQUE KEY `uk_workspace_edge` (`tenant_id`,`user_id`,`workspace_id`,`edge_key`),KEY `idx_workspace` (`tenant_id`,`user_id`,`workspace_id`,`delete_time`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Source: public/upgrade/20260915_short_drama_canvas_v2.sql
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_v2_message` (`id` bigint unsigned NOT NULL AUTO_INCREMENT,`tenant_id` int unsigned NOT NULL,`user_id` int unsigned NOT NULL,`workspace_id` bigint unsigned NOT NULL,`request_key` varchar(64) NOT NULL,`role` varchar(16) NOT NULL,`content` mediumtext NOT NULL,`create_time` int unsigned NOT NULL DEFAULT 0,`update_time` int unsigned NOT NULL DEFAULT 0,PRIMARY KEY (`id`),UNIQUE KEY `uk_message_request` (`tenant_id`,`user_id`,`workspace_id`,`request_key`),KEY `idx_history` (`tenant_id`,`user_id`,`workspace_id`,`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Source: public/upgrade/20260915_short_drama_canvas_v2.sql
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_v2_node` (`id` bigint unsigned NOT NULL AUTO_INCREMENT,`tenant_id` int unsigned NOT NULL,`user_id` int unsigned NOT NULL,`workspace_id` bigint unsigned NOT NULL,`node_key` varchar(64) NOT NULL,`node_type` varchar(24) NOT NULL,`business_kind` varchar(32) NOT NULL DEFAULT '',`title` varchar(120) NOT NULL DEFAULT '',`content_json` mediumtext NOT NULL,`position_x` decimal(12,2) NOT NULL DEFAULT 0,`position_y` decimal(12,2) NOT NULL DEFAULT 0,`width` int unsigned NOT NULL DEFAULT 320,`height` int unsigned NOT NULL DEFAULT 220,`z_index` int NOT NULL DEFAULT 0,`version` int unsigned NOT NULL DEFAULT 1,`delete_time` int unsigned NOT NULL DEFAULT 0,`create_time` int unsigned NOT NULL DEFAULT 0,`update_time` int unsigned NOT NULL DEFAULT 0,PRIMARY KEY (`id`),UNIQUE KEY `uk_workspace_node` (`tenant_id`,`user_id`,`workspace_id`,`node_key`),KEY `idx_workspace` (`tenant_id`,`user_id`,`workspace_id`,`delete_time`,`z_index`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Source: public/upgrade/20260915_short_drama_canvas_v2.sql
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_v2_view` (`id` bigint unsigned NOT NULL AUTO_INCREMENT,`tenant_id` int unsigned NOT NULL,`user_id` int unsigned NOT NULL,`workspace_id` bigint unsigned NOT NULL,`view_key` varchar(64) NOT NULL DEFAULT 'global',`viewport_json` text NOT NULL,`preferences_json` text NOT NULL,`version` int unsigned NOT NULL DEFAULT 1,`create_time` int unsigned NOT NULL DEFAULT 0,`update_time` int unsigned NOT NULL DEFAULT 0,PRIMARY KEY (`id`),UNIQUE KEY `uk_workspace_view` (`tenant_id`,`user_id`,`workspace_id`,`view_key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Source: public/upgrade/20260915_short_drama_canvas_v2.sql
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_v2_workspace` (`id` bigint unsigned NOT NULL AUTO_INCREMENT,`tenant_id` int unsigned NOT NULL,`user_id` int unsigned NOT NULL,`project_id` bigint unsigned DEFAULT NULL,`request_key` varchar(64) NOT NULL,`title` varchar(120) NOT NULL DEFAULT '',`status` varchar(24) NOT NULL DEFAULT 'creating',`error_message` varchar(255) NOT NULL DEFAULT '',`version` int unsigned NOT NULL DEFAULT 1,`skill_snapshot_json` mediumtext NOT NULL,`delete_time` int unsigned NOT NULL DEFAULT 0,`create_time` int unsigned NOT NULL DEFAULT 0,`update_time` int unsigned NOT NULL DEFAULT 0,PRIMARY KEY (`id`),UNIQUE KEY `uk_owner_request` (`tenant_id`,`user_id`,`request_key`),UNIQUE KEY `uk_owner_project` (`tenant_id`,`user_id`,`project_id`),KEY `idx_owner` (`tenant_id`,`user_id`,`delete_time`,`update_time`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Source: app/apps/aigc_short_drama/migrations/upgrade_20260909_episode_queue.sql
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_episode_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `project_id` int unsigned NOT NULL,
  `episode_number` int unsigned NOT NULL,
  `production_project_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` varchar(80) NOT NULL DEFAULT '',
  `outline_task_id` varchar(80) NOT NULL DEFAULT '',
  `title` varchar(120) NOT NULL DEFAULT '',
  `outline_json` longtext,
  `series_json` longtext,
  `result_json` longtext,
  `continuity_json` longtext,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `progress` int unsigned NOT NULL DEFAULT 0,
  `error` text,
  `provider` varchar(80) NOT NULL DEFAULT '',
  `provider_request_id` varchar(255) NOT NULL DEFAULT '',
  `provider_task_id` varchar(255) NOT NULL DEFAULT '',
  `retry_count` int unsigned NOT NULL DEFAULT 0,
  `completed_once` tinyint unsigned NOT NULL DEFAULT 0,
  `cancel_requested` tinyint unsigned NOT NULL DEFAULT 0,
  `started_at` int unsigned NOT NULL DEFAULT 0,
  `finished_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_project_episode` (`tenant_id`,`project_id`,`episode_number`,`delete_time`),
  KEY `idx_production` (`production_project_id`,`delete_time`),
  KEY `idx_queue` (`status`,`project_id`,`episode_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Source: app/apps/aigc_short_drama/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_skill` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `creator_admin_id` int unsigned NOT NULL DEFAULT 0, `skill_key` varchar(80) NOT NULL DEFAULT '',
  `name` varchar(120) NOT NULL DEFAULT '', `description` varchar(600) NOT NULL DEFAULT '',
  `invocation_rule` varchar(200) NOT NULL DEFAULT '', `category_ids_json` text,
  `cover_asset_id` int unsigned NOT NULL DEFAULT 0, `cover_url` varchar(500) NOT NULL DEFAULT '',
  `cover_type` varchar(12) NOT NULL DEFAULT 'image', `definition_json` longtext,
  `model_policy_json` text, `execution_policy_json` text, `status` tinyint unsigned NOT NULL DEFAULT 1,
  `home_recommended` tinyint unsigned NOT NULL DEFAULT 0,
  `release_status` varchar(24) NOT NULL DEFAULT 'draft', `version` int unsigned NOT NULL DEFAULT 1,
  `published_version` int unsigned NOT NULL DEFAULT 0, `published_at` int unsigned NOT NULL DEFAULT 0,
  `sort` int NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0, `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_tenant_key_deleted` (`tenant_id`,`skill_key`,`delete_time`),
  KEY `idx_tenant_visible` (`tenant_id`,`status`,`release_status`,`delete_time`),
  KEY `idx_tenant_home_recommended` (`tenant_id`,`home_recommended`,`status`,`release_status`,`delete_time`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧Skill主表';

-- Source: app/apps/aigc_short_drama/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_skill_category` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(60) NOT NULL DEFAULT '', `icon` varchar(120) NOT NULL DEFAULT '', `sort` int NOT NULL DEFAULT 0,
  `status` tinyint unsigned NOT NULL DEFAULT 1, `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0, `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_tenant_status_sort` (`tenant_id`,`status`,`sort`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧Skill分类';

-- Source: app/apps/aigc_short_drama/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_skill_usage` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0, `project_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` varchar(64) NOT NULL DEFAULT '', `skill_id` int unsigned NOT NULL DEFAULT 0,
  `skill_version` int unsigned NOT NULL DEFAULT 0, `skill_source` varchar(16) NOT NULL DEFAULT 'manual',
  `skill_name` varchar(120) NOT NULL DEFAULT '', `status` varchar(24) NOT NULL DEFAULT 'submitted',
  `snapshot_json` longtext, `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0, `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_tenant_user_time` (`tenant_id`,`user_id`,`create_time`,`delete_time`),
  UNIQUE KEY `idx_task` (`tenant_id`,`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧Skill使用记录';

-- Source: app/apps/aigc_short_drama/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_skill_version` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `skill_id` int unsigned NOT NULL DEFAULT 0, `version` int unsigned NOT NULL DEFAULT 1,
  `release_status` varchar(24) NOT NULL DEFAULT 'draft', `snapshot_json` longtext,
  `created_by` int unsigned NOT NULL DEFAULT 0,
  `published_by` int unsigned NOT NULL DEFAULT 0, `published_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_skill_version` (`tenant_id`,`skill_id`,`version`),
  KEY `idx_tenant_skill_release` (`tenant_id`,`skill_id`,`release_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧Skill版本';

-- Source: app/apps/aigc_short_drama/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_template` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `title` varchar(120) NOT NULL DEFAULT '',
  `description` varchar(1200) NOT NULL DEFAULT '',
  `category` varchar(60) NOT NULL DEFAULT '',
  `cover_asset_id` int unsigned NOT NULL DEFAULT 0,
  `cover_url` varchar(500) NOT NULL DEFAULT '',
  `cover_type` varchar(12) NOT NULL DEFAULT 'image',
  `workflow_nodes_json` longtext,
  `workflow_edges_json` longtext,
  `input_slots_json` longtext,
  `show_on_home` tinyint unsigned NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_home` (`tenant_id`,`status`,`show_on_home`,`delete_time`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧首页工作流模板';

-- Source: app/apps/aigc_short_drama/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_user_skill` (
  `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0, `skill_id` int unsigned NOT NULL DEFAULT 0,
  `enabled` tinyint unsigned NOT NULL DEFAULT 0, `last_enabled_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_user_skill` (`tenant_id`,`user_id`,`skill_id`),
  KEY `idx_tenant_user_enabled` (`tenant_id`,`user_id`,`enabled`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI短剧用户Skill设置';

-- Source: app/apps/aigc_watermark_removal/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_watermark_removal_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `config_json` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短视频去水印配置';

-- Source: app/apps/aigc_watermark_removal/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_watermark_removal_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `video_uri` varchar(500) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'tenant',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_task_video` (`tenant_id`,`task_id`,`video_uri`), KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短视频去水印结果';

-- Source: app/apps/aigc_watermark_removal/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_aigc_watermark_removal_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `app_task_id` int unsigned NOT NULL DEFAULT 0,
  `consumption_id` int unsigned NOT NULL DEFAULT 0,
  `provider_task_id` varchar(160) NOT NULL DEFAULT '',
  `market_product_id` int unsigned NOT NULL DEFAULT 0,
  `market_sku_id` int unsigned NOT NULL DEFAULT 0,
  `pricing_snapshot` text,
  `source_url` varchar(1000) NOT NULL DEFAULT '',
  `request_snapshot` text,
  `tenant_cost_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(12,2) NOT NULL DEFAULT 0.00,
  `actual_tenant_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `actual_user_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_tenant_user` (`tenant_id`,`user_id`), KEY `idx_consumption` (`consumption_id`), KEY `idx_status` (`tenant_id`,`status`), KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='短视频去水印任务';

-- Source: app/apps/image_human/migrations/install.sql
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

-- Source: app/apps/image_human/migrations/install.sql
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
  UNIQUE KEY `uk_tenant_task` (`tenant_id`,`task_id`),
  KEY `idx_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全驱动数字人计费记录';

-- Source: app/apps/image_human/migrations/install.sql
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

-- Source: app/apps/image_human/migrations/install.sql
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

-- Source: app/apps/image_human/migrations/install.sql
CREATE TABLE IF NOT EXISTS `la_image_human_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `avatar_id` int unsigned NOT NULL DEFAULT 0,
  `voice_id` int unsigned NOT NULL DEFAULT 0,
  `title` varchar(120) NOT NULL DEFAULT '',
  `image_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '人物图片',
  `audio_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '驱动音频',
  `script_text` text COMMENT '文案内容',
  `prompt` text COMMENT '提示词',
  `mode` varchar(30) NOT NULL DEFAULT 'fast',
  `duration` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tenant_cost_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `user_charge_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `provider` varchar(50) NOT NULL DEFAULT 'xhadmin',
  `model` varchar(100) NOT NULL DEFAULT 'image_human',
  `provider_task_id` varchar(120) NOT NULL DEFAULT '',
  `app_task_id` int unsigned NOT NULL DEFAULT 0,
  `consumption_id` int unsigned NOT NULL DEFAULT 0,
  `market_product_id` int unsigned NOT NULL DEFAULT 0,
  `market_sku_id` int unsigned NOT NULL DEFAULT 0,
  `pricing_snapshot` text,
  `idempotency_key` varchar(100) DEFAULT NULL,
  `market_request_id` varchar(120) NOT NULL DEFAULT '',
  `market_retry_count` tinyint unsigned NOT NULL DEFAULT 0,
  `billing_status` varchar(30) NOT NULL DEFAULT 'none',
  `market_error_code` varchar(80) NOT NULL DEFAULT '',
  `provider_stage` varchar(30) NOT NULL DEFAULT '' COMMENT '供应商阶段',
  `tts_task_id` varchar(120) NOT NULL DEFAULT '' COMMENT '音频合成任务ID',
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
  KEY `idx_market_app_task` (`app_task_id`),
  KEY `idx_market_consumption` (`consumption_id`),
  KEY `idx_market_request` (`market_request_id`),
  KEY `idx_market_idempotency` (`tenant_id`,`user_id`,`idempotency_key`),
  KEY `idx_status` (`tenant_id`,`status`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全驱动数字人生成任务';

-- Source: app/apps/smart_clip/migrations/install.sql
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

-- Source: app/apps/smart_clip/migrations/install.sql
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

-- Source: app/apps/smart_clip/migrations/install.sql
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

-- Source: app/apps/smart_clip/migrations/install.sql
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

-- Source: app/apps/smart_clip/migrations/install.sql
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

-- Source: app/apps/smart_clip/migrations/install.sql
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

-- Source: app/apps/smart_clip/migrations/install.sql
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
  `app_task_id` int unsigned NOT NULL DEFAULT 0,
  `consumption_id` int unsigned NOT NULL DEFAULT 0,
  `market_product_id` int unsigned NOT NULL DEFAULT 0,
  `market_sku_id` int unsigned NOT NULL DEFAULT 0,
  `pricing_snapshot` text,
  `idempotency_key` varchar(100) DEFAULT NULL,
  `market_request_id` varchar(120) NOT NULL DEFAULT '',
  `market_retry_count` tinyint unsigned NOT NULL DEFAULT 0,
  `billing_status` varchar(30) NOT NULL DEFAULT 'none',
  `market_error_code` varchar(80) NOT NULL DEFAULT '',
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
  ,KEY `idx_market_app_task` (`app_task_id`)
  ,KEY `idx_market_consumption` (`consumption_id`)
  ,KEY `idx_market_request` (`market_request_id`)
  ,KEY `idx_market_idempotency` (`tenant_id`,`user_id`,`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI视频剪辑任务';

SET @ikjf3n_sql = IF(EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_app_order') AND NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_app_order' AND COLUMN_NAME='remark'), 'ALTER TABLE `la_tenant_app_order` ADD COLUMN `remark` varchar(255) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE ikjf3n_stmt FROM @ikjf3n_sql;
EXECUTE ikjf3n_stmt;
DEALLOCATE PREPARE ikjf3n_stmt;

SET @ikjf3n_sql = IF(EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_app_order') AND NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_app_order' AND COLUMN_NAME='source_sn'), 'ALTER TABLE `la_tenant_app_order` ADD COLUMN `source_sn` varchar(64) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE ikjf3n_stmt FROM @ikjf3n_sql;
EXECUTE ikjf3n_stmt;
DEALLOCATE PREPARE ikjf3n_stmt;

SET @ikjf3n_sql = IF(EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_llm_model') AND NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_llm_model' AND COLUMN_NAME='platform_input_unit_price'), 'ALTER TABLE `la_aigc_llm_model` ADD COLUMN `platform_input_unit_price` decimal(12,4) NOT NULL DEFAULT 0.0000', 'SELECT 1');
PREPARE ikjf3n_stmt FROM @ikjf3n_sql;
EXECUTE ikjf3n_stmt;
DEALLOCATE PREPARE ikjf3n_stmt;

SET @ikjf3n_sql = IF(EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_llm_model') AND NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_llm_model' AND COLUMN_NAME='platform_output_unit_price'), 'ALTER TABLE `la_aigc_llm_model` ADD COLUMN `platform_output_unit_price` decimal(12,4) NOT NULL DEFAULT 0.0000', 'SELECT 1');
PREPARE ikjf3n_stmt FROM @ikjf3n_sql;
EXECUTE ikjf3n_stmt;
DEALLOCATE PREPARE ikjf3n_stmt;

SET @ikjf3n_sql = IF(EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_product_promo_video_config') AND NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_product_promo_video_config' AND COLUMN_NAME='market_enabled'), 'ALTER TABLE `la_aigc_product_promo_video_config` ADD COLUMN `market_enabled` tinyint NOT NULL DEFAULT 1', 'SELECT 1');
PREPARE ikjf3n_stmt FROM @ikjf3n_sql;
EXECUTE ikjf3n_stmt;
DEALLOCATE PREPARE ikjf3n_stmt;

SET @ikjf3n_sql = IF(EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_system_menu') AND NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_system_menu' AND COLUMN_NAME='delete_time'), 'ALTER TABLE `la_tenant_system_menu` ADD COLUMN `delete_time` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE ikjf3n_stmt FROM @ikjf3n_sql;
EXECUTE ikjf3n_stmt;
DEALLOCATE PREPARE ikjf3n_stmt;

SET @ikjf3n_sql = IF(EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_short_drama_subject') AND NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_short_drama_subject' AND COLUMN_NAME='three_view_image'), 'ALTER TABLE `la_aigc_short_drama_subject` ADD COLUMN `three_view_image` varchar(500) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE ikjf3n_stmt FROM @ikjf3n_sql;
EXECUTE ikjf3n_stmt;
DEALLOCATE PREPARE ikjf3n_stmt;

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT p.id,'A','详情','',0,'ai_consumption/detail','','','','',0,0,0,'','core','core_ai_consumption_platform_detail',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_system_menu` p WHERE p.source_menu_key='core_ai_consumption_platform' AND p.source<>'tenant' AND NOT EXISTS (SELECT 1 FROM `la_system_menu` n WHERE n.source_menu_key='core_ai_consumption_platform_detail');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT p.tenant_id,p.id,'A','导出模板','',0,'decorate.template/export','','','','',0,1,0,'','core','core_tenant_decorate_template_export',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.perms='decorate.template/lists' AND p.source<>'tenant' AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` n WHERE n.tenant_id=p.tenant_id AND n.perms='decorate.template/export');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT p.tenant_id,p.id,'A','导入模板','',0,'decorate.template/import','','','','',0,1,0,'','core','core_tenant_decorate_template_import',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.perms='decorate.template/lists' AND p.source<>'tenant' AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` n WHERE n.tenant_id=p.tenant_id AND n.perms='decorate.template/import');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT p.tenant_id,p.id,'A','数据源','',0,'decorate.data/sources','','','','',0,1,0,'','core','core_tenant_decorate_data_sources',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.perms='decorate.template/lists' AND p.source<>'tenant' AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` n WHERE n.tenant_id=p.tenant_id AND n.perms='decorate.data/sources');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT p.tenant_id,p.id,'A','详情','',0,'ai_consumption/detail','','','','',0,0,0,'','core','core_ai_consumption_tenant_detail',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.perms='ai_consumption/lists' AND p.source<>'tenant' AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` n WHERE n.tenant_id=p.tenant_id AND n.perms='ai_consumption/detail');
