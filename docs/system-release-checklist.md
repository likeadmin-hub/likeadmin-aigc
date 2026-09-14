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
