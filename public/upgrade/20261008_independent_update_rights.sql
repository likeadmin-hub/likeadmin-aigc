-- Global platform-only package authorization proof. Idempotent; no tenant tables or trading data.
CREATE TABLE IF NOT EXISTS `la_system_package_grant` (
  `package_id` int unsigned NOT NULL,
  `context_key` char(64) NOT NULL,
  `response_json` longtext NOT NULL COMMENT 'Private original signed response; includes short-lived credentials',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`package_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='System package site authorization';
