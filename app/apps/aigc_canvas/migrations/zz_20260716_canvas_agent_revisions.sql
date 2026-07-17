SET @db_name = DATABASE();

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_batch' AND COLUMN_NAME='batch_kind')=0,
  'ALTER TABLE `la_aigc_canvas_agent_batch` ADD COLUMN `batch_kind` varchar(24) NOT NULL DEFAULT ''initial'' AFTER `execution_mode`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_batch' AND COLUMN_NAME='parent_batch_id')=0,
  'ALTER TABLE `la_aigc_canvas_agent_batch` ADD COLUMN `parent_batch_id` int unsigned NOT NULL DEFAULT 0 AFTER `batch_kind`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_batch' AND COLUMN_NAME='revision_no')=0,
  'ALTER TABLE `la_aigc_canvas_agent_batch` ADD COLUMN `revision_no` int unsigned NOT NULL DEFAULT 0 AFTER `parent_batch_id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_batch' AND COLUMN_NAME='revision_instruction')=0,
  'ALTER TABLE `la_aigc_canvas_agent_batch` ADD COLUMN `revision_instruction` text NULL AFTER `revision_no`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_batch' AND COLUMN_NAME='decision_json')=0,
  'ALTER TABLE `la_aigc_canvas_agent_batch` ADD COLUMN `decision_json` longtext NULL AFTER `revision_instruction`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_batch' AND COLUMN_NAME='scope_json')=0,
  'ALTER TABLE `la_aigc_canvas_agent_batch` ADD COLUMN `scope_json` longtext NULL AFTER `decision_json`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_batch' AND INDEX_NAME='idx_batch_parent')=0,
  'ALTER TABLE `la_aigc_canvas_agent_batch` ADD KEY `idx_batch_parent` (`tenant_id`,`parent_batch_id`,`revision_no`,`delete_time`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
