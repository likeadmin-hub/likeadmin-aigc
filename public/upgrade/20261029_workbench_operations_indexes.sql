-- 经营概览日期范围索引。每条独立执行，重复索引由 SqlMigrationExecutor 安全跳过。
-- 统一任务和结算账本为共享表，新租户沿用共享索引，不创建租户分表索引。
ALTER TABLE `la_ai_app_task` ADD INDEX `idx_ops_tenant_parent_time` (`tenant_id`,`parent_task_id`,`create_time`);
ALTER TABLE `la_ai_app_task` ADD INDEX `idx_ops_parent_time` (`parent_task_id`,`create_time`);
ALTER TABLE `la_ai_consumption_log` ADD INDEX `idx_ops_tenant_billing_finish` (`tenant_id`,`billing_status`,`finish_time`);
ALTER TABLE `la_ai_consumption_log` ADD INDEX `idx_ops_billing_finish` (`billing_status`,`finish_time`);
