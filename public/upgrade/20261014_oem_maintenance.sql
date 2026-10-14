-- OEM maintenance is lightweight and never provisions tenants in the scheduler.
INSERT INTO `la_dev_crontab` (`name`,`type`,`system`,`remark`,`command`,`params`,`status`,`expression`,`error`,`last_time`,`time`,`max_time`,`create_time`,`update_time`,`delete_time`)
SELECT '贴牌订单核对与提醒',1,1,'付款退款核对、未付款超时关闭、付款24小时后每日人工跟进提醒','brand:maintain','',1,'* * * * *','',0,'0','0',UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),NULL
WHERE NOT EXISTS (SELECT 1 FROM `la_dev_crontab` WHERE `command`='brand:maintain' AND (`delete_time` IS NULL OR `delete_time`=0));
