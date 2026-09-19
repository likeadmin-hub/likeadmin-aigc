INSERT INTO `la_aigc_digital_human_config` (`tenant_id`,`provider_mode`,`provider`,`model`,`config_json`,`status`,`create_time`,`update_time`)
SELECT
  0,
  'platform',
  'xhadmin',
  'xiaojiayu1.0',
  JSON_OBJECT(
    'pricing',
    JSON_OBJECT(
      'generate_models',
      JSON_ARRAYAGG(
        JSON_OBJECT(
          'code',
          `code`,
          'platform_unit_cost',
          COALESCE(JSON_UNQUOTE(JSON_EXTRACT((SELECT `config_json` FROM `la_aigc_digital_human_config` WHERE `tenant_id` = 0 LIMIT 1), '$.pricing.generate.platform_unit_cost')), '0.20'),
          'tenant_unit_price',
          COALESCE(JSON_UNQUOTE(JSON_EXTRACT((SELECT `config_json` FROM `la_aigc_digital_human_config` WHERE `tenant_id` = 0 LIMIT 1), '$.pricing.generate.tenant_unit_price')), '0.30')
        )
      ),
      'avatar_clone',
      COALESCE(JSON_EXTRACT((SELECT `config_json` FROM `la_aigc_digital_human_config` WHERE `tenant_id` = 0 LIMIT 1), '$.pricing.avatar_clone'), JSON_OBJECT('platform_unit_cost', 2.00, 'tenant_unit_price', 3.00)),
      'voice_clone',
      COALESCE(JSON_EXTRACT((SELECT `config_json` FROM `la_aigc_digital_human_config` WHERE `tenant_id` = 0 LIMIT 1), '$.pricing.voice_clone'), JSON_OBJECT('platform_unit_cost', 1.00, 'tenant_unit_price', 2.00))
    )
  ),
  1,
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
FROM `la_aigc_digital_human_channel`
WHERE `tenant_id` = 0
ON DUPLICATE KEY UPDATE
`la_aigc_digital_human_config`.`config_json` = JSON_INSERT(
  COALESCE(NULLIF(`la_aigc_digital_human_config`.`config_json`, ''), '{}'),
  '$.pricing.generate_models',
  COALESCE(
    JSON_EXTRACT(`la_aigc_digital_human_config`.`config_json`, '$.pricing.generate_models'),
    JSON_EXTRACT(VALUES(`config_json`), '$.pricing.generate_models')
  )
),
`la_aigc_digital_human_config`.`update_time` = UNIX_TIMESTAMP();
