# 动作同步开发交接

## 范围与入口

- task：独立动作同步工具，`app_code=aigc_motion_sync`。
- branch：server、web 均使用原商品视频分支 `feature/aigc-refinement`，不覆盖商品视频或旧动作迁移应用。
- server scope：应用注册、五张业务表、统一接口、真实算力市场接入、异步任务、计费、托管素材、模板及统一资产。
- web scope：PC 创作工作台、独立移动创作/历史页、平台/租户配置、模板和记录管理、共享工具/资产入口。
- PC：`/ai/tools/aigc_motion_sync`；移动：`/apps/aigc_motion_sync/pages/creator/index`、`/apps/aigc_motion_sync/pages/tasks/index`。租户访问沿用系统上下文。
- 后台从应用中心的“管理”进入；实际菜单路径为 `/aigc-motion-sync/config`、`cases`、`jobs`，组件位于 `apps/aigc_motion_sync/`。

最短流程是上传一张角色图片和一个动作视频，选择真实上架的模型、清晰度、人物朝向，查看报价并生成。描述可选。无需分镜、商品信息或辅助分析任务。

## 模型文档与能力边界

2026-11-10 通过算力超市 `UpstreamPricingService::queryDeveloperDocDetail` 读取了两份完整的公开接入文档及结构化参数声明：

- [Kling 3.0 motion-control](https://api.likeadmin.cn/user_center/docs?slug=model-kling-3.0-motion-control)，上游文档 520、模型 339。
- [Kling 2.6 motion-control](https://api.likeadmin.cn/user_center/docs?slug=model-kling-2.6-motion-control)，上游文档 533、模型 346。

上面的数字仅用于记录文档来源，运行时不以数字 ID 或显示名称匹配模型。准入要求模型资源、`other` 分类、官方渠道，以及稳定的 `kling-2.6-motion-control` / `kling-3.0-motion-control` 标识同时匹配。仅在此应用内开放，普通视频创作的模型目录不扩入这些资源。

| 参数/能力 | 实际处理 |
| --- | --- |
| `input_urls`、`video_urls` | 各一个，服务端从有权使用的上传回执生成地址 |
| `prompt` | 可选，最多 2500 字符 |
| `mode` | 按真实可售 SKU 的锁定参数生成，支持的清晰度由目录返回 |
| `character_orientation` | `video` 或 `image`；明确传值，不依赖供应商隐式默认 |
| 3.0 默认清晰度 | 文档默认 720p；只有真实存在且上架时才采用，否则选择实际可售规格 |
| 2.6 图片要求 | JPG/JPEG/PNG，不超过 10MB，宽高均大于 300px，宽高比 0.4～2.5 |
| 2.6 视频要求 | MP4/MOV，不超过 100MB，3～30 秒；图片朝向模式最长 10 秒 |
| 3.0 素材限制 | 该份市场文档没有明确公布尺寸、文件大小和时长限制；本工具采用 JPG/PNG 10MB、MP4/MOV 100MB、3～30 秒（图片朝向最长 10 秒）的保守产品输入范围，不冒充 3.0 文档声明，也不继承 2.6 图片尺寸要求 |

视频时长来自服务端实际探测，前端提交的时长和存储元信息不能作为授权或计费依据。生成请求跟随输入动作视频，不向上游虚构可选生成时长或画幅比例。

当前算力超市接口没有声明保留原声、生成音频、独立音频、首尾帧或角色元素参数。因此不展示或发送这些控制，也不承诺复刻 OpenArt 的全部选项。更换为 3.0 不代表当前市场接口就获得官方其他产品中的全部增强能力。

## 接口、状态和计费约定

完整 API 声明见 `app/apps/aigc_motion_sync/api_schema.json`，控制器均走系统 `/app/{app_code}` 分发，不直接面向供应商：

- `config/detail,models,setup`：配置与真实模型规格；写入仅供管理员。
- `project/detail,save,fromJob`：当前用户独立草稿、修订冲突保护、已终结任务再次编辑。
- `job/estimate,create,detail,lists,cancel,recover,delete`：报价、生成、历史和状态处理。
- `case/lists,detail,remix,save,fromJob,status,delete`：公开灵感、完整同款和有权限的后台模板管理。

报价有效期 180 秒，绑定租户、用户、项目、修订号，单次消费。提交重新检查实际价格和预占后的冻结快照；提交标识由租户/用户隔离并保持幂等。浏览器丢失创建响应时保留原标识和原请求，按钮转为“核对本次提交”，不会用新标识重复付费提交。

状态为 `queued / running / unknown / success / failed / canceled`。业务记录 ID、共享系统任务 ID、供应商任务 ID 分开记录；尚未受理不会伪造供应商 ID。排队取消仅允许在提交开始标记之前。运行中或结果未知的任务不能删除、再次生成或假定退款。

`motion_sync_submit` 是持久队列类型。控制器事务仅保存业务记录、调用共享预占和入队，不执行供应商 HTTP。已消费的开始标记不会清除；传输超时或进程中断后不能自动重新付费提交。已有上游回执的任务通过公共查询/回调、结果转存和结算继续推进；未知且无回执的任务保留审计，需核对上游受理结果。

复用 `MarketVideoRuntimeService`、共享点数账户和结算账本；不直接修改余额。成功要求费用已结算且所有预期视频已托管，再幂等投影作品。供应商明确失败/未开始的取消通过公共链路退回冻结；不把 HTTP 接单当成作品成功。多输出视频逐个保存，不只取第一个。

## 素材、模板和共享改动

上传适配遵循当前接口真实字段：`uri` 是完整展示 URL，`url` 是存储键。共享 `packages/creation-studio/media.ts` 统一转换；商品视频使用相同适配并通过素材回归测试。草稿不持久保存 blob 地址；系统现有会话草稿用于切换工具后恢复，刷新浏览器不保证恢复同一会话内存。

服务端核对真实文件归属、存储范围、引擎和域名。`ManagedStorageReader` 仅读取已授权、与原配置匹配的托管对象；阿里云经现有 SDK 读取桶内对象，并限制大小和超时。它不提供任意 URL 代理，不降低公共 URL 的 SSRF 检查，也不改用其他租户的凭据兜底。视频校验新增的读取回调是可选项，原有调用者默认行为不变。元数据缓存故障不会阻断授权素材校验。

作品通过 `motion_sync` 来源接入统一资产查询、选择器、预览和管理，保存完整 `storage_scope/storage_engine/storage_domain`。历史删除是软删除，保留账单、模板、上传回执和共享物理对象。

后台模板支持手工上传完整素材及预览、编辑、查询、状态、排序、删除，以及真实成功记录转模板。记录转模板读取提交快照，不读取后来修改的草稿。同款只建立素材权限元数据和当前用户独立草稿，不复制 OSS 文件、不调用模型、不扣点、不静默替换不可用模型。

平台模板在本站租户间共享。本次未提供跨独立部署的内置模板发布/源码同步机制，也未交付带授权公开素材的预置模板。不能将本站数据库模板称为跨站点内置模板。

共享回归范围还包括公共任务派发、结果投影、视频资源准入、素材选择器和后台上传组件。`MicroVideoSubmission` 保留兼容门面和既有回执键；非动作同步视频目录和原调用签名继续有效。PC 使用公共素材、提示词、模型选择、紧凑参数、结果页签、灵感卡片和作品预览；移动端使用独立纵向表单与公共底部操作区，五个装修 Tab 宿主均有实际内容组件。

## 安装、升级与运维

- 五张表：`aigc_motion_sync_config/project/quote/task/result`，任务、结果、报价和项目均有租户/用户隔离；灵感复用公共 AppCase。
- 应用自带幂等 `migrations/install.sql` 和 bootstrap；既有站点增量源码为 `upgrade/upgrade_20261110_aigc_motion_sync.sql`（与 `public/upgrade` 同步）。全新安装 SQL 同步维护。
- manifest、平台/租户菜单权限、统一 API、PC/平台/租户/UniApp 入口和移动装修 `meta.pages` 已声明。
- 当前默认应用政策会阻止默认应用的平台禁用/卸载；此工具沿用该政策，不声称这些被系统禁止的操作可执行。租户上下架独立保存，重复安装不得覆盖租户的下架选择。
- 清理逻辑保护活动任务、审计和共享素材；不要通过手动删表或删文件处理未知任务。回退前停止新提交、处理所有非终态账单，保留业务表、公共任务/账本与上传对象，避免让旧代码接管新应用任务。
- 运行依赖正常的公共队列工作进程、结果查询/回调和结果转存配置。CLI worker 使用已验证的提交素材地址；本地存储须确保供应商能够访问，localhost 地址不能作为生产素材地址。

## 验证与未验证边界

遵循项目规则：源码先在 feature 提交并合入本地 develop，以下测试/构建仅在对应本地 develop 执行。命令中的 PHP 为容器 PHP 8.2；测试不调用付费模型。

```sh
php vendor/bin/phpunit tests/Feature/MotionSyncRuntimeTest.php
php vendor/bin/phpunit tests/Feature/MotionSyncMediaTest.php
AIGC_VIDEO_PREPARATION_DB_TESTS=1 php vendor/bin/phpunit tests/Feature/VideoCatalogAdmissionBudgetTest.php
MOTION_SYNC_LOCAL_TEST=x_cn php tests/motion-sync-local-flow.php
MOTION_SYNC_LOCAL_TEST=x_cn php tests/motion-sync-install.php
# web/pc
node --test tests/motion-sync-workflow.test.mjs tests/product-video-materials.test.mjs tests/asset-sources.test.cjs tests/creation-tool-switcher.test.cjs
nuxi build
# web/uniapp：直接编译，不能运行带发布/复制步骤的 build 包装器
node node_modules/@dcloudio/vite-plugin-uni/bin/uni.js build
```

已通过：模型准入与原生请求 2 测试/21 断言；素材边界 3 测试/11 断言；普通视频目录准入 2 测试/26 断言；上述前端定向检查 32 测试；24 个变更 Vue 文件脚本/模板/样式编译；33 个变更 PHP 文件语法检查；PC、H5 构建。

本地事务回滚验收实际执行了素材探测、草稿隔离、报价、幂等、持久入队、租户/用户冻结退款、未知状态保护、平台/租户记录筛选、业务/系统任务编号查询、模板同款，以及模拟已确认回执后的真实公共结算和资产投影。全新安装验收使用随机表前缀，覆盖共享库/分库新租户、菜单权限、移动页面、重复安装和租户上下架保留，结束删除测试表。

浏览器使用自制测试图案/短视频核验上传 OSS、工具切换后素材可用、两版模型真实报价、后台手工模板保存、标准视频预览、预览内一键同款的完整回填和后台记录页加载。临时模板已软删除，再次进入后已授权草稿素材仍可使用。首次同款发现 Web 进程探测程序路径遇到目录限制，已补上系统现有 `runtime/bin` 候选并跳过不可访问目录，不修改目录访问安全配置；修复后浏览器同款和报价通过。

共享媒体回归 `MicroVideoMediaTest` 全量执行时，一项符号链接测试受容器禁用 `symlink()` 影响报错；没有修改 PHP 安全配置。排除该环境不支持项后其余 9 测试/90 断言通过，覆盖归属校验、危险地址拒绝、实际视频合并、重复边界处理与持久素材回执。`MicroVideoSubmissionTest` 的 z_cn 专用场景在 x_cn 环境不能算作已执行通过。

没有点击付费生成；模型真实动作保真度、复杂人物/遮挡/手部质量、实际供应商端到端回调与交付时长尚未实单验证。H5 已编译，微信小程序/原生 App 设备端上传与播放尚未实机验证。

交付只包含源码、迁移和本文档；不包含上传测试文件、数据库、运行日志、构建目录、已编译 `server/public` 前端或任何发布包，不修改机器本地代理配置。
