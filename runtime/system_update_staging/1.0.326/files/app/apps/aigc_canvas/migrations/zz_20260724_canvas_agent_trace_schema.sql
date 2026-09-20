SET @db_name = DATABASE();

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_run' AND COLUMN_NAME='parent_run_id')=0,
  'ALTER TABLE `la_aigc_canvas_agent_run` ADD COLUMN `parent_run_id` int unsigned NOT NULL DEFAULT 0 AFTER `request_id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_run' AND COLUMN_NAME='sub_agent_code')=0,
  'ALTER TABLE `la_aigc_canvas_agent_run` ADD COLUMN `sub_agent_code` varchar(64) NOT NULL DEFAULT '''' AFTER `parent_run_id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_run' AND COLUMN_NAME='depth')=0,
  'ALTER TABLE `la_aigc_canvas_agent_run` ADD COLUMN `depth` tinyint unsigned NOT NULL DEFAULT 0 AFTER `sub_agent_code`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_run' AND COLUMN_NAME='sequence')=0,
  'ALTER TABLE `la_aigc_canvas_agent_run` ADD COLUMN `sequence` int unsigned NOT NULL DEFAULT 0 AFTER `depth`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_run' AND COLUMN_NAME='handoff_json')=0,
  'ALTER TABLE `la_aigc_canvas_agent_run` ADD COLUMN `handoff_json` longtext NULL AFTER `output_json`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_run' AND INDEX_NAME='idx_parent_run')=0,
  'ALTER TABLE `la_aigc_canvas_agent_run` ADD KEY `idx_parent_run` (`tenant_id`,`parent_run_id`,`sequence`,`delete_time`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
