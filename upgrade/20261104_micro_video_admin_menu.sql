-- Keep existing menu IDs, routes and role assignments.
UPDATE `la_tenant_system_menu` SET `name`='项目管理', `sort`=40 WHERE `source`='app' AND `app_code`='aigc_micro_video' AND `source_menu_key`='aigc_micro_video_workbench';
UPDATE `la_tenant_system_menu` SET `name`='任务记录', `sort`=30 WHERE `source`='app' AND `app_code`='aigc_micro_video' AND `source_menu_key`='aigc_micro_video_task';
UPDATE `la_tenant_system_menu` SET `name`='创作内容', `sort`=20 WHERE `source`='app' AND `app_code`='aigc_micro_video' AND `source_menu_key`='aigc_micro_video_direction';
UPDATE `la_tenant_system_menu` SET `name`='应用配置', `sort`=10 WHERE `source`='app' AND `app_code`='aigc_micro_video' AND `source_menu_key`='aigc_micro_video_config';
