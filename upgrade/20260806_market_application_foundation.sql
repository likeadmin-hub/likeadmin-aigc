CREATE TABLE IF NOT EXISTS `la_ai_market_app_gate` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `app_code` varchar(80) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 0 COMMENT 'market entry defaults to off',
  `rollout_percent` tinyint unsigned NOT NULL DEFAULT 0 COMMENT 'stable user rollout percentage',
  `user_whitelist` text COMMENT 'JSON user whitelist',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_app` (`tenant_id`,`app_code`),
  KEY `idx_app_status` (`app_code`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI market application tenant gate';
