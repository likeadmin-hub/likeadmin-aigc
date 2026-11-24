INSERT INTO `la_system_menu` (pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT p.id,'C','数据统计','el-icon-TrendCharts',110,'ai_consumption/statistics','statistics','tenant/task/statistics','','',0,1,0,'','core','core_task_statistics_platform',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_system_menu` p
WHERE p.source_menu_key='core_task_log_platform'
  AND NOT EXISTS (SELECT 1 FROM `la_system_menu` m WHERE m.source_menu_key='core_task_statistics_platform');
