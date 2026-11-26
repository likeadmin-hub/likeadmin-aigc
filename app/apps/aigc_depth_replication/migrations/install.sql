CREATE TABLE IF NOT EXISTS `la_aigc_depth_replication_config` (
 `tenant_id` int unsigned NOT NULL, `max_seconds` tinyint unsigned NOT NULL DEFAULT 15,
 PRIMARY KEY (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='深度复刻配置';

CREATE TABLE IF NOT EXISTS `la_aigc_depth_replication_job` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `kind` varchar(12) NOT NULL, `status` varchar(16) NOT NULL DEFAULT 'queued', `analysis_id` bigint unsigned NOT NULL DEFAULT 0,
 `request_id` varchar(80) NOT NULL, `input_hash` char(64) NOT NULL, `source_json` text NOT NULL, `settings_json` text NOT NULL,
 `result_json` mediumtext NOT NULL, `error` varchar(255) NOT NULL DEFAULT '', `create_time` int unsigned NOT NULL, `update_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_request` (`tenant_id`,`user_id`,`request_id`),
 KEY `idx_queue` (`status`,`id`), KEY `idx_owner` (`tenant_id`,`user_id`,`id`), KEY `idx_analysis` (`analysis_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='深度分析及白模导出任务';

