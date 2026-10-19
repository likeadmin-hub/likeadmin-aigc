-- Core shared tables: explicit owner/recipient, never tenant-suffixed.
CREATE TABLE IF NOT EXISTS `la_point_alert_setting` (
 `owner_id` int unsigned NOT NULL, `revision` int unsigned NOT NULL DEFAULT 1, `config` longtext NOT NULL, `update_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_point_alert_account` (
 `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL DEFAULT 0, `state` longtext NOT NULL, `balance` decimal(20,2) NOT NULL DEFAULT 0, `reason` varchar(30) NOT NULL DEFAULT '', `update_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_point_alert_dirty` (
 `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL DEFAULT 0, `version` bigint unsigned NOT NULL DEFAULT 1, `due_at` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`tenant_id`,`user_id`), KEY `due` (`due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_point_alert_round` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `owner_id` int unsigned NOT NULL, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL DEFAULT 0,
 `cycle_no` int unsigned NOT NULL, `sequence` int unsigned NOT NULL, `rule_revision` int unsigned NOT NULL, `balance` decimal(20,2) NOT NULL, `threshold` decimal(20,2) NOT NULL,
 `title` varchar(120) NOT NULL, `content` text NOT NULL, `status` varchar(20) NOT NULL DEFAULT 'pending', `read_time` int unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL, `update_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `admission` (`tenant_id`,`user_id`,`cycle_no`,`sequence`), KEY `owner_records` (`owner_id`,`id`), KEY `private_inbox` (`tenant_id`,`user_id`,`id`), KEY `pending` (`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_point_alert_delivery` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `round_id` bigint unsigned NOT NULL, `channel` varchar(16) NOT NULL, `recipient` varchar(200) NOT NULL DEFAULT '', `status` varchar(20) NOT NULL DEFAULT 'pending', `attempts` tinyint unsigned NOT NULL DEFAULT 0, `lease_at` int unsigned NOT NULL DEFAULT 0, `result` varchar(255) NOT NULL DEFAULT '', `update_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `one_delivery` (`round_id`,`channel`,`recipient`), KEY `work` (`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_point_alert_contact` (
 `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL DEFAULT 0, `config` longtext NOT NULL, `update_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_point_alert_cursor` (
 `owner_id` int unsigned NOT NULL, `last_id` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
