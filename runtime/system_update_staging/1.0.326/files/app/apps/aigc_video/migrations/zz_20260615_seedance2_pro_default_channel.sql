SET NAMES utf8mb4;

SET @video_channel_table_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_channel');
SET @video_spec_table_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_video_channel_spec');

INSERT INTO `la_aigc_video_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT 0,'seedance2_pro','Seedance 2.0 Pro','seedance2_pro','seedance2_pro',9,'{"app_code":"seedance2_pro","submit_path":"/api/v1/apps/seedance2_pro/create","task_path":"/api/v1/apps/seedance2_pro/query?task_id={task_id}","poll_interval":2,"poll_attempts":0,"quantity_options":[1],"duration_options":[4,5,6,7,8,9,10,11,12,13,14,15],"default_duration":5,"ratio_options":["adaptive","9:16","16:9","1:1","4:3","3:4","21:9"],"mode_options":["pro","fast"],"default_mode":"pro","supported_asset_types":["image","video","audio"],"max_reference_images":9,"max_reference_videos":3,"max_reference_audios":3,"max_reference_assets":15}',1,600,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE @video_channel_table_exists > 0
ON DUPLICATE KEY UPDATE
    `la_aigc_video_channel`.`name`=VALUES(`name`),
    `la_aigc_video_channel`.`provider`=VALUES(`provider`),
    `la_aigc_video_channel`.`model`=VALUES(`model`),
    `la_aigc_video_channel`.`max_reference_images`=VALUES(`max_reference_images`),
    `la_aigc_video_channel`.`config_json`=VALUES(`config_json`),
    `la_aigc_video_channel`.`status`=1,
    `la_aigc_video_channel`.`sort`=GREATEST(`la_aigc_video_channel`.`sort`, VALUES(`sort`)),
    `la_aigc_video_channel`.`update_time`=UNIX_TIMESTAMP();

DELETE FROM `la_aigc_video_channel_spec`
WHERE @video_spec_table_exists > 0
  AND `tenant_id` = 0
  AND `channel_code` = 'seedance2_pro';

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`upstream_unit_cost`,`platform_unit_cost`,`tenant_unit_price`,`upstream_cost_text`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
SELECT 0,'seedance2_pro',t.`quality`,t.`quality_label`,t.`ratio`,0,0,90.0000,100.0000,100.0000,CONCAT(t.`quality_label`,'，点 / 秒'),CONCAT('{"model":"seedance2_pro","duration":0,"mode":"', t.`quality`, '"}'),1,t.`sort`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (
    SELECT 'pro' AS `quality`, 'Pro 模式每秒' AS `quality_label`, 'mode_pro' AS `ratio`, 2000 AS `sort` UNION ALL
    SELECT 'fast', 'Fast 模式每秒', 'mode_fast', 1990
) AS t
WHERE @video_spec_table_exists > 0
ON DUPLICATE KEY UPDATE
    `la_aigc_video_channel_spec`.`quality_label`=VALUES(`quality_label`),
    `la_aigc_video_channel_spec`.`width`=VALUES(`width`),
    `la_aigc_video_channel_spec`.`height`=VALUES(`height`),
    `la_aigc_video_channel_spec`.`upstream_unit_cost`=VALUES(`upstream_unit_cost`),
    `la_aigc_video_channel_spec`.`platform_unit_cost`=VALUES(`platform_unit_cost`),
    `la_aigc_video_channel_spec`.`tenant_unit_price`=VALUES(`tenant_unit_price`),
    `la_aigc_video_channel_spec`.`upstream_cost_text`=VALUES(`upstream_cost_text`),
    `la_aigc_video_channel_spec`.`provider_params_json`=VALUES(`provider_params_json`),
    `la_aigc_video_channel_spec`.`status`=1,
    `la_aigc_video_channel_spec`.`sort`=VALUES(`sort`),
    `la_aigc_video_channel_spec`.`update_time`=UNIX_TIMESTAMP();

UPDATE `la_aigc_video_channel`
SET `status` = 0,
    `update_time` = UNIX_TIMESTAMP()
WHERE @video_channel_table_exists > 0
  AND `tenant_id` = 0
  AND `code` IN ('grok_video_xaiq','happy_horse','happyhorse','happy_horse_video','wan','seedance','omni_flash_ext');

UPDATE `la_aigc_video_channel`
SET `name` = 'Grok Video（xAIQ）',
    `update_time` = UNIX_TIMESTAMP()
WHERE @video_channel_table_exists > 0
  AND `tenant_id` = 0
  AND `code` = 'grok_video_xaiq';
