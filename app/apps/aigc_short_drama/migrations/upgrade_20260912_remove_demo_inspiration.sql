-- Retire only the bundled system demo. Keep tenant uploads and source files.
SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_inspiration' AND COLUMN_NAME = 'delete_time') = 0,
  'SELECT 1',
  'UPDATE `la_aigc_short_drama_inspiration` SET `status` = 0, `delete_time` = UNIX_TIMESTAMP(), `update_time` = UNIX_TIMESTAMP() WHERE `tenant_id` = 0 AND `title` = ''冬日河畔的静默'' AND `delete_time` = 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
