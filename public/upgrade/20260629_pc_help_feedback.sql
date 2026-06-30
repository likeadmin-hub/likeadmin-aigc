CREATE TABLE IF NOT EXISTS `la_pc_feedback` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
  `type` varchar(30) NOT NULL DEFAULT 'feature' COMMENT '反馈类型',
  `content` varchar(500) NOT NULL DEFAULT '' COMMENT '反馈内容',
  `images` text COMMENT '反馈图片',
  `contact` varchar(120) NOT NULL DEFAULT '' COMMENT '联系方式',
  `status` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '状态:0待处理 1处理中 2已处理',
  `reply` varchar(500) NOT NULL DEFAULT '' COMMENT '处理回复',
  `create_time` int unsigned NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  `delete_time` int unsigned NOT NULL DEFAULT 0 COMMENT '删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`create_time`),
  KEY `idx_delete` (`tenant_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='PC用户反馈表';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,system_default.`id`,'C','客服设置','el-icon-Service',35,'setting.customer_service/getConfig','customer-service','setting/customer_service/index','','',0,1,0,'','core','core_tenant_customer_service',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` system_default
WHERE system_default.`tenant_id` = 0
  AND system_default.`source_menu_key` = 'core_tenant_system_default'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = 0
      AND exists_menu.`source_menu_key` = 'core_tenant_customer_service'
  );

SET @core_tenant_customer_service_template_id := (
  SELECT `id` FROM `la_tenant_system_menu`
  WHERE `tenant_id` = 0 AND `source_menu_key` = 'core_tenant_customer_service'
  ORDER BY `id` DESC
  LIMIT 1
);

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT 0,@core_tenant_customer_service_template_id,child.`type`,child.`name`,child.`icon`,child.`sort`,child.`perms`,child.`paths`,child.`component`,child.`selected`,child.`params`,child.`is_cache`,child.`is_show`,child.`is_disable`,child.`app_code`,child.`source`,child.`source_menu_key`,child.`is_core`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM (
  SELECT 'A' AS `type`,'保存' AS `name`,'' AS `icon`,0 AS `sort`,'setting.customer_service/setConfig' AS `perms`,'' AS `paths`,'' AS `component`,'' AS `selected`,'' AS `params`,0 AS `is_cache`,1 AS `is_show`,0 AS `is_disable`,'' AS `app_code`,'core' AS `source`,'core_tenant_customer_service_save' AS `source_menu_key`,1 AS `is_core`
  UNION ALL SELECT 'A','反馈列表','',0,'setting.pc_feedback/lists','','','','',0,0,0,'','core','core_tenant_pc_feedback_lists',1
  UNION ALL SELECT 'A','处理反馈','',0,'setting.pc_feedback/reply','','','','',0,0,0,'','core','core_tenant_pc_feedback_reply',1
) child
WHERE @core_tenant_customer_service_template_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = 0
      AND exists_menu.`source_menu_key` = child.`source_menu_key`
  );

UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` parent
  ON parent.`tenant_id` = child.`tenant_id`
  AND parent.`source_menu_key` = 'core_tenant_customer_service'
SET child.`pid` = parent.`id`, child.`update_time` = UNIX_TIMESTAMP()
WHERE child.`source_menu_key` IN (
  'core_tenant_customer_service_save',
  'core_tenant_pc_feedback_lists',
  'core_tenant_pc_feedback_reply'
) AND child.`pid` <> parent.`id`;

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT tenant.`id`,system_default.`id`,'C','客服设置','el-icon-Service',35,'setting.customer_service/getConfig','customer-service','setting/customer_service/index','','',0,1,0,'','core','core_tenant_customer_service',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant` tenant
JOIN `la_tenant_system_menu` system_default
  ON system_default.`tenant_id` = tenant.`id`
  AND system_default.`source_menu_key` = 'core_tenant_system_default'
WHERE (tenant.`delete_time` IS NULL OR tenant.`delete_time` = 0)
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = tenant.`id`
      AND exists_menu.`source_menu_key` = 'core_tenant_customer_service'
  );

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT parent.`tenant_id`,parent.`id`,child.`type`,child.`name`,child.`icon`,child.`sort`,child.`perms`,child.`paths`,child.`component`,child.`selected`,child.`params`,child.`is_cache`,child.`is_show`,child.`is_disable`,child.`app_code`,child.`source`,child.`source_menu_key`,child.`is_core`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` parent
JOIN (
  SELECT 'A' AS `type`,'保存' AS `name`,'' AS `icon`,0 AS `sort`,'setting.customer_service/setConfig' AS `perms`,'' AS `paths`,'' AS `component`,'' AS `selected`,'' AS `params`,0 AS `is_cache`,1 AS `is_show`,0 AS `is_disable`,'' AS `app_code`,'core' AS `source`,'core_tenant_customer_service_save' AS `source_menu_key`,1 AS `is_core`
  UNION ALL SELECT 'A','反馈列表','',0,'setting.pc_feedback/lists','','','','',0,0,0,'','core','core_tenant_pc_feedback_lists',1
  UNION ALL SELECT 'A','处理反馈','',0,'setting.pc_feedback/reply','','','','',0,0,0,'','core','core_tenant_pc_feedback_reply',1
) child
WHERE parent.`source_menu_key` = 'core_tenant_customer_service'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = parent.`tenant_id`
      AND exists_menu.`source_menu_key` = child.`source_menu_key`
  );

UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` parent
  ON parent.`tenant_id` = child.`tenant_id`
  AND parent.`source_menu_key` = 'core_tenant_customer_service'
SET child.`pid` = parent.`id`, child.`update_time` = UNIX_TIMESTAMP()
WHERE child.`source_menu_key` IN (
  'core_tenant_customer_service_save',
  'core_tenant_pc_feedback_lists',
  'core_tenant_pc_feedback_reply'
) AND child.`pid` <> parent.`id`;

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT DISTINCT rm.`role_id`, menu.`id`
FROM `la_tenant_system_role_menu` rm
JOIN `la_tenant_system_role` tenant_role ON tenant_role.`id` = rm.`role_id`
JOIN `la_tenant_system_menu` menu ON menu.`tenant_id` = tenant_role.`tenant_id`
WHERE menu.`source_menu_key` IN (
  'core_tenant_customer_service',
  'core_tenant_customer_service_save',
  'core_tenant_pc_feedback_lists',
  'core_tenant_pc_feedback_reply'
) AND (tenant_role.`delete_time` IS NULL OR tenant_role.`delete_time` = 0);
