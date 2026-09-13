-- 幂等索引升级；重复索引和不存在的可选表由升级执行器安全跳过。
ALTER TABLE `la_operation_log` ADD INDEX `idx_create_time_id` (`create_time`,`id`);
ALTER TABLE `la_operation_log` ADD INDEX `idx_admin_create_id` (`admin_id`,`create_time`,`id`);
ALTER TABLE `la_operation_log` ADD INDEX `idx_type_create_id` (`type`,`create_time`,`id`);
ALTER TABLE `la_tenant_file` ADD INDEX `idx_tenant_source_id` (`tenant_id`,`source`,`id`);
ALTER TABLE `la_tenant_file` ADD INDEX `idx_tenant_source_cid_id` (`tenant_id`,`source`,`cid`,`id`);
ALTER TABLE `la_tenant_file` ADD INDEX `idx_tenant_source_type_id` (`tenant_id`,`source`,`type`,`id`);
ALTER TABLE `la_tenant_system_menu` ADD INDEX `idx_tenant_show_sort_id` (`tenant_id`,`is_show`,`sort`,`id`);
ALTER TABLE `la_tenant_system_menu` ADD INDEX `idx_tenant_pid_sort_id` (`tenant_id`,`pid`,`sort`,`id`);
ALTER TABLE `la_tenant_system_menu` ADD INDEX `idx_tenant_app_code` (`tenant_id`,`app_code`);
ALTER TABLE `la_tenant_point_log` ADD INDEX `idx_tenant_type_action_time_id` (`tenant_id`,`change_type`,`action`,`create_time`,`id`);
ALTER TABLE `la_tenant_point_log` ADD INDEX `idx_tenant_time_id` (`tenant_id`,`create_time`,`id`);
ALTER TABLE `la_user_account_log` ADD INDEX `idx_tenant_user_time_id` (`tenant_id`,`user_id`,`create_time`,`id`);
ALTER TABLE `la_user_account_log` ADD INDEX `idx_tenant_time_id` (`tenant_id`,`create_time`,`id`);
