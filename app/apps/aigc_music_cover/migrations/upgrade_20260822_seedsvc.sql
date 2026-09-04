SET @music_cover_ref_asset_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `la_aigc_music_cover_task` ADD COLUMN `reference_asset_id` int unsigned NOT NULL DEFAULT 0 AFTER `source_url`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_music_cover_task' AND COLUMN_NAME = 'reference_asset_id');
PREPARE music_cover_ref_asset_stmt FROM @music_cover_ref_asset_sql;
EXECUTE music_cover_ref_asset_stmt;
DEALLOCATE PREPARE music_cover_ref_asset_stmt;

SET @music_cover_ref_uri_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `la_aigc_music_cover_task` ADD COLUMN `reference_uri` varchar(500) NOT NULL DEFAULT '''' AFTER `reference_asset_id`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_music_cover_task' AND COLUMN_NAME = 'reference_uri');
PREPARE music_cover_ref_uri_stmt FROM @music_cover_ref_uri_sql;
EXECUTE music_cover_ref_uri_stmt;
DEALLOCATE PREPARE music_cover_ref_uri_stmt;

SET @music_cover_ref_url_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `la_aigc_music_cover_task` ADD COLUMN `reference_url` varchar(1000) NOT NULL DEFAULT '''' AFTER `reference_uri`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_music_cover_task' AND COLUMN_NAME = 'reference_url');
PREPARE music_cover_ref_url_stmt FROM @music_cover_ref_url_sql;
EXECUTE music_cover_ref_url_stmt;
DEALLOCATE PREPARE music_cover_ref_url_stmt;
