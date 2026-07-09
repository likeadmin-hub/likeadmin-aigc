SET NAMES utf8mb4;

SET @video_channel_table_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_channel');

UPDATE `la_aigc_video_channel`
SET `status` = 1,
    `update_time` = UNIX_TIMESTAMP()
WHERE @video_channel_table_exists > 0
  AND `tenant_id` = 0
  AND `status` = 0
  AND `code` IN ('grok_video_xaiq','happy_horse','happyhorse','happy_horse_video','wan','seedance','omni_flash_ext');
