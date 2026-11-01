# 画布兼容与升级说明

[返回项目首页](../README.md) · [部署与运维指南](deployment-and-operations.md)

以下记录用于已有画布项目的会话隔离、历史转换及升级核对。

## Canvas project Agent isolation

Canvas conversation sessions remain page-bound; generation preferences use the owned project key `canvas:{project_id}` (shared only by pages in that project). The additive source migration snapshots legacy user-wide defaults once for each existing project. Runtime initialization covers projects created later without rewriting submitted run snapshots.

Conflicting query/body identifiers are rejected before scope selection. Project deletion cancels remaining Agent work; admission and result collection lock the owned project before run records. Already admitted native jobs retain their task identity and continue result polling, while deleted pages reject graph writeback. Removing a node or media reference only changes the canvas graph; deleting an original asset in the user library affects all references and has a separate confirmation.


## Canvas retirement upgrade audit

- Deploy the shared backend and matching PC/tenant frontend sources together. An app-only package copies `app/apps/aigc_canvas` and cannot carry the shared controllers, migration executor, or Agent engine. This change requires a system/source update; publishing remains the repository owner's responsibility.
- Include deleted legacy PHP/frontend source files in the updater deletion set. Overlay copying alone preserves obsolete controllers; the database API/menu cleanup alone is not a route authorization guard. API and tenant middleware also reject retired canvas routes when obsolete files remain, while keeping native asset registration compatible. Drain the old canvas worker before replacing code and start only the current configured workers afterward.
- `zz_20261017_00_conversation_preferences.sql` prepares older preference schemas before project-specific snapshots, even when canvas updates before the conversation app. The reviewed `CanvasConversationSchema` bridge recognizes only the exact historical scope migration bytes, so immutable migrations remain unchanged and other dynamic SQL remains forbidden by the runtime updater.
- History conversion is tenant/user/project scoped and lazy on first authorized project access. Original graph snapshots and legacy conversation tables remain for recovery and idempotent import; do not remove them as unused runtime code. History import never resubmits generation or recharges a task.
- Rollback: preserve additive columns, indexes, backups and imported records. Do not restore the old two-column preference unique index after multiple project rows exist. Returning to the old application requires a coordinated code/database backup restore, not a destructive down migration.
