SET @db_name = DATABASE();

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'la_aigc_canvas_skill' AND COLUMN_NAME = 'examples_json') = 0,
  'ALTER TABLE `la_aigc_canvas_skill` ADD COLUMN `examples_json` text NULL COMMENT ''Positive examples JSON'' AFTER `workflow_json`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'la_aigc_canvas_skill' AND COLUMN_NAME = 'negative_examples_json') = 0,
  'ALTER TABLE `la_aigc_canvas_skill` ADD COLUMN `negative_examples_json` text NULL COMMENT ''Negative examples JSON'' AFTER `examples_json`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'la_aigc_canvas_skill' AND COLUMN_NAME = 'required_slots_json') = 0,
  'ALTER TABLE `la_aigc_canvas_skill` ADD COLUMN `required_slots_json` text NULL COMMENT ''Required slots JSON'' AFTER `negative_examples_json`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'la_aigc_canvas_skill' AND COLUMN_NAME = 'optional_slots_json') = 0,
  'ALTER TABLE `la_aigc_canvas_skill` ADD COLUMN `optional_slots_json` text NULL COMMENT ''Optional slots JSON'' AFTER `required_slots_json`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'la_aigc_canvas_skill' AND COLUMN_NAME = 'defaults_json') = 0,
  'ALTER TABLE `la_aigc_canvas_skill` ADD COLUMN `defaults_json` text NULL COMMENT ''Default slot values JSON'' AFTER `optional_slots_json`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'la_aigc_canvas_skill' AND COLUMN_NAME = 'clarification_policy_json') = 0,
  'ALTER TABLE `la_aigc_canvas_skill` ADD COLUMN `clarification_policy_json` text NULL COMMENT ''Clarification policy JSON'' AFTER `defaults_json`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'la_aigc_canvas_skill' AND COLUMN_NAME = 'output_policy_json') = 0,
  'ALTER TABLE `la_aigc_canvas_skill` ADD COLUMN `output_policy_json` text NULL COMMENT ''Output policy JSON'' AFTER `tool_policy_json`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
