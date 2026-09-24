-- Preserve old workspace runs while allowing the current canvas generation writer
-- to insert rows without legacy workspace-only fields.
SET @legacy_run_table = (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_run');

SET @legacy_run_sql = IF(@legacy_run_table = 1 AND (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_run' AND INDEX_NAME = 'uk_run_request') > 0, 'ALTER TABLE `la_aigc_short_drama_canvas_run` DROP INDEX `uk_run_request`', 'SELECT 1');
PREPARE legacy_run_stmt FROM @legacy_run_sql;
EXECUTE legacy_run_stmt;
DEALLOCATE PREPARE legacy_run_stmt;

SET @legacy_run_sql = IF(@legacy_run_table = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_run' AND COLUMN_NAME = 'workspace_id') > 0, 'ALTER TABLE `la_aigc_short_drama_canvas_run` MODIFY COLUMN `workspace_id` bigint unsigned NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE legacy_run_stmt FROM @legacy_run_sql;
EXECUTE legacy_run_stmt;
DEALLOCATE PREPARE legacy_run_stmt;

SET @legacy_run_sql = IF(@legacy_run_table = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_run' AND COLUMN_NAME = 'request_key') > 0, 'ALTER TABLE `la_aigc_short_drama_canvas_run` MODIFY COLUMN `request_key` varchar(64) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE legacy_run_stmt FROM @legacy_run_sql;
EXECUTE legacy_run_stmt;
DEALLOCATE PREPARE legacy_run_stmt;

SET @legacy_run_sql = IF(@legacy_run_table = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_run' AND COLUMN_NAME = 'request_hash') > 0, 'ALTER TABLE `la_aigc_short_drama_canvas_run` MODIFY COLUMN `request_hash` char(64) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE legacy_run_stmt FROM @legacy_run_sql;
EXECUTE legacy_run_stmt;
DEALLOCATE PREPARE legacy_run_stmt;

SET @legacy_run_sql = IF(@legacy_run_table = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_run' AND COLUMN_NAME = 'skill_snapshot_json') > 0, 'ALTER TABLE `la_aigc_short_drama_canvas_run` MODIFY COLUMN `skill_snapshot_json` mediumtext NULL', 'SELECT 1');
PREPARE legacy_run_stmt FROM @legacy_run_sql;
EXECUTE legacy_run_stmt;
DEALLOCATE PREPARE legacy_run_stmt;

SET @legacy_run_sql = IF(@legacy_run_table = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_run' AND COLUMN_NAME = 'context_json') > 0, 'ALTER TABLE `la_aigc_short_drama_canvas_run` MODIFY COLUMN `context_json` mediumtext NULL', 'SELECT 1');
PREPARE legacy_run_stmt FROM @legacy_run_sql;
EXECUTE legacy_run_stmt;
DEALLOCATE PREPARE legacy_run_stmt;

SET @legacy_run_sql = IF(@legacy_run_table = 1 AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_run' AND COLUMN_NAME = 'result_json') > 0, 'ALTER TABLE `la_aigc_short_drama_canvas_run` MODIFY COLUMN `result_json` mediumtext NULL', 'SELECT 1');
PREPARE legacy_run_stmt FROM @legacy_run_sql;
EXECUTE legacy_run_stmt;
DEALLOCATE PREPARE legacy_run_stmt;
