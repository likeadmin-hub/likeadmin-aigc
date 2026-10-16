# Micro Video Implementation Plan

> Execute with superpowers:subagent-driven-development; user has explicitly authorized implementation of the agreed design. No repeated design approval required.

**Goal:** Complete micro-video application across backend, PC, H5 and Mini Program, with real generation/retry/concat evidence.
**Architecture:** Parent task and ordered segment tasks orchestrate existing market runtimes; immutable plan and pricing confirmation; separate resumable media merge. Frontends share the v1 contract in the spec and existing theme owners.
**Tech Stack:** ThinkPHP 8/MySQL/PHP 8.2, Vue/Nuxt, UniApp, FFmpeg/ffprobe.
**Spec:** `docs/superpowers/specs/2026-10-16-micro-video-design.md`

## Global Constraints
- All app APIs under app.aigc_micro_video; tenant/user ownership enforced on every mutation/read.
- Shared point services only; no direct balance SQL. Unknown submission is reconciled before retry.
- Failed segment retry never resubmits successful segments; merge retry never regenerates clips.
- Preserve actual returned durations and source audio; no extra creative media processing.
- Theme tokens only on all terminals; user-visible counts only during plan confirmation.
- Feature commits and local develop integration per repository instructions; unrelated files preserved.

## Review Focus
- Duplicate submit and worker death around upstream admission must not create duplicate charged tasks.
- Model/price/template changes after plan creation cannot silently change confirmed generation.
- Untrusted image URIs and cross-user task/asset access must be rejected.
- Mobile page hide/unload and late quote responses cannot overwrite drafts or show false failures.
- Different codec/audio streams and actual durations must still produce playable ordered output.

## Task 1: Backend and model workflow (root)
- [ ] Add failing policy tests for model whitelist, duration plan, references, continuity, stale revision, failed-only retry and public presentation.
- [ ] Implement app manifest/schema/migrations/controllers/models, config, direction and immutable plan services.
- [ ] Implement worker, admission/reconciliation/retry, complete actual-duration concat and install lifecycle parity.
- [ ] Verify PHP tests and commit explicit task allowlist; merge local develop, install app on verified local database.

## Task 2: PC creator (PC implementer)
- [ ] Implement app-local API/types, two-step creator, direction preview and whole-work history using existing video layout/theme primitives.
- [ ] Register app route and tools entry. Test stale quotes, editing and retry action behavior.

## Task 3: Mobile creator (mobile implementer)
- [ ] Implement H5/Mini Program creator, confirmation and work pages, native uploads/downloads, lifecycle and theme behavior.
- [ ] Register routes/manifest-compatible entry. Test recovery, draft and quote sequencing.

## Task 4: Tenant admin (admin implementer)
- [ ] Implement model whitelist/default, model/text settings, prompt settings, directions and detailed task/segment administration.
- [ ] Register entry with existing app management loader and theme-compatible Element Plus components.

## Task 5: Integration and acceptance (root)
- [ ] Review each task for spec and quality; fix findings with targeted regression tests.
- [ ] Commit web allowlist and merge local develop; run PHP, frontend checks, tenant/PC/H5/MP builds with isolated output.
- [ ] Real local owned image vision/planning, real video generation, failed-only retry and final concat; record task/ledger/duration/content evidence.
- [ ] Browser interactions and all registered theme matrix for PC/H5/admin; Mini Program runtime availability checked separately.
- [ ] Broad code review, fix findings, final report with verified evidence and concrete limitations.
