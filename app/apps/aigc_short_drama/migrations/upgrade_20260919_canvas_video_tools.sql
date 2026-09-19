-- Manual capture uses the media worker too; web requests never execute FFmpeg.
SET @frame_kind_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_canvas_poster_job' AND COLUMN_NAME = 'job_kind') = 0, 'ALTER TABLE `la_aigc_short_drama_canvas_poster_job` ADD COLUMN `job_kind` varchar(20) NOT NULL DEFAULT ''poster'', ADD COLUMN `capture_time` decimal(12,3) NOT NULL DEFAULT 0.001, ADD COLUMN `result_json` mediumtext NULL', 'SELECT 1');
PREPARE frame_kind_stmt FROM @frame_kind_sql;
EXECUTE frame_kind_stmt;
DEALLOCATE PREPARE frame_kind_stmt;
