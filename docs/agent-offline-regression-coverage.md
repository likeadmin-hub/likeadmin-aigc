# Agent execution and recovery: offline regression coverage

Validated 2026-10-15 on local `develop`, after merging source commits from `feature/agent-optimization` independently in server and web. This is a coverage ledger, not a claim that every possible provider failure has been tested.

## Change contract

- Every native submit/query outcome, including exceptions, is reconciled under run/step locks with owner, lease, task identity and version checks. Stale outcomes cannot downgrade a completed step. A late accepted submission can retain its task ID without reviving a quarantined run or submitting again.
- Query-error timeout uses the step's admission time in `prepared._execution.started_at`. Legacy steps use their admission event; records without an event get a persisted first-observation time. The conversation/plan age never expires a newly started task. A new admission resets the clock.
- Completed receipts whose local collection failed can retry local collection. Invalid ownership/identity results require a fresh query; they are not trusted from the receipt cache. Successful original-task manual checks collect results only after the current version is checked inside the transaction.
- `result._agent_artifact_ids` links each step to its output, independently of session-gallery deduplication. Existing favorites and original artifact ownership remain intact. Legacy rows still resolve by run/step identity.
- The additive public `steps[].artifact_ids` field contains only artifact IDs from the same tenant, user and session. PC task cards/timeline can display a reused result in each linked step without duplicating gallery entries. Missing fields retain legacy rendering.
- No new endpoint, request parameter, permission, price, model protocol value, migration or automatic historical repair. Existing API declarations have no strict response schema requiring an update. No app version or release artifact is produced.

## Executed coverage matrix

All backend test names below are in `tests/Feature/`. All listed tests passed. Supplier/LLM responses are mocks, not live generation.

| Area | Invariant/scenarios exercised | Evidence |
| --- | --- | --- |
| Submit/idempotency | Repeated request ID, conflicting payload, owner isolation, original task identity | HomeAgentDatabaseTest, HomeAgentWorkerTest |
| Edit/confirm | Edit/remove, requote, stale approval rejection, retries preserve lineage, duplicate confirmation | HomeAgentPlanManagementTest |
| Retry safety | Failed/refunded eligibility; unresolved billing, successful/results-bearing tasks, foreign owner and attempt limit reject; new approval required | HomeAgentPlanManagementTest |
| Cancellation/admission | Before execution, during preparation, during accepted submission, quote failure/change, later-step cancellation | HomeAgentExecutionBoundaryTest (17 scenarios) |
| Result/lease races | Late pending/exception/success after a replacement worker finishes; task-ID/version mismatch; confirmation poll replacement | HomeAgentResponseRecoveryTest (18 scenarios) |
| Accepted submission uncertainty | Late task ID retained without restarting review; no next-step submission | HomeAgentResponseRecoveryTest |
| Real concurrent claims | Three independent processes claim six runs; exactly six submits; restart of bound tasks queries only | HomeAgentIsolatedConcurrencyTest |
| Real process exit | Child exits with 77 at submit boundary before binding; recovery quarantines the unknown submission, no replay | HomeAgentIsolatedConcurrencyTest |
| DB contention handling | Mocked MySQL deadlock/lock timeout deferred; unrelated database errors propagate | HomeAgentClaimContentionTest; real row contention also occurs in isolated concurrency test |
| Query clocks | Old plan/new task; expired step; legacy admission event; missing legacy event with stable observation clock; fresh admission | HomeAgentResponseRecoveryTest |
| Result collection | Owner-invalid result rejected then recovered by querying same task; cached local receipt recovered without supplier calls; cancel retains accepted output | HomeAgentResponseRecoveryTest |
| Multi-stage execution | Real output gates continuation; ask/auto approvals; failed/partial/empty results; ordinary distinct outputs and same-URI reuse | HomeAgentSequenceTest, HomeAgentResponseRecoveryTest |
| Gallery/ownership | Cross-run reuse within session, legacy links, tenant/user/session separation, favorite preserved, no duplicate gallery row | HomeAgentResponseRecoveryTest, HomeAgentArtifactLibraryTest, HomeAgentFavoritesTest |
| Shared asset consumers | Fitting output association, deleted/restored aliases and partial outputs, source metadata retained | HomeAgentFittingArtifactsTest, WorkspaceAssetDeletionTest |
| Long documents | 200,000 characters/50 mocked chunks, bounded notes, citations/pagination, cancellation and resume, final-chunk resume, batch limit, no repeated completed chunks | HomeAgentLongReadTest |
| Long-read protection | Deleted/foreign source, uncertain settlement/receipt, balance admission failure and recovery | HomeAgentLongReadTest, HomeAgentBillingRecoveryTest |
| Read/context cache | Tenant/user/session/topic boundaries; recent context; lookup/cache behavior | HomeAgentReadCacheTest, HomeAgentContextTest, HomeAgentTopicTest |
| Text billing | Usage stream variants, missing usage, timeout/rejection, settlement/receipt persistence failures, uncertain cost quarantine, insufficient balance | MarketTextUsageStreamTest, HomeAgentBillingRecoveryTest |
| Transcription billing | Durable response receipt recovery, no repeated reconciliation charge, owner isolation, incomplete/unknown response stays review | HomeAgentTranscriptionRecoveryTest |
| Speech billing | Sync/async success, failure/refund, uncertainty, duplicate submit/query, storage recovery, frozen quote, balance/permission denial | HomeAgentSpeechTest |
| Music billing | Submit/query success and failures, query outage, duplicate query/settlement, result recovery | HomeAgentMusicWorkflowTest |
| Permission/contracts | Capability owner isolation, disabled/revoked capability paths, old budget authorization and ask/auto contract | HomeAgentCapabilityIsolationTest, HomeAgentContractsTest, HomeAgentSpeechTest, HomeAgentPlanManagementTest |
| Read APIs/SSE | Owner-scoped stream snapshots/events and historical message attachments | HomeAgentStreamTest, HomeAgentMessageAttachmentsTest |
| PC client regressions | 76 tests: editor/retry/resume UI, confirmation, stop, reconnection, upload cancellation/dedup, preferences, skills, result/gallery metadata, timeline, repeated-output links | web/pc/tests/home-agent-*.test.cjs |

Totals: **25 distinct backend test files**, **76 frontend tests**, **6 changed PHP files linted**. Repeated affected tests after the additive detail/display change are not counted as new test files. Some frontend tests render actual Vue templates through SSR; this is not a live-browser paid workflow test.

## Safety controls and baseline comparison

- Database fixtures are rolled back. Billing tests exercise real local wallet/ledger logic only within rollback transactions with mocked transports; no real supplier work is generated.
- Isolated concurrency tests use six uniquely prefixed cloned tables, not business tables. Native generation and access setup are mocked in this concurrency fixture; real authorization checks are covered separately above. Real workers cannot claim the test rows. Temporary tables are removed on completion.
- Test PHP processes disable global `curl_exec`, `curl_multi_exec`, `fsockopen`, `pfsockopen` and `stream_socket_client`. Namespaced fake transports remain available. No real task-worker command, historical reconciliation, callback replay or batch refund is run.
- First isolated-fixture setup failed due to a framework config-object mismatch. The fixture was corrected, the six empty temporary tables were explicitly removed, and the real multiprocess test then passed. This was a test-harness failure, not a hidden passing result.
- Read-only before/after comparisons were equal for both wallets, consumption financial fields, user and tenant ledger contents, Agent historical run/step/artifact counts/status hash, queue states and active leases.
- Historical transcription remains one review item reserving **40 points**, with no receipt journal. Text billing retains **41 pending_usage / 2 settlement_failed**. No historical backcharge, refund or resubmission was performed.
- Agent Worker remained healthy and idle with no eligible/active Agent jobs. The existing daemon was **not restarted** during this change; activating newly loaded worker code requires a separate safe drain/reload. CLI regressions load the new code.

## Repeat safely

Follow AGENTS.md: commit on the feature branch; merge into clean local develop; run validation only there; return to feature before editing. Run database tests sequentially, not concurrently against shared business tables.

Use PHP 8.2 in the local baota container, backend workdir `/www/wwwroot/likeadmin-aigc/server`:

```sh
php -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client tests/Feature/HomeAgentResponseRecoveryTest.php
php -d disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client tests/Feature/HomeAgentIsolatedConcurrencyTest.php
```

Run all other named backend tests in the matrix with the same network restrictions. Do not substitute the older `HomeAgentConcurrentWorkerTest.php`, which uses committed fixtures in normal tables and requires the real worker stopped. On web local develop run `node --test pc/tests/home-agent-*.test.cjs`.

Take read-only wallet/ledger/review snapshots before and after. If any differs, investigate before declaring completion; do not repair historical billing as part of test cleanup.

## Limits / rollback

No live provider generation, paid end-to-end browser flow, production deployment, real provider outage, exhaustive OS-level crash timing, full system load test, or app install/uninstall lifecycle was performed. There are no route, manifest, permission or installation changes. Long-document sampling limits and the native storage/model limits remain unchanged. These tests demonstrate the listed invariants; they do not prove the absence of every possible defect.

Source rollback requires no data migration. New optional JSON fields can remain; never erase runs, result links, receipts or financial records. Old code can ignore those fields but loses the new response fencing and reused-output handling, so keep these fixes together. No historical review item should be processed automatically during rollback or worker activation.
