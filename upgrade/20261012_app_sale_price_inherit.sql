-- Application sale prices: NULL follows market; preserve existing values.
ALTER TABLE `la_aigc_local_redraw_config` MODIFY COLUMN `unit_price` decimal(12,2) NULL DEFAULT NULL COMMENT '用户售价，NULL跟随算力市场，0免费';
ALTER TABLE `la_aigc_one_click_cleanup_config` MODIFY COLUMN `unit_price` decimal(12,2) NULL DEFAULT NULL COMMENT '用户售价，NULL跟随算力市场，0免费';
ALTER TABLE `la_aigc_product_suite_config` MODIFY COLUMN `unit_price` decimal(12,2) NULL DEFAULT NULL COMMENT '用户售价，NULL跟随算力市场，0免费';
ALTER TABLE `la_aigc_product_multi_angle_config` MODIFY COLUMN `unit_price` decimal(12,2) NULL DEFAULT NULL COMMENT '用户售价，NULL跟随算力市场，0免费';
ALTER TABLE `la_aigc_fashion_lookbook_config` MODIFY COLUMN `unit_price` decimal(12,2) NULL DEFAULT NULL COMMENT '用户售价，NULL跟随算力市场，0免费';
