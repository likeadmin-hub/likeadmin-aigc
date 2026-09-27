-- Latest news uses tenant_config; missing configuration receives the reference catalog.
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT p.tenant_id,p.id,'C','最新动态','el-icon-Film',96,'app.system_default.latest_news/lists','latest-news','latest_news/index','','',0,1,0,'system_default','core','core_tenant_latest_news',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` p WHERE p.source_menu_key='core_tenant_system_default' AND p.source<>'tenant'
AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` n WHERE n.tenant_id=p.tenant_id AND n.source_menu_key='core_tenant_latest_news');
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT p.tenant_id,p.id,'A','保存','',0,'app.system_default.latest_news/save','','','','',0,0,0,'system_default','core','core_tenant_latest_news_save',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` p WHERE p.source_menu_key='core_tenant_latest_news' AND p.source<>'tenant'
AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` n WHERE n.tenant_id=p.tenant_id AND n.source_menu_key='core_tenant_latest_news_save');
