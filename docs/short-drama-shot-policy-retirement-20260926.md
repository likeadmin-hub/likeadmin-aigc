# Short-drama genre quota retirement — local validation

Date: 2026-09-26. Branch: `feature/short-drama-enhancement` in both source repositories.

## Scope and contracts

- Backend: retire genre/default keyword tables, min/max count validation and quota-driven expansion; remove the obsolete repair prompt entry. New script tasks carry `_shot_policy_version=2`. Empty legacy rule fields remain readable for API compatibility but cannot reactivate rules.
- Tenant frontend: remove the entire pacing reference table and its save/default-reset logic. Keep episode duration and per-shot duration controls.
- PC frontend, separate first commit `1361a6b`: progressive preview follows form section order, storyboard follows chronological order, complete repairs replace obsolete previews, Unicode typing remains safe.
- Canvas creative prompt shares the no-genre-quota instruction. Frozen custom creative instructions and historical task records are not rewritten.
- No changes to route/permission/menu, charging, provider adapters, app lifecycle, or tenant identity contracts. Historical paid request signatures remain unchanged.
- Legacy receipts are read only within the same tenant/user/task and unchanged creative inputs, then passed to their owning stage for normal decoding, bounded repair, normalization, quality and continuity checks. This covers local patches, skeletons and individual scene chunks as well as complete scripts. Non-received ambiguous units cannot fall through into a new paid submission and require checking the prior request.

## Verified

- Focused PHP suite: 157 tests / 5,659 assertions passed, including dialogue, timing, continuity, input isolation, prompt documents, receipt persistence and unsafe legacy receipt fences.
- PC preview tests: 13 passed. Three modified Vue SFCs (tenant configuration, plan page, streaming preview) compiled successfully using the local Vue compiler.
- Real saved response for `sd_plan_202609261309343195` / episode 19767: decoded, normalized and quality-reviewed read-only. All 9 original shots retained; 0 issues / 0 blocking issues. This was NOT a paid regeneration or a full continuity-generation run, and the stored failed status was not changed.
- App configuration migration tested in a transaction: first application affected 1 row, second affected 0; all unrelated fields preserved; rollback matched the original values. Then applied locally: 1 row, second application 0; active old rule keys 0; recoverable backups 1.
- Live tenant config page loads without the retired table; duration controls remain. No form save, task retry or model invocation was triggered during verification.
- Runtime activation performed only after observing 0 running jobs and 0 outbox leases.

## Wider suite limitations

The wider `ShortDrama` filter ran 423 tests / 6,734 assertions: 12 errors, 4 failures, 28 skipped. It is NOT a green full-suite result.

- Ten errors rely on unavailable hashed build artifacts or an obsolete sibling frontend path. One layout assertion also depends on generated assets. Build outputs were not restored or committed just to satisfy old checks.
- Two normalization fixture errors, the compact subject-prompt wording assertion and the unknown-model assertion also reproduce with the pre-retirement service loaded in memory; no checkout or business data was changed for that comparison.
- One assertion pins manifest version 1.0.14 although the pre-change version was already 1.0.25 (now source manifest 1.0.26).
- The wider run additionally uncovered an existing missing duration-policy variable in the full prompt builder. This was restored and its affected test now passes.
- No paid LLM/video generation was run. Automated checks and saved-response replay do not guarantee future upstream availability or perfect model output.

## Recovery and deployment

The app migration `upgrade_20260926_retire_storyboard_rules.sql` removes only the active `storyboard_rules` key and archives it as `_retired_storyboard_rules_v1`. Restoring the old behavior requires a deliberate source rollback plus copying that backup back into the active field; never replay old paid tasks or rewrite their signatures as part of rollback. No historical script, task, asset, billing or result row is deleted.

Local develop integration only; push feature branches only. Source-only delivery excludes runtime files, compiled frontend assets and machine-local Vite proxy settings. No remote production migration, deployment or release publishing is performed by this task.

## Follow-up residual and impact review

- Corrected full-script validation being applied prematurely to historical local patches. Every received unit now retains its original stage-specific schema; truncated receipts still fail the normal decoder and enter the existing bounded recovery flow.
- Reuse historical skeleton and scene receipts by unit key, avoiding repeat generation of completed units.
- Recognize retired custom-document condition headings without executing them; ignore removed catalog keys during legacy document migration.
- Strip the exact retired built-in genre paragraph from frozen system templates when assembling requests, and omit old count diagnostics from model-facing plan payloads. Stored snapshots and original results remain intact.
- Apply the no-genre-quota Agent instruction only to script/storyboard stages. Asset, video and music stages retain their own prompts.
- Remaining rule names are empty API adapters, historical audit fields, migration backup identifiers and compatibility readers. No active tenant genre rules remain in the local database.
