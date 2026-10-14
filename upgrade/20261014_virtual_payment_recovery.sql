ALTER TABLE `la_virtual_pay_intent` ADD COLUMN `refund_source` varchar(16) NOT NULL DEFAULT 'none';
ALTER TABLE `la_ai_app_task` ADD INDEX `idx_virtual_refund_user` (`tenant_id`,`user_id`,`status`);
ALTER TABLE `la_ai_consumption_log` ADD INDEX `idx_virtual_refund_billing` (`tenant_id`,`user_id`,`billing_status`);
INSERT INTO `la_dev_crontab` (`name`,`type`,`system`,`remark`,`command`,`params`,`status`,`expression`,`error`,`last_time`,`time`,`max_time`,`create_time`,`update_time`,`delete_time`)
SELECT '虚拟支付订单核对',1,1,'有界核对付款、退款和发货结果，过期未付款订单经渠道核对后释放优惠','virtualpay:maintain','',1,'* * * * *','',0,'0','0',UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),NULL
WHERE NOT EXISTS(SELECT 1 FROM `la_dev_crontab` WHERE command='virtualpay:maintain' AND (delete_time IS NULL OR delete_time=0));

ALTER TABLE `la_tenant_package_price_snapshot` ADD COLUMN `payment_context` text NULL;
CREATE TABLE IF NOT EXISTS `la_package_payment_receipt` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `snapshot_id` bigint unsigned NOT NULL,
 `channel` tinyint unsigned NOT NULL, `transaction_id` varchar(128) NOT NULL, `amount_cents` bigint unsigned NOT NULL,
 `status` varchar(24) NOT NULL, `message` varchar(500) NOT NULL DEFAULT '', `created_at` int unsigned NOT NULL, `updated_at` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_transaction` (`channel`,`transaction_id`), KEY `idx_package_attention` (`tenant_id`,`status`), KEY `idx_snapshot` (`snapshot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE `la_tenant_package_price_snapshot` ADD COLUMN `next_check_at` int unsigned NOT NULL DEFAULT 0;
ALTER TABLE `la_tenant_package_price_snapshot` ADD INDEX `idx_payment_due` (`status`,`next_check_at`,`expires_at`);
