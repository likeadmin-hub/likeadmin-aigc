ALTER TABLE `la_aigc_short_drama_vnext_stage` ADD COLUMN `attempt_count` int unsigned NOT NULL DEFAULT 1;
ALTER TABLE `la_aigc_short_drama_vnext_stage` ADD COLUMN `retry_after` int unsigned NOT NULL DEFAULT 0;
