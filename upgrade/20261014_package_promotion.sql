-- Additive promotion/quote state. Existing packages and orders remain unchanged.
CREATE TABLE IF NOT EXISTS `la_tenant_package_promotion` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL DEFAULT 0,
 `biz_type` varchar(24) NOT NULL, `package_id` int unsigned NOT NULL,
 `enabled` tinyint unsigned NOT NULL DEFAULT 0, `rule_new` tinyint unsigned NOT NULL DEFAULT 0,
 `rule_all` tinyint unsigned NOT NULL DEFAULT 0, `hours` int unsigned NOT NULL DEFAULT 48,
 `discount_prices` longtext, `all_start` int unsigned NOT NULL DEFAULT 0, `all_end` int unsigned NOT NULL DEFAULT 0,
 `version` int unsigned NOT NULL DEFAULT 1, `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_package` (`tenant_id`,`biz_type`,`package_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_tenant_package_discount_claim` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL,
 `user_id` int unsigned NOT NULL, `biz_type` varchar(24) NOT NULL, `package_id` int unsigned NOT NULL,
 `status` varchar(16) NOT NULL DEFAULT 'available', `order_id` int unsigned NOT NULL DEFAULT 0,
 `reserved_until` int unsigned NOT NULL DEFAULT 0, `consumed_at` int unsigned NOT NULL DEFAULT 0,
 `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_user_package` (`tenant_id`,`user_id`,`biz_type`,`package_id`),
 KEY `idx_reserved` (`status`,`reserved_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_tenant_package_price_snapshot` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `biz_type` varchar(24) NOT NULL, `order_id` int unsigned NOT NULL, `order_sn` varchar(64) NOT NULL,
 `package_id` int unsigned NOT NULL DEFAULT 0, `cycle` varchar(16) NOT NULL DEFAULT 'package',
 `regular_cents` bigint unsigned NOT NULL, `payable_cents` bigint unsigned NOT NULL,
 `is_discount` tinyint unsigned NOT NULL DEFAULT 0, `promotion_version` int unsigned NOT NULL DEFAULT 0,
 `matched_rules` varchar(64) NOT NULL DEFAULT '', `eligible_end_at` int unsigned NOT NULL DEFAULT 0,
 `expires_at` int unsigned NOT NULL, `status` varchar(16) NOT NULL DEFAULT 'pending',
 `rights` longtext, `created_at` int unsigned NOT NULL, `paid_at` int unsigned NOT NULL DEFAULT 0,
 `channel` int unsigned NOT NULL DEFAULT 0, `closed_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_order` (`tenant_id`,`biz_type`,`order_id`),
 UNIQUE KEY `uk_sn` (`tenant_id`,`biz_type`,`order_sn`), KEY `idx_due` (`status`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`create_time`,`update_time`)
SELECT 0,p.id,'C','虚拟支付','el-icon-Wallet',80,'finance.virtual_pay/detail','virtual_pay','finance/virtual_pay','','',0,1,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` p WHERE p.tenant_id=0 AND p.paths='package' AND p.type='M' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=0 AND e.perms='finance.virtual_pay/detail');
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`create_time`,`update_time`)
SELECT 0,p.id,'A','保存优惠','',0,'finance.promotion/save','','','','',0,0,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` p WHERE p.tenant_id=0 AND p.paths='package' AND p.type='M' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=0 AND e.perms='finance.promotion/save');
