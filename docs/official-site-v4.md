# Visual creation official site v4

## Source and content mapping

The public reference HTML, CSS and interaction scripts were retrieved from https://openart.ai/ on 2026-09-30. Source snapshots and media downloads are kept outside delivery repositories in `reference-assets/openart/` (relative to the workspace root). No external analytics, brand logos, customer endorsements or social accounts are embedded in the application.

Reference structure maps to real capabilities:
- Director → AI short drama; character → digital human; world → infinite canvas.
- Image, video, audio → existing image/video creator and music tool.
- Studio tools → product image, virtual try-on, photo restoration, smart clip, AI conversation.
- Model cards and menu → current tenant's available market image/video models; navigation uses the existing `channel` query parameter.
- Testimonials → configurable workflow cards without invented customer quotes or ratings.

## Configuration

Tenant → Official website: branding, theme, accent, fallback image, SEO, header navigation, announcement, hero media, carousels, scenes, feature rows, model overrides, tools, workflow cards, FAQ, guides, footer, CTA and information pages. All media starts with a single local placeholder when not configured. Videos are muted, autoplay, loop and pause offscreen.

The six menu entries are tools (studio/image/video), models, open platform, pricing, enterprise, help. Links accept internal paths or HTTPS destinations. Tenant and referral context is only added to internal links. `/official/open`, `/official/enterprise`, `/official/help` are configurable local fallbacks.

Motion: reference-derived hero bob/parallax/gather and expansion, looping card rail with hover/focus pause, scene crossfade, sticky feature navigation and scroll selection, tool accordion, FAQ, hover scaling and responsive menu. Reduced-motion preference disables automatic carousel and hero animation. No reference site's runtime code is executed.

## Contract

Existing official-site configuration API, schema version 4, additive public `model_catalog` allowlist and media/icon/model override fields. No SQL or permission changes. Runtime SKU/pricing/provider secrets are never included in `model_catalog`. Catalog is cached per tenant for 60 seconds. Existing custom text/media is retained where corresponding modules remain; untouched defaults upgrade to the new design.
