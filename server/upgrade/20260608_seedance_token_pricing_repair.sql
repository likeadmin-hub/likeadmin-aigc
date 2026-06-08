UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_SET(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.duration_options', JSON_ARRAY(4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15),
        '$.supported_asset_types', JSON_ARRAY('image', 'video', 'audio'),
        '$.max_reference_images', 9,
        '$.max_reference_videos', 3,
        '$.max_reference_audios', 3,
        '$.max_reference_assets', 15
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `code` = 'seedance';

UPDATE `la_aigc_video_channel_spec`
SET `status` = 0,
    `update_time` = UNIX_TIMESTAMP()
WHERE `channel_code` = 'seedance'
  AND JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(`provider_params_json`, ''), '{}'), '$._pricing_variant')) IS NULL;

INSERT INTO `la_aigc_video_channel_spec` (
    `tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,
    `upstream_unit_cost`,`platform_unit_cost`,`tenant_unit_price`,`upstream_cost_text`,`cost_source_url`,
    `provider_params_json`,`status`,`sort`,`create_time`,`update_time`
)
SELECT
    0,
    'seedance',
    CONCAT(template.`resolution`, '_', variant.`variant`),
    CONCAT(UPPER(template.`resolution`), ' / ', variant.`label`),
    template.`ratio`,
    template.`width`,
    template.`height`,
    variant.`upstream_cost`,
    GREATEST(
        COALESCE((
            SELECT MAX(old_spec.`platform_unit_cost`)
            FROM `la_aigc_video_channel_spec` old_spec
            WHERE old_spec.`tenant_id` = 0
              AND old_spec.`channel_code` = 'seedance'
              AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(old_spec.`provider_params_json`, ''), '{}'), '$.resolution'))) = template.`resolution`
        ), 0),
        variant.`upstream_cost`
    ),
    GREATEST(
        COALESCE((
            SELECT MAX(old_spec.`tenant_unit_price`)
            FROM `la_aigc_video_channel_spec` old_spec
            WHERE old_spec.`tenant_id` = 0
              AND old_spec.`channel_code` = 'seedance'
              AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(old_spec.`provider_params_json`, ''), '{}'), '$.resolution'))) = template.`resolution`
        ), 0),
        variant.`upstream_cost`
    ),
    CONCAT(UPPER(template.`resolution`), ' / ', variant.`label`, '，点 / 百万 Token'),
    '',
    CONCAT(
        '{"resolution":"', template.`resolution`,
        '","ratio":"', template.`ratio`,
        '","_pricing_variant":"', variant.`variant`, '"}'
    ),
    1,
    1800 - template.`resolution_sort` - variant.`variant_sort` - template.`ratio_sort`,
    UNIX_TIMESTAMP(),
    UNIX_TIMESTAMP()
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
) AS template
JOIN (
    SELECT 'with_video' AS `variant`, '含视频输入' AS `label`, 1 AS `variant_sort`, '480p' AS `resolution`, 3000.00 AS `upstream_cost` UNION ALL
    SELECT 'without_video', '不含视频输入', 2, '480p', 5000.00 UNION ALL
    SELECT 'with_video', '含视频输入', 1, '720p', 3200.00 UNION ALL
    SELECT 'without_video', '不含视频输入', 2, '720p', 5500.00 UNION ALL
    SELECT 'with_video', '含视频输入', 1, '1080p', 3500.00 UNION ALL
    SELECT 'without_video', '不含视频输入', 2, '1080p', 6000.00
) AS variant ON variant.`resolution` = template.`resolution`
WHERE EXISTS (
    SELECT 1 FROM `la_aigc_video_channel`
    WHERE `tenant_id` = 0 AND `code` = 'seedance'
)
ON DUPLICATE KEY UPDATE
    `quality_label` = VALUES(`quality_label`),
    `width` = VALUES(`width`),
    `height` = VALUES(`height`),
    `upstream_unit_cost` = VALUES(`upstream_unit_cost`),
    `platform_unit_cost` = GREATEST(`platform_unit_cost`, VALUES(`upstream_unit_cost`)),
    `tenant_unit_price` = GREATEST(`tenant_unit_price`, VALUES(`upstream_unit_cost`)),
    `upstream_cost_text` = VALUES(`upstream_cost_text`),
    `cost_source_url` = VALUES(`cost_source_url`),
    `provider_params_json` = VALUES(`provider_params_json`),
    `status` = 1,
    `sort` = VALUES(`sort`),
    `update_time` = VALUES(`update_time`);
