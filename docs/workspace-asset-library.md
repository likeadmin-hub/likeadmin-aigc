# Account asset library

The PC asset page uses the existing app result readers and preview. Account metadata lives in the global `user_asset_state` table, scoped by both `tenant_id` and `user_id`; source records and files are not copied or deleted. This is a shared account service, like Appearance, not an installable AI app. It has no app manifest, menu or permission changes.

Core authenticated APIs: GET `/workspace_asset/state`, GET `/workspace_asset/uploads?page_no=1`, POST `/workspace_asset/update`, POST `/workspace_asset/upload`. Mutations accept up to 100 validated `source:kind:id` references and a partial `favorite`, `label`, or `action` (`trash`, `restore`, `remove`) patch. Every reference is resolved against its owned source record. No client file URL is trusted. Batch writes are transactional. Permanent removal retains a tombstone for the library, preserving creation history and physical media.

Run `upgrade/20261012_workspace_asset_state.sql` for existing installations. The same idempotent schema is included in `public/install/db/like.sql` and the public SQL mirror. New tenants need no seed row or private table: their first metadata change inserts an owned row into this shared table. No new columns are read from app tables and no app migrations are required. Upgrades must apply the SQL before enabling the new page; missing schema returns a recoverable UI error, not request-time DDL.

Uploads reuse `UploadService`, `TenantFile` and stored storage-scope/engine/domain metadata. MIME and size are checked before upload. Uploaded files and their signed/authorized URLs remain governed by existing storage rules. Generated sources retain their existing recent-results limits; saved favorites and trashed references are read independently so they can still be restored after aging out of recent results. Uploads load in pages of 100.

Reference: https://www.imagine.art/asset/all (inspected 2026-10-12). Matched date groups, compact square media grid, density slider, hover selection/actions, bottom batch toolbar, right-hand navigation, multi-select tool/model/ratio/label filters and calendar range. Requested exclusions: team/private folders, creator filter and navigation grid toggle. Existing system preview is retained. Reference-site publishing and folder move have no corresponding account-library contract and are not included.

Validation: `vendor/bin/phpunit tests/Unit/WorkspaceAssetTest.php`; local rollback-only integration: `WORKSPACE_ASSET_TEST=1 php tests/workspace-assets/integration.php`. Run only on local develop per AGENTS.md.

## Filter completeness follow-up

Tool filters use the PC system tool catalog (including tenant display overrides), keyed by app code. Separate sources cover picture digital humans, full-drive humans, digital-human video, image/video tools, music and clips. Hairstyle uses shared image storage; fitting results retain shared image references while displaying the fitting tool. Canvas media in short-drama storage is attributed to the canvas tool.

The library reads task/asset/upload pages to completion with bounded request concurrency. Image/video task controllers now forward pagination parameters; short-drama global assets add count/page metadata while keeping the lists field. Legacy non-paginated calls retain their defaults. Filters normalize nested metadata and equivalent ratios. Saved labeled records are also reconstructed when absent from the initial source results. No schema or billing changes.

## Local upload provenance

`tenant_file.upload_origin=local` is written only by multipart upload paths (general image/video/file uploads and music/digital-human audio). Generated and registered remote files retain the empty default. The idempotent upgrade marks legacy multipart directories and audio records with explicit upload evidence. No physical files are removed. Upload references, including saved favorites/trash, must meet the same provenance restriction. The PC All view excludes upload sources; local media belongs to Uploads (or Favorites when explicitly starred).
