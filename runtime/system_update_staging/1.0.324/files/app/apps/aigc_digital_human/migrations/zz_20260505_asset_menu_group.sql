UPDATE `la_tenant_system_menu`
SET `paths` = 'public-avatar',
    `component` = 'apps/aigc_digital_human/public-avatar',
    `update_time` = UNIX_TIMESTAMP()
WHERE `app_code` = 'aigc_digital_human'
  AND `source_menu_key` = 'aigc_digital_human_public_avatar';

UPDATE `la_tenant_system_menu`
SET `paths` = 'public-voice',
    `component` = 'apps/aigc_digital_human/public-voice',
    `update_time` = UNIX_TIMESTAMP()
WHERE `app_code` = 'aigc_digital_human'
  AND `source_menu_key` = 'aigc_digital_human_public_voice';
