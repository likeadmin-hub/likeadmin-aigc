-- Repair the short-drama project settings column for existing installations.

SET @sql = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_project' AND COLUMN_NAME = 'generation_settings_json') = 0,
  'ALTER TABLE `la_aigc_short_drama_project` ADD COLUMN `generation_settings_json` text COMMENT ''Project generation settings'' AFTER `input_asset_ids`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
