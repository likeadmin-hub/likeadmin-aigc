UPDATE `la_tenant_system_menu`
SET `type` = 'M',
    `component` = '',
    `update_time` = UNIX_TIMESTAMP()
WHERE `source_menu_key` = 'core_tenant_system_default'
  AND `paths` = 'system-default';

UPDATE `la_tenant_system_menu`
SET `pid` = 158,
    `sort` = 10,
    `update_time` = UNIX_TIMESTAMP()
WHERE `id` = 159;

UPDATE `la_tenant_system_menu`
SET `pid` = 158,
    `sort` = 20,
    `update_time` = UNIX_TIMESTAMP()
WHERE `id` = 70;

UPDATE `la_tenant_system_menu`
SET `pid` = 158,
    `sort` = 30,
    `update_time` = UNIX_TIMESTAMP()
WHERE `id` = 101;

UPDATE `la_tenant_system_menu`
SET `pid` = 158,
    `sort` = 40,
    `update_time` = UNIX_TIMESTAMP()
WHERE `id` = 63;

UPDATE `la_system_menu`
SET `type` = 'M',
    `component` = '',
    `update_time` = UNIX_TIMESTAMP()
WHERE `source_menu_key` = 'core_system_default'
  AND `paths` = 'system-default';

UPDATE `la_system_menu`
SET `pid` = 158,
    `sort` = 10,
    `update_time` = UNIX_TIMESTAMP()
WHERE `id` = 159;

UPDATE `la_system_menu`
SET `pid` = 158,
    `sort` = 20,
    `update_time` = UNIX_TIMESTAMP()
WHERE `id` = 70;

UPDATE `la_system_menu`
SET `pid` = 158,
    `sort` = 30,
    `update_time` = UNIX_TIMESTAMP()
WHERE `id` = 101;

UPDATE `la_system_menu`
SET `pid` = 158,
    `sort` = 40,
    `update_time` = UNIX_TIMESTAMP()
WHERE `id` = 63;
