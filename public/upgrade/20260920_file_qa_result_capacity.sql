-- Full multi-episode parse results exceed TEXT's 64 KiB capacity.
-- Reapplying this widening migration preserves existing data.
ALTER TABLE `la_ai_consumption_log` MODIFY COLUMN `response_summary` LONGTEXT NULL;
