INSERT INTO `la_aigc_video_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'grok_video_xaiq','Grok Video（xAIQ）','xhadmin','grok-video',7,'{"poll_interval":2,"poll_attempts":30,"quantity_options":[1],"duration_options":[6,10,15,20,25,30],"quality":"720p"}',1,400,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','Happy Horse','happyhorse','happyhorse-1.0-t2v',9,'{"submit_path":"/api/v1/apps/happy_horse/submit","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[3,5,10,15],"resolution":"720P"}',1,300,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'wan','Wan 2.7','xhadmin','wan2.7',4,'{"app_code":"wan","submit_path":"/api/v1/apps/wan/create","task_path":"/api/v1/apps/wan/query?task_id={task_id}","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[2,3,4,5,6,7,8,9,10,11,12,13,14,15],"videoedit_duration_options":[2,3,4,5,6,7,8,9,10],"supported_asset_types":["image","video","audio"],"max_reference_images":4,"max_reference_videos":1,"max_reference_audios":1,"max_reference_assets":6}',1,390,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','Seedance 2.0','xhadmin','seedance-2-text-2-video',9,'{"app_code":"seedance","submit_path":"/api/v1/apps/seedance/create","task_path":"/api/v1/tasks/{task_id}","asset_group_path":"/api/v1/apps/seedance/createGroup","asset_create_path":"/api/v1/apps/seedance/createAsset","project_name":"default","group_type":"AIGC","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[3,4,5,6,7,8,9,10,11,12,13,14,15],"supported_asset_types":["image","video","audio"],"max_reference_images":9,"max_reference_videos":3,"max_reference_audios":3,"max_reference_assets":15}',1,380,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','Omni-Flash-Ext','xhadmin','omni-flash-ext',3,'{"app_code":"omni_flash_ext","submit_path":"/api/v1/apps/omni_flash_ext/create","task_path":"/api/v1/tasks/{task_id}","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[4,6,8,10],"supported_asset_types":["image"],"max_reference_images":3,"max_reference_assets":3}',1,370,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `name`=VALUES(`name`),
  `provider`=VALUES(`provider`),
  `model`=VALUES(`model`),
  `max_reference_images`=VALUES(`max_reference_images`),
  `sort`=VALUES(`sort`),
  `update_time`=VALUES(`update_time`);

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_SET(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.quantity_options', JSON_ARRAY(1),
        '$.duration_options',
        CASE `code`
            WHEN 'grok_video_xaiq' THEN JSON_ARRAY(6, 10, 15, 20, 25, 30)
            WHEN 'happy_horse' THEN JSON_ARRAY(3, 5, 10, 15)
            WHEN 'wan' THEN JSON_ARRAY(2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15)
            WHEN 'seedance' THEN JSON_ARRAY(3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15)
            WHEN 'omni_flash_ext' THEN JSON_ARRAY(4, 6, 8, 10)
            ELSE JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.duration_options')
        END,
        '$.videoedit_duration_options',
        CASE `code`
            WHEN 'wan' THEN JSON_ARRAY(2, 3, 4, 5, 6, 7, 8, 9, 10)
            ELSE JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.videoedit_duration_options')
        END
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `code` IN ('grok_video_xaiq', 'happy_horse', 'wan', 'seedance', 'omni_flash_ext');

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_SET(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.submit_path', '/api/v1/apps/happy_horse/submit',
        '$.poll_interval', 2,
        '$.poll_attempts', 0,
        '$.resolution', '720P'
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `code` = 'happy_horse';

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_SET(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.app_code', 'wan',
        '$.submit_path', '/api/v1/apps/wan/create',
        '$.task_path', '/api/v1/apps/wan/query?task_id={task_id}',
        '$.supported_asset_types', JSON_ARRAY('image', 'video', 'audio'),
        '$.max_reference_images', 4,
        '$.max_reference_videos', 1,
        '$.max_reference_audios', 1,
        '$.max_reference_assets', 6
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `code` = 'wan';

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_SET(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.app_code', 'seedance',
        '$.submit_path', '/api/v1/apps/seedance/create',
        '$.task_path', '/api/v1/tasks/{task_id}',
        '$.asset_group_path', '/api/v1/apps/seedance/createGroup',
        '$.asset_create_path', '/api/v1/apps/seedance/createAsset',
        '$.project_name', 'default',
        '$.group_type', 'AIGC',
        '$.supported_asset_types', JSON_ARRAY('image', 'video', 'audio'),
        '$.max_reference_images', 9,
        '$.max_reference_videos', 3,
        '$.max_reference_audios', 3,
        '$.max_reference_assets', 15
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `code` = 'seedance';

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_SET(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.app_code', 'omni_flash_ext',
        '$.submit_path', '/api/v1/apps/omni_flash_ext/create',
        '$.task_path', '/api/v1/tasks/{task_id}',
        '$.supported_asset_types', JSON_ARRAY('image'),
        '$.max_reference_images', 3,
        '$.max_reference_assets', 3
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `code` = 'omni_flash_ext';

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT
    0,
    'grok_video_xaiq',
    CAST(duration.`duration` AS CHAR),
    CONCAT(duration.`duration`, '秒'),
    ratio.`ratio`,
    ratio.`width`,
    ratio.`height`,
    duration.`price`,
    duration.`price`,
    CONCAT('{"quality":"720p","duration":', duration.`duration`, ',"aspect_ratio":"', ratio.`ratio`, '"}'),
    1,
    duration.`sort_base` - ratio.`sort_offset`,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
FROM (
    SELECT 6 AS `duration`, 0.17 AS `price`, 1000 AS `sort_base` UNION ALL
    SELECT 10, 0.28, 950 UNION ALL
    SELECT 15, 0.42, 900 UNION ALL
    SELECT 20, 0.56, 850 UNION ALL
    SELECT 25, 0.70, 800 UNION ALL
    SELECT 30, 0.84, 750
) AS duration
CROSS JOIN (
    SELECT '16:9' AS `ratio`, 1280 AS `width`, 720 AS `height`, 0 AS `sort_offset` UNION ALL
    SELECT '9:16', 720, 1280, 10 UNION ALL
    SELECT '1:1', 720, 720, 20 UNION ALL
    SELECT '2:3', 720, 1080, 30 UNION ALL
    SELECT '3:2', 1080, 720, 40
) AS ratio
ON DUPLICATE KEY UPDATE
    `quality_label`=VALUES(`quality_label`),
    `width`=VALUES(`width`),
    `height`=VALUES(`height`),
    `provider_params_json`=VALUES(`provider_params_json`),
    `status`=VALUES(`status`),
    `sort`=VALUES(`sort`),
    `update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT
    0,
    'happy_horse',
    CONCAT(LOWER(template.`resolution`), '_', duration.`duration`),
    CONCAT(template.`resolution`, ' · ', duration.`duration`, '秒'),
    template.`ratio`,
    template.`width`,
    template.`height`,
    CASE template.`resolution`
        WHEN '1080P' THEN duration.`price_1080p`
        ELSE duration.`price_720p`
    END,
    CASE template.`resolution`
        WHEN '1080P' THEN duration.`price_1080p`
        ELSE duration.`price_720p`
    END,
    CONCAT('{"resolution":"', template.`resolution`, '","duration":', duration.`duration`, ',"ratio":"', template.`ratio`, '"}'),
    1,
    1200 - duration.`duration` * 10 - template.`sort_offset`,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
FROM (
    SELECT '720P' AS `resolution`, '16:9' AS `ratio`, 1280 AS `width`, 720 AS `height`, 0 AS `sort_offset` UNION ALL
    SELECT '720P', '9:16', 720, 1280, 10 UNION ALL
    SELECT '720P', '1:1', 720, 720, 20 UNION ALL
    SELECT '720P', '4:3', 960, 720, 30 UNION ALL
    SELECT '720P', '3:4', 720, 960, 40 UNION ALL
    SELECT '1080P', '16:9', 1920, 1080, 200 UNION ALL
    SELECT '1080P', '9:16', 1080, 1920, 210 UNION ALL
    SELECT '1080P', '1:1', 1080, 1080, 220 UNION ALL
    SELECT '1080P', '4:3', 1440, 1080, 230 UNION ALL
    SELECT '1080P', '3:4', 1080, 1440, 240
) AS template
CROSS JOIN (
    SELECT 3 AS `duration`, 0.08 AS `price_720p`, 0.17 AS `price_1080p` UNION ALL
    SELECT 5, 0.14, 0.28 UNION ALL
    SELECT 10, 0.28, 0.56 UNION ALL
    SELECT 15, 0.42, 0.84
) AS duration
ON DUPLICATE KEY UPDATE
    `quality_label`=VALUES(`quality_label`),
    `width`=VALUES(`width`),
    `height`=VALUES(`height`),
    `provider_params_json`=VALUES(`provider_params_json`),
    `status`=VALUES(`status`),
    `sort`=VALUES(`sort`),
    `update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT
    0,
    'wan',
    CONCAT(LOWER(template.`resolution`), '_', duration.`duration`),
    CONCAT(UPPER(template.`resolution`), ' · ', duration.`duration`, '秒'),
    template.`ratio`,
    template.`width`,
    template.`height`,
    0.00,
    0.00,
    CONCAT('{"resolution":"', template.`resolution`, '","duration":', duration.`duration`, ',"size":"', template.`ratio`, '"}'),
    1,
    1000 - duration.`duration` - template.`sort_offset`,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
FROM (
    SELECT '720p' AS `resolution`, '16:9' AS `ratio`, 1280 AS `width`, 720 AS `height`, 0 AS `sort_offset` UNION ALL
    SELECT '720p', '9:16', 720, 1280, 10 UNION ALL
    SELECT '720p', '1:1', 720, 720, 20 UNION ALL
    SELECT '1080p', '16:9', 1920, 1080, 30 UNION ALL
    SELECT '1080p', '9:16', 1080, 1920, 40
) AS template
CROSS JOIN (
    SELECT 2 AS `duration` UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
    UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
    UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13
    UNION ALL SELECT 14 UNION ALL SELECT 15
) AS duration
ON DUPLICATE KEY UPDATE
    `quality_label`=VALUES(`quality_label`),
    `width`=VALUES(`width`),
    `height`=VALUES(`height`),
    `provider_params_json`=VALUES(`provider_params_json`),
    `status`=VALUES(`status`),
    `sort`=VALUES(`sort`),
    `update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT
    0,
    'seedance',
    CONCAT(LOWER(template.`resolution`), '_', duration.`duration`),
    CONCAT(UPPER(template.`resolution`), ' · ', duration.`duration`, '秒'),
    template.`ratio`,
    template.`width`,
    template.`height`,
    0.00,
    0.00,
    CONCAT('{"resolution":"', template.`resolution`, '","duration":', duration.`duration`, ',"ratio":"', template.`ratio`, '"}'),
    1,
    1000 - duration.`duration` - template.`sort_offset`,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
FROM (
    SELECT '720p' AS `resolution`, '16:9' AS `ratio`, 1280 AS `width`, 720 AS `height`, 0 AS `sort_offset` UNION ALL
    SELECT '720p', '9:16', 720, 1280, 10 UNION ALL
    SELECT '720p', '1:1', 720, 720, 20 UNION ALL
    SELECT '720p', 'adaptive', 0, 0, 30 UNION ALL
    SELECT '1080p', '16:9', 1920, 1080, 40 UNION ALL
    SELECT '1080p', '9:16', 1080, 1920, 50
) AS template
CROSS JOIN (
    SELECT 3 AS `duration` UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
    UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10
    UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14
    UNION ALL SELECT 15
) AS duration
ON DUPLICATE KEY UPDATE
    `quality_label`=VALUES(`quality_label`),
    `width`=VALUES(`width`),
    `height`=VALUES(`height`),
    `provider_params_json`=VALUES(`provider_params_json`),
    `status`=VALUES(`status`),
    `sort`=VALUES(`sort`),
    `update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT
    0,
    'omni_flash_ext',
    CONCAT(LOWER(template.`resolution`), '_', duration.`duration`),
    CONCAT(UPPER(template.`resolution`), ' · ', duration.`duration`, '秒'),
    template.`ratio`,
    template.`width`,
    template.`height`,
    0.00,
    0.00,
    CONCAT('{"resolution":"', template.`resolution`, '","duration":', duration.`duration`, ',"aspect_ratio":"', template.`ratio`, '"}'),
    1,
    1000 - duration.`duration` - template.`sort_offset`,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
FROM (
    SELECT '720p' AS `resolution`, '16:9' AS `ratio`, 1280 AS `width`, 720 AS `height`, 0 AS `sort_offset` UNION ALL
    SELECT '720p', '9:16', 720, 1280, 10 UNION ALL
    SELECT '720p', '1:1', 720, 720, 20 UNION ALL
    SELECT '1080p', '16:9', 1920, 1080, 30 UNION ALL
    SELECT '1080p', '9:16', 1080, 1920, 40
) AS template
CROSS JOIN (
    SELECT 4 AS `duration` UNION ALL SELECT 6 UNION ALL SELECT 8 UNION ALL SELECT 10
) AS duration
ON DUPLICATE KEY UPDATE
    `quality_label`=VALUES(`quality_label`),
    `width`=VALUES(`width`),
    `height`=VALUES(`height`),
    `provider_params_json`=VALUES(`provider_params_json`),
    `status`=VALUES(`status`),
    `sort`=VALUES(`sort`),
    `update_time`=VALUES(`update_time`);
