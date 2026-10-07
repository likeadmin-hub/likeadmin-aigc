-- Account tool pins are shared across devices; rows are explicitly tenant/user scoped.
CREATE TABLE IF NOT EXISTS `la_user_tool_pins` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `tool_ids` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_user` (`tenant_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户置顶工具';
