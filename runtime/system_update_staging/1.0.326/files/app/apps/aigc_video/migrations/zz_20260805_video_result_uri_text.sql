-- Store complete upstream video URLs, including long-lived signed URLs.

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_result' AND COLUMN_NAME = 'video_uri') = 1,
  'ALTER TABLE `la_aigc_video_result` MODIFY COLUMN `video_uri` TEXT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
