# System 1.0.395 release

User-authorized scope: publish exact 1.0.394 to 1.0.395 system update including default AI decoration, unified worker lifecycle and actual worker-impact upgrade checks; synchronize server/frontend develop and master, plus server GitHub mirror.

- [x] Freeze approved local develop snapshots; commit version and release notes; checkpoint merge local develop.
- [x] Build PC, tenant, platform, H5 and mp-weixin through official scripts; validate 1.0.395 artifact and PC version.
- [x] Verify decoration/update lifecycle regressions, schema-free no-stop path, safe retry and package staging.
- [x] Stage against remote 1.0.394 SHA; package with automatic patch bump; validate all hashes, required runtime helpers, asset inventories and no forbidden data.
- [x] Commit build/evidence, publish private system package and independently read back record.
- [ ] Synchronize requested public branches without force; verify all remote heads.

Constraints: no new SQL, no model calls, no live tenant modification or customer upgrade, no vendor edits, no unrelated worktree files, no changes to release manifest/signature/runtime protocols. Preserve previous 1.0.394 archives. AI decoration defaults on; model-effect acceptance remains pending. This patch requires stopping processes for installation because it changes worker and updater code; subsequent packages use actual-content impact checks.

Release review found the bootstrap loader preferred an old canonical manager on sites originally installed at 394. The loader now chooses the selected/explicit candidate generation manager; the genuine390 pending-bootstrap fallback and ordinary no-pointer canonical path remain supported. A fresh child with genuine394 canonical manager and395 selected manager must run the latter and reject before SQL (SQL_CALLS=0). Superseded unpublished archives are retained under before-manager-pin-fix, and the final package must include the revised shared loader.

Private publication verified: record369, status1, beta0, exact394 to395, ZIP SHA2564e12159c873dac0e08c9858fc67391688a771ef023d34197b0f1fb1d6229820f. Remote public-branch synchronization remains the final step.
