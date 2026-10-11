-- User-defined asset labels. Defaults are supplied lazily for existing accounts.
CREATE TABLE IF NOT EXISTS `la_user_asset_labels` (
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `labels` text NOT NULL,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Account asset label catalog';
