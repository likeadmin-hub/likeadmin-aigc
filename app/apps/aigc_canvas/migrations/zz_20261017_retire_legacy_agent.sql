-- Retire old Canvas Agent entry points; preserve all project, asset and history tables.
INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
SELECT 'aigc_canvas','app.aigc_canvas.generate/assetRegister','POST','aigc_canvas:asset:register','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_app_api` WHERE `app_code`='aigc_canvas' AND `api_path`='app.aigc_canvas.generate/assetRegister' AND `scene`='user' AND `api_method`='POST');
INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
SELECT 'aigc_canvas','app.aigc_canvas.agent/assetRegister','POST','aigc_canvas:asset:register','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_app_api` WHERE `app_code`='aigc_canvas' AND `api_path`='app.aigc_canvas.agent/assetRegister' AND `scene`='user' AND `api_method`='POST');
DELETE FROM `la_tenant_system_role_menu` WHERE `menu_id` IN (
SELECT `id` FROM `la_tenant_system_menu` WHERE `app_code`='aigc_canvas' AND `source`='app'
AND `source_menu_key` IN ('aigc_canvas_skill','aigc_canvas_governance','aigc_canvas_trace'));
DELETE FROM `la_tenant_system_menu` WHERE `app_code`='aigc_canvas' AND `source`='app'
AND `source_menu_key` IN ('aigc_canvas_skill','aigc_canvas_governance','aigc_canvas_trace');
DELETE FROM `la_app_api` WHERE `app_code`='aigc_canvas' AND (
(`api_path` LIKE 'app.aigc_canvas.agent/%' AND `api_path` <> 'app.aigc_canvas.agent/assetRegister')
OR `api_path` LIKE 'app.aigc_canvas.agent_chat/%'
OR `api_path` LIKE 'app.aigc_canvas.agent_tool/%'
OR `api_path` LIKE 'app.aigc_canvas.agent_generation/%'
OR `api_path` LIKE 'app.aigc_canvas.skill/%'
OR `api_path` LIKE 'app.aigc_canvas.governance/%'
OR `api_path` LIKE 'app.aigc_canvas.admin_trace/%');
