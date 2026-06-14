UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_SET(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.poll_interval', 2,
        '$.poll_attempts',
        CASE `code`
            WHEN 'grok_video_xaiq' THEN 30
            WHEN 'omni_flash_ext' THEN 0
            ELSE JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.poll_attempts')
        END,
        '$.quantity_options', JSON_ARRAY(1),
        '$.duration_options',
        CASE `code`
            WHEN 'grok_video_xaiq' THEN JSON_ARRAY(6, 10, 15, 20, 25, 30)
            WHEN 'omni_flash_ext' THEN JSON_ARRAY(4, 6, 8, 10)
            ELSE JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.duration_options')
        END,
        '$.supported_asset_types', JSON_ARRAY('image'),
        '$.max_reference_images',
        CASE `code`
            WHEN 'grok_video_xaiq' THEN 7
            WHEN 'omni_flash_ext' THEN 3
            ELSE JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.max_reference_images')
        END,
        '$.max_reference_assets',
        CASE `code`
            WHEN 'grok_video_xaiq' THEN 7
            WHEN 'omni_flash_ext' THEN 3
            ELSE JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.max_reference_assets')
        END
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `code` IN ('grok_video_xaiq', 'omni_flash_ext');

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_SET(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.quality', '720p'
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `code` = 'grok_video_xaiq';

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_SET(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.app_code', 'omni_flash_ext',
        '$.pricing_api_code', 'create',
        '$.api_code', 'create',
        '$.submit_path', '/api/v1/apps/omni_flash_ext/create',
        '$.task_path', '/api/v1/tasks/{task_id}'
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `code` = 'omni_flash_ext';

UPDATE `la_aigc_video_channel_spec`
SET `tenant_unit_price` = `platform_unit_cost`,
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `channel_code` = 'omni_flash_ext'
  AND `tenant_unit_price` <= 0
  AND `platform_unit_cost` > 0;
