SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_subject' AND COLUMN_NAME = 'category') = 0,
  'ALTER TABLE `la_aigc_short_drama_subject` ADD COLUMN `category` varchar(40) NOT NULL DEFAULT ''character'' AFTER `description`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_subject' AND COLUMN_NAME = 'gender') = 0,
  'ALTER TABLE `la_aigc_short_drama_subject` ADD COLUMN `gender` varchar(20) NOT NULL DEFAULT ''unknown'' AFTER `category`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_subject' AND COLUMN_NAME = 'age_stage') = 0,
  'ALTER TABLE `la_aigc_short_drama_subject` ADD COLUMN `age_stage` varchar(30) NOT NULL DEFAULT ''unknown'' AFTER `gender`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_subject' AND INDEX_NAME = 'idx_subject_filters') = 0,
  'ALTER TABLE `la_aigc_short_drama_subject` ADD INDEX `idx_subject_filters` (`tenant_id`,`category`,`gender`,`age_stage`,`status`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
