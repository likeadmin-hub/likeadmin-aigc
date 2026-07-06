INSERT INTO `la_tenant_system_menu`
(`tenant_id`, `pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
SELECT parent.`tenant_id`, parent.`id`, 'A', '查询', '', 2, 'ai_task/query', '', '', '', '', 0, 1, 0, '', 'core', 'core_ai_task_tenant_query', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`source_menu_key` = 'core_ai_task_tenant'
  AND NOT EXISTS (
      SELECT 1
      FROM `la_tenant_system_menu` child
      WHERE child.`pid` = parent.`id`
        AND child.`tenant_id` = parent.`tenant_id`
        AND child.`perms` = 'ai_task/query'
  );
