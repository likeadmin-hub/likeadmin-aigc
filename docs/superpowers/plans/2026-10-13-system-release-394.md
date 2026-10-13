# System 1.0.394 Release Implementation Plan

> For agentic workers: REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Publish the exact 1.0.393 to 1.0.394 system update and synchronize frontend/server develop and master.

**Architecture:** Freeze existing local develop snapshots in the already isolated release workspaces. Use official per-terminal builds, staging and package/publish tools; preserve original worktree changes and tenant data.

**Tech Stack:** PHP 8.2, Node/Vite/Nuxt/UniApp, Git, official Python packaging/private release client.

**Spec:** User-authorized release request; docs/system-release-checklist.md and the LikeAdmin update-packaging skill.

## Global Constraints

- Exact predecessor 1.0.393; automatic patch bump to 1.0.394.
- No secrets, local runtime data, uploads or frontend sources in the system archive.
- Preserve immutable runtime migration and vendor protocols; no vendor edits.
- Agent disabled by default; real model acceptance pending, not declared passed.
- Separate source/runtime repositories; no force pushes.

## Review Focus

- Cover every changed source/SQL path in staging or document exclusions.
- Recompute signatures and frontend/Mini Program artifact metadata.
- Verify all six Agent tables in fresh-install and incremental SQL.
- Confirm predecessor/version/package hash from private release readback.
- Verify both public branches on each required remote match the tested commit.

## Tasks

- [ ] Freeze scope, bump versions and commit release notes independently; checkpoint merge local develop.
- [ ] Build PC, tenant, platform, H5 and mp-weixin with repository scripts; verify entries/artifact.
- [ ] Run focused decoration/video regressions and source runtime probes; retain baseline-test limitations.
- [ ] Stage against genuine 1.0.393; package with automatic bump, validate signatures/SQL/forbidden paths/updater preflight.
- [ ] Commit outputs; publish verified archive, read back private record, synchronize develop/master to Gitee and server GitHub.

Ruling: Move the Ads JSON from docs into the application runtime resource directory and adjust both importer/preparer paths. The official system stager excludes docs, so the source-only placement would break post-upgrade imports. JSON bytes and 60 preset identities remain unchanged; no import or model call is executed.

Ruling: The independent reviewer found canonical CLI paths bypassed immutable generation selection. PHP now uses runtime-loader/AigcRuntimeApp and pinned generation data; Python validates/pins current.json, rejects pending updates/traversal/missing data and never falls back. Eight regression checks plus a genuine 393 canonical-tree / 394 selected-generation probe validate this without importing data or calling models. Rebuild the not-yet-published artifact and retain the superseded artifact separately.
