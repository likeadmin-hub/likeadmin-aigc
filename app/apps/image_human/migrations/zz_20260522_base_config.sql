UPDATE `la_image_human_config`
SET `config_json` = JSON_SET(
  COALESCE(NULLIF(`config_json`, ''), '{}'),
  '$.base_config',
  COALESCE(JSON_EXTRACT(`config_json`, '$.base_config'), JSON_OBJECT('script_max_length', 200, 'prompt_max_length', 200))
),
`update_time` = UNIX_TIMESTAMP()
WHERE JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.base_config') IS NULL;

UPDATE `la_image_human_config`
SET `config_json` = JSON_SET(
  COALESCE(NULLIF(`config_json`, ''), '{}'),
  '$.base_config.script_max_length',
  COALESCE(JSON_EXTRACT(`config_json`, '$.base_config.script_max_length'), JSON_EXTRACT(`config_json`, '$.base_config.prompt_max_length'), 200),
  '$.base_config.prompt_max_length',
  COALESCE(JSON_EXTRACT(`config_json`, '$.base_config.prompt_max_length'), 200)
),
`update_time` = UNIX_TIMESTAMP();
