# 短剧画布 Agent P0 核对报告

## 最新增量：P2 A03 位置指代歧义澄清（2026-09-21）

本轮在两仓库 `feature/short-drama-optimization` 实现后先合入本地 `develop` 验收；没有调用真实 Provider、创建媒体生成任务、扣费、迁移业务库或发布。server 实现提交 `48bbb6f80`，随后测试夹具修正 `9207631aa`；web 提交 `2c5d8d2`。二者均已以 `--no-ff` 合入各自本地 `develop`。

- 对未显式选择节点、含“左边/右边 + 图片”位置指代的请求，服务端只在多个图片节点落入同一前导位置带时创建 `clarify` 终态会话：保存用户消息、Agent 澄清消息和最多 4 个安全候选标签；不写 outbox、不设置 active run，Worker 永远不可提交它。
- 候选只包含当前租户、当前用户、当前画布的 node ID、标题和固定类型；不返回图快照、素材 URL、存储 URI 或 Provider 输入。用户必须点击一个候选使画布选区成为明确 node ID，再由右下角输入框重新发送。未做“自动猜左图”的降级。
- SSE 的持久消息投影包含同一份受限候选字段，`clarify` 被视为终态；PC 状态机仅接收 assistant 的 2–4 个合法图片候选，页面以文本插值卡片展示，防止候选数据成为 HTML。

实际验收（全部在已合入的本地 `develop`）：

| 验收面 | 结果 |
| --- | --- |
| 隔离 MySQL P2 会话 | PASS，`p2_conversation.php` **64 PASS / 0 FAIL**。覆盖歧义澄清、无 outbox、持久候选、幂等重放和显式节点后恢复普通 queued 请求。 |
| 隔离 Worker 回归 | PASS，`p2_worker.php` **90 PASS / 0 FAIL**。确认共享会话/冻结上下文/写回/视觉输入既有行为未回退。 |
| PC 状态机 | PASS，`short-drama-conversation-state.test.cjs` **11 PASS / 0 FAIL**；伪造 user 候选会被拒绝。 |
| Chrome → 隔离 HTTP/MySQL | PASS，`short-drama-agent-http-browser.cjs` **9 PASS / 0 FAIL**。真实右侧面板先展示两张候选卡，点击后下一条请求实际带 `selected_node_ids=['2']`；澄清 run 的 outbox=0，整个夹具媒体 run/双方积分账本均为 0。 |

该项满足验收清单 A03 的“不随机选择、显示候选、用户确认后再提交、无额外费用”行为门槛。候选卡目前显示节点图标、标题和 ID，而不是复制素材 URL 作为缩略图；多图真实理解/比较质量和附件语义仍为 NOT_RUN。P2 仍受审核产品策略、结构化规划/受限工具、预算和未知用量对账等未完成项约束，**不可放行到 P3**。

### 同轮追加：A01 双图输入、A04 冻结引用、A10 已知约束

- A01：`ConversationImages` 已按显式选中顺序冻结并解析多达 4 张已授权图片。隔离 `p2_worker.php` 最新 **99 PASS / 0 FAIL**，新增双图顺序、无额外媒体任务断言。随后在用户此前授权的本地真实数据上完成一次受限验证：tenant 1 / user 1 / canvas 7 / thread 8 / run 12，Qwen3.6-Plus（模型 ID 1）实际读取两个已授权图片节点 `91002`、`1789815526636`，成功回复 899 字，分别识别雨夜人物场景和蓝色储物柜的主体、色彩与构图差异；该 run 新增媒体任务数为 0。A01 的双图理解路径现为 PASS；没有将该真实样本扩展为附件、多图上限质量或视频/音频理解验收。
- A04：Chrome 验收在候选点击后创建真实持久会话，隔离桥通过 GraphService 将被引用节点 x 从 320 移至 999；run 快照仍为 node `2` / x `320`。随后浏览器先重读新 revision 才发送下一条，证明不会用陈旧页面覆盖图。该浏览器套件仍 **9 PASS / 0 FAIL**（新增断言并入既有流程）。
- A10：`ConversationTextContext` 从历史 user 消息中仅提取明确且长度受限的画风、比例、时长，作为当前 user-role JSON 的 `known_creation_constraints`；提示模型除非用户修改，不重复询问。没有把这些文本提升为 system 指令、工具参数或生成任务。隔离 Worker 验证后续请求收到 `国风水墨 / 9:16 / 15 秒`。队列为空后本地 Agent Worker 从 PID 1191069 优雅重启至 PID 1204377 并加载 develop；真实 tenant 1 / user 1 / canvas 7 / thread 9 的 run 13、14 使用 Qwen3.6-Plus 连续完成，第二轮直接给出 15 秒国风水墨 9:16 分镜建议、未含问号且未创建媒体任务。A10 为 PASS；不将单样本结果扩展为所有语言表达或复杂规划质量。

### P2 当前阶段门槛复测

在新增实现和真实样本后，从 server 本地 `develop` 串行执行 `p2_migrations`、`p2_conversation`、`p2_conversation_concurrency`、`p2_execution`、`p2_settings`、`p2_send`、`p2_http`、`p2_worker`、`p2_queue_crash`、`p2_stop`、`p2_stop_race`、`p2_recovery`：**647 PASS / 0 FAIL**。测试持续使用 internal Docker 网络、遮盖 `.env`、隔离 MySQL 与模拟 Provider；真实 A01/A10 样本单列在上，不混入这 647 项。

P2 仍为 **BLOCKED，不能进入 P3**：短剧 Agent 尚缺经产品确认的审核等级、审核范围、人工复核和最小化审计保留期；现有其他应用词表不可跨应用复用，不能用静态弱规则伪装通过。附件上传/多文件撤销与视频/音频理解也仍未验收，前端保持明确拒绝附件，避免假称已发送给模型。其余 A01—A15 的已实现问答、引用、Skill、刷新、停止、幂等和安全文本展示均有上述隔离或真实行为证据。

## 最新：2026-09-21 当前实现全量回归验收

本节在两仓库已集成最新 feature 的本地 `develop` 执行。当前实现范围的全量后端、PC 行为和隔离浏览器验收已经完成；没有重新触发真实付费媒体生成。此前取得的真实 Qwen 文本写回和单图理解账本证据仍保留在下一节，未因本轮全量回归删除。

| 验收面 | 状态 | 本轮可复现证据 |
| --- | --- | --- |
| P0/P1/P2 后端隔离回归 | PASS | 29 个脚本串行通过，**905 PASS / 0 FAIL**。覆盖 P0 基线/生成/控制器/HTTP，P1 图 CAS、并发、迁移、手工生成投影和崩溃恢复，P2 会话、偏好、执行、Worker、队列崩溃、停止竞态与恢复。测试数据库/网络/Provider 保持隔离。 |
| PC 行为与状态 | PASS | `short-drama-*.test.cjs` **127 PASS / 0 FAIL**。覆盖四节点画布、Agent 作用域、冻结选区写回、模型偏好、文本安全渲染、保存冲突、运行结果保护和现有短剧流程。 |
| Chrome 浏览器链路 | PASS | **41 PASS / 0 FAIL**：本地草稿冲突恢复 8、真实 HTTP/隔离 MySQL 四节点持久化 9、四种模拟生成与权威账本 16、Agent 右侧面板/SSE/停止/刷新/租户切换/XSS 8。未调用真实 Provider 或真实媒体生成。 |
| PC 静态生成 | PASS | Nuxt 成功 prerender **125** 条路由，包含 `/ai/short-drama/canvas`；仅生成 `.output` 本机验证产物，未复制至 server/public、未提交。 |
| 常驻 Agent Worker | PASS（运行态） | 容器内 `short-drama-canvas-agent:short-drama-canvas-agent_00` 为 RUNNING，PID 1191069，由 Supervisor 管理；本轮不重启、不注入业务故障。 |
| Vue 全类型检查 | BLOCKED（环境） | 仓库未安装 `vue-tsc`，Nuxt 调用 `npx -p vue-tsc -p typescript vue-tsc --noEmit` 时本机无 `npx`，因此未进入类型诊断。未下载或修改依赖；Vue SFC 编译行为测试已纳入上述 127 PASS。 |
| 历史独立画布 E2E | NOT_RUN（接口已退役） | `short-drama-canvas-entry.e2e.mjs` 仍断言旧的 `capabilities/create/detail/saveView` 独立画布及“无限画布”入口；当前四节点画布为 `canvas/current|save|run`，不恢复旧接口以让历史脚本变绿。 |

本轮先后发现三项**测试合同陈旧**，均已修正并重跑全量 PC 套件：Agent 测试将动态选区错误写死为 `selected_node_ids: []`；分镜最小时长错误写死为静态 `min="4"`；时间轴 VM 夹具缺少当前正常化函数。另将冲突浏览器用例改为复用机器已安装的 Chrome，避免下载缺失的 Playwright Chromium。修复均只改测试；没有改变短剧业务、Agent API、计费或数据库行为。web 提交 `f0c56c0`、`0e03acb`、`a6c1055` 已各自先进入 feature 再无冲突合入本地 develop。

阶段结论：P0 仍按第 17—18 节已确认的 B05“独立应用不可用”限定口径通过，P1 隔离验收通过；P2 已具备上述实现和回归证据，但因短剧 Agent 审核策略仍 BLOCKED、双图比较/附件语义、结构化规划工具、真实预算与未知用量对账、生产调度恢复等未验收能力，**P2 不放行**。P3—P6 **NOT_RUN**，不可将本轮 0 FAIL 表述为方案全部完成。无生产迁移、部署、发布、远程推送或批量真实付费生成。

## 最新：确认式文本写回、图片理解与本地真实验收

2026-09-21，用户明确要求实时同步本地真实数据并做真实测试，确认采用“展示回复→点击写回原节点”的流程，并单独批准仅向本地 x_cn 新增偏好表。分支仍为 `feature/short-drama-optimization`。server 实现 `58b1ff47e`、`6023d2f1e`、`b6c724596`、`30322bb35`，测试 `68d973565`；web `f8db625`。每个提交均已先合入各自本地 develop；没有推送 develop、远程部署或发布。

已实现：PC 发送前保存画布并传实际选中节点 ID；发送重试保留同一负载。消息返回已授权的冻结原文/版本，使用文本插值展示。点击“写回原文本节点”走现有 canvas/patch 的新增 apply_agent_text 操作：只读取本用户、本画布成功 run 的真实 assistant 回复，核对原节点内容/提示词/版本，去掉旧 richContent、推进版本，保留布局；Graph receipt 保证重复请求不再写一版。原内容保留在 run 快照并可在对话展开查看。没有新增 API 路由或新增业务表；默认模型偏好表是此前既有增量 DDL，本次获准后只执行了该表的 CREATE IF NOT EXISTS，读取返回空偏好/revision 0，不擅自改用户默认模型。

图像理解：只解析当前用户、当前画布的短剧图片资产；执行前复核未删除及冻结 URI/存储元数据。真实模型必须通过 requiresVision 校验。受管本地图片需额外证明来自该用户 tenant_file 上传记录或其生成任务 output_asset_ids；只读 public/uploads 下真实路径，限制图片数 4、单图 8 MiB 和 PNG/JPEG/WebP MIME，以 data URI 发送给真实模型；不让供应商读取 localhost、不获取任意浏览器路径。远程图沿用存储服务 URL。未配置视觉的推理模型拒绝，不自动替换模型。附件上传、多图复杂比较质量和视频/音频理解仍未验收，不能把此次单图验收扩大为完整多模态能力。

真实证据（均 tenant 1 / user 1，本轮新建验收数据，没有改原画布 13）：

| 项目 | 结果与真实数据 |
| --- | --- |
| 文本推理 | canvas 14 / thread 5 / run 8、9，Qwen3.6-Plus，两次 success，app_task 1059/1060，实际消费 2.139200 / 2.608200 积分 |
| 版本保护与确认写回 | 第一次 run 8 引用 v1，页面初始化补模型配置后成为 v2，真实按钮返回冲突，未覆盖；基于 v2 的 run 9 再次成功后，在真实浏览器点击确认，节点显示改写文本、content_revision=3，原文仍在对话可展开查看。PASS |
| 图像理解 | canvas 15 / thread 6 / run 10，真实 Qwen3.6-Plus 正确回答图片英文 Admin、白色文字、蓝色背景；app_task 1061，实际消费 0.485800 积分。输入是项目自带公开 Logo 的验收副本，不是私有素材。真实回复在浏览器可见。PASS |
| 账本 | 上述三笔 consumption 均 run_status=success、billing_status=settled，总用户消费 5.233200 积分；两个验收画布媒体生成 run 数为 0。PASS |
| 本地文件来源保护 | 初次公开测试文件仅注册短剧资产，新增保护后拒绝；为真实验收副本补登记用户上传文件元数据（tenant_file 495）后，实际本地 PNG 转为 6650 字节 data URI、图片数 1。未为此再次付费调用。PASS |
| Worker | 确认 active_outbox=0 后由 Supervisor 优雅重启；首次更新 PID 1189327，收尾再次重启以加载来源保护。启动后 RUNNING。没有中断执行中的任务；完整容器重启 NOT_RUN |

回归：隔离 p2_worker 最新 90 PASS；p1_graph 19、p1_manual_authority 10、p2_conversation 57、p2_http 47 PASS；前端 state/reader 18 PASS、现有 Agent 隔离浏览器 8 PASS；均 exit 0。后四后端脚本及前端浏览器在最后本地文件来源小修前执行，不伪称最终提交全量重跑；最后实际本地文件解析和 p2_worker 在最终实现上通过。根据回归保护技能覆盖图写入、权限、幂等、普通对话与既有节点字段。未运行完整构建/全部 P0—P6。

失败记录：图片测试夹具最初把远程 URI 标为 local，被存储服务重写而断言失败，纠正为 oss 后通过；真实初始化图片节点误用 Graph add_node 的受限 metadata.image，正确拒绝且图事务回滚，随后沿用现有手工 Canvas save 入口建立图片节点，没有放宽 Graph 白名单。文件来源复查发现 tenant_file 活跃记录使用 delete_time=NULL，已兼容 NULL/0 并实际复测，不把有效上传当已删除。测试资产/画布/账本和公开 Logo 副本保留供用户查看，未静默清理真实记录。

剩余：A02 取得真实确认写回与原文追踪证据；A01 取得真实单图视觉能力证据，但双图比较仍 NOT_RUN。完整审核策略仍 BLOCKED；规划/工具执行、预算等 P2 原缺项不因本次通过而放行。SSE 仍推送完整持久回复，逐 token 同步输出未实现。P2 未全部完成，P3—P6 未放行。

## 最新增量：P2 冻结文本引用进入模型消息

本段为最新状态，下面各节保留历史证据。server `f721185f6` / `5929a4463`，分支 `feature/short-drama-optimization`，web 无改动；无 API、数据库迁移、权限或计费契约变更。

查明并修复：ConversationStore 已冻结 selected_nodes，但真实文本 Provider 只转发 messages，导致节点材料未实际进入模型输入。新增 ConversationTextContext，在 Worker 预检之前用冻结快照构造 user-role JSON 材料，保留本轮请求、graph_revision、node_id/content_revision/文本及提示词。材料不提升为 system 或工具指令，不查当前节点、不请求媒体 URL。非文本节点明确标记 media_understanding_available=false，不用媒体提示词冒充视觉理解；无引用时历史消息格式保持原样。格式及大小异常在 Provider 调用前拒绝。

本地 develop 合入每个 feature 提交后实际执行：p2_worker 79 PASS、p2_conversation 57 PASS、p2_queue_crash 31 PASS、p2_stop 76 PASS，共 243 PASS，四脚本 exit 0；diff check PASS。遵循回归保护技能，覆盖共享 Worker 的普通对话、冻结材料、幂等、进程故障恢复、停止与未授权工具拒绝。测试全部在独立数据库/internal 网络/模拟 Provider 中执行，未调用付费模型。新用例确认发送后移动和改写节点时，Provider 仍收到旧内容和版本，实时画布不被覆盖，材料中的“生成100个视频”没有执行权限或媒体任务。

首次新增测试失败为测试 request_key 含空格，被实际 Store 正确拒绝 INVALID_REQUEST_KEY；改用合法夹具 key 后完整复测通过，没有放宽产品校验。业务常驻 Worker 本轮未重启，不能声称其已加载新 PHP 类；真实选中文本的浏览器到供应商验收仍 NOT_RUN。

A02 仍仅部分实现：冻结原内容可追踪、模型输入已连通，但尚无“新文本版本写回并在 UI 可见”的完整闭环；A04 获得发送后移动仍保持引用 ID 的服务端证据，尚无相应用例浏览器验收。A01 图片理解、审核策略、规划/工具执行等缺项继续保留。P2 不放行，P3—P6 NOT_RUN。

## 最新增量：2026-09-21 续跑 SSE 重连验收

本段更新后文历史状态。P0 按第 18 节限定口径、P1 隔离验收通过；P2 尚未放行，P3—P6 NOT_RUN。分支 `feature/short-drama-optimization`，本轮 server 仅改隔离测试桥接及报告，web 无源码变更，API/权限/迁移契约不变。

- 本轮实际验证：在两仓库已集成的本地 develop 执行 state/reader/run-result-policy，21 PASS、0 FAIL；真实 Chrome → 隔离 HTTP/MySQL 的 Agent 浏览器套件修复后 8 PASS、exit 0。包含账户模型偏好、不建作品节点的会话提交、持久回复经 SSE 展示、刷新不重复发送、停止、SPA 租户切换清理、安全文本渲染。Provider 未接入此夹具，媒体任务和双方积分账本均为零。
- 失败及修复：首次浏览器测试因 localhost:3000 拒绝连接未进入页面；随后 3000 服务已恢复。额外启动的开发服务选到 3001，已仅停止该新增进程，没有停止原服务或修改代理。第二次进入页面后，SSE 重连重复触发夹具领取已成功 run，服务端正确拒绝，夹具报错。测试提交 `f297a8e1c` 允许仅对 success 且持久回复内容严格相同的运行重放 SSE；其他状态或不同内容仍拒绝。修复先提交 feature、合入 develop，再完成上述 8 项复测，不放宽业务 claim 规则。
- 本地运行状态：此前用户授权真实测试后，运行 #4/#5/#6 已成功并产生真实消费；这些是此前会话的验收记录，不是本轮付费复测。此前用户“执行配置”后，tenant 1 的 Agent Worker 已加入本机 Supervisor，退出后自动恢复已测试。本轮只读确认 `short-drama-canvas-agent_00` RUNNING、PID 1185820；没有重启或故障注入业务 Worker。配置在宿主机持久挂载的 panel/plugin/supervisor/profile 中，不交付进源码。完整容器重启仍 NOT_RUN。
- SSE 当前传递持久状态及完整回复，不是模型逐 token 输出；任务提交仍由 durable outbox/Worker 执行，不能称为已经完成用户要求的同步逐字流式链路。纯问答成功样本也不能代替媒体、预算、未知用量、图像理解或工具规划验收。
- 保留第 23 节的审核产品策略 BLOCKED 与其他 P2 缺项；本轮未做付费生成、业务迁移、部署、发布或远端推送。后续先补 P2 未完成项，不能由本次 8 项通过直接进入 P3。

复测命令：web develop 执行 `CANVAS_TEST_BROWSER_CHANNEL=chrome NODE_PATH=/Users/panda/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules /Users/panda/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node pc/tests/short-drama-agent-http-browser.cjs`。隔离桥接仍遮盖 `.env`、源码只读、internal 网络及限定测试身份；本轮未接触私有素材或供应商密钥。

> 当前进度以末尾最新章节为准；前面各节为历史审计证据。第 18 节按用户在 B05 验收口径确认问题后的“继续”推进，采用已说明的独立应用不可用隔离口径，保留默认应用策略及与 tenant-only 关闭的差异。P0 隔离基线与 P1 已通过，P2 尚未放行。

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

> 以上各节为当时的历史检查结果；以下最新记录覆盖其中已经补测或修复的缺口，不代表 P1 整体放行。

## 8. 保存与封面写回保护（部分 G11）

server `2880d3825`；web 未改。普通 save 在事务内先锁定所属画布，再读取、合并、保存，与既有视频/封面投影使用同一行锁。对于同一视频，服务端已有的持久封面优先于旧浏览器快照中的非空旧封面；不同视频不合并旧封面。没有增加 API、字段或迁移，没有启动真实 Worker。

在本地 develop 执行 `p1_poster_save.php`：4 PASS，exit 0，验证位置修改保留、同视频旧封面不可覆盖、新视频不继承封面、已有封面不重复排队。重新执行 p0_baseline.php / p0_controller.php / p0_generation.php：10 / 15 / 13 PASS，exit 0；原整图过期覆盖继续明确输出 KNOWN_GAP。

新增测试使用合成 URL 和数据库回滚，不进行文件请求。运行方法沿用 tests/agent/README.md 的 docker run 参数，将脚本名换为 `p1_poster_save.php`。这里只验证陈旧快照的合并行为，不把行锁代码视为“真实封面 Worker 并发测试已通过”。G11 整体验收仍待独立进程/真实写入路径测试，版本号、GET 恢复写入、前端缓存和生成恢复仍待统一。P1 不放行，P2—P6 未启动。

## 9. 连续实施：冲突恢复、真实 HTTP 与统一图版本

代码基线：server `656526bf8`（包含 `bf343ff87` 统一写入、`57a7b1f56` 图操作、`8329cf0cf` 容量恢复、`d20d467fe` wire 校验）；web `065ffd1`（包含 `23118ea` 浏览器覆盖、`146fe94` 容量提示及 `68e3ad2` 生成保护）。均先提交 feature，再合入本地 develop 执行测试，没有推送 develop，没有迁移业务库。

### 9.1 已实现的边界

- 现有 save 支持可选 `expected_document_token`，对未迁移库也能检测整图变化。新 PC 保存携带读取到的 token；冲突时暂停自动重试、保留后续本地编辑、允许导出草稿和明确重新读取云端。旧缓存时间戳再大也不自动整图上传。加载后的 watcher 在允许保存前排空，避免页面初始化自动覆盖。
- 已有 `graph_revision` 列时，save、patch、GET 历史补节点、视频结果、封面成功/失败写回均通过 `GraphService::persistLockedDocument` 推进版本；调用者保留所属画布行锁。新 PC 携带返回的 `expected_revision`。首次版本化保存/patch 将该文档标为 schema v2，此后缺少 revision 的旧保存明确拒绝。没有新列的业务库继续 token 兼容路径，不自动 ALTER。
- Graph patch 对版本字段兼容当前 Request 的数字字符串，只接受规范无符号整数；服务端在锁内分配不复用墓碑的安全整数节点 ID。新增 set_group（沿用现有 `agentGroupId`）、精确 remove_edge、语义重复边拒绝；同素材首帧和尾帧用途不会误合并。patch 尚未公开为 API，能力矩阵检查尚未完成。
- GET 恢复在锁内重新读最新节点和墓碑，不覆盖并发编辑；数字墓碑兼容字符串比较；200 节点时保留历史并返回 `recovery_pending_node_ids`，PC 明确提示。不会补出第 201 节点，也不会截掉原节点。
- 提交前拒绝不存在/类型已变的节点，不建 run 或调用下游。PC 任意节点保存失败后不继续生成；轮询返回后再次确认节点对象及当前 run，旧 run 或已删除对象不写当前画布。尚不等于服务端提交幂等或完整 generation fencing。

### 9.2 本轮实际结果

以下 12 个脚本在相同隔离环境串行完整执行，exit 0，共 **136 条断言**（不是 136 条阶段用例）：

| 脚本 | PASS | 证据边界 |
|---|---:|---|
| p0_baseline.php | 10 | 四节点数据库往返/归属/容量；仍输出未版本化旧客户端覆盖 KNOWN_GAP |
| p0_generation.php | 16 | 四类模拟生成、真实账本；非法节点不产生 run/费用 |
| p0_controller.php | 15 | 实际中间件/控制器，进程内 |
| p0_http.php | 6 | 测试容器内真实 PHP HTTP 内核、路由、租户解析、登录和应用中间件；只有读/建/存/列表路由，禁止生成 |
| p1_graph.php | 19 | patch CAS/回执/权限/字段保护/原子容量错误 |
| p1_graph_wire.php | 18 | 数字字符串版本、分配 ID、非法 wire 不写图或回执 |
| p1_graph_operations.php | 11 | 分组、旧边/位置兼容、不同用途引用、精确删线与版本冲突 |
| p1_concurrency.php | 10 | 10 个独立进程重复 key；同版本不同 key；实际 save 与封面投影竞争 |
| p1_poster_save.php | 4 | 同视频保留持久封面，不同视频不继承 |
| p1_save_cas.php | 5 | 实际 save 的内容 token 冲突 |
| p1_read_recovery.php | 10 | 锁内恢复、墓碑、容量、历史保留和延后恢复 |
| p1_revision_integration.php | 12 | 普通保存/patch/视频/封面/恢复共用 revision，版本化文档拒绝旧客户端 |

四类模拟下游接收仍为 4 次，租户/用户消费记录各 4 条，任务 4 条、媒体资产 3 条；没有真正供应商调用。并发 save/封面测试另连续 3 轮通过；只调用真实投影方法，不运行视频下载/FFmpeg。

前端 30 项纯逻辑/源码契约检查通过；Vue script/template 编译通过。独立临时 Chrome 的浏览器冲突测试增加为 8 项，覆盖草稿归档、未来时间戳、冲突停存、后续草稿导出、明确重读、容量提示与无未捕获异常。所有 API 在浏览器测试中被合成响应拦截，禁止外站/未知写请求；**不能与真实 HTTP 测试拼称浏览器到数据库生成 E2E 已通过**。

本轮失败记录：浏览器运行器最初没有 bundled Chromium，改用已有 Chrome 的隔离 profile；夹具曾误拦截 Nuxt API 模块路径，已缩小匹配；浏览器发现初始化 watcher 误触发保存，修复后通过。HTTP router 最初 SCRIPT_FILENAME 导致 ThinkPHP 误选 app，修复 fixture 的 public/index.php 身份后通过。版本集成 fixture 最初用了不被 managed URI 规则接受的 `fixture/` 路径，改为合成 `uploads/fixture/` 后投影断言通过，未放宽产品规则。

### 9.3 仍未放行的项目

P1 **否**；P2—P6 **NOT_RUN**。普通保存/后台 JSON 写入已共用持久化版本边界，但这不是完整 Graph DTO/权限闭环：普通保存仍需服务端元数据保护、内容/布局版本治理；生产可重复迁移及安装/升级一致性未完成；生成请求 key/hash、提交快照、outbox、恢复对账未实现。G06/G07 仅有部分投影/前端迟到结果证据，不是四类 Provider 完整乱序/删除回调验证。G12 完整手工浏览器生成与开关回归未完成。不得启用 Agent 后台图写入。

原业务库缺少音乐表的风险仍在；真实模型协议、物理文件转存、真实付费小样本、生产迁移与部署均未执行。没有修改原 Worker、PC 本地代理、用户 canvas 11 或既有 Story/Episode 生成服务。

## 10. 2026-09-21 后续实现与实测（取代第 9 节的当前状态，不删除历史证据）

### 10.1 已实现范围

- 图结构的应用安装、应用增量升级、系统升级和完整安装 SQL 已增加版本与幂等回执；在隔离库执行真实 SQL，验证重复执行、旧 JSON/删除墓碑/已有回执保留、四份列定义一致及 key 大小写敏感。完整安装器、应用注册及新租户生命周期仍未测；业务库未执行迁移。
- 版本化普通保存由服务端维护内容/布局版本及任务、费用、资产归属字段，合法旧手工任务绑定验证实际 owned run。旧 schema-v1 的兼容保存仍保留限制，不能泛称所有旧客户端已有 CAS。
- 增加未开放路由的 GenerationIntentService 和 Canvas::submitIdempotent：冻结输入与 Skill 快照，数据库原子建立逻辑 run 与提交意图，claim token/fence/lease 防重复提交；十独立进程仅一个下游接收。下游 PointService 仍是扣费权威。
- 结果不明/过期不重提、不假退款。原 worker 迟到回执保留任务编号与结果，仍为 needs_reconciliation；错误 token/fence 与冲突回执拒绝，普通轮询不能擅自把未核实状态变为成功。完整对账查询、进程终止恢复和生产级 outbox 扫描尚未完成。
- 四类结果写回验证 active generation、输入签名和节点存在；删除/旧回调不复活节点、不覆盖新结果，历史保留。视频复用原异步封面任务和租户存储元数据，不在图锁内下载或提取媒体。
- PC 本地草稿保存 base_revision/base_document_token，保留人工导出与明确重读，不按时间戳覆盖云端。未实现局部未确认操作自动重放。

### 10.2 最新验证与失败记录

Server 功能基准 `2d03320bc`，本地 develop 合入后完整串行回归：

| 脚本 | PASS |
| --- | ---: |
| p0_baseline / p0_generation / p0_controller / p0_http | 10 / 16 / 15 / 8 |
| p1_graph / p1_graph_wire / p1_graph_operations / p1_concurrency | 19 / 18 / 11 / 14 |
| p1_poster_save / p1_save_cas / p1_read_recovery / p1_revision_integration | 4 / 5 / 10 / 12 |
| p1_migrations / p1_generation_intent / p1_generation_projection / p1_manual_authority | 10 / 33 / 34 / 10 |
| p0_generation.php idempotent | 24 |

共 253 个断言，exit 0；不是 253 条阶段验收项。各脚本末尾 NOT_RUN 仅说明该脚本的覆盖范围，阶段结论以本节为准。

Web `fdec432` 的真实 HTTP 浏览器场景 7 PASS：独立 headless Chrome → 容器内真实 HTTP/middleware/controller → 隔离 MySQL；四类工具栏建节点、标题及文本编辑、文本拖动后再编辑、保存刷新重开、同文本双标签页冲突与明确重读均通过。账户/模型目录请求仍用空夹具，仅 current/save 转发真实后端；禁止生成及其他写路由、禁止外站，不是浏览器生成全链路验收。

Web 六组纯逻辑/源码契约测试本轮 29 PASS，Vue script/template 编译 PASS。合成响应冲突浏览器 8 PASS；新增草稿版本断言发现导出分支未保留 base_revision，已于 `84a2a66` 修复并复测通过，缓存及冲突后导出均保留权威基础版本。

失败如实保留：真实浏览器最初不能双击文本，定位到 stage 过早 pointer capture，改为文本拖动超过阈值后捕获，编辑/拖动/再编辑验证通过；早期测试错误选到 composer editable 已改为具体文本编辑器。双标签页夹具曾被后来创建的媒体遮挡，改用真实拖动移开文本；曾在第一标签页输入与失焦保存未结束前比较 revision，改为稳定读检查后通过。未使用强制点击或伪造 DOM 事件绕过问题。

### 10.3 P1 准入与用户交互约束

P1 **尚未放行**，P2—P6 **NOT_RUN**。G01 已有真实双标签页文本冲突证据；G02/G03/G04/G06/G07/G08/G09/G10/G11 有数据库或模拟执行边界证据，但不能据此宣称真实 Provider 回调/完整 Worker 已通过。G12 手工连接及四类生成的浏览器闭环、关闭 Agent 开关的完整回归尚未完成。生成意图 schema 仍为隔离测试草案，未注册生产迁移；patch/幂等提交新入口尚未开放，后台 Agent 图写入仍禁止。

用户补充约束：整个右侧区域为 Agent 对话面板，右下角输入框是对话入口；对话使用所选推理模型，图片/视频动作使用各自模型偏好；不额外增加 Agent 入口，不以问答自动创建作品节点或触发媒体生成。进入 P2 时必须在现有面板接入，而非另建独立聊天页面。

分支统一为 `feature/short-drama-optimization`；本地 develop 仅集成与验证。未推送 develop、未部署、未触碰真实付费生成或业务迁移。

## 11. P1 隔离验收结论与 P2 准入

阶段：P1，**隔离数据库 + 模拟 Provider 验收通过，可开始 P2 源码实施**。这不是生产上线或真实供应商验收。代码基线 server `18ce6f235` / web `788c454`（本地 develop 已合入）；后端完整串行 318 PASS、exit 0，前端六组逻辑/源码测试 29 PASS，冲突浏览器 8 PASS，真实 HTTP 手工生成浏览器 14 PASS，Vue SFC 编译 PASS。

| 用例 | 状态 | 行为证据与边界 |
| --- | --- | --- |
| G01 | PASS | 两个真实 Chrome 标签页经真实 HTTP 修改同一文本，旧版本拒绝，数据库保留先成功者；明确重读恢复 |
| G02 | PASS | 四类实际结果投影保持用户移动；独立进程并发保存与后台封面写回；不声称全场景自动合并 |
| G03 | PASS | 独立十进程 Graph receipt 竞争；真实 HTTP 十次重放返回同一回执 |
| G04 | PASS | 服务及真实 HTTP 同 key 换 payload 返回 IDEMPOTENCY_CONFLICT |
| G05 | PASS | 未来本地时间戳不覆盖云端；冲突后草稿保留 base_revision 并可导出 |
| G06 | PASS | 四类 Canvas 幂等适配器在下游调用中删除节点，完成后不复活；真实短剧任务及媒体资产记录仍保留。下游为模拟而非真实回调 |
| G07 | PASS | 四类实际适配器第一调用未返回时执行第二调用，第二结果保持当前，两个任务/资产版本进入历史，无投影重复扣费 |
| G08 | PASS | 数据库 200 边界可保存，201 明确拒绝且原图不变；满图历史恢复返回待恢复 ID 而不截断 |
| G09 | PASS | patch 白名单及版本化普通保存拒绝/剥离伪造成功、费用、归属字段；合法绑定验证实际 owned run |
| G10 | PASS | 旧 from/to、ID、坐标数据库往返；真实端口拖动创建文本→图片参考，刷新后 edges 不变 |
| G11 | PASS | 实际 save 与封面投影在独立进程竞争，两者结果保留；相同视频重复投影不增版本，无封面下游轮询不清除已有封面。未执行 FFmpeg/物理文件 |
| G12 | PASS | 测试租户显式 canvas_agent.enabled=false，真实浏览器手工创建/连线/四类提交/刷新通过；模拟 Provider 接收 4 次，真实租户/用户账本各 4 条，余额 96/92 |

上述 G01—G12：PASS 12 / FAIL 0 / BLOCKED 0 / NOT_RUN 0（仅列出的隔离验收范围）。P2—P6 此时尚未完成，不能据此启用业务租户或宣称整个方案已完成。

新增接口/迁移：`canvas/patch` 按 user 登录场景注册，复用 canvas:use:user，服务端 FeatureGate 默认关闭且仅认当前租户明确启用；请求体不能打开开关。`current/save` 响应补 `agent_enabled`。测试租户开→关验证新入口拒绝，而普通保存仍可用。未改变现有 run 路由的旧手工调用契约；新的幂等适配器供后续 Agent 执行使用。生成意图正式增量/新装/系统升级 SQL 已补齐，23 项迁移测试包含索引一致性与冻结快照保留；测试 Provider 计数表没有进入产品迁移。

故障证据：14 项真实 SIGKILL 子进程测试覆盖 prepared/claimed/模拟接收/accepted 四个持久化边界。prepared 可用冻结输入继续，accepted 可投影；中间未知状态只进入对账，不重提、不退款。35 项 intent 测试包括迟到回执保留、fence 拒绝、旧 prepared 被新请求替代时未提交即取消。生产扫描 Worker/真实 Provider 对账仍留待执行阶段接入，不把测试恢复子进程称为已部署恢复服务。

本轮新增失败与修复：浏览器模型夹具最初未匹配真实 dot-route，已改为正确目录请求；开启真实模拟生成后发现旧富文本盖住新文本、重载恢复无变化也保存、视频参考数组无变化也触发保存、相同视频轮询递增图 revision，均修复后完整回归通过；HTTP patch 测试首次误将现有数字字符串坐标当整数，改为严格验证现有线协议，未使用宽松比较。

剩余风险/边界：旧 schema-v1 不带 token/revision 的兼容覆盖仍明确 KNOWN_GAP，只允许旧未启用并发文档；Agent 使用的版本化路径有 CAS。尚未跑完整安装器/新租户注册生命周期、完整 Nuxt build/typecheck、真实 Provider 协议/物理文件转存和付费样本。现有业务 x_cn 缺少音乐表仍未迁移。P2 继续保持 Agent 默认关闭；付费调用、业务迁移、部署须另行确认。事件/对话表及对话调度将在 P2 扩展，不将当前 generation intent 冒充完整多步骤 outbox。

## 12. P2 会话持久化及执行边界增量（尚未阶段放行）

分支 `feature/short-drama-optimization`，本轮仅 server 源码；web 未修改。新增独立 thread/message/run/event/outbox 五表，新装、应用增量、系统新装和系统升级定义一致，未复用既有 Story/Episode Agent 表。正式增量 SQL 仅在已授权的隔离库执行，业务库和真实 Worker 未动。源码提交 `fe0c1883a`、`0f17d5961`、`ef2f87187`、`8f222cb2e`、`7ed30b106` 均先 feature 提交再合入本地 develop 验证，未推送共享分支或部署。

内部 `ConversationStore` 实现 scoped create/list/messages/events、幂等消息接受；canvas→thread→run 锁序，重复请求先回放再检查忙碌及图版本；一次事务包含用户消息、运行、冻结模型/Skill/ID 引用快照、事件、outbox 和会话游标。发送和读取不会创建或改写作品节点。陌生租户/用户/画布、关闭开关、冲突请求键、旧图版本、缺失引用、未支持附件字段明确拒绝。每个会话暂只允许一个活动运行。

内部 `ConversationExecution` 提供 claim、complete、unknown、expire 边界；仅第一个 claim 获得 token/fence，完成生成顺序消息和持久化事件；过期及结果不明进入 needs_reconciliation，不自动重提、不退款。迟到回复单独保留证据，不作为成功 assistant 消息，重复相同回执稳定、不同回执拒绝。尚无生产扫描调度器、真实模型调用或自动对账；不能把此类称为已运行的生产 Worker。

测试：既有 P0/P1 完整串行 318 PASS；P2 migration 34、conversation 57、独立多进程 concurrency 23、execution 67，共新增 181 个断言。十进程相同请求只落一份消息/运行/事件/outbox；同 key 不同内容竞争和不同 key 同会话竞争只有一方成功。末尾 outbox 插入故障验证先写入的 run/message/event 一并回滚。执行状态四场景包括 success、expire、unknown、直接迟到。均隔离库、不访问真实模型、不改业务积分账本。不是 181 项完整 P2 验收。

本轮失败：首轮 conversation 测试发现 insertGetId 产生字符串而事件读取返回整数，严格游标比较失败；修复 acknowledgement 中 run_id/event_cursor 的输出类型后 57 项重测通过，未改成宽松断言。

**P2 未放行，P3—P6 未开始。** 现有内部快照参数只能由未来服务端解析器提供，不允许直接传 HTTP 请求体。模型可用性/能力校验、真实短剧 Skill 版本解析、素材归属、完整多模态上下文、安全审核、成本/预算确认、模型调用账本绑定、进程故障恢复、API 权限、客户端事件去重/安全渲染/租户切换和右侧面板接入仍未完成。当前快照仅含所选节点的 ID/类型/坐标/文本/prompt/内容版本，不假称已有图片理解。晚到证据事件不应由前端按成功回复展示。旧 schema-v1 无 token 兼容覆盖问题仍为 KNOWN_GAP。

后续明确沿用整个右侧对话区域和右下角输入框，聊天用所选推理模型、媒体用各自模型偏好，不新建独立 Agent 入口。当前 UI 尚未切到新消息链路，仍保留原逻辑；在模型校验和 HTTP/隔离集成测试就绪前不开放新写入口，也不启用业务租户。

## 13. P2 模型解析、会话 HTTP 与有限执行适配层

本节更新第 12 节的“未路由/未解析”状态，不改变 **P2 尚未阶段放行** 的结论。分支仍为 `feature/short-drama-optimization`；仅修改 server，web 本轮未改（`788c454`）。源码节点：`a5bd3d219` 模型解析、`8c0cb58f0` 发送快照、`456ecbede` 会话接口、`5c5af736c` 有限执行适配层、`dba29d669` Skill 版本资格校验。均先 feature 提交再合入本地 develop 验证，未执行业务迁移或启用业务租户，未调用付费模型或发布部署。

已完成：

- `ConversationSettings` 读取与现有画布一致的真实算力模型目录，不用竞品名称或静态模型冒充配置。推理/图片/视频分别解析，未知、禁用和当前租户停售规格拒绝；快照白名单去掉任意密钥、费用覆盖字段。媒体未配置不阻止纯文本对话，推理模型不可用则拒绝。新增 `canvasModelGroups` 仅读模型目录，不读取用户项目/素材列表。
- `ConversationService::send` 在同一接受事务中解析实际已发布短剧 Skill 和模型，首次冻结；请求 hash 包含规范化偏好和 Skill ID/version。重试先回放再解析，后续模型下架或 Skill 更新不会改写既有运行。Skill 解析共享方法现额外排除已删除/未发布版本；原 Canvas Skill 应用测试通过，未声称 Story/Episode 全流程覆盖。
- 新增 `canvas_agent/threads`、`createThread`、`messages`、`events`、`send`，均以 `aigc_short_drama:canvas:use:user` 登录场景声明，既有应用/租户中间件和默认关闭 FeatureGate 生效。HTTP ID 严格规范化，异常按公开代码白名单返回；晚到回复证据不通过公开事件输出正文。send 仅返回 queued，不等待 Provider，也不创建节点/媒体任务。
- `ConversationWorker` 为一次一个运行的服务端有限执行器，先 claim 再调用注入的服务端 Provider interface；Provider 边界已实测没有数据库事务。上下文在接受时冻结最近 38 条消息及本轮消息并明确记录窗口策略；不执行模型工具。无费用预检失败释放会话并失败，Provider 异常/非法工具响应/迟到回复只进入待核实，不自动调用第二次、不伪造退款。不存在默认真实 Provider 或已注册生产扫描命令，不能称为生产调度已接通。

最新完整后端串行回归 **614 PASS，exit 0**：原 499 + `p2_settings` 24 + `p2_send` 26 + `p2_http` 21 + `p2_worker` 44。额外 `canvas_composer_skill.php` 通过，源码 diff whitespace 检查通过。四个新增脚本均使用隔离环境；settings/send 模型及 Skill 由测试合成记录提供，解析走真实数据库/目录服务；HTTP 经真实中间件/控制器/数据库，但完成回复由隔离服务夹具直接驱动；Worker Provider 为接口测试替身，无真实账本和远程质量验收。不能将这些断言数量当成阶段 A01—A15 的全部通过项。

检查发现并记录：既有文本 Market runtime 使用实际 usage 后结算和兼容重试。是否满足新 Agent 的预算/预检、未知结果不重提及账本关联要求仍需专门适配验证；尚未把它直接作为真实 Provider 连接到 Worker，也没有更改共享市场计费/重试行为。内容审核适配亦未完成，不能用预检接口的存在冒充审核已实现。

剩余：真实 Provider Adapter 的所选模型复核、安全审核、计费/预算与 app_task 绑定；持久调度扫描与进程故障恢复；附件/图片理解和完整上下文预算；偏好 CAS、stop/retry、前端安全渲染和事件去重/租户切换；现有右侧对话面板及右下角输入框接新接口。当前浏览器仍运行原前端，不宣称用户已能通过新链路聊天。生产迁移/付费样本/部署继续需单独确认。旧 schema-v1 兼容覆盖仍为 KNOWN_GAP；P3—P6 未开始。

## 14. P2 提交前复核与持久扫描恢复

阶段仍为 P2，**未放行**。本轮仅 server，分支 `feature/short-drama-optimization`；实现提交 `4338cf83a`、`3c3e5063c`，web 未改。未修改共享 PointService/Market runtime 的计费规则，未执行业务迁移或部署，未注册生产常驻任务。

执行边界补充：预检成功不等于仍持有有效提交权限。`authorizeSubmission` 在调用 Provider 前重新锁定会话/运行/outbox，核对 token/fence、活动运行、剩余租约足够覆盖请求超时以及 Agent 开关。outbox 从 processing 变为 submitting，并写 run.submitting 事件后才允许一次外部调用；同一 claim 第二次申请不会再次授权。预检期间租约过期/不足会进入待核实，开关关闭会在无外部请求时失败。预检抛错与过期同时发生也不再误报普通失败或继续提交。提交许可之后发生的开关变化不能声称撤销已发出的请求，仍须对账。

`ConversationQueue::tick` 增加租户限定、有上限、有 cursor 的持久 outbox 扫描。只处理到期 pending；过期 processing/submitting 仅进入 needs_reconciliation，不调用 Provider。Agent 关闭时 pending 保留并延后，由调用方扫描到末尾后重置 cursor 再访问。单条扫描异常返回内部 dispatch_error，不暴露原始错误，也不擅自修改未知状态或阻塞整个批次。该服务需要服务端显式注入 Provider adapter；尚无默认真实适配器或生产 supervisor 注册，不能称为已经部署的常驻 Worker。删除画布等失效记录的运维清理策略尚未实现，扫描会保留诊断状态而不自动删除数据。

新增 `p2_queue_crash.php` **31 PASS**：独立 PHP 子进程在 queued、claimed、handoff、模拟 Provider received、completed 五个持久化边界精确 SIGKILL。重新扫描后 queued 执行一次、completed 不重复执行；中间三个不确定边界只待核实。独立测试接收计数表证实无重复接收，只有已确认结果成为 assistant 消息。另验证关闭开关不提交、分页 cursor、回到零重扫延后项和跨租户不可见。未中断任何业务 Worker。

完整后端串行 **675 PASS、exit 0**：第 13 节 614 + execution 新增 4（现 71）+ worker 新增 26（现 70）+ queue_crash 31。Provider 边界没有数据库事务的断言仍通过；预检后关闭、过期、剩余不足和过期抛错四场景外部调用为零。属于隔离 SQL/模拟 Provider 验收，非真实供应商或付费验收。旧 schema-v1 无 token 写覆盖风险仍单列 KNOWN_GAP。

计费核对遵循方案 16.1—16.2：实际扣费权威仍是原执行服务，Agent 预算不是第二个钱包。本轮仅读取 PointService、文本 Market runtime 和相关方案，确认后结算、重试及待用量恢复路径需要专门适配与测试；没有额外扣费或未经验证的全平台计费改动。下一步仍需完成真实适配器的余额/预算/价格确认、审核、账本引用及未知用量处理，再接生产调度和现有右侧对话 UI。其余 P2 缺项沿用第 13 节，P3—P6 未开始。

## 15. P2 停止接口及独立进程竞争校验（尚未阶段放行）

仅 server，分支 `feature/short-drama-optimization`；web 保持 `788c454` 未改。功能提交 `a3deae3e9`，并发修复 `1801dae11`，测试扩展 `2af629984`。全部先提交 feature，再合入本地 develop 执行隔离测试；没有推送 develop、业务迁移、真实付费生成或部署。

- 新增 POST `app.aigc_short_drama.canvas_agent/stop`，沿用登录及 canvas:use:user 权限，仅接受 canvas_id/thread_id/run_id，身份取认证上下文。跨租户、用户、画布、会话均拒绝。Agent 开关关闭后，所属用户仍可停止既有任务。
- queued/pending 或 running/processing 可以本地确定取消，释放活动会话，不再获得提交许可。预检过程中停止，包括预检随后抛错，均不调用模拟 Provider。
- 已取得 durable submitting 许可或已有未知结果，只记录停止请求并进入 needs_reconciliation，保持会话执行隔离；不会声称上游已取消、自动退款或重新提交。迟到回复保留证据而不成为正常成功消息。完成的任务不会被停止操作倒退。
- 首次独立 stop/permit 进程竞争失败：MySQL REPEATABLE READ 下，锁前身份查询建立旧快照，等待锁后 MAX(sequence) 仍读旧序号，触发 uk_run_sequence 重复。改为持有既有执行锁时使用 current locking read 读取最新事件；成功消息回放、停止请求去重、迟到证据去重也使用 current read，未降低隔离级别、移除唯一键或吞掉冲突。

验证：完整 28 脚本串行回归 **795 PASS、exit 0**（原 675 + HTTP 新增 4 + stop 76 + stop_race 40）。随后扩展并单独重跑 stop_race **52 PASS、exit 0**：10 轮独立进程停止/提交竞争，以及重复停止、重复成功回复、重复迟到回复 3 组竞争各 4 个断言；当前各套件最新结果合计 **807 PASS**，不将其表述为扩展后已再次完整串行运行。测试 finally 仅清理本次合成 canvas scope/config；真实业务 Worker、数据库及积分未改。

尚未验证真实 Provider 取消/退款、实际计费及审核、生产调度、前端停止按钮和整个右侧对话面板接入；前端本轮没有重新验收。P2 仍未放行，P3—P6 不提前执行。下一步沿用右下角唯一输入入口和现有右侧聊天区域，不新增独立 Agent 页面；所选推理模型用于对话，图片/视频偏好独立保留。第 14 节其余缺项及 schema-v1 KNOWN_GAP 继续有效。

## 16. P2 刷新恢复状态读取契约（尚未阶段放行）

本轮分支仍为 `feature/short-drama-optimization`，server 功能 `45d280192`、测试 `dcc0be595`；web `788c454` 未改。新增 GET `app.aigc_short_drama.canvas_agent/run`，沿用 canvas:use:user、登录及应用/租户开关，不新增表或迁移。不改变原四节点生成、Story/Episode 或扣费逻辑。

该接口按认证 tenant/user + canvas/thread/run 完整范围查询未删除记录，只返回 id、thread_id、status、version、can_stop、needs_reconciliation、安全 error_code、创建和更新时间。排队/运行显示可停止，待核实不会承诺取消成功或允许自动重试；错误只输出白名单或 RUN_FAILED。冻结上下文、模型配置、租约 token、原始错误和迟到回复证据不进入响应。状态查询不推进消息/事件游标，不生成消息或启动任务。开关关闭后普通读取仍拒绝，所属用户停止既有任务的例外保持不变。

本轮从合入最新 feature 的本地 develop 串行执行 9 个相关套件：recovery 84、conversation 57、concurrency 23、execution 71、HTTP 37、worker 70、queue_crash 31、stop 76、stop_race 52，合计 **501 PASS、exit 0**。HTTP 比上轮增加 12 个断言；recovery 新增 84 个。重复读取六种状态时五张会话表前后完全相同，软删除 run/thread/canvas 拒绝，未知错误内容被安全映射。`git diff --check` 通过。本轮未重跑 P0/P1、前端或完整安装生命周期，不把历次累计数说成本轮全量测试。

未发现本轮测试失败。适用技能为应用接口规范、Provider/计费边界及共享回归保护：检查已有 Market 文本服务后保持其后结算/兼容重试契约不动，没有将它直接接入生产 Agent。真实付费、预算预留、审核、账本关联仍需专门适配验证。A11 目前只有后端刷新恢复证据，尚无右侧面板浏览器证据，不能标记整体通过；P2 未放行，P3—P6 未开始。无业务库迁移、真实生成或部署。

## 17. 自动续跑：P0 浏览器基线补验与 B05 口径差异

本轮先核对未验收 P0，而非重做 P1 或提前推进 P3。分支 `feature/short-drama-optimization`；server 测试提交 `e0e10dd88` / `97a25f0ca`，web 测试提交 `5a8eee5` / `47d7b0a` / `75694ca`。仅测试及报告修改，无产品 API/权限/迁移变化。两仓库提交后合入本地 develop 执行，结束返回 feature；未推送共享分支。

沿用真实 Chrome → 受限 JSON-lines bridge → 隔离 HTTP 内核 → MySQL 的手工生成测试，新增独立应用不可用的实际 `app.aigc_canvas.project/lists` 探针和真实 `app.aigc_short_drama.asset/lists` 读取。测试路由仅增加这两个 GET；素材探针固定无 project_id，避免读取时触发项目素材修复。夹具预检查两个 app code 均为空，仅创建合成数据，finally 清除自身记录。禁止外部网络/付费 Provider；四类生成仍是最低层模拟调用、实际应用任务/素材/积分账本。

实际执行：在 web develop 使用 README 的 Node/Chrome 命令并设置 CANVAS_TEST_MOCK_GENERATION=1，**16 PASS、exit 0**。包括独立应用中间件拒绝、Agent 显式关闭、四类型手工按钮、端口连线、保存重开、双标签页冲突恢复、恰好四次模拟接收及双方各四条账本、浏览器读取三份 canvas_image/video/audio 资产。既有 P0 B02/B03 隔离基线本轮得到重测证据；真实 Provider 和物理文件转存仍 NOT_RUN，不需付费才能声称模拟范围成立。

失败及修复：第一次探针误用不存在的独立 canvas/lists 路由，HTTP 返回 HTML，桥接 JSON 解析失败，随后清理 EPIPE 遮蔽原始错误。修复子进程诊断与关闭保护，改为实际 project/lists，重测通过。未以非 JSON/404 充当权限拒绝。

B05 **部分 PASS / 原文口径 BLOCKED**：当前 AppAccessService::tenantCanUse 对 installed 默认应用调用 DefaultAppService::ensureTenantDefaultApp 并放行；后者将 shelf_status/enable_status 恢复为 on/enabled。aigc_canvas 属于该默认集合。因此“仅关测试租户开关并保持关闭”与现有策略冲突，此结论来自源码核对，不声称已通过 tenant-only 关闭浏览器测试。本轮用隔离 app.status=disabled（同时合成租户记录 disabled）验证独立应用确实不可用时短剧存取、生成、素材不受影响，不能偷换为 tenant-only 用例通过。

需要确认：是否接受以“独立无限画布应用不可用，而授权短剧仍可用”作为 B05 的隔离验收口径，并保留默认应用自动启用策略；若必须支持租户关闭默认应用，需要另行明确全平台策略变更范围。本任务不擅自修改 DefaultAppService/AppAccessService，不重新触发同一探针或跳过门槛。P0 全项放行在此确认前保持待定，既有 P2 切片保留、不启用业务租户。无生产迁移、部署、发布或真实生成。

## 18. 按用户继续指示恢复 P2：客户端作用域与增量状态

用户在第 17 节验收口径询问后回复“继续”；本轮开工明确说明按所建议口径推进，保留平台默认应用策略，不改全平台开关逻辑。B05 采用“独立应用不可用，而授权短剧仍可用”的已通过隔离边界；tenant-only 禁用不视为已实现。P0 B01/B04 原发现性核对、B02/B03 第 17 节隔离浏览器基线、B05 上述受限口径成立，允许回到 P2；真实供应商与物理文件范围仍 NOT_RUN。后续自动续跑不再重复询问相同的 B05 口径，但必须保留这个限制。

分支 `feature/short-drama-optimization`。web 源码 `8dea7f2` / `e690ad1` 新增独立 `conversation-state.mjs`，server 本轮仅记录证据；无 API、迁移或权限变化。状态模块仅面向现有四节点画布的新会话接口，不复用旧 V2 的未验证 workspace 接口，不修改既有右侧 UI 或旧手工生成路径。

实现边界：tenant/user/canvas/thread 四重作用域；每次切换（含退出后回到相同作用域）推进 epoch，旧请求回包不能写入新状态。消息和事件独立游标，整页验证后原子接受，重复 ticket/并发旧页丢弃；缺失消息序号、重用消息 ID、畸形页不能推进游标。任务快照按版本防止迟到回包倒退，终态不回到运行，待核实也不自动重试。生命周期事件只投影状态，不变成 assistant 文本，私有迟到文本不进入客户端状态。外部取得的是深拷贝，不能篡改内部状态。

验证：web 已合入的本地 develop 执行 Node --test `short-drama-conversation-state.test.cjs`（10 PASS）与 `short-drama-canvas-run-result-policy.test.cjs`（3 PASS），共 **13 PASS / FAIL 0、exit 0**，`git diff --check` 通过。前者实际调用状态模块而非源码正则；后者保护原四节点迟到结果/删除节点行为。无 HTTP、数据库、模型或文件写入。纯字符串保留测试不是 HTML 安全渲染验收；浏览器 DOM/XSS 验证仍 NOT_RUN。

此模块尚未绑定右侧面板，也尚无新链路发送、轮询和刷新浏览器证据，A11/A13/A15 不因此整体放行。下一步仍是现有右侧面板的受控接口接入与浏览器测试、真实 Provider 的账本/审核/预算适配；不得注册付费生产调度或启用业务租户。P2 未完成，P3—P6 未开始。没有生产迁移、付费生成、部署、发布或源码推送。

## 19. P2 有界客户端读取协调层

web `21180aa`（feature/short-drama-optimization）新增 `conversation-reader.mjs`，server 仅更新报告。读取协调层组合第 18 节状态模块，显式传 tenant_id/canvas_id/thread_id，不把 user_id 当作请求身份；API transport 由后续现有页面接入注入。本轮不调用真实 HTTP，不导入旧 canvas_v2/workspace 接口，不开启业务 Agent，也不改变现有节点请求卡和手工生成。

每次 refresh 串行读取 run → messages → events，各流每次至多一页；重叠调用返回 busy 而不新增请求。先读取任务终态，再排空消息和事件页，避免成功后立即停轮询遗漏最后回复。queued/running 建议继续轮询；终态/待核实在页面读尽后停止，不做无界重试。切换 scope 后旧响应及旧 finally 不能污染或解锁新 scope 的请求；dispose 清空状态。中途读失败保留已经成功提交的流游标，错误只输出通用 code，不泄漏原始诊断。

在合入最新源码的 web develop 执行三个 Node 行为套件：reader 8、state 10、原四节点 run-result-policy 3，共 **21 PASS / FAIL 0、exit 0**；git diff --check 通过。模拟 Promise 延迟/失败覆盖重叠、跨租户旧回包、新请求锁保护、分页 101 条、最后回复、读失败恢复、dispose 和畸形页。测试名称中的 contract 指传参行为，不代表真实网络或中间件验收。本轮未重跑后端数据库/浏览器套件，没有新阶段放行。

尚未接入右侧面板和实际计时器/HTTP transport；发送幂等恢复、刷新选择会话、DOM 安全渲染及浏览器 A11/A13/A15 仍 NOT_RUN。P2 不因此宣称完成。后续继续把此模块绑定现有右侧聊天区域，先在隔离 mock 环境验证，再处理真实模型预检/账本等未完成能力；P3—P6 仍未开始。两仓库保持 feature 分支，无生产迁移、付费生成、发布或部署。

## 20. P2 右侧 Agent 面板隔离浏览器验收（尚未阶段放行）

本轮把第 18—19 节的 scoped reader 接入现有右侧面板及右下角 `CanvasComposer`，没有新建 Agent 页面，也没有改变原四节点画布的手工生成入口。server 测试桥接提交 `9001a7a65`；web 面板/API/浏览器测试提交 `96de185`、`ba2fc95`、`cde054b`、`db15e43`。每个 feature 提交均已合入各自本地 develop 后执行验证，随后切回 feature；未推送 develop、未执行生产迁移、未部署或调用付费模型。

实现边界：当服务端 `current` 返回当前租户显式启用的 `agent_enabled` 时，只有右侧面板替换为会话组件；关闭时完整保留原 ComposerChats、CanvasComposer 和既有节点流程。会话组件只调用 `threads/createThread/messages/events/run/send/stop`，使用认证租户请求头，POST body 不再携带可伪造的 `tenant_id`。浏览器的推理模型选择仍来自服务端实际画布模型目录；隔离场景插入本地市场产品与 SKU 仅为目录解析夹具，不调用其 Provider。图片/视频默认偏好保持独立；素材附件在未完成授权与多模态验收前由 UI 明确拒绝，不会伪装为已发送给 Agent。消息用 Vue 文本插值渲染，未使用 `v-html`、`innerHTML`、浏览器 Provider 请求或原 canvas 媒体 run。

验证均在本地 develop：

| 范围 | 结果 | 证据与限制 |
| --- | --- | --- |
| Agent HTTP 真实中间件/控制器/隔离 MySQL | PASS，37 | 登录、租户/用户隔离、严格 ID、幂等、刷新、停止、开关和不写图均通过；Provider、账本与生产迁移仍 NOT_RUN |
| Composer/reader/state/SFC 行为套件 | PASS，35 | 包含新 POST scope 边界、序列/游标、旧 scope 回包、状态终态和 SFC 编译；不是浏览器 Provider 验收 |
| 启用 Agent 的真实 Chrome → 受限 HTTP bridge → 隔离 MySQL | PASS，5 | 右侧面板出现；下方输入创建一个 thread/message/run/outbox；刷新恢复 queued 且没有第二次发送；停止转 canceled；`<img …>` 内容作为文字显示、不生成 DOM 图片、不执行脚本、无 page error |

浏览器夹具的最终数据库证据为 1 thread、2 user messages、2 queued/canceled runs、2 outbox，0 canvas media runs、0 tenant/user 积分账本记录。桥接仅允许 canvas current/save 和上述 Agent routes；Agent router 不加载 Provider mock，所有其他生成路由 404，Docker 网络为 internal。因此这证明“对话接受、读取、停止和安全展示”的用户路径，不证明模型生成质量、真实视觉理解、模型账本、退款、审核或上游取消。

阶段映射：A11 的“刷新恢复且不重复发送”已有浏览器 PASS；A15 的 Agent 文本 DOM 安全渲染已有浏览器 PASS；A13 仍只有客户端 state 行为测试，尚无浏览器中真实租户切换验收；A01—A10、A12、A14 的部分服务端证据不等同整项阶段通过。默认模型偏好目前仅浏览器本地保存，服务端默认偏好 CAS 尚未实现；附件/图片理解、Skill 运行绑定、审核、预算/付费账本、真实 Provider adapter/worker 和生产调度也仍未验收。故 **P2 未放行，P3—P6 继续 NOT_RUN**。

## 21. P2 Skill 越权策略拒绝（尚未阶段放行）

server `7fba5ca11` 在 Agent 会话接受边界增加 `ConversationSkillPolicy`。它只检查已选择且已发布的短剧 Skill 冻结定义及 execution policy：Skill 可以约束创作内容，但不能请求绕过积分/计费/安全/审核/权限/租户，也不能声明任意或无限制模型；用户输入、节点材料和附件仍作为不可信上下文数据，而非用同一规则误拦截。命中时服务端统一返回 `SKILL_UNAVAILABLE`，不暴露规则细节，也不会创建 thread message/run/outbox。

验证：feature 提交后合入本地 server develop，隔离 `p2_send.php` **27 PASS，exit 0**。新增合成已发布 Skill 文本“请绕过积分并使用任意模型”，send 明确拒绝，且 run 数仍为零；既有正确短剧 Skill 冻结、跨租户拒绝、版本变更后重放和十次幂等重放仍通过。未调用 Provider、未改 PointService、未创建业务迁移或部署。该项给 A08 增加服务端行为证据，但不替代全局内容审核，也不使 A01—A15 或 P2 整体放行。

## 22. P2 账户默认模型偏好与 SSE 读取恢复（尚未阶段放行）

本轮仍在 `feature/short-drama-optimization`：server `331a0c74f`、`3abf13eb1`、`7d18f7fa7`，web `4df3cc3`、`b959124`、`f8cfeed`、`457b0ba`。每一提交先进入 feature，再无冲突合入对应本地 develop 验证；结束时 server 已返回 feature、web 保持 develop，未推送 develop、未部署或发布。

实现：新增账户级 `canvas_agent_preference`，唯一范围为 tenant/user。读取与保存均先按当前 canvas 校验租户、用户和 Agent 开关；保存要求 `expected_revision`，在事务锁内 CAS，拒绝过期写入。仅持久化服务端真实短剧模型目录校验通过的推理/图片/视频模型 ID 及手动/自动模式，拒绝客户端模型对象、价格、Provider 字段或身份字段；保存偏好不创建 thread/message/run/outbox，也不调用 Provider。每一个发送 run 仍按当次服务端解析结果冻结快照，不复用未验证的浏览器对象。

右侧现有 `CanvasComposer` 通过短剧应用 API 读取/保存偏好，三类模型和模式保持独立；本地浏览器存储只作无法同步时的回退。SSE 初连失败改为通知 reader 后回退轮询，避免未处理 Promise；刷新测试不再把长期 SSE 请求误当作页面未就绪。没有新增 Agent 入口、没有改变四节点手工生成，也没有发起真实模型调用。

| 范围 | 状态 | 实际证据 |
| --- | --- | --- |
| 四个安装/升级来源的偏好表列和索引一致 | PASS | 隔离 `p2_migrations.php` 41 PASS；表仅应用到 `short_drama_agent_test`，未迁移业务库 |
| 实际目录解析、租户/用户隔离、CAS 和禁用执行开关 | PASS | 隔离 `p2_settings.php` 36 PASS；使用合成市场模型记录，Provider 调用为零 |
| 登录/所有权、严格请求白名单、HTTP CAS 与不创建任务 | PASS | 隔离真实中间件/控制器/数据库 `p2_http.php` 47 PASS；无媒体 run、无积分账本写入 |
| 模型弹层账户保存、刷新恢复、停止和文本安全渲染 | PASS | web 组件/SFC 17 PASS；Chrome → 受限 bridge → 隔离 MySQL 6 PASS；默认模型保存后为 0 thread/0 run/0 charge |
| 真实 Provider、真实付费、生产迁移、部署 | NOT_RUN | 本轮明确不执行；隔离网络无外网、Provider mock 未接入该用例 |

本轮先出现两项测试问题并修复后复测：服务端首次保存响应与读取响应的关联数组字段顺序不一致，已固定为模型字段后模式字段；SSE 连接失败最初会留下未处理 Promise，已改为受控回退。长期 SSE 使浏览器测试的 `networkidle` 不再成立，夹具改为等待 DOM 后验证持久 UI。以上均有复测通过结果，不把第一次失败隐去。

阶段结论：默认模型偏好不再只是浏览器本地保存，P2 仍**未放行**。A01—A03 的图片/候选歧义、附件与视觉理解，完整内容审核，Skill 到执行计划的绑定，文本调用的预算/唯一账本/未知用量处理，生产调度与对账，及 A13 的真实浏览器租户切换仍为 FAIL/BLOCKED/NOT_RUN（按各项尚无完整行为证据，不以本轮测试替代）。P3—P6 继续 NOT_RUN。

## 23. P2 租户作用域切换补验与审核边界核对（尚未阶段放行）

web `1d85ab4`（页面作用域失效保护）与 `7b6f330`（浏览器用例）均先在 `feature/short-drama-optimization` 提交，再无冲突合入本地 develop 验证；server 本节只记录审计结果。未执行生产迁移、部署、推送或真实/付费 Provider 调用。

右侧会话 state/reader 原本已能在 tenant/user/canvas/thread 改变时丢弃旧回包，但页面级画布文档读取和保存没有同等保护。本轮为画布 route tenant、canvas ID 与认证用户建立作用域 generation：切换时立即清空节点、选中状态、Agent 开关、模型 revision 与内存中的 document token；旧读取、旧保存响应只有在仍属于相同作用域时才可安装或写回。清理过程不把旧作用域内存写入新作用域 localStorage。Agent 子组件因开关先关闭而卸载，其 SSE AbortController 也不会继续向新面板投递。

验证在 web develop 中实际使用 Chrome、Nuxt router 的 SPA `push` 和受限 HTTP→隔离 MySQL bridge：先创建一条持久化的 Agent 消息，再切换到 bridge 不拥有的 tenant 94012。新租户的所有 API 请求明确返回 scope 拒绝；页面在新文档接受前已卸载旧 Agent 面板、节点数为零、旧消息不在 DOM，且无未捕获 page error。完整浏览器流程 **7 PASS / FAIL 0**，同时重新执行 Composer/state/reader/SFC 行为套件 **35 PASS / FAIL 0**。因此 **A13（切换租户后旧请求返回）在此隔离浏览器范围为 PASS**；该项不包含真实业务租户数据读取。

内容审核状态经源码与平台服务核对后为 **BLOCKED（缺少产品策略，未以弱规则冒充完成）**：现有 `AigcLlmSensitiveWord` 属于另一个 app 的数据域；直接复用会破坏短剧应用隔离。短剧现有 `checkSensitivePrompt` 只有少量凭据词拦截，`ConversationSkillPolicy` 仅审核已发布 Skill 越权文本，二者都不能替代 Agent 用户消息审核。当前短剧 Agent 没有自身的可配置审核策略、审核 Provider adapter、最小化审计记录/保留期或管理员申诉/复核规范。未新增一个静态词表来声称“内容安全已通过”，也未发送任何用户内容到外部审核服务。要解除该阻碍，需产品确认短剧 Agent 的审核等级、可编辑范围、人工复核与审计保留策略，然后按 tenant/app scope 另行实现和隔离验证。

P2 继续**未放行**：A01—A03 图像理解/歧义候选、A02 可追踪文本版本、A09 完整素材指令处理、A10 上下文补问策略、A12 有界结构化工具修复、A14 一次规划提交意图、以及真实执行的预算/账本/未知用量和生产调度/对账均仍需逐项行为证据。P3—P6 均 NOT_RUN。

## 24. P2 默认审核策略实现（尚未阶段放行）

产品已确认短剧画布 Agent 的默认策略：审核输入与输出、命中后直接拒绝、只保留最小审计记录 30 天、暂不进入人工复核。本轮新增短剧应用专属的策略读取与安全审计，不复用 `aigc_llm` 的敏感词表，也不保存用户原文、模型原文、素材 URI、模型密钥或规则内容。

- 输入在会话入队前按 tenant/user/canvas/thread 校验归属并审核。命中后返回统一 `CONTENT_BLOCKED`，不创建 message/run/outbox，不进入 Worker，因此不会发起模型调用或产生 Agent 侧计费。
- 输出通过 Market 文本运行时的服务端回调在共享结算前复核；命中后先释放仍处于预留状态的共享用量，再以 `SAFETY_OUTPUT_BLOCKED` 终结运行，不写 assistant 消息。客户端仅看到 `CONTENT_BLOCKED`，不看到命中规则或模型回复。
- 审计表范围包含 tenant、user、canvas、thread、run、输入/输出方向、策略版本、决定、摘要哈希、长度、是否越过 Provider 边界和到期时间；唯一键防止同一请求重试无限堆积。Worker 每轮有界清理到期记录。既有已落库请求先按原幂等回放，不使用后来变更的策略重新解释。
- 当前规则引擎只执行该租户短剧配置中的显式拒绝词；没有供应商语义审核器时不把空规则集冒充为“全内容识别”。真实语义审核 Provider 仍为 NOT_RUN，必须在引入并验收具体 Provider 后才可声称覆盖更广泛的违规类别。

隔离验证：`p2_migrations.php` 49 PASS / 0 FAIL，验证新装、完整安装、应用增量和系统增量的列/索引一致及审计去重；`p2_safety.php` 10 PASS / 0 FAIL，覆盖输入无 run/outbox、输出不发布、摘要脱敏、跨租户隔离和 30 天清理；`p2_send.php` 27 PASS / 0 FAIL，确认策略加入后既有模型/Skill 快照和幂等重放未回退；`p2_worker.php` 99 PASS / 0 FAIL，确认 Worker 不创建媒体任务且既有冻结上下文保持。

本轮没有实际调用付费模型、没有执行本地业务库迁移、没有部署或发布。输出审核在真实语义 Provider 上的质量与最终计费对账仍 NOT_RUN；附件上传/撤销/授权语义也仍未验收。因此 P2 仍未放行到 P3，下一项应先补齐附件语义并在已授权环境验证。
