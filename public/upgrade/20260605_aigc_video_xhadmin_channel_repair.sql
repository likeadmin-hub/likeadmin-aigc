INSERT INTO `la_aigc_video_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'wan','Wan 2.7','xhadmin','wan2.7',4,'{"app_code":"wan","submit_path":"/api/v1/apps/wan/create","task_path":"/api/v1/apps/wan/query?task_id={task_id}","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[2,3,4,5,6,7,8,9,10,11,12,13,14,15],"videoedit_duration_options":[2,3,4,5,6,7,8,9,10],"supported_asset_types":["image","video","audio"],"max_reference_images":4,"max_reference_videos":1,"max_reference_audios":1,"max_reference_assets":6}',1,390,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','Seedance 2.0','xhadmin','seedance-2-text-2-video',9,'{"app_code":"seedance","submit_path":"/api/v1/apps/seedance/create","task_path":"/api/v1/tasks/{task_id}","asset_group_path":"/api/v1/apps/seedance/createGroup","asset_create_path":"/api/v1/apps/seedance/createAsset","project_name":"default","group_type":"AIGC","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[3,4,5,6,7,8,9,10,11,12,13,14,15],"supported_asset_types":["image","video","audio"],"max_reference_images":9,"max_reference_videos":3,"max_reference_audios":3,"max_reference_assets":15}',1,380,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','Omni-Flash-Ext','xhadmin','omni-flash-ext',3,'{"app_code":"omni_flash_ext","submit_path":"/api/v1/apps/omni_flash_ext/create","task_path":"/api/v1/tasks/{task_id}","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[4,6,8,10],"supported_asset_types":["image"],"max_reference_images":3,"max_reference_assets":3}',1,370,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`provider`=VALUES(`provider`),`model`=VALUES(`model`),`max_reference_images`=VALUES(`max_reference_images`),`config_json`=VALUES(`config_json`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);

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
(0,'omni_flash_ext','720p_6','720P · 6秒','16:9',1280,720,0.00,0.00,'{"resolution":"720p","duration":6,"aspect_ratio":"16:9"}',1,1150,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_6','720P · 6秒','9:16',720,1280,0.00,0.00,'{"resolution":"720p","duration":6,"aspect_ratio":"9:16"}',1,1140,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_6','720P · 6秒','1:1',720,720,0.00,0.00,'{"resolution":"720p","duration":6,"aspect_ratio":"1:1"}',1,1130,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','1080p_6','1080P · 6秒','16:9',1920,1080,0.00,0.00,'{"resolution":"1080p","duration":6,"aspect_ratio":"16:9"}',1,1120,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','1080p_6','1080P · 6秒','9:16',1080,1920,0.00,0.00,'{"resolution":"1080p","duration":6,"aspect_ratio":"9:16"}',1,1110,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_10','720P · 10秒','16:9',1280,720,0.00,0.00,'{"resolution":"720p","duration":10,"aspect_ratio":"16:9"}',1,1100,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','720p_10','720P · 10秒','9:16',720,1280,0.00,0.00,'{"resolution":"720p","duration":10,"aspect_ratio":"9:16"}',1,1090,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `quality_label`=VALUES(`quality_label`),`width`=VALUES(`width`),`height`=VALUES(`height`),`provider_params_json`=VALUES(`provider_params_json`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_SET(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.duration_options',
        CASE `code`
            WHEN 'wan' THEN JSON_ARRAY(2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15)
            WHEN 'seedance' THEN JSON_ARRAY(3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15)
            WHEN 'omni_flash_ext' THEN JSON_ARRAY(4, 6, 8, 10)
            ELSE JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.duration_options')
        END
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `code` IN ('wan', 'seedance', 'omni_flash_ext');
