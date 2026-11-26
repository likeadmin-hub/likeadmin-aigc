CREATE TABLE IF NOT EXISTS `la_aigc_video_replication_job_stage` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `tenant_id` int unsigned NOT NULL, `user_id` int unsigned NOT NULL,
 `root_job_id` int unsigned NOT NULL, `stage` varchar(30) NOT NULL, `child_job_id` int unsigned NOT NULL,
 `create_time` int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (`id`), UNIQUE KEY `uk_stage` (`root_job_id`,`stage`), UNIQUE KEY `uk_child` (`child_job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='视频复刻分阶段任务';
