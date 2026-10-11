-- Durable admission counts include idempotent replay and rejected business parameters.
CREATE TABLE IF NOT EXISTS `la_open_platform_rate` (
`id` bigint unsigned NOT NULL AUTO_INCREMENT,
`tenant_id` bigint unsigned NOT NULL, `key_id` bigint unsigned NOT NULL DEFAULT 0,
`slot` bigint NOT NULL, `calls` int unsigned NOT NULL DEFAULT 0,
PRIMARY KEY (`id`), UNIQUE KEY `tenant_key_slot` (`tenant_id`,`key_id`,`slot`),
KEY `expired_slot` (`slot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
