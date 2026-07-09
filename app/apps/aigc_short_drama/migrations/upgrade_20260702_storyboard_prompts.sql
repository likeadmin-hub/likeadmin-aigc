SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'title') = 0,
  'ALTER TABLE `la_aigc_short_drama_storyboard` ADD COLUMN `title` varchar(120) NOT NULL DEFAULT '''' AFTER `act`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'shot_type') = 0,
  'ALTER TABLE `la_aigc_short_drama_storyboard` ADD COLUMN `shot_type` varchar(80) NOT NULL DEFAULT '''' AFTER `camera_movement`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'angle') = 0,
  'ALTER TABLE `la_aigc_short_drama_storyboard` ADD COLUMN `angle` varchar(80) NOT NULL DEFAULT '''' AFTER `shot_type`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'action') = 0,
  'ALTER TABLE `la_aigc_short_drama_storyboard` ADD COLUMN `action` text AFTER `angle`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'result') = 0,
  'ALTER TABLE `la_aigc_short_drama_storyboard` ADD COLUMN `result` varchar(500) NOT NULL DEFAULT '''' AFTER `action`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'atmosphere') = 0,
  'ALTER TABLE `la_aigc_short_drama_storyboard` ADD COLUMN `atmosphere` varchar(300) NOT NULL DEFAULT '''' AFTER `result`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'image_prompt') = 0,
  'ALTER TABLE `la_aigc_short_drama_storyboard` ADD COLUMN `image_prompt` text AFTER `atmosphere`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'video_prompt') = 0,
  'ALTER TABLE `la_aigc_short_drama_storyboard` ADD COLUMN `video_prompt` text AFTER `image_prompt`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'bgm_prompt') = 0,
  'ALTER TABLE `la_aigc_short_drama_storyboard` ADD COLUMN `bgm_prompt` varchar(500) NOT NULL DEFAULT '''' AFTER `video_prompt`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'sound_effect') = 0,
  'ALTER TABLE `la_aigc_short_drama_storyboard` ADD COLUMN `sound_effect` varchar(500) NOT NULL DEFAULT '''' AFTER `bgm_prompt`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'scene_ref_id') = 0,
  'ALTER TABLE `la_aigc_short_drama_storyboard` ADD COLUMN `scene_ref_id` varchar(80) NOT NULL DEFAULT '''' AFTER `sound_effect`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_storyboard' AND COLUMN_NAME = 'subject_ref_ids') = 0,
  'ALTER TABLE `la_aigc_short_drama_storyboard` ADD COLUMN `subject_ref_ids` text AFTER `scene_ref_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
