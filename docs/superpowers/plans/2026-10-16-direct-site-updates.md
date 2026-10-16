# Direct site updates implementation plan

Spec: ../specs/2026-10-16-direct-site-updates-design.md (approved by user).
Execution: native in this session; user explicitly requested direct implementation, full testing and release.

## Global constraints
Keep package signatures, exact upgrade chain, SQL journaling, tenant data and unrelated site processes intact. No dependency repository, hard links, shared vendor tree or recurring full generation. Preserve legacy 402 upgrade/recovery until canonical migration is confirmed.

## Task 1: Direct transaction and HTTP guard
Files: app/common/service/update/DirectSiteUpdateService.php, direct-runtime-loader.php, runtime-loader.php.
Interfaces: DirectSiteUpdateService(root, php, runner), apply(extract, manifest, callbacks): array; recover(confirm): array; enabled(root): bool; acquireReader(root): resource; legacy migration methods.
Write filesystem/process fixtures first: two code updates create no .composer-runtime; unchanged vendor inode/bytes retained; complete dependency replacement deletes obsolete files; thrown completion restores exact files; committed reply loss is recovered; SQL indeterminate cannot replay; concurrent writer/reader rejected; protected paths rejected.
Use one root-local update lease, durable phase journal and replacement-only backups under runtime/update_workspace. Atomic file replacement and complete directory replacement. After copy, run migrations and completion in a fresh interpreter. Keep small terminal receipts; remove temporary payload/backup after confirmation. Gate HTTP during an active update; no business database access in the loader guard.

## Task 2: Canonical loader and legacy handoff
Maintain candidate overrides for the real 402 updater. Ordinary new installs load root code/vendor without generation creation. Legacy complete migrates the effective candidate code/vendor to canonical paths using the same direct transaction before metadata commit; commit the direct marker only with successful completion. On rejection restore canonical paths and leave legacy recovery valid. Legacy cleanup checks this site's real selection/generation leases, never all-host PHP inventory; busy readers defer deletion without copying more data.
Test actual old 402 manager with new 403 completion, reply loss, rollback, root/CLI lazy load, active legacy readers, cleanup after exit and foreign PHP process.

## Task 3: System and app update integration
Files: SystemPackageUpdateService.php, AppPackageUpdateService.php, composer-runtime-runner.php, ComposerRuntimeUpdateService.php.
System/app updates use DirectSiteUpdateService when canonical. App/system share the update lease, verify the original archive again, preserve migration and final metadata transactions. Reuse existing preparation/completion in fresh CLI with canonical override. Preflight must not create .composer-runtime. Keep old manager solely for already-started legacy transition/recovery.
Run real disposable MySQL preflight/apply, app install/update and failure tests. Verify version markers only advance on successful finalization.

## Task 4: Packaging and documentation
Update official stager/verifier coverage for new loader/service. Keep existing composer_runtime transport schema for complete offline dependency replacement. Explain canonical installation, legacy transition and ephemeral backups in release standards and release notes. No customer Composer/network install. Add changes to source manifest and archive signatures.

## Task 5: Verification and integration
Run full PHP suite in disposable database, runtime failure/process tests, staging/package tests, relevant frontend tests and all five builds. Verify legacy 402 -> 403, another canonical update, missing reply and restart recovery, unchanged protected state, and byte-size cleanup. Commit task files and merge into local develop without unrelated worktree changes. Perform independent whole-branch review.

## Task 6: Release
Archive/promote neutral 403 mini-program, commit all generated outputs, stage official exact 402 -> 403 package. Verify ZIP/TAR signatures and parity, archive coverage, no protected data or build paths. Preflight real update-source selector and wrong-predecessor rejection. Publish private system record, read back metadata and verify downloaded hash. Push approved isolated server tree to Gitee/GitHub develop/master and frontend source to Gitee develop/master, then verify all remote heads. Report customer installation and tenant WeChat publication separately.

## Review focus
Legacy completion after parent death; dependency replacement while a root-vendor legacy reader exists; no SQL replay after interruption; HTTP recovery when dependencies are temporarily absent; no archive success marker copied ahead of metadata commit.
