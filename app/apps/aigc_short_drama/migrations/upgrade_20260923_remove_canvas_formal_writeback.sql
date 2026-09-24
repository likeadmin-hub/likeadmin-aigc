-- Retire only the nine user-facing formal-project bridge routes. Preserve
-- historical canvas-binding rows; no user project, media task or ledger data
-- is deleted by this migration.
DELETE FROM `la_app_api`
WHERE `app_code` = 'aigc_short_drama'
  AND `scene` = 'user'
  AND `api_path` IN (
    'app.aigc_short_drama.canvas/binding',
    'app.aigc_short_drama.canvas/bind',
    'app.aigc_short_drama.canvas/writebackSources',
    'app.aigc_short_drama.canvas/previewStoryWriteback',
    'app.aigc_short_drama.canvas/applyStoryWriteback',
    'app.aigc_short_drama.canvas/previewEpisodeWriteback',
    'app.aigc_short_drama.canvas/applyEpisodeWriteback',
    'app.aigc_short_drama.canvas/previewShotWriteback',
    'app.aigc_short_drama.canvas/applyShotWriteback'
  );
