# OEM official page (template v9)

- Public PC route: `/official/oem`, with tenant query and tenant-prefix support.
- `affiliate` navigation becomes `oem` once on v7 → v8. Later OEM label/link/order/visibility edits are preserved. Home copy, testimonials, uploads and remaining navigation retain their values.
- New modules: `oem_hero`, `oem_intro`, `oem_clients`, `oem_features`, `oem_packages`. Outside the homepage key list. Existing tenant read migration and fresh defaults share the same source; no SQL is required.
- Tenant editor has page-level tabs and a shared configuration tab. Shared brand, navigation, promo and footer data remain a single source.
- Public pricing reads the existing tenant-scoped `/brand.package/lists`; it shows only shelf-enabled packages with available quota and uses `tenant_sale_price`. Opening a package routes to existing `/join-opc` with the selected package ID. Creating/paying orders and granting quotas are unchanged.
- Only reference icons remain bundled in PC `public/oem-enterprise`. Photos/videos/posters/backgrounds belong to tenant Material Center → 官网 and are selected in the OEM editor. New defaults leave these fields empty.
- v9 clears retired local OEM media paths, preserving tenant-uploaded URLs, icons, homepage content and package contracts. Existing bundled media must be uploaded and its config references replaced before removing frontend files. Each tenant owns its storage; do not copy tenant 1 URLs into defaults or migrations. No media/database content is shipped in source commits.
- Update backend, PC frontend and tenant frontend together. No compiled output is included in feature commits.

## Checks

Run `OfficialSiteConfigTest` and `OfficialSiteRouteTest`, then validate desktop and narrow layouts, hover/focus case cards, scroll/click feature selection, reduced motion, empty package state and editor save/read round-trip. Do not create a paid order merely to preview the layout.
