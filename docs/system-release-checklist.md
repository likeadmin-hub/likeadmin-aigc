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

Publish the updater bridge 1.0.391 before functional 1.0.392. Customers must switch the website and its corresponding task processes to PHP 8.2 and gracefully restart those processes before the functional update. Other sites keep their existing PHP configuration.

`files/composer.json`, `files/composer.lock` and `files/vendor` remain forbidden. The next step declares `composer_runtime` schema 1, `payload_dir: composer-runtime`, `min_updater_version: 1.0.391`, `min_php: 8.2.0`, required extensions and probe classes. Deliver matching manifests and a complete no-dev vendor under that separate signed directory. The updater probes the runtime offline and atomically selects an immutable code/dependency generation. Existing requests stay pinned to their generation.

Run staging, package validation, fault injection, process pinning and the full regression suite before publication. A failed file/finalization/version phase restores code, dependency selection and markers. Successful additive SQL is durable and is not replayed on retry; interrupted SQL requires explicit recovery. No customer-side Composer resolution is needed. Generate staging with `scripts/prepare-system-update.mjs`, then use the normal official packager version bump.
