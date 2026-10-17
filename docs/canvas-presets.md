# Canvas presets

Tenant presets remain in the current tenant's catalog and graph snapshots. Publication now retains image/video/audio collections, active media, posters, references and media mentions as independent managed copies. It resets tasks and credentials. Old snapshots require republishing from the source canvas; merely editing their cover cannot recover media that was previously omitted.

Files are retained under `uploads/aigc_canvas/templates/{tenant}/` using the effective tenant storage service. They are content-addressed, separate from source uploads, and deliberately not removed when a source project or catalog card is removed: existing project copies must continue working. Storage identities are persisted, and private read signatures are refreshed when instantiated or reloaded. Publication rejects transient files, unsupported content, private-network downloads and known foreign-tenant file references. The per-file image limit is 20 MiB, other media 100 MiB, total 256 MiB and 100 files per preset. Template JSON remains limited to 60 KB; bytes are never embedded in it.

## Source-delivered builtins

`app/apps/aigc_canvas/data/presets/*.json` is an independent read-only catalog. Each definition has a stable `builtin-*` ID, schema/version, cover, description and graph. Media is public HTTPS object-storage/CDN content with no temporary query signature. Neither media bytes, tenant IDs, local database model IDs, tasks nor storage credentials are shipped in this directory.

The PC portal merges this catalog with the tenant's own enabled cards. Tenant administration receives `builtin_cards` separately from its editable `config.cards`. The reserved `builtin-*` namespace cannot be written by tenant catalog APIs. There is no platform public-template management page or API.

Reading the source directory applies equally to fresh installations, existing installations after source update, and newly created tenants, including tenants without catalog config rows. No database seeding or schema migration is needed. Source updates do not overwrite tenant presets or already-instantiated projects. Application access/open/shelf middleware continues to control use.

### Export in tenant administration

Open **无限画布 → 模板管理 → 导出为内置预设**. Save edits in the canvas first, then select the source project and page. Alternatively, click **导出为内置预设** on an already-published tenant template; this exports its published snapshot, so republish first if its source has changed.

Choose **新增内置预设** for an automatically generated stable ID and version 1, or **更新已有内置预设** to retain a selected installed builtin's ID and increment its version. Fill in the name, category and description. A cover is optional; the first image in the selected canvas is used automatically when no cover is selected. Confirm that the selected media may be public, then download the JSON. The exporter retains independent public object-storage copies and checks their anonymous accessibility. Transient, foreign-tenant or inaccessible files fail export without installing anything.

Put the downloaded file, with its original filename, into `app/apps/aigc_canvas/data/presets/` on the task feature branch, review it and commit it with the source. An update replaces the file with the same ID. No JSON editing is required. Other installations receive the preset when they update their source. Merely exporting a definition does not change the running builtin catalog, tenant catalog, source canvas or existing user projects. The source directory is never written by HTTP.

The operation uses the existing authenticated tenant `POST app.aigc_canvas.template/publish` permission with `action=export_builtin`; ordinary publication without this action is unchanged. Export accepts `source=canvas` plus `source_project_id`/`source_page_id`, or `source=template` plus `template_id`; `target=new|update`, optional `builtin_id` for update, title/category/description/cover_file_id, and explicit boolean `confirm_public_media=true`. It returns `{filename,preset,media_count}` in the normal success envelope. The server assigns the exported ID/version and ignores browser-supplied identity/version overrides. No new API path, permission, database migration or platform management is introduced; fresh install, new tenant and existing installation use the same code path and existing publication permission.

### CLI alternative

To export a published tenant template from the integrated local `develop` runtime, inspect and then export:

```sh
php scripts/export-canvas-builtin-preset.php --tenant=1 --template=CARD_ID --id=builtin-example --title='Example'
php scripts/export-canvas-builtin-preset.php --tenant=1 --template=CARD_ID --id=builtin-example --title='Example' --write
```

The explicit write copies selected demo files to the currently configured public object storage under `uploads/aigc_canvas/builtin/builtin-example/` and creates `runtime/canvas-presets/builtin-example.json`. Switch back to the feature branch, review that definition, and copy only the JSON into the source catalog. Do not commit runtime output or uploads. Include this JSON in a normal source commit; this is not a version package/publication workflow. Deployments may mirror public demo files to their own CDN by updating the source definition's stable HTTPS addresses. Tenant-private files are never automatically included.

Rollback: revert the source commits to remove the builtin catalog feature. Existing user projects remain independent copies. Retain referenced object-storage demo files and tenant template copies; do not delete them as part of rollback or soft uninstall.
