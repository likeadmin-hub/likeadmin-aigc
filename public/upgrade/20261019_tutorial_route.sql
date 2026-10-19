-- Repair only the built-in tutorial route; tenant-owned menus remain unchanged.
-- Split tenant menu tables are repaired by MenuLogic when their menus are loaded.
UPDATE `la_tenant_system_menu`
SET `component` = 'setting/website/tutorial'
WHERE `source_menu_key` = 'core_tenant_tutorial'
  AND `source` <> 'tenant'
  AND `component` <> 'setting/website/tutorial';
