# 短视频去水印前端入口

PC entry: `/ai/tools/aigc_watermark_removal`

Tenant admin entries:

- `/app/aigc_watermark_removal`
- `/app/aigc_watermark_removal/task`

The package includes standalone runtime assets (`pc.js`, `tenant.js`, `style.css`, `admin.css`, and `pc-entry.html`). The app manifest deploys these files to `public` on install/update, so the routes are usable immediately after the package is applied, including deployments that serve static tool directories before the Nuxt fallback.

The page calls `config/detail`, `generate/estimate`, `generate/index`, `task/lists`, and `task/delete`. The API accepts a short-video share `url` plus optional `market_product_id` and `market_sku_id`; the config response exposes the sellable `watermark_removal/remove` application API.
