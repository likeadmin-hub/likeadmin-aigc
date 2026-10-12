-- Preserve existing explicit prices, including zero.
ALTER TABLE `la_aigc_product_multi_angle_config` MODIFY COLUMN `unit_price` decimal(12,2) NULL DEFAULT NULL COMMENT '用户售价，NULL跟随算力市场，0免费';
