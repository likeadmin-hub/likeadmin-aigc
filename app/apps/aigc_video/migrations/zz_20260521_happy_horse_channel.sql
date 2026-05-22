INSERT INTO `la_aigc_video_channel` (`tenant_id`,`code`,`name`,`provider`,`model`,`max_reference_images`,`config_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'happy_horse','Happy Horse','happyhorse','happyhorse-1.0-t2v',9,'{"poll_interval":2,"poll_attempts":0,"quantity_options":[1],"resolution":"720P"}',1,300,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`provider`=VALUES(`provider`),`model`=VALUES(`model`),`max_reference_images`=VALUES(`max_reference_images`),`config_json`=VALUES(`config_json`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);

INSERT INTO `la_aigc_video_channel_spec` (`tenant_id`,`channel_code`,`quality`,`quality_label`,`ratio`,`width`,`height`,`platform_unit_cost`,`tenant_unit_price`,`provider_params_json`,`status`,`sort`,`create_time`,`update_time`)
VALUES
(0,'happy_horse','720p_3','720P · 3秒','16:9',1280,720,0.08,0.08,'{"resolution":"720P","duration":3,"ratio":"16:9"}',1,1200,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_3','720P · 3秒','9:16',720,1280,0.08,0.08,'{"resolution":"720P","duration":3,"ratio":"9:16"}',1,1190,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_3','720P · 3秒','1:1',720,720,0.08,0.08,'{"resolution":"720P","duration":3,"ratio":"1:1"}',1,1180,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_3','720P · 3秒','4:3',960,720,0.08,0.08,'{"resolution":"720P","duration":3,"ratio":"4:3"}',1,1170,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_3','720P · 3秒','3:4',720,960,0.08,0.08,'{"resolution":"720P","duration":3,"ratio":"3:4"}',1,1160,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_5','720P · 5秒','16:9',1280,720,0.14,0.14,'{"resolution":"720P","duration":5,"ratio":"16:9"}',1,1150,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_5','720P · 5秒','9:16',720,1280,0.14,0.14,'{"resolution":"720P","duration":5,"ratio":"9:16"}',1,1140,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_5','720P · 5秒','1:1',720,720,0.14,0.14,'{"resolution":"720P","duration":5,"ratio":"1:1"}',1,1130,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_5','720P · 5秒','4:3',960,720,0.14,0.14,'{"resolution":"720P","duration":5,"ratio":"4:3"}',1,1120,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_5','720P · 5秒','3:4',720,960,0.14,0.14,'{"resolution":"720P","duration":5,"ratio":"3:4"}',1,1110,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_10','720P · 10秒','16:9',1280,720,0.28,0.28,'{"resolution":"720P","duration":10,"ratio":"16:9"}',1,1100,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_10','720P · 10秒','9:16',720,1280,0.28,0.28,'{"resolution":"720P","duration":10,"ratio":"9:16"}',1,1090,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_10','720P · 10秒','1:1',720,720,0.28,0.28,'{"resolution":"720P","duration":10,"ratio":"1:1"}',1,1080,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_10','720P · 10秒','4:3',960,720,0.28,0.28,'{"resolution":"720P","duration":10,"ratio":"4:3"}',1,1070,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_10','720P · 10秒','3:4',720,960,0.28,0.28,'{"resolution":"720P","duration":10,"ratio":"3:4"}',1,1060,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_15','720P · 15秒','16:9',1280,720,0.42,0.42,'{"resolution":"720P","duration":15,"ratio":"16:9"}',1,1050,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_15','720P · 15秒','9:16',720,1280,0.42,0.42,'{"resolution":"720P","duration":15,"ratio":"9:16"}',1,1040,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_15','720P · 15秒','1:1',720,720,0.42,0.42,'{"resolution":"720P","duration":15,"ratio":"1:1"}',1,1030,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_15','720P · 15秒','4:3',960,720,0.42,0.42,'{"resolution":"720P","duration":15,"ratio":"4:3"}',1,1020,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','720p_15','720P · 15秒','3:4',720,960,0.42,0.42,'{"resolution":"720P","duration":15,"ratio":"3:4"}',1,1010,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_3','1080P · 3秒','16:9',1920,1080,0.17,0.17,'{"resolution":"1080P","duration":3,"ratio":"16:9"}',1,1000,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_3','1080P · 3秒','9:16',1080,1920,0.17,0.17,'{"resolution":"1080P","duration":3,"ratio":"9:16"}',1,990,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_3','1080P · 3秒','1:1',1080,1080,0.17,0.17,'{"resolution":"1080P","duration":3,"ratio":"1:1"}',1,980,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_3','1080P · 3秒','4:3',1440,1080,0.17,0.17,'{"resolution":"1080P","duration":3,"ratio":"4:3"}',1,970,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_3','1080P · 3秒','3:4',1080,1440,0.17,0.17,'{"resolution":"1080P","duration":3,"ratio":"3:4"}',1,960,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_5','1080P · 5秒','16:9',1920,1080,0.28,0.28,'{"resolution":"1080P","duration":5,"ratio":"16:9"}',1,950,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_5','1080P · 5秒','9:16',1080,1920,0.28,0.28,'{"resolution":"1080P","duration":5,"ratio":"9:16"}',1,940,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_5','1080P · 5秒','1:1',1080,1080,0.28,0.28,'{"resolution":"1080P","duration":5,"ratio":"1:1"}',1,930,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_5','1080P · 5秒','4:3',1440,1080,0.28,0.28,'{"resolution":"1080P","duration":5,"ratio":"4:3"}',1,920,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_5','1080P · 5秒','3:4',1080,1440,0.28,0.28,'{"resolution":"1080P","duration":5,"ratio":"3:4"}',1,910,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_10','1080P · 10秒','16:9',1920,1080,0.56,0.56,'{"resolution":"1080P","duration":10,"ratio":"16:9"}',1,900,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_10','1080P · 10秒','9:16',1080,1920,0.56,0.56,'{"resolution":"1080P","duration":10,"ratio":"9:16"}',1,890,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_10','1080P · 10秒','1:1',1080,1080,0.56,0.56,'{"resolution":"1080P","duration":10,"ratio":"1:1"}',1,880,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_10','1080P · 10秒','4:3',1440,1080,0.56,0.56,'{"resolution":"1080P","duration":10,"ratio":"4:3"}',1,870,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_10','1080P · 10秒','3:4',1080,1440,0.56,0.56,'{"resolution":"1080P","duration":10,"ratio":"3:4"}',1,860,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_15','1080P · 15秒','16:9',1920,1080,0.84,0.84,'{"resolution":"1080P","duration":15,"ratio":"16:9"}',1,850,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_15','1080P · 15秒','9:16',1080,1920,0.84,0.84,'{"resolution":"1080P","duration":15,"ratio":"9:16"}',1,840,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_15','1080P · 15秒','1:1',1080,1080,0.84,0.84,'{"resolution":"1080P","duration":15,"ratio":"1:1"}',1,830,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_15','1080P · 15秒','4:3',1440,1080,0.84,0.84,'{"resolution":"1080P","duration":15,"ratio":"4:3"}',1,820,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'happy_horse','1080p_15','1080P · 15秒','3:4',1080,1440,0.84,0.84,'{"resolution":"1080P","duration":15,"ratio":"3:4"}',1,810,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `quality_label`=VALUES(`quality_label`),`width`=VALUES(`width`),`height`=VALUES(`height`),`platform_unit_cost`=VALUES(`platform_unit_cost`),`tenant_unit_price`=VALUES(`tenant_unit_price`),`provider_params_json`=VALUES(`provider_params_json`),`status`=VALUES(`status`),`sort`=VALUES(`sort`),`update_time`=VALUES(`update_time`);
