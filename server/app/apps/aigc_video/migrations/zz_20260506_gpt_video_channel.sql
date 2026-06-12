INSERT INTO `la_aigc_video_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'grok_video_xaiq','Grok Video（xAIQ）','xhadmin','grok-video',7,'{"poll_interval":2,"poll_attempts":30,"quantity_options":[1],"quality":"720p"}',1,400,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `la_aigc_video_channel`.`name`=IF(`la_aigc_video_channel`.`name` IS NULL OR `la_aigc_video_channel`.`name` = '', VALUES(`name`), `la_aigc_video_channel`.`name`),`la_aigc_video_channel`.`provider`=IF(`la_aigc_video_channel`.`provider` IS NULL OR `la_aigc_video_channel`.`provider` = '', VALUES(`provider`), `la_aigc_video_channel`.`provider`),`la_aigc_video_channel`.`model`=IF(`la_aigc_video_channel`.`model` IS NULL OR `la_aigc_video_channel`.`model` = '', VALUES(`model`), `la_aigc_video_channel`.`model`),`la_aigc_video_channel`.`max_reference_images`=IF(`la_aigc_video_channel`.`max_reference_images` <= 0, VALUES(`max_reference_images`), `la_aigc_video_channel`.`max_reference_images`),`la_aigc_video_channel`.`config_json`=IF(`la_aigc_video_channel`.`config_json` IS NULL OR `la_aigc_video_channel`.`config_json` = '' OR `la_aigc_video_channel`.`config_json` = '{}', VALUES(`config_json`), `la_aigc_video_channel`.`config_json`),`la_aigc_video_channel`.`status`=`la_aigc_video_channel`.`status`,`la_aigc_video_channel`.`sort`=IF(`la_aigc_video_channel`.`sort` <= 0, VALUES(`sort`), `la_aigc_video_channel`.`sort`),`la_aigc_video_channel`.`update_time`=`la_aigc_video_channel`.`update_time`;

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'grok_video_xaiq','6','6秒','16:9',1280,720,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"16:9"}',1,1000,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','6','6秒','9:16',720,1280,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"9:16"}',1,990,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','6','6秒','1:1',720,720,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"1:1"}',1,980,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','6','6秒','2:3',720,1080,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"2:3"}',1,970,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','6','6秒','3:2',1080,720,0.17,0.17,'{"quality":"720p","duration":6,"aspect_ratio":"3:2"}',1,960,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','16:9',1280,720,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"16:9"}',1,950,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','9:16',720,1280,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"9:16"}',1,940,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','1:1',720,720,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"1:1"}',1,930,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','2:3',720,1080,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"2:3"}',1,920,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','10','10秒','3:2',1080,720,0.28,0.28,'{"quality":"720p","duration":10,"aspect_ratio":"3:2"}',1,910,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','16:9',1280,720,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"16:9"}',1,900,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','9:16',720,1280,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"9:16"}',1,890,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','1:1',720,720,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"1:1"}',1,880,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','2:3',720,1080,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"2:3"}',1,870,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','15','15秒','3:2',1080,720,0.42,0.42,'{"quality":"720p","duration":15,"aspect_ratio":"3:2"}',1,860,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','16:9',1280,720,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"16:9"}',1,850,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','9:16',720,1280,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"9:16"}',1,840,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','1:1',720,720,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"1:1"}',1,830,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','2:3',720,1080,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"2:3"}',1,820,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','20','20秒','3:2',1080,720,0.56,0.56,'{"quality":"720p","duration":20,"aspect_ratio":"3:2"}',1,810,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','16:9',1280,720,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"16:9"}',1,800,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','9:16',720,1280,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"9:16"}',1,790,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','1:1',720,720,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"1:1"}',1,780,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','2:3',720,1080,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"2:3"}',1,770,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','25','25秒','3:2',1080,720,0.70,0.70,'{"quality":"720p","duration":25,"aspect_ratio":"3:2"}',1,760,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','16:9',1280,720,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"16:9"}',1,750,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','9:16',720,1280,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"9:16"}',1,740,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','1:1',720,720,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"1:1"}',1,730,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','2:3',720,1080,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"2:3"}',1,720,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'grok_video_xaiq','30','30秒','3:2',1080,720,0.84,0.84,'{"quality":"720p","duration":30,"aspect_ratio":"3:2"}',1,710,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `la_aigc_video_channel_spec`.`quality_label`=IF(`la_aigc_video_channel_spec`.`quality_label` IS NULL OR `la_aigc_video_channel_spec`.`quality_label` = '', VALUES(`quality_label`), `la_aigc_video_channel_spec`.`quality_label`),`la_aigc_video_channel_spec`.`width`=IF(`la_aigc_video_channel_spec`.`width` <= 0, VALUES(`width`), `la_aigc_video_channel_spec`.`width`),`la_aigc_video_channel_spec`.`height`=IF(`la_aigc_video_channel_spec`.`height` <= 0, VALUES(`height`), `la_aigc_video_channel_spec`.`height`),`la_aigc_video_channel_spec`.`platform_unit_cost`=IF(`la_aigc_video_channel_spec`.`platform_unit_cost` <= 0, VALUES(`platform_unit_cost`), `la_aigc_video_channel_spec`.`platform_unit_cost`),`la_aigc_video_channel_spec`.`tenant_unit_price`=IF(`la_aigc_video_channel_spec`.`tenant_unit_price` <= 0, VALUES(`tenant_unit_price`), `la_aigc_video_channel_spec`.`tenant_unit_price`),`la_aigc_video_channel_spec`.`provider_params_json`=IF(`la_aigc_video_channel_spec`.`provider_params_json` IS NULL OR `la_aigc_video_channel_spec`.`provider_params_json` = '' OR `la_aigc_video_channel_spec`.`provider_params_json` = '{}', VALUES(`provider_params_json`), `la_aigc_video_channel_spec`.`provider_params_json`),`la_aigc_video_channel_spec`.`status`=`la_aigc_video_channel_spec`.`status`,`la_aigc_video_channel_spec`.`sort`=IF(`la_aigc_video_channel_spec`.`sort` <= 0, VALUES(`sort`), `la_aigc_video_channel_spec`.`sort`),`la_aigc_video_channel_spec`.`update_time`=`la_aigc_video_channel_spec`.`update_time`;

UPDATE `la_aigc_video_channel`
SET `status` = `status`, `sort` = `sort`, `update_time` = `update_time`
WHERE `tenant_id` = 0 AND `code` <> 'grok_video_xaiq';

UPDATE `la_aigc_video_channel_spec`
SET `status` = `status`, `sort` = `sort`, `update_time` = `update_time`
WHERE `tenant_id` = 0 AND `channel_code` <> 'grok_video_xaiq';
