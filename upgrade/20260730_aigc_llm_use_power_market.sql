-- AIGC 对话改为统一使用模型 API 市场，移除旧通道和模型配置入口。
DELETE FROM `la_system_menu`
WHERE `source_menu_key` IN ('aigc_llm_platform_channel', 'aigc_llm_platform_model');

DELETE role_menu
FROM `la_tenant_system_role_menu` role_menu
INNER JOIN `la_tenant_system_menu` menu ON menu.`id` = role_menu.`menu_id`
WHERE menu.`source_menu_key` IN ('aigc_llm_channel', 'aigc_llm_model');

DELETE FROM `la_tenant_system_menu`
WHERE `source_menu_key` IN ('aigc_llm_channel', 'aigc_llm_model');

DELETE FROM `la_app_api`
WHERE `app_code` = 'aigc_llm'
  AND (`api_path` LIKE 'app.aigc_llm.channel/%' OR `api_path` LIKE 'app.aigc_llm.model/%');
