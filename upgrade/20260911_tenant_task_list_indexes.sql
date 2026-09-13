-- 幂等索引升级；重复索引和不存在的可选表由升级执行器安全跳过。
ALTER TABLE `la_aigc_image_task` ADD INDEX `idx_tenant_delete_id` (`tenant_id`,`delete_time`,`id`);
ALTER TABLE `la_aigc_image_task` ADD INDEX `idx_tenant_status_delete_id` (`tenant_id`,`status`,`delete_time`,`id`);
ALTER TABLE `la_aigc_image_task` ADD INDEX `idx_tenant_user_delete_id` (`tenant_id`,`user_id`,`delete_time`,`id`);
ALTER TABLE `la_aigc_image_task` ADD INDEX `idx_tenant_style_delete_id` (`tenant_id`,`style`,`delete_time`,`id`);
ALTER TABLE `la_aigc_image_result` ADD INDEX `idx_tenant_task_delete_id` (`tenant_id`,`task_id`,`delete_time`,`id`);
