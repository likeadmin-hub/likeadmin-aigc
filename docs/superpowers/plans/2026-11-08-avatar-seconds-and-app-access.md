# Avatar seconds and application availability implementation plan

**Goal:** Accurate digital-human second billing and consistent closed-app behavior.
**Architecture:** Extend the current billing and registry/access owners. Centralize
frontend availability gates and retain existing platform/tenant switches.
**Tech Stack:** PHP8.2/ThinkPHP/MySQL, Vue3/Nuxt PC, UniApp H5/MP, Vue admin.
**Spec:** ../specs/2026-11-08-avatar-seconds-and-app-access.md

## Constraints

- Preserve previous stored-media fixes and all unrelated worktree changes.
- Feature branches per repository; task-scoped commits/checkpoint local-develop
  merges. No remote push or deployment without a new delivery request.
- No historical price mutation, automatic backcharge or paid provider requests.
- Schema change must cover installation/new-tenant/app migration/system update
  paths that own the table, with an exact guarded migration and package SQL order.

## Tasks

1. Remove the obsolete empty-state history action, preserve the actual upload
   picker and separate existing avatar/voice business libraries; compile template.
2. Trace pic_lipsync/image_human/video human quantity and pricing snapshots. Write
   failing cases for fractional actual duration/unknown duration/duplicate and
   failure settlement; repair only proven defects. Preserve clone billing.
3. Widen the digital-human billing quantity from INT to DECIMAL(16,6) with an exact
   guarded, repeatable migration. Verify old rows, metadata, unsigned maximum,
   rejection of unknown types and install/update package coverage.
4. Make default-app initialization and effective app access honor platform disable
   and tenant shelf off. Keep backend reopen/management and existing task polling.
   Test each boundary against an isolated real database.
5. Reuse central PC and UniApp availability state for menus, homepage/DIY entries,
   aliases and direct routes. Add a distinct closed/retry state; do not mount a
   closed tool or silently substitute a closed generation mode.
6. Ensure existing platform/tenant switch controls are exposed for every tool.
   Filter digital-human tabs using effective available app codes.
7. Review cross-layer contracts, run targeted plus relevant full regressions and
   official builds. Commit and merge checkpoints into local develop, run guarded
   local migrations against an explicitly verified disposable DB, and revalidate.
8. Save test/build/schema parity and limitation evidence under runtime. Leave local
   develop with preserved unrelated files and explicitly report publication scope.

## Review focus

Default initialization resurrecting disabled apps; tenant contamination in cached
catalogues; explicit closed modes falling back to a paid model; integer or guessed
usage reaching final settlement; task polling/refund stranded behind a new gate.
