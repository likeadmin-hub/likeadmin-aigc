ALTER TABLE `la_aigc_short_drama_skill`
  ADD COLUMN `home_recommended` tinyint unsigned NOT NULL DEFAULT 0 AFTER `status`,
  ADD KEY `idx_tenant_home_recommended` (`tenant_id`,`home_recommended`,`status`,`release_status`,`delete_time`,`sort`);
