SET @image_human_config_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'la_image_human_config'
);

SET @image_human_sql = IF(
  @image_human_config_exists > 0,
  'UPDATE `la_image_human_config`
SET `config_json` = JSON_SET(
  COALESCE(NULLIF(`config_json`, ''''), ''{}''),
  ''$.base_config'',
  COALESCE(JSON_EXTRACT(`config_json`, ''$.base_config''), JSON_OBJECT(''script_max_length'', 200, ''prompt_max_length'', 200))
),
`update_time` = UNIX_TIMESTAMP()
WHERE JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''''), ''{}''), ''$.base_config'') IS NULL',
  'SELECT 1'
);
PREPARE image_human_stmt FROM @image_human_sql;
EXECUTE image_human_stmt;
DEALLOCATE PREPARE image_human_stmt;

SET @image_human_sql = IF(
  @image_human_config_exists > 0,
  'UPDATE `la_image_human_config`
SET `config_json` = JSON_SET(
  COALESCE(NULLIF(`config_json`, ''''), ''{}''),
  ''$.base_config.script_max_length'',
  COALESCE(JSON_EXTRACT(`config_json`, ''$.base_config.script_max_length''), JSON_EXTRACT(`config_json`, ''$.base_config.prompt_max_length''), 200),
  ''$.base_config.prompt_max_length'',
  COALESCE(JSON_EXTRACT(`config_json`, ''$.base_config.prompt_max_length''), 200)
),
`update_time` = UNIX_TIMESTAMP()',
  'SELECT 1'
);
PREPARE image_human_stmt FROM @image_human_sql;
EXECUTE image_human_stmt;
DEALLOCATE PREPARE image_human_stmt;
