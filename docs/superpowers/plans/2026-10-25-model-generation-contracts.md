# Model Generation Contracts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

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
- [ ] Pin failing tests for enum encodings, duration ranges, native URL shapes and first/last frame separation.
- [ ] Implement shared pure helpers and run the focused suite.
- [ ] Commit checkpoint and merge local develop.

### Task 2: Integrate runtime options, admission and payloads
**Files:** Modify `MarketImageModelRuntimeService.php`, `MarketVideoRuntimeService.php`, reference asset normalization and business request adapters as needed.
- [ ] Add failing representative payload tests for Hailuo, PixVerse, Nano Banana, Qwen, MiniMax H3 and Kling contracts.
- [ ] Expose full parameter/material modes, fix native fields/types and parameter choices, validate before reservation while retaining legacy/app paths.
- [ ] Run all model payload/mode fixtures, existing runtime regressions and syntax checks; commit and merge.

### Task 3: Render and preserve the full contract
**Files:** Shared generation parameter component and `pc/apps/aigc_video/model-contract.ts`, image contract, composer, creation page and workspace home; focused frontend tests.
- [ ] Pin failing frontend tests for last-frame mode, per-mode limits, full scalar/nested parameters and stale selections.
- [ ] Render schema-driven controls and structured material roles with existing uploads; retain provider parameters in quote and submit.
- [ ] Run focused suites, type/build checks; commit and merge.

### Task 4: Re-audit and deploy
- [ ] Regenerate all 109-model, 201-mode audit evidence, adding conditional/boundary fixtures where needed; resolve failures.
- [ ] Obtain one independent whole-change review, resolve correctness findings and re-run affected checks.
- [ ] Back up online changed files/assets and deploy verified artifacts without interrupting active jobs.
- [ ] Verify online hashes, full configuration, representative valid/invalid nonbillable quotes and browser controls; publish the updated matrix and precise test boundaries.

## Execution ledger
- 2026-10-25: Baseline audit read; both remotes fetched, no remote changes missing locally; feature branches created preserving existing local fixes.
