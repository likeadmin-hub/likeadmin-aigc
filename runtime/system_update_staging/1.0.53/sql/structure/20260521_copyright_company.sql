UPDATE `la_config`
SET `value` = '[{"key":"贵州猿创科技有限责任公司","value":""}]',
    `update_time` = UNIX_TIMESTAMP()
WHERE `type` = 'copyright'
  AND `name` = 'config';

INSERT INTO `la_config` (`type`, `name`, `value`, `create_time`, `update_time`)
SELECT 'copyright', 'config', '[{"key":"贵州猿创科技有限责任公司","value":""}]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
WHERE NOT EXISTS (
    SELECT 1 FROM `la_config` WHERE `type` = 'copyright' AND `name` = 'config'
);

UPDATE `la_tenant_config`
SET `value` = '[{"key":"贵州猿创科技有限责任公司","value":""}]',
    `update_time` = UNIX_TIMESTAMP()
WHERE `type` = 'copyright'
  AND `name` = 'config';

INSERT INTO `la_tenant_config` (`tenant_id`, `type`, `name`, `value`, `create_time`, `update_time`)
SELECT `id`, 'copyright', 'config', '[{"key":"贵州猿创科技有限责任公司","value":""}]', UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `la_tenant`
WHERE NOT EXISTS (
    SELECT 1 FROM `la_tenant_config`
    WHERE `la_tenant_config`.`tenant_id` = `la_tenant`.`id`
      AND `la_tenant_config`.`type` = 'copyright'
      AND `la_tenant_config`.`name` = 'config'
);
