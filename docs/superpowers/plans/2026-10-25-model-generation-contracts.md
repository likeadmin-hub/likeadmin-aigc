# Model Generation Contracts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Make every sellable image/video model use its upstream parameter and material contract, then verify and deploy the complete change.
**Architecture:** Normalize synchronized schemas and material modes in shared PHP helpers. Both runtimes expose this contract, validate requests before reservation, and serialize native fields without changing historical billing snapshots. Shared frontend controls render the contract and retain mode/material/parameter selections.
**Tech Stack:** PHP 8.2, ThinkPHP, TypeScript, Vue/Nuxt, existing Docker/BaoTa runtimes.
**Spec:** ../../../../runtime/model-capability-audit-20261025/检查报告.md

## Global Constraints
- Preserve tenant shelf/manual prices, old task snapshots, application API routing and unrelated work.
- Use upstream declarations; do not invent resolutions, durations, modes, or reference limits.
- Do not restore reverted theme/color changes. No paid generation without a concrete authorized budget.
- Develop on feature branches, commit checkpoints and merge local develop before runtime verification. No remote develop/master pushes.

## Review Focus
- Same model name across channels can have different contracts, including mutually exclusive frames.
- Scalar URLs, URL arrays, object arrays, nested subjects, masks and asset IDs are different inputs.
- Zero/smart durations and shared billing tiers must retain their native types and valid SKU selection.
- Required and mode-specific parameters must fail before billing; defaults must not leak across modes.
- Model/mode changes must clear invalid controls and re-resolve prices in both creation entry points.

### Task 1: Normalize schemas and material contracts
**Files:** Create `app/common/service/power/MarketGenerationSchema.php`, `MarketGenerationMaterials.php`; test `tests/market-generation-contracts.php`.
**Interfaces:** `MarketGenerationSchema::properties/choices/normalize/validate`; `MarketGenerationMaterials::modes/serialize/validate` consume synchronized metadata and immutable SKU locks.
- [x] Pin failing tests for enum encodings, duration ranges, native URL shapes and first/last frame separation.
- [x] Implement shared pure helpers and run the focused suite.
- [x] Commit checkpoint and merge local develop.

### Task 2: Integrate runtime options, admission and payloads
**Files:** Modify `MarketImageModelRuntimeService.php`, `MarketVideoRuntimeService.php`, reference asset normalization and business request adapters as needed.
- [x] Add failing representative payload tests for Hailuo, PixVerse, Nano Banana, Qwen, MiniMax H3 and Kling contracts.
- [x] Expose full parameter/material modes, fix native fields/types and parameter choices, validate before reservation while retaining legacy/app paths.
- [x] Run all model payload/mode fixtures, existing runtime regressions and syntax checks; commit and merge.

### Task 3: Render and preserve the full contract
**Files:** Shared generation parameter component and `pc/apps/aigc_video/model-contract.ts`, image contract, composer, creation page and workspace home; focused frontend tests.
- [x] Pin failing frontend tests for last-frame mode, per-mode limits, full scalar/nested parameters and stale selections.
- [x] Render schema-driven controls and structured material roles with existing uploads; retain provider parameters in quote and submit.
- [x] Run focused suites, type/build checks; commit and merge.

### Task 4: Re-audit and deploy
- [x] Regenerate all 109-model, 207-request audit evidence, adding conditional/boundary fixtures where needed; resolve failures.
- [x] Obtain one independent whole-change review, resolve correctness findings and re-run affected checks.
- [x] Back up online changed files/assets and deploy verified artifacts without interrupting active jobs.
- [x] Verify online hashes, full configuration, representative valid/invalid nonbillable quotes and browser controls; publish the updated matrix and precise test boundaries.

## Execution ledger
- 2026-10-25: Baseline audit read; both remotes fetched, no remote changes missing locally; feature branches created preserving existing local fixes.

- 2026-10-25: Shared schema/native contracts and all 109 catalogue rows wired; 207 payload fixtures pass with zero missing fields or native-schema errors. 90 current native fingerprints match; remaining legacy/app paths retain their audited adapters.
- 2026-10-25: Frontend shared scalar/structured controls, frame views and SKU dimensions checked: 45 focused regressions, 134 video-mode fixtures, 221 existing field/wire checks pass. PC static generation succeeds from committed isolated source, including publisher sanitation.
- Final: fixed native input/video billing variants, independent image rendering quality, undefined dimensions, role inference, frame/reference combinations and advanced-parameter duplicate signatures; focused 45/45 suite green.
- Final: fixed missing actual-duration receipt parsing and delayed receipt reservation settlement. Baseline reflection reproduced token-count-as-seconds failure; revised duration tests GREEN. Real local transactional settlement verifies reservation retained until usage, difference refunded to both wallets, repeat receipt idempotency, and complete rollback.
- 2026-10-25: Source-duration requests use server-measured audio/video; frames/fps derive from normalized native values. Inputless durationless generation retains upstream meter's 60-second estimate and requires actual output receipt. No invented duration is sent upstream.
- 2026-10-25: Authorized online deployment at opc.likeadmin.cn / aigc.likeadmin.cn; all 16 backend/resource preexisting hashes matched baseline, backup at runtime/support-backups/model-parameters-20261025-012123. Root static publication matches existing publisher; prior assets retained. Final browser acceptance adds first-frame-slot compatibility and removes undeclared geometry controls. No workers restarted.
- 2026-10-25: Online 49 image/60 video configs, 90 native fingerprint matches; 205 sellable request quotes pass, 2 no-SKU options stay unavailable. Missing required frame rejected. Catalogue, prices, task and consumption counts unchanged by quote validation. Browser PixVerse quote and Nano Banana Pro 1K/2K quotes pass; measured 2s OmniHuman audio quotes 214 points. Zero provider submissions.
