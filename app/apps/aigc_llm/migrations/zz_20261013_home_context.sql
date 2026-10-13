-- Source-backed memory for the homepage Agent; shared tables are tenant/user/session scoped.
CREATE TABLE IF NOT EXISTS `la_aigc_llm_agent_context` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `session_id` int unsigned NOT NULL,
  `summary` mediumtext NOT NULL,
  `through_message_id` bigint unsigned NOT NULL DEFAULT 0,
  `focus` text NOT NULL,
  `focus_run_id` bigint unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_owner` (`tenant_id`,`user_id`,`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
