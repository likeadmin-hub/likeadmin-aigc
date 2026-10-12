-- Complete missing 390 short-drama relationships without rewriting existing customer rows.
-- Only conditional ADD COLUMN / ADD KEY; no historical canvas backfill.
SET NAMES utf8mb4;

-- Link short-drama task history to its owning short-drama canvas project.
SET @canvas_task_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_generation_task' AND COLUMN_NAME = 'canvas_id') = 0, 'ALTER TABLE `la_aigc_short_drama_generation_task` ADD COLUMN `canvas_id` int unsigned NOT NULL DEFAULT 0 AFTER `project_id`', 'SELECT 1');
PREPARE canvas_task_column_stmt FROM @canvas_task_column_sql;
EXECUTE canvas_task_column_stmt;
DEALLOCATE PREPARE canvas_task_column_stmt;

SET @canvas_task_index_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_generation_task' AND INDEX_NAME = 'idx_canvas_type') = 0, 'ALTER TABLE `la_aigc_short_drama_generation_task` ADD KEY `idx_canvas_type` (`tenant_id`,`canvas_id`,`task_type`,`status`)', 'SELECT 1');
PREPARE canvas_task_index_stmt FROM @canvas_task_index_sql;
EXECUTE canvas_task_index_stmt;
DEALLOCATE PREPARE canvas_task_index_stmt;

SET @canvas_asset_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_asset' AND COLUMN_NAME = 'canvas_id') = 0, 'ALTER TABLE `la_aigc_short_drama_asset` ADD COLUMN `canvas_id` int unsigned NOT NULL DEFAULT 0 AFTER `project_id`', 'SELECT 1');
PREPARE canvas_asset_column_stmt FROM @canvas_asset_column_sql;
EXECUTE canvas_asset_column_stmt;
DEALLOCATE PREPARE canvas_asset_column_stmt;

SET @canvas_asset_index_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_asset' AND INDEX_NAME = 'idx_canvas_type') = 0, 'ALTER TABLE `la_aigc_short_drama_asset` ADD KEY `idx_canvas_type` (`tenant_id`,`canvas_id`,`asset_type`,`delete_time`)', 'SELECT 1');
PREPARE canvas_asset_index_stmt FROM @canvas_asset_index_sql;
EXECUTE canvas_asset_index_stmt;
DEALLOCATE PREPARE canvas_asset_index_stmt;


SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_asset' AND COLUMN_NAME = 'episode_id') = 0, 'ALTER TABLE `la_aigc_short_drama_asset` ADD COLUMN `episode_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_asset' AND COLUMN_NAME = 'episode_number') = 0, 'ALTER TABLE `la_aigc_short_drama_asset` ADD COLUMN `episode_number` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'episode_id') = 0, 'ALTER TABLE `la_aigc_short_drama_storyboard` ADD COLUMN `episode_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'episode_number') = 0, 'ALTER TABLE `la_aigc_short_drama_storyboard` ADD COLUMN `episode_number` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_generation_task' AND COLUMN_NAME = 'episode_id') = 0, 'ALTER TABLE `la_aigc_short_drama_generation_task` ADD COLUMN `episode_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_generation_task' AND COLUMN_NAME = 'episode_number') = 0, 'ALTER TABLE `la_aigc_short_drama_generation_task` ADD COLUMN `episode_number` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_script_task' AND COLUMN_NAME = 'episode_id') = 0, 'ALTER TABLE `la_aigc_short_drama_script_task` ADD COLUMN `episode_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_script_task' AND COLUMN_NAME = 'episode_number') = 0, 'ALTER TABLE `la_aigc_short_drama_script_task` ADD COLUMN `episode_number` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_plan_version' AND COLUMN_NAME = 'episode_id') = 0, 'ALTER TABLE `la_aigc_short_drama_plan_version` ADD COLUMN `episode_id` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

SET @episode_column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_plan_version' AND COLUMN_NAME = 'episode_number') = 0, 'ALTER TABLE `la_aigc_short_drama_plan_version` ADD COLUMN `episode_number` int unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE episode_column_stmt FROM @episode_column_sql;
EXECUTE episode_column_stmt;
DEALLOCATE PREPARE episode_column_stmt;

