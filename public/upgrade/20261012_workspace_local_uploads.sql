-- Explicit provenance: user ownership alone does not imply a local upload.
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_tenant_file' AND COLUMN_NAME='upload_origin')=0,
 'ALTER TABLE `la_tenant_file` ADD COLUMN `upload_origin` varchar(20) NOT NULL DEFAULT '''' COMMENT ''local=用户本地上传，空=历史或自动保存''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- These directories were written by the multipart UploadService, not generation persistence.
UPDATE `la_tenant_file` SET `upload_origin`='local' WHERE `upload_origin`='' AND `uri` REGEXP '^/?uploads/(images|video|file|appearance|feedback)/[0-9]{8}/';
-- Music and digital-human audio share a directory with generated audio: require upload evidence.
SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='la_aigc_music_asset')>0,
 'UPDATE `la_tenant_file` f INNER JOIN `la_aigc_music_asset` a ON a.tenant_id=f.tenant_id AND a.user_id=f.source_id AND CAST(a.uri AS BINARY)=CAST(f.uri AS BINARY) SET f.upload_origin=''local'' WHERE f.upload_origin='''' AND f.source=1 AND a.source_action=''upload_audio''', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
