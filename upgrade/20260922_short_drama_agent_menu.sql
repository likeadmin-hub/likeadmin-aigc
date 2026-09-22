-- Tenant-only Agent navigation; existing installations can also use AppMenuService::syncTenantMenus.
INSERT INTO `la_tenant_system_menu` (tenant_id,pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT parent.tenant_id,parent.id,'C','短剧画布 Agent','',11,'app.aigc_short_drama.config/detail','agent','apps/aigc_short_drama/agent','','',0,1,0,'aigc_short_drama','app','aigc_short_drama_agent',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.app_code='aigc_short_drama' AND parent.source='app' AND parent.source_menu_key='aigc_short_drama'
AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` existing WHERE existing.tenant_id=parent.tenant_id AND existing.app_code='aigc_short_drama' AND existing.source_menu_key='aigc_short_drama_agent');
