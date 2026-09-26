-- Retire editable genre quotas only. Preserve original values for rollback/audit.
-- Never rewrite task snapshots, paid receipts, story content or billing rows.
UPDATE `la_aigc_short_drama_config`
SET `config_json` = JSON_REMOVE(
    JSON_SET(`config_json`, '$._retired_storyboard_rules_v1',
        COALESCE(JSON_EXTRACT(`config_json`, '$._retired_storyboard_rules_v1'), JSON_EXTRACT(`config_json`, '$.storyboard_rules'))),
    '$.storyboard_rules')
WHERE JSON_VALID(`config_json`)
  AND JSON_CONTAINS_PATH(`config_json`, 'one', '$.storyboard_rules');
