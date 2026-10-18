-- Prepare older conversation preference tables before canvas project snapshots, regardless of app update order.
-- Canvas can be installed before the conversation application. Keep its shared preference schema identical.
CREATE TABLE IF NOT EXISTS `la_aigc_llm_agent_preference` (
  `workspace_key` varchar(160) NOT NULL DEFAULT 'aigc_llm',
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `preferences` mediumtext NOT NULL,
  `create_time` int unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  UNIQUE KEY `owner` (`tenant_id`,`user_id`,`workspace_key`),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `la_aigc_llm_agent_preference` ADD COLUMN `workspace_key` varchar(160) NOT NULL DEFAULT 'aigc_llm';
ALTER TABLE `la_aigc_llm_agent_preference` DROP INDEX `owner`, ADD UNIQUE KEY `owner` (`tenant_id`,`user_id`,`workspace_key`);
