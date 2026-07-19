CREATE TABLE IF NOT EXISTS `la_tenant_domain_alias` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
  `domain` varchar(255) NOT NULL DEFAULT '' COMMENT '域名别名',
  `is_primary` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否主域名',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态 0禁用 1启用',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_domain` (`domain`),
  KEY `idx_tenant_primary` (`tenant_id`,`is_primary`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户域名别名';

CREATE TABLE IF NOT EXISTS `la_tenant_package` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL DEFAULT '' COMMENT '套餐名称',
  `duration_days` int unsigned NOT NULL DEFAULT 0 COMMENT '有效期天数',
  `sale_price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '平台建议售价',
  `quota_price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '租户采购单个额度价格',
  `quota_unit` varchar(20) NOT NULL DEFAULT '个' COMMENT '额度单位',
  `description` varchar(500) NOT NULL DEFAULT '' COMMENT '说明',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态 0停用 1启用',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户开通套餐';

CREATE TABLE IF NOT EXISTS `la_tenant_package_app_plan` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `package_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户套餐ID',
  `app_code` varchar(64) NOT NULL DEFAULT '' COMMENT '应用编码',
  `app_plan_id` int unsigned NOT NULL DEFAULT 0 COMMENT '应用套餐ID',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_package_app_plan` (`package_id`,`app_code`,`app_plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户套餐绑定应用套餐';

CREATE TABLE IF NOT EXISTS `la_tenant_contract_order` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `order_sn` varchar(64) NOT NULL DEFAULT '',
  `order_terminal` tinyint unsigned NOT NULL DEFAULT 0,
  `package_id` int unsigned NOT NULL DEFAULT 0,
  `package_name` varchar(80) NOT NULL DEFAULT '',
  `duration_days` int unsigned NOT NULL DEFAULT 0,
  `order_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `pay_way` tinyint unsigned NOT NULL DEFAULT 0,
  `pay_status` tinyint unsigned NOT NULL DEFAULT 0,
  `pay_sn` varchar(64) NOT NULL DEFAULT '',
  `transaction_id` varchar(128) NOT NULL DEFAULT '',
  `pay_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_sn` (`order_sn`),
  KEY `idx_tenant_pay` (`tenant_id`,`pay_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户合约订单';

CREATE TABLE IF NOT EXISTS `la_tenant_contract_record` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `package_id` int unsigned NOT NULL DEFAULT 0,
  `package_name` varchar(80) NOT NULL DEFAULT '',
  `duration_days` int unsigned NOT NULL DEFAULT 0,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `start_time` int unsigned NOT NULL DEFAULT 0,
  `old_expire_time` int unsigned NOT NULL DEFAULT 0,
  `expire_time` int unsigned NOT NULL DEFAULT 0,
  `operator_id` int unsigned NOT NULL DEFAULT 0,
  `source_order_sn` varchar(64) NOT NULL DEFAULT '',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_time` (`tenant_id`,`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户合约开通记录';

CREATE TABLE IF NOT EXISTS `la_tenant_brand_quota_order` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '采购租户ID',
  `admin_id` int unsigned NOT NULL DEFAULT 0,
  `order_sn` varchar(64) NOT NULL DEFAULT '',
  `order_terminal` tinyint unsigned NOT NULL DEFAULT 0,
  `package_id` int unsigned NOT NULL DEFAULT 0,
  `package_name` varchar(80) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 0,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `order_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `pay_way` tinyint unsigned NOT NULL DEFAULT 0,
  `pay_status` tinyint unsigned NOT NULL DEFAULT 0,
  `pay_sn` varchar(64) NOT NULL DEFAULT '',
  `transaction_id` varchar(128) NOT NULL DEFAULT '',
  `pay_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_sn` (`order_sn`),
  KEY `idx_tenant_pay` (`tenant_id`,`pay_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户采购贴牌额度订单';

CREATE TABLE IF NOT EXISTS `la_tenant_brand_quota_bucket` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `package_id` int unsigned NOT NULL DEFAULT 0,
  `total_quota` int unsigned NOT NULL DEFAULT 0,
  `remaining_quota` int unsigned NOT NULL DEFAULT 0,
  `used_quota` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_package` (`tenant_id`,`package_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户贴牌额度库存';

CREATE TABLE IF NOT EXISTS `la_tenant_brand_quota_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `package_id` int unsigned NOT NULL DEFAULT 0,
  `change_type` varchar(30) NOT NULL DEFAULT '',
  `change_quota` int NOT NULL DEFAULT 0,
  `before_quota` int NOT NULL DEFAULT 0,
  `after_quota` int NOT NULL DEFAULT 0,
  `source_sn` varchar(64) NOT NULL DEFAULT '',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_package` (`tenant_id`,`package_id`),
  KEY `idx_source` (`source_sn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户贴牌额度流水';

CREATE TABLE IF NOT EXISTS `la_tenant_brand_package_price` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `package_id` int unsigned NOT NULL DEFAULT 0,
  `sale_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` tinyint unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_package` (`tenant_id`,`package_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户贴牌套餐定价';

CREATE TABLE IF NOT EXISTS `la_tenant_brand_order` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '售卖租户ID',
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `order_sn` varchar(64) NOT NULL DEFAULT '',
  `order_terminal` tinyint unsigned NOT NULL DEFAULT 0,
  `package_id` int unsigned NOT NULL DEFAULT 0,
  `package_name` varchar(80) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `order_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `target_tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '续费目标租户',
  `child_tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '开通后租户',
  `child_tenant_name` varchar(80) NOT NULL DEFAULT '',
  `child_domain_alias` varchar(255) NOT NULL DEFAULT '',
  `admin_account` varchar(64) NOT NULL DEFAULT '',
  `admin_password_hash` varchar(255) NOT NULL DEFAULT '',
  `pay_way` tinyint unsigned NOT NULL DEFAULT 0,
  `pay_status` tinyint unsigned NOT NULL DEFAULT 0,
  `pay_sn` varchar(64) NOT NULL DEFAULT '',
  `transaction_id` varchar(128) NOT NULL DEFAULT '',
  `pay_time` int unsigned NOT NULL DEFAULT 0,
  `open_status` tinyint unsigned NOT NULL DEFAULT 0,
  `open_time` int unsigned NOT NULL DEFAULT 0,
  `open_error` varchar(500) NOT NULL DEFAULT '' COMMENT '开通失败原因',
  `refund_status` tinyint unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_sn` (`order_sn`),
  KEY `idx_tenant_pay` (`tenant_id`,`pay_status`),
  KEY `idx_child_tenant` (`child_tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='终端用户购买贴牌租户订单';

SET @db := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='la_tenant_brand_order' AND COLUMN_NAME='open_error')=0,'ALTER TABLE `la_tenant_brand_order` ADD COLUMN `open_error` varchar(500) NOT NULL DEFAULT '''' COMMENT ''开通失败原因'' AFTER `open_time`','SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='la_tenant' AND COLUMN_NAME='contract_package_id')=0,'ALTER TABLE `la_tenant` ADD COLUMN `contract_package_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''当前套餐ID'' AFTER `domain_alias_enable`','SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='la_tenant' AND COLUMN_NAME='contract_package_name')=0,'ALTER TABLE `la_tenant` ADD COLUMN `contract_package_name` varchar(80) NOT NULL DEFAULT '''' COMMENT ''当前套餐名称'' AFTER `contract_package_id`','SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='la_tenant' AND COLUMN_NAME='contract_start_time')=0,'ALTER TABLE `la_tenant` ADD COLUMN `contract_start_time` int unsigned NOT NULL DEFAULT 0 COMMENT ''合同开始时间'' AFTER `contract_package_name`','SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='la_tenant' AND COLUMN_NAME='contract_expire_time')=0,'ALTER TABLE `la_tenant` ADD COLUMN `contract_expire_time` int unsigned NOT NULL DEFAULT 0 COMMENT ''合同到期时间'' AFTER `contract_start_time`','SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='la_tenant' AND COLUMN_NAME='contract_renew_time')=0,'ALTER TABLE `la_tenant` ADD COLUMN `contract_renew_time` int unsigned NOT NULL DEFAULT 0 COMMENT ''最近续费时间'' AFTER `contract_expire_time`','SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='la_tenant' AND COLUMN_NAME='contract_status')=0,'ALTER TABLE `la_tenant` ADD COLUMN `contract_status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT ''合同状态 0未签约 1有效 2到期'' AFTER `contract_renew_time`','SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='la_tenant' AND COLUMN_NAME='parent_tenant_id')=0,'ALTER TABLE `la_tenant` ADD COLUMN `parent_tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''父级租户ID'' AFTER `contract_status`','SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='la_tenant' AND COLUMN_NAME='source_tenant_id')=0,'ALTER TABLE `la_tenant` ADD COLUMN `source_tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''来源租户ID'' AFTER `parent_tenant_id`','SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='la_tenant_app_order' AND COLUMN_NAME='remark')=0,'ALTER TABLE `la_tenant_app_order` ADD COLUMN `remark` varchar(255) NOT NULL DEFAULT '''' COMMENT ''备注'' AFTER `pay_time`','SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='la_tenant_app_order' AND COLUMN_NAME='source_sn')=0,'ALTER TABLE `la_tenant_app_order` ADD COLUMN `source_sn` varchar(64) NOT NULL DEFAULT '''' COMMENT ''来源单号'' AFTER `remark`','SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `la_tenant_domain_alias` (`tenant_id`,`domain`,`is_primary`,`status`,`create_time`,`update_time`)
SELECT `id`, LOWER(TRIM(TRAILING '/' FROM SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(CASE WHEN LOWER(TRIM(`domain_alias`)) LIKE 'https://%' THEN SUBSTRING(TRIM(`domain_alias`), 9) WHEN LOWER(TRIM(`domain_alias`)) LIKE 'http://%' THEN SUBSTRING(TRIM(`domain_alias`), 8) ELSE TRIM(`domain_alias`) END,'/',1),'?',1),'#',1),':',1))), 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `la_tenant`
WHERE `domain_alias` IS NOT NULL AND TRIM(`domain_alias`) <> '';

SET @tenant_root_id := COALESCE((SELECT `id` FROM `la_system_menu` WHERE `paths`='tenant' AND `pid`=0 LIMIT 1), 117);
INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT @tenant_root_id,'C','租户套餐','el-icon-Tickets',95,'tenant.package/lists','package','tenant/package/index','','',0,1,0,'','core','core_tenant_package_platform',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_system_menu` WHERE `source_menu_key`='core_tenant_package_platform');
SET @platform_tenant_package_id := (SELECT `id` FROM `la_system_menu` WHERE `source_menu_key`='core_tenant_package_platform' LIMIT 1);
UPDATE `la_system_menu` SET `pid`=@tenant_root_id,`name`='租户套餐',`perms`='tenant.package/lists',`paths`='package',`component`='tenant/package/index',`source`='core',`is_core`=1,`update_time`=UNIX_TIMESTAMP() WHERE `source_menu_key`='core_tenant_package_platform';
INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT @platform_tenant_package_id,x.`type`,x.`name`,'',0,x.`perms`,'','','','',0,0,0,'','core',x.`source_menu_key`,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (
  SELECT 'A' AS `type`,'新增套餐' AS `name`,'tenant.package/add' AS `perms`,'core_tenant_package_platform_add' AS `source_menu_key`
  UNION ALL SELECT 'A','编辑套餐','tenant.package/edit','core_tenant_package_platform_edit'
  UNION ALL SELECT 'A','删除套餐','tenant.package/delete','core_tenant_package_platform_delete'
  UNION ALL SELECT 'A','套餐详情','tenant.package/detail','core_tenant_package_platform_detail'
  UNION ALL SELECT 'A','应用套餐选项','tenant.package/appPlans','core_tenant_package_platform_app_plans'
  UNION ALL SELECT 'A','额度订单','tenant.package/quotaOrders','core_tenant_package_platform_quota_orders'
  UNION ALL SELECT 'A','贴牌订单','tenant.package/brandOrders','core_tenant_package_platform_brand_orders'
) x
WHERE @platform_tenant_package_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `la_system_menu` e WHERE e.`source_menu_key`=x.`source_menu_key`);

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT tenants.`tenant_id`,0,'M','贴牌管理','el-icon-Connection',650,'','brand','','','',0,1,0,'','core','core_tenant_brand',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (SELECT DISTINCT `tenant_id` FROM `la_tenant_system_menu`) tenants
WHERE NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` m WHERE m.`tenant_id`=tenants.`tenant_id` AND m.`source_menu_key`='core_tenant_brand');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT brand.`tenant_id`,brand.`id`,child.`type`,child.`name`,child.`icon`,child.`sort`,child.`perms`,child.`paths`,child.`component`,'','',0,child.`is_show`,0,'','core',child.`source_menu_key`,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` brand
JOIN (
  SELECT 'C' AS `type`,'贴牌套餐' AS `name`,'el-icon-PriceTag' AS `icon`,100 AS `sort`,'brand.package/lists' AS `perms`,'package' AS `paths`,'brand/package' AS `component`,1 AS `is_show`,'core_tenant_brand_package' AS `source_menu_key`
  UNION ALL SELECT 'C','额度购买','el-icon-ShoppingCart',90,'brand.quota/orders','quota','brand/quota',1,'core_tenant_brand_quota'
  UNION ALL SELECT 'C','订单管理','el-icon-Document',80,'brand.order/lists','order','brand/order',1,'core_tenant_brand_order'
) child
WHERE brand.`source_menu_key`='core_tenant_brand'
  AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` e WHERE e.`tenant_id`=brand.`tenant_id` AND e.`source_menu_key`=child.`source_menu_key`);

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT parent.`tenant_id`,parent.`id`,'A',child.`name`,'',0,child.`perms`,'','','','',0,0,0,'','core',child.`source_menu_key`,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
JOIN (
  SELECT 'core_tenant_brand_package' AS `parent_key`,'保存定价' AS `name`,'brand.package/savePrice' AS `perms`,'core_tenant_brand_package_save' AS `source_menu_key`
  UNION ALL SELECT 'core_tenant_brand_quota','套餐列表','brand.quota/packages','core_tenant_brand_quota_packages'
  UNION ALL SELECT 'core_tenant_brand_quota','创建订单','brand.quota/createOrder','core_tenant_brand_quota_create'
  UNION ALL SELECT 'core_tenant_brand_quota','支付方式','brand.pay/payWay','core_tenant_brand_pay_way'
  UNION ALL SELECT 'core_tenant_brand_quota','预支付','brand.pay/prepay','core_tenant_brand_pay_prepay'
  UNION ALL SELECT 'core_tenant_brand_quota','支付状态','brand.pay/payStatus','core_tenant_brand_pay_status'
) child ON parent.`source_menu_key`=child.`parent_key`
WHERE NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` e WHERE e.`tenant_id`=parent.`tenant_id` AND e.`source_menu_key`=child.`source_menu_key`);

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT DISTINCT rm.`role_id`,menu.`id`
FROM `la_tenant_system_role_menu` rm
JOIN `la_tenant_system_role` role ON role.`id`=rm.`role_id`
JOIN `la_tenant_system_menu` menu ON menu.`tenant_id`=role.`tenant_id`
WHERE menu.`source_menu_key` IN ('core_tenant_brand','core_tenant_brand_package','core_tenant_brand_package_save','core_tenant_brand_quota','core_tenant_brand_quota_packages','core_tenant_brand_quota_create','core_tenant_brand_pay_way','core_tenant_brand_pay_prepay','core_tenant_brand_pay_status','core_tenant_brand_order')
  AND (role.`delete_time` IS NULL OR role.`delete_time`=0);

INSERT INTO `la_dev_crontab` (`name`,`type`,`system`,`remark`,`command`,`params`,`status`,`expression`,`error`,`last_time`,`time`,`max_time`,`create_time`,`update_time`,`delete_time`)
SELECT '租户到期扫描', 1, 1, '每日扫描已到期租户并禁用访问', 'tenant:expire_contracts', '', 1, '10 2 * * *', NULL, NULL, '0', '0', UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), NULL
WHERE NOT EXISTS (SELECT 1 FROM `la_dev_crontab` WHERE `command`='tenant:expire_contracts');

INSERT INTO `la_app_plan` (`app_code`,`name`,`duration_months`,`open_points`,`renew_points`,`status`,`sort`,`create_time`,`update_time`)
SELECT a.`code`,'默认套餐',12,0.00,0.00,1,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_app` a
WHERE a.`status`='installed'
  AND a.`code` <> 'system_default'
  AND NOT EXISTS (
    SELECT 1 FROM `la_app_plan` p WHERE p.`app_code`=a.`code` AND p.`status`=1
  );
