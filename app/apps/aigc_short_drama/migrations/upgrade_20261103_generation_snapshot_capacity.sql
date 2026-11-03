-- Reviewed snapshot capacity intents. SqlMigrationExecutor preserves live column semantics.
-- Missing optional app tables are skipped; existing larger types are never narrowed.
ALTER TABLE `la_aigc_short_drama_script_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT;
ALTER TABLE `la_aigc_short_drama_generation_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT;
