# 无限画布前端

- PC入口：`/pc/app/aigc_canvas`
- 租户端管理：`tenant/src/views/apps/aigc_canvas`
- 平台端管理：`platform/src/views/apps/aigc_canvas`

无限画布不维护独立模型/API配置，文本、生图与生视频执行分别复用 `aigc_llm`、`aigc_image` 和 `aigc_video` 应用。
