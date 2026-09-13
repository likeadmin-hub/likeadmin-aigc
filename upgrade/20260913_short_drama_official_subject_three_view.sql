-- Optional tenant official subject three-view image.
SET @sd_three_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_short_drama_subject' AND COLUMN_NAME = 'three_view_image') = 0, 'ALTER TABLE `la_aigc_short_drama_subject` ADD COLUMN `three_view_image` varchar(500) NOT NULL DEFAULT '''' AFTER `image`', 'SELECT 1');
PREPARE sd_three_stmt FROM @sd_three_sql;
EXECUTE sd_three_stmt;
DEALLOCATE PREPARE sd_three_stmt;
