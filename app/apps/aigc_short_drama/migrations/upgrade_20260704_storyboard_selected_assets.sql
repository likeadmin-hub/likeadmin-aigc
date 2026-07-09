SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'selected_image_asset_id') = 0,
  'ALTER TABLE `la_aigc_short_drama_storyboard` ADD COLUMN `selected_image_asset_id` int unsigned NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'selected_video_asset_id') = 0,
  'ALTER TABLE `la_aigc_short_drama_storyboard` ADD COLUMN `selected_video_asset_id` int unsigned NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
