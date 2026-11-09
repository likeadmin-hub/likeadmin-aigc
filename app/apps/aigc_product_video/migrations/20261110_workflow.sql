CREATE TABLE IF NOT EXISTS `la_aigc_product_video_job_stage` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `root_job_id` int unsigned NOT NULL, `stage` varchar(30) NOT NULL, `child_job_id` int unsigned NOT NULL,
 `create_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`), UNIQUE KEY `uk_stage` (`root_job_id`,`stage`), UNIQUE KEY `uk_child` (`child_job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品视频智能分析阶段';
CREATE TABLE IF NOT EXISTS `la_aigc_product_video_product` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `name` varchar(100) NOT NULL, `body_json` longtext NOT NULL, `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
 `delete_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`), KEY `idx_owner` (`tenant_id`,`user_id`,`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品视频商品资料库';
