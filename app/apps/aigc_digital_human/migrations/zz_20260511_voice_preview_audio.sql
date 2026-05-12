SET @aigc_dh_voice_table = CONCAT('la_', 'aigc_digital_human_voice');

SET @aigc_dh_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_dh_voice_table, '` ADD COLUMN `preview_audio_uri` varchar(500) NOT NULL DEFAULT '''' COMMENT ''试听音频地址'' AFTER `audio_uri`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_dh_voice_table AND COLUMN_NAME = 'preview_audio_uri');
PREPARE stmt FROM @aigc_dh_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
