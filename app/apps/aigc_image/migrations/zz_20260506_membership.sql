CREATE TABLE IF NOT EXISTS `la_membership_plan` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '套餐名称',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '套餐简介',
  `monthly_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '月付价格',
  `yearly_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '年付价格',
  `monthly_market_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '月付划线价',
  `yearly_market_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '年付划线价',
  `monthly_bonus_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '月付赠送积分',
  `yearly_bonus_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '年付赠送积分',
  `features` text COMMENT '权益说明',
  `is_recommend` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否推荐',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员套餐';

CREATE TABLE IF NOT EXISTS `la_recharge_package` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '套餐名称',
  `points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '到账点数',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '售价',
  `market_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '划线价',
  `is_recommend` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否推荐',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='算力套餐';

CREATE TABLE IF NOT EXISTS `la_membership_plan_app` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `plan_id` int unsigned NOT NULL DEFAULT 0,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plan_app` (`plan_id`,`app_code`),
  KEY `idx_tenant_app` (`tenant_id`,`app_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员套餐关联应用';

CREATE TABLE IF NOT EXISTS `la_membership_order` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `order_sn` varchar(64) NOT NULL DEFAULT '',
  `pay_sn` varchar(64) NOT NULL DEFAULT '',
  `transaction_id` varchar(128) NOT NULL DEFAULT '',
  `order_terminal` tinyint unsigned NOT NULL DEFAULT 0,
  `plan_id` int unsigned NOT NULL DEFAULT 0,
  `plan_name` varchar(100) NOT NULL DEFAULT '',
  `cycle` varchar(20) NOT NULL DEFAULT 'monthly',
  `duration_months` int unsigned NOT NULL DEFAULT 1,
  `order_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `bonus_points` decimal(10,2) NOT NULL DEFAULT 0.00,
  `before_expire_time` int unsigned NOT NULL DEFAULT 0,
  `after_expire_time` int unsigned NOT NULL DEFAULT 0,
  `pay_way` tinyint unsigned NOT NULL DEFAULT 0,
  `pay_status` tinyint unsigned NOT NULL DEFAULT 0,
  `pay_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_sn` (`order_sn`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`,`pay_status`),
  KEY `idx_plan` (`tenant_id`,`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员订单';

CREATE TABLE IF NOT EXISTS `la_user_membership` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `plan_id` int unsigned NOT NULL DEFAULT 0,
  `plan_name` varchar(100) NOT NULL DEFAULT '',
  `app_codes` text COMMENT '可用应用',
  `features` text COMMENT '权益快照',
  `start_time` int unsigned NOT NULL DEFAULT 0,
  `expire_time` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `source_order_sn` varchar(64) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_expire` (`tenant_id`,`expire_time`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户会员权益';

SET @membership_recharge_order_table = REPLACE('`la_recharge_order`', '`', '');
SET @membership_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @membership_recharge_order_table, '` ADD COLUMN `recharge_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT ''到账点数'' AFTER `order_amount`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @membership_recharge_order_table AND COLUMN_NAME = 'recharge_points');
PREPARE membership_stmt FROM @membership_sql;
EXECUTE membership_stmt;
DEALLOCATE PREPARE membership_stmt;

SET @membership_recharge_order_table = REPLACE('`la_recharge_order`', '`', '');
SET @membership_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @membership_recharge_order_table, '` ADD COLUMN `package_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''充值套餐ID'' AFTER `recharge_points`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @membership_recharge_order_table AND COLUMN_NAME = 'package_id');
PREPARE membership_stmt FROM @membership_sql;
EXECUTE membership_stmt;
DEALLOCATE PREPARE membership_stmt;

SET @membership_recharge_order_table = REPLACE('`la_recharge_order`', '`', '');
SET @membership_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @membership_recharge_order_table, '` ADD COLUMN `package_name` varchar(100) NOT NULL DEFAULT '''' COMMENT ''充值套餐名称'' AFTER `package_id`'), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @membership_recharge_order_table AND COLUMN_NAME = 'package_name');
PREPARE membership_stmt FROM @membership_sql;
EXECUTE membership_stmt;
DEALLOCATE PREPARE membership_stmt;

INSERT INTO `la_membership_plan` (
  `tenant_id`,
  `name`,
  `description`,
  `monthly_price`,
  `yearly_price`,
  `monthly_market_price`,
  `yearly_market_price`,
  `monthly_bonus_points`,
  `yearly_bonus_points`,
  `features`,
  `is_recommend`,
  `status`,
  `sort`,
  `create_time`,
  `update_time`
)
SELECT
  t.`id`,
  plans.`name`,
  plans.`description`,
  plans.`monthly_price`,
  plans.`yearly_price`,
  plans.`monthly_market_price`,
  plans.`yearly_market_price`,
  plans.`monthly_bonus_points`,
  plans.`yearly_bonus_points`,
  plans.`features`,
  plans.`is_recommend`,
  1,
  plans.`sort`,
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
FROM `la_tenant` t
JOIN (
  SELECT '免费会员' AS `name`, '系统默认免费会员，基础应用可直接使用' AS `description`, 0.00 AS `monthly_price`, 0.00 AS `yearly_price`, 0.00 AS `monthly_market_price`, 0.00 AS `yearly_market_price`, 0.00 AS `monthly_bonus_points`, 0.00 AS `yearly_bonus_points`, '["基础应用永久免费使用","可购买积分继续创作","会员权益可由租户继续调整"]' AS `features`, 0 AS `is_recommend`, 100 AS `sort`
  UNION ALL
  SELECT '基础会员', '适合轻量创作用户，赠送基础积分', 19.90, 199.00, 29.90, 299.00, 100.00, 1500.00, '["每月赠送100积分","按年开通赠送1500积分","适合个人轻量创作"]', 0, 90
  UNION ALL
  SELECT '高级会员', '适合高频创作用户，赠送更多积分', 39.90, 399.00, 69.90, 699.00, 300.00, 4200.00, '["每月赠送300积分","按年开通赠送4200积分","适合高频图文与视频创作"]', 1, 80
) plans
WHERE NOT EXISTS (
  SELECT 1 FROM `la_membership_plan` p
  WHERE p.`tenant_id` = t.`id`
);

INSERT INTO `la_recharge_package` (
  `tenant_id`,
  `name`,
  `points`,
  `amount`,
  `market_amount`,
  `is_recommend`,
  `status`,
  `sort`,
  `create_time`,
  `update_time`
)
SELECT
  t.`id`,
  packages.`name`,
  packages.`points`,
  packages.`amount`,
  packages.`market_amount`,
  packages.`is_recommend`,
  1,
  packages.`sort`,
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
FROM `la_tenant` t
JOIN (
  SELECT '体验包' AS `name`, 10.00 AS `points`, 10.00 AS `amount`, 0.00 AS `market_amount`, 0 AS `is_recommend`, 100 AS `sort`
  UNION ALL
  SELECT '轻量包', 30.00, 30.00, 0.00, 0, 90
  UNION ALL
  SELECT '标准包', 50.00, 50.00, 0.00, 0, 80
  UNION ALL
  SELECT '进阶包', 100.00, 100.00, 0.00, 1, 70
  UNION ALL
  SELECT '专业包', 300.00, 300.00, 0.00, 0, 60
  UNION ALL
  SELECT '团队包', 500.00, 500.00, 0.00, 0, 50
) packages
WHERE NOT EXISTS (
  SELECT 1 FROM `la_recharge_package` p
  WHERE p.`tenant_id` = t.`id`
);
