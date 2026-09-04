INSERT INTO `la_app_plan` (`app_code`,`name`,`duration_months`,`open_points`,`renew_points`,`status`,`sort`,`create_time`,`update_time`)
SELECT 'aigc_music_cover','一年套餐',12,0.00,0.00,1,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `la_app_plan` WHERE `app_code` = 'aigc_music_cover');
