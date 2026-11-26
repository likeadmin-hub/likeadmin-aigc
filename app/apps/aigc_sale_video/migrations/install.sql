CREATE TABLE IF NOT EXISTS `la_aigc_sale_video_config` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
 `body_json` text NOT NULL, `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='带货视频创作配置';
CREATE TABLE IF NOT EXISTS `la_aigc_sale_video_project` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `title` varchar(100) NOT NULL DEFAULT '', `revision` int unsigned NOT NULL DEFAULT 1, `body_json` longtext NOT NULL,
 `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0, `delete_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), KEY `idx_owner` (`tenant_id`,`user_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='带货视频创作草稿';
CREATE TABLE IF NOT EXISTS `la_aigc_sale_video_job` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `project_id` int unsigned NOT NULL, `project_revision` int unsigned NOT NULL, `action` varchar(30) NOT NULL,
 `idempotency_key` varchar(100) NOT NULL, `input_hash` char(64) NOT NULL, `status` varchar(30) NOT NULL DEFAULT 'queued',
 `request_json` longtext NOT NULL, `receipt_json` longtext NOT NULL, `result_json` longtext NOT NULL,
 `app_task_id` int unsigned NOT NULL DEFAULT 0, `consumption_id` int unsigned NOT NULL DEFAULT 0,
 `billing_status` varchar(30) NOT NULL DEFAULT 'none', `user_charge_points` decimal(18,6) NOT NULL DEFAULT 0,
 `error` varchar(1000) NOT NULL DEFAULT '', `lease_until` int unsigned NOT NULL DEFAULT 0, `lease_token` varchar(40) NOT NULL DEFAULT '',
 `next_run_at` int unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0, `delete_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_idempotency` (`tenant_id`,`user_id`,`idempotency_key`),
 KEY `idx_pending` (`status`,`next_run_at`,`lease_until`), KEY `idx_owner` (`tenant_id`,`user_id`,`delete_time`), KEY `idx_project` (`tenant_id`,`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='带货视频模型任务';
CREATE TABLE IF NOT EXISTS `la_aigc_sale_video_quote` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL, `project_id` int unsigned NOT NULL,
 `token` char(48) NOT NULL, `input_hash` char(64) NOT NULL, `request_json` longtext NOT NULL, `expires_at` int unsigned NOT NULL,
 `consumed_job_id` int unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_token` (`token`), KEY `idx_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='带货视频确认报价';
CREATE TABLE IF NOT EXISTS `la_aigc_sale_video_job_stage` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `root_job_id` int unsigned NOT NULL, `stage` varchar(30) NOT NULL, `child_job_id` int unsigned NOT NULL,
 `create_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`), UNIQUE KEY `uk_stage` (`root_job_id`,`stage`), UNIQUE KEY `uk_child` (`child_job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='带货视频智能分析阶段';
CREATE TABLE IF NOT EXISTS `la_aigc_sale_video_product` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `name` varchar(100) NOT NULL, `body_json` longtext NOT NULL, `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
 `delete_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`), KEY `idx_owner` (`tenant_id`,`user_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='带货视频商品资料库';
CREATE TABLE IF NOT EXISTS `la_aigc_sale_video_builtin` (
 `builtin_key` varchar(64) NOT NULL, `tenant_id` int unsigned NOT NULL DEFAULT 0,
 `case_id` int unsigned NULL DEFAULT NULL, `source_case_id` int unsigned NOT NULL DEFAULT 0, `source_tenant_id` int unsigned NOT NULL DEFAULT 0,
 `version` int unsigned NOT NULL DEFAULT 0, `source_hash` char(64) NOT NULL DEFAULT '',
 `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`builtin_key`), UNIQUE KEY `uk_case` (`case_id`), KEY `idx_source` (`source_case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='带货视频平台内置模板映射';
