INSERT INTO `la_system_menu` (pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT id,'A','清空系统日志','',0,'setting.system.log/clear','','','','',0,0,0,'','core','core_system_log_clear',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_system_menu` WHERE perms='setting.system.log/lists' AND type='C'
AND NOT EXISTS (SELECT 1 FROM `la_system_menu` WHERE perms='setting.system.log/clear')
ORDER BY id LIMIT 1;
