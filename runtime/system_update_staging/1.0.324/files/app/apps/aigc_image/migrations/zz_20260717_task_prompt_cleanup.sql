SET NAMES utf8mb4;

UPDATE `la_aigc_image_task`
SET `prompt` = REPLACE(`prompt`, '�', ''),
    `negative_prompt` = REPLACE(`negative_prompt`, '�', ''),
    `reference_images` = REPLACE(`reference_images`, '�', ''),
    `provider_params_json` = REPLACE(`provider_params_json`, '�', ''),
    `error` = REPLACE(`error`, '�', ''),
    `update_time` = UNIX_TIMESTAMP()
WHERE CONCAT_WS('', `prompt`, `negative_prompt`, `reference_images`, `provider_params_json`, `error`) LIKE '%�%';
