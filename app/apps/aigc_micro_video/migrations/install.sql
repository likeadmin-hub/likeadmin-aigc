CREATE TABLE IF NOT EXISTS `la_aigc_micro_video_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `config_json` longtext NOT NULL,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`), UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微视频config';

CREATE TABLE IF NOT EXISTS `la_aigc_micro_video_direction` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `code` varchar(80) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(500) NOT NULL DEFAULT '',
  `prompt` text NOT NULL,
  `continuity_prompt` text NOT NULL,
  `cover_image` varchar(1000) NOT NULL DEFAULT '',
  `example_video` varchar(1000) NOT NULL DEFAULT '',
  `is_builtin` tinyint NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`), UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微视频direction';

CREATE TABLE IF NOT EXISTS `la_aigc_micro_video_task` (
  `generation_mode` varchar(20) NOT NULL DEFAULT 'reference',
  `frame_status` varchar(30) NOT NULL DEFAULT 'off',
  `frame_model_id` varchar(100) NOT NULL DEFAULT '',
  `frame_quality` varchar(40) NOT NULL DEFAULT '',
  `frames_revision` int unsigned NOT NULL DEFAULT 0,
  `frame_quote_json` longtext NULL,
  `frame_quote_token` varchar(64) NOT NULL DEFAULT '',
  `frame_quote_expires` int unsigned NOT NULL DEFAULT 0,
  `frame_batch_id` int unsigned NOT NULL DEFAULT 0,

  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `idempotency_key` varchar(100) NOT NULL,
  `title` varchar(200) NOT NULL DEFAULT '',
  `status` varchar(40) NOT NULL DEFAULT 'planning',
  `params_json` longtext NOT NULL,
  `config_snapshot` longtext NOT NULL,
  `model_snapshot` longtext NOT NULL,
  `direction_snapshot` longtext NOT NULL,
  `prompt` longtext NOT NULL,
  `analysis` longtext NOT NULL,
  `continuity` longtext NOT NULL,
  `planning_result` longtext NOT NULL,
  `plan_revision` int unsigned NOT NULL DEFAULT 0,
  `quote_json` longtext NOT NULL,
  `quote_token` varchar(64) NOT NULL DEFAULT '',
  `quote_expires` int unsigned NOT NULL DEFAULT 0,
  `confirmed_quote` longtext NOT NULL,
  `submit_key` varchar(100) NOT NULL DEFAULT '',
  `resume_key` varchar(100) NOT NULL DEFAULT '',
  `merge_status` varchar(30) NOT NULL DEFAULT 'pending',
  `result_uri` varchar(1000) NOT NULL DEFAULT '',
  `cover_uri` varchar(1000) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT '',
  `storage_engine` varchar(30) NOT NULL DEFAULT '',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `actual_duration` decimal(12,3) NOT NULL DEFAULT 0,
  `error` varchar(1000) NOT NULL DEFAULT '',
  `lease_token` varchar(64) NOT NULL DEFAULT '',
  `lease_until` int unsigned NOT NULL DEFAULT 0,
  `next_run_at` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`), UNIQUE KEY `uk_tenant_user_key` (`tenant_id`,`user_id`,`idempotency_key`), KEY `idx_due` (`status`,`next_run_at`,`lease_until`), KEY `idx_owner` (`tenant_id`,`user_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微视频task';

CREATE TABLE IF NOT EXISTS `la_aigc_micro_video_segment` (
  `first_frame_id` int unsigned NOT NULL DEFAULT 0,
  `last_frame_id` int unsigned NOT NULL DEFAULT 0,

  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `task_id` int unsigned NOT NULL,
  `sort` int unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `duration` int unsigned NOT NULL,
  `prompt` longtext NOT NULL,
  `image_indices` text NOT NULL,
  `start_state` text NOT NULL,
  `end_state` text NOT NULL,
  `continuity` text NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `attempt_no` int unsigned NOT NULL DEFAULT 0,
  `attempt_id` int unsigned NOT NULL DEFAULT 0,
  `video_uri` varchar(1000) NOT NULL DEFAULT '',
  `actual_duration` decimal(12,3) NOT NULL DEFAULT 0,
  `storage_scope` varchar(20) NOT NULL DEFAULT '',
  `storage_engine` varchar(30) NOT NULL DEFAULT '',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `error` varchar(1000) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`), UNIQUE KEY `uk_task_sort` (`task_id`,`sort`), KEY `idx_owner` (`tenant_id`,`user_id`,`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微视频segment';

CREATE TABLE IF NOT EXISTS `la_aigc_micro_video_attempt` (
  `frame_id` int unsigned NOT NULL DEFAULT 0,

  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `task_id` int unsigned NOT NULL,
  `segment_id` int unsigned NOT NULL DEFAULT 0,
  `kind` varchar(20) NOT NULL,
  `attempt_no` int unsigned NOT NULL,
  `status` varchar(30) NOT NULL,
  `app_task_id` int unsigned NOT NULL DEFAULT 0,
  `consumption_id` int unsigned NOT NULL DEFAULT 0,
  `provider_task_id` varchar(200) NOT NULL DEFAULT '',
  `request_json` longtext NOT NULL,
  `result_json` longtext NOT NULL,
  `error` varchar(1000) NOT NULL DEFAULT '',
  `user_charge_points` decimal(16,4) NOT NULL DEFAULT 0,
  `billing_status` varchar(30) NOT NULL DEFAULT 'none',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`), UNIQUE KEY `uk_execution` (`task_id`,`segment_id`,`frame_id`,`kind`,`attempt_no`), KEY `idx_consumption` (`consumption_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微视频attempt';

CREATE TABLE IF NOT EXISTS `la_aigc_micro_video_frame` (
 `id` int unsigned NOT NULL AUTO_INCREMENT,
 `tenant_id` int unsigned NOT NULL,
 `user_id` int unsigned NOT NULL,
 `task_id` int unsigned NOT NULL,
 `boundary_index` int unsigned NOT NULL,
 `version` int unsigned NOT NULL,
 `is_latest` tinyint unsigned NULL DEFAULT 1,
 `prompt` longtext NOT NULL,
 `reference_indices` text NOT NULL,
 `prompt_fingerprint` varchar(64) NOT NULL DEFAULT '',
 `dependency_fingerprint` varchar(64) NOT NULL DEFAULT '',
 `source_fingerprint` varchar(64) NOT NULL DEFAULT '',
 `status` varchar(30) NOT NULL DEFAULT 'pending',
 `confirmed` tinyint NOT NULL DEFAULT 0,
 `image_uri` varchar(1000) NOT NULL DEFAULT '',
 `storage_scope` varchar(20) NOT NULL DEFAULT '',
 `storage_engine` varchar(30) NOT NULL DEFAULT '',
 `storage_domain` varchar(255) NOT NULL DEFAULT '',
 `attempt_id` int unsigned NOT NULL DEFAULT 0,
 `user_charge_points` decimal(16,4) NOT NULL DEFAULT 0,
 `error` varchar(1000) NOT NULL DEFAULT '',
 `create_time` int unsigned NOT NULL DEFAULT 0,
 `update_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_boundary_version` (`task_id`,`boundary_index`,`version`),
 UNIQUE KEY `uk_current_boundary` (`task_id`,`boundary_index`,`is_latest`), KEY `idx_owner` (`tenant_id`,`user_id`,`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微视频共享边界版本';
CREATE TABLE IF NOT EXISTS `la_aigc_micro_video_frame_batch` (
 `id` int unsigned NOT NULL AUTO_INCREMENT,
 `tenant_id` int unsigned NOT NULL,
 `user_id` int unsigned NOT NULL,
 `task_id` int unsigned NOT NULL,
 `idempotency_key` varchar(100) NOT NULL,
 `request_hash` varchar(64) NOT NULL,
 `quote_json` longtext NOT NULL,
 `frame_ids` text NOT NULL,
 `status` varchar(30) NOT NULL DEFAULT 'pending',
 `create_time` int unsigned NOT NULL DEFAULT 0,
 `update_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_admission` (`tenant_id`,`user_id`,`task_id`,`idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微视频帧图幂等受理';

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
