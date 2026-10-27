-- Distribution posters: additive, repeatable, published snapshots are immutable.
CREATE TABLE IF NOT EXISTS `la_distribution_poster_template` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL,
 `name` varchar(80) NOT NULL, `sort` int NOT NULL DEFAULT 0, `status` tinyint unsigned NOT NULL DEFAULT 0,
 `revision` int unsigned NOT NULL DEFAULT 1, `published_version` int unsigned NOT NULL DEFAULT 0,
 `draft_json` longtext NOT NULL, `create_time` int unsigned NOT NULL, `update_time` int unsigned NOT NULL,
 `delete_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), KEY `idx_tenant_list` (`tenant_id`,`delete_time`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `la_distribution_poster_version` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `template_id` int unsigned NOT NULL,
 `revision` int unsigned NOT NULL, `name` varchar(80) NOT NULL, `layout_json` longtext NOT NULL,
 `admin_id` int unsigned NOT NULL DEFAULT 0, `create_time` int unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_template_revision` (`template_id`,`revision`), KEY `idx_tenant` (`tenant_id`,`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
