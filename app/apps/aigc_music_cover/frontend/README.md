# 音乐翻唱前端入口

PC entry: `/ai/tools/aigc_music_cover`

Tenant admin entries:

- `/app/aigc_music_cover`
- `/app/aigc_music_cover/task`

The package includes standalone runtime assets (`pc.js`, `tenant.js`, `style.css`, `admin.css`, and `pc-entry.html`). The manifest deploys them to `public` during install/update, so the PC route works immediately and the current PC/admin shells can load the route overlays.

The PC page supports uploading both the original song and target-voice reference, or entering public URLs. It calls `config/detail`, `asset/upload_audio`, `generate/estimate`, `generate/index`, `task/lists`, `task/retry`, and `task/delete`. The tenant page exposes status control, market API readiness, task polling, retry, playback, download, and deletion. The config response exposes the sellable `seedsvc/submit` application API.
