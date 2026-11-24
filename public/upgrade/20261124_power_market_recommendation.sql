SET @recommend_sql = IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'la_power_market_product' AND column_name = 'is_recommended'), 'SELECT 1', 'ALTER TABLE `la_power_market_product` ADD COLUMN `is_recommended` tinyint unsigned NOT NULL DEFAULT 0');
PREPARE market_recommend FROM @recommend_sql;
EXECUTE market_recommend;
DEALLOCATE PREPARE market_recommend;

INSERT INTO `la_system_menu` (pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT id,'A','设置推荐','',0,'power.market/setRecommendation','','','','',0,0,0,'','core','core_power_market_recommendation',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_system_menu` WHERE source_menu_key='core_power_market'
AND NOT EXISTS (SELECT 1 FROM `la_system_menu` WHERE perms='power.market/setRecommendation');
