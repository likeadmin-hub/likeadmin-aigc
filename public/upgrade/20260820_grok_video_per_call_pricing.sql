-- Grok Video is sold as one submitted generation request, not by output second.
UPDATE `la_power_market_sku` AS sku
INNER JOIN `la_power_market_product` AS product ON product.`id` = sku.`product_id`
SET sku.`usage_unit` = 'per_call',
    sku.`usage_unit_size` = 1,
    sku.`update_time` = UNIX_TIMESTAMP()
WHERE product.`resource_type` = 'app_api'
  AND product.`upstream_app_code` = 'grok_video'
  AND sku.`status` = 1;
