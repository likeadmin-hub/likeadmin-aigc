-- Preserve customer voice assets while completing the required preview-audio schema.
SET NAMES utf8mb4;
SET @q = IF(EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_digital_human_voice') AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_digital_human_voice' AND COLUMN_NAME='preview_audio_uri'), 'ALTER TABLE `la_aigc_digital_human_voice` ADD COLUMN `preview_audio_uri` varchar(500) NOT NULL DEFAULT ''''', 'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
