UPDATE `la_aigc_image_channel`
SET `config_json` = JSON_SET(
    CASE
        WHEN JSON_VALID(COALESCE(NULLIF(`config_json`, ''), '{}'))
            THEN COALESCE(NULLIF(`config_json`, ''), '{}')
        ELSE '{}'
    END,
    '$.quantity_options',
    JSON_ARRAY(1),
    '$.ratio_options',
    JSON_ARRAY('auto','1:1','3:2','2:3','4:3','3:4','5:4','4:5','16:9','9:16','2:1','1:2','3:1','1:3','21:9','9:21')
),
`update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `code` IN ('gpt_image_2_pro','images2_pro');

UPDATE `la_aigc_image_channel`
SET `config_json` = JSON_SET(
    CASE
        WHEN JSON_VALID(COALESCE(NULLIF(`config_json`, ''), '{}'))
            THEN COALESCE(NULLIF(`config_json`, ''), '{}')
        ELSE '{}'
    END,
    '$.quantity_options',
    JSON_ARRAY(1),
    '$.ratio_options',
    JSON_ARRAY('1:1','3:2','2:3','4:3','3:4','5:4','4:5','16:9','9:16','2:1','1:2','3:1','1:3','21:9','9:21')
),
`update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `code` IN ('gpt_image_2_fast','images2_fast');
