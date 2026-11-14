-- User-owned history pagination indexes. Existing installations receive the system index migration.
ALTER TABLE `la_aigc_copywriting_task` ADD INDEX `idx_owner_history_page` (`tenant_id`,`user_id`,`delete_time`,`id`);
