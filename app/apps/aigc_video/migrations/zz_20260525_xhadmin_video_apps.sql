SET @aigc_video_task_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_video_task'
);

SET @aigc_video_sql = (
  SELECT IF(
    @aigc_video_task_exists > 0 AND COUNT(*) = 0,
    'ALTER TABLE `la_aigc_video_task` ADD COLUMN `reference_assets` text COMMENT ''参考素材'' AFTER `reference_images`',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_video_task'
    AND COLUMN_NAME = 'reference_assets'
);
PREPARE aigc_video_stmt FROM @aigc_video_sql;
EXECUTE aigc_video_stmt;
DEALLOCATE PREPARE aigc_video_stmt;

SET @aigc_video_duration_sql = (
  SELECT IF(
    @aigc_video_task_exists > 0 AND COUNT(*) = 0,
    'ALTER TABLE `la_aigc_video_task` ADD COLUMN `duration` int unsigned NOT NULL DEFAULT 0 COMMENT ''生成时长秒数'' AFTER `ratio`',
    'SELECT 1'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_aigc_video_task'
    AND COLUMN_NAME = 'duration'
);
PREPARE aigc_video_duration_stmt FROM @aigc_video_duration_sql;
EXECUTE aigc_video_duration_stmt;
DEALLOCATE PREPARE aigc_video_duration_stmt;

INSERT INTO `la_aigc_video_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'wan','Wan 2.7','xhadmin','wan2.7',4,'{"app_code":"wan","submit_path":"/api/v1/apps/wan/create","task_path":"/api/v1/apps/wan/query?task_id={task_id}","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[2,3,4,5,6,7,8,9,10,11,12,13,14,15],"videoedit_duration_options":[2,3,4,5,6,7,8,9,10],"supported_asset_types":["image","video","audio"],"max_reference_images":4,"max_reference_videos":1,"max_reference_audios":1,"max_reference_assets":6}',1,390,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','Seedance 2.0','xhadmin','seedance-2-text-2-video',9,'{"app_code":"seedance","submit_path":"/api/v1/apps/seedance/create","task_path":"/api/v1/tasks/{task_id}","asset_group_path":"/api/v1/apps/seedance/createGroup","asset_create_path":"/api/v1/apps/seedance/createAsset","project_name":"default","group_type":"AIGC","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[3,4,5,6,7,8,9,10,11,12,13,14,15],"supported_asset_types":["image","video","audio"],"max_reference_images":9,"max_reference_videos":3,"max_reference_audios":3,"max_reference_assets":15}',1,380,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','Omni-Flash-Ext','xhadmin','omni-flash-ext',3,'{"app_code":"omni_flash_ext","submit_path":"/api/v1/apps/omni_flash_ext/create","task_path":"/api/v1/tasks/{task_id}","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[4,6,8,10],"supported_asset_types":["image"],"max_reference_images":3,"max_reference_assets":3}',1,370,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `la_aigc_video_channel`.`name`=IF(`la_aigc_video_channel`.`name` IS NULL OR `la_aigc_video_channel`.`name` = '', VALUES(`name`), `la_aigc_video_channel`.`name`),`la_aigc_video_channel`.`provider`=IF(`la_aigc_video_channel`.`provider` IS NULL OR `la_aigc_video_channel`.`provider` = '', VALUES(`provider`), `la_aigc_video_channel`.`provider`),`la_aigc_video_channel`.`model`=IF(`la_aigc_video_channel`.`model` IS NULL OR `la_aigc_video_channel`.`model` = '', VALUES(`model`), `la_aigc_video_channel`.`model`),`la_aigc_video_channel`.`max_reference_images`=IF(`la_aigc_video_channel`.`max_reference_images` <= 0, VALUES(`max_reference_images`), `la_aigc_video_channel`.`max_reference_images`),`la_aigc_video_channel`.`config_json`=IF(`la_aigc_video_channel`.`config_json` IS NULL OR `la_aigc_video_channel`.`config_json` = '' OR `la_aigc_video_channel`.`config_json` = '{}', VALUES(`config_json`), `la_aigc_video_channel`.`config_json`),`la_aigc_video_channel`.`status`=`la_aigc_video_channel`.`status`,`la_aigc_video_channel`.`sort`=IF(`la_aigc_video_channel`.`sort` <= 0, VALUES(`sort`), `la_aigc_video_channel`.`sort`),`la_aigc_video_channel`.`update_time`=`la_aigc_video_channel`.`update_time`;

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'wan','720p_5','720P · 5秒','16:9',1280,720,0.00,0.00,'{"resolution":"720p","duration":5,"size":"16:9"}',1,1300,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'wan','720p_5','720P · 5秒','9:16',720,1280,0.00,0.00,'{"resolution":"720p","duration":5,"size":"9:16"}',1,1290,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'wan','720p_5','720P · 5秒','1:1',720,720,0.00,0.00,'{"resolution":"720p","duration":5,"size":"1:1"}',1,1280,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'wan','1080p_5','1080P · 5秒','16:9',1920,1080,0.00,0.00,'{"resolution":"1080p","duration":5,"size":"16:9"}',1,1270,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'wan','1080p_5','1080P · 5秒','9:16',1080,1920,0.00,0.00,'{"resolution":"1080p","duration":5,"size":"9:16"}',1,1260,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'wan','720p_10','720P · 10秒','16:9',1280,720,0.00,0.00,'{"resolution":"720p","duration":10,"size":"16:9"}',1,1250,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'wan','720p_10','720P · 10秒','9:16',720,1280,0.00,0.00,'{"resolution":"720p","duration":10,"size":"9:16"}',1,1240,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','720p_5','720P · 5秒','16:9',1280,720,0.00,0.00,'{"resolution":"720p","duration":5,"ratio":"16:9"}',1,1230,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','720p_5','720P · 5秒','9:16',720,1280,0.00,0.00,'{"resolution":"720p","duration":5,"ratio":"9:16"}',1,1220,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','720p_5','720P · 5秒','1:1',720,720,0.00,0.00,'{"resolution":"720p","duration":5,"ratio":"1:1"}',1,1210,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','720p_5','720P · 5秒','adaptive',0,0,0.00,0.00,'{"resolution":"720p","duration":5,"ratio":"adaptive"}',1,1200,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','1080p_5','1080P · 5秒','16:9',1920,1080,0.00,0.00,'{"resolution":"1080p","duration":5,"ratio":"16:9"}',1,1190,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','1080p_5','1080P · 5秒','9:16',1080,1920,0.00,0.00,'{"resolution":"1080p","duration":5,"ratio":"9:16"}',1,1180,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','720p_10','720P · 10秒','16:9',1280,720,0.00,0.00,'{"resolution":"720p","duration":10,"ratio":"16:9"}',1,1170,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','720p_10','720P · 10秒','9:16',720,1280,0.00,0.00,'{"resolution":"720p","duration":10,"ratio":"9:16"}',1,1160,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_4','720P · 4秒','16:9',1280,720,0.00,0.00,'{"resolution":"720p","duration":4,"aspect_ratio":"16:9"}',1,1150,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_4','720P · 4秒','9:16',720,1280,0.00,0.00,'{"resolution":"720p","duration":4,"aspect_ratio":"9:16"}',1,1140,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_4','720P · 4秒','1:1',720,720,0.00,0.00,'{"resolution":"720p","duration":4,"aspect_ratio":"1:1"}',1,1130,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','1080p_4','1080P · 4秒','16:9',1920,1080,0.00,0.00,'{"resolution":"1080p","duration":4,"aspect_ratio":"16:9"}',1,1120,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','1080p_4','1080P · 4秒','9:16',1080,1920,0.00,0.00,'{"resolution":"1080p","duration":4,"aspect_ratio":"9:16"}',1,1110,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_6','720P · 6秒','16:9',1280,720,0.00,0.00,'{"resolution":"720p","duration":6,"aspect_ratio":"16:9"}',1,1100,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_6','720P · 6秒','9:16',720,1280,0.00,0.00,'{"resolution":"720p","duration":6,"aspect_ratio":"9:16"}',1,1090,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_6','720P · 6秒','1:1',720,720,0.00,0.00,'{"resolution":"720p","duration":6,"aspect_ratio":"1:1"}',1,1080,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','1080p_6','1080P · 6秒','16:9',1920,1080,0.00,0.00,'{"resolution":"1080p","duration":6,"aspect_ratio":"16:9"}',1,1070,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','1080p_6','1080P · 6秒','9:16',1080,1920,0.00,0.00,'{"resolution":"1080p","duration":6,"aspect_ratio":"9:16"}',1,1060,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_8','720P · 8秒','16:9',1280,720,0.00,0.00,'{"resolution":"720p","duration":8,"aspect_ratio":"16:9"}',1,1050,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_8','720P · 8秒','9:16',720,1280,0.00,0.00,'{"resolution":"720p","duration":8,"aspect_ratio":"9:16"}',1,1040,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_8','720P · 8秒','1:1',720,720,0.00,0.00,'{"resolution":"720p","duration":8,"aspect_ratio":"1:1"}',1,1030,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','1080p_8','1080P · 8秒','16:9',1920,1080,0.00,0.00,'{"resolution":"1080p","duration":8,"aspect_ratio":"16:9"}',1,1020,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','1080p_8','1080P · 8秒','9:16',1080,1920,0.00,0.00,'{"resolution":"1080p","duration":8,"aspect_ratio":"9:16"}',1,1010,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_10','720P · 10秒','16:9',1280,720,0.00,0.00,'{"resolution":"720p","duration":10,"aspect_ratio":"16:9"}',1,1000,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_10','720P · 10秒','9:16',720,1280,0.00,0.00,'{"resolution":"720p","duration":10,"aspect_ratio":"9:16"}',1,990,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_10','720P · 10秒','1:1',720,720,0.00,0.00,'{"resolution":"720p","duration":10,"aspect_ratio":"1:1"}',1,980,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','1080p_10','1080P · 10秒','16:9',1920,1080,0.00,0.00,'{"resolution":"1080p","duration":10,"aspect_ratio":"16:9"}',1,970,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','1080p_10','1080P · 10秒','9:16',1080,1920,0.00,0.00,'{"resolution":"1080p","duration":10,"aspect_ratio":"9:16"}',1,960,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `la_aigc_video_channel_spec`.`quality_label`=IF(`la_aigc_video_channel_spec`.`quality_label` IS NULL OR `la_aigc_video_channel_spec`.`quality_label` = '', VALUES(`quality_label`), `la_aigc_video_channel_spec`.`quality_label`),`la_aigc_video_channel_spec`.`width`=IF(`la_aigc_video_channel_spec`.`width` <= 0, VALUES(`width`), `la_aigc_video_channel_spec`.`width`),`la_aigc_video_channel_spec`.`height`=IF(`la_aigc_video_channel_spec`.`height` <= 0, VALUES(`height`), `la_aigc_video_channel_spec`.`height`),`la_aigc_video_channel_spec`.`platform_unit_cost`=IF(`la_aigc_video_channel_spec`.`platform_unit_cost` <= 0, VALUES(`platform_unit_cost`), `la_aigc_video_channel_spec`.`platform_unit_cost`),`la_aigc_video_channel_spec`.`tenant_unit_price`=IF(`la_aigc_video_channel_spec`.`tenant_unit_price` <= 0, VALUES(`tenant_unit_price`), `la_aigc_video_channel_spec`.`tenant_unit_price`),`la_aigc_video_channel_spec`.`provider_params_json`=IF(`la_aigc_video_channel_spec`.`provider_params_json` IS NULL OR `la_aigc_video_channel_spec`.`provider_params_json` = '' OR `la_aigc_video_channel_spec`.`provider_params_json` = '{}', VALUES(`provider_params_json`), `la_aigc_video_channel_spec`.`provider_params_json`),`la_aigc_video_channel_spec`.`status`=`la_aigc_video_channel_spec`.`status`,`la_aigc_video_channel_spec`.`sort`=IF(`la_aigc_video_channel_spec`.`sort` <= 0, VALUES(`sort`), `la_aigc_video_channel_spec`.`sort`),`la_aigc_video_channel_spec`.`update_time`=`la_aigc_video_channel_spec`.`update_time`;
