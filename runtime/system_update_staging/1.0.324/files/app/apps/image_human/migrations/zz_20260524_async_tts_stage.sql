SET @image_human_task_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_image_human_task'
);

SET @image_human_sql = (
  SELECT IF(
    @image_human_task_exists > 0 AND COUNT(*) = 0,
    'ALTER TABLE `la_image_human_task` ADD COLUMN `provider_stage` varchar(30) NOT NULL DEFAULT '''' COMMENT ''供应商阶段'' AFTER `provider_task_id`',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_image_human_task'
    AND COLUMN_NAME = 'provider_stage'
);
PREPARE image_human_stmt FROM @image_human_sql;
EXECUTE image_human_stmt;
DEALLOCATE PREPARE image_human_stmt;

SET @image_human_sql = (
  SELECT IF(
    @image_human_task_exists > 0 AND COUNT(*) = 0,
    'ALTER TABLE `la_image_human_task` ADD COLUMN `tts_task_id` varchar(120) NOT NULL DEFAULT '''' COMMENT ''音频合成任务ID'' AFTER `provider_stage`',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_image_human_task'
    AND COLUMN_NAME = 'tts_task_id'
);
PREPARE image_human_stmt FROM @image_human_sql;
EXECUTE image_human_stmt;
DEALLOCATE PREPARE image_human_stmt;

SET @image_human_sql = IF(
  @image_human_task_exists > 0,
  'UPDATE `la_image_human_task`
SET `provider_stage` = IF(`provider_task_id` <> '''', ''video_running'', IF(`audio_uri` <> '''' AND (`voice_id` <= 0 OR COALESCE(`script_text`, '''') = ''''), ''video_submitted'', ''created''))
WHERE `provider_stage` = ''''',
  'SELECT 1'
);
PREPARE image_human_stmt FROM @image_human_sql;
EXECUTE image_human_stmt;
DEALLOCATE PREPARE image_human_stmt;
