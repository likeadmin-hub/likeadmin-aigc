UPDATE `la_aigc_digital_human_channel` c
JOIN `la_aigc_digital_human_config` cfg
  ON cfg.`tenant_id` = 0
SET c.`provider` = cfg.`provider`,
    c.`model` = cfg.`model`,
    c.`config_json` = JSON_INSERT(
        CASE
            WHEN JSON_VALID(COALESCE(NULLIF(c.`config_json`, ''), '{}'))
              AND JSON_TYPE(CAST(COALESCE(NULLIF(c.`config_json`, ''), '{}') AS JSON)) = 'OBJECT'
            THEN CAST(COALESCE(NULLIF(c.`config_json`, ''), '{}') AS JSON)
            ELSE JSON_OBJECT()
        END,
        '$.lipsync_model',
        cfg.`model`
    ),
    c.`update_time` = UNIX_TIMESTAMP()
WHERE c.`tenant_id` = 0
  AND cfg.`model` <> ''
  AND cfg.`provider` <> '';
