# 短剧画布 Agent P0 核对报告

日期：2026-09-21。状态：只读核对及静态/纯逻辑基线完成；行为基线未完成，**P0 尚未放行，P1—P6 未实施**。

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

所有执行命令运行在对应本地 develop。未提交生成请求，没有 Provider 接收次数或消费账本行为证据，不能记为“计费通过”。

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

准入结论：**否**。需指定或确认新建隔离数据库、T1/T2 与 U1/U2/U3，以及无真实 Provider 密钥的测试进程。不得把当前 x_cn 当可迁移/故障注入环境。P1—P6 全部 NOT_RUN；不能用已通过的静态检查跳过 G01—G12。
