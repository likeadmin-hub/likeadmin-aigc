UPDATE `la_aigc_digital_human_channel`
SET `config_json` = JSON_INSERT(
    CASE
        WHEN JSON_VALID(COALESCE(NULLIF(`config_json`, ''), '{}'))
          AND JSON_TYPE(IF(JSON_VALID(COALESCE(NULLIF(`config_json`, ''), '{}')), COALESCE(NULLIF(`config_json`, ''), '{}'), '{}')) = 'OBJECT'
        THEN COALESCE(NULLIF(`config_json`, ''), '{}')
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
    OR JSON_TYPE(IF(JSON_VALID(COALESCE(NULLIF(`config_json`, ''), '{}')), COALESCE(NULLIF(`config_json`, ''), '{}'), '{}')) <> 'OBJECT'
    OR JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(COALESCE(NULLIF(`config_json`, ''), '{}')), COALESCE(NULLIF(`config_json`, ''), '{}'), '{}'), '$.description')) IS NULL
    OR JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(COALESCE(NULLIF(`config_json`, ''), '{}')), COALESCE(NULLIF(`config_json`, ''), '{}'), '{}'), '$.description')) = ''
  );
