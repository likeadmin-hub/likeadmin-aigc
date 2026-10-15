# Long-document fidelity repair — 2026-10-15

## Scope and behavior

The offline comparison reproduced a specific loss: a valid 3,470-character model summary containing a late deadline correction was stored as only 3,000 characters; a note over 500 characters also lost its ending. The run still completed reading. This was a storage/prompt fidelity defect, not a measured real-model error rate.

`AgentLongReadService` now:

- Stores returned summaries and notes in full in the existing MEDIUMTEXT event payload. The 3,000/500-character prompt limits are suggestions, subordinate to preserving relevant facts, exceptions and corrections.
- Rejects an encoded payload exceeding MEDIUMTEXT capacity through the existing review-only path, without advancing the cursor or automatically repeating the call. It does not silently truncate to fit storage.
- Keeps the current raw text segment as `source_text` in its event, exposed through the existing owner-authorized `reading_notes` tool. Source offsets and pagination remain unchanged. PDF/video evidence is the existing extracted text / sampled visual reading, not a promise to preserve every audiovisual detail.
- Supplies up to 1,000 characters of the previous segment's raw tail to the next model request, separately from the summary and new material. The new segment cursor does not move backwards and there is still one summarization request per segment.
- Marks model-facing summaries `summary_verified: false`. Completed means the read cursor reached the end, not semantic verification. Instructions require checking source evidence for dates, amounts, quantities, negatives, exceptions and corrections; missing summary content does not establish absence in the source.
- Reads legacy inline summaries and events without `source_text` unchanged. No historical backfill or automatic rereading is performed. Legacy continuations lack overlap until their first newly recorded segment.

No endpoint, user-facing status, model selection, provider protocol, permission, price, settlement rule, migration, app version, or frontend change. Preferences retain only cursors and event references. Source evidence access uses the existing file/tenant/user/session checks.

## Executed offline validation

Validated from local `develop` after merging implementation commit `ae0ccbdde`; all fixture transports were mocked and DB changes rolled back. PHP processes disabled global curl/socket calls and URL file access.

**13 test files passed**:

- HomeAgentLongReadFidelityTest (new): oversize summaries/notes preserved byte-for-byte; tail correction survives storage and next prompt; split conditions available with raw overlap; raw source reconstruction; cancellation/resume without replay; bounded preferences; incomplete summary explicitly unverified with independent evidence retained; legacy inline/event compatibility; invalid response review-only; owner/session/deletion isolation; no generation steps.
- HomeAgentLongReadTest: 200,000 characters / 50 mock requests, pagination, cancel/resume, final-chunk resume, batch pause, admission recovery, uncertain response quarantine.
- HomeAgentReadCacheTest, HomeAgentUploadedDocumentTest, HomeAgentContextTest, HomeAgentContextChatTest.
- HomeAgentBillingRecoveryTest, HomeAgentPlanManagementTest, HomeAgentSequenceTest, HomeAgentExecutionBoundaryTest, HomeAgentResponseRecoveryTest, HomeAgentCapabilityIsolationTest, HomeAgentContractsTest.

Both changed PHP files passed syntax checks. The original comparison corpus was replayed separately:

| Probe | Before | After |
| --- | --- | --- |
| Six extraction/format/length comparisons | Exact source retained | Exact source retained |
| Complete fixture summary, 16,000 / 200,000 characters | All 8 fixture facts retained | All 8 fixture facts retained |
| Oversized summary with late date correction | Correction lost in summary and note | Correction retained in summary and note |
| Deliberately incomplete fixture summary | Missing fact accepted | Summary still incomplete; explicitly unverified, raw evidence retained separately |

The last row is intentional transparency: this repair cannot make an incomplete model response complete through deterministic validation. No semantic completeness detector or real-model accuracy score is claimed. Source-check instructions are model guidance, not proof that every future answer will perform the check. Raw overlap is bounded, not a guarantee for arbitrarily long split clauses. Full answer-quality A/B still requires a suitable inference environment.

## Safety comparison and activation

Read-only before/after snapshots matched for both wallets, consumption counts/financial fields, complete user and tenant ledgers, Agent run/step/artifact counts and status hash, queue states and active leases. Historical STT review remained **1 item / 40 reserved points**; text review remained **41 pending_usage / 2 settlement_failed**. No paid task, backcharge, refund, resubmission, or historical correction was performed.

The existing Agent daemon (PID 340637) remained healthy and idle and was **not restarted**. CLI regressions loaded the repair; the long-running daemon needs a safe reload to activate it. No production deployment was performed.

Full summaries, source checks and raw overlap can consume more tokens and event storage than truncation. This is an intentional quality-first tradeoff; no token savings are claimed. Existing model context limits and review-only handling of uncertain calls remain in effect.

## Handoff

task: preserve long-document evidence and eliminate silent summary/note truncation

branch: feature/agent-optimization

server scope: AgentLongReadService, focused fidelity regression, this evidence document

web scope: none; unchanged

contract changes: optional event fields source_text/summary_verified; model-facing overlap and verification metadata; no migration or permission/billing change

completed / remaining: source repair and offline regression complete; daemon activation and real-model answer-quality comparison not performed

validation: 13 offline test files, two PHP syntax checks, six extraction comparisons, four deterministic long-read replays, unchanged financial/historical snapshots

server commit: ae0ccbdde (implementation); subsequent documentation commit recorded in Git

web commit: unchanged at 62eb965926873a16701a014e0fb1ffcba5cf5842

risks or blockers: semantic omissions remain possible; no free inference environment established; very large responses remain subject to model/storage capacity; no historical data repair
