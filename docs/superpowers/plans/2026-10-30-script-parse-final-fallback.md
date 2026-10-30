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
- [x] Commit and merge into local develop, then run the explicit short-drama suite.

## Task 2: Acceptance
- [x] Complete the four live original samples (2/2/3/2 episodes) already in progress.
- [x] Exercise the fallback contract with controlled invalid-primary tests and a separate real-model repair receipt; distinguish those from untouched real API results.
- [x] Record statuses, exact time boundaries, source checks, model/recovery limitations and billing evidence in the acceptance report.

Execution notes: source snapshot is capped at 2 MB without truncation; unavailable snapshots leave the primary API path intact. Existing heading numbers are preserved in source while internal episodes follow document order. New fallback stages disable model, transient and compatibility retries; actual runtime app_task event fences cancellation. A fresh read-only reviewer identified three issues (event hook, runtime retries, late billing); all received failing-then-passing regression tests, and the reviewer confirmed them resolved.

Ruling: final live acceptance found execution instructions being judged as plot events. Add a versioned workflow classification in content acceptance, discard only returned workflow items, retain actual in-story dialogue/actions, and invalidate old unscoped requirement caches. This prevents bogus on-screen requirements without changing older paid prompts.


Final acceptance (2026-10-30): four unchanged uploaded fixtures produced 2/2/3/2 episodes through the real parser and normal episode-production APIs. All nine public episode states are success with needs_review=false. Source ranges, duration boundaries, absolute offsets, display labels and public/persisted shot values passed independent checks. The separate controlled-invalid-primary live repair used one real model call (6894); invalid repair 6847 stopped without looping. Valid real API smoke project 1410 produced three episodes with zero fallback units. Explicit final suite: 360 tests / 1239 assertions; all 17 changed PHP files pass PHP 8.0 lint. Report and private receipts: runtime/short-drama-real-api-retest3-20261030/验收报告.txt (outside Git). Earlier interrupted stream consumption 6625 remains settlement_failed/provider_stream_incomplete and was not replayed. Verified using DeepSeek-V4-Pro product 65; default GPT channel unavailable, no global configuration change. Local develop only; no push, release, API-repository work or generated media.
