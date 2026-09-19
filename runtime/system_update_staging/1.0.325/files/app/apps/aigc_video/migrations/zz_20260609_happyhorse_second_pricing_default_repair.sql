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
SET `sort` = IF(`sort` <= 0, 410, `sort`),
    `config_json` = JSON_INSERT(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.app_code', 'happy_horse',
        '$.pricing_api_code', 'submit',
        '$.api_code', 'submit',
        '$.submit_path', '/api/v1/apps/happy_horse/submit',
        '$.query_path', '/api/v1/apps/happy_horse/query',
        '$.duration_options', JSON_ARRAY(3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15),
        '$.videoedit_duration_options', JSON_ARRAY(3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15),
        '$.quantity_options', JSON_ARRAY(1),
        '$.supported_asset_types', JSON_ARRAY('image', 'video'),
        '$.max_reference_images', 9,
        '$.max_reference_videos', 1,
        '$.max_reference_assets', 10
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `code` = 'happy_horse';

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`upstream_unit_cost`,`platform_unit_cost`,`tenant_unit_price`,`upstream_cost_text`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT 0,'happy_horse',LOWER(t.`resolution`),t.`resolution`,t.`ratio`,t.`width`,t.`height`,t.`second_rate`,t.`second_rate`,t.`second_rate`,CONCAT(t.`resolution`, ' 上游秒单价，点 / 秒'),CONCAT('{"resolution":"', t.`resolution`, '","ratio":"', t.`ratio`, '"}'),1,1500 - t.`sort_offset`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (
    SELECT '720P' AS `resolution`, '16:9' AS `ratio`, 1280 AS `width`, 720 AS `height`, 0.0280 AS `second_rate`, 0 AS `sort_offset` UNION ALL
    SELECT '720P', '9:16', 720, 1280, 0.0280, 10 UNION ALL
    SELECT '720P', '1:1', 720, 720, 0.0280, 20 UNION ALL
    SELECT '720P', '4:3', 960, 720, 0.0280, 30 UNION ALL
    SELECT '720P', '3:4', 720, 960, 0.0280, 40 UNION ALL
    SELECT '1080P', '16:9', 1920, 1080, 0.0560, 200 UNION ALL
    SELECT '1080P', '9:16', 1080, 1920, 0.0560, 210 UNION ALL
    SELECT '1080P', '1:1', 1080, 1080, 0.0560, 220 UNION ALL
    SELECT '1080P', '4:3', 1440, 1080, 0.0560, 230 UNION ALL
    SELECT '1080P', '3:4', 1080, 1440, 0.0560, 240
) AS t
WHERE @video_spec_table_exists > 0
ON DUPLICATE KEY UPDATE
    `la_aigc_video_channel_spec`.`quality_label`=IF(`la_aigc_video_channel_spec`.`quality_label` IS NULL OR `la_aigc_video_channel_spec`.`quality_label` = '', VALUES(`quality_label`), `la_aigc_video_channel_spec`.`quality_label`),
    `la_aigc_video_channel_spec`.`width`=IF(`la_aigc_video_channel_spec`.`width` <= 0, VALUES(`width`), `la_aigc_video_channel_spec`.`width`),
    `la_aigc_video_channel_spec`.`height`=IF(`la_aigc_video_channel_spec`.`height` <= 0, VALUES(`height`), `la_aigc_video_channel_spec`.`height`),
    `la_aigc_video_channel_spec`.`upstream_unit_cost`=IF(`la_aigc_video_channel_spec`.`upstream_unit_cost` <= 0, VALUES(`upstream_unit_cost`), `la_aigc_video_channel_spec`.`upstream_unit_cost`),
    `la_aigc_video_channel_spec`.`platform_unit_cost`=IF(`la_aigc_video_channel_spec`.`platform_unit_cost` <= 0, VALUES(`platform_unit_cost`), `la_aigc_video_channel_spec`.`platform_unit_cost`),
    `la_aigc_video_channel_spec`.`tenant_unit_price`=IF(`la_aigc_video_channel_spec`.`tenant_unit_price` <= 0, VALUES(`tenant_unit_price`), `la_aigc_video_channel_spec`.`tenant_unit_price`),
    `la_aigc_video_channel_spec`.`upstream_cost_text`=IF(`la_aigc_video_channel_spec`.`upstream_cost_text` IS NULL OR `la_aigc_video_channel_spec`.`upstream_cost_text` = '', VALUES(`upstream_cost_text`), `la_aigc_video_channel_spec`.`upstream_cost_text`),
    `la_aigc_video_channel_spec`.`provider_params_json`=IF(`la_aigc_video_channel_spec`.`provider_params_json` IS NULL OR `la_aigc_video_channel_spec`.`provider_params_json` = '' OR `la_aigc_video_channel_spec`.`provider_params_json` = '{}', VALUES(`provider_params_json`), `la_aigc_video_channel_spec`.`provider_params_json`),
    `la_aigc_video_channel_spec`.`status`=`la_aigc_video_channel_spec`.`status`,
    `la_aigc_video_channel_spec`.`sort`=IF(`la_aigc_video_channel_spec`.`sort` <= 0, VALUES(`sort`), `la_aigc_video_channel_spec`.`sort`),
    `la_aigc_video_channel_spec`.`update_time`=`la_aigc_video_channel_spec`.`update_time`;

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`upstream_unit_cost`,`platform_unit_cost`,`tenant_unit_price`,`upstream_cost_text`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT g.`tenant_id`,'happy_horse',LOWER(g.`resolution`),g.`resolution`,g.`ratio`,MAX(g.`width`),MAX(g.`height`),MAX(g.`second_rate`),MAX(g.`second_rate`),ROUND(AVG(g.`tenant_second_price`), 4),CONCAT(g.`resolution`, ' 上游秒单价，点 / 秒'),CONCAT('{"resolution":"', g.`resolution`, '","ratio":"', g.`ratio`, '"}'),1,MAX(g.`sort`),UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (
    SELECT s.`tenant_id`,
           CASE UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(s.`provider_params_json`, ''), '{}'), '$.resolution')))
               WHEN '1080P' THEN '1080P'
               ELSE '720P'
           END AS `resolution`,
           s.`ratio`,
           s.`width`,
           s.`height`,
           CASE UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(s.`provider_params_json`, ''), '{}'), '$.resolution')))
               WHEN '1080P' THEN 0.0560
               ELSE 0.0280
           END AS `second_rate`,
           CASE
               WHEN CAST(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(s.`provider_params_json`, ''), '{}'), '$.duration')) AS UNSIGNED) > 0
                    AND s.`tenant_unit_price` > (CASE UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(s.`provider_params_json`, ''), '{}'), '$.resolution'))) WHEN '1080P' THEN 0.0560 ELSE 0.0280 END) * 1.5
               THEN s.`tenant_unit_price` / CAST(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(s.`provider_params_json`, ''), '{}'), '$.duration')) AS UNSIGNED)
               WHEN s.`tenant_unit_price` <= 0 THEN CASE UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(s.`provider_params_json`, ''), '{}'), '$.resolution'))) WHEN '1080P' THEN 0.0560 ELSE 0.0280 END
               ELSE s.`tenant_unit_price`
           END AS `tenant_second_price`,
           s.`sort`
    FROM `la_aigc_video_channel_spec` AS s
    WHERE @video_spec_table_exists > 0
      AND s.`tenant_id` > 0
      AND s.`channel_code` = 'happy_horse'
      AND (s.`quality` NOT IN ('720p', '1080p') OR JSON_EXTRACT(COALESCE(NULLIF(s.`provider_params_json`, ''), '{}'), '$.duration') IS NOT NULL)
) AS g
GROUP BY g.`tenant_id`, g.`resolution`, g.`ratio`
ON DUPLICATE KEY UPDATE
    `la_aigc_video_channel_spec`.`quality_label`=IF(`la_aigc_video_channel_spec`.`quality_label` IS NULL OR `la_aigc_video_channel_spec`.`quality_label` = '', VALUES(`quality_label`), `la_aigc_video_channel_spec`.`quality_label`),
    `la_aigc_video_channel_spec`.`width`=IF(`la_aigc_video_channel_spec`.`width` <= 0, VALUES(`width`), `la_aigc_video_channel_spec`.`width`),
    `la_aigc_video_channel_spec`.`height`=IF(`la_aigc_video_channel_spec`.`height` <= 0, VALUES(`height`), `la_aigc_video_channel_spec`.`height`),
    `la_aigc_video_channel_spec`.`upstream_unit_cost`=IF(`la_aigc_video_channel_spec`.`upstream_unit_cost` <= 0, VALUES(`upstream_unit_cost`), `la_aigc_video_channel_spec`.`upstream_unit_cost`),
    `la_aigc_video_channel_spec`.`platform_unit_cost`=IF(`la_aigc_video_channel_spec`.`platform_unit_cost` <= 0, VALUES(`platform_unit_cost`), `la_aigc_video_channel_spec`.`platform_unit_cost`),
    `la_aigc_video_channel_spec`.`tenant_unit_price`=IF(`la_aigc_video_channel_spec`.`tenant_unit_price` <= 0, VALUES(`tenant_unit_price`), `la_aigc_video_channel_spec`.`tenant_unit_price`),
    `la_aigc_video_channel_spec`.`upstream_cost_text`=IF(`la_aigc_video_channel_spec`.`upstream_cost_text` IS NULL OR `la_aigc_video_channel_spec`.`upstream_cost_text` = '', VALUES(`upstream_cost_text`), `la_aigc_video_channel_spec`.`upstream_cost_text`),
    `la_aigc_video_channel_spec`.`provider_params_json`=IF(`la_aigc_video_channel_spec`.`provider_params_json` IS NULL OR `la_aigc_video_channel_spec`.`provider_params_json` = '' OR `la_aigc_video_channel_spec`.`provider_params_json` = '{}', VALUES(`provider_params_json`), `la_aigc_video_channel_spec`.`provider_params_json`),
    `la_aigc_video_channel_spec`.`status`=`la_aigc_video_channel_spec`.`status`,
    `la_aigc_video_channel_spec`.`sort`=IF(`la_aigc_video_channel_spec`.`sort` <= 0, VALUES(`sort`), `la_aigc_video_channel_spec`.`sort`),
    `la_aigc_video_channel_spec`.`update_time`=`la_aigc_video_channel_spec`.`update_time`;

UPDATE `la_aigc_video_channel_spec`
SET `status` = `status`,
    `update_time` = `update_time`
WHERE @video_spec_table_exists > 0
  AND `channel_code` = 'happy_horse'
  AND (`quality` NOT IN ('720p', '1080p') OR JSON_EXTRACT(COALESCE(NULLIF(`provider_params_json`, ''), '{}'), '$.duration') IS NOT NULL);
