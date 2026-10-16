-- Retain the first pre-adaptation snapshot; never overwrite it on retries.
CREATE TABLE IF NOT EXISTS `la_aigc_canvas_project_compat_backup` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `project_id` int unsigned NOT NULL,
  `snapshot_json` longtext NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_owner` (`tenant_id`,`user_id`,`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Original canvas project snapshots before compatibility adaptation';

-- Retire old Canvas Agent entry points; preserve all project, asset and history tables.
INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
SELECT 'aigc_canvas','app.aigc_canvas.generate/assetRegister','POST','aigc_canvas:asset:register','user',1,0,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_app_api` WHERE `app_code`='aigc_canvas' AND `api_path`='app.aigc_canvas.generate/assetRegister' AND `scene`='user' AND `api_method`='POST');
DELETE role_menu FROM `la_tenant_system_role_menu` role_menu
JOIN `la_tenant_system_menu` menu ON menu.`id`=role_menu.`menu_id`
WHERE menu.`app_code`='aigc_canvas' AND menu.`source`='app'
AND menu.`source_menu_key` IN ('aigc_canvas_skill','aigc_canvas_governance','aigc_canvas_trace');
DELETE FROM `la_tenant_system_menu` WHERE `app_code`='aigc_canvas' AND `source`='app'
AND `source_menu_key` IN ('aigc_canvas_skill','aigc_canvas_governance','aigc_canvas_trace');
DELETE FROM `la_app_api` WHERE `app_code`='aigc_canvas' AND (
`api_path` LIKE 'app.aigc_canvas.agent/%'
OR `api_path` LIKE 'app.aigc_canvas.agent_chat/%'
OR `api_path` LIKE 'app.aigc_canvas.agent_tool/%'
OR `api_path` LIKE 'app.aigc_canvas.agent_generation/%'
OR `api_path` LIKE 'app.aigc_canvas.skill/%'
OR `api_path` LIKE 'app.aigc_canvas.governance/%'
OR `api_path` LIKE 'app.aigc_canvas.admin_trace/%');
