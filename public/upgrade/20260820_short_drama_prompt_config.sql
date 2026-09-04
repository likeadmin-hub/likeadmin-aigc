-- Add a dedicated tenant-admin page for short-drama prompt configuration.
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT parent.`tenant_id`,parent.`id`,'C','提示词配置','',6,'app.aigc_short_drama.config/detail','prompt','apps/aigc_short_drama/prompt','','',0,1,0,'aigc_short_drama','app','aigc_short_drama_prompt',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`source_menu_key`='aigc_short_drama'
  AND NOT EXISTS (
    SELECT 1
    FROM `la_tenant_system_menu` existing
    WHERE existing.`tenant_id`=parent.`tenant_id`
      AND existing.`source_menu_key`='aigc_short_drama_prompt'
  );

-- Roles that can manage the basic short-drama configuration inherit this page.
INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT DISTINCT role_menu.`role_id`,prompt_menu.`id`
FROM `la_tenant_system_role_menu` role_menu
JOIN `la_tenant_system_menu` config_menu
  ON config_menu.`id`=role_menu.`menu_id`
 AND config_menu.`source_menu_key`='aigc_short_drama_config'
JOIN `la_tenant_system_menu` prompt_menu
  ON prompt_menu.`tenant_id`=config_menu.`tenant_id`
 AND prompt_menu.`source_menu_key`='aigc_short_drama_prompt';
