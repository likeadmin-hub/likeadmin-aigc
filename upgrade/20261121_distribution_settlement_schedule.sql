-- Install the default distribution settlement schedule without overriding operator settings.
INSERT INTO `la_dev_crontab` (`name`,`type`,`system`,`remark`,`command`,`params`,`status`,`expression`,`error`,`last_time`,`time`,`max_time`,`create_time`,`update_time`,`delete_time`)
SELECT '分销佣金结算',1,1,'结算已到期的分销佣金','distribution:settle','',1,'* * * * *','',0,'0','0',UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),NULL
WHERE NOT EXISTS (SELECT 1 FROM `la_dev_crontab` WHERE `command`='distribution:settle' AND (`delete_time` IS NULL OR `delete_time`=0));
