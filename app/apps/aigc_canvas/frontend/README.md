# 无限画布前端

- PC 入口：`/pc/app/aigc_canvas`
- 租户端管理：`tenant/src/views/apps/aigc_canvas`
- 平台端管理：`platform/src/views/apps/aigc_canvas`

无限画布不维护独立的生图、生视频、音乐模型配置。Agent 文本模型使用统一文本模型配置；图片、视频和音乐生成通过算力市场已上架的模型 API / 应用 API SKU 路由执行。
