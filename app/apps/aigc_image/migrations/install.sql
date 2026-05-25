-- LikeAdmin AIGC SaaS application core and AIGC image sample migration.
-- Execute once before installing aigc_image from the platform app center.

CREATE TABLE IF NOT EXISTS `la_app` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL DEFAULT '' COMMENT '应用标识',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '应用名称',
  `icon` varchar(255) NOT NULL DEFAULT '' COMMENT '应用图标',
  `description` varchar(500) NOT NULL DEFAULT '' COMMENT '应用描述',
  `category` varchar(50) NOT NULL DEFAULT 'common' COMMENT '应用分类',
  `cover` varchar(255) NOT NULL DEFAULT '' COMMENT '应用封面',
  `client_tags` varchar(255) NOT NULL DEFAULT '' COMMENT '适用端标签',
  `install_count` int unsigned NOT NULL DEFAULT 0 COMMENT '安装量',
  `view_count` int unsigned NOT NULL DEFAULT 0 COMMENT '浏览量',
  `is_builtin` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否内置应用',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `current_version` varchar(50) NOT NULL DEFAULT '' COMMENT '当前版本',
  `status` varchar(30) NOT NULL DEFAULT 'installed' COMMENT 'installed/disabled/removed',
  `expire_policy` varchar(20) NOT NULL DEFAULT 'block' COMMENT '过期策略:block不可用 allow仍可用',
  `install_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SaaS应用';

INSERT INTO `la_app` (`code`,`name`,`icon`,`description`,`category`,`cover`,`client_tags`,`install_count`,`view_count`,`is_builtin`,`sort`,`current_version`,`status`,`install_time`,`update_time`)
VALUES ('system_default','系统应用','el-icon-Setting','系统内置基础能力，包含素材、消息、文章、用户充值等默认功能。','builtin','','platform,tenant',0,0,1,1000,'1.0.0','installed',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `name`=VALUES(`name`),
  `icon`=VALUES(`icon`),
  `description`=VALUES(`description`),
  `category`=VALUES(`category`),
  `client_tags`=VALUES(`client_tags`),
  `is_builtin`=VALUES(`is_builtin`),
  `sort`=VALUES(`sort`),
  `status`=VALUES(`status`),
  `update_time`=VALUES(`update_time`);

CREATE TABLE IF NOT EXISTS `la_app_plan` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `app_code` varchar(64) NOT NULL DEFAULT '' COMMENT '应用标识',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '套餐名称',
  `duration_months` int unsigned NOT NULL DEFAULT 1 COMMENT '开通时长(月)',
  `open_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '开通点数',
  `renew_points` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '续费点数',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态:1启用0禁用',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_app_code` (`app_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用套餐';

CREATE TABLE IF NOT EXISTS `la_app_version` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `version` varchar(50) NOT NULL DEFAULT '',
  `require_core` varchar(50) NOT NULL DEFAULT '',
  `package_path` varchar(255) NOT NULL DEFAULT '',
  `manifest_json` text,
  `changelog` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_version` (`app_code`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SaaS应用版本';

CREATE TABLE IF NOT EXISTS `la_app_install` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `version` varchar(50) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'success',
  `error` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SaaS应用安装记录';

CREATE TABLE IF NOT EXISTS `la_tenant_app` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `version` varchar(50) NOT NULL DEFAULT '',
  `buy_status` varchar(30) NOT NULL DEFAULT 'paid',
  `shelf_status` varchar(30) NOT NULL DEFAULT 'on',
  `enable_status` varchar(30) NOT NULL DEFAULT 'enabled',
  `expire_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_app` (`tenant_id`,`app_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户应用';

CREATE TABLE IF NOT EXISTS `la_tenant_app_order` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `order_sn` varchar(64) NOT NULL DEFAULT '',
  `plan_id` int unsigned NOT NULL DEFAULT 0 COMMENT '套餐ID',
  `plan_name` varchar(100) NOT NULL DEFAULT '' COMMENT '套餐名称',
  `duration_months` int unsigned NOT NULL DEFAULT 0 COMMENT '开通时长(月)',
  `order_type` varchar(20) NOT NULL DEFAULT 'open' COMMENT 'open/renew',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `points_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '扣除点数',
  `before_expire_time` int unsigned NOT NULL DEFAULT 0 COMMENT '变更前到期时间',
  `after_expire_time` int unsigned NOT NULL DEFAULT 0 COMMENT '变更后到期时间',
  `operator_id` int unsigned NOT NULL DEFAULT 0 COMMENT '操作人',
  `pay_status` tinyint NOT NULL DEFAULT 0,
  `pay_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户应用订单';

CREATE TABLE IF NOT EXISTS `la_app_migration` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `scope` varchar(30) NOT NULL DEFAULT '',
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `version` varchar(50) NOT NULL DEFAULT '',
  `migration_key` varchar(120) NOT NULL DEFAULT '',
  `batch` varchar(30) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `error` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_migration` (`scope`,`app_code`,`tenant_id`,`migration_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用迁移记录';

CREATE TABLE IF NOT EXISTS `la_app_api` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `api_path` varchar(255) NOT NULL DEFAULT '',
  `api_method` varchar(20) NOT NULL DEFAULT 'GET',
  `permission_key` varchar(120) NOT NULL DEFAULT '',
  `scene` varchar(30) NOT NULL DEFAULT 'tenant_admin',
  `need_login` tinyint NOT NULL DEFAULT 1,
  `need_role_permission` tinyint NOT NULL DEFAULT 1,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_api` (`app_code`,`api_path`,`api_method`,`scene`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用API声明';

CREATE TABLE IF NOT EXISTS `la_app_frontend_entry` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `terminal` varchar(30) NOT NULL DEFAULT '',
  `entry_key` varchar(100) NOT NULL DEFAULT '',
  `name` varchar(100) NOT NULL DEFAULT '',
  `path` varchar(255) NOT NULL DEFAULT '',
  `icon` varchar(255) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `meta` text,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_entry` (`app_code`,`terminal`,`entry_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='应用前端入口';

CREATE TABLE IF NOT EXISTS `la_aigc_image_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `provider_mode` varchar(30) NOT NULL DEFAULT 'platform',
  `provider` varchar(50) NOT NULL DEFAULT 'mock',
  `model` varchar(100) NOT NULL DEFAULT 'mock-image',
  `config_json` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC生图配置';

CREATE TABLE IF NOT EXISTS `la_aigc_image_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `prompt` text,
  `negative_prompt` text,
  `style` varchar(50) NOT NULL DEFAULT '',
  `ratio` varchar(30) NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT 1,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `error` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC生图任务';

CREATE TABLE IF NOT EXISTS `la_aigc_image_result` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `image_uri` varchar(255) NOT NULL DEFAULT '',
  `storage_scope` varchar(20) NOT NULL DEFAULT 'platform',
  `storage_engine` varchar(30) NOT NULL DEFAULT 'local',
  `storage_domain` varchar(255) NOT NULL DEFAULT '',
  `width` int unsigned NOT NULL DEFAULT 0,
  `height` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_task` (`tenant_id`,`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC生图结果';

CREATE TABLE IF NOT EXISTS `la_aigc_image_quota` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `total_quota` int unsigned NOT NULL DEFAULT 0,
  `used_quota` int unsigned NOT NULL DEFAULT 0,
  `expire_time` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC生图额度';

CREATE TABLE IF NOT EXISTS `la_aigc_image_sensitive_word` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `word` varchar(100) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC生图敏感词';

CREATE TABLE IF NOT EXISTS `la_update_source` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '更新源名称',
  `base_url` varchar(255) NOT NULL DEFAULT '' COMMENT '授权系统接口地址',
  `license_key` varchar(255) NOT NULL DEFAULT '' COMMENT 'API Key/授权key',
  `online_base_url` varchar(255) NOT NULL DEFAULT '' COMMENT '线上授权系统接口地址',
  `online_license_key` varchar(255) NOT NULL DEFAULT '' COMMENT '线上API Key/授权key',
  `dev_mode` tinyint NOT NULL DEFAULT 1 COMMENT '开发模式：1开启 0关闭',
  `ssl_verify` tinyint NOT NULL DEFAULT 0 COMMENT 'SSL证书校验：1开启 0关闭',
  `public_key` text COMMENT '响应验签公钥',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '状态',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='接口渠道';

CREATE TABLE IF NOT EXISTS `la_update_package` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `package_id` varchar(120) NOT NULL DEFAULT '' COMMENT '远端包ID',
  `type` varchar(20) NOT NULL DEFAULT 'app' COMMENT 'system/app',
  `source` varchar(20) NOT NULL DEFAULT 'cloud' COMMENT 'cloud/upload',
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `version` varchar(50) NOT NULL DEFAULT '',
  `format` varchar(20) NOT NULL DEFAULT 'zip',
  `local_path` varchar(500) NOT NULL DEFAULT '',
  `extract_path` varchar(500) NOT NULL DEFAULT '',
  `sha256` varchar(64) NOT NULL DEFAULT '',
  `package_size` bigint unsigned NOT NULL DEFAULT 0,
  `manifest_json` text,
  `status` varchar(30) NOT NULL DEFAULT 'downloaded',
  `error` text,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_type_app` (`type`,`app_code`),
  KEY `idx_package` (`package_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='更新包记录';

CREATE TABLE IF NOT EXISTS `la_update_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(20) NOT NULL DEFAULT 'app' COMMENT 'system/app',
  `action` varchar(30) NOT NULL DEFAULT '' COMMENT 'install/update/apply',
  `package_id` int unsigned NOT NULL DEFAULT 0,
  `app_code` varchar(64) NOT NULL DEFAULT '',
  `version` varchar(50) NOT NULL DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `preflight_json` text,
  `result_json` text,
  `error` text,
  `operator_id` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_type_status` (`type`,`status`),
  KEY `idx_package_id` (`package_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='更新任务记录';

CREATE TABLE IF NOT EXISTS `la_update_license` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `license_id` varchar(120) NOT NULL DEFAULT '' COMMENT '授权ID',
  `product_code` varchar(80) NOT NULL DEFAULT '' COMMENT '产品码',
  `customer_name` varchar(120) NOT NULL DEFAULT '' COMMENT '客户名称',
  `domains_json` text COMMENT '绑定域名',
  `machine_fingerprint_hash` varchar(64) NOT NULL DEFAULT '' COMMENT '机器指纹hash',
  `license_json` text COMMENT '授权文件内容',
  `signature` text COMMENT '授权签名',
  `file_sha256` varchar(64) NOT NULL DEFAULT '' COMMENT '授权文件sha256',
  `status` varchar(30) NOT NULL DEFAULT 'active' COMMENT '状态',
  `issued_at` int unsigned NOT NULL DEFAULT 0,
  `expires_at` int unsigned NOT NULL DEFAULT 0,
  `update_until` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_license_id` (`license_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='更新服务授权';

-- Existing core table changes for upgraded installations.
-- Keep these ALTERs idempotent because system updates can resync built-in apps.
SET @aigc_image_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = REPLACE('`la_tenant`', '`', '') AND COLUMN_NAME = 'allow_custom_storage') = 0,
  'ALTER TABLE `la_tenant` ADD COLUMN `allow_custom_storage` tinyint NOT NULL DEFAULT 0 COMMENT ''允许租户自定义存储''',
  'SELECT 1'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;
SET @aigc_image_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = REPLACE('`la_tenant`', '`', '') AND COLUMN_NAME = 'point_balance') = 0,
  'ALTER TABLE `la_tenant` ADD COLUMN `point_balance` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT ''租户点数余额''',
  'SELECT 1'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;
SET @aigc_image_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = REPLACE('`la_file`', '`', '') AND COLUMN_NAME = 'storage_scope') = 0,
  'ALTER TABLE `la_file` ADD COLUMN `storage_scope` varchar(20) NOT NULL DEFAULT ''platform'' COMMENT ''存储作用域''',
  'SELECT 1'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;
SET @aigc_image_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = REPLACE('`la_file`', '`', '') AND COLUMN_NAME = 'storage_engine') = 0,
  'ALTER TABLE `la_file` ADD COLUMN `storage_engine` varchar(30) NOT NULL DEFAULT ''local'' COMMENT ''存储引擎''',
  'SELECT 1'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;
SET @aigc_image_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = REPLACE('`la_file`', '`', '') AND COLUMN_NAME = 'storage_domain') = 0,
  'ALTER TABLE `la_file` ADD COLUMN `storage_domain` varchar(255) NOT NULL DEFAULT '''' COMMENT ''存储域名''',
  'SELECT 1'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;
SET @aigc_image_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = REPLACE('`la_tenant_file`', '`', '') AND COLUMN_NAME = 'storage_scope') = 0,
  'ALTER TABLE `la_tenant_file` ADD COLUMN `storage_scope` varchar(20) NOT NULL DEFAULT ''platform'' COMMENT ''存储作用域''',
  'SELECT 1'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;
SET @aigc_image_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = REPLACE('`la_tenant_file`', '`', '') AND COLUMN_NAME = 'storage_engine') = 0,
  'ALTER TABLE `la_tenant_file` ADD COLUMN `storage_engine` varchar(30) NOT NULL DEFAULT ''local'' COMMENT ''存储引擎''',
  'SELECT 1'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;
SET @aigc_image_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = REPLACE('`la_tenant_file`', '`', '') AND COLUMN_NAME = 'storage_domain') = 0,
  'ALTER TABLE `la_tenant_file` ADD COLUMN `storage_domain` varchar(255) NOT NULL DEFAULT '''' COMMENT ''存储域名''',
  'SELECT 1'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;
SET @aigc_image_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = REPLACE('`la_system_menu`', '`', '') AND COLUMN_NAME = 'app_code') = 0,
  'ALTER TABLE `la_system_menu` ADD COLUMN `app_code` varchar(64) NOT NULL DEFAULT '''' COMMENT ''应用标识''',
  'SELECT 1'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;
SET @aigc_image_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = REPLACE('`la_system_menu`', '`', '') AND COLUMN_NAME = 'source') = 0,
  'ALTER TABLE `la_system_menu` ADD COLUMN `source` varchar(20) NOT NULL DEFAULT ''core'' COMMENT ''菜单来源''',
  'SELECT 1'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;
SET @aigc_image_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = REPLACE('`la_system_menu`', '`', '') AND COLUMN_NAME = 'source_menu_key') = 0,
  'ALTER TABLE `la_system_menu` ADD COLUMN `source_menu_key` varchar(120) NOT NULL DEFAULT '''' COMMENT ''来源菜单key''',
  'SELECT 1'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;
SET @aigc_image_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = REPLACE('`la_system_menu`', '`', '') AND COLUMN_NAME = 'is_core') = 0,
  'ALTER TABLE `la_system_menu` ADD COLUMN `is_core` tinyint NOT NULL DEFAULT 1 COMMENT ''是否核心菜单''',
  'SELECT 1'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;
SET @aigc_image_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = REPLACE('`la_tenant_system_menu`', '`', '') AND COLUMN_NAME = 'app_code') = 0,
  'ALTER TABLE `la_tenant_system_menu` ADD COLUMN `app_code` varchar(64) NOT NULL DEFAULT '''' COMMENT ''应用标识''',
  'SELECT 1'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;
SET @aigc_image_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = REPLACE('`la_tenant_system_menu`', '`', '') AND COLUMN_NAME = 'source') = 0,
  'ALTER TABLE `la_tenant_system_menu` ADD COLUMN `source` varchar(20) NOT NULL DEFAULT ''core'' COMMENT ''菜单来源''',
  'SELECT 1'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;
SET @aigc_image_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = REPLACE('`la_tenant_system_menu`', '`', '') AND COLUMN_NAME = 'source_menu_key') = 0,
  'ALTER TABLE `la_tenant_system_menu` ADD COLUMN `source_menu_key` varchar(120) NOT NULL DEFAULT '''' COMMENT ''来源菜单key''',
  'SELECT 1'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;
SET @aigc_image_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = REPLACE('`la_tenant_system_menu`', '`', '') AND COLUMN_NAME = 'is_core') = 0,
  'ALTER TABLE `la_tenant_system_menu` ADD COLUMN `is_core` tinyint NOT NULL DEFAULT 1 COMMENT ''是否核心菜单''',
  'SELECT 1'
);
PREPARE aigc_image_stmt FROM @aigc_image_sql;
EXECUTE aigc_image_stmt;
DEALLOCATE PREPARE aigc_image_stmt;

-- Core navigation for the app center. App-specific business menus are synced as top-level menus during install.
INSERT IGNORE INTO `la_system_menu`
(`id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9000,0,'M','应用管理','el-icon-Grid',60,'','apps','','','',0,1,0,'','core','core_app_center',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(9001,9000,'C','应用中心','el-icon-Menu',100,'app/lists','center','apps/center/index','','',0,1,0,'','core','core_app_center_index',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

INSERT IGNORE INTO `la_system_menu`
(`id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9020,0,'M','系统服务','el-icon-Refresh',50,'','system-service','','','',0,1,0,'','core','core_update_service',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(9024,9020,'C','接口渠道','el-icon-Connection',110,'upgrade/source','channel','update/channel/index','','',0,1,0,'','core','core_update_channel',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(9021,9020,'C','版本更新','el-icon-UploadFilled',100,'upgrade/overview','version','update/version/index','','',0,1,0,'','core','core_update_version',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(9022,9020,'C','授权信息','el-icon-Key',90,'upgrade/licenseInfo','license','update/license/index','','',0,1,0,'','core','core_update_license',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(9023,9020,'C','版本日志','el-icon-List',80,'upgrade/logs','log','update/log/index','','',0,1,0,'','core','core_update_log',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

INSERT IGNORE INTO `la_tenant_system_menu`
(`id`,`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
VALUES
(9000,0,0,'M','应用管理','el-icon-Grid',60,'','apps','','','',0,1,0,'','core','core_tenant_app_center',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(9001,0,9000,'C','应用市场','el-icon-Shop',100,'app/market','market','app/market/index','','',0,1,0,'','core','core_tenant_app_market',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(9002,0,9000,'C','我的应用','el-icon-Menu',90,'app/my','my','app/my/index','','',0,0,0,'','core','core_tenant_my_app',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

-- For tenants using table-splitting, run equivalent ALTER statements on:
-- la_tenant_file_{tenantSn}
-- la_tenant_system_menu_{tenantSn}
