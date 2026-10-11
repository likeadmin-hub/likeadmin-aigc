-- Shared account asset library metadata; no source result or physical file is removed.
-- Global tenant_id-scoped table, like user_appearance; no per-tenant seed rows are required.
CREATE TABLE IF NOT EXISTS `la_user_asset_state` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `asset_ref` varchar(100) NOT NULL,
  `favorite` tinyint unsigned DEFAULT NULL,
  `label` varchar(20) NOT NULL DEFAULT '',
  `trashed_at` int unsigned NOT NULL DEFAULT 0,
  `hidden_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owner_asset` (`tenant_id`,`user_id`,`asset_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Account asset library state';
