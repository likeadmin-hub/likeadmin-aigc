-- Account preferences use explicit tenant/user identity, shared across terminals.
CREATE TABLE IF NOT EXISTS `la_user_appearance` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `preferences_json` mediumtext NOT NULL,
  `revision` int unsigned NOT NULL DEFAULT 1,
  `create_time` int unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户外观与定制皮肤';
