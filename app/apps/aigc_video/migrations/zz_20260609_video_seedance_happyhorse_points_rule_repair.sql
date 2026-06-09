SET @video_spec_table_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_channel_spec');

SET @video_sql = (SELECT IF(@video_spec_table_exists > 0 AND COUNT(*) = 0, 'ALTER TABLE `la_aigc_video_channel_spec` ADD COLUMN `upstream_unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT ''上游成本单价'' AFTER `height`', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_channel_spec' AND COLUMN_NAME = 'upstream_unit_cost');
PREPARE video_stmt FROM @video_sql;
EXECUTE video_stmt;
DEALLOCATE PREPARE video_stmt;

SET @video_sql = (SELECT IF(@video_spec_table_exists > 0 AND COUNT(*) > 0, 'ALTER TABLE `la_aigc_video_channel_spec` MODIFY COLUMN `upstream_unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT ''上游成本单价''', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_channel_spec' AND COLUMN_NAME = 'upstream_unit_cost');
PREPARE video_stmt FROM @video_sql;
EXECUTE video_stmt;
DEALLOCATE PREPARE video_stmt;

SET @video_sql = (SELECT IF(@video_spec_table_exists > 0 AND COUNT(*) > 0, 'ALTER TABLE `la_aigc_video_channel_spec` MODIFY COLUMN `platform_unit_cost` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT ''平台成本单价''', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_channel_spec' AND COLUMN_NAME = 'platform_unit_cost');
PREPARE video_stmt FROM @video_sql;
EXECUTE video_stmt;
DEALLOCATE PREPARE video_stmt;

SET @video_sql = (SELECT IF(@video_spec_table_exists > 0 AND COUNT(*) > 0, 'ALTER TABLE `la_aigc_video_channel_spec` MODIFY COLUMN `tenant_unit_price` decimal(12,4) NOT NULL DEFAULT 0.0000 COMMENT ''租户用户售价''', 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_channel_spec' AND COLUMN_NAME = 'tenant_unit_price');
PREPARE video_stmt FROM @video_sql;
EXECUTE video_stmt;
DEALLOCATE PREPARE video_stmt;

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_SET(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.duration_options', JSON_ARRAY(4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15),
        '$.quantity_options', JSON_ARRAY(1),
        '$.supported_asset_types', JSON_ARRAY('image', 'video', 'audio'),
        '$.pricing_api_code', 'create',
        '$.api_code', 'create'
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `code` = 'seedance';

UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_SET(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.duration_options', JSON_ARRAY(3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15),
        '$.videoedit_duration_options', JSON_ARRAY(3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15),
        '$.quantity_options', JSON_ARRAY(1),
        '$.supported_asset_types', JSON_ARRAY('image', 'video'),
        '$.pricing_api_code', 'submit',
        '$.api_code', 'submit'
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0 AND `code` = 'happy_horse';

UPDATE `la_aigc_video_channel_spec` AS s
JOIN (
    SELECT x.`id`,
           CAST(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(x.`provider_params_json`, ''), '{}'), '$.duration')) AS UNSIGNED) AS `duration`,
           CASE UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(x.`provider_params_json`, ''), '{}'), '$.resolution')))
               WHEN '1080P' THEN 0.0560
               ELSE 0.0280
           END AS `second_rate`,
           UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(x.`provider_params_json`, ''), '{}'), '$.resolution'))) AS `resolution`
    FROM `la_aigc_video_channel_spec` AS x
    WHERE x.`channel_code` = 'happy_horse'
) AS p ON p.`id` = s.`id`
SET s.`upstream_unit_cost` = p.`second_rate`,
    s.`platform_unit_cost` = CASE
        WHEN s.`tenant_id` = 0 THEN p.`second_rate`
        WHEN p.`duration` > 0 AND s.`platform_unit_cost` > p.`second_rate` * 1.5 THEN ROUND(s.`platform_unit_cost` / p.`duration`, 4)
        WHEN s.`platform_unit_cost` <= 0 THEN p.`second_rate`
        ELSE s.`platform_unit_cost`
    END,
    s.`tenant_unit_price` = CASE
        WHEN s.`tenant_id` = 0 THEN p.`second_rate`
        WHEN p.`duration` > 0 AND s.`tenant_unit_price` > p.`second_rate` * 1.5 THEN ROUND(s.`tenant_unit_price` / p.`duration`, 4)
        WHEN s.`tenant_unit_price` <= 0 THEN p.`second_rate`
        ELSE s.`tenant_unit_price`
    END,
    s.`upstream_cost_text` = CONCAT(COALESCE(NULLIF(p.`resolution`, ''), '720P'), ' 上游秒单价，点 / 秒'),
    s.`update_time` = UNIX_TIMESTAMP();
