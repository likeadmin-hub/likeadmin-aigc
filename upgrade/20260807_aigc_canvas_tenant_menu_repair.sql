-- Repair tenant admin menus and API declarations for AIGC canvas.

DELETE FROM `la_app_api`
WHERE `app_code` = 'aigc_canvas'
  AND `scene` = 'tenant_admin'
  AND `api_path` = 'app.aigc_canvas.config/dependencies';

INSERT INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`)
VALUES
('aigc_canvas','app.aigc_canvas.admin_project/lists','GET','aigc_canvas:project:lists','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_canvas','app.aigc_canvas.admin_project/delete','POST','aigc_canvas:project:delete','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
('aigc_canvas','app.aigc_canvas.admin_project/clear','POST','aigc_canvas:project:clear','tenant_admin',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE
`permission_key` = VALUES(`permission_key`),
`need_login` = VALUES(`need_login`),
`need_role_permission` = VALUES(`need_role_permission`),
`status` = VALUES(`status`),
`update_time` = VALUES(`update_time`);

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,0,'M','无限画布','el-icon-Share',96,'','aigc-canvas','','','',0,1,0,'aigc_canvas','app','aigc_canvas',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (
  SELECT 1 FROM `la_tenant_system_menu`
  WHERE `tenant_id` = 0 AND `source_menu_key` = 'aigc_canvas'
);

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT t.`id`,0,'M','无限画布','el-icon-Share',96,'','aigc-canvas','','','',0,1,0,'aigc_canvas','app','aigc_canvas',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant` t
JOIN `la_tenant_app` ta ON ta.`tenant_id` = t.`id`
  AND ta.`app_code` = 'aigc_canvas'
  AND ta.`buy_status` = 'paid'
  AND ta.`shelf_status` = 'on'
  AND ta.`enable_status` = 'enabled'
WHERE NOT EXISTS (
  SELECT 1 FROM `la_tenant_system_menu` m
  WHERE m.`tenant_id` = t.`id` AND m.`source_menu_key` = 'aigc_canvas'
);

UPDATE `la_tenant_system_menu`
SET `pid` = 0,
    `type` = 'M',
    `name` = '无限画布',
    `icon` = 'el-icon-Share',
    `sort` = 96,
    `perms` = '',
    `paths` = 'aigc-canvas',
    `component` = '',
    `is_show` = 1,
    `is_disable` = 0,
    `app_code` = 'aigc_canvas',
    `source` = 'app',
    `is_core` = 0,
    `update_time` = UNIX_TIMESTAMP()
WHERE `source_menu_key` = 'aigc_canvas'
  AND `source` = 'app';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT parent.`tenant_id`,parent.`id`,item.`type`,item.`name`,item.`icon`,item.`sort`,item.`perms`,item.`paths`,item.`component`,'','',0,1,0,'aigc_canvas','app',item.`source_menu_key`,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
JOIN (
  SELECT 'C' AS `type`, '用量统计' AS `name`, '' AS `icon`, 10 AS `sort`, 'app.aigc_canvas.admin/stat' AS `perms`, 'stat' AS `paths`, 'apps/aigc_canvas/stat' AS `component`, 'aigc_canvas_stat' AS `source_menu_key`
  UNION ALL SELECT 'C','创作任务','',20,'app.aigc_canvas.admin_run/lists','run','apps/aigc_canvas/run','aigc_canvas_run'
  UNION ALL SELECT 'C','基础配置','',30,'app.aigc_canvas.config/detail','config','apps/aigc_canvas/config','aigc_canvas_config'
  UNION ALL SELECT 'C','Skills管理','',40,'app.aigc_canvas.skill/lists','skill','apps/aigc_canvas/skill','aigc_canvas_skill'
  UNION ALL SELECT 'C','Agent 治理','',50,'app.aigc_canvas.governance/policies','governance','apps/aigc_canvas/governance','aigc_canvas_governance'
  UNION ALL SELECT 'C','Agent Trace','',60,'app.aigc_canvas.admin_trace/lists','trace','apps/aigc_canvas/trace','aigc_canvas_trace'
) item
WHERE parent.`source_menu_key` = 'aigc_canvas'
  AND parent.`source` = 'app'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = parent.`tenant_id`
      AND exists_menu.`source_menu_key` = item.`source_menu_key`
  );

UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` parent
  ON parent.`tenant_id` = child.`tenant_id`
 AND parent.`source_menu_key` = 'aigc_canvas'
 AND parent.`source` = 'app'
SET child.`pid` = parent.`id`,
    child.`type` = 'C',
    child.`name` = CASE child.`source_menu_key`
      WHEN 'aigc_canvas_stat' THEN '用量统计'
      WHEN 'aigc_canvas_run' THEN '创作任务'
      WHEN 'aigc_canvas_config' THEN '基础配置'
      WHEN 'aigc_canvas_skill' THEN 'Skills管理'
      WHEN 'aigc_canvas_governance' THEN 'Agent 治理'
      ELSE 'Agent Trace'
    END,
    child.`sort` = CASE child.`source_menu_key`
      WHEN 'aigc_canvas_stat' THEN 10
      WHEN 'aigc_canvas_run' THEN 20
      WHEN 'aigc_canvas_config' THEN 30
      WHEN 'aigc_canvas_skill' THEN 40
      WHEN 'aigc_canvas_governance' THEN 50
      ELSE 60
    END,
    child.`perms` = CASE child.`source_menu_key`
      WHEN 'aigc_canvas_stat' THEN 'app.aigc_canvas.admin/stat'
      WHEN 'aigc_canvas_run' THEN 'app.aigc_canvas.admin_run/lists'
      WHEN 'aigc_canvas_config' THEN 'app.aigc_canvas.config/detail'
      WHEN 'aigc_canvas_skill' THEN 'app.aigc_canvas.skill/lists'
      WHEN 'aigc_canvas_governance' THEN 'app.aigc_canvas.governance/policies'
      ELSE 'app.aigc_canvas.admin_trace/lists'
    END,
    child.`paths` = CASE child.`source_menu_key`
      WHEN 'aigc_canvas_stat' THEN 'stat'
      WHEN 'aigc_canvas_run' THEN 'run'
      WHEN 'aigc_canvas_config' THEN 'config'
      WHEN 'aigc_canvas_skill' THEN 'skill'
      WHEN 'aigc_canvas_governance' THEN 'governance'
      ELSE 'trace'
    END,
    child.`component` = CASE child.`source_menu_key`
      WHEN 'aigc_canvas_stat' THEN 'apps/aigc_canvas/stat'
      WHEN 'aigc_canvas_run' THEN 'apps/aigc_canvas/run'
      WHEN 'aigc_canvas_config' THEN 'apps/aigc_canvas/config'
      WHEN 'aigc_canvas_skill' THEN 'apps/aigc_canvas/skill'
      WHEN 'aigc_canvas_governance' THEN 'apps/aigc_canvas/governance'
      ELSE 'apps/aigc_canvas/trace'
    END,
    child.`is_show` = 1,
    child.`is_disable` = 0,
    child.`app_code` = 'aigc_canvas',
    child.`source` = 'app',
    child.`is_core` = 0,
    child.`update_time` = UNIX_TIMESTAMP()
WHERE child.`source` = 'app'
  AND child.`source_menu_key` IN ('aigc_canvas_stat','aigc_canvas_run','aigc_canvas_config','aigc_canvas_skill','aigc_canvas_governance','aigc_canvas_trace');

DELETE role_menu
FROM `la_tenant_system_role_menu` role_menu
JOIN `la_tenant_system_menu` menu ON menu.`id` = role_menu.`menu_id`
WHERE menu.`app_code` = 'aigc_canvas'
  AND menu.`source` = 'app'
  AND (
    menu.`source_menu_key` IN ('aigc_canvas_dependency','aigc_canvas_dependencies','aigc_canvas_project')
    OR (menu.`paths` = 'dependencies' AND menu.`component` = 'apps/aigc_canvas/dependencies')
    OR (menu.`paths` = 'project' AND menu.`component` = 'apps/aigc_canvas/project')
  );

DELETE FROM `la_tenant_system_menu`
WHERE `app_code` = 'aigc_canvas'
  AND `source` = 'app'
  AND (
    `source_menu_key` IN ('aigc_canvas_dependency','aigc_canvas_dependencies','aigc_canvas_project')
    OR (`paths` = 'dependencies' AND `component` = 'apps/aigc_canvas/dependencies')
    OR (`paths` = 'project' AND `component` = 'apps/aigc_canvas/project')
  );

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT DISTINCT role_menu.`role_id`, target_menu.`id`
FROM `la_tenant_system_role_menu` role_menu
JOIN `la_tenant_system_menu` owned_menu ON owned_menu.`id` = role_menu.`menu_id`
JOIN `la_tenant_system_role` role ON role.`id` = role_menu.`role_id`
JOIN `la_tenant_system_menu` target_menu
  ON target_menu.`tenant_id` = owned_menu.`tenant_id`
 AND target_menu.`source_menu_key` IN ('aigc_canvas','aigc_canvas_stat','aigc_canvas_run','aigc_canvas_config','aigc_canvas_skill','aigc_canvas_governance','aigc_canvas_trace')
WHERE owned_menu.`source_menu_key` IN ('aigc_canvas','aigc_canvas_stat','aigc_canvas_run','aigc_canvas_config','aigc_canvas_skill','aigc_canvas_governance','aigc_canvas_trace')
  AND target_menu.`source` = 'app'
  AND (role.`delete_time` IS NULL OR role.`delete_time` = 0);
