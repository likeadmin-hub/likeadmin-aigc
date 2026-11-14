-- User-owned history pagination indexes. Existing installations receive the system index migration.
ALTER TABLE `la_aigc_short_drama_episode_task` ADD INDEX `idx_owner_history_page` (`tenant_id`,`user_id`,`delete_time`,`id`);
ALTER TABLE `la_aigc_short_drama_generation_task` ADD INDEX `idx_owner_history_page` (`tenant_id`,`user_id`,`delete_time`,`id`);
