# Micro-video frame consistency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement task-by-task.

**Goal:** Ship opt-in shared boundary images across admin/PC/mobile and prove paid image/video retries and merge with the supplied woman/product.
**Architecture:** Extend existing micro-video orchestration and frozen request/quote contracts; reuse managed image runtime and billing. Keep reference-mode legacy tasks intact. Root owns frontend and acceptance, one backend implementer owns server; only one implementation subagent at a time.
**Tech Stack:** ThinkPHP PHP82/MySQL, Vue3/Nuxt, tenant Element Plus, UniApp, existing market runtimes and FFmpeg.
**Spec:** ../specs/2026-10-17-micro-video-frames-design.md

## Global Constraints
- Exact DTOs/endpoints and copy come from Spec; no vendor, tenant or price hardcoding.
- Preserve unrelated files; explicit task staging; feature branches -> local develop checkpoint before runtime/migrations. No remote push/release.
- User already approved implementation. Do not stop to ask them to reapprove intermediate docs.
- Parent runtime evidence outside repositories: runtime/micro-video-frames-20261017/.

## Review Focus
- Duplicate/unknown image admission must not create another bill (Task 1).
- A regenerated shared boundary must invalidate both video slots and dependent images (Task 1/2).
- Returning to creation while another plan finishes must not overwrite draft (Task 2).
- Legacy whitelisted model removal and image capability modes must not alter frozen retries (Task 1).
- Cross-user IDs, signed asset URLs, failed storage and restart recovery must preserve authority/receipt (Task 1/3).

### Task 1: Backend frame execution and configuration
Files: app/common/service/app/aigc_micro_video/{MicroVideoCatalog,MicroVideoSettings,MicroVideoService,MicroVideoStore,MicroVideoWorker,MicroVideoReservation,MicroVideoMedia}.php; create focused MicroVideoFrames/FramePolicy/FrameWorker classes as needed; app/api/controller/app/aigc_micro_video/Frame.php; app/apps/aigc_micro_video/{manifest.json,api_schema.json,migrations/*,permissions/*}; tests/Feature/MicroVideo*.
Interfaces: Spec frozen DTOs and endpoints, existing tenant config API, frame generation separate from video submit.
- [ ] Write failing pure/service tests proving N+1 links, freshness/ownership/quote barriers, replay and retry isolation.
- [ ] Run tests red with Docker PHP82/phpunit.
- [ ] Implement capability filtering, stable segments, schema, image service/worker, request mode/roles, migration/install parity and admin DTOs.
- [ ] Run PHP syntax, new tests and existing micro-video/provider contract tests green.
- [ ] Commit only task files. Report schema migration method, contract deviations, tests and SHAs. Root checkpoint-merges and applies migration locally before runtime.

### Task 2: PC, tenant admin and mobile flows
Files: web/pc/apps/aigc_micro_video/{api.ts,types.ts,useMicroVideo.ts,workflow.mjs,MicroVideoCreator.vue,components/*,micro-video.scss,tests}; pc/data/pc-ui-messages.json; web/tenant/src/views/apps/aigc_micro_video/{config,task}.vue; web/uniapp/src/apps/aigc_micro_video/{api,workflow,useWork,styles,pages/*}; app UX contracts.
Interfaces: Spec DTO/endpoints. Root owns these files; backend implementer must not edit web.
- [ ] Add controller regressions for detach/return, immutable background plan and image operations/quote invalidation.
- [ ] Run tests red, then implement retained-draft return, full count copy and opt-in frames UI/model/quote/single save/regenerate/confirm.
- [ ] Implement tenant model/template controls and admin frame diagnostics.
- [ ] Implement dedicated mobile mode/frames actions, confirmation gate and history recovery using mobile theme tokens.
- [ ] Run PC/mobile tests, scoped lint/i18n/theme/compilers, then commit and checkpoint-merge before runtime.

### Task 3: Integrated verification and actual acceptance
Files: runtime/micro-video-frames-20261017/ scripts/logs/reports (outside Git); update app docs/testing if findings warrant.
Interfaces: Actual tenant1 owned material URIs from prior final test; config set through application services; real paid calls authorized by user.
- [ ] Review backend and frontend changes independently, fix concrete findings with feature checkpoints.
- [ ] Build PC/H5/MP into isolated artifacts, verify real UI mode-off/on, pricing, return/back, full count, selection and frame preview across 13 skins/default/custom light+dark, narrow and reduced motion.
- [ ] Configure actual compatible video/image models. Reuse existing owned woman and blue product refs; prepare 2x10 plan.
- [ ] Inject one pre-provider frame failure, retry only that frame via normal endpoint and verify successful siblings/ledger unchanged. Complete/confirm 3 images.
- [ ] Inject one pre-provider video segment failure, retry normal continue path after new quote, preserve successful sibling and all images. Merge.
- [ ] Verify replay and terminal ticks add no attempts/billing; ffprobe/ASR/frame comparisons and actual in-app playback. Fix application issues, leave no unsupported success claims.
- [ ] Preserve real work; expire only temporary test credentials; leave required local worker/dev service running. Deliver final video and concise evidence with any residual visual limitations.
