# Agent plan management and long reading

Source change on `feature/agent-optimization`; no migration or release package.

- `AgentPlanService` adds owner-scoped editor, edit/remove, retry preparation and original-task checking. Session/run locking and an approval hash protect edits from stale tabs and concurrent confirmations. Edits do not submit or charge. Model selection, references and fixed generation preferences remain unchanged.
- Retry preparation requires a definitive failed/canceled original task and `refunded`, `none` or `prechecked` billing state, or an unsubmitted step without a `tool.started` event. Unknown submissions and unresolved fees are blocked. Every retry creates a new step ID and therefore a new native billing identity. The original step remains terminal with a supersession link; attempts are limited to three. Explicit confirmation is required even in automatic mode.
- Checking a known task calls its existing query adapter. It never resubmits. Once results are reconciled, the existing worker resumes polling/continuation. Pending unsubmitted steps require a new confirmation.
- `AgentLongReadService` adds the internal `reading` phase, reusing queued/running/terminal statuses. The optional `_long_reads` preference snapshot stores the cursor, bounded rolling summary and source notes. Existing records without it retain their behavior. One worker claim performs at most one metered text call: 8,000 source characters, a 3,000-character rolling summary and a 500-character note. PDF batches keep page images and split long extracted text; video batches are explicitly sampled frames, not audio understanding.
- Every source read checks session/topic/file ownership. Deleted sources are excluded from summary context and cannot be fetched through notes. Stop preserves an already completed paid chunk; resuming uses the saved cursor. Uncertain model/settlement outcomes enter `needs_review` and are never automatically replayed. After 128 batches a manual continuation is required. Native document/media size limits remain in force.
- API declarations are in the app schema: `planEditor`, `editPlan`, `retryStep`, `checkStep`, `resumeReading`. The frontend consumes additive detail fields; old clients can still confirm/cancel and see existing steps.

## Validation

Focused rollback fixtures: `HomeAgentPlanManagementTest.php`, `HomeAgentLongReadTest.php`. Run along with existing context, uploaded document, media, sequence, composer settings, billing recovery and provider protocol tests. No real provider calls are needed. Frontend: `home-agent-plan-management.test.cjs`, existing composer/run/reconnect/generation/reply-stage tests, SFC compilation and visual review.

## Runtime and rollback

Reload the local worker only through its normal process manager after current work has drained. The shared supervisor script manages other queues, so do not kill it merely to refresh one service. No production deployment is included.

Before reverting to code that does not understand the `reading` phase, finish or explicitly stop those runs and preserve their preferences/notes. Do not clear task/consumption records or refund manually as part of a source rollback. Reverting the UI alone is compatible with existing confirmation and stop APIs.
