# Server-rendered authentication pages

These pages are short transitions within the existing Chinese mobile login flow,
not a separate identity or sign-in system. `OfficialAccountOAuthRelayService::navigationPage`
owns both the WeChat entry and return states. Do not render account details or credentials.

Use the mobile login's purple family, Chinese system fonts, a quiet pale background,
and a centered white surface. The runtime CSS variables in that renderer own these
scoped tokens: primary `#9238e8`, primary soft `#f3eafa`, text `#242232`, secondary
text `#706c7d`, surface `#ffffff`, canvas `#f8f7fb`, border `#ece8f1`.

Use one status heading, one description and a visible 48px continuation link.
Automatic navigation remains immediate. Preserve an accessible fallback without
JavaScript; after a delayed transition, replace the indefinite waiting message
with retry guidance. Honor safe areas, reduced motion, keyboard focus and 320px
screens. No external font, image or UI framework is needed for this brief transition.

Do not change OAuth state, browser binding, expiry or redirect validation for visual
reasons. The management authorization pages remain a separate workflow.

| Capability | Canonical owner | Source of truth | Allowed variants | Verification |
|---|---|---|---|---|
| Navigation | `OfficialAccountOAuthRelayService::navigationPage` | Validated OAuth relay target | Enter WeChat / return to app | `tests/wechat/official-oauth-http.py`, browser continuation link |
| Async status | Same renderer and its local CSS tokens | Current relay phase | Initial / delayed navigation / no JavaScript | Browser mobile layout, delayed status and reduced motion |
