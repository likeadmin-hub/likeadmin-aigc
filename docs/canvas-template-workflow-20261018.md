# Canvas template workflow

Branch: feature/agent-optimization. Source-only change; no provider calls or billing.

- Tenant template detail accepts `sources=1`, paginated keyword search, and optional `source_project_id` for page choices. Existing template read permission applies.
- Template publish retains catalog revision checks and accepts explicit `refresh_workflow`, `source_project_id`, `source_page_id` per card. Under the existing tenant transaction it stores an independent sanitized graph. Metadata-only edits retain the snapshot. Templates without a snapshot remain preview-only.
- Existing user project/create accepts `template_id`, `request_id` (16–96 safe characters), and optional mode `insert` plus owned `project_id` / `page_id`. Create returns a project and durably deduplicates the request by tenant/user. Insert returns fresh nodes/edges for the editor's existing undo and durable-save flow. It never mutates the target project server-side.
- Reuse existing registered routes and permissions; no new menus, APIs, tables or columns. Snapshot and receipt entries use existing tenant_config, type `aigc_canvas_template`. Snapshot JSON is limited to 60KB to fit existing TEXT storage across install/bootstrap/upgrade. Card IDs are hashed into bounded config names.
- Published graph is capped at 200 nodes / 500 edges. Copy only editable prompt/model fields and scalar advanced controls, internal node mentions, layout and groups. Strip jobs, output media, external asset references and credentials. Published covers remain explicitly selected tenant material-center files. Admin must review prompt text before publication.
- Public website config exposes only workflow availability, version and node count. Disabled/deleted cards cannot be used. Removing a source project does not invalidate its published snapshot.
- Imagine reference screenshot guides the image-first five-column catalog, category pills, search, left rail and floating canvas panel. Responsive layouts collapse columns. Existing light/dark theme remains supported.

Validation: CanvasTemplateWorkflowTest.php uses rollback fixtures; frontend template manager tests and placement tests cover source selection, failed saves, graph positioning and invalid identities. Run only on locally integrated develop per AGENTS.md.
