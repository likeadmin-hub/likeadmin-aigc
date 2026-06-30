UPDATE `la_app`
SET `is_builtin` = 0,
    `expire_policy` = 'allow',
    `update_time` = UNIX_TIMESTAMP()
WHERE `code` = 'aigc_action_transfer';

UPDATE `la_app`
SET `is_builtin` = 0,
    `expire_policy` = 'allow',
    `update_time` = UNIX_TIMESTAMP()
WHERE `code` IN (
  'aigc_hairstyle',
  'aigc_fitting',
  'aigc_product_image',
  'aigc_style_transfer',
  'aigc_photo_restore',
  'aigc_model_wear',
  'aigc_background_removal',
  'aigc_image_translate',
  'aigc_one_click_cleanup',
  'aigc_product_suite',
  'aigc_product_multi_angle',
  'aigc_fashion_lookbook',
  'aigc_product_promo_video',
  'aigc_outpaint',
  'aigc_local_redraw',
  'smart_clip',
  'image_human'
);

UPDATE `la_tenant_app`
SET `expire_time` = 4102415999,
    `update_time` = UNIX_TIMESTAMP()
WHERE `app_code` IN (
  'aigc_action_transfer',
  'aigc_hairstyle',
  'aigc_fitting',
  'aigc_product_image',
  'aigc_style_transfer',
  'aigc_photo_restore',
  'aigc_model_wear',
  'aigc_background_removal',
  'aigc_image_translate',
  'aigc_one_click_cleanup',
  'aigc_product_suite',
  'aigc_product_multi_angle',
  'aigc_fashion_lookbook',
  'aigc_product_promo_video',
  'aigc_outpaint',
  'aigc_local_redraw',
  'smart_clip',
  'image_human'
)
  AND `expire_time` = 0;

INSERT INTO `la_app_plan` (`app_code`,`name`,`duration_months`,`open_points`,`renew_points`,`status`,`sort`,`create_time`,`update_time`)
SELECT 'aigc_action_transfer','一年套餐',12,0.00,0.00,1,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (
  SELECT 1 FROM `la_app_plan` WHERE `app_code`='aigc_action_transfer'
);

UPDATE `la_app_plan`
SET `name` = '一年套餐',
    `duration_months` = 12,
    `status` = 1,
    `update_time` = UNIX_TIMESTAMP()
WHERE `app_code` = 'aigc_action_transfer'
  AND `name` IN ('', '1');
