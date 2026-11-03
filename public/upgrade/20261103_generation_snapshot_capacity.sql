-- Reviewed snapshot capacity intents. SqlMigrationExecutor preserves live column semantics.
-- Missing optional app tables are skipped; existing larger types are never narrowed.
ALTER TABLE `la_aigc_video_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT;
ALTER TABLE `la_aigc_digital_human_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT;
ALTER TABLE `la_aigc_product_promo_video_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT;
ALTER TABLE `la_aigc_action_transfer_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT;
ALTER TABLE `la_aigc_person_replacement_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT;
ALTER TABLE `la_aigc_short_drama_script_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT;
ALTER TABLE `la_aigc_short_drama_generation_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT;
ALTER TABLE `la_aigc_music_cover_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT;
ALTER TABLE `la_aigc_music_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT;
ALTER TABLE `la_aigc_watermark_removal_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT;
ALTER TABLE `la_aigc_pic_lipsync_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT;
ALTER TABLE `la_image_human_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT;
ALTER TABLE `la_ai_consumption_log` MODIFY COLUMN `price_snapshot` MEDIUMTEXT;
