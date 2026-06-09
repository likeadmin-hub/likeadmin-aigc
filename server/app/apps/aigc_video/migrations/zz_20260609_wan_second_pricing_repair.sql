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
SET `config_json` = JSON_INSERT(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.app_code', 'wan',
        '$.pricing_api_code', 'create',
        '$.api_code', 'create',
        '$.duration_options', JSON_ARRAY(2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15),
        '$.videoedit_duration_options', JSON_ARRAY(2, 3, 4, 5, 6, 7, 8, 9, 10),
        '$.quantity_options', JSON_ARRAY(1),
        '$.supported_asset_types', JSON_ARRAY('image', 'video', 'audio')
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `code` = 'wan';

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`upstream_unit_cost`,`platform_unit_cost`,`tenant_unit_price`,`upstream_cost_text`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT 0,'wan',LOWER(t.`resolution`),t.`resolution`,t.`ratio`,t.`width`,t.`height`,
       COALESCE((SELECT ROUND(AVG(NULLIF(s.`upstream_unit_cost`, 0)), 4) FROM `la_aigc_video_channel_spec` AS s WHERE s.`tenant_id` = 0 AND s.`channel_code` = 'wan' AND UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(s.`provider_params_json`, ''), '{}'), '$.resolution'))) = t.`resolution` AND s.`ratio` = t.`ratio`), t.`second_rate`),
       COALESCE((SELECT ROUND(AVG(NULLIF(s.`platform_unit_cost`, 0)), 4) FROM `la_aigc_video_channel_spec` AS s WHERE s.`tenant_id` = 0 AND s.`channel_code` = 'wan' AND UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(s.`provider_params_json`, ''), '{}'), '$.resolution'))) = t.`resolution` AND s.`ratio` = t.`ratio`), t.`second_rate`),
       COALESCE((SELECT ROUND(AVG(NULLIF(s.`tenant_unit_price`, 0)), 4) FROM `la_aigc_video_channel_spec` AS s WHERE s.`tenant_id` = 0 AND s.`channel_code` = 'wan' AND UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(s.`provider_params_json`, ''), '{}'), '$.resolution'))) = t.`resolution` AND s.`ratio` = t.`ratio`), t.`second_rate`),
       CONCAT(t.`resolution`, ' 上游秒单价，点 / 秒'),CONCAT('{"resolution":"', LOWER(t.`resolution`), '","size":"', t.`ratio`, '"}'),1,1600 - t.`sort_offset`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (
    SELECT '720P' AS `resolution`, '16:9' AS `ratio`, 1280 AS `width`, 720 AS `height`, 47.8100 AS `second_rate`, 0 AS `sort_offset` UNION ALL
    SELECT '720P', '9:16', 720, 1280, 47.8100, 10 UNION ALL
    SELECT '720P', '1:1', 720, 720, 47.8100, 20 UNION ALL
    SELECT '720P', '4:3', 960, 720, 47.8100, 30 UNION ALL
    SELECT '720P', '3:4', 720, 960, 47.8100, 40 UNION ALL
    SELECT '1080P', '16:9', 1920, 1080, 78.9100, 200 UNION ALL
    SELECT '1080P', '9:16', 1080, 1920, 78.9100, 210 UNION ALL
    SELECT '1080P', '1:1', 1080, 1080, 78.9100, 220 UNION ALL
    SELECT '1080P', '4:3', 1440, 1080, 78.9100, 230 UNION ALL
    SELECT '1080P', '3:4', 1080, 1440, 78.9100, 240
) AS t
WHERE @video_spec_table_exists > 0
ON DUPLICATE KEY UPDATE
    `quality_label`=IF(`quality_label` IS NULL OR `quality_label` = '', VALUES(`quality_label`), `quality_label`),
    `width`=IF(`width` <= 0, VALUES(`width`), `width`),
    `height`=IF(`height` <= 0, VALUES(`height`), `height`),
    `upstream_unit_cost`=IF(`upstream_unit_cost` <= 0, VALUES(`upstream_unit_cost`), `upstream_unit_cost`),
    `platform_unit_cost`=IF(`platform_unit_cost` <= 0, VALUES(`platform_unit_cost`), `platform_unit_cost`),
    `tenant_unit_price`=IF(`tenant_unit_price` <= 0, VALUES(`tenant_unit_price`), `tenant_unit_price`),
    `upstream_cost_text`=IF(`upstream_cost_text` IS NULL OR `upstream_cost_text` = '', VALUES(`upstream_cost_text`), `upstream_cost_text`),
    `provider_params_json`=IF(`provider_params_json` IS NULL OR `provider_params_json` = '' OR `provider_params_json` = '{}', VALUES(`provider_params_json`), `provider_params_json`),
    `status`=`status`,
    `sort`=IF(`sort` <= 0, VALUES(`sort`), `sort`),
    `update_time`=`update_time`;

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`upstream_unit_cost`,`platform_unit_cost`,`tenant_unit_price`,`upstream_cost_text`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT g.`tenant_id`,'wan',LOWER(g.`resolution`),g.`resolution`,g.`ratio`,MAX(g.`width`),MAX(g.`height`),MAX(g.`upstream_second_price`),MAX(g.`platform_second_price`),ROUND(AVG(g.`tenant_second_price`), 4),CONCAT(g.`resolution`, ' 上游秒单价，点 / 秒'),CONCAT('{"resolution":"', LOWER(g.`resolution`), '","size":"', g.`ratio`, '"}'),1,MAX(g.`sort`),UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (
    SELECT s.`tenant_id`,
           CASE UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(s.`provider_params_json`, ''), '{}'), '$.resolution')))
               WHEN '1080P' THEN '1080P'
               WHEN '1080' THEN '1080P'
               ELSE '720P'
           END AS `resolution`,
           s.`ratio`,
           s.`width`,
           s.`height`,
           CASE WHEN s.`upstream_unit_cost` > 0 THEN s.`upstream_unit_cost` ELSE CASE UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(s.`provider_params_json`, ''), '{}'), '$.resolution'))) WHEN '1080P' THEN 78.9100 WHEN '1080' THEN 78.9100 ELSE 47.8100 END END AS `upstream_second_price`,
           CASE WHEN s.`platform_unit_cost` > 0 THEN s.`platform_unit_cost` ELSE CASE UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(s.`provider_params_json`, ''), '{}'), '$.resolution'))) WHEN '1080P' THEN 78.9100 WHEN '1080' THEN 78.9100 ELSE 47.8100 END END AS `platform_second_price`,
           CASE WHEN s.`tenant_unit_price` > 0 THEN s.`tenant_unit_price` ELSE CASE UPPER(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(s.`provider_params_json`, ''), '{}'), '$.resolution'))) WHEN '1080P' THEN 78.9100 WHEN '1080' THEN 78.9100 ELSE 47.8100 END END AS `tenant_second_price`,
           s.`sort`
    FROM `la_aigc_video_channel_spec` AS s
    WHERE @video_spec_table_exists > 0
      AND s.`tenant_id` > 0
      AND s.`channel_code` = 'wan'
      AND (s.`quality` NOT IN ('720p', '1080p') OR JSON_EXTRACT(COALESCE(NULLIF(s.`provider_params_json`, ''), '{}'), '$.duration') IS NOT NULL)
) AS g
GROUP BY g.`tenant_id`, g.`resolution`, g.`ratio`
ON DUPLICATE KEY UPDATE
    `quality_label`=IF(`quality_label` IS NULL OR `quality_label` = '', VALUES(`quality_label`), `quality_label`),
    `width`=IF(`width` <= 0, VALUES(`width`), `width`),
    `height`=IF(`height` <= 0, VALUES(`height`), `height`),
    `upstream_unit_cost`=IF(`upstream_unit_cost` <= 0, VALUES(`upstream_unit_cost`), `upstream_unit_cost`),
    `platform_unit_cost`=IF(`platform_unit_cost` <= 0, VALUES(`platform_unit_cost`), `platform_unit_cost`),
    `tenant_unit_price`=IF(`tenant_unit_price` <= 0, VALUES(`tenant_unit_price`), `tenant_unit_price`),
    `upstream_cost_text`=IF(`upstream_cost_text` IS NULL OR `upstream_cost_text` = '', VALUES(`upstream_cost_text`), `upstream_cost_text`),
    `provider_params_json`=IF(`provider_params_json` IS NULL OR `provider_params_json` = '' OR `provider_params_json` = '{}', VALUES(`provider_params_json`), `provider_params_json`),
    `status`=`status`,
    `sort`=IF(`sort` <= 0, VALUES(`sort`), `sort`),
    `update_time`=`update_time`;

UPDATE `la_aigc_video_channel_spec`
SET `status` = `status`,
    `update_time` = `update_time`
WHERE @video_spec_table_exists > 0
  AND `channel_code` = 'wan'
  AND (`quality` NOT IN ('720p', '1080p') OR JSON_EXTRACT(COALESCE(NULLIF(`provider_params_json`, ''), '{}'), '$.duration') IS NOT NULL);
