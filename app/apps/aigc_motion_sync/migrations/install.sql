CREATE TABLE IF NOT EXISTS `la_aigc_motion_sync_config` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
 `body_json` text NOT NULL, `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='动作同步配置';
CREATE TABLE IF NOT EXISTS `la_aigc_motion_sync_project` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `revision` int unsigned NOT NULL DEFAULT 1, `body_json` longtext NOT NULL,
 `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0, `delete_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), KEY `idx_owner` (`tenant_id`,`user_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='动作同步草稿';
CREATE TABLE IF NOT EXISTS `la_aigc_motion_sync_quote` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `project_id` int unsigned NOT NULL, `revision` int unsigned NOT NULL, `token` char(48) NOT NULL,
 `quote_json` longtext NOT NULL, `expires_at` int unsigned NOT NULL, `consumed_task_id` int unsigned NOT NULL DEFAULT 0,
 `create_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`), UNIQUE KEY `uk_token` (`token`), KEY `idx_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='动作同步报价';
CREATE TABLE IF NOT EXISTS `la_aigc_motion_sync_task` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `project_id` int unsigned NOT NULL, `project_revision` int unsigned NOT NULL,
 `idempotency_key` varchar(100) NOT NULL, `status` varchar(30) NOT NULL DEFAULT 'queued',
 `prompt` text NOT NULL, `request_json` longtext NOT NULL,
 `app_task_id` int unsigned NOT NULL DEFAULT 0, `consumption_id` int unsigned NOT NULL DEFAULT 0,
 `billing_status` varchar(30) NOT NULL DEFAULT 'none', `user_charge_points` decimal(18,6) NOT NULL DEFAULT 0,
 `submit_started_at` int unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL DEFAULT 0,
 `update_time` int unsigned NOT NULL DEFAULT 0, `delete_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_idempotency` (`tenant_id`,`user_id`,`idempotency_key`),
 UNIQUE KEY `uk_consumption` (`consumption_id`,`id`), KEY `idx_owner` (`tenant_id`,`user_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='动作同步最终作品任务';
CREATE TABLE IF NOT EXISTS `la_aigc_motion_sync_result` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `task_id` int unsigned NOT NULL, `asset_id` int unsigned NOT NULL,
 `video_uri` varchar(2048) NOT NULL DEFAULT '', `cover_uri` varchar(2048) NOT NULL DEFAULT '',
 `duration` decimal(10,3) NOT NULL DEFAULT 0, `width` int unsigned NOT NULL DEFAULT 0, `height` int unsigned NOT NULL DEFAULT 0,
 `storage_scope` varchar(20) NOT NULL DEFAULT '', `storage_engine` varchar(20) NOT NULL DEFAULT '', `storage_domain` varchar(512) NOT NULL DEFAULT '',
 `create_time` int unsigned NOT NULL DEFAULT 0, `delete_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_asset` (`task_id`,`asset_id`), KEY `idx_owner` (`tenant_id`,`user_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='动作同步已存储作品';

-- BEGIN portable inspiration mapping
CREATE TABLE IF NOT EXISTS `la_app_builtin_inspiration` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `app_code` varchar(64) NOT NULL,
 `builtin_key` varchar(64) NOT NULL, `tenant_id` int unsigned NOT NULL DEFAULT 0,
 `case_id` int unsigned DEFAULT NULL, `source_case_id` int unsigned DEFAULT NULL,
 `source_tenant_id` int unsigned NOT NULL DEFAULT 0, `source_job_id` int unsigned DEFAULT NULL,
 `version` int unsigned NOT NULL DEFAULT 0, `source_hash` varchar(64) NOT NULL DEFAULT '',
 `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_builtin` (`app_code`,`builtin_key`),
 UNIQUE KEY `uk_case` (`case_id`), UNIQUE KEY `uk_source` (`app_code`,`source_case_id`),
 UNIQUE KEY `uk_job` (`app_code`,`source_tenant_id`,`source_job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台跨站点内置灵感模板映射';
-- END portable inspiration mapping
