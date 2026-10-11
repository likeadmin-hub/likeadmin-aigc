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


## PHP 8.2 dependency upgrade

Publish the updater bridge 1.0.391 before functional 1.0.392. The official stager generates six worker/cron files from the real 1.0.390 `--base-ref`, adding only generation retirement checks at completed task/batch boundaries. It never copies functional worker implementations into the bridge, and refuses missing or ambiguous insertion contexts. Bridge SQL is empty; all business migrations belong to 1.0.392. Build the functional diff against a real Git tree containing only the delivered 1.0.391 bridge, so no functional changes are omitted.

Customers must switch the website and its corresponding task processes to PHP 8.2 and gracefully restart those processes before the functional update. Other sites keep their existing PHP configuration.

The bridge must declare signed `updater_bridge: true` alongside `package_mode: incremental`. Official staging `--bridge` emits the declaration. Package it with the official `package_update.py system --updater-bridge` command: it validates the exact 390 → 391 endpoints and 14-file updater/worker allowlist, requires empty SQL/delete/full-replacement lists, and skips automatic built-in app metadata/frontend/migration injection. No `.sql` file or Composer payload belongs in this bridge. Functional incremental packages retain the normal six-app injection and verification. The repository verifier enforces both modes independently.

`files/composer.json`, `files/composer.lock` and `files/vendor` remain forbidden. The next step declares `composer_runtime` schema 1, `payload_dir: composer-runtime`, `min_updater_version: 1.0.391`, `min_php: 8.2.0`, required extensions and probe classes. Deliver matching manifests and a complete no-dev vendor under that separate signed directory. The updater probes the runtime offline and atomically selects an immutable code/dependency generation. Existing requests stay pinned to their generation.

Run staging, package validation, fault injection, process pinning and the full regression suite before publication. A failed file/finalization/version phase restores code, dependency selection and markers. Successful additive SQL is durable and is not replayed on retry; interrupted SQL requires explicit recovery. No customer-side Composer resolution is needed. Generate staging with `scripts/prepare-system-update.mjs`, then use the normal official packager version bump.


Run the real old-vendor/process transition acceptance test (offline disposable Docker containers only):

```bash
node --test tests/system-update/staging.test.mjs
node tests/runtime-updater/bridge-process.mjs --baseline <real-390-server> --bridge <official-391-staging> --composer <production-composer-projection>
```

This test keeps a PHP 8.0 worker alive on genuine 1.0.390 vendor, activates the complete production runtime using PHP 8.2, checks that the claimed batch finishes before the old process exits, and checks that a fresh process loads Neuron. Its business service is a fixture; full public updater/SQL/billing acceptance remains a separate disposable-database test.
