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

-- Backfill historical canvas projections using the immutable canvas run link.
UPDATE `la_aigc_short_drama_generation_task` g
INNER JOIN `la_aigc_short_drama_canvas_run` r ON r.tenant_id = g.tenant_id AND g.task_id = CONCAT('canvas_run_', r.id) AND r.delete_time = 0
SET g.canvas_id = r.canvas_id
WHERE g.canvas_id = 0;

UPDATE `la_aigc_short_drama_asset` a
INNER JOIN `la_aigc_short_drama_canvas_run` r ON r.tenant_id = a.tenant_id AND a.task_id = CONCAT('canvas_run_', r.id) AND r.delete_time = 0
SET a.canvas_id = r.canvas_id
WHERE a.canvas_id = 0;
