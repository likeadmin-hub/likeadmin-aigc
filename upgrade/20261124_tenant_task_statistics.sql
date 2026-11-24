INSERT INTO `la_tenant_system_menu` (tenant_id,pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT p.tenant_id,p.id,'C','数据统计','el-icon-TrendCharts',110,'ai_consumption/statistics','statistics','consumer/task/statistics','','',0,1,0,'','core','core_task_statistics_tenant',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM la_tenant_system_menu p
WHERE p.source_menu_key='core_task_log_tenant'
  AND NOT EXISTS (SELECT 1 FROM la_tenant_system_menu m WHERE m.tenant_id=p.tenant_id AND m.source_menu_key='core_task_statistics_tenant');
