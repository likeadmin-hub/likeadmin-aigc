# System 1.0.395 release

User-authorized scope: publish exact 1.0.394 to 1.0.395 system update including default AI decoration, unified worker lifecycle and actual worker-impact upgrade checks; synchronize server/frontend develop and master, plus server GitHub mirror.

- [x] Freeze approved local develop snapshots; commit version and release notes; checkpoint merge local develop.
- [x] Build PC, tenant, platform, H5 and mp-weixin through official scripts; validate 1.0.395 artifact and PC version.
- [x] Verify decoration/update lifecycle regressions, schema-free no-stop path, safe retry and package staging.
- [ ] Stage against remote 1.0.394 SHA; package with automatic patch bump; validate all hashes, required runtime helpers, asset inventories and no forbidden data.
- [ ] Commit build/evidence, publish private system package and independently read back record.
- [ ] Synchronize requested public branches without force; verify all remote heads.

Constraints: no new SQL, no model calls, no live tenant modification or customer upgrade, no vendor edits, no unrelated worktree files, no changes to release manifest/signature/runtime protocols. Preserve previous 1.0.394 archives. AI decoration defaults on; model-effect acceptance remains pending. This patch requires stopping processes for installation because it changes worker and updater code; subsequent packages use actual-content impact checks.
