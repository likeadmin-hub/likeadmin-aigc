DELETE role_menu FROM `la_tenant_system_role_menu` role_menu
JOIN `la_tenant_system_menu` menu ON menu.`id` = role_menu.`menu_id`
WHERE menu.`app_code` = 'aigc_digital_human'
  AND menu.`source` = 'app'
  AND menu.`source_menu_key` = 'aigc_digital_human_case';

DELETE FROM `la_tenant_system_menu`
WHERE `app_code` = 'aigc_digital_human'
  AND `source` = 'app'
  AND `source_menu_key` = 'aigc_digital_human_case';
