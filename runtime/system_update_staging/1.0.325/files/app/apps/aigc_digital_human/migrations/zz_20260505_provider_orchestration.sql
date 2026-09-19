SET @aigc_dh_task_table = REPLACE('`la_aigc_digital_human_task`', '`', '');
SET @aigc_dh_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_dh_task_table, '` ADD COLUMN `provider_stage` varchar(50) NOT NULL DEFAULT '''' COMMENT ''供应商编排阶段'' AFTER `provider_task_id`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_dh_task_table AND COLUMN_NAME = 'provider_stage');
PREPARE aigc_dh_stmt FROM @aigc_dh_sql;
EXECUTE aigc_dh_stmt;
DEALLOCATE PREPARE aigc_dh_stmt;

SET @aigc_dh_task_table = REPLACE('`la_aigc_digital_human_task`', '`', '');
SET @aigc_dh_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_dh_task_table, '` ADD COLUMN `tts_task_id` varchar(120) NOT NULL DEFAULT '''' COMMENT ''TTS供应商任务ID'' AFTER `provider_stage`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_dh_task_table AND COLUMN_NAME = 'tts_task_id');
PREPARE aigc_dh_stmt FROM @aigc_dh_sql;
EXECUTE aigc_dh_stmt;
DEALLOCATE PREPARE aigc_dh_stmt;

SET @aigc_dh_task_table = REPLACE('`la_aigc_digital_human_task`', '`', '');
SET @aigc_dh_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_dh_task_table, '` ADD COLUMN `tts_audio_uri` varchar(500) NOT NULL DEFAULT '''' COMMENT ''TTS音频地址'' AFTER `tts_task_id`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_dh_task_table AND COLUMN_NAME = 'tts_audio_uri');
PREPARE aigc_dh_stmt FROM @aigc_dh_sql;
EXECUTE aigc_dh_stmt;
DEALLOCATE PREPARE aigc_dh_stmt;

SET @aigc_dh_task_table = REPLACE('`la_aigc_digital_human_task`', '`', '');
SET @aigc_dh_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_dh_task_table, '` ADD COLUMN `provider_payload_json` text COMMENT ''供应商阶段载荷'' AFTER `tts_audio_uri`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_dh_task_table AND COLUMN_NAME = 'provider_payload_json');
PREPARE aigc_dh_stmt FROM @aigc_dh_sql;
EXECUTE aigc_dh_stmt;
DEALLOCATE PREPARE aigc_dh_stmt;

SET @aigc_dh_task_table = REPLACE('`la_aigc_digital_human_task`', '`', '');
SET @aigc_dh_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @aigc_dh_task_table, '` ADD KEY `idx_tts_task` (`tenant_id`,`provider`,`tts_task_id`)'), 'SELECT 1') FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @aigc_dh_task_table AND INDEX_NAME = 'idx_tts_task');
PREPARE aigc_dh_stmt FROM @aigc_dh_sql;
EXECUTE aigc_dh_stmt;
DEALLOCATE PREPARE aigc_dh_stmt;

UPDATE `la_aigc_digital_human_channel`
SET `provider` = 'xhadmin',
    `model` = 'xiaojiayu1.0',
    `config_json` = '{"tts_model":"s2-pro","tts_format":"mp3","lipsync_model":"xiaojiayu1.0"}',
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `code` IN ('master', 'all', 'free') AND `provider` = 'mock';
