-- Preserve existing configuration while supporting the full editable effect catalog.
ALTER TABLE `la_aigc_video_config` MODIFY COLUMN `config_json` mediumtext;
