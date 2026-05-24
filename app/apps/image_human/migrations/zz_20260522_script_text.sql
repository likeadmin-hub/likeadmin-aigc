SET @image_human_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `la_image_human_task` ADD COLUMN `script_text` text COMMENT ''文案内容'' AFTER `audio_uri`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_image_human_task' AND COLUMN_NAME = 'script_text');
PREPARE image_human_stmt FROM @image_human_sql;
EXECUTE image_human_stmt;
DEALLOCATE PREPARE image_human_stmt;

UPDATE `la_image_human_task`
SET `script_text` = COALESCE(NULLIF(`script_text`, ''), `prompt`, '')
WHERE `script_text` IS NULL OR `script_text` = '';
