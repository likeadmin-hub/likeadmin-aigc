CREATE TABLE IF NOT EXISTS `la_power_market_price_rule` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `owner_id` int unsigned NOT NULL DEFAULT 0 COMMENT '0平台，其他租户ID',
 `scope_type` varchar(12) NOT NULL DEFAULT 'global',
 `scope_id` int unsigned NOT NULL DEFAULT 0,
 `mode` varchar(12) NOT NULL DEFAULT 'inherit',
 `value` decimal(16,6) NOT NULL DEFAULT 0,
 `revision` bigint unsigned NOT NULL DEFAULT 0,
 `follow_apps` tinyint unsigned NOT NULL DEFAULT 0,
 `create_time` int unsigned NOT NULL DEFAULT 0,
 `update_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_owner_scope` (`owner_id`,`scope_type`,`scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='算力市场两级溢价规则';
INSERT IGNORE INTO `la_power_market_price_rule` (`owner_id`,`scope_type`,`scope_id`,`mode`,`value`,`create_time`,`update_time`)
 SELECT 0,'sku',id,'manual',sale_points,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_power_market_sku` WHERE sale_points <> upstream_price AND NOT EXISTS (SELECT 1 FROM `la_power_market_price_rule` r WHERE r.owner_id=0 AND r.scope_type='global' AND r.scope_id=0);
INSERT IGNORE INTO `la_power_market_price_rule` (`owner_id`,`scope_type`,`scope_id`,`mode`,`value`,`create_time`,`update_time`)
 SELECT t.tenant_id,'sku',t.sku_id,'manual',t.sale_points,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_power_market_sku_price` t JOIN `la_power_market_sku` s ON s.id=t.sku_id WHERE t.sale_points <> s.sale_points AND NOT EXISTS (SELECT 1 FROM `la_power_market_price_rule` r WHERE r.owner_id=t.tenant_id AND r.scope_type='global' AND r.scope_id=0);
INSERT IGNORE INTO `la_power_market_price_rule` (`owner_id`,`scope_type`,`scope_id`,`mode`,`value`,`create_time`,`update_time`) VALUES (0,'global',0,'inherit',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
INSERT IGNORE INTO `la_power_market_price_rule` (`owner_id`,`scope_type`,`scope_id`,`mode`,`value`,`create_time`,`update_time`) SELECT DISTINCT owner_id,'global',0,'inherit',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_power_market_price_rule` WHERE owner_id>0;
