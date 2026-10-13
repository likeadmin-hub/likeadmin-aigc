-- Independent Agent skill tab, sharing the existing AIGC configuration permission.
UPDATE `la_tenant_system_menu` SET sort=50 WHERE app_code='aigc_llm' AND source='app' AND source_menu_key='aigc_llm_config';
INSERT INTO `la_tenant_system_menu` (tenant_id,pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT parent.tenant_id,parent.id,'C','技能配置','',40,'app.aigc_llm.config/detail','skill-config','apps/aigc_llm/skill-config','','',0,1,0,'aigc_llm','app','aigc_llm_skill_config',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.app_code='aigc_llm' AND parent.source='app' AND parent.source_menu_key='aigc_llm'
AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` existing WHERE existing.tenant_id=parent.tenant_id AND existing.app_code='aigc_llm' AND existing.source_menu_key='aigc_llm_skill_config');
