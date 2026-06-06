SET @aigc_image_channel_spec_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_image_channel_spec');
SET @aigc_image_channel_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_aigc_image_channel');

SET @aigc_image_mock_sql = IF(@aigc_image_channel_spec_exists > 0 AND @aigc_image_channel_exists > 0,
  'DELETE s FROM `la_aigc_image_channel_spec` s WHERE s.`channel_code` IN (''master'',''all'',''free'') AND (EXISTS (SELECT 1 FROM `la_aigc_image_channel` c WHERE c.`tenant_id` = s.`tenant_id` AND c.`code` = s.`channel_code` AND (c.`provider` = ''mock'' OR c.`model` = ''mock-image'')) OR EXISTS (SELECT 1 FROM `la_aigc_image_channel` c WHERE c.`tenant_id` = 0 AND c.`code` = s.`channel_code` AND (c.`provider` = ''mock'' OR c.`model` = ''mock-image'')))',
  'SELECT 1'
);
PREPARE aigc_image_mock_stmt FROM @aigc_image_mock_sql;
EXECUTE aigc_image_mock_stmt;
DEALLOCATE PREPARE aigc_image_mock_stmt;

SET @aigc_image_mock_sql = IF(@aigc_image_channel_exists > 0,
  'DELETE FROM `la_aigc_image_channel` WHERE `code` IN (''master'',''all'',''free'') AND (`provider` = ''mock'' OR `model` = ''mock-image'')',
  'SELECT 1'
);
PREPARE aigc_image_mock_stmt FROM @aigc_image_mock_sql;
EXECUTE aigc_image_mock_stmt;
DEALLOCATE PREPARE aigc_image_mock_stmt;
