-- Reviewed snapshot capacity intents. SqlMigrationExecutor preserves live column semantics.
-- Missing optional app tables are skipped; existing larger types are never narrowed.
ALTER TABLE `la_aigc_watermark_removal_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT;
