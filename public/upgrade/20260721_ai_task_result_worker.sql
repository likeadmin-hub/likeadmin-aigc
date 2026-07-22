-- Keep public upgrade in sync with server/upgrade/20260721_ai_task_result_worker.sql.
CREATE TABLE IF NOT EXISTS `la_ai_task_job` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT, `app_task_id` int unsigned NOT NULL DEFAULT 0, `consumption_id` int unsigned NOT NULL DEFAULT 0, `result_asset_id` bigint unsigned NOT NULL DEFAULT 0,
  `job_type` varchar(32) NOT NULL DEFAULT '', `status` varchar(20) NOT NULL DEFAULT 'pending', `priority` int NOT NULL DEFAULT 0, `payload` text,
  `attempts` int unsigned NOT NULL DEFAULT 0, `max_attempts` int unsigned NOT NULL DEFAULT 0, `next_run_time` int unsigned NOT NULL DEFAULT 0, `lease_token` varchar(96) NOT NULL DEFAULT '', `lease_expire_time` int unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(1000) NOT NULL DEFAULT '', `idempotency_key` varchar(120) NOT NULL DEFAULT '', `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0, `finish_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_idempotency` (`idempotency_key`), KEY `idx_claim` (`status`,`next_run_time`,`priority`,`lease_expire_time`), KEY `idx_consumption` (`consumption_id`,`job_type`), KEY `idx_asset` (`result_asset_id`,`job_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI异步结果任务队列';
CREATE TABLE IF NOT EXISTS `la_ai_task_result_asset` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT, `app_task_id` int unsigned NOT NULL DEFAULT 0, `consumption_id` int unsigned NOT NULL DEFAULT 0, `tenant_id` int unsigned NOT NULL DEFAULT 0, `user_id` int unsigned NOT NULL DEFAULT 0,
  `asset_type` varchar(20) NOT NULL DEFAULT '', `external_url` text, `external_expire_time` int unsigned NOT NULL DEFAULT 0, `local_uri` text, `storage_scope` varchar(20) NOT NULL DEFAULT '', `storage_engine` varchar(32) NOT NULL DEFAULT '', `storage_domain` varchar(255) NOT NULL DEFAULT '', `storage_meta` text,
  `transfer_status` varchar(20) NOT NULL DEFAULT 'external', `transfer_attempts` int unsigned NOT NULL DEFAULT 0, `last_error` varchar(1000) NOT NULL DEFAULT '', `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `idx_consumption` (`consumption_id`,`asset_type`), KEY `idx_tenant_transfer` (`tenant_id`,`transfer_status`,`update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI任务结果资源';
INSERT INTO `la_tenant_config` (`tenant_id`,`type`,`name`,`value`,`create_time`,`update_time`)
SELECT t.`id`,'ai_task','result_transfer_enabled','0',UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant` t
WHERE NOT EXISTS (SELECT 1 FROM `la_tenant_config` c WHERE c.`tenant_id`=t.`id` AND c.`type`='ai_task' AND c.`name`='result_transfer_enabled');
UPDATE `la_dev_crontab` SET `expression`='*/5 * * * *',`params`='--limit=100',`remark`='补投异步任务结果处理作业',`update_time`=UNIX_TIMESTAMP() WHERE `command`='ai:usage_reconcile' AND `delete_time` IS NULL;
