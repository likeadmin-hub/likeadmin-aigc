CREATE TABLE IF NOT EXISTS `la_ai_app_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `task_no` varchar(48) NOT NULL DEFAULT '',
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `app_code` varchar(80) NOT NULL DEFAULT '',
  `action_code` varchar(80) NOT NULL DEFAULT '',
  `business_table` varchar(100) NOT NULL DEFAULT '',
  `business_id` int unsigned NOT NULL DEFAULT 0,
  `parent_task_id` int unsigned NOT NULL DEFAULT 0,
  `status` varchar(30) NOT NULL DEFAULT 'queued',
  `progress` tinyint unsigned NOT NULL DEFAULT 0,
  `request_summary` text,
  `result_summary` text,
  `estimated_tenant_cost` decimal(16,6) NOT NULL DEFAULT 0.000000,
  `estimated_user_price` decimal(16,6) NOT NULL DEFAULT 0.000000,
  `actual_tenant_cost` decimal(16,6) NOT NULL DEFAULT 0.000000,
  `actual_user_price` decimal(16,6) NOT NULL DEFAULT 0.000000,
  `idempotency_key` varchar(64) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_no` (`task_no`),
  UNIQUE KEY `uk_tenant_idempotency` (`tenant_id`,`idempotency_key`),
  KEY `idx_tenant_app_time` (`tenant_id`,`app_code`,`create_time`),
  KEY `idx_business` (`business_table`,`business_id`),
  KEY `idx_user_time` (`tenant_id`,`user_id`,`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI应用任务';

CREATE TABLE IF NOT EXISTS `la_ai_consumption_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `consume_no` varchar(48) NOT NULL DEFAULT '',
  `app_task_id` int unsigned NOT NULL DEFAULT 0,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `app_code` varchar(80) NOT NULL DEFAULT '',
  `action_code` varchar(80) NOT NULL DEFAULT '',
  `resource_type` varchar(20) NOT NULL DEFAULT '',
  `product_id` int unsigned NOT NULL DEFAULT 0,
  `sku_id` int unsigned NOT NULL DEFAULT 0,
  `model_code` varchar(160) NOT NULL DEFAULT '',
  `api_code` varchar(120) NOT NULL DEFAULT '',
  `protocol` varchar(60) NOT NULL DEFAULT '',
  `provider` varchar(80) NOT NULL DEFAULT '',
  `upstream_request_id` varchar(160) NOT NULL DEFAULT '',
  `upstream_task_id` varchar(160) NOT NULL DEFAULT '',
  `quantity` decimal(16,6) NOT NULL DEFAULT 0.000000,
  `usage_unit` varchar(40) NOT NULL DEFAULT '',
  `usage_snapshot` text,
  `price_snapshot` text,
  `request_summary` text,
  `response_summary` text,
  `run_status` varchar(30) NOT NULL DEFAULT 'pending',
  `billing_status` varchar(30) NOT NULL DEFAULT 'none',
  `reserved_tenant_cost` decimal(16,6) NOT NULL DEFAULT 0.000000,
  `reserved_user_price` decimal(16,6) NOT NULL DEFAULT 0.000000,
  `actual_tenant_cost` decimal(16,6) NOT NULL DEFAULT 0.000000,
  `actual_user_price` decimal(16,6) NOT NULL DEFAULT 0.000000,
  `tenant_point_sn` varchar(64) NOT NULL DEFAULT '',
  `user_point_sn` varchar(64) NOT NULL DEFAULT '',
  `error_code` varchar(64) NOT NULL DEFAULT '',
  `error_message` varchar(1000) NOT NULL DEFAULT '',
  `refresh_requested_at` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `finish_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_consume_no` (`consume_no`),
  KEY `idx_app_task` (`app_task_id`),
  KEY `idx_tenant_time` (`tenant_id`,`create_time`),
  KEY `idx_tenant_status` (`tenant_id`,`run_status`,`billing_status`),
  KEY `idx_upstream_task` (`upstream_task_id`),
  KEY `idx_product_sku` (`product_id`,`sku_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI上游消耗日志';

CREATE TABLE IF NOT EXISTS `la_ai_consumption_event` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `consumption_id` int unsigned NOT NULL DEFAULT 0,
  `event_type` varchar(30) NOT NULL DEFAULT '',
  `event_status` varchar(30) NOT NULL DEFAULT '',
  `attempt_no` int unsigned NOT NULL DEFAULT 1,
  `payload_summary` text,
  `payload_ciphertext` mediumtext,
  `http_status` int unsigned NOT NULL DEFAULT 0,
  `elapsed_ms` int unsigned NOT NULL DEFAULT 0,
  `create_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_consumption_time` (`consumption_id`,`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI消耗调用事件';

SET @has_ai_app_task_id := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_image_task' AND COLUMN_NAME = 'app_task_id');
SET @ai_app_task_id_sql := IF(@has_ai_app_task_id = 0, 'ALTER TABLE `la_aigc_image_task` ADD COLUMN `app_task_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''统一应用任务ID'' AFTER `id`, ADD KEY `idx_app_task` (`app_task_id`)', 'SELECT 1');
PREPARE ai_app_task_id_stmt FROM @ai_app_task_id_sql;
EXECUTE ai_app_task_id_stmt;
DEALLOCATE PREPARE ai_app_task_id_stmt;

SET @has_consumption_id := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_image_billing' AND COLUMN_NAME = 'consumption_id');
SET @consumption_id_sql := IF(@has_consumption_id = 0, 'ALTER TABLE `la_aigc_image_billing` ADD COLUMN `consumption_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''统一消耗日志ID'' AFTER `id`, ADD KEY `idx_consumption` (`consumption_id`)', 'SELECT 1');
PREPARE consumption_id_stmt FROM @consumption_id_sql;
EXECUTE consumption_id_stmt;
DEALLOCATE PREPARE consumption_id_stmt;

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,'M','任务日志','el-icon-Document','50','','task-log','','','',0,1,0,'','core','core_task_log_platform',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_system_menu` WHERE `source_menu_key`='core_task_log_platform');
SET @task_log_platform_id := (SELECT `id` FROM `la_system_menu` WHERE `source_menu_key`='core_task_log_platform' LIMIT 1);
UPDATE `la_system_menu` SET `pid`=@task_log_platform_id,`name`='应用日志',`perms`='ai_task/lists',`paths`='application',`component`='tenant/task/index',`update_time`=UNIX_TIMESTAMP() WHERE `source_menu_key`='core_ai_task_platform';
INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT @task_log_platform_id,'C','应用日志','el-icon-List',100,'ai_task/lists','application','tenant/task/index','','',0,1,0,'','core','core_ai_task_platform',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE @task_log_platform_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_system_menu` WHERE `source_menu_key`='core_ai_task_platform');
UPDATE `la_tenant_system_menu` SET `name`='点数流水',`update_time`=UNIX_TIMESTAMP() WHERE `source_menu_key`='core_tenant_power_mall_consume_logs';

INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT @task_log_platform_id,'C','消耗日志','el-icon-DataAnalysis',90,'ai_consumption/lists','consumption','power_mall/consumption','','',0,1,0,'','core','core_ai_consumption_platform',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE @task_log_platform_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_system_menu` WHERE `source_menu_key`='core_ai_consumption_platform');
UPDATE `la_system_menu` SET `pid`=@task_log_platform_id,`name`='消耗日志',`perms`='ai_consumption/lists',`paths`='consumption',`component`='power_mall/consumption',`update_time`=UNIX_TIMESTAMP() WHERE `source_menu_key`='core_ai_consumption_platform';
SET @ai_consumption_platform_id := (SELECT `id` FROM `la_system_menu` WHERE `source_menu_key`='core_ai_consumption_platform' LIMIT 1);
INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT @ai_consumption_platform_id,'A','详情','',0,'ai_consumption/detail','','','','',0,0,0,'','core','core_ai_consumption_platform_detail',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE @ai_consumption_platform_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_system_menu` WHERE `source_menu_key`='core_ai_consumption_platform_detail');

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT source.`tenant_id`,0,'M','任务日志','el-icon-Document',50,'','task-log','','','',0,1,0,'','core','core_task_log_tenant',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (SELECT DISTINCT `tenant_id` FROM `la_tenant_system_menu` WHERE `source_menu_key` IN ('core_ai_task_tenant','core_tenant_power_market','core_ai_consumption_tenant')) source
WHERE NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` exists_menu WHERE exists_menu.`tenant_id`=source.`tenant_id` AND exists_menu.`source_menu_key`='core_task_log_tenant');
UPDATE `la_tenant_system_menu` task
JOIN `la_tenant_system_menu` parent ON parent.`tenant_id`=task.`tenant_id` AND parent.`source_menu_key`='core_task_log_tenant'
SET task.`pid`=parent.`id`,task.`name`='应用日志',task.`perms`='ai_task/lists',task.`paths`='application',task.`component`='consumer/task/index',task.`update_time`=UNIX_TIMESTAMP()
WHERE task.`source_menu_key`='core_ai_task_tenant';
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT parent.`tenant_id`,parent.`id`,'C','应用日志','el-icon-List',100,'ai_task/lists','application','consumer/task/index','','',0,1,0,'','core','core_ai_task_tenant',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`source_menu_key`='core_task_log_tenant'
  AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` exists_menu WHERE exists_menu.`tenant_id`=parent.`tenant_id` AND exists_menu.`source_menu_key`='core_ai_task_tenant');
INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT parent.`tenant_id`,parent.`id`,'C','消耗日志','el-icon-DataAnalysis',90,'ai_consumption/lists','consumption','power_mall/consumption','','',0,1,0,'','core','core_ai_consumption_tenant',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`source_menu_key`='core_task_log_tenant'
  AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` exists_menu WHERE exists_menu.`tenant_id`=parent.`tenant_id` AND exists_menu.`source_menu_key`='core_ai_consumption_tenant');
UPDATE `la_tenant_system_menu` consumption
JOIN `la_tenant_system_menu` parent ON parent.`tenant_id`=consumption.`tenant_id` AND parent.`source_menu_key`='core_task_log_tenant'
SET consumption.`pid`=parent.`id`,consumption.`name`='消耗日志',consumption.`perms`='ai_consumption/lists',consumption.`paths`='consumption',consumption.`component`='power_mall/consumption',consumption.`update_time`=UNIX_TIMESTAMP()
WHERE consumption.`source_menu_key`='core_ai_consumption_tenant';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT parent.`tenant_id`,parent.`id`,'A','详情','',0,'ai_consumption/detail','','','','',0,0,0,'','core','core_ai_consumption_tenant_detail',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
WHERE parent.`source_menu_key`='core_ai_consumption_tenant'
  AND NOT EXISTS (SELECT 1 FROM `la_tenant_system_menu` exists_menu WHERE exists_menu.`tenant_id`=parent.`tenant_id` AND exists_menu.`source_menu_key`='core_ai_consumption_tenant_detail');
