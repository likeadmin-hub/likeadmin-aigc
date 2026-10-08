# OEM reference icons

Source: https://higgsfield.ai/enterprise (retrieved 2026-10-03).

Only icons/brand marks remain bundled:
- `fortune-logo.png`: hero badge. The former suite logo is removed and must not be restored as a preset.
- `logo-primary-*.webp`, `logo-secondary-*.webp`: client brand marks.
- `mcp-pill.png`: MCP application icon strip.

OEM photos, backgrounds, composition images, video posters and videos are managed by each tenant in Material Center → 官网. They are not shipped as frontend assets or shared across tenants. Select them in Official Website → OEM贴牌. New installations keep the layout/copy/icons, with empty media fields. Never add a tenant's storage URLs as global defaults.

Marketing, cinema and MCP preview geometry remains implemented in Vue; operators can select uploaded composition images and overlay videos, or use a full custom preview. No third-party tracking, authentication or application runtime is included.
