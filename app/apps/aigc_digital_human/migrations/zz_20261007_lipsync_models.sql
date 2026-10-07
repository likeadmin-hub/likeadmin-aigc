-- Preserve prices, availability, custom providers/models and historical tasks.
UPDATE `la_aigc_digital_human_channel`
SET `config_json` = JSON_SET(
        CASE
            WHEN JSON_VALID(COALESCE(NULLIF(`config_json`, ''), '{}'))
              AND JSON_TYPE(IF(JSON_VALID(COALESCE(NULLIF(`config_json`, ''), '{}')), COALESCE(NULLIF(`config_json`, ''), '{}'), '{}')) = 'OBJECT'
            THEN COALESCE(NULLIF(`config_json`, ''), '{}')
            ELSE JSON_OBJECT()
        END,
        '$.lipsync_model',
        CASE `code`
            WHEN 'master' THEN 'xiaojiayu1.0'
            WHEN 'all' THEN 'xiaojiayu2.0'
            WHEN 'free' THEN 'xiaojiayu3.0'
        END
    ),
    `model` = CASE `code`
        WHEN 'master' THEN 'xiaojiayu1.0'
        WHEN 'all' THEN 'xiaojiayu2.0'
        WHEN 'free' THEN 'xiaojiayu3.0'
    END,
    `update_time` = UNIX_TIMESTAMP()
WHERE `provider` = 'xhadmin'
  AND `code` IN ('master', 'all', 'free')
  AND `model` IN ('', 'mock-digital-human', 'xiaojiayu1.0', 'xiaojiayu2.0', 'xiaojiayu3.0');
