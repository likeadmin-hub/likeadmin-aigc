SET NAMES utf8mb4;

UPDATE `la_aigc_short_drama_storyboard`
SET `shot_type` = '普通画面',
    `update_time` = UNIX_TIMESTAMP()
WHERE `shot_type` = '普通画';

UPDATE `la_aigc_short_drama_storyboard`
SET `video_prompt` = REPLACE(`video_prompt`, CONCAT('景别：普通画', CHAR(13), CHAR(10)), CONCAT('景别：普通画面', CHAR(13), CHAR(10))),
    `update_time` = UNIX_TIMESTAMP()
WHERE `video_prompt` LIKE CONCAT('%', '景别：普通画', CHAR(13), CHAR(10), '%');

UPDATE `la_aigc_short_drama_storyboard`
SET `video_prompt` = REPLACE(`video_prompt`, CONCAT('景别：普通画', CHAR(10)), CONCAT('景别：普通画面', CHAR(10))),
    `update_time` = UNIX_TIMESTAMP()
WHERE `video_prompt` LIKE CONCAT('%', '景别：普通画', CHAR(10), '%');

UPDATE `la_aigc_short_drama_storyboard`
SET `video_prompt` = REPLACE(`video_prompt`, CONCAT('景别普通画', CHAR(13), CHAR(10)), CONCAT('景别：普通画面', CHAR(13), CHAR(10))),
    `update_time` = UNIX_TIMESTAMP()
WHERE `video_prompt` LIKE CONCAT('%', '景别普通画', CHAR(13), CHAR(10), '%');

UPDATE `la_aigc_short_drama_storyboard`
SET `video_prompt` = REPLACE(`video_prompt`, CONCAT('景别普通画', CHAR(10)), CONCAT('景别：普通画面', CHAR(10))),
    `update_time` = UNIX_TIMESTAMP()
WHERE `video_prompt` LIKE CONCAT('%', '景别普通画', CHAR(10), '%');

UPDATE `la_aigc_short_drama_storyboard`
SET `image_prompt` = REPLACE(`image_prompt`, '�', ''),
    `video_prompt` = REPLACE(`video_prompt`, '�', ''),
    `visual_description` = REPLACE(`visual_description`, '�', ''),
    `composition` = REPLACE(`composition`, '�', ''),
    `camera_movement` = REPLACE(`camera_movement`, '�', ''),
    `update_time` = UNIX_TIMESTAMP()
WHERE CONCAT_WS('', `image_prompt`, `video_prompt`, `visual_description`, `composition`, `camera_movement`) LIKE '%�%';

UPDATE `la_aigc_short_drama_generation_task`
SET `request_json` = REPLACE(REPLACE(REPLACE(REPLACE(`request_json`, '景别：普通画\n', '景别：普通画面\n'), CONCAT('景别：普通画', CHAR(10)), CONCAT('景别：普通画面', CHAR(10))), '景别普通画\n', '景别：普通画面\n'), CONCAT('景别普通画', CHAR(10)), CONCAT('景别：普通画面', CHAR(10))),
    `result_json` = REPLACE(REPLACE(REPLACE(REPLACE(`result_json`, '景别：普通画\n', '景别：普通画面\n'), CONCAT('景别：普通画', CHAR(10)), CONCAT('景别：普通画面', CHAR(10))), '景别普通画\n', '景别：普通画面\n'), CONCAT('景别普通画', CHAR(10)), CONCAT('景别：普通画面', CHAR(10))),
    `model_json` = REPLACE(REPLACE(REPLACE(REPLACE(`model_json`, '景别：普通画\n', '景别：普通画面\n'), CONCAT('景别：普通画', CHAR(10)), CONCAT('景别：普通画面', CHAR(10))), '景别普通画\n', '景别：普通画面\n'), CONCAT('景别普通画', CHAR(10)), CONCAT('景别：普通画面', CHAR(10))),
    `update_time` = UNIX_TIMESTAMP()
WHERE CONCAT_WS('', `request_json`, `result_json`, `model_json`) LIKE '%景别：普通画%'
   OR CONCAT_WS('', `request_json`, `result_json`, `model_json`) LIKE '%景别普通画%';

UPDATE `la_aigc_short_drama_generation_task`
SET `request_json` = REPLACE(REPLACE(`request_json`, '景别：普通画\\n', '景别：普通画面\\n'), '景别普通画\\n', '景别：普通画面\\n'),
    `result_json` = REPLACE(REPLACE(`result_json`, '景别：普通画\\n', '景别：普通画面\\n'), '景别普通画\\n', '景别：普通画面\\n'),
    `model_json` = REPLACE(REPLACE(`model_json`, '景别：普通画\\n', '景别：普通画面\\n'), '景别普通画\\n', '景别：普通画面\\n'),
    `update_time` = UNIX_TIMESTAMP()
WHERE CONCAT_WS('', `request_json`, `result_json`, `model_json`) LIKE '%景别：普通画%'
   OR CONCAT_WS('', `request_json`, `result_json`, `model_json`) LIKE '%景别普通画%';

UPDATE `la_aigc_short_drama_generation_task`
SET `request_json` = REPLACE(`request_json`, '"shot_type":"普通画"', '"shot_type":"普通画面"'),
    `result_json` = REPLACE(`result_json`, '"shot_type":"普通画"', '"shot_type":"普通画面"'),
    `model_json` = REPLACE(`model_json`, '"shot_type":"普通画"', '"shot_type":"普通画面"'),
    `update_time` = UNIX_TIMESTAMP()
WHERE CONCAT_WS('', `request_json`, `result_json`, `model_json`) LIKE '%"shot_type":"普通画"%';

UPDATE `la_aigc_short_drama_generation_task`
SET `request_json` = REPLACE(`request_json`, '�', ''),
    `result_json` = REPLACE(`result_json`, '�', ''),
    `model_json` = REPLACE(`model_json`, '�', ''),
    `error_msg` = REPLACE(`error_msg`, '�', ''),
    `operator_error` = REPLACE(`operator_error`, '�', ''),
    `update_time` = UNIX_TIMESTAMP()
WHERE CONCAT_WS('', `request_json`, `result_json`, `model_json`, `error_msg`, `operator_error`) LIKE '%�%';

UPDATE `la_aigc_short_drama_agent_run`
SET `input_summary` = REPLACE(`input_summary`, '�', ''),
    `request_json` = REPLACE(`request_json`, '�', ''),
    `output_summary` = REPLACE(`output_summary`, '�', ''),
    `model_json` = REPLACE(`model_json`, '�', ''),
    `error_msg` = REPLACE(`error_msg`, '�', ''),
    `update_time` = UNIX_TIMESTAMP()
WHERE CONCAT_WS('', `input_summary`, `request_json`, `output_summary`, `model_json`, `error_msg`) LIKE '%�%';

UPDATE `la_aigc_short_drama_agent_step_log`
SET `input_json` = REPLACE(`input_json`, '�', ''),
    `output_json` = REPLACE(`output_json`, '�', ''),
    `error_msg` = REPLACE(`error_msg`, '�', ''),
    `update_time` = UNIX_TIMESTAMP()
WHERE CONCAT_WS('', `input_json`, `output_json`, `error_msg`) LIKE '%�%';

UPDATE `la_aigc_short_drama_asset`
SET `title` = REPLACE(`title`, '�', ''),
    `meta_json` = REPLACE(`meta_json`, '�', ''),
    `update_time` = UNIX_TIMESTAMP()
WHERE CONCAT_WS('', `title`, `meta_json`) LIKE '%�%';

UPDATE `la_aigc_short_drama_plan_version`
SET `story_bible_json` = REPLACE(`story_bible_json`, '�', ''),
    `continuity_json` = REPLACE(`continuity_json`, '�', ''),
    `plan_json` = REPLACE(`plan_json`, '�', ''),
    `storyboard_json` = REPLACE(`storyboard_json`, '�', ''),
    `update_time` = UNIX_TIMESTAMP()
WHERE CONCAT_WS('', `story_bible_json`, `continuity_json`, `plan_json`, `storyboard_json`) LIKE '%�%';

UPDATE `la_aigc_short_drama_plan_version`
SET `plan_json` = REPLACE(REPLACE(REPLACE(REPLACE(`plan_json`, '景别普通画\\n', '景别：普通画面\\n'), CONCAT('景别普通画', CHAR(10)), CONCAT('景别：普通画面', CHAR(10))), '景别：普通画\\n', '景别：普通画面\\n'), CONCAT('景别：普通画', CHAR(10)), CONCAT('景别：普通画面', CHAR(10))),
    `storyboard_json` = REPLACE(REPLACE(REPLACE(REPLACE(`storyboard_json`, '景别普通画\\n', '景别：普通画面\\n'), CONCAT('景别普通画', CHAR(10)), CONCAT('景别：普通画面', CHAR(10))), '景别：普通画\\n', '景别：普通画面\\n'), CONCAT('景别：普通画', CHAR(10)), CONCAT('景别：普通画面', CHAR(10))),
    `update_time` = UNIX_TIMESTAMP()
WHERE CONCAT_WS('', `plan_json`, `storyboard_json`) LIKE '%景别普通画%'
   OR CONCAT_WS('', `plan_json`, `storyboard_json`) LIKE '%景别：普通画%';

UPDATE `la_aigc_short_drama_script_task`
SET `request_json` = REPLACE(`request_json`, '�', ''),
    `result_json` = REPLACE(`result_json`, '�', ''),
    `error` = REPLACE(`error`, '�', ''),
    `operator_error` = REPLACE(`operator_error`, '�', ''),
    `update_time` = UNIX_TIMESTAMP()
WHERE CONCAT_WS('', `request_json`, `result_json`, `error`, `operator_error`) LIKE '%�%';

UPDATE `la_aigc_short_drama_script_task`
SET `result_json` = REPLACE(REPLACE(REPLACE(REPLACE(`result_json`, '景别普通画\\n', '景别：普通画面\\n'), CONCAT('景别普通画', CHAR(10)), CONCAT('景别：普通画面', CHAR(10))), '景别：普通画\\n', '景别：普通画面\\n'), CONCAT('景别：普通画', CHAR(10)), CONCAT('景别：普通画面', CHAR(10))),
    `update_time` = UNIX_TIMESTAMP()
WHERE `result_json` LIKE '%景别普通画%'
   OR `result_json` LIKE '%景别：普通画%';

UPDATE `la_aigc_short_drama_storyboard`
SET `action` = REPLACE(`action`, '�', ''),
    `result` = REPLACE(`result`, '�', ''),
    `atmosphere` = REPLACE(`atmosphere`, '�', ''),
    `update_time` = UNIX_TIMESTAMP()
WHERE CONCAT_WS('', `action`, `result`, `atmosphere`) LIKE '%�%';
