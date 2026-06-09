UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_INSERT(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.quantity_options', JSON_ARRAY(1),
        '$.duration_options',
        CASE `code`
            WHEN 'happy_horse' THEN JSON_ARRAY(3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15)
            WHEN 'seedance' THEN JSON_ARRAY(4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15)
            WHEN 'wan' THEN JSON_ARRAY(2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15)
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
  AND `code` IN ('happy_horse', 'seedance', 'wan');

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT
    0,
    'happy_horse',
    CONCAT(LOWER(template.`resolution`), '_', duration.`duration`),
    CONCAT(template.`resolution`, ' · ', duration.`duration`, '秒'),
    template.`ratio`,
    template.`width`,
    template.`height`,
    ROUND(duration.`duration` * CASE template.`resolution` WHEN '1080P' THEN 0.056 ELSE 0.028 END, 2),
    ROUND(duration.`duration` * CASE template.`resolution` WHEN '1080P' THEN 0.056 ELSE 0.028 END, 2),
    CONCAT('{"resolution":"', template.`resolution`, '","duration":', duration.`duration`, ',"ratio":"', template.`ratio`, '"}'),
    1,
    1300 - duration.`duration` * 10 - template.`sort_offset`,
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
    SELECT 3 AS `duration` UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
    UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10
    UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14
    UNION ALL SELECT 15
) AS duration
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
    1250 - duration.`duration` - template.`sort_offset`,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
FROM (
    SELECT '480p' AS `resolution`, '16:9' AS `ratio`, 854 AS `width`, 480 AS `height`, 0 AS `sort_offset` UNION ALL
    SELECT '480p', '4:3', 640, 480, 10 UNION ALL
    SELECT '480p', '1:1', 480, 480, 20 UNION ALL
    SELECT '480p', '3:4', 480, 640, 30 UNION ALL
    SELECT '480p', '9:16', 480, 854, 40 UNION ALL
    SELECT '480p', '21:9', 1120, 480, 50 UNION ALL
    SELECT '480p', 'adaptive', 0, 0, 60 UNION ALL
    SELECT '720p', '16:9', 1280, 720, 100 UNION ALL
    SELECT '720p', '4:3', 960, 720, 110 UNION ALL
    SELECT '720p', '1:1', 720, 720, 120 UNION ALL
    SELECT '720p', '3:4', 720, 960, 130 UNION ALL
    SELECT '720p', '9:16', 720, 1280, 140 UNION ALL
    SELECT '720p', '21:9', 1680, 720, 150 UNION ALL
    SELECT '720p', 'adaptive', 0, 0, 160 UNION ALL
    SELECT '1080p', '16:9', 1920, 1080, 200 UNION ALL
    SELECT '1080p', '4:3', 1440, 1080, 210 UNION ALL
    SELECT '1080p', '1:1', 1080, 1080, 220 UNION ALL
    SELECT '1080p', '3:4', 1080, 1440, 230 UNION ALL
    SELECT '1080p', '9:16', 1080, 1920, 240 UNION ALL
    SELECT '1080p', '21:9', 2520, 1080, 250 UNION ALL
    SELECT '1080p', 'adaptive', 0, 0, 260
) AS template
CROSS JOIN (
    SELECT 4 AS `duration` UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
    UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11
    UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15
) AS duration
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
    1200 - duration.`duration` - template.`sort_offset`,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
FROM (
    SELECT '720p' AS `resolution`, '16:9' AS `ratio`, 1280 AS `width`, 720 AS `height`, 0 AS `sort_offset` UNION ALL
    SELECT '720p', '9:16', 720, 1280, 10 UNION ALL
    SELECT '720p', '1:1', 720, 720, 20 UNION ALL
    SELECT '720p', '4:3', 960, 720, 30 UNION ALL
    SELECT '720p', '3:4', 720, 960, 40 UNION ALL
    SELECT '1080p', '16:9', 1920, 1080, 100 UNION ALL
    SELECT '1080p', '9:16', 1080, 1920, 110 UNION ALL
    SELECT '1080p', '1:1', 1080, 1080, 120 UNION ALL
    SELECT '1080p', '4:3', 1440, 1080, 130 UNION ALL
    SELECT '1080p', '3:4', 1080, 1440, 140
) AS template
CROSS JOIN (
    SELECT 2 AS `duration` UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
    UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
    UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13
    UNION ALL SELECT 14 UNION ALL SELECT 15
) AS duration
WHERE 1 = 1
ON DUPLICATE KEY UPDATE
    `quality_label`=IF(`quality_label` IS NULL OR `quality_label` = '', VALUES(`quality_label`), `quality_label`),
    `width`=IF(`width` <= 0, VALUES(`width`), `width`),
    `height`=IF(`height` <= 0, VALUES(`height`), `height`),
    `provider_params_json`=IF(`provider_params_json` IS NULL OR `provider_params_json` = '' OR `provider_params_json` = '{}', VALUES(`provider_params_json`), `provider_params_json`),
    `status`=`status`,
    `sort`=IF(`sort` <= 0, VALUES(`sort`), `sort`),
    `update_time`=`update_time`;
