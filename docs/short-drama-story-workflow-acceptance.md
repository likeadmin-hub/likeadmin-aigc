# Story setting / episode outline implementation ledger

Scope: new explicit `story_outline_v2` multi-episode projects only. Existing production queue and legacy UI remain. Source only, no release artifacts.

## Implemented in this working session

- Draft overlay in request_json, immutable original result_json, optimistic draft versions, tenant/user/project checks, strict confirmation with permissive incomplete draft saves.
- Editable story and all four episode fields, serialized 800 ms autosave, keep edits on failure, flush before confirmation, browser closing warning, navigation guard.
- Returning to story invalidates the unconfirmed outline; confirmed episode queues lock prior stages.
- Dedicated planning worker, database advisory task locks, attempt fencing at final publication, read-only v2 polling, immutable per-unit provider receipts.
- Input/output budget checks, conservative configurable fallback, range splitting, one-episode repair, exact numbering/required-field checks, no fabricated missing episodes.
- Safe connection-establishment failures wait 30 / 120 seconds (at most two retries). Ambiguous interrupted requests do not blindly replay.
- Fresh install SQL, app migration, upgrade SQL and public upgrade mirror for planning-unit storage.

## Verified so far

- 2026-09-13: 57 focused backend tests, 54,199 assertions, including transactional DB tests. Most assertions are exhaustive range checks, not distinct user scenarios.
- Browser mocked API test: story edit/save/confirm, 30 episode editors, save-error retention, reload, final-edit flush, existing episode/start navigation. Component and plan.vue compile.
- Real project 313, story `sd_plan_202609131820161856`, Qwen3.6-Plus: story succeeded (1,403 input / 1,523 output tokens, 4.0964 points), edited draft retained.
- Real project 313 outline `sd_plan_revision_202609131822189068`: 3 episodes valid; edited first title reached episode records; episodes 7548–7550 all succeeded in existing queue, production projects 314 / 318 / 319.
- Real project 315 outline `sd_plan_revision_202609131826596089`: 10 episodes succeeded after a TLS connection failure and explicit resume.
- Real project 316 outline `sd_plan_revision_202609131826597460`: first 10 saved; one-episode tail exposed repeated-public-fields truncation. Fix now returns only episodes and increases one-episode repair headroom. Retest ongoing.
- Real project 317 outline `sd_plan_revision_202609131826593544`: 30-episode test ongoing; TLS failures and splitting observed. Do not report as passed until terminal validation.

## Remaining verification / known limitations

- New home entry is not yet switched on; enable only after remaining acceptance checks and ensuring planning worker is running.
- Long 50 / 100 outline and cross-boundary production real tests not run yet.
- New browser legacy-path assertion being added. Older broad episodes E2E has stale selectors (checklist absent on completed outlines, textarea replaced by mention editor, old queue labels); not reported passing.
- Exact duplicate detection exists; semantic continuity quality is not equivalent to structural completeness and is not guaranteed.
- Rich long-content production splitting and independent heartbeat/lease orchestration from earlier broader plan are not complete. Existing episode worker remains unchanged.
- Provider transport lacks an upstream idempotency contract for ambiguous submitted requests; do not claim guaranteed exactly-once upstream billing.

## Local commands

`php think short-drama:planning-worker` is required for the new story/outline path. Existing `short-drama:episode-worker` still handles confirmed production.

`SHORT_DRAMA_STORY_DB_TESTS=1 php vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Feature --filter 'ShortDrama(Story|AutoDuration|MultiEpisode|OutlineValidation|ScriptPlanGeneration)'`

Frontend: `PLAYWRIGHT_MODULE=<runtime>/node_modules/playwright node tests/short-drama-story-outline-v2.e2e.mjs` (mocked API, no billing).

Historical project 309 has not been overwritten. Test projects are retained as acceptance evidence.
