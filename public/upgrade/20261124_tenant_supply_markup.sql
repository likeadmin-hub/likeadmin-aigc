CREATE TABLE IF NOT EXISTS `la_tenant_power_supply_markup` (
  `tenant_id` int unsigned NOT NULL,
  `rate_percent` decimal(9,2) unsigned NOT NULL DEFAULT 0.00,
  `revision` int unsigned NOT NULL DEFAULT 0,
  `updated_by` int unsigned NOT NULL,
  `update_time` int unsigned NOT NULL,
  PRIMARY KEY (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台指定的租户算力供货溢价';

CREATE TABLE IF NOT EXISTS `la_tenant_power_supply_markup_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `admin_id` int unsigned NOT NULL,
  `request_id` char(64) CHARACTER SET ascii NOT NULL,
  `before_rate_percent` decimal(9,2) unsigned NOT NULL,
  `after_rate_percent` decimal(9,2) unsigned NOT NULL,
  `before_revision` int unsigned NOT NULL,
  `after_revision` int unsigned NOT NULL,
  `result_json` text NOT NULL,
  `create_time` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_request` (`request_id`),
  KEY `idx_tenant_revision` (`tenant_id`,`after_revision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户算力溢价操作记录';

INSERT INTO `la_system_menu` (pid,type,name,icon,sort,perms,paths,component,selected,params,is_cache,is_show,is_disable,app_code,source,source_menu_key,is_core,create_time,update_time)
SELECT parent.id,'A',permission.name,'',0,permission.perms,'','','','',0,1,0,'','core',permission.menu_key,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (SELECT id FROM `la_system_menu` WHERE component='tenant/lists/index' AND type='C' ORDER BY id LIMIT 1) parent
CROSS JOIN (
  SELECT '查看算力溢价' AS name,'tenant.tenant/supplyMarkup' AS perms,'core_tenant_supply_markup_read' AS menu_key
  UNION ALL SELECT '设置算力溢价','tenant.tenant/applySupplyMarkup','core_tenant_supply_markup_edit'
) permission
WHERE NOT EXISTS (SELECT 1 FROM `la_system_menu` existing WHERE existing.perms=permission.perms);
