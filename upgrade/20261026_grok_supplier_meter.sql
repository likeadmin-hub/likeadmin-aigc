-- Restore only a supplier-declared unit that was masked by the old Grok compatibility rule.
-- No unit prices, retail rules, balances or historical task snapshots are rewritten.
UPDATE `la_power_market_sku` AS sku
JOIN `la_power_market_product` AS product ON product.`id` = sku.`product_id`
SET sku.`usage_unit` = JSON_UNQUOTE(JSON_EXTRACT(sku.`source_payload`, '$.usage_unit')),
    sku.`usage_unit_size` = GREATEST(1, COALESCE(JSON_EXTRACT(sku.`source_payload`, '$.usage_unit_size'), 1)),
    sku.`update_time` = UNIX_TIMESTAMP()
WHERE product.`resource_type` = 'app_api'
  AND product.`upstream_app_code` = 'grok_video'
  AND JSON_VALID(sku.`source_payload`)
  AND JSON_UNQUOTE(JSON_EXTRACT(sku.`source_payload`, '$.usage_unit')) IN ('per_call', 'output_second')
  AND sku.`usage_unit` <> JSON_UNQUOTE(JSON_EXTRACT(sku.`source_payload`, '$.usage_unit'));
