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
