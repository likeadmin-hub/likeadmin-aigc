INSERT INTO `la_system_menu` (`pid`, `type`, `name`, `icon`, `sort`, `perms`, `paths`, `component`, `selected`, `params`, `is_cache`, `is_show`, `is_disable`, `app_code`, `source`, `source_menu_key`, `is_core`, `create_time`, `update_time`)
SELECT parent.`id`, 'A', '查看上游账户余额', '', 0, 'power.market/accountBalance', '', '', '', '', 0, 0, 0, '', 'core', 'core_power_market_account_balance', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `la_system_menu` parent
WHERE parent.`source_menu_key` = 'core_power_market'
  AND NOT EXISTS (
      SELECT 1 FROM `la_system_menu` existing
      WHERE existing.`source_menu_key` = 'core_power_market_account_balance'
  )
ORDER BY parent.`id` LIMIT 1;
