-- PC 官网配置与贴牌订单额度预占
SET @db := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='la_tenant_brand_quota_bucket' AND COLUMN_NAME='reserved_quota')=0,'ALTER TABLE `la_tenant_brand_quota_bucket` ADD COLUMN `reserved_quota` int unsigned NOT NULL DEFAULT 0 COMMENT ''已预占额度'' AFTER `remaining_quota`','SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='la_tenant_brand_order' AND COLUMN_NAME='reserve_status')=0,'ALTER TABLE `la_tenant_brand_order` ADD COLUMN `reserve_status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT ''0无 1预占 2已消耗 3已释放 4已过期'' AFTER `open_error`','SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='la_tenant_brand_order' AND COLUMN_NAME='reserve_time')=0,'ALTER TABLE `la_tenant_brand_order` ADD COLUMN `reserve_time` int unsigned NOT NULL DEFAULT 0 AFTER `reserve_status`','SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='la_tenant_brand_order' AND COLUMN_NAME='reserve_expire_time')=0,'ALTER TABLE `la_tenant_brand_order` ADD COLUMN `reserve_expire_time` int unsigned NOT NULL DEFAULT 0 AFTER `reserve_time`','SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='la_tenant_brand_order' AND INDEX_NAME='idx_reserve_expire')=0,'ALTER TABLE `la_tenant_brand_order` ADD KEY `idx_reserve_expire` (`pay_status`,`reserve_status`,`reserve_expire_time`)','SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,0,'M','官方网站','el-icon-Monitor',110,'','official-site','','','',0,1,0,'','core','core_tenant_official_site',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` WHERE `tenant_id`=0 AND `source_menu_key`='core_tenant_official_site');
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,m.id,'C','官网配置','',100,'setting.web.official_site/get','official-site','official_website/index','','',0,1,0,'','core','core_tenant_official_site_config',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` m WHERE m.tenant_id=0 AND m.source_menu_key='core_tenant_official_site'
AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` WHERE `tenant_id`=0 AND `source_menu_key`='core_tenant_official_site_config');
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,m.id,'A','保存','',0,'setting.web.official_site/save','','','','',0,1,0,'','core','core_tenant_official_site_save',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` m WHERE m.tenant_id=0 AND m.source_menu_key='core_tenant_official_site_config'
AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` WHERE `tenant_id`=0 AND `source_menu_key`='core_tenant_official_site_save');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT m.tenant_id,0,'M','官方网站','el-icon-Monitor',110,'','official-site','','','',0,1,0,'','core','core_tenant_official_site',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (SELECT DISTINCT tenant_id FROM `la_tenant_system_menu`) m
WHERE m.tenant_id<>0 AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=m.tenant_id AND e.source_menu_key='core_tenant_official_site');
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT m.tenant_id,m.id,'C','官网配置','',100,'setting.web.official_site/get','official-site','official_website/index','','',0,1,0,'','core','core_tenant_official_site_config',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` m WHERE m.source_menu_key='core_tenant_official_site'
AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=m.tenant_id AND e.source_menu_key='core_tenant_official_site_config');
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT m.tenant_id,m.id,'A','保存','',0,'setting.web.official_site/save','','','','',0,1,0,'','core','core_tenant_official_site_save',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` m WHERE m.source_menu_key='core_tenant_official_site_config'
AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=m.tenant_id AND e.source_menu_key='core_tenant_official_site_save');
INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`) SELECT r.id,m.id FROM `la_tenant_system_role` r JOIN `la_tenant_system_menu` m ON m.tenant_id=r.tenant_id WHERE m.source_menu_key IN ('core_tenant_official_site','core_tenant_official_site_config','core_tenant_official_site_save') AND (r.delete_time IS NULL OR r.delete_time=0);

INSERT INTO `la_dev_crontab` (`name`,`type`,`system`,`remark`,`command`,`params`,`status`,`expression`,`error`,`last_time`,`time`,`max_time`,`create_time`,`update_time`,`delete_time`)
SELECT '贴牌订单超时释放',1,1,'每分钟释放30分钟未支付订单的贴牌额度','tenant:expire_brand_orders','',1,'* * * * *',NULL,NULL,'0','0',UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),NULL
WHERE NOT EXISTS (SELECT 1 FROM `la_dev_crontab` WHERE `command`='tenant:expire_brand_orders');
