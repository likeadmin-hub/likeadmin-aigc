CREATE TABLE IF NOT EXISTS `la_power_market_product` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_code` varchar(191) NOT NULL DEFAULT '', `resource_type` varchar(20) NOT NULL DEFAULT '', `name` varchar(160) NOT NULL DEFAULT '', `description` varchar(1000) NOT NULL DEFAULT '',
  `source_code` varchar(40) NOT NULL DEFAULT '', `upstream_resource_key` varchar(191) NOT NULL DEFAULT '', `upstream_app_code` varchar(80) NOT NULL DEFAULT '', `upstream_api_code` varchar(80) NOT NULL DEFAULT '', `upstream_model_code` varchar(120) NOT NULL DEFAULT '', `upstream_channel_code` varchar(120) NOT NULL DEFAULT '', `model_type` varchar(20) NOT NULL DEFAULT '' COMMENT 'text/image/video',
  `source_payload` text, `status` tinyint unsigned NOT NULL DEFAULT 1, `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_source_resource` (`source_code`,`upstream_resource_key`), KEY `idx_type_status` (`resource_type`,`status`), KEY `idx_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='算力市场商品';

CREATE TABLE IF NOT EXISTS `la_power_market_sku` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL DEFAULT 0, `sku_key` varchar(160) NOT NULL DEFAULT '', `title` varchar(200) NOT NULL DEFAULT '', `billing_mode` varchar(20) NOT NULL DEFAULT 'fixed',
  `locked_params` text, `selectable_params` text, `usage_unit` varchar(40) NOT NULL DEFAULT 'per_call', `usage_unit_size` decimal(16,6) NOT NULL DEFAULT 1.000000,
  `upstream_price` decimal(16,6) NOT NULL DEFAULT 0.000000, `sale_points` decimal(16,6) NOT NULL DEFAULT 0.000000, `sale_status` tinyint unsigned NOT NULL DEFAULT 1, `price_hash` char(40) NOT NULL DEFAULT '', `source_payload` text, `status` tinyint unsigned NOT NULL DEFAULT 1, `sort` int NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_product_sku` (`product_id`,`sku_key`), KEY `idx_product_status` (`product_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='算力市场计费SKU';

CREATE TABLE IF NOT EXISTS `la_tenant_power_market_sku_price` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
  `sku_id` int unsigned NOT NULL DEFAULT 0 COMMENT '算力市场SKU ID',
  `sale_points` decimal(16,6) NOT NULL DEFAULT 0.000000 COMMENT '租户销售单价',
  `sale_status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '销售状态:0下架 1上架',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_sku` (`tenant_id`,`sku_id`),
  KEY `idx_tenant_status` (`tenant_id`,`sale_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户算力市场SKU定价';

CREATE TABLE IF NOT EXISTS `la_tenant_power_market_product` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0, `product_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(160) NOT NULL DEFAULT '', `icon` varchar(500) NOT NULL DEFAULT '', `description` varchar(1000) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uk_tenant_product` (`tenant_id`,`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户算力市场商品展示配置';

SET @has_market_model_type := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_power_market_product' AND COLUMN_NAME = 'model_type');
SET @market_model_type_sql := IF(@has_market_model_type = 0, 'ALTER TABLE `la_power_market_product` ADD COLUMN `model_type` varchar(20) NOT NULL DEFAULT '''' COMMENT ''text/image/video'' AFTER `upstream_channel_code`', 'SELECT 1');
PREPARE market_model_type_stmt FROM @market_model_type_sql;
EXECUTE market_model_type_stmt;
DEALLOCATE PREPARE market_model_type_stmt;
UPDATE `la_power_market_product`
SET `model_type` = 'text'
WHERE `resource_type` = 'model' AND `model_type` = '';

SET @has_market_sale_points := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_power_market_sku' AND COLUMN_NAME = 'sale_points');
SET @market_sale_points_sql := IF(@has_market_sale_points = 0, 'ALTER TABLE `la_power_market_sku` ADD COLUMN `sale_points` decimal(16,6) NOT NULL DEFAULT 0.000000 COMMENT ''平台销售单价'' AFTER `upstream_price`', 'SELECT 1');
PREPARE market_sale_points_stmt FROM @market_sale_points_sql;
EXECUTE market_sale_points_stmt;
DEALLOCATE PREPARE market_sale_points_stmt;
SET @has_market_sale_status := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_power_market_sku' AND COLUMN_NAME = 'sale_status');
SET @market_sale_status_sql := IF(@has_market_sale_status = 0, 'ALTER TABLE `la_power_market_sku` ADD COLUMN `sale_status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT ''销售状态:0停用 1启用'' AFTER `sale_points`', 'SELECT 1');
PREPARE market_sale_status_stmt FROM @market_sale_status_sql;
EXECUTE market_sale_status_stmt;
DEALLOCATE PREPARE market_sale_status_stmt;
UPDATE `la_power_market_sku` SET `sale_points` = `upstream_price` WHERE `sale_points` = 0 AND `upstream_price` > 0;

SET @core_power_mall_id := (SELECT `id` FROM `la_system_menu` WHERE `source_menu_key`='core_power_mall' LIMIT 1);
INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT @core_power_mall_id,'C','算力市场','el-icon-Connection',95,'power.market/lists','market','power_mall/market','','',0,1,0,'','core','core_power_market',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE @core_power_mall_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `la_system_menu` c WHERE c.`source_menu_key`='core_power_market');

SET @core_power_market_id := (SELECT `id` FROM `la_system_menu` WHERE `source_menu_key`='core_power_market' LIMIT 1);
UPDATE `la_system_menu` SET `pid`=@core_power_mall_id,`type`='C',`name`='算力市场',`icon`='el-icon-Connection',`sort`=95,`perms`='power.market/lists',`paths`='market',`component`='power_mall/market',`is_show`=1,`is_disable`=0,`source`='core',`is_core`=1,`update_time`=UNIX_TIMESTAMP()
WHERE `source_menu_key`='core_power_market' AND @core_power_mall_id IS NOT NULL;

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT @core_power_market_id,x.`type`,x.`name`,'',0,x.`perms`,'','','','',0,0,0,'','core',x.`source_menu_key`,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (
  SELECT 'A' AS `type`,'详情' AS `name`,'power.market/detail' AS `perms`,'core_power_market_detail' AS `source_menu_key`
  UNION ALL SELECT 'A','应用列表','power.market/apps','core_power_market_apps'
  UNION ALL SELECT 'A','应用详情','power.market/appDetail','core_power_market_app_detail'
  UNION ALL SELECT 'A','保存售价','power.market/savePrices','core_power_market_save_prices'
  UNION ALL SELECT 'A','商品类型','power.market/types','core_power_market_types'
  UNION ALL SELECT 'A','同步上游价格','power.market/sync','core_power_market_sync'
) x
WHERE @core_power_market_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `la_system_menu` e WHERE e.`source_menu_key`=x.`source_menu_key`);

UPDATE `la_system_menu` SET `pid`=@core_power_market_id,`source`='core',`is_core`=1,`update_time`=UNIX_TIMESTAMP()
WHERE `source_menu_key` IN ('core_power_market_detail','core_power_market_apps','core_power_market_app_detail','core_power_market_save_prices','core_power_market_types','core_power_market_sync')
  AND @core_power_market_id IS NOT NULL;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT parent.`tenant_id`,parent.`id`,child.`type`,child.`name`,child.`icon`,child.`sort`,child.`perms`,child.`paths`,child.`component`,child.`selected`,child.`params`,child.`is_cache`,child.`is_show`,child.`is_disable`,child.`app_code`,child.`source`,child.`source_menu_key`,child.`is_core`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
JOIN (
  SELECT 'C' AS `type`,'算力市场' AS `name`,'el-icon-Connection' AS `icon`,95 AS `sort`,'power.market/models' AS `perms`,'market' AS `paths`,'power_mall/market' AS `component`,'' AS `selected`,'' AS `params`,0 AS `is_cache`,1 AS `is_show`,0 AS `is_disable`,'' AS `app_code`,'core' AS `source`,'core_tenant_power_market' AS `source_menu_key`,1 AS `is_core`
) child
WHERE parent.`source_menu_key` = 'core_tenant_power_mall'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = parent.`tenant_id`
      AND exists_menu.`source_menu_key` = child.`source_menu_key`
  );

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT market.`tenant_id`,market.`id`,child.`type`,child.`name`,child.`icon`,child.`sort`,child.`perms`,child.`paths`,child.`component`,child.`selected`,child.`params`,child.`is_cache`,child.`is_show`,child.`is_disable`,child.`app_code`,child.`source`,child.`source_menu_key`,child.`is_core`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` market
JOIN (
  SELECT 'A' AS `type`,'模型列表' AS `name`,'' AS `icon`,0 AS `sort`,'power.market/models' AS `perms`,'' AS `paths`,'' AS `component`,'' AS `selected`,'' AS `params`,0 AS `is_cache`,0 AS `is_show`,0 AS `is_disable`,'' AS `app_code`,'core' AS `source`,'core_tenant_power_market_models' AS `source_menu_key`,1 AS `is_core`
  UNION ALL SELECT 'A','详情','',0,'power.market/detail','','','','',0,0,0,'','core','core_tenant_power_market_detail',1
  UNION ALL SELECT 'A','应用列表','',0,'power.market/apps','','','','',0,0,0,'','core','core_tenant_power_market_apps',1
  UNION ALL SELECT 'A','应用详情','',0,'power.market/appDetail','','','','',0,0,0,'','core','core_tenant_power_market_app_detail',1
  UNION ALL SELECT 'A','保存配置','',0,'power.market/savePrices','','','','',0,0,0,'','core','core_tenant_power_market_save_prices',1
) child
WHERE market.`source_menu_key` = 'core_tenant_power_market'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = market.`tenant_id`
      AND exists_menu.`source_menu_key` = child.`source_menu_key`
  );

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT DISTINCT rm.`role_id`,menu.`id`
FROM `la_tenant_system_role_menu` rm
JOIN `la_tenant_system_role` role ON role.`id` = rm.`role_id`
JOIN `la_tenant_system_menu` menu ON menu.`tenant_id` = role.`tenant_id`
WHERE menu.`source_menu_key` IN (
  'core_tenant_power_market',
  'core_tenant_power_market_models',
  'core_tenant_power_market_detail',
  'core_tenant_power_market_apps',
  'core_tenant_power_market_app_detail',
  'core_tenant_power_market_save_prices'
)
  AND (role.`delete_time` IS NULL OR role.`delete_time` = 0);
