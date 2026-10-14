-- Independent mini-program identities and append-only credential versions.
CREATE TABLE IF NOT EXISTS `la_virtual_pay_config` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL,
 `app_id` varchar(64) NOT NULL, `env` tinyint unsigned NOT NULL DEFAULT 0,
 `enabled` tinyint unsigned NOT NULL DEFAULT 0, `version_id` bigint unsigned NOT NULL DEFAULT 0,
 `update_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_app_env` (`tenant_id`,`app_id`,`env`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_virtual_pay_config_version` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `config_id` bigint unsigned NOT NULL,
 `tenant_id` int unsigned NOT NULL, `app_id` varchar(64) NOT NULL, `env` tinyint unsigned NOT NULL,
 `authorizer_id` int unsigned NOT NULL DEFAULT 0, `offer_id` varchar(64) NOT NULL,
 `mini_secret` text NOT NULL, `app_key` text NOT NULL, `token` text NOT NULL, `aes_key` text NOT NULL,
 `message_mode` varchar(16) NOT NULL DEFAULT 'safe', `created_at` int unsigned NOT NULL,
 PRIMARY KEY (`id`), KEY `idx_identity` (`tenant_id`,`app_id`,`env`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_virtual_pay_product` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL,
 `app_id` varchar(64) NOT NULL, `env` tinyint unsigned NOT NULL, `biz_type` varchar(24) NOT NULL,
 `package_id` int unsigned NOT NULL, `cycle` varchar(16) NOT NULL, `variant` varchar(16) NOT NULL,
 `product_id` varchar(128) NOT NULL, `price_cents` bigint unsigned NOT NULL, `updated_at` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_mapping` (`tenant_id`,`app_id`,`env`,`biz_type`,`package_id`,`cycle`,`variant`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_virtual_pay_session` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `app_id` varchar(64) NOT NULL, `session_id` char(64) NOT NULL, `openid` varchar(128) NOT NULL,
 `session_key` text NOT NULL, `expires_at` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_session` (`session_id`), KEY `idx_expire` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE `la_tenant_system_menu` SET `source`='core',`source_menu_key`='core_tenant_virtual_pay',`is_core`=1 WHERE tenant_id=0 AND perms='finance.virtual_pay/detail';
UPDATE `la_tenant_system_menu` SET `source`='core',`source_menu_key`='core_tenant_promotion_save',`is_core`=1 WHERE tenant_id=0 AND perms='finance.promotion/save';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`perms`,`is_show`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT 0,p.id,'A','保存配置','finance.virtual_pay/save',0,'core','core_tenant_virtual_pay_save',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.tenant_id=0 AND p.perms='finance.virtual_pay/detail' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=0 AND e.perms='finance.virtual_pay/save');
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`perms`,`is_show`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT 0,p.id,'A','绑定道具','finance.virtual_pay/saveProduct',0,'core','core_tenant_virtual_pay_saveProduct',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.tenant_id=0 AND p.perms='finance.virtual_pay/detail' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=0 AND e.perms='finance.virtual_pay/saveProduct');
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`perms`,`is_show`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT 0,p.id,'A','交易记录','finance.virtual_pay/orders',0,'core','core_tenant_virtual_pay_orders',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.tenant_id=0 AND p.perms='finance.virtual_pay/detail' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=0 AND e.perms='finance.virtual_pay/orders');
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`perms`,`is_show`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT 0,p.id,'A','重试核对','finance.virtual_pay/retry',0,'core','core_tenant_virtual_pay_retry',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.tenant_id=0 AND p.perms='finance.virtual_pay/detail' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=0 AND e.perms='finance.virtual_pay/retry');
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`perms`,`is_show`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT 0,p.id,'A','发起退款','finance.virtual_pay/refund',0,'core','core_tenant_virtual_pay_refund',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.tenant_id=0 AND p.perms='finance.virtual_pay/detail' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=0 AND e.perms='finance.virtual_pay/refund');
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`perms`,`is_show`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT 0,p.id,'A','异常处理','finance.virtual_pay/resolve',0,'core','core_tenant_virtual_pay_resolve',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.tenant_id=0 AND p.perms='finance.virtual_pay/detail' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=0 AND e.perms='finance.virtual_pay/resolve');
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`perms`,`is_show`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT 0,p.id,'A','优惠详情','finance.promotion/detail',0,'core','core_tenant_promotion_detail',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `la_tenant_system_menu` p WHERE p.tenant_id=0 AND p.paths='package' AND p.type='M' AND NOT EXISTS(SELECT 1 FROM `la_tenant_system_menu` e WHERE e.tenant_id=0 AND e.perms='finance.promotion/detail');
