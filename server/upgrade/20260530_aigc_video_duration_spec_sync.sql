UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_INSERT(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.duration_options',
        CASE `code`
            WHEN 'grok_video_xaiq' THEN JSON_ARRAY(6, 10, 15, 20, 25, 30)
            WHEN 'happy_horse' THEN JSON_ARRAY(3, 5, 10, 15)
            WHEN 'wan' THEN JSON_ARRAY(2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15)
            WHEN 'seedance' THEN JSON_ARRAY(3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15)
            WHEN 'omni_flash_ext' THEN JSON_ARRAY(4, 6, 8, 10)
            ELSE JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.duration_options')
        END
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `code` IN ('grok_video_xaiq', 'happy_horse', 'wan', 'seedance', 'omni_flash_ext');

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
    1000 - duration.`duration`,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
FROM (
    SELECT DISTINCT
        COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`provider_params_json`, '$.resolution')), ''), '720p') AS `resolution`,
        `ratio`,
        `width`,
        `height`
    FROM `la_aigc_video_channel_spec`
    WHERE `tenant_id` = 0 AND `channel_code` = 'wan'
) AS template
CROSS JOIN (
    SELECT 2 AS `duration` UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5
    UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
    UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13
    UNION ALL SELECT 14 UNION ALL SELECT 15
) AS duration
WHERE 1 = 1
ON DUPLICATE KEY UPDATE
    `quality_label` = IF(`quality_label` IS NULL OR `quality_label` = '', VALUES(`quality_label`), `quality_label`),
    `provider_params_json` = IF(`provider_params_json` IS NULL OR `provider_params_json` = '' OR `provider_params_json` = '{}', VALUES(`provider_params_json`), `provider_params_json`),
    `update_time` = `update_time`;

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
    1000 - duration.`duration`,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
FROM (
    SELECT DISTINCT
        COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`provider_params_json`, '$.resolution')), ''), '720p') AS `resolution`,
        `ratio`,
        `width`,
        `height`
    FROM `la_aigc_video_channel_spec`
    WHERE `tenant_id` = 0 AND `channel_code` = 'seedance'
) AS template
CROSS JOIN (
    SELECT 3 AS `duration` UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
    UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10
    UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14
    UNION ALL SELECT 15
) AS duration
WHERE 1 = 1
ON DUPLICATE KEY UPDATE
    `quality_label` = IF(`quality_label` IS NULL OR `quality_label` = '', VALUES(`quality_label`), `quality_label`),
    `provider_params_json` = IF(`provider_params_json` IS NULL OR `provider_params_json` = '' OR `provider_params_json` = '{}', VALUES(`provider_params_json`), `provider_params_json`),
    `update_time` = `update_time`;

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
    1000 - duration.`duration`,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
FROM (
    SELECT DISTINCT
        COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(`provider_params_json`, '$.resolution')), ''), '720p') AS `resolution`,
        `ratio`,
        `width`,
        `height`
    FROM `la_aigc_video_channel_spec`
    WHERE `tenant_id` = 0 AND `channel_code` = 'omni_flash_ext'
) AS template
CROSS JOIN (
    SELECT 4 AS `duration` UNION ALL SELECT 6 UNION ALL SELECT 8 UNION ALL SELECT 10
) AS duration
WHERE 1 = 1
ON DUPLICATE KEY UPDATE
    `quality_label` = IF(`quality_label` IS NULL OR `quality_label` = '', VALUES(`quality_label`), `quality_label`),
    `provider_params_json` = IF(`provider_params_json` IS NULL OR `provider_params_json` = '' OR `provider_params_json` = '{}', VALUES(`provider_params_json`), `provider_params_json`),
    `update_time` = `update_time`;
