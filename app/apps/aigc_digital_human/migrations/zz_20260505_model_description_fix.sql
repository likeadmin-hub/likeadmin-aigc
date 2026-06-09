UPDATE `la_aigc_digital_human_channel`
SET `config_json` = JSON_INSERT(
    CASE
        WHEN JSON_VALID(COALESCE(NULLIF(`config_json`, ''), '{}'))
          AND JSON_TYPE(CAST(COALESCE(NULLIF(`config_json`, ''), '{}') AS JSON)) = 'OBJECT'
        THEN CAST(COALESCE(NULLIF(`config_json`, ''), '{}') AS JSON)
        ELSE JSON_OBJECT()
    END,
    '$.description',
    CASE `code`
        WHEN 'master' THEN '高质量数字人视频模型，适合正式口播和营销内容'
        WHEN 'all' THEN '通用数字人视频模型，适合产品介绍和知识讲解'
        WHEN 'free' THEN '轻量体验模型，适合快速试用和短文案预览'
        ELSE '标准数字人视频模型'
    END
)
WHERE `tenant_id` = 0
  AND (
    NOT JSON_VALID(COALESCE(NULLIF(`config_json`, ''), '{}'))
    OR JSON_TYPE(CAST(COALESCE(NULLIF(`config_json`, ''), '{}') AS JSON)) <> 'OBJECT'
    OR JSON_UNQUOTE(JSON_EXTRACT(CAST(COALESCE(NULLIF(`config_json`, ''), '{}') AS JSON), '$.description')) IS NULL
    OR JSON_UNQUOTE(JSON_EXTRACT(CAST(COALESCE(NULLIF(`config_json`, ''), '{}') AS JSON), '$.description')) = ''
  );
