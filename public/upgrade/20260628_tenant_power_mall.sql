CREATE TABLE IF NOT EXISTS `la_tenant_power_package` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(20) NOT NULL DEFAULT 'points' COMMENT '套餐类型:member会员套餐 points点数套餐',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '套餐名称',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '套餐说明',
  `duration_months` int unsigned NOT NULL DEFAULT 0 COMMENT '会员套餐有效月数',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '租户购买金额',
  `points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '赠送点数',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态:0停用 1启用',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type_status` (`type`,`status`,`sort`),
  KEY `idx_status_sort` (`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户算力商城套餐';

CREATE TABLE IF NOT EXISTS `la_tenant_power_order` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
  `admin_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户管理员ID',
  `order_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '订单编号',
  `pay_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '支付编号',
  `transaction_id` varchar(128) NOT NULL DEFAULT '' COMMENT '第三方交易号',
  `order_terminal` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '下单终端',
  `package_id` int unsigned NOT NULL DEFAULT 0 COMMENT '套餐ID',
  `package_type` varchar(20) NOT NULL DEFAULT '' COMMENT '套餐类型快照',
  `package_name` varchar(100) NOT NULL DEFAULT '' COMMENT '套餐名称快照',
  `duration_months` int unsigned NOT NULL DEFAULT 0 COMMENT '有效月数快照',
  `order_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '订单金额',
  `points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '到账点数',
  `expire_time` int unsigned NOT NULL DEFAULT 0 COMMENT '点数过期时间,0永久有效',
  `pay_way` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '支付方式',
  `pay_status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '支付状态',
  `pay_time` int unsigned NOT NULL DEFAULT 0 COMMENT '支付时间',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_sn` (`order_sn`),
  KEY `idx_tenant_status` (`tenant_id`,`pay_status`,`create_time`),
  KEY `idx_package` (`package_id`,`package_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户算力商城订单';

CREATE TABLE IF NOT EXISTS `la_tenant_point_bucket` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
  `source_order_sn` varchar(64) NOT NULL DEFAULT '' COMMENT '来源算力商城订单号',
  `package_type` varchar(20) NOT NULL DEFAULT '' COMMENT '套餐类型',
  `total_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '批次总点数',
  `remaining_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '批次剩余点数',
  `expire_time` int unsigned NOT NULL DEFAULT 0 COMMENT '过期时间,0永久有效',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态:1有效 2已过期',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_order` (`tenant_id`,`source_order_sn`),
  KEY `idx_tenant_expire` (`tenant_id`,`status`,`expire_time`),
  KEY `idx_remaining` (`tenant_id`,`status`,`remaining_points`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户算力商城点数批次';

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,'M','算力商城','el-icon-Goods',700,'','power-mall','','','',0,1,0,'','core','core_power_mall',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (
  SELECT 1 FROM `la_system_menu` WHERE `source_menu_key` = 'core_power_mall'
);

SET @core_power_mall_id := (
  SELECT `id` FROM `la_system_menu`
  WHERE `source_menu_key` = 'core_power_mall'
  ORDER BY `id` DESC
  LIMIT 1
);

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT @core_power_mall_id,'C','算力套餐','el-icon-Coin',100,'power.package/lists','package','power_mall/package','','',0,1,0,'','core','core_power_mall_package',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE @core_power_mall_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `la_system_menu` WHERE `source_menu_key` = 'core_power_mall_package'
  );

SET @core_power_package_id := (
  SELECT `id` FROM `la_system_menu`
  WHERE `source_menu_key` = 'core_power_mall_package'
  ORDER BY `id` DESC
  LIMIT 1
);

UPDATE `la_system_menu`
SET `pid` = @core_power_mall_id, `update_time` = UNIX_TIMESTAMP()
WHERE @core_power_mall_id IS NOT NULL
  AND `source_menu_key` = 'core_power_mall_package'
  AND `pid` <> @core_power_mall_id;

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT @core_power_package_id, child.`type`, child.`name`, child.`icon`, child.`sort`, child.`perms`, child.`paths`, child.`component`, child.`selected`, child.`params`, child.`is_cache`, child.`is_show`, child.`is_disable`, child.`app_code`, child.`source`, child.`source_menu_key`, child.`is_core`, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM (
  SELECT 'A' AS `type`,'详情' AS `name`,'' AS `icon`,0 AS `sort`,'power.package/detail' AS `perms`,'' AS `paths`,'' AS `component`,'' AS `selected`,'' AS `params`,0 AS `is_cache`,0 AS `is_show`,0 AS `is_disable`,'' AS `app_code`,'core' AS `source`,'core_power_mall_package_detail' AS `source_menu_key`,1 AS `is_core`
  UNION ALL SELECT 'A','新增','',0,'power.package/add','','','','',0,0,0,'','core','core_power_mall_package_add',1
  UNION ALL SELECT 'A','编辑','',0,'power.package/edit','','','','',0,0,0,'','core','core_power_mall_package_edit',1
  UNION ALL SELECT 'A','删除','',0,'power.package/delete','','','','',0,0,0,'','core','core_power_mall_package_delete',1
  UNION ALL SELECT 'A','订单记录','',0,'power.package/orders','','','','',0,0,0,'','core','core_power_mall_package_orders',1
  UNION ALL SELECT 'A','套餐类型','',0,'power.package/types','','','','',0,0,0,'','core','core_power_mall_package_types',1
) child
WHERE @core_power_package_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `la_system_menu` exists_menu
    WHERE exists_menu.`source_menu_key` = child.`source_menu_key`
  );

UPDATE `la_system_menu`
SET `pid` = @core_power_package_id, `update_time` = UNIX_TIMESTAMP()
WHERE @core_power_package_id IS NOT NULL
  AND `source_menu_key` IN (
    'core_power_mall_package_detail',
    'core_power_mall_package_add',
    'core_power_mall_package_edit',
    'core_power_mall_package_delete',
    'core_power_mall_package_orders',
    'core_power_mall_package_types'
  )
  AND `pid` <> @core_power_package_id;

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT @core_power_mall_id,'C','支付配置','el-icon-CreditCard',90,'power.pay_way/getPayWay','pay-config','power_mall/pay_config/index','','',0,1,0,'','core','core_power_mall_pay_config',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE @core_power_mall_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `la_system_menu` WHERE `source_menu_key` = 'core_power_mall_pay_config'
  );

SET @core_power_pay_config_id := (
  SELECT `id` FROM `la_system_menu`
  WHERE `source_menu_key` = 'core_power_mall_pay_config'
  ORDER BY `id` DESC
  LIMIT 1
);

UPDATE `la_system_menu`
SET `pid` = @core_power_mall_id, `update_time` = UNIX_TIMESTAMP()
WHERE @core_power_mall_id IS NOT NULL
  AND `source_menu_key` = 'core_power_mall_pay_config'
  AND `pid` <> @core_power_mall_id;

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT @core_power_pay_config_id, child.`type`, child.`name`, child.`icon`, child.`sort`, child.`perms`, child.`paths`, child.`component`, child.`selected`, child.`params`, child.`is_cache`, child.`is_show`, child.`is_disable`, child.`app_code`, child.`source`, child.`source_menu_key`, child.`is_core`, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM (
  SELECT 'A' AS `type`,'设置支付方式' AS `name`,'' AS `icon`,0 AS `sort`,'power.pay_way/setPayWay' AS `perms`,'' AS `paths`,'' AS `component`,'' AS `selected`,'' AS `params`,0 AS `is_cache`,0 AS `is_show`,0 AS `is_disable`,'' AS `app_code`,'core' AS `source`,'core_power_mall_pay_way_set' AS `source_menu_key`,1 AS `is_core`
  UNION ALL SELECT 'A','支付配置列表','',0,'power.pay_config/lists','','','','',0,0,0,'','core','core_power_mall_pay_config_lists',1
  UNION ALL SELECT 'A','支付配置详情','',0,'power.pay_config/getConfig','','','','',0,0,0,'','core','core_power_mall_pay_config_detail',1
  UNION ALL SELECT 'A','保存支付配置','',0,'power.pay_config/setConfig','','','','',0,0,0,'','core','core_power_mall_pay_config_set',1
) child
WHERE @core_power_pay_config_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `la_system_menu` exists_menu
    WHERE exists_menu.`source_menu_key` = child.`source_menu_key`
  );

UPDATE `la_system_menu`
SET `pid` = @core_power_pay_config_id, `update_time` = UNIX_TIMESTAMP()
WHERE @core_power_pay_config_id IS NOT NULL
  AND `source_menu_key` IN (
    'core_power_mall_pay_way_set',
    'core_power_mall_pay_config_lists',
    'core_power_mall_pay_config_detail',
    'core_power_mall_pay_config_set'
  )
  AND `pid` <> @core_power_pay_config_id;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,0,'M','算力商城','el-icon-Goods',70,'','power-mall','','','',0,1,0,'','core','core_tenant_power_mall',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (
  SELECT 1 FROM `la_tenant_system_menu`
  WHERE `tenant_id` = 0 AND `source_menu_key` = 'core_tenant_power_mall'
);

SET @core_tenant_power_template_id := (
  SELECT `id` FROM `la_tenant_system_menu`
  WHERE `tenant_id` = 0 AND `source_menu_key` = 'core_tenant_power_mall'
  ORDER BY `id` DESC
  LIMIT 1
);

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,@core_tenant_power_template_id,child.`type`,child.`name`,child.`icon`,child.`sort`,child.`perms`,child.`paths`,child.`component`,child.`selected`,child.`params`,child.`is_cache`,child.`is_show`,child.`is_disable`,child.`app_code`,child.`source`,child.`source_menu_key`,child.`is_core`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (
  SELECT 'C' AS `type`,'购买算力' AS `name`,'el-icon-Coin' AS `icon`,100 AS `sort`,'power.mall/packages' AS `perms`,'buy' AS `paths`,'power_mall/index' AS `component`,'' AS `selected`,'' AS `params`,0 AS `is_cache`,1 AS `is_show`,0 AS `is_disable`,'' AS `app_code`,'core' AS `source`,'core_tenant_power_mall_buy' AS `source_menu_key`,1 AS `is_core`
  UNION ALL SELECT 'C','购买记录','el-icon-Document',90,'power.mall/orders','records','power_mall/records','','',0,1,0,'','core','core_tenant_power_mall_records',1
) child
WHERE @core_tenant_power_template_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = 0
      AND exists_menu.`source_menu_key` = child.`source_menu_key`
  );

UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` parent
  ON parent.`tenant_id` = child.`tenant_id`
  AND parent.`source_menu_key` = 'core_tenant_power_mall'
SET child.`pid` = parent.`id`, child.`update_time` = UNIX_TIMESTAMP()
WHERE child.`source_menu_key` IN ('core_tenant_power_mall_buy','core_tenant_power_mall_records')
  AND child.`pid` <> parent.`id`;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT buy.`tenant_id`,buy.`id`,child.`type`,child.`name`,child.`icon`,child.`sort`,child.`perms`,child.`paths`,child.`component`,child.`selected`,child.`params`,child.`is_cache`,child.`is_show`,child.`is_disable`,child.`app_code`,child.`source`,child.`source_menu_key`,child.`is_core`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` buy
JOIN (
  SELECT 'A' AS `type`,'点数概览' AS `name`,'' AS `icon`,0 AS `sort`,'power.mall/stats' AS `perms`,'' AS `paths`,'' AS `component`,'' AS `selected`,'' AS `params`,0 AS `is_cache`,0 AS `is_show`,0 AS `is_disable`,'' AS `app_code`,'core' AS `source`,'core_tenant_power_mall_stats' AS `source_menu_key`,1 AS `is_core`
  UNION ALL
  SELECT 'A' AS `type`,'创建订单' AS `name`,'' AS `icon`,0 AS `sort`,'power.mall/createOrder' AS `perms`,'' AS `paths`,'' AS `component`,'' AS `selected`,'' AS `params`,0 AS `is_cache`,0 AS `is_show`,0 AS `is_disable`,'' AS `app_code`,'core' AS `source`,'core_tenant_power_mall_create_order' AS `source_menu_key`,1 AS `is_core`
  UNION ALL SELECT 'A','支付方式','',0,'power.pay/payWay','','','','',0,0,0,'','core','core_tenant_power_pay_way',1
  UNION ALL SELECT 'A','预支付','',0,'power.pay/prepay','','','','',0,0,0,'','core','core_tenant_power_pay_prepay',1
  UNION ALL SELECT 'A','支付状态','',0,'power.pay/payStatus','','','','',0,0,0,'','core','core_tenant_power_pay_status',1
) child
WHERE buy.`source_menu_key` = 'core_tenant_power_mall_buy'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = buy.`tenant_id`
      AND exists_menu.`source_menu_key` = child.`source_menu_key`
  );

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT records.`tenant_id`,records.`id`,'A','订单详情','',0,'power.mall/orderDetail','','','','',0,0,0,'','core','core_tenant_power_mall_order_detail',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` records
WHERE records.`source_menu_key` = 'core_tenant_power_mall_records'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = records.`tenant_id`
      AND exists_menu.`source_menu_key` = 'core_tenant_power_mall_order_detail'
  );

UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` buy
  ON buy.`tenant_id` = child.`tenant_id`
  AND buy.`source_menu_key` = 'core_tenant_power_mall_buy'
SET child.`pid` = buy.`id`, child.`update_time` = UNIX_TIMESTAMP()
WHERE child.`source_menu_key` IN (
    'core_tenant_power_mall_stats',
    'core_tenant_power_mall_create_order',
    'core_tenant_power_pay_way',
    'core_tenant_power_pay_prepay',
    'core_tenant_power_pay_status'
  )
  AND child.`pid` <> buy.`id`;

UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` records
  ON records.`tenant_id` = child.`tenant_id`
  AND records.`source_menu_key` = 'core_tenant_power_mall_records'
SET child.`pid` = records.`id`, child.`update_time` = UNIX_TIMESTAMP()
WHERE child.`source_menu_key` = 'core_tenant_power_mall_order_detail'
  AND child.`pid` <> records.`id`;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT ta.`id`,0,'M','算力商城','el-icon-Goods',70,'','power-mall','','','',0,1,0,'','core','core_tenant_power_mall',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant` ta
WHERE (ta.`delete_time` IS NULL OR ta.`delete_time` = 0)
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` m
    WHERE m.`tenant_id` = ta.`id`
      AND m.`source_menu_key` = 'core_tenant_power_mall'
  );

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT parent.`tenant_id`,parent.`id`,child.`type`,child.`name`,child.`icon`,child.`sort`,child.`perms`,child.`paths`,child.`component`,child.`selected`,child.`params`,child.`is_cache`,child.`is_show`,child.`is_disable`,child.`app_code`,child.`source`,child.`source_menu_key`,child.`is_core`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
JOIN (
  SELECT 'C' AS `type`,'购买算力' AS `name`,'el-icon-Coin' AS `icon`,100 AS `sort`,'power.mall/packages' AS `perms`,'buy' AS `paths`,'power_mall/index' AS `component`,'' AS `selected`,'' AS `params`,0 AS `is_cache`,1 AS `is_show`,0 AS `is_disable`,'' AS `app_code`,'core' AS `source`,'core_tenant_power_mall_buy' AS `source_menu_key`,1 AS `is_core`
  UNION ALL SELECT 'C','购买记录','el-icon-Document',90,'power.mall/orders','records','power_mall/records','','',0,1,0,'','core','core_tenant_power_mall_records',1
) child
WHERE parent.`source_menu_key` = 'core_tenant_power_mall'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = parent.`tenant_id`
      AND exists_menu.`source_menu_key` = child.`source_menu_key`
  );

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT buy.`tenant_id`,buy.`id`,child.`type`,child.`name`,child.`icon`,child.`sort`,child.`perms`,child.`paths`,child.`component`,child.`selected`,child.`params`,child.`is_cache`,child.`is_show`,child.`is_disable`,child.`app_code`,child.`source`,child.`source_menu_key`,child.`is_core`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` buy
JOIN (
  SELECT 'A' AS `type`,'点数概览' AS `name`,'' AS `icon`,0 AS `sort`,'power.mall/stats' AS `perms`,'' AS `paths`,'' AS `component`,'' AS `selected`,'' AS `params`,0 AS `is_cache`,0 AS `is_show`,0 AS `is_disable`,'' AS `app_code`,'core' AS `source`,'core_tenant_power_mall_stats' AS `source_menu_key`,1 AS `is_core`
  UNION ALL
  SELECT 'A' AS `type`,'创建订单' AS `name`,'' AS `icon`,0 AS `sort`,'power.mall/createOrder' AS `perms`,'' AS `paths`,'' AS `component`,'' AS `selected`,'' AS `params`,0 AS `is_cache`,0 AS `is_show`,0 AS `is_disable`,'' AS `app_code`,'core' AS `source`,'core_tenant_power_mall_create_order' AS `source_menu_key`,1 AS `is_core`
  UNION ALL SELECT 'A','支付方式','',0,'power.pay/payWay','','','','',0,0,0,'','core','core_tenant_power_pay_way',1
  UNION ALL SELECT 'A','预支付','',0,'power.pay/prepay','','','','',0,0,0,'','core','core_tenant_power_pay_prepay',1
  UNION ALL SELECT 'A','支付状态','',0,'power.pay/payStatus','','','','',0,0,0,'','core','core_tenant_power_pay_status',1
) child
WHERE buy.`source_menu_key` = 'core_tenant_power_mall_buy'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = buy.`tenant_id`
      AND exists_menu.`source_menu_key` = child.`source_menu_key`
  );

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT records.`tenant_id`,records.`id`,'A','订单详情','',0,'power.mall/orderDetail','','','','',0,0,0,'','core','core_tenant_power_mall_order_detail',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` records
WHERE records.`source_menu_key` = 'core_tenant_power_mall_records'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = records.`tenant_id`
      AND exists_menu.`source_menu_key` = 'core_tenant_power_mall_order_detail'
  );

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT DISTINCT rm.`role_id`, menu.`id`
FROM `la_tenant_system_role_menu` rm
JOIN `la_tenant_system_role` role ON role.`id` = rm.`role_id`
JOIN `la_tenant_system_menu` menu ON menu.`tenant_id` = role.`tenant_id`
WHERE menu.`source_menu_key` IN (
  'core_tenant_power_mall',
  'core_tenant_power_mall_buy',
  'core_tenant_power_mall_records',
  'core_tenant_power_mall_stats',
  'core_tenant_power_mall_create_order',
  'core_tenant_power_pay_way',
  'core_tenant_power_pay_prepay',
  'core_tenant_power_pay_status',
  'core_tenant_power_mall_order_detail'
)
  AND (role.`delete_time` IS NULL OR role.`delete_time` = 0);
