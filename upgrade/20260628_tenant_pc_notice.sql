CREATE TABLE IF NOT EXISTS `la_tenant_pc_notice` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
  `title` varchar(120) NOT NULL DEFAULT '' COMMENT '公告标题',
  `summary` varchar(255) NOT NULL DEFAULT '' COMMENT '公告摘要',
  `content` text NOT NULL COMMENT '公告正文',
  `image` varchar(255) NOT NULL DEFAULT '' COMMENT '封面图',
  `is_popup` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '是否进入自动弹窗:0否 1是',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '状态:0停用 1启用',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `publish_time` int unsigned NOT NULL DEFAULT 0 COMMENT '发布时间',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  `delete_time` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_status` (`tenant_id`,`status`,`sort`,`publish_time`),
  KEY `idx_popup` (`tenant_id`,`is_popup`,`status`,`publish_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户PC消息公告';

CREATE TABLE IF NOT EXISTS `la_tenant_pc_notice_read` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0 COMMENT '租户ID',
  `notice_id` int unsigned NOT NULL DEFAULT 0 COMMENT '公告ID',
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
  `read_time` int unsigned NOT NULL DEFAULT 0 COMMENT '阅读时间',
  `popup_time` int unsigned NOT NULL DEFAULT 0 COMMENT '弹窗时间',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_notice_user` (`tenant_id`,`notice_id`,`user_id`),
  KEY `idx_user_read` (`tenant_id`,`user_id`,`read_time`),
  KEY `idx_notice` (`notice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='租户PC消息公告阅读记录';

UPDATE `la_tenant_system_menu`
SET `app_code` = '', `source` = 'core', `source_menu_key` = 'core_tenant_message', `is_core` = 1, `update_time` = UNIX_TIMESTAMP()
WHERE `source` = 'core'
  AND `source_menu_key` = ''
  AND `name` = '消息管理'
  AND `paths` = 'message';

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT DISTINCT ta.`id`,0,'C','消息公告','el-icon-Bell',97,'notice.pc_notice/lists','notice','message/pc_notice/index','','',0,1,0,'','core','core_tenant_pc_notice',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant` ta
WHERE (ta.`delete_time` IS NULL OR ta.`delete_time` = 0)
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = ta.`id`
      AND exists_menu.`source_menu_key` = 'core_tenant_pc_notice'
  );

UPDATE `la_tenant_system_menu` child
SET child.`pid` = 0,
    child.`type` = 'C',
    child.`name` = '消息公告',
    child.`paths` = 'notice',
    child.`perms` = 'notice.pc_notice/lists',
    child.`component` = 'message/pc_notice/index',
    child.`icon` = 'el-icon-Bell',
    child.`sort` = 97,
    child.`is_show` = 1,
    child.`is_disable` = 0,
    child.`app_code` = '',
    child.`source` = 'core',
    child.`is_core` = 1,
    child.`update_time` = UNIX_TIMESTAMP()
WHERE child.`source_menu_key` = 'core_tenant_pc_notice'
  AND child.`source` = 'core'
  AND (
    child.`pid` <> 0
    OR child.`type` <> 'C'
    OR child.`name` <> '消息公告'
    OR child.`paths` <> 'notice'
    OR child.`sort` <> 97
  );

INSERT INTO `la_tenant_system_menu` (`tenant_id`,`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`)
SELECT menu.`tenant_id`,menu.`id`,child.`type`,child.`name`,child.`icon`,child.`sort`,child.`perms`,child.`paths`,child.`component`,child.`selected`,child.`params`,child.`is_cache`,child.`is_show`,child.`is_disable`,child.`app_code`,child.`source`,child.`source_menu_key`,child.`is_core`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_tenant_system_menu` menu
JOIN (
  SELECT 'A' AS `type`, '详情' AS `name`, '' AS `icon`, 0 AS `sort`, 'notice.pc_notice/detail' AS `perms`, '' AS `paths`, '' AS `component`, '' AS `selected`, '' AS `params`, 0 AS `is_cache`, 0 AS `is_show`, 0 AS `is_disable`, '' AS `app_code`, 'core' AS `source`, 'core_tenant_pc_notice_detail' AS `source_menu_key`, 1 AS `is_core`
  UNION ALL SELECT 'A', '新增', '', 0, 'notice.pc_notice/add', '', '', '', '', 0, 0, 0, '', 'core', 'core_tenant_pc_notice_add', 1
  UNION ALL SELECT 'A', '编辑', '', 0, 'notice.pc_notice/edit', '', '', '', '', 0, 0, 0, '', 'core', 'core_tenant_pc_notice_edit', 1
  UNION ALL SELECT 'A', '删除', '', 0, 'notice.pc_notice/delete', '', '', '', '', 0, 0, 0, '', 'core', 'core_tenant_pc_notice_delete', 1
  UNION ALL SELECT 'A', '状态', '', 0, 'notice.pc_notice/status', '', '', '', '', 0, 0, 0, '', 'core', 'core_tenant_pc_notice_status', 1
) child
WHERE menu.`source_menu_key` = 'core_tenant_pc_notice'
  AND menu.`source` = 'core'
  AND NOT EXISTS (
    SELECT 1 FROM `la_tenant_system_menu` exists_menu
    WHERE exists_menu.`tenant_id` = menu.`tenant_id`
      AND exists_menu.`source_menu_key` = child.`source_menu_key`
  );

UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` parent
  ON parent.`tenant_id` = child.`tenant_id`
  AND parent.`source_menu_key` = 'core_tenant_pc_notice'
  AND parent.`source` = 'core'
SET child.`pid` = parent.`id`, child.`update_time` = UNIX_TIMESTAMP()
WHERE child.`source_menu_key` IN (
    'core_tenant_pc_notice_detail',
    'core_tenant_pc_notice_add',
    'core_tenant_pc_notice_edit',
    'core_tenant_pc_notice_delete',
    'core_tenant_pc_notice_status'
  )
  AND child.`source` = 'core'
  AND child.`pid` <> parent.`id`;

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT DISTINCT rm.`role_id`, menu.`id`
FROM `la_tenant_system_role_menu` rm
JOIN `la_tenant_system_role` role ON role.`id` = rm.`role_id`
JOIN `la_tenant_system_menu` parent ON parent.`tenant_id` = role.`tenant_id` AND parent.`source_menu_key` = 'core_tenant_case_gallery'
JOIN `la_tenant_system_menu` menu ON menu.`tenant_id` = role.`tenant_id`
WHERE rm.`menu_id` = parent.`id`
  AND menu.`source_menu_key` IN (
    'core_tenant_pc_notice',
    'core_tenant_pc_notice_detail',
    'core_tenant_pc_notice_add',
    'core_tenant_pc_notice_edit',
    'core_tenant_pc_notice_delete',
    'core_tenant_pc_notice_status'
  )
  AND (role.`delete_time` IS NULL OR role.`delete_time` = 0);
