-- P1/P2 Skill governance: platform-owned Agent orchestration policy.
CREATE TABLE IF NOT EXISTS `la_aigc_canvas_agent_orchestration_policy` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `policy_key` varchar(80) NOT NULL DEFAULT '',
  `config_json` longtext NULL,
  `enabled` tinyint unsigned NOT NULL DEFAULT 1,
  `version` int unsigned NOT NULL DEFAULT 1,
  `updated_by` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_policy` (`tenant_id`,`policy_key`,`delete_time`),
  KEY `idx_tenant_enabled` (`tenant_id`,`enabled`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AIGC canvas Agent orchestration policies';
