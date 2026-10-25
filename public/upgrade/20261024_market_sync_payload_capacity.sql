-- Model documentation/capability snapshots can exceed TEXT's 65,535-byte limit.
-- Widen losslessly for existing installations; repeat execution is safe.
ALTER TABLE `la_power_market_product`
    MODIFY COLUMN `source_payload` LONGTEXT NULL COMMENT '上游同步快照';
ALTER TABLE `la_power_market_sku`
    MODIFY COLUMN `source_payload` LONGTEXT NULL COMMENT '上游SKU快照';
