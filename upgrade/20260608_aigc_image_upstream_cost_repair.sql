UPDATE `la_aigc_image_channel_spec`
SET `upstream_cost_text` = '请在规格价格页点击查询上游价格后同步',
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND (`upstream_unit_cost` IS NULL OR `upstream_unit_cost` <= 0)
  AND (`upstream_cost_text` IS NULL OR `upstream_cost_text` = '');
