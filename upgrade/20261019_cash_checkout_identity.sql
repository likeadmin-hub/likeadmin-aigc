CREATE TABLE IF NOT EXISTS `la_cash_checkout` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `biz_type` varchar(32) NOT NULL,
  `order_id` int unsigned NOT NULL DEFAULT 0,
  `order_sn` varchar(64) NOT NULL,
  `request_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `request_hash` char(64) NOT NULL DEFAULT '',
  `result_json` mediumtext,
  `channel` tinyint unsigned NOT NULL DEFAULT 0,
  `transaction_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_sn` (`order_sn`),
  UNIQUE KEY `uk_purchase_request` (`tenant_id`,`user_id`,`biz_type`,`request_key`),
  UNIQUE KEY `uk_payment_receipt` (`channel`,`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='现金购买请求与到账去重';
