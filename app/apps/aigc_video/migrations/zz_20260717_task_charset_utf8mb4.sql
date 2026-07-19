SET NAMES utf8mb4;

SET @aigc_video_task_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_video_task'
);

SET @sql := IF(
  @aigc_video_task_exists > 0,
  'ALTER TABLE `la_aigc_video_task` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `la_aigc_video_task`
SET `prompt` = REPLACE(`prompt`, '�', ''),
    `negative_prompt` = REPLACE(`negative_prompt`, '�', ''),
    `reference_images` = REPLACE(`reference_images`, '�', ''),
    `reference_assets` = REPLACE(`reference_assets`, '�', ''),
    `error` = REPLACE(`error`, '�', ''),
    `update_time` = UNIX_TIMESTAMP()
WHERE CONCAT_WS('', `prompt`, `negative_prompt`, `reference_images`, `reference_assets`, `error`) LIKE '%�%';
