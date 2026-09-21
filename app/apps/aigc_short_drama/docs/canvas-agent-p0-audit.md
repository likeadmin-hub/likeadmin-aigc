# 短剧画布 Agent P0 核对报告

日期：2026-09-21。状态：只读核对、静态/纯逻辑及隔离数据库服务级基线已执行；浏览器/HTTP 行为基线未完成，**P0 尚未完整放行**。P1 仅有未接入业务入口的 GraphService 基础切片，不能视为阶段完成；P2—P6 未实施。

## 1. 输入和基线

已完整阅读用户提供的《短剧画布Agent-AI开发执行指令》《短剧画布Agent架构方案》《短剧画布Agent阶段测试与验收清单》《小云雀Agent实测与映射》，以及根目录、server、web 的 AGENTS.md。

- server 实现基线：`e9511d51baf29bdb12c7a13f63346c11423d82ed`，分支 `feature/short-drama-optimization`；本地验证 develop 为 `d683acfef`，已包含该提交。
- web 实现基线：`637cfb8`，同名 feature；本地验证 develop 为 `8a2b7be88046a2a8e19c36dfc27aa56d27596b07`。
- 两仓库核对开始时工作区干净。未回退到方案中的旧 SHA。
- PC：`http://localhost:3000/ai/short-drama/canvas?canvas_id=11&tenant_id=1`，浏览器实际呈现文本/图片/视频/音频生成器和聊天面板。
- 容器 `baota` 将源码映射到 `/www/wwwroot/likeadmin-aigc`；PHP 8.0 可执行，MySQL 可连接。应用实际配置为容器内 `127.0.0.1` 的 `x_cn` 库，`la_` 前缀。未输出账号密码。
- 发现正在运行 `ai:task-worker --worker=result`、`short-drama:episode-worker`、`short-drama:planning-worker`；没有据此声称 Agent 后台恢复已存在。
- 现有业务库不是已确认的隔离测试库；本次只读其结构和 API 注册，不写测试账户、不修改用户画布、不运行生产迁移。

## 2. 当前入口、API 与数据库证据

### 2.1 实际入口

`web/pc/pages/ai/short-drama/projects.vue` 的项目打开和创建导航均指向 `/ai/short-drama/canvas`，传 `canvas_id` 并保留 `tenant_id`；实际页面和组件是 `pages/ai/short-drama/canvas.vue`、`components/short-drama/canvas/ShortDramaCanvasNode.vue`。不切换旧 canvas/canvas_v2。

`web/pc/api/short_drama.ts` 的 current/lists/create/save/delete/run/task 调用 `app.aigc_short_drama.canvas/*`。服务端 `CanvasController` 从 request tenantId / userId 传身份，不从请求体取身份。`AppAccessMiddleware` 从控制器名解析 app_code 并执行租户应用准入。

实际 `la_app_api` 查得 7 个 canvas 路由及 asset/editCanvasImage、asset/captureCanvasVideoFrame；均 status=1、need_login=1，权限键为 aigc_short_drama 命名空间。未发现已注册 canvas_agent/send、threads、events、patch、quote。注册存在不等于逐接口授权行为已通过。

### 2.2 实际结构（information_schema 查询，不以 SQL 文件替代）

| 实际表 | 当前事实 | 方案差异 |
|---|---|---|
| la_aigc_short_drama_canvas | id/tenant_id/user_id/title/nodes_json/edges_json/viewport_json/create_time/update_time/delete_time/removed_node_ids_json | 无 graph_revision/schema_version |
| la_aigc_short_drama_canvas_run | node_id/node_type/provider_task_id/status/progress/request_json/result_json/error 等；owner、node、status 索引 | 无请求 key/hash、唯一提交约束、独立 input version、Agent step 映射 |
| la_aigc_short_drama_canvas_poster_job | 独立封面任务，idempotency_key 唯一，lease_token、lease_expire_time、attempts、frame 字段 | 已有能力必须保留，不可重新造一份封面队列；图投影仍需统一版本入口 |
| la_aigc_short_drama_agent_run / agent_step_log | 现有 project_id/task_id/agent_run_id 和 Skill 快照 | 已有正式短剧运行记录，不是 canvas 会话/调度表；无 canvas_id/thread_id/租约 fencing，不改变其既有含义 |
| canvas_agent_thread/message/run/step/event/outbox、canvas_binding、canvas_mutation_receipt | 当前结构查询未发现 | 方案新增，尚未执行任何迁移 |

## 3. 证据化差异与风险

| 范围 | 已实现 / 需适配 / 缺失 | 证据与影响 |
|---|---|---|
| 四节点 | 已实现，需行为基线 | ShortDramaCanvasService::submit 明确 text/image/video/audio；不增加业务语义节点 |
| 聊天历史 | UI 已实现，真实 Agent 缺失 | ComposerChats + composerConversations 从 composer 来源节点分组；submitAgentDraft 仍建文本节点再执行，不是独立消息会话，纯问答仍会污染作品图 |
| Skill | 文档部分过时 | submit 已 resolveForTask、applyComposerSkill，保存 skill_snapshot，syncShortDramaTask 已写 skill_id/version/snapshot；不再是恒定 skill_id=0。运行冻结续作和 Agent 阶段加载仍待实现 |
| Skill 安全 | 部分已有，需集成验证 | resolveForTask 校验发布状态和请求版本；Runtime 有 missingSlots、validateMedia、instruction。不能把提示词中的边界当执行权限 |
| 图保存 | 缺 CAS | save 先读后整图 UPDATE，无 expected_revision 和统一事务版本检查 |
| 图写入者 | 尚未统一 | save、projectVideoRunToCanvas、formatDocument 恢复写入、ShortDramaCanvasPosterJobService::project 等直接写 nodes_json；读取 current 可能恢复并写图，不是纯查询 |
| 已删节点与迟到结果 | 部分保护 | removed_node_ids_json 防止恢复已删除节点；视频投影有 canvasRunId 匹配及行锁。不能据此认定所有媒体/旧内容版本保护已通过 |
| 容量 | 有明确缺陷 | normalizeNodes 使用 array_slice(...,0,200)，超限会静默截断；应改明确错误 |
| 客户端恢复 | 有覆盖风险 | loadCanvasDocument 比 cached.updatedAt 与服务端 update_time 后安装本地整图；缓存不带 base_revision；persistCanvas 不传 expected_revision |
| 客户端受保护字段 | 未建立白名单 | normalizeNodes 只检查 id/type，客户端 metadata 可带状态等；需要区分编辑字段与服务端产物字段 |
| 生成幂等 | canvas 层缺失 | submit 每次 insertGetId 新 run；node_id 非空即继续，未在该方法核验节点确实存在且类型一致；未知外部结果被统一 catch 为 failed |
| 下游任务映射 | 部分已有 | canvas_run.provider_task_id 实际可能是委托应用本地行 ID；sourceTaskProjection 再读真实 provider_task_id；需明确 TaskRef，不能直接重命名旧字段 |
| 恢复 | 需适配 | runDetail 调 refreshRun；页面轮询/恢复触发投影。已有通用任务 Worker 不等于 canvas Agent Run/step 可脱离页面恢复 |
| 引用能力 | 前端已有基础 | connection-rules 支持完整输入候选与任一兼容模型；未发现统一前后端 Capability DTO/共享行为夹具；服务端 generationPayload 仍接收参考数组，需要权限/版本/用途去重及实际模型再校验 |
| 计费 | 已有权威服务，需行为验证 | PointService 有 assertCanConsumeAmounts、reserveBusinessAmountsInCurrentTransaction、settleReservedBusinessAmountsInCurrentTransaction。不能声称没有预占，也不能推断 Agent 总预算已接通 |
| 四生成适配 | 已有，不另建供应商接口 | submit 调 AigcLlmService、AigcImageService（含 local redraw 分支）、AigcVideoService、AigcMusicService；文本同步成功无 status 已处理。供应商幂等/对账/取消支持等级尚未逐通道验证 |
| 短剧归属 | 现有投影需保留 | generation_task/asset 写自由画布 project_id=0 与 canvas_id；无新绑定迁移，不批量改历史归属 |
| 跨应用复用 | 有需专项回归项 | editImage 对 crop/transform 调 AigcCanvasService 图像工具方法；不能断言完全没有跨应用依赖，也不等于业务表已混用。B05/O10 必测 |
| 正式故事/剧集 | 已有 | StoryWorkflow::VARIANT=story_outline_v2，EpisodeService 使用 production_project_id；薄 Adapter 接入前必须区分父项目、episode、制作项目与确认语义 |
| Agent 配置、授权和调度 | 缺失 | 尚无当前画布专属持久化计划审批、事件游标、DAG、预算占用、fencing/outbox，以及三个独立能力关闭开关 |

## 4. 测试证据和准入

所有执行命令运行在对应本地 develop。初次核对未提交生成请求；用户随后批准隔离测试环境，补充结果见第 6 节。模拟下游接收次数和真实 PointService 测试账本均已有证据，但不能据此认定真实 Provider 或完整 UI 计费链路通过。

| P0 用例 | 状态 | 证据/缺口 |
|---|---|---|
| B01 真实入口 | PASS（入口证据） | projects.vue 导航 + 已运行浏览器 DOM，当前四生成器可见；未在业务账号新建作品 |
| B02 四节点保存重开 | BLOCKED | 需隔离测试画布；本轮未写真实业务库/画布；现有静态保存测试不能代替 |
| B03 手工执行与账本 | BLOCKED | 未确认隔离 Provider/账本环境；真实模型未获付费授权 |
| B04 表/API/能力核对 | PASS（发现性核对） | 已查实际表列和 app_api 注册；缺失能力列入上表，供应商支持等级保留待测 |
| B05 关闭独立画布应用 | BLOCKED | 无隔离租户；不在现有业务租户关闭应用 |

统计：PASS 2 / FAIL 0 / BLOCKED 3 / NOT_RUN 0；该统计仅为 B01—B05，不表示 G/A/M/R/D/O 后续用例已执行。

实际命令与退出码：

```sh
# web/，Node 使用机器现有绝对路径
/Users/panda/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node --test pc/tests/short-drama-canvas-connections.test.cjs pc/tests/short-drama-canvas-persistence.test.cjs pc/tests/short-drama-canvas-groups.test.cjs pc/tests/short-drama-canvas-controls.test.cjs
# exit 0，22 passed；混合纯逻辑/源码契约，非数据库/浏览器生成验收

docker exec -w /www/wwwroot/likeadmin-aigc/server baota php app/apps/aigc_short_drama/tests/short_drama_canvas_text_task_contract.php
# exit 0，text task contract passed；源码契约检查

docker exec -w /www/wwwroot/likeadmin-aigc/server baota php app/apps/aigc_short_drama/tests/canvas_composer_skill.php
# exit 0，Skill validation passed；纯逻辑检查
```

数据库只读查询通过 PHP/ThinkPHP 引导执行：information_schema.COLUMNS（TABLE_SCHEMA 和 TABLE_NAME 模式参数化）与 app_api 指定 app_code/路径字段投影，exit 0。第一次 `SHOW TABLES LIKE ?` 在当前 MySQL prepared statement 下语法错误、exit 255；改用 information_schema 后成功。这是审计命令错误，不是产品测试 FAIL，保留记录。

未运行现有 canvas_toolbar_integration.php：它写当前配置数据库的 tenant/user=1，即使最终 rollback 也不满足本轮隔离测试要求。未做故障注入、迁移、真实付费生成和部署。

## 5. 下一步实施文件范围及契约

两仓库继续 `feature/short-drama-optimization`；先补行为基线，再开启 P1。以下是计划，不是已落地功能：

1. server P1：新增 canvas_agent/GraphService（有限 patch、CAS、字段白名单、receipt）；修改 ShortDramaCanvasService 及 PosterJob 图投影；扩展 canvas/run，新增 receipt/outbox/事件及增量迁移。统一手工与未来 Agent 提交输入及幂等边界。
2. web P1：api/short_drama.ts、canvas/types.ts、canvas.vue、useShortDramaCanvasGraph，带 expected_revision/request_key；缓存 base_revision，不用本地时钟自动覆盖新图；冲突保留本地草稿。
3. P2：独立短剧 CanvasAgentController/Service、ContextBuilder、Planner、PolicyValidator、会话/消息/事件与对应面板/composable；复用原 Composer 的真实模型与 Skill 目录，不移植 aigc_canvas Runtime。
4. P3/P4：规范化能力/引用/报价快照和共享 JSON 行为夹具；现有执行服务薄 Adapter；数据库持久化 Scheduler、受限并行、租约、对账、停止、局部重试及预算。
5. P5：canvas_binding 和现有 Story/Episode 薄 Adapter，正式写入版本与确认检查；不重写剧集生成。
6. P6：api_schema/permissions、必要安装/升级入口、开关、审计与压测/回退测试。真实模型验收另列样本/预算申请。

待冻结 DTO：GraphSnapshot(id, graph_revision, schema_version, nodes, edges, viewport, removed_node_ids)；GraphMutation(request_key, expected_revision, operations)；MutationReceipt(request_hash, base_revision, result_revision, result)；GenerationInputSnapshot（模型/Skill/素材版本/input_hash）；TaskRef(source_app_code, source_task_id, provider_task_id)。节点继续兼容安全整数，边继续兼容 from/to，身份与归属永远服务端解析。

初次核对准入结论：**否**。该时点隔离环境尚未批准。以下第 6 节更新此后的状态；不得把当前 x_cn 当可迁移/故障注入环境，不能用静态或服务级测试跳过浏览器及 G01—G12 验收。

## 6. 获准隔离环境后的补充记录

用户明确允许建立隔离测试环境。创建 Docker internal 网络 `short-drama-agent-test`、无宿主机端口映射的 `short-drama-agent-test-db`（MySQL 8）以及仅运行 CLI 测试的 `short-drama-agent-test-php:local`。测试数据库固定 `short_drama_agent_test`，`.env` 被空设备覆盖，源码只读，runtime 为临时内存目录。仅复制原库表结构，不复制业务行、素材或供应商密钥；未启动原有 Worker。

原业务账号 CREATE DATABASE 被 MySQL 1044 拒绝，默认 root socket 无密码被 1045 拒绝；没有修改授权或读取面板 root 密码，改用独立容器。原 `x_cn`、PC 代理及原 Worker 不变。

### 6.1 可复现测试结果

环境搭建和运行命令见 `../tests/agent/README.md`。以下脚本分别独立运行，退出码均为 0：

| 脚本 / 实现提交 | 断言 | 真实覆盖边界 |
|---|---|---|
| p0_baseline.php / 7dfce8637 | 7 PASS；2 KNOWN_GAP | 四节点 JSON 数据库往返、旧 from/to、视口、租户/用户拒绝访问与回滚；重现旧整图保存覆盖和 201 节点截断为 200 |
| p0_generation.php / d1babc71c | 13 PASS | 实际 CanvasService、AppAccessService、PointService 和任务/资产投影；只替换下游生成服务边界 |
| p1_graph.php / 058bee077 | 19 PASS | 事务 CAS、重复 patch 10 次稳定返回、receipt 唯一、同 key 不同负载拒绝、越权/字段伪造拒绝、容量错误原子回滚、内容修改保留坐标 |

模拟下游接收四类独立调用共 4 次，重复轮询未再提交。测试租户余额 100→96，用户余额 100→92；真实积分服务生成租户消费记录 4 条、用户消费记录 4 条；短剧任务 4 条、媒体资产 3 条，均 project_id=0，重复读取不增加投影。所有这些行在 finally 中回滚。这里的接收计数位于模拟下游服务边界，不是实际供应商的 HTTP 接收计数。

测试搭建期间修复了夹具 sn/account 唯一键冲突、图片任务表无 billing_status 列等问题；它们不是产品行为失败。测试引导器修复异常退出码，未将打印异常但退出 0 作为通过。

### 6.2 新发现与阶段门槛

- 原业务库没有 `aigc_music` 任务/结果表。仅在测试库执行已有音乐应用 install.sql，使模拟音频投影可测；未安装或迁移业务库。真实环境音频可用性仍未验证，不能以测试库结果掩盖差异。
- B02 数据库存取已 PASS，浏览器创建/保存/刷新重开仍 NOT_RUN。
- B03 服务级四类调用与账本已 PASS，手工 UI 到 HTTP 的端到端、实际 Provider Adapter、物理文件转存仍 NOT_RUN。
- B05 AppAccessService 和 CanvasService 在独立画布应用禁用时已 PASS；HTTP 中间件与浏览器资源入口仍 NOT_RUN。
- P1 图服务目前没有 API 注册、正式迁移或业务调用者。测试 schema 是一次性的草案，不是可上线迁移；现有保存、封面 Job、生成提交和前端缓存均未迁移到该服务。
- P1 的 19 个断言是串行数据库行为测试，不是双标签页或独立进程竞争测试。G01—G12 **尚未全部通过**，禁止开放 Agent 后台图写入；P2—P6 保持 NOT_RUN。

当前决定：先补齐 P0 端到端基线，继续记录无法验证项，不将基础切片或模拟成功视为完整阶段验收。没有真实付费调用、生产迁移、部署或发布。

## 7. 继续执行：控制器基线、容量保护与多进程竞争

代码：server `9e06e877e`（控制器基线）、`3c5fc88f0`（容量保护）、`ef5ea3aef`（并发回执修复）；web 保持 `637cfb8`，无新增前端实现。命令依旧在本地 develop 合入 feature 后执行。

1. `p0_controller.php`：15 PASS，exit 0。数据库合成 session 经真实 LoginMiddleware 和 AppAccessMiddleware，再调用真实 CanvasController。验证缺失/无效 token、token 与租户不匹配、请求体伪造 owner、跨用户/跨租户读写删、应用下架，以及禁用独立画布时短剧四节点存取。
2. 最初夹具漏填 user_session.tenant_id，exit 1；修复测试夹具。其后四节点严格相等失败，证据显示现有 Request 全局 trim 把数值字段转成数字字符串，并非节点丢失。测试明确固定现行 wire format 后通过；未来 Graph API 需处理 revision 的解析，不可直接将当前 Request 输出当整型 DTO。
3. 已修复现有 CanvasService 超量保存静默截断：写入和排队前检测容量，201 节点明确报 CANVAS_CAPACITY_EXCEEDED，原文档完全不变；200 节点仍可保存。`p0_baseline.php` 当前 10 PASS / 1 KNOWN_GAP（旧整图覆盖），exit 0。
4. `p1_concurrency.php` 使用独立进程/连接、就绪屏障真实竞争。首次失败：同 key 并发回执读取遇到旧快照，产生版本冲突。GraphService 回执查询改为锁定的当前读，在版本判断前处理已完成回执；复测 7 PASS，并额外连续 5 轮 exit 0。不是仅检查本地行数：还严格比较 10 个返回值一致，竞争不同 key 时一成功一冲突。
5. 重新执行 p0_generation.php：13 PASS；p1_graph.php：19 PASS。原四类模拟生成接收 4 次、租户/用户消费各 4 条、短剧任务 4 条、媒体资产 3 条保持。前端已有 22 项纯逻辑/源码契约检查 exit 0。五个数据库测试共 64 个断言（多次重复轮次不重复计数），并非 64 条阶段验收用例。

### 当前准入解释

P0 的入口与服务端对象契约已清晰且基线可复现，可继续 P1 的受限开发；此前“等待隔离环境”的阻碍已经解除。但 **P0 浏览器/完整 HTTP 端到端仍 NOT_RUN**，不声称所有 P0 验收完成。该限制必须保留到阶段总验收，不得用进程内控制器测试冒充网络/浏览器证据。

P1 仍未放行：G01/G03 只在独立 GraphService 边界有行为证据；G08 已覆盖现有保存入口的服务层，尚无浏览器证据。G02/G05/G06/G07/G11/G12 的集成验证、普通保存与后台写入统一、客户端缓存 revision、正式幂等迁移与生成恢复仍未完成。GraphService 没有新增可访问 API，仍不开放 Agent 后台写图。P2—P6 NOT_RUN。

本轮业务改变仅容量超限拒绝；没有修改计费服务、现有短剧故事/剧集服务、素材存储、前端代理、业务库 schema、真实 Worker 或供应商配置。后续重点是统一图写入与缓存冲突，不能仅接一个 patch API 就宣布 P1 完成。

## 8. 保存与封面写回保护（部分 G11）

server `2880d3825`；web 未改。普通 save 在事务内先锁定所属画布，再读取、合并、保存，与既有视频/封面投影使用同一行锁。对于同一视频，服务端已有的持久封面优先于旧浏览器快照中的非空旧封面；不同视频不合并旧封面。没有增加 API、字段或迁移，没有启动真实 Worker。

在本地 develop 执行 `p1_poster_save.php`：4 PASS，exit 0，验证位置修改保留、同视频旧封面不可覆盖、新视频不继承封面、已有封面不重复排队。重新执行 p0_baseline.php / p0_controller.php / p0_generation.php：10 / 15 / 13 PASS，exit 0；原整图过期覆盖继续明确输出 KNOWN_GAP。

新增测试使用合成 URL 和数据库回滚，不进行文件请求。运行方法沿用 tests/agent/README.md 的 docker run 参数，将脚本名换为 `p1_poster_save.php`。这里只验证陈旧快照的合并行为，不把行锁代码视为“真实封面 Worker 并发测试已通过”。G11 整体验收仍待独立进程/真实写入路径测试，版本号、GET 恢复写入、前端缓存和生成恢复仍待统一。P1 不放行，P2—P6 未启动。
