-- Replace only the previous built-in description; preserve tenant-authored display copy.
UPDATE `la_app_display_config`
SET `description`='基于商品素材创作视频，支持直接描述、商品智能分析、分镜预览与视频生成。', `update_time`=UNIX_TIMESTAMP()
WHERE `app_code`='aigc_product_video'
  AND `description`='基于商品素材创作视频，支持直接描述、智能分镜、参考视频分析和故事板预览。';
