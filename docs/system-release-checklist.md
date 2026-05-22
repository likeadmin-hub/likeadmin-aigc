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
