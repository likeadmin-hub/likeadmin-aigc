# Uploaded script final fallback implementation plan

> **For agentic workers:** Use superpowers:executing-plans to implement this plan in the current session. Steps use checkbox syntax.

**Goal:** Run one source-bound model repair after a completed real parser result fails validation.
**Architecture:** Freeze upload source/model; validate the primary output; queue a durable one-call repair; publish only revalidated output with combined billing.
**Tech Stack:** Existing PHP/ThinkPHP task services, market text runtime, PHPUnit and local Docker.
**Spec:** docs/superpowers/specs/2026-10-30-script-parse-final-fallback-design.md

## Global constraints
- Exactly one additional provider call per parse task; no automatic repeat after an invalid repair.
- Keep the primary API task, receipt, source bytes, tenant ownership and cancellation fences.
- Keep paid calls outside transactions and HTTP polling.
- Do not enter/change the API repository, push, publish or generate media.

## Review focus
- Concurrent polls must schedule only one repair.
- Restarted delivery must replay its receipt without paying again.
- Incorrect/missing source ranges, episode count and time segments must fail validation.
- Canceled/superseded tasks cannot submit or publish.
- Failed/unknown provider receipts must remain auditable without automatic resubmission.

## Task 1: Source contract and bounded fallback
Files: new ShortDramaFileParseFallback service and tests; existing upload creation, parse synchronization and planning dispatch services.
- [x] Add failing tests for valid-primary bypass, one invalid-primary repair, invalid-repair stop, preserved source/episode boundaries and timing checks.
- [x] Implement a pure validator/request contract plus a durable background stage using the existing planning unit and file-model source attachment.
- [x] Add transactional scheduling/cancellation/replay and cost-aggregation regression tests.
- [ ] Commit and merge into local develop, then run the explicit short-drama suite.

## Task 2: Acceptance
- [ ] Complete the four live original samples (2/2/3/2 episodes) already in progress.
- [ ] Exercise the fallback contract with controlled invalid-primary tests and a separate real-model repair receipt; distinguish those from untouched real API results.
- [ ] Record statuses, exact time boundaries, source checks, model/recovery limitations and billing evidence in the acceptance report.

Execution notes: source snapshot is capped at 2 MB without truncation; unavailable snapshots leave the primary API path intact. Existing heading numbers are preserved in source while internal episodes follow document order. New fallback stages disable model, transient and compatibility retries; actual runtime app_task event fences cancellation. A fresh read-only reviewer identified three issues (event hook, runtime retries, late billing); all received failing-then-passing regression tests, and the reviewer confirmed them resolved.
