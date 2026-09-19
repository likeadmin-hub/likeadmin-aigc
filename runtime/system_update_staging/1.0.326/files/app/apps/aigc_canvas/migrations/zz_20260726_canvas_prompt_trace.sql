SET @db_name = DATABASE();

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_batch' AND COLUMN_NAME='creative_context_json')=0,
  'ALTER TABLE `la_aigc_canvas_agent_batch` ADD COLUMN `creative_context_json` longtext NULL AFTER `scope_json`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_batch' AND COLUMN_NAME='plan_version')=0,
  'ALTER TABLE `la_aigc_canvas_agent_batch` ADD COLUMN `plan_version` int unsigned NOT NULL DEFAULT 1 AFTER `creative_context_json`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_batch' AND COLUMN_NAME='prompt_compiler_version')=0,
  'ALTER TABLE `la_aigc_canvas_agent_batch` ADD COLUMN `prompt_compiler_version` varchar(40) NOT NULL DEFAULT '''' AFTER `plan_version`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_batch' AND COLUMN_NAME='evidence_snapshot_json')=0,
  'ALTER TABLE `la_aigc_canvas_agent_batch` ADD COLUMN `evidence_snapshot_json` longtext NULL AFTER `prompt_compiler_version`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='la_aigc_canvas_agent_batch' AND COLUMN_NAME='claim_snapshot_json')=0,
  'ALTER TABLE `la_aigc_canvas_agent_batch` ADD COLUMN `claim_snapshot_json` longtext NULL AFTER `evidence_snapshot_json`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
