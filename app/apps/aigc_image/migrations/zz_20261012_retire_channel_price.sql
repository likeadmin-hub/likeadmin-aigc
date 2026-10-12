-- Retire the legacy tenant image-price menu; keep historical price data.
UPDATE `la_tenant_system_menu` SET `is_show`=0, `is_disable`=1 WHERE `app_code`='aigc_image' AND `source`='app' AND `source_menu_key`='aigc_image_channel_price';
