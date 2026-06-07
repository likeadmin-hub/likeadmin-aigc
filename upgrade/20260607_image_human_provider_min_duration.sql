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
  ''$.provider'',
  COALESCE(JSON_EXTRACT(`config_json`, ''$.provider''), JSON_OBJECT(''submit_path'', ''/api/v1/apps/image_human/submit'', ''query_path'', ''/api/v1/apps/image_human/query'', ''timeout'', 60, ''min_duration'', 2)),
  ''$.provider.min_duration'',
  COALESCE(JSON_EXTRACT(`config_json`, ''$.provider.min_duration''), 2)
),
`update_time` = UNIX_TIMESTAMP()',
  'SELECT 1'
);
PREPARE image_human_stmt FROM @image_human_sql;
EXECUTE image_human_stmt;
DEALLOCATE PREPARE image_human_stmt;
