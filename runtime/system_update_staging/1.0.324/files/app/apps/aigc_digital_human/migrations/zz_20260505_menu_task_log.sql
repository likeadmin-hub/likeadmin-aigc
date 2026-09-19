DELETE FROM `la_system_menu`
WHERE `app_code` = 'aigc_digital_human'
  AND `source_menu_key` = 'aigc_digital_human_platform_spec';

UPDATE `la_system_menu`
SET `name` = '数字人视频', `update_time` = UNIX_TIMESTAMP()
WHERE `app_code` = 'aigc_digital_human'
  AND `source_menu_key` = 'aigc_digital_human_platform';

UPDATE `la_tenant_system_menu`
SET `name` = '数字人视频', `update_time` = UNIX_TIMESTAMP()
WHERE `app_code` = 'aigc_digital_human'
  AND `source_menu_key` = 'aigc_digital_human';
