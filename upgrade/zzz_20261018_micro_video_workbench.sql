-- Additive workbench v1. Immutable versions; no ALTER of legacy task/direction.
CREATE TABLE IF NOT EXISTS `la_aigc_micro_video_workbench_record` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL DEFAULT 0,
 `kind` varchar(20) NOT NULL, `title` varchar(120) NOT NULL, `visibility` varchar(20) NOT NULL DEFAULT 'private',
 `status` tinyint unsigned NOT NULL DEFAULT 1, `revision` int unsigned NOT NULL DEFAULT 1, `body_json` longtext NOT NULL,
 `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0, `delete_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), KEY `idx_owner` (`tenant_id`,`user_id`,`kind`,`delete_time`), KEY `idx_shared` (`tenant_id`,`kind`,`visibility`,`status`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_aigc_micro_video_workbench_version` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `record_id` int unsigned NOT NULL, `revision` int unsigned NOT NULL, `title` varchar(120) NOT NULL, `body_json` longtext NOT NULL, `create_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_version` (`record_id`,`revision`), KEY `idx_owner` (`tenant_id`,`user_id`,`record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_aigc_micro_video_workbench_quote` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `project_id` int unsigned NOT NULL, `project_revision` int unsigned NOT NULL, `token` varchar(64) NOT NULL,
 `action` varchar(30) NOT NULL, `input_hash` char(64) NOT NULL, `quote_json` longtext NOT NULL, `request_json` longtext NOT NULL,
 `expires_at` int unsigned NOT NULL, `create_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_token` (`token`), KEY `idx_owner` (`tenant_id`,`user_id`,`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_aigc_micro_video_workbench_job` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `project_id` int unsigned NOT NULL, `project_revision` int unsigned NOT NULL, `action` varchar(30) NOT NULL,
 `idempotency_key` varchar(90) NOT NULL, `input_hash` char(64) NOT NULL, `status` varchar(20) NOT NULL DEFAULT 'queued',
 `request_json` longtext NOT NULL, `receipt_json` longtext NOT NULL, `result_json` longtext NOT NULL,
 `consumption_id` int unsigned NOT NULL DEFAULT 0, `app_task_id` int unsigned NOT NULL DEFAULT 0, `user_charge_points` decimal(18,8) NOT NULL DEFAULT 0,
 `error` varchar(1000) NOT NULL DEFAULT '', `lease_token` varchar(64) NOT NULL DEFAULT '', `lease_until` int unsigned NOT NULL DEFAULT 0,
 `next_run_at` int unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL, `update_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_request` (`tenant_id`,`user_id`,`idempotency_key`), KEY `idx_runnable` (`status`,`next_run_at`,`lease_until`), KEY `idx_project` (`tenant_id`,`user_id`,`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_aigc_micro_video_workbench_execution` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `project_id` int unsigned NOT NULL, `project_revision` int unsigned NOT NULL, `idempotency_key` varchar(90) NOT NULL,
 `task_id` int unsigned NOT NULL, `snapshot_json` longtext NOT NULL, `create_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_request` (`tenant_id`,`user_id`,`idempotency_key`), KEY `idx_project` (`tenant_id`,`user_id`,`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_aigc_micro_video_workbench_asset` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `source_kind` varchar(20) NOT NULL, `source_id` int unsigned NOT NULL, `source_hash` char(64) NOT NULL, `image_json` longtext NOT NULL,
 `create_time` int unsigned NOT NULL, `update_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_source` (`tenant_id`,`user_id`,`source_kind`,`source_id`,`source_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
