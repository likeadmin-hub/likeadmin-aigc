UPDATE `la_aigc_llm_channel`
SET `name` = CASE
        WHEN `name` = CONCAT('Xhadmin', ' Qwen3.6-Plus') THEN 'Qwen3.6-Plus 兼容通道'
        ELSE `name`
    END,
    `config_json` = JSON_SET(
        COALESCE(NULLIF(`config_json`, ''), '{}'),
        '$.base_url',
        '',
        '$.remark',
        CASE
            WHEN JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.remark')) LIKE '%Xhadmin%'
                OR JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.remark')) LIKE '%DashScope%'
            THEN 'Qwen3.6-Plus OpenAI compatible'
            ELSE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.remark')), '')
        END
    ),
    `update_time` = UNIX_TIMESTAMP()
WHERE `tenant_id` = 0
  AND `code` = 'dashscope_compatible'
  AND (
      `name` = CONCAT('Xhadmin', ' Qwen3.6-Plus')
      OR JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.base_url')) = CONCAT('https://api.', 'xhadmin.cn')
      OR JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.remark')) LIKE '%Xhadmin%'
      OR JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(`config_json`, ''), '{}'), '$.remark')) LIKE '%DashScope%'
  );
