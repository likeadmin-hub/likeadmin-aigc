UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_INSERT(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.duration_options',
        JSON_ARRAY(4, 6, 8, 10)
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `code` = 'omni_flash_ext';

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT
    0,
    'omni_flash_ext',
    CONCAT(template.`resolution`, '_', duration.`duration`),
    CONCAT(UPPER(template.`resolution`), ' · ', duration.`duration`, '秒'),
    template.`ratio`,
    template.`width`,
    template.`height`,
    0.00,
    0.00,
    CONCAT('{"resolution":"', template.`resolution`, '","duration":', duration.`duration`, ',"aspect_ratio":"', template.`ratio`, '"}'),
    1,
    1200 - duration.`duration` * 10 - template.`sort_offset`,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
FROM (
    SELECT '720p' AS `resolution`, '16:9' AS `ratio`, 1280 AS `width`, 720 AS `height`, 1 AS `sort_offset`
    UNION ALL SELECT '720p', '9:16', 720, 1280, 2
    UNION ALL SELECT '720p', '1:1', 720, 720, 3
    UNION ALL SELECT '1080p', '16:9', 1920, 1080, 4
    UNION ALL SELECT '1080p', '9:16', 1080, 1920, 5
) AS template
CROSS JOIN (
    SELECT 4 AS `duration`
    UNION ALL SELECT 6
    UNION ALL SELECT 8
    UNION ALL SELECT 10
) AS duration
WHERE 1 = 1
ON DUPLICATE KEY UPDATE
    `quality_label` = IF(`quality_label` IS NULL OR `quality_label` = '', VALUES(`quality_label`), `quality_label`),
    `width` = IF(`width` <= 0, VALUES(`width`), `width`),
    `height` = IF(`height` <= 0, VALUES(`height`), `height`),
    `provider_params_json` = IF(`provider_params_json` IS NULL OR `provider_params_json` = '' OR `provider_params_json` = '{}', VALUES(`provider_params_json`), `provider_params_json`),
    `status` = `status`,
    `sort` = IF(`sort` <= 0, VALUES(`sort`), `sort`),
    `update_time` = `update_time`;
