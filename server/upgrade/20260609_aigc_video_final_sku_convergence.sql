INSERT INTO `la_aigc_video_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'grok_video_xaiq','Grok Video（xAIQ）','xhadmin','grok-video',7,'{"poll_interval":2,"poll_attempts":30,"quantity_options":[1],"duration_options":[6,10,15,20,25,30],"quality":"720p","supported_asset_types":["image"],"max_reference_images":7,"max_reference_assets":7}',1,400,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'wan','Wan 2.7','xhadmin','wan2.7',4,'{"app_code":"wan","pricing_api_code":"create","api_code":"create","submit_path":"/api/v1/apps/wan/create","task_path":"/api/v1/apps/wan/query?task_id={task_id}","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[2,3,4,5,6,7,8,9,10,11,12,13,14,15],"videoedit_duration_options":[2,3,4,5,6,7,8,9,10],"supported_asset_types":["image","video","audio"],"max_reference_images":4,"max_reference_videos":1,"max_reference_audios":1,"max_reference_assets":6}',1,390,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'seedance','Seedance 2.0','xhadmin','seedance-2-text-2-video',9,'{"app_code":"seedance","pricing_api_code":"create","api_code":"create","submit_path":"/api/v1/apps/seedance/create","task_path":"/api/v1/tasks/{task_id}","asset_group_path":"/api/v1/apps/seedance/createGroup","asset_create_path":"/api/v1/apps/seedance/createAsset","project_name":"default","group_type":"AIGC","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[4,5,6,7,8,9,10,11,12,13,14,15],"supported_asset_types":["image","video","audio"],"max_reference_images":9,"max_reference_videos":3,"max_reference_audios":3,"max_reference_assets":15}',1,380,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'omni_flash_ext','Omni-Flash-Ext','xhadmin','omni-flash-ext',3,'{"app_code":"omni_flash_ext","pricing_api_code":"create","api_code":"create","submit_path":"/api/v1/apps/omni_flash_ext/create","task_path":"/api/v1/tasks/{task_id}","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[4,6,8,10],"supported_asset_types":["image"],"max_reference_images":3,"max_reference_assets":3}',1,370,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','Happy Horse','happyhorse','happyhorse-1.0-t2v',9,'{"app_code":"happy_horse","pricing_api_code":"submit","api_code":"submit","submit_path":"/api/v1/apps/happy_horse/submit","query_path":"/api/v1/apps/happy_horse/query","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[3,4,5,6,7,8,9,10,11,12,13,14,15],"videoedit_duration_options":[3,4,5,6,7,8,9,10,11,12,13,14,15],"resolution":"720P","supported_asset_types":["image","video"],"max_reference_images":9,"max_reference_videos":1,"max_reference_assets":10}',1,300,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `name`=IF(`name` IS NULL OR `name` = '', VALUES(`name`), `name`),
  `provider`=IF(`provider` IS NULL OR `provider` = '', VALUES(`provider`), `provider`),
  `model`=IF(`model` IS NULL OR `model` = '', VALUES(`model`), `model`),
  `max_reference_images`=IF(`max_reference_images` <= 0, VALUES(`max_reference_images`), `max_reference_images`),
  `sort`=IF(`sort` <= 0, VALUES(`sort`), `sort`),
  `update_time`=`update_time`;

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_INSERT(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.quantity_options', JSON_ARRAY(1),
        '$.supported_asset_types',
        CASE `code`
            WHEN 'grok_video_xaiq' THEN JSON_ARRAY('image')
            WHEN 'wan' THEN JSON_ARRAY('image', 'video', 'audio')
            WHEN 'seedance' THEN JSON_ARRAY('image', 'video', 'audio')
            WHEN 'omni_flash_ext' THEN JSON_ARRAY('image')
            WHEN 'happy_horse' THEN JSON_ARRAY('image', 'video')
            ELSE JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.supported_asset_types')
        END,
        '$.duration_options',
        CASE `code`
            WHEN 'grok_video_xaiq' THEN JSON_ARRAY(6, 10, 15, 20, 25, 30)
            WHEN 'wan' THEN JSON_ARRAY(2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15)
            WHEN 'seedance' THEN JSON_ARRAY(4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15)
            WHEN 'omni_flash_ext' THEN JSON_ARRAY(4, 6, 8, 10)
            WHEN 'happy_horse' THEN JSON_ARRAY(3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15)
            ELSE JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.duration_options')
        END,
        '$.videoedit_duration_options',
        CASE `code`
            WHEN 'wan' THEN JSON_ARRAY(2, 3, 4, 5, 6, 7, 8, 9, 10)
            WHEN 'happy_horse' THEN JSON_ARRAY(3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15)
            ELSE JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.videoedit_duration_options')
        END,
        '$.pricing_api_code',
        CASE `code`
            WHEN 'wan' THEN 'create'
            WHEN 'seedance' THEN 'create'
            WHEN 'omni_flash_ext' THEN 'create'
            WHEN 'happy_horse' THEN 'submit'
            ELSE JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.pricing_api_code'))
        END,
        '$.api_code',
        CASE `code`
            WHEN 'wan' THEN 'create'
            WHEN 'seedance' THEN 'create'
            WHEN 'omni_flash_ext' THEN 'create'
            WHEN 'happy_horse' THEN 'submit'
            ELSE JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.api_code'))
        END
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `code` IN ('grok_video_xaiq', 'wan', 'seedance', 'omni_flash_ext', 'happy_horse');

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_INSERT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.app_code', 'wan', '$.submit_path', '/api/v1/apps/wan/create', '$.task_path', '/api/v1/apps/wan/query?task_id={task_id}', '$.max_reference_images', 4, '$.max_reference_videos', 1, '$.max_reference_audios', 1, '$.max_reference_assets', 6),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `code` = 'wan';

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_INSERT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.app_code', 'seedance', '$.submit_path', '/api/v1/apps/seedance/create', '$.task_path', '/api/v1/tasks/{task_id}', '$.asset_group_path', '/api/v1/apps/seedance/createGroup', '$.asset_create_path', '/api/v1/apps/seedance/createAsset', '$.project_name', 'default', '$.group_type', 'AIGC', '$.max_reference_images', 9, '$.max_reference_videos', 3, '$.max_reference_audios', 3, '$.max_reference_assets', 15),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `code` = 'seedance';

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_INSERT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.app_code', 'omni_flash_ext', '$.submit_path', '/api/v1/apps/omni_flash_ext/create', '$.task_path', '/api/v1/tasks/{task_id}', '$.max_reference_images', 3, '$.max_reference_assets', 3),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `code` = 'omni_flash_ext';

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_INSERT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.app_code', 'happy_horse', '$.submit_path', '/api/v1/apps/happy_horse/submit', '$.query_path', '/api/v1/apps/happy_horse/query', '$.max_reference_images', 9, '$.max_reference_videos', 1, '$.max_reference_assets', 10, '$.resolution', '720P'),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `code` = 'happy_horse';

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`upstream_unit_cost`,`platform_unit_cost`,`tenant_unit_price`,`upstream_cost_text`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT 0,'grok_video_xaiq',CAST(d.`duration` AS CHAR),CONCAT(d.`duration`, '秒'),r.`ratio`,r.`width`,r.`height`,d.`upstream`,d.`platform`,d.`platform`,CONCAT('Grok Video 上游 720p ', d.`duration`, '秒固定价 / 次'),CONCAT('{"quality":"720p","duration":', d.`duration`, ',"aspect_ratio":"', r.`ratio`, '"}'),1,d.`sort_base` - r.`sort_offset`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (
    SELECT 6 AS `duration`, 30.00 AS `upstream`, 0.17 AS `platform`, 1000 AS `sort_base` UNION ALL
    SELECT 10, 30.00, 0.28, 950 UNION ALL
    SELECT 15, 30.00, 0.42, 900 UNION ALL
    SELECT 20, 30.00, 0.56, 850 UNION ALL
    SELECT 25, 30.00, 0.70, 800 UNION ALL
    SELECT 30, 30.00, 0.84, 750
) AS d
CROSS JOIN (
    SELECT '16:9' AS `ratio`, 1280 AS `width`, 720 AS `height`, 0 AS `sort_offset` UNION ALL
    SELECT '9:16', 720, 1280, 10 UNION ALL
    SELECT '1:1', 720, 720, 20 UNION ALL
    SELECT '2:3', 720, 1080, 30 UNION ALL
    SELECT '3:2', 1080, 720, 40
) AS r
WHERE 1 = 1
ON DUPLICATE KEY UPDATE
    `quality_label`=IF(`quality_label` IS NULL OR `quality_label` = '', VALUES(`quality_label`), `quality_label`),
    `width`=IF(`width` <= 0, VALUES(`width`), `width`),
    `height`=IF(`height` <= 0, VALUES(`height`), `height`),
    `upstream_unit_cost`=IF(`upstream_unit_cost` <= 0, VALUES(`upstream_unit_cost`), `upstream_unit_cost`),
    `platform_unit_cost`=IF(`platform_unit_cost` <= 0, VALUES(`platform_unit_cost`), `platform_unit_cost`),
    `tenant_unit_price`=IF(`tenant_unit_price` <= 0, VALUES(`tenant_unit_price`), `tenant_unit_price`),
    `upstream_cost_text`=IF(`upstream_cost_text` = '', VALUES(`upstream_cost_text`), `upstream_cost_text`),
    `provider_params_json`=IF(`provider_params_json` IS NULL OR `provider_params_json` = '' OR `provider_params_json` = '{}', VALUES(`provider_params_json`), `provider_params_json`),
    `status`=`status`,
    `sort`=IF(`sort` <= 0, VALUES(`sort`), `sort`),
    `update_time`=`update_time`;

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT 0,'wan',CONCAT(LOWER(t.`resolution`), '_', d.`duration`),CONCAT(UPPER(t.`resolution`), ' · ', d.`duration`, '秒'),t.`ratio`,t.`width`,t.`height`,0.00,0.00,CONCAT('{"resolution":"', t.`resolution`, '","duration":', d.`duration`, ',"size":"', t.`ratio`, '"}'),1,1400 - d.`duration` * 10 - t.`sort_offset`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (
    SELECT '720p' AS `resolution`, '16:9' AS `ratio`, 1280 AS `width`, 720 AS `height`, 0 AS `sort_offset` UNION ALL
    SELECT '720p', '9:16', 720, 1280, 10 UNION ALL
    SELECT '720p', '1:1', 720, 720, 20 UNION ALL
    SELECT '720p', '4:3', 960, 720, 30 UNION ALL
    SELECT '720p', '3:4', 720, 960, 40 UNION ALL
    SELECT '1080p', '16:9', 1920, 1080, 200 UNION ALL
    SELECT '1080p', '9:16', 1080, 1920, 210 UNION ALL
    SELECT '1080p', '1:1', 1080, 1080, 220 UNION ALL
    SELECT '1080p', '4:3', 1440, 1080, 230 UNION ALL
    SELECT '1080p', '3:4', 1080, 1440, 240
) AS t
CROSS JOIN (
    SELECT 2 AS `duration` UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
    UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
    UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13
    UNION ALL SELECT 14 UNION ALL SELECT 15
) AS d
WHERE 1 = 1
ON DUPLICATE KEY UPDATE
    `quality_label`=IF(`quality_label` IS NULL OR `quality_label` = '', VALUES(`quality_label`), `quality_label`),
    `width`=IF(`width` <= 0, VALUES(`width`), `width`),
    `height`=IF(`height` <= 0, VALUES(`height`), `height`),
    `provider_params_json`=IF(`provider_params_json` IS NULL OR `provider_params_json` = '' OR `provider_params_json` = '{}', VALUES(`provider_params_json`), `provider_params_json`),
    `status`=`status`,
    `sort`=IF(`sort` <= 0, VALUES(`sort`), `sort`),
    `update_time`=`update_time`;

UPDATE `la_aigc_video_channel_spec`
SET `status` = `status`,
    `update_time` = `update_time`
WHERE `tenant_id` = 0
  AND `channel_code` = 'seedance'
  AND JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(`provider_params_json`, ''), '{}'), '$._pricing_variant')) IS NULL;

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`upstream_unit_cost`,`platform_unit_cost`,`tenant_unit_price`,`upstream_cost_text`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT 0,'seedance',CONCAT(t.`resolution`, '_', v.`variant`),CONCAT(UPPER(t.`resolution`), ' / ', v.`label`),t.`ratio`,t.`width`,t.`height`,v.`upstream_cost`,v.`upstream_cost`,v.`upstream_cost`,CONCAT(UPPER(t.`resolution`), ' / ', v.`label`, '，点 / 百万 Token'),CONCAT('{"resolution":"', t.`resolution`, '","ratio":"', t.`ratio`, '","_pricing_variant":"', v.`variant`, '"}'),1,1800 - t.`resolution_sort` - v.`variant_sort` - t.`ratio_sort`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (
    SELECT '480p' AS `resolution`, 0 AS `resolution_sort`, '16:9' AS `ratio`, 854 AS `width`, 480 AS `height`, 0 AS `ratio_sort` UNION ALL
    SELECT '480p', 0, '4:3', 640, 480, 10 UNION ALL
    SELECT '480p', 0, '1:1', 480, 480, 20 UNION ALL
    SELECT '480p', 0, '3:4', 480, 640, 30 UNION ALL
    SELECT '480p', 0, '9:16', 480, 854, 40 UNION ALL
    SELECT '480p', 0, '21:9', 1120, 480, 50 UNION ALL
    SELECT '480p', 0, 'adaptive', 0, 0, 60 UNION ALL
    SELECT '720p', 100, '16:9', 1280, 720, 0 UNION ALL
    SELECT '720p', 100, '4:3', 960, 720, 10 UNION ALL
    SELECT '720p', 100, '1:1', 720, 720, 20 UNION ALL
    SELECT '720p', 100, '3:4', 720, 960, 30 UNION ALL
    SELECT '720p', 100, '9:16', 720, 1280, 40 UNION ALL
    SELECT '720p', 100, '21:9', 1680, 720, 50 UNION ALL
    SELECT '720p', 100, 'adaptive', 0, 0, 60 UNION ALL
    SELECT '1080p', 200, '16:9', 1920, 1080, 0 UNION ALL
    SELECT '1080p', 200, '4:3', 1440, 1080, 10 UNION ALL
    SELECT '1080p', 200, '1:1', 1080, 1080, 20 UNION ALL
    SELECT '1080p', 200, '3:4', 1080, 1440, 30 UNION ALL
    SELECT '1080p', 200, '9:16', 1080, 1920, 40 UNION ALL
    SELECT '1080p', 200, '21:9', 2520, 1080, 50 UNION ALL
    SELECT '1080p', 200, 'adaptive', 0, 0, 60
) AS t
JOIN (
    SELECT 'with_video' AS `variant`, '含视频输入' AS `label`, 1 AS `variant_sort`, '480p' AS `resolution`, 3000.00 AS `upstream_cost` UNION ALL
    SELECT 'without_video', '不含视频输入', 2, '480p', 5000.00 UNION ALL
    SELECT 'with_video', '含视频输入', 1, '720p', 3200.00 UNION ALL
    SELECT 'without_video', '不含视频输入', 2, '720p', 5500.00 UNION ALL
    SELECT 'with_video', '含视频输入', 1, '1080p', 3500.00 UNION ALL
    SELECT 'without_video', '不含视频输入', 2, '1080p', 6000.00
) AS v ON v.`resolution` = t.`resolution`
WHERE 1 = 1
ON DUPLICATE KEY UPDATE
    `quality_label`=IF(`quality_label` IS NULL OR `quality_label` = '', VALUES(`quality_label`), `quality_label`),
    `width`=IF(`width` <= 0, VALUES(`width`), `width`),
    `height`=IF(`height` <= 0, VALUES(`height`), `height`),
    `upstream_unit_cost`=IF(`upstream_unit_cost` <= 0, VALUES(`upstream_unit_cost`), `upstream_unit_cost`),
    `platform_unit_cost`=IF(`platform_unit_cost` <= 0, VALUES(`platform_unit_cost`), `platform_unit_cost`),
    `tenant_unit_price`=IF(`tenant_unit_price` <= 0, VALUES(`tenant_unit_price`), `tenant_unit_price`),
    `upstream_cost_text`=IF(`upstream_cost_text` = '', VALUES(`upstream_cost_text`), `upstream_cost_text`),
    `provider_params_json`=IF(`provider_params_json` IS NULL OR `provider_params_json` = '' OR `provider_params_json` = '{}', VALUES(`provider_params_json`), `provider_params_json`),
    `status`=`status`,
    `sort`=IF(`sort` <= 0, VALUES(`sort`), `sort`),
    `update_time`=`update_time`;

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT 0,'omni_flash_ext',CONCAT(LOWER(t.`resolution`), '_', d.`duration`),CONCAT(UPPER(t.`resolution`), ' · ', d.`duration`, '秒'),t.`ratio`,t.`width`,t.`height`,0.00,0.00,CONCAT('{"resolution":"', t.`resolution`, '","duration":', d.`duration`, ',"aspect_ratio":"', t.`ratio`, '"}'),1,1000 - d.`duration` * 10 - t.`sort_offset`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (
    SELECT '720p' AS `resolution`, '16:9' AS `ratio`, 1280 AS `width`, 720 AS `height`, 0 AS `sort_offset` UNION ALL
    SELECT '720p', '9:16', 720, 1280, 10 UNION ALL
    SELECT '720p', '1:1', 720, 720, 20 UNION ALL
    SELECT '1080p', '16:9', 1920, 1080, 30 UNION ALL
    SELECT '1080p', '9:16', 1080, 1920, 40
) AS t
CROSS JOIN (
    SELECT 4 AS `duration` UNION ALL SELECT 6 UNION ALL SELECT 8 UNION ALL SELECT 10
) AS d
WHERE 1 = 1
ON DUPLICATE KEY UPDATE
    `quality_label`=IF(`quality_label` IS NULL OR `quality_label` = '', VALUES(`quality_label`), `quality_label`),
    `width`=IF(`width` <= 0, VALUES(`width`), `width`),
    `height`=IF(`height` <= 0, VALUES(`height`), `height`),
    `provider_params_json`=IF(`provider_params_json` IS NULL OR `provider_params_json` = '' OR `provider_params_json` = '{}', VALUES(`provider_params_json`), `provider_params_json`),
    `status`=`status`,
    `sort`=IF(`sort` <= 0, VALUES(`sort`), `sort`),
    `update_time`=`update_time`;

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT 0,'happy_horse',CONCAT(LOWER(t.`resolution`), '_', d.`duration`),CONCAT(t.`resolution`, ' · ', d.`duration`, '秒'),t.`ratio`,t.`width`,t.`height`,ROUND(d.`duration` * CASE t.`resolution` WHEN '1080P' THEN 0.056 ELSE 0.028 END, 2),ROUND(d.`duration` * CASE t.`resolution` WHEN '1080P' THEN 0.056 ELSE 0.028 END, 2),CONCAT('{"resolution":"', t.`resolution`, '","duration":', d.`duration`, ',"ratio":"', t.`ratio`, '"}'),1,1300 - d.`duration` * 10 - t.`sort_offset`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
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
) AS t
CROSS JOIN (
    SELECT 3 AS `duration` UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
    UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10
    UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14
    UNION ALL SELECT 15
) AS d
WHERE 1 = 1
ON DUPLICATE KEY UPDATE
    `quality_label`=IF(`quality_label` IS NULL OR `quality_label` = '', VALUES(`quality_label`), `quality_label`),
    `width`=IF(`width` <= 0, VALUES(`width`), `width`),
    `height`=IF(`height` <= 0, VALUES(`height`), `height`),
    `platform_unit_cost`=IF(`platform_unit_cost` <= 0, VALUES(`platform_unit_cost`), `platform_unit_cost`),
    `tenant_unit_price`=IF(`tenant_unit_price` <= 0, VALUES(`tenant_unit_price`), `tenant_unit_price`),
    `provider_params_json`=IF(`provider_params_json` IS NULL OR `provider_params_json` = '' OR `provider_params_json` = '{}', VALUES(`provider_params_json`), `provider_params_json`),
    `status`=`status`,
    `sort`=IF(`sort` <= 0, VALUES(`sort`), `sort`),
    `update_time`=`update_time`;
