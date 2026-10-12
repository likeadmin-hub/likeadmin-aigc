-- Delivery history is append-only; no legacy large table changes.
CREATE TABLE IF NOT EXISTS `la_open_platform_webhook_delivery` (
`id` bigint unsigned NOT NULL AUTO_INCREMENT,
`webhook_id` bigint unsigned NOT NULL, `event_id` varchar(64) NOT NULL,
`attempt` int NOT NULL, `http_code` int NOT NULL DEFAULT 0,
`error_message` varchar(500) NOT NULL DEFAULT '', `create_time` bigint NOT NULL DEFAULT 0,
PRIMARY KEY (`id`), KEY `delivery_history` (`webhook_id`,`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
