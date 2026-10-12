-- Open platform additive tables; existing tenants remain disabled.
CREATE TABLE IF NOT EXISTS `la_open_platform_key` (
`id` bigint unsigned NOT NULL AUTO_INCREMENT,
`create_time` bigint NOT NULL DEFAULT 0, `update_time` bigint NOT NULL DEFAULT 0,
`tenant_id` bigint unsigned NOT NULL, `user_id` bigint unsigned NOT NULL,
`name` varchar(100) NOT NULL, `key_hash` char(64) NOT NULL, `prefix` varchar(24) NOT NULL,
`status` tinyint NOT NULL DEFAULT 1, `expires_at` bigint NOT NULL DEFAULT 0,
`scopes` text NOT NULL, `ip_allowlist` text NOT NULL, `rpm` int NOT NULL DEFAULT 0,
`concurrency` int NOT NULL DEFAULT 0, `daily_limit` decimal(18,2) NOT NULL DEFAULT 0,
`monthly_limit` decimal(18,2) NOT NULL DEFAULT 0, `last_used_at` bigint NOT NULL DEFAULT 0,
UNIQUE KEY `key_hash` (`key_hash`), KEY `owner` (`tenant_id`,`user_id`,`status`),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `la_open_platform_setting` (
`id` bigint unsigned NOT NULL AUTO_INCREMENT,
`create_time` bigint NOT NULL DEFAULT 0, `update_time` bigint NOT NULL DEFAULT 0,
`tenant_id` bigint unsigned NOT NULL, `enabled` tinyint NOT NULL DEFAULT 0,
`config` mediumtext NOT NULL, UNIQUE KEY `tenant` (`tenant_id`),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `la_open_platform_contract` (
`id` bigint unsigned NOT NULL AUTO_INCREMENT,
`create_time` bigint NOT NULL DEFAULT 0, `update_time` bigint NOT NULL DEFAULT 0,
`code` varchar(160) NOT NULL, `method` varchar(10) NOT NULL,
`path` varchar(255) NOT NULL, `version` int NOT NULL DEFAULT 1,
`status` varchar(20) NOT NULL DEFAULT 'draft', `kind` varchar(20) NOT NULL DEFAULT 'market',
`product_id` bigint NOT NULL DEFAULT 0, `config` mediumtext NOT NULL,
UNIQUE KEY `code_version` (`code`,`version`), KEY `published` (`status`,`kind`),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `la_open_platform_request` (
`id` bigint unsigned NOT NULL AUTO_INCREMENT,
`create_time` bigint NOT NULL DEFAULT 0, `update_time` bigint NOT NULL DEFAULT 0,
`request_id` varchar(64) NOT NULL, `tenant_id` bigint unsigned NOT NULL,
`user_id` bigint unsigned NOT NULL, `key_id` bigint unsigned NOT NULL,
`method` varchar(10) NOT NULL, `path` varchar(255) NOT NULL, `app_code` varchar(100) NOT NULL DEFAULT '',
`kind` varchar(20) NOT NULL, `contract_id` bigint NOT NULL DEFAULT 0,
`idempotency_hash` char(64) NULL, `payload_hash` char(64) NOT NULL,
`status` varchar(30) NOT NULL DEFAULT 'accepted', `billing_status` varchar(30) NOT NULL DEFAULT 'none',
`budget_user` decimal(18,2) NOT NULL DEFAULT 0, `budget_tenant` decimal(18,2) NOT NULL DEFAULT 0,
`actual_user` decimal(18,2) NOT NULL DEFAULT 0, `actual_tenant` decimal(18,2) NOT NULL DEFAULT 0,
`rate_slot` bigint NOT NULL DEFAULT 0, `active` tinyint NOT NULL DEFAULT 1,
`upstream_task_id` varchar(255) NOT NULL DEFAULT '', `tool_task_id` varchar(100) NOT NULL DEFAULT '',
`consumption_ids` text NOT NULL, `snapshot` mediumtext NOT NULL,
`response_blob` mediumtext NOT NULL, `response_type` varchar(150) NOT NULL DEFAULT 'application/json',
`response_code` int NOT NULL DEFAULT 0, `error_code` varchar(80) NOT NULL DEFAULT '',
`error_message` varchar(500) NOT NULL DEFAULT '', `duration_ms` int NOT NULL DEFAULT 0,
`next_run_time` bigint NOT NULL DEFAULT 0, `lease_token` varchar(64) NOT NULL DEFAULT '',
`lease_expire` bigint NOT NULL DEFAULT 0, `finish_time` bigint NOT NULL DEFAULT 0,
UNIQUE KEY `request_id` (`request_id`), UNIQUE KEY `idempotency` (`tenant_id`,`user_id`,`idempotency_hash`),
KEY `owner_time` (`tenant_id`,`user_id`,`create_time`), KEY `key_rate` (`key_id`,`rate_slot`),
KEY `recovery` (`next_run_time`,`active`), KEY `upstream` (`tenant_id`,`user_id`,`upstream_task_id`),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `la_open_platform_file` (
`id` bigint unsigned NOT NULL AUTO_INCREMENT,
`create_time` bigint NOT NULL DEFAULT 0, `update_time` bigint NOT NULL DEFAULT 0,
`tenant_id` bigint unsigned NOT NULL, `user_id` bigint unsigned NOT NULL,
`file_id` varchar(64) NOT NULL, `uri` varchar(1024) NOT NULL, `name` varchar(255) NOT NULL,
`mime` varchar(150) NOT NULL DEFAULT '', `size` bigint NOT NULL DEFAULT 0,
`storage_scope` varchar(40) NOT NULL DEFAULT '', `storage_engine` varchar(40) NOT NULL DEFAULT '',
`storage_domain` varchar(255) NOT NULL DEFAULT '', UNIQUE KEY `file_id` (`file_id`),
KEY `owner` (`tenant_id`,`user_id`),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `la_open_platform_webhook` (
`id` bigint unsigned NOT NULL AUTO_INCREMENT,
`create_time` bigint NOT NULL DEFAULT 0, `update_time` bigint NOT NULL DEFAULT 0,
`tenant_id` bigint unsigned NOT NULL, `user_id` bigint unsigned NOT NULL,
`request_id` varchar(64) NOT NULL, `event_id` varchar(64) NOT NULL,
`url` varchar(1024) NOT NULL, `secret_blob` text NOT NULL, `payload_blob` mediumtext NOT NULL,
`status` varchar(20) NOT NULL DEFAULT 'pending', `attempts` int NOT NULL DEFAULT 0,
`next_run_time` bigint NOT NULL DEFAULT 0, `lease_token` varchar(64) NOT NULL DEFAULT '',
`lease_expire` bigint NOT NULL DEFAULT 0, `http_code` int NOT NULL DEFAULT 0,
`last_error` varchar(500) NOT NULL DEFAULT '', UNIQUE KEY `event_id` (`event_id`),
KEY `due` (`status`,`next_run_time`), KEY `owner` (`tenant_id`,`user_id`),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `la_open_platform_document` (
`id` bigint unsigned NOT NULL AUTO_INCREMENT,
`create_time` bigint NOT NULL DEFAULT 0, `update_time` bigint NOT NULL DEFAULT 0,
`tenant_id` bigint unsigned NOT NULL DEFAULT 0, `slug` varchar(180) NOT NULL,
`version` int NOT NULL DEFAULT 1, `title` varchar(255) NOT NULL, `category` varchar(100) NOT NULL,
`status` varchar(20) NOT NULL DEFAULT 'draft', `content` mediumtext NOT NULL,
`contract_code` varchar(160) NOT NULL DEFAULT '', `sort` int NOT NULL DEFAULT 0,
UNIQUE KEY `version` (`tenant_id`,`slug`,`version`), KEY `published` (`tenant_id`,`status`,`sort`),
PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

