ALTER TABLE `la_ai_consumption_log` ADD INDEX `idx_market_health_time` (`create_time`, `product_id`, `run_status`);
