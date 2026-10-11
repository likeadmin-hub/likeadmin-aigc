-- aigc_video: add sibling effect configuration without pruning existing/custom menus.
INSERT INTO `la_system_menu` (`pid`, `type`, `name`, `sort`, `perms`, `paths`, `component`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
SELECT p.id, 'C', '基础配置', 30, 'app.aigc_video.config/detail', 'config', 'apps/aigc_video/config', 'aigc_video', 'app', 'aigc_video_platform_config', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `la_system_menu` p
WHERE p.app_code='aigc_video' AND p.source='app' AND p.source_menu_key='aigc_video_platform'
AND NOT EXISTS (SELECT 1 FROM `la_system_menu` e WHERE e.app_code='aigc_video' AND e.source_menu_key='aigc_video_platform_config');

INSERT INTO `la_system_menu` (`pid`, `type`, `name`, `sort`, `perms`, `paths`, `component`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
SELECT p.id, 'C', '效果配置', 29, 'app.aigc_video.config/detail', 'effects', 'apps/aigc_video/effects', 'aigc_video', 'app', 'aigc_video_platform_effects', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `la_system_menu` p
WHERE p.app_code='aigc_video' AND p.source='app' AND p.source_menu_key='aigc_video_platform'
AND NOT EXISTS (SELECT 1 FROM `la_system_menu` e WHERE e.app_code='aigc_video' AND e.source_menu_key='aigc_video_platform_effects');

INSERT INTO `la_tenant_system_menu` (`tenant_id`, `pid`, `type`, `name`, `sort`, `perms`, `paths`, `component`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
SELECT p.`tenant_id`, p.id, 'C', '效果配置', 29, 'app.aigc_video.config/detail', 'effects', 'apps/aigc_video/effects', 'aigc_video', 'app', 'aigc_video_effects', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` p
WHERE p.app_code='aigc_video' AND p.source='app' AND p.source_menu_key='aigc_video'
AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` e WHERE e.app_code='aigc_video' AND e.source_menu_key='aigc_video_effects' AND e.tenant_id=p.tenant_id);

UPDATE `la_tenant_system_menu` SET sort=30 WHERE app_code='aigc_video' AND source='app' AND source_menu_key='aigc_video_config';
INSERT INTO `la_tenant_system_role_menu` (`role_id`, `menu_id`)
SELECT DISTINCT rm.role_id, e.id FROM `la_tenant_system_role_menu` rm
JOIN `la_tenant_system_menu` c ON c.id=rm.menu_id AND c.app_code='aigc_video' AND c.source='app' AND c.source_menu_key='aigc_video_config'
JOIN `la_tenant_system_menu` e ON e.app_code='aigc_video' AND e.source='app' AND e.source_menu_key='aigc_video_effects' AND e.tenant_id=c.tenant_id
WHERE NOT EXISTS (SELECT 1 FROM `la_tenant_system_role_menu` existing WHERE existing.role_id=rm.role_id AND existing.menu_id=e.id);

UPDATE `la_system_menu` SET sort=30 WHERE app_code='aigc_video' AND source='app' AND source_menu_key='aigc_video_platform_config';
INSERT INTO `la_system_role_menu` (`role_id`, `menu_id`)
SELECT DISTINCT rm.role_id, e.id FROM `la_system_role_menu` rm
JOIN `la_system_menu` c ON c.id=rm.menu_id AND c.app_code='aigc_video' AND c.source='app' AND c.source_menu_key='aigc_video_platform_config'
JOIN `la_system_menu` e ON e.app_code='aigc_video' AND e.source='app' AND e.source_menu_key='aigc_video_platform_effects'
WHERE NOT EXISTS (SELECT 1 FROM `la_system_role_menu` existing WHERE existing.role_id=rm.role_id AND existing.menu_id=e.id);
