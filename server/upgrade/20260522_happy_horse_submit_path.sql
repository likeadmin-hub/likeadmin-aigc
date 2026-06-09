UPDATE `la_aigc_video_channel`
SET `config_json` = JSON_INSERT(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.submit_path',
        '/api/v1/apps/happy_horse/submit'
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `code` = 'happy_horse';
