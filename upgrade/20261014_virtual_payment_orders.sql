CREATE TABLE IF NOT EXISTS `la_virtual_pay_intent` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `biz_type` varchar(24) NOT NULL, `order_id` int unsigned NOT NULL, `config_version_id` bigint unsigned NOT NULL,
 `app_id` varchar(64) NOT NULL, `env` tinyint unsigned NOT NULL, `openid` varchar(128) NOT NULL,
 `out_trade_no` varchar(32) NOT NULL, `platform` varchar(16) NOT NULL,
 `product_id` varchar(128) NOT NULL, `amount_cents` bigint unsigned NOT NULL, `attach` varchar(128) NOT NULL,
 `sign_data` longtext NOT NULL, `status` varchar(24) NOT NULL DEFAULT 'pending',
 `delivery_status` varchar(24) NOT NULL DEFAULT 'pending', `xpay_shipping_status` varchar(24) NOT NULL DEFAULT 'pending',
 `general_shipping_status` varchar(24) NOT NULL DEFAULT 'pending', `refund_status` varchar(24) NOT NULL DEFAULT 'none',
 `wx_order_id` varchar(128) NOT NULL DEFAULT '', `wxpay_order_id` varchar(128) NOT NULL DEFAULT '',
 `order_type` tinyint unsigned NOT NULL DEFAULT 0, `paid_at` int unsigned NOT NULL DEFAULT 0,
 `created_at` int unsigned NOT NULL, `expires_at` int unsigned NOT NULL, `updated_at` int unsigned NOT NULL,
 `last_error` varchar(500) NOT NULL DEFAULT '', `refund_order_id` varchar(32) NOT NULL DEFAULT '',
 `refund_requested_at` int unsigned NOT NULL DEFAULT 0, `refund_cents` bigint unsigned NOT NULL DEFAULT 0,
 `recovery_status` varchar(24) NOT NULL DEFAULT 'none', `rights_before` longtext,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_attempt` (`out_trade_no`), KEY `idx_order` (`tenant_id`,`biz_type`,`order_id`),
 KEY `idx_pending` (`status`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_virtual_pay_receipt` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `intent_id` bigint unsigned NOT NULL,
 `app_id` varchar(64) NOT NULL, `env` tinyint unsigned NOT NULL, `transaction_key` varchar(128) NOT NULL,
 `amount_cents` bigint unsigned NOT NULL, `kind` varchar(24) NOT NULL DEFAULT 'payment',
 `normalized` longtext NOT NULL, `created_at` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_transaction` (`app_id`,`env`,`transaction_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_virtual_pay_event` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `app_id` varchar(64) NOT NULL,
 `intent_id` bigint unsigned NOT NULL DEFAULT 0, `event` varchar(80) NOT NULL, `event_key` char(64) NOT NULL,
 `payload` longtext NOT NULL, `status` varchar(24) NOT NULL DEFAULT 'pending', `message` varchar(500) NOT NULL DEFAULT '',
 `created_at` int unsigned NOT NULL, `updated_at` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_event` (`tenant_id`,`app_id`,`event_key`), KEY `idx_attention` (`tenant_id`,`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_virtual_pay_work` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `intent_id` bigint unsigned NOT NULL, `kind` varchar(24) NOT NULL,
 `status` varchar(24) NOT NULL DEFAULT 'pending', `next_at` int unsigned NOT NULL DEFAULT 0,
 `lease_token` varchar(64) NOT NULL DEFAULT '', `lease_until` int unsigned NOT NULL DEFAULT 0,
 `generation` varchar(128) NOT NULL DEFAULT '', `attempts` int unsigned NOT NULL DEFAULT 0,
 `last_error` varchar(500) NOT NULL DEFAULT '', `updated_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_work` (`intent_id`,`kind`), KEY `idx_due` (`status`,`next_at`,`lease_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
