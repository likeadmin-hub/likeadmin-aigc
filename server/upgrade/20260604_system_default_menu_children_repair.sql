SET @menu_table = 'la_tenant_system_menu';
SET @menu_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @menu_table, '` ADD COLUMN `app_code` varchar(64) NOT NULL DEFAULT '''' COMMENT ''应用标识'''), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @menu_table AND COLUMN_NAME = 'app_code');
PREPARE menu_stmt FROM @menu_sql;
EXECUTE menu_stmt;
DEALLOCATE PREPARE menu_stmt;

SET @menu_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @menu_table, '` ADD COLUMN `source` varchar(20) NOT NULL DEFAULT ''core'' COMMENT ''菜单来源'''), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @menu_table AND COLUMN_NAME = 'source');
PREPARE menu_stmt FROM @menu_sql;
EXECUTE menu_stmt;
DEALLOCATE PREPARE menu_stmt;

SET @menu_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @menu_table, '` ADD COLUMN `source_menu_key` varchar(120) NOT NULL DEFAULT '''' COMMENT ''来源菜单key'''), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @menu_table AND COLUMN_NAME = 'source_menu_key');
PREPARE menu_stmt FROM @menu_sql;
EXECUTE menu_stmt;
DEALLOCATE PREPARE menu_stmt;

SET @menu_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @menu_table, '` ADD COLUMN `is_core` tinyint NOT NULL DEFAULT 1 COMMENT ''是否核心菜单'''), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @menu_table AND COLUMN_NAME = 'is_core');
PREPARE menu_stmt FROM @menu_sql;
EXECUTE menu_stmt;
DEALLOCATE PREPARE menu_stmt;

SET @menu_table = 'la_system_menu';
SET @menu_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @menu_table, '` ADD COLUMN `app_code` varchar(64) NOT NULL DEFAULT '''' COMMENT ''应用标识'''), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @menu_table AND COLUMN_NAME = 'app_code');
PREPARE menu_stmt FROM @menu_sql;
EXECUTE menu_stmt;
DEALLOCATE PREPARE menu_stmt;

SET @menu_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @menu_table, '` ADD COLUMN `source` varchar(20) NOT NULL DEFAULT ''core'' COMMENT ''菜单来源'''), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @menu_table AND COLUMN_NAME = 'source');
PREPARE menu_stmt FROM @menu_sql;
EXECUTE menu_stmt;
DEALLOCATE PREPARE menu_stmt;

SET @menu_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @menu_table, '` ADD COLUMN `source_menu_key` varchar(120) NOT NULL DEFAULT '''' COMMENT ''来源菜单key'''), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @menu_table AND COLUMN_NAME = 'source_menu_key');
PREPARE menu_stmt FROM @menu_sql;
EXECUTE menu_stmt;
DEALLOCATE PREPARE menu_stmt;

SET @menu_sql = (SELECT IF(COUNT(*) = 0, CONCAT('ALTER TABLE `', @menu_table, '` ADD COLUMN `is_core` tinyint NOT NULL DEFAULT 1 COMMENT ''是否核心菜单'''), 'SELECT 1') FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @menu_table AND COLUMN_NAME = 'is_core');
PREPARE menu_stmt FROM @menu_sql;
EXECUTE menu_stmt;
DEALLOCATE PREPARE menu_stmt;

UPDATE `la_tenant_system_menu` app_center
JOIN `la_tenant_system_menu` system_menu
  ON system_menu.`tenant_id` = app_center.`tenant_id`
 AND (system_menu.`source_menu_key` = 'core_tenant_system_default'
      OR (system_menu.`name` = '系统应用' AND system_menu.`paths` = 'system-default'))
SET system_menu.`pid` = app_center.`id`,
    system_menu.`type` = 'M',
    system_menu.`name` = '系统应用',
    system_menu.`icon` = 'el-icon-Setting',
    system_menu.`sort` = 10,
    system_menu.`paths` = 'system-default',
    system_menu.`component` = '',
    system_menu.`app_code` = 'system_default',
    system_menu.`source` = 'core',
    system_menu.`source_menu_key` = 'core_tenant_system_default',
    system_menu.`is_core` = 1,
    system_menu.`is_show` = 1,
    system_menu.`is_disable` = 0,
    system_menu.`update_time` = UNIX_TIMESTAMP()
WHERE app_center.`source_menu_key` = 'core_tenant_app_center'
  AND system_menu.`source` <> 'tenant';

UPDATE `la_tenant_system_menu` child
JOIN `la_tenant_system_menu` parent
  ON parent.`tenant_id` = child.`tenant_id`
 AND parent.`source_menu_key` = 'core_tenant_system_default'
SET child.`pid` = parent.`id`,
    child.`app_code` = 'system_default',
    child.`source` = 'core',
    child.`is_core` = 1,
    child.`is_show` = 1,
    child.`is_disable` = 0,
    child.`sort` = CASE child.`id`
        WHEN 159 THEN 10
        WHEN 70 THEN 20
        WHEN 101 THEN 30
        WHEN 63 THEN 40
        ELSE child.`sort`
    END,
    child.`source_menu_key` = CASE child.`id`
        WHEN 159 THEN 'core_tenant_recharge_config'
        WHEN 70 THEN 'core_tenant_article'
        WHEN 101 THEN 'core_tenant_message'
        WHEN 63 THEN 'core_tenant_material'
        ELSE child.`source_menu_key`
    END,
    child.`update_time` = UNIX_TIMESTAMP()
WHERE child.`source` <> 'tenant'
  AND child.`id` IN (159,70,101,63);

UPDATE `la_tenant_system_menu` item
SET item.`app_code` = 'system_default',
    item.`source` = 'core',
    item.`is_core` = 1,
    item.`source_menu_key` = CASE item.`id`
        WHEN 160 THEN 'core_tenant_recharge_config_save'
        WHEN 64 THEN 'core_tenant_material_index'
        WHEN 71 THEN 'core_tenant_article_lists'
        WHEN 72 THEN 'core_tenant_article_lists_edit'
        WHEN 73 THEN 'core_tenant_article_column'
        WHEN 74 THEN 'core_tenant_article_add'
        WHEN 75 THEN 'core_tenant_article_detail'
        WHEN 76 THEN 'core_tenant_article_delete'
        WHEN 77 THEN 'core_tenant_article_status'
        WHEN 78 THEN 'core_tenant_article_column_add'
        WHEN 79 THEN 'core_tenant_article_column_delete'
        WHEN 80 THEN 'core_tenant_article_column_detail'
        WHEN 81 THEN 'core_tenant_article_column_status'
        WHEN 105 THEN 'core_tenant_article_edit'
        WHEN 102 THEN 'core_tenant_notice'
        WHEN 103 THEN 'core_tenant_notice_detail'
        WHEN 104 THEN 'core_tenant_notice_edit'
        WHEN 107 THEN 'core_tenant_sms'
        WHEN 108 THEN 'core_tenant_sms_setup'
        WHEN 109 THEN 'core_tenant_sms_detail'
        ELSE item.`source_menu_key`
    END,
    item.`update_time` = UNIX_TIMESTAMP()
WHERE item.`source` <> 'tenant'
  AND item.`id` IN (160,64,71,72,73,74,75,76,77,78,79,80,81,105,102,103,104,107,108,109);

UPDATE `la_system_menu`
SET `type` = 'M',
    `component` = '',
    `update_time` = UNIX_TIMESTAMP()
WHERE `source_menu_key` = 'core_system_default'
  AND `paths` = 'system-default';
