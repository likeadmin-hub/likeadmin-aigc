# System Release Checklist

Before publishing a system update package, run:

```bash
node scripts/verify-system-update-package.mjs runtime/release_packages/system/<version>/system_<version>.zip
```

The check intentionally requires these compatibility paths:

- `sql/data/`
- `sql/structure/`
- `menus/`
- `rollback/`

Older installed updaters validate those paths before applying an update, even when a release has no data SQL. Keep a marker file such as `sql/data/README.md` in empty SQL directories so zip extraction preserves the directory.

The existing-install version marker `upgrade/version.json` is updater-owned state, not a runtime payload file. Exclude it (and the legacy `public/upgrade/version.json`) from system `files/`; the updater writes the new version only after all migrations and application synchronization succeed. The install-ready server repository still contains the target version. Record this deliberate package/repository difference in the release manifest.


## Direct 1.0.390 to complete 1.0.391

Publish one customer-visible complete 1.0.391. The current release does not require an intermediate bridge or a 1.0.392 customer operation. Use the actual 1.0.390 Git tree as the staging baseline, with the complete 391 source and all five built terminals as the target.

Before the customer starts the update, switch this website to PHP 8.2, disable this project's Supervisor/cron automatic launches, let current tasks finish, and stop its task/cron processes. The update checks Linux `/proc` without signalling processes; a matching live process or an unreadable/hidden process inventory blocks activation. PHP CLI 8.2 must be executable by the website and `proc_open` available. Do not alter global PHP settings or other projects. After success, start this project's workers and cron with PHP 8.2 and re-enable its supervision.

The official stager's `--runtime-bootstrap` mode creates a signed legacy-compatible outer incremental package for exactly 390 → 391. Outer `files/` contains only the PHP 8 compatible `PcWechatMigration.php` wrapper generated against the real baseline. Outer SQL contains only the existing WeChat migration marker plus the bootstrap marker and a fail-closed completion guard. There are no outer business migrations, entrypoint replacements, worker patches, Composer files, public assets, app metadata or frontend payloads. If the wrapper was already loaded by the old interpreter, the guard rejects the update before the old updater can falsely mark success.

The complete `bootstrap-payload/` contains the new updater/loader, feature code, frontend replacements, all business SQL and the normal six built-in app payloads. Its separate `composer-runtime/` contains matching Composer manifests, a complete no-dev vendor and installed/autoload/service metadata. Inner `composer_runtime` stays schema 1 with `min_updater_version: 1.0.391`, `min_php: 8.2.0`, extension and class probes. Do not lower the updater requirement to pretend the old updater supports dependency installation. The capable signed helper explicitly takes over that operation in a fresh interpreter. The outer signature covers every inner file and the inner signature itself; hidden frontend artifact metadata retains the existing exact allowlist.

`signature.json` is a per-file integrity inventory, not an independent RSA signature. Online authenticity comes from the real RSA-verified grant bound to the archive SHA-256; nested inventories and complete coverage prevent partial payload changes. An explicitly authorized local deployment must verify a trusted artifact digest and all file inventories, without fabricating a remote grant.

The old 390 updater performs its real archive/grant/signature preflight. The wrapper verifies helper checksums before execution, delegates to a fresh PHP 8.2 process, and returns terminal JSON after confirmation. It never resumes the old completion/catch tail. The fresh process probes the complete candidate offline, records SQL once, and installs immutable code/dependency generations. Existing requests retain the old canonical business code/vendor. Candidate metadata synchronization, package/task completion and version-marker writes belong to the final fresh database transaction. A filesystem failure or rejected metadata transaction restores public files, pointers and markers. Successful additive SQL remains applied and is not replayed; interrupted SQL is indeterminate and blocked for explicit recovery.

A writer lease is inherited by candidate processes. A killed parent cannot make another request restore files while its candidate transaction is alive. After candidate exit, recovery checks the database, pointer and durable journal and either adopts the confirmed commit or restores the previous generation. Lost child replies after commit also require fresh confirmation. Ordinary bootstrap without an installed pointer uses canonical paths and does not create runtime state.

The compatible outer migration wrapper may remain after a failed update. Genuine 390 cannot capture customer-local original bytes before its own file-copy phase. This wrapper does not replace normal request entrypoints, workers or business code. Do not claim a byte-for-byte rollback of that one outer compatibility file. Never replace the site's vendor in place or run customer-side Composer/network dependency resolution. Preserve `.env`, `config/install.lock`, runtime task data, uploads and other projects.

The official stager generates signed `config/runtime_migration_baseline.php` from the genuine 390 Git object's migration paths and SHA-256 bytes. A historical file with changed bytes is rejected and requires a new migration filename. Both preparation and completion adopt identical historical migrations without executing any of their SQL, including dynamic SQL, channel/pricing seeds and task repair DML. Adoption is recorded only in the final metadata transaction. Known missing structure is covered by the explicit current cross-version additive repair SQL; do not rerun historical scripts to repair unknown customer schemas. Only the two new canvas CREATE TABLE migrations belong to this release's app migration delta. New app migrations may use static additive CREATE TABLE/ADD COLUMN or INDEX, and static DML inside finalization. Dynamic SQL/session variables, transaction-control statements and mixed destructive ALTER clauses are rejected before any statement executes. Fresh preparation and completion also verify that metadata tables, split tenant menus and new DML targets use InnoDB; unknown or nontransactional targets block the update without changing customer engines.

After activation, the app-center updater must install changes into a new generation under the same runtime writer lock, preserve the previous generation, and commit app metadata with pointer selection. Verify remove/reinstall of the same signed ZIP after another app update, failed metadata/retry without SQL replay, and lazy class loading from both old and new processes.

Build with the official tools (fresh staging/output directories, normal predecessor-to-target version bump):

```bash
node scripts/prepare-system-update.mjs --base-ref <real-390-commit> --base-version 1.0.390 --version 1.0.391 --runtime-bootstrap --composer-source <verified-production-composer> --notes docs/releases/1.0.391.md --out-dir <fresh-staging>
python3 /Users/likeadmin/.codex/skills/likeadmin-aigc-saas-update-packaging/scripts/package_update.py system --repo <directory-containing-server> --source-dir <fresh-staging> --out-dir <fresh-output>
node scripts/verify-system-update-package.mjs <fresh-output>/system_1.0.391.zip
```

Before publishing, run the focused runtime/failure/process tests, official staging and packaging tests, and the real old-service database chain. The legacy fixture verifies the actual 390 service hash, uses its original vendor, real grants and signatures, and observes state from independent processes. It covers PHP 8.0 rejection, dependency failures, final metadata rejection and retry, cached old hooks, committed reply loss and a paused completion transaction across parent death. Synthetic runtime callback tests alone do not prove old390 compatibility.

```bash
node --test tests/system-update/staging.test.mjs
python3 tests/system-update/composer-packaging.py
python3 tests/system-update/runtime-bootstrap-packaging.py
python3 tests/runtime-updater/system-service.legacy.py --archive <official-391.zip> --baseline <genuine-390-server> --app-packages
```


## 1.0.403 onwards: canonical site updates (supersedes immutable-generation rules above)

The 390→391 bridge remains a historical compatibility contract. Starting at 403, ordinary system and app updates run from the site's canonical app/config/route/extend/vendor directories. No new `.composer-runtime` generations, shared dependency repository or hard links are created. Unchanged vendor is retained; changed dependencies are carried in the existing signed `composer_runtime` schema-1 transport and replace the complete vendor directory offline. Composer is never executed on customer sites.

A site-local reader/writer lease held by HTTP and ordinary CLI readers prevents requests from observing partial replacement. Existing worker impact gates still require relevant long-running tasks to stop before affected code is changed. Only replaced paths have temporary rollback copies under runtime/update_workspace; watched completion side effects are included once and cannot overlap a full-directory replacement. Durable SQL state blocks indeterminate SQL replay; metadata completion and version markers are confirmed in a fresh PHP process before final adoption. Files roll back after rejected metadata, and a lost committed reply is independently confirmed rather than rolled back.

A genuine 402 updater may create its final transitional candidate. The new completion helper materializes that selected effective code/vendor into canonical paths under the same transaction, retaining protected .env, install.lock, uploads and task state. A failed metadata transaction restores canonical files. Legacy parents retain their journals and recovery protocol until confirmed completion. Legacy snapshots are retired only after this site's actual update/selection/generation leases are free; foreign PHP processes never prevent cleanup. Quarantined retired storage removal resumes after interruption. New receipts are small metadata, not code/vendor copies.

Before release run direct-site.php, direct-legacy.php and direct-crash.php; the canonical and genuine402 real database service matrix; actual app install/remove/reinstall after an intervening second app update; metadata rollback/retry with migrations once; and official staging/package verification. Package all new loader, service and cleanup paths via the normal source diff. Exclude runtime journals, locks, temporary backups and `.composer-runtime` from archives. Normal installed version markers remain updater-owned and excluded from files/.
