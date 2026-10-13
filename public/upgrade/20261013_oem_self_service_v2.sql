-- OEM_V2: additive schema, legacy orders remain flow_version=1.

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_package' AND COLUMN_NAME='package_code')=0,'ALTER TABLE `la_tenant_package` ADD COLUMN `package_code` varchar(64) DEFAULT NULL','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_package' AND COLUMN_NAME='package_version')=0,'ALTER TABLE `la_tenant_package` ADD COLUMN `package_version` int unsigned NOT NULL DEFAULT 1','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_package' AND COLUMN_NAME='family_package_id')=0,'ALTER TABLE `la_tenant_package` ADD COLUMN `family_package_id` int unsigned NOT NULL DEFAULT 0','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_package' AND COLUMN_NAME='prices_configured')=0,'ALTER TABLE `la_tenant_package` ADD COLUMN `prices_configured` tinyint unsigned NOT NULL DEFAULT 0','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_package' AND INDEX_NAME='uk_oem_code')=0,'ALTER TABLE `la_tenant_package` ADD UNIQUE KEY `uk_oem_code` (`package_code`)','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant' AND COLUMN_NAME='oem_sales_enabled')=0,'ALTER TABLE `la_tenant` ADD COLUMN `oem_sales_enabled` tinyint unsigned NOT NULL DEFAULT 0','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant' AND COLUMN_NAME='oem_flow_enabled')=0,'ALTER TABLE `la_tenant` ADD COLUMN `oem_flow_enabled` tinyint unsigned NOT NULL DEFAULT 0','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_order' AND COLUMN_NAME='flow_version')=0,'ALTER TABLE `la_tenant_brand_order` ADD COLUMN `flow_version` tinyint unsigned NOT NULL DEFAULT 1','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_order' AND COLUMN_NAME='order_kind')=0,'ALTER TABLE `la_tenant_brand_order` ADD COLUMN `order_kind` varchar(16) NOT NULL DEFAULT ''new''','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_order' AND COLUMN_NAME='workflow_state')=0,'ALTER TABLE `la_tenant_brand_order` ADD COLUMN `workflow_state` varchar(32) NOT NULL DEFAULT ''awaiting_payment''','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_order' AND COLUMN_NAME='tenant_review')=0,'ALTER TABLE `la_tenant_brand_order` ADD COLUMN `tenant_review` varchar(16) NOT NULL DEFAULT ''pending''','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_order' AND COLUMN_NAME='platform_review')=0,'ALTER TABLE `la_tenant_brand_order` ADD COLUMN `platform_review` varchar(16) NOT NULL DEFAULT ''pending''','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_order' AND COLUMN_NAME='package_snapshot')=0,'ALTER TABLE `la_tenant_brand_order` ADD COLUMN `package_snapshot` longtext','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_order' AND COLUMN_NAME='brand_snapshot')=0,'ALTER TABLE `la_tenant_brand_order` ADD COLUMN `brand_snapshot` longtext','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_order' AND COLUMN_NAME='review_note')=0,'ALTER TABLE `la_tenant_brand_order` ADD COLUMN `review_note` varchar(500) NOT NULL DEFAULT ''''','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_order' AND COLUMN_NAME='closed_time')=0,'ALTER TABLE `la_tenant_brand_order` ADD COLUMN `closed_time` int unsigned NOT NULL DEFAULT 0','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_order' AND COLUMN_NAME='delivered_time')=0,'ALTER TABLE `la_tenant_brand_order` ADD COLUMN `delivered_time` int unsigned NOT NULL DEFAULT 0','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_order' AND COLUMN_NAME='service_expire_time')=0,'ALTER TABLE `la_tenant_brand_order` ADD COLUMN `service_expire_time` int unsigned NOT NULL DEFAULT 0','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_order' AND COLUMN_NAME='last_remind_time')=0,'ALTER TABLE `la_tenant_brand_order` ADD COLUMN `last_remind_time` int unsigned NOT NULL DEFAULT 0','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_order' AND COLUMN_NAME='remind_count')=0,'ALTER TABLE `la_tenant_brand_order` ADD COLUMN `remind_count` int unsigned NOT NULL DEFAULT 0','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_order' AND INDEX_NAME='idx_oem_work')=0,'ALTER TABLE `la_tenant_brand_order` ADD KEY `idx_oem_work` (`flow_version`,`workflow_state`,`pay_time`)','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_order' AND INDEX_NAME='idx_oem_owner')=0,'ALTER TABLE `la_tenant_brand_order` ADD KEY `idx_oem_owner` (`tenant_id`,`user_id`,`id`)','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_quota_order' AND COLUMN_NAME='flow_version')=0,'ALTER TABLE `la_tenant_brand_quota_order` ADD COLUMN `flow_version` tinyint unsigned NOT NULL DEFAULT 1','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_quota_order' AND COLUMN_NAME='brand_order_id')=0,'ALTER TABLE `la_tenant_brand_quota_order` ADD COLUMN `brand_order_id` int unsigned NOT NULL DEFAULT 0','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_quota_order' AND COLUMN_NAME='package_snapshot')=0,'ALTER TABLE `la_tenant_brand_quota_order` ADD COLUMN `package_snapshot` longtext','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

SET @oem_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_brand_quota_order' AND COLUMN_NAME='closed_time')=0,'ALTER TABLE `la_tenant_brand_quota_order` ADD COLUMN `closed_time` int unsigned NOT NULL DEFAULT 0','SELECT 1'); PREPARE oem_stmt FROM @oem_sql; EXECUTE oem_stmt; DEALLOCATE PREPARE oem_stmt;

CREATE TABLE IF NOT EXISTS `la_tenant_brand_event` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `tenant_id` int unsigned NOT NULL,
  `actor_type` varchar(16) NOT NULL,
  `actor_id` int unsigned NOT NULL DEFAULT 0,
  `action` varchar(64) NOT NULL,
  `state` varchar(32) NOT NULL,
  `note` varchar(500) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `la_tenant_brand_payment_intent` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_from` varchar(32) NOT NULL,
  `order_id` int unsigned NOT NULL,
  `tenant_id` int unsigned NOT NULL,
  `pay_sn` varchar(64) NOT NULL,
  `channel` tinyint unsigned NOT NULL,
  `merchant_id` varchar(128) NOT NULL,
  `amount_cents` bigint unsigned NOT NULL,
  `currency` varchar(8) NOT NULL DEFAULT 'CNY',
  `context` longtext,
  `expires_at` int unsigned NOT NULL,
  `last_query_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pay_sn` (`pay_sn`),
  KEY `idx_order` (`order_from`,`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `la_tenant_brand_payment` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `intent_id` int unsigned NOT NULL,
  `order_from` varchar(32) NOT NULL,
  `order_id` int unsigned NOT NULL,
  `tenant_id` int unsigned NOT NULL,
  `channel` tinyint unsigned NOT NULL,
  `merchant_id` varchar(128) NOT NULL,
  `transaction_id` varchar(128) NOT NULL,
  `amount_cents` bigint unsigned NOT NULL,
  `currency` varchar(8) NOT NULL DEFAULT 'CNY',
  `disposition` varchar(16) NOT NULL DEFAULT 'primary',
  `paid_time` int unsigned NOT NULL,
  `context` longtext,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_trade` (`channel`,`merchant_id`,`transaction_id`),
  KEY `idx_order` (`order_from`,`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `la_tenant_brand_refund` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` int unsigned NOT NULL,
  `order_from` varchar(32) NOT NULL,
  `order_id` int unsigned NOT NULL,
  `tenant_id` int unsigned NOT NULL,
  `refund_sn` varchar(64) NOT NULL,
  `amount_cents` bigint unsigned NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'queued',
  `reason` varchar(255) NOT NULL DEFAULT '',
  `channel_refund_id` varchar(128) NOT NULL DEFAULT '',
  `last_error` varchar(500) NOT NULL DEFAULT '',
  `attempts` int unsigned NOT NULL DEFAULT 0,
  `next_check_time` int unsigned NOT NULL DEFAULT 0,
  `lease_token` varchar(64) NOT NULL DEFAULT '',
  `lease_until` int unsigned NOT NULL DEFAULT 0,
  `confirmed_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payment` (`payment_id`),
  UNIQUE KEY `uk_refund_sn` (`refund_sn`),
  KEY `idx_poll` (`status`,`next_check_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `la_tenant_brand_job` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'queued',
  `lease_token` varchar(64) NOT NULL DEFAULT '',
  `lease_until` int unsigned NOT NULL DEFAULT 0,
  `heartbeat_time` int unsigned NOT NULL DEFAULT 0,
  `attempts` int unsigned NOT NULL DEFAULT 0,
  `steps` longtext,
  `last_error` varchar(500) NOT NULL DEFAULT '',
  `runtime_generation` varchar(128) NOT NULL DEFAULT '',
  `finished_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order` (`order_id`),
  KEY `idx_claim` (`status`,`lease_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `la_tenant_brand_domain` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `tenant_id` int unsigned NOT NULL,
  `domain` varchar(255) NOT NULL,
  `challenge` varchar(128) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `verified_time` int unsigned NOT NULL DEFAULT 0,
  `last_check_time` int unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(500) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order` (`order_id`),
  KEY `idx_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `la_tenant_package` (`package_code`,`name`,`duration_days`,`sale_price`,`quota_price`,`status`,`sort`,`prices_configured`,`create_time`,`update_time`) SELECT 'oem_basic','基础版',365,0,0,0,30,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() WHERE NOT EXISTS(SELECT 1 FROM `la_tenant_package` WHERE `package_code`='oem_basic');

INSERT INTO `la_tenant_package` (`package_code`,`name`,`duration_days`,`sale_price`,`quota_price`,`status`,`sort`,`prices_configured`,`create_time`,`update_time`) SELECT 'oem_professional','专业版',365,0,0,0,20,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() WHERE NOT EXISTS(SELECT 1 FROM `la_tenant_package` WHERE `package_code`='oem_professional');

INSERT INTO `la_tenant_package` (`package_code`,`name`,`duration_days`,`sale_price`,`quota_price`,`status`,`sort`,`prices_configured`,`create_time`,`update_time`) SELECT 'oem_enterprise','企业版',365,0,0,0,10,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() WHERE NOT EXISTS(SELECT 1 FROM `la_tenant_package` WHERE `package_code`='oem_enterprise');

-- OEM workbench and least-privilege action menus.

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT 0,'M','贴牌业务','el-icon-Connection',640,'','brand','','','',0,1,0,'','core','core_oem_platform',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() WHERE NOT EXISTS(SELECT 1 FROM `la_system_menu` WHERE `source_menu_key`='core_oem_platform');

SET @oem_root := (SELECT id FROM `la_system_menu` WHERE source_menu_key='core_oem_platform' LIMIT 1);

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT @oem_root,'C','贴牌工作台','el-icon-Document',100,'brand.order/lists','order','brand/order','','',0,1,0,'','core','core_oem_platform_order',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() WHERE NOT EXISTS(SELECT 1 FROM `la_system_menu` WHERE source_menu_key='core_oem_platform_order');

SET @oem_order := (SELECT id FROM `la_system_menu` WHERE source_menu_key='core_oem_platform_order' LIMIT 1);

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT @oem_order,'A','订单详情','',0,'brand.order/detail','','','','',0,0,0,'','core','core_oem_platform_detail',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() WHERE NOT EXISTS(SELECT 1 FROM `la_system_menu` WHERE source_menu_key='core_oem_platform_detail');

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT @oem_order,'A','确认开通','',0,'brand.order/confirm','','','','',0,0,0,'','core','core_oem_platform_confirm',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() WHERE NOT EXISTS(SELECT 1 FROM `la_system_menu` WHERE source_menu_key='core_oem_platform_confirm');

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT @oem_order,'A','退回或拒绝','',0,'brand.order/review','','','','',0,0,0,'','core','core_oem_platform_review',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() WHERE NOT EXISTS(SELECT 1 FROM `la_system_menu` WHERE source_menu_key='core_oem_platform_review');

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT @oem_order,'A','重试开通','',0,'brand.order/retry','','','','',0,0,0,'','core','core_oem_platform_retry',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() WHERE NOT EXISTS(SELECT 1 FROM `la_system_menu` WHERE source_menu_key='core_oem_platform_retry');

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT @oem_order,'A','处理异常退款','',0,'brand.order/resolveRefund','','','','',0,0,0,'','core','core_oem_platform_resolveRefund',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() WHERE NOT EXISTS(SELECT 1 FROM `la_system_menu` WHERE source_menu_key='core_oem_platform_resolveRefund');

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT @oem_order,'A','销售租户','',0,'brand.order/sellers','','','','',0,0,0,'','core','core_oem_platform_sellers',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() WHERE NOT EXISTS(SELECT 1 FROM `la_system_menu` WHERE source_menu_key='core_oem_platform_sellers');

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT @oem_order,'A','销售资格配置','',0,'brand.order/saveSeller','','','','',0,0,0,'','core','core_oem_platform_saveSeller',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() WHERE NOT EXISTS(SELECT 1 FROM `la_system_menu` WHERE source_menu_key='core_oem_platform_saveSeller');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT p.tenant_id,p.id,'A','订单详情','',0,'brand.order/detail','','','','',0,0,0,'','core','core_oem_tenant_brand_order_detail',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.source_menu_key='core_tenant_brand_order' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=p.tenant_id AND e.source_menu_key='core_oem_tenant_brand_order_detail');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT p.tenant_id,p.id,'A','审核申请','',0,'brand.order/review','','','','',0,0,0,'','core','core_oem_tenant_brand_order_review',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.source_menu_key='core_tenant_brand_order' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=p.tenant_id AND e.source_menu_key='core_oem_tenant_brand_order_review');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT p.tenant_id,p.id,'A','提交平台','',0,'brand.order/submit','','','','',0,0,0,'','core','core_oem_tenant_brand_order_submit',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.source_menu_key='core_tenant_brand_order' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=p.tenant_id AND e.source_menu_key='core_oem_tenant_brand_order_submit');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT p.tenant_id,p.id,'A','预占额度','',0,'brand.order/reserve','','','','',0,0,0,'','core','core_oem_tenant_brand_order_reserve',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.source_menu_key='core_tenant_brand_order' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=p.tenant_id AND e.source_menu_key='core_oem_tenant_brand_order_reserve');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT p.tenant_id,p.id,'A','退款审核','',0,'brand.order/refundReview','','','','',0,0,0,'','core','core_oem_tenant_brand_order_refundReview',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.source_menu_key='core_tenant_brand_order' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=p.tenant_id AND e.source_menu_key='core_oem_tenant_brand_order_refundReview');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT p.tenant_id,p.id,'A','经营统计','',0,'brand.order/statistics','','','','',0,0,0,'','core','core_oem_tenant_brand_order_statistics',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.source_menu_key='core_tenant_brand_order' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=p.tenant_id AND e.source_menu_key='core_oem_tenant_brand_order_statistics');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT p.tenant_id,p.id,'A','采购详情','',0,'brand.quota/detail','','','','',0,0,0,'','core','core_oem_tenant_brand_quota_detail',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.source_menu_key='core_tenant_brand_quota' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=p.tenant_id AND e.source_menu_key='core_oem_tenant_brand_quota_detail');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT p.tenant_id,p.id,'A','按单采购','',0,'brand.quota/purchaseForOrder','','','','',0,0,0,'','core','core_oem_tenant_brand_quota_purchaseForOrder',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.source_menu_key='core_tenant_brand_quota' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=p.tenant_id AND e.source_menu_key='core_oem_tenant_brand_quota_purchaseForOrder');
