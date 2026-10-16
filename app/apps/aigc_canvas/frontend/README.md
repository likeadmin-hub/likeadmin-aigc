# 无限画布前端

- PC 入口：`/pc/app/aigc_canvas`
- 租户端管理：`tenant/src/views/apps/aigc_canvas`
- 平台端管理：`platform/src/views/apps/aigc_canvas`

无限画布不维护独立的生图、生视频、音乐模型配置。Agent 文本模型使用统一文本模型配置；图片、视频和音乐生成通过算力市场已上架的模型 API / 应用 API SKU 路由执行。

当前画布仅使用共享对话引擎，旧聊天编排、Skills 管理、治理、追踪与回放入口已退役。手动画布的提示词编译和提交校验位于 `app/common/service/app/aigc_canvas/prompt/`，资产登记使用原生生成控制器。

历史项目保持原 ID；打开项目时适配旧节点与页面索引，并将关联的旧对话一次性导入项目第一页（旧记录有有效页面标识时沿用该页面）。原表和原内容保留，迁移标记保证删除后的会话不会重新导入。历史任务和待执行操作不重放，不产生生成调用或扣费。原来的独立回放分享链接退役。

部署时先停止旧 `canvas:subagent-worker` 接收任务，核对已提交任务的最终结果，再更新服务；通用生成任务 worker 与共享对话 worker 继续运行。执行应用迁移后，标准租户旧菜单/API 声明清理；分表租户由既有默认应用菜单同步按新清单修复。可使用 `canvas:adapt-projects` 先检查数据，再用 `--apply` 批量适配已有项目。
