-- 公众号历史管理页路由修复：保留原 URL，改为渠道设置下的独立隐藏路由。
SET NAMES utf8mb4;
SET @now := UNIX_TIMESTAMP();

INSERT IGNORE INTO `la_tenant_system_role_menu` (`role_id`,`menu_id`)
SELECT DISTINCT role_menu.`role_id`, child.`id`
FROM `la_tenant_system_role_menu` role_menu
INNER JOIN `la_tenant_system_menu` old_parent ON old_parent.`id`=role_menu.`menu_id`
INNER JOIN `la_tenant_system_menu` child ON child.`pid`=old_parent.`id`
WHERE old_parent.`paths`='wx_oa'
  AND child.`component` IN (
    'channel/wx_oa/config','channel/wx_oa/menu',
    'channel/wx_oa/reply/follow_reply','channel/wx_oa/reply/keyword_reply',
    'channel/wx_oa/reply/default_reply'
  );

UPDATE `la_tenant_system_menu` child
INNER JOIN `la_tenant_system_menu` root
  ON root.`tenant_id`=child.`tenant_id`
 AND root.`pid`=0
 AND (root.`source_menu_key`='core_tenant_channel_manage' OR root.`name`='渠道设置')
SET child.`pid`=root.`id`,
    child.`type`='C',
    child.`paths`=CASE child.`component`
      WHEN 'channel/wx_oa/config' THEN 'wx_oa/config'
      WHEN 'channel/wx_oa/menu' THEN 'wx_oa/menu'
      WHEN 'channel/wx_oa/reply/follow_reply' THEN 'wx_oa/reply/follow_reply'
      WHEN 'channel/wx_oa/reply/keyword_reply' THEN 'wx_oa/reply/keyword_reply'
      WHEN 'channel/wx_oa/reply/default_reply' THEN 'wx_oa/reply/default_reply'
      ELSE child.`paths`
    END,
    child.`selected`='channel/overview',
    child.`is_show`=0,
    child.`is_disable`=0,
    child.`update_time`=@now
WHERE child.`component` IN (
    'channel/wx_oa/config','channel/wx_oa/menu',
    'channel/wx_oa/reply/follow_reply','channel/wx_oa/reply/keyword_reply',
    'channel/wx_oa/reply/default_reply'
  );
