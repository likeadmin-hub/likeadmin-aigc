# Application availability regression coverage

Run `python3 tests/app-access/switch.integration.py` from the server repository. The runner creates a random MySQL container, verifies its server UUID, masks `/app/.env`, and mounts runtime as tmpfs. It never connects to the application database and performs no paid generation.

## Availability contract

User navigation and new user execution require the app to be installed, tenant entitlement valid, tenant shelf on, tenant enable on, both platform and tenant display switches open, and both owning service switches open where present. An explicit platform OFF cannot be overridden by a tenant ON. Missing display/service rows retain the owning defaults. There is no authorization result cache, including in long-lived workers.

`enabledTenantAppCodes()` and `frontendAppCodes()` apply these controls. The existing GEO market preview remains display-only until a valid execution entitlement exists. Purchased and tenant management lists do not apply display/service gates, so administrators can still view and reopen closed apps. Existing task/result/history exceptions stay owner-scoped in their original controllers.

API middleware, homepage Agent capability authorization, canvas/native adapters which call the shared gate, and published OpenToolAdapter admission therefore read the same current controls. Existing accepted work is not canceled; historical read exceptions retain their previous behavior.

## Owning field inventory

All registered apps, including future/custom apps, honor `tenant_app_config.status` for scopes `tenant_id=0` and the current tenant. An absent row means display open. These fields are separate from app registry status, tenant shelf/enable switches, resource channel/model statuses, and subscription expiry.

The following 29 apps each own `{app_code}_config.status`:

| Apps | Owning service switch |
| --- | --- |
| aigc_image, aigc_video, aigc_digital_human, aigc_llm | table status column |
| aigc_hairstyle, aigc_fitting, aigc_product_image, aigc_style_transfer | table status column |
| aigc_photo_restore, aigc_image_translate, aigc_one_click_cleanup | table status column |
| aigc_product_multi_angle, aigc_product_promo_video, aigc_action_transfer | table status column |
| aigc_outpaint, aigc_product_suite, aigc_local_redraw, aigc_person_replacement | table status column |
| aigc_short_drama, aigc_background_removal, aigc_fashion_lookbook, aigc_geo | table status column |
| aigc_model_wear, aigc_music, aigc_music_cover, aigc_watermark_removal | table status column |
| aigc_pic_lipsync, image_human, smart_clip | table status column |

Micro-video is the 30th source: `aigc_micro_video_config.config_json.status`, read with the default and numeric conversion used by MicroVideoSettings/MicroVideoService. A missing key defaults to 1; numeric/string zero, false, and explicit null close service. A `config_json.status` key on a status-column app is not treated as a switch.

Canvas and copywriting have no separate application service-status field; they use registry, tenant entitlement/shelf/enable, and display controls. `system_default` retains its protected installed registry lifecycle. Open platform additionally has its existing API-publication authorization (`open_platform_setting.enabled` plus `config.tenant_enabled`); its console intentionally exposes an `enabled` state and remains readable when API publication is disabled. This change does not reinterpret that API authorization as a console visibility control.

The source inventory is deliberately explicit and read-only. Each availability read discovers installed optional config tables with one metadata query, then reads all candidate owning fields in one UNION query; missing optional tables retain defaults. Neither missing-table metadata nor authorization decisions are cached, so same-process app installation immediately takes effect. Business config builders are never called during gate evaluation, avoiding lazy writes, provider catalog queries, and recursion.

## Matrix and preservation

The matrix exercises every shipped manifest app plus the protected system app, platform/tenant display OFF, service OFF, a tenant ON under platform OFF, absent defaults, tenant isolation, reopen, PC/UniApp/tenant entry sources, direct config and generation middleware rejection, Agent tool admission, published tool admission, and history middleware exceptions. Micro-video JSON variants and a same-process optional table installation regression are included. MySQL's private general log confirms a full catalog issues one service-switch UNION instead of per-app queries.

The pre-existing suite also verifies tenant switches survive default synchronization and actual updater SQL/DDL execution, owner management lists remain available, paid entitlement renewal survives updates, historical Agent events retain user ownership, and OEM contention requeues paid work. Current application migrations and system upgrade SQL were audited for unconditional UPDATEs of existing display/service status; none were found. This change does not write configuration or alter the existing updater snapshot contract.
