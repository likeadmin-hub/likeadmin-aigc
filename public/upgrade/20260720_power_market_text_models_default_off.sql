-- Text model catalogues are opt-in: platform operators choose which SKUs are sellable.
UPDATE `la_power_market_sku` AS `sku`
INNER JOIN `la_power_market_product` AS `product` ON `product`.`id` = `sku`.`product_id`
SET `sku`.`sale_status` = 0,
    `sku`.`update_time` = UNIX_TIMESTAMP()
WHERE `product`.`source_code` = 'likeadmin_api'
  AND `product`.`resource_type` = 'model'
  AND `product`.`model_type` = 'text';
