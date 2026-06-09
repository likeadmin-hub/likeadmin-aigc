INSERT INTO `la_aigc_digital_human_config` (`tenant_id`,`provider_mode`,`provider`,`model`,`config_json`,`status`,`create_time`,`update_time`)
VALUES (
  0,
  'platform',
  'xhadmin',
  'xiaojiayu1.0',
  JSON_OBJECT(
    'pricing',
    JSON_OBJECT(
      'generate',
      JSON_OBJECT(
        'platform_unit_cost',
        COALESCE((SELECT `platform_unit_cost` FROM `la_aigc_digital_human_channel_spec` WHERE `tenant_id` = 0 AND `status` = `status` ORDER BY `sort` DESC, `id` ASC LIMIT 1), 0.20),
        'tenant_unit_price',
        COALESCE((SELECT `tenant_unit_price` FROM `la_aigc_digital_human_channel_spec` WHERE `tenant_id` = 0 AND `status` = `status` ORDER BY `sort` DESC, `id` ASC LIMIT 1), 0.30)
      ),
      'avatar_clone',
      JSON_OBJECT('platform_unit_cost', 2.00, 'tenant_unit_price', 3.00),
      'voice_clone',
      JSON_OBJECT('platform_unit_cost', 1.00, 'tenant_unit_price', 2.00)
    )
  ),
  1,
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
)
ON DUPLICATE KEY UPDATE
`config_json` = JSON_INSERT(
  COALESCE(NULLIF(`config_json`, ''), '{}'),
  '$.pricing.generate',
  COALESCE(
    JSON_EXTRACT(`config_json`, '$.pricing.generate'),
    JSON_OBJECT(
      'platform_unit_cost',
      COALESCE((SELECT `platform_unit_cost` FROM `la_aigc_digital_human_channel_spec` WHERE `tenant_id` = 0 AND `status` = `status` ORDER BY `sort` DESC, `id` ASC LIMIT 1), 0.20),
      'tenant_unit_price',
      COALESCE((SELECT `tenant_unit_price` FROM `la_aigc_digital_human_channel_spec` WHERE `tenant_id` = 0 AND `status` = `status` ORDER BY `sort` DESC, `id` ASC LIMIT 1), 0.30)
    )
  ),
  '$.pricing.avatar_clone',
  COALESCE(JSON_EXTRACT(`config_json`, '$.pricing.avatar_clone'), JSON_OBJECT('platform_unit_cost', 2.00, 'tenant_unit_price', 3.00)),
  '$.pricing.voice_clone',
  COALESCE(JSON_EXTRACT(`config_json`, '$.pricing.voice_clone'), JSON_OBJECT('platform_unit_cost', 1.00, 'tenant_unit_price', 2.00))
),
`update_time` = UNIX_TIMESTAMP();
