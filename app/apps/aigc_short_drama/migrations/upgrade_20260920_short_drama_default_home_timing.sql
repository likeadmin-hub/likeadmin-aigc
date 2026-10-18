-- Apply the product defaults to existing tenant configurations on upgrade.
-- Existing duration bounds are preserved; only the default strategy is enabled.
UPDATE `la_aigc_short_drama_config`
SET `config_json` = JSON_SET(
    IF(JSON_VALID(`config_json`), `config_json`, JSON_OBJECT()),
    '$.home_style', 'imagine',
    '$.episode_duration_rule',
    IF(
        JSON_TYPE(JSON_EXTRACT(IF(JSON_VALID(`config_json`), `config_json`, JSON_OBJECT()), '$.episode_duration_rule')) = 'OBJECT',
        JSON_SET(
            JSON_EXTRACT(IF(JSON_VALID(`config_json`), `config_json`, JSON_OBJECT()), '$.episode_duration_rule'),
            '$.enabled', TRUE
        ),
        JSON_OBJECT(
            'enabled', TRUE,
            'target_seconds', 120,
            'min_seconds', 110,
            'max_seconds', 130
        )
    )
),
`update_time` = UNIX_TIMESTAMP();
