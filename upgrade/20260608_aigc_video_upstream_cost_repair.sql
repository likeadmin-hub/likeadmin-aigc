UPDATE `la_aigc_video_channel_spec`
SET `upstream_unit_cost` = CASE CAST(`quality` AS UNSIGNED)
        WHEN 6 THEN 30.00
        WHEN 10 THEN 50.00
        WHEN 15 THEN 75.00
        WHEN 20 THEN 100.00
        WHEN 25 THEN 125.00
        WHEN 30 THEN 150.00
        ELSE `upstream_unit_cost`
    END,
    `upstream_cost_text` = CASE CAST(`quality` AS UNSIGNED)
        WHEN 6 THEN 'Grok Video 上游 720p 6秒固定价 / 次'
        WHEN 10 THEN 'Grok Video 上游 720p 10秒固定价 / 次'
        WHEN 15 THEN 'Grok Video 上游 720p 15秒固定价 / 次'
        WHEN 20 THEN 'Grok Video 上游 720p 20秒固定价 / 次'
        WHEN 25 THEN 'Grok Video 上游 720p 25秒固定价 / 次'
        WHEN 30 THEN 'Grok Video 上游 720p 30秒固定价 / 次'
        ELSE `upstream_cost_text`
    END,
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `channel_code` = 'grok_video_xaiq'
  AND CAST(`quality` AS UNSIGNED) IN (6, 10, 15, 20, 25, 30)
  AND (`upstream_unit_cost` IS NULL OR `upstream_unit_cost` <= 0);

UPDATE `la_aigc_video_channel_spec`
SET `upstream_cost_text` = '请在规格价格页点击查询上游价格后同步',
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND (`upstream_unit_cost` IS NULL OR `upstream_unit_cost` <= 0)
  AND (`upstream_cost_text` IS NULL OR `upstream_cost_text` = '');
