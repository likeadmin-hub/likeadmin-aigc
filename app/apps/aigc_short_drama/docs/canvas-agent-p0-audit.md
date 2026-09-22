# 短剧画布 Agent P0 核对报告

> **当前结论（2026-09-22，第 31 节）**：按原始验收清单 A01—A15，P2 本地阶段已通过，可进入 P3。此前将 PDF/Office、完整取消恢复、外部语义审核 Provider、生产部署等泛化为 P2 阻碍的结论在此纠正；历史记录保留但不再代表当前门槛。P3—P6 尚未验收完成。P0/P1 沿用第 18 节限定口径及既有验收证据。

## 最新增量：P2 文本附件发送、移除与恢复（2026-09-21）

已接通右下角 TXT/Markdown 多附件路径：浏览器读取文本，发送前移除的附件不进入请求；提交后按消息冻结姓名和内容，刷新/SSE 恢复附件卡，可展开查看原文。历史附件以 user-role 不可信材料参与后续对话，不提升为系统指令或用户确认的风格约束。单文件 100KB、总文本 400KB、最多 10 个，服务端拒绝任意 URL、路径、二进制 NUL、无效 UTF-8 和多余字段；输入审核包含附件内容。附件内容/顺序进入请求幂等身份，移除或修改附件后前端生成新请求键，避免复用旧请求体。

本次不新增表或接口路由；`send` 增加可选 `attachments: [{type: "text", name, content}]`，复用现有消息附件列。无附件历史保持兼容。文本由用户主动提供，不涉及服务器文件读取；**图片/素材库资源的引用授权与撤销仍未完成，不能将本次文本验收等同所有附件能力完成**。移除草稿引用不删除素材库资源，已发送文本作为消息历史保留。

提交均先进入 `feature/short-drama-optimization`，再 `--no-ff` 合入本地 develop 执行。server 实现 `bbef6f598`、测试 `ad1cc6b98` / `dcfc3e7da`；web 实现 `4a50342`、浏览器测试 `d903656` / `a7c2ffc`。

| 范围 | 结果与边界 |
| --- | --- |
| 隔离 MySQL 附件行为 | PASS，`p2_attachments.php` 17 项，包含归属拒绝、内容与顺序冻结、重试冲突、消息恢复、移除后新消息不携带附件、无媒体任务 |
| 既有后端消费者 | PASS，`p2_send`、`p2_worker`、`p2_conversation`、`p2_http`、`p2_safety`；Worker 100 项，审核 11 项，新增模型输入附件与附件命中审核断言 |
| 前端状态/读取/附件 | PASS，三个 Node 套件共 22 项 |
| Chrome → HTTP → 隔离 MySQL / SSE | PASS，10 项，真实文件选择、移除、发送、SSE、刷新、附件文本 XSS、停止、租户切换；媒体任务和积分写入均为零 |
| 真实 Provider / 常驻 Worker 加载 | NOT_RUN，本轮未调用付费模型、未重启业务 Worker，不声称常驻进程已加载附件实现 |
| 图片附件授权、视频/音频/PDF/Word 理解 | NOT_RUN，仍需后续实现与验收，P2 未放行 |

首次回归发现旧测试仍期望拒绝所有附件，已改为断言非法 URL 附件被拒绝并复测通过。HTTP 与浏览器共用夹具并行时触发非空保护，改为串行后通过；浏览器首次因 3000 端口未启动失败，恢复本地 PC 预览后重跑；模型选择弹层遮挡移除按钮的测试步骤已补齐关闭动作，无强制点击。均未绕过生产接口或权限以使测试通过。两仓库 diff 检查通过；无业务库迁移、付费生成、部署、发布或推送。

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

补充回归与本地验证：上述功能提交合入本地 `develop` 后，13 个 P2 隔离套件完整串行运行 **665 PASS / 0 FAIL**（migration、conversation、concurrency、execution、settings、send、HTTP、safety、worker、queue crash、stop、stop race、recovery）。用户已授权的本地 `x_cn` 仅应用该条 `CREATE TABLE IF NOT EXISTS` 审计表增量，确认创建 17 列；应用前 outbox 只有历史 `done/canceled`，没有 pending/processing/submitting 项，随后以 TERM 平滑重启常驻 Worker 并确认新 PID。租户 1 读取到 `enabled=true`、`execution_enabled=true`、输入/输出审核、直接拒绝、30 天、无人工复核。未运行真实 Provider、没有写入真实对话/审计数据、没有扣费、生产迁移、部署或发布。

## 25. P2 图片附件登记、撤销与执行前授权复核（尚未阶段放行）

本轮完成第 24 节明确留下的图片附件语义；不改变四节点画布、既有短剧生成、计费或业务库 schema。server 功能/测试提交 `0921491db`、`5bf0b9e6e`、`a31d5f468`、`3dc896425`，web 提交 `1391625`、`fa69725`、`30b44a2`；均先在 matching feature 分支提交、无冲突合入本地 develop 后验证，未推送 develop、未运行生产迁移、部署或付费生成。

- 右下角 Agent 输入框最多接受四个已完成的图片附件。素材库图片直接复用已有短剧 asset ID；本地图片上传成功后调用既有短剧 `asset/register`，登记为当前画布资产后才允许发送。浏览器仅发送 `{type,image,asset_id,name}`，不上传 storage URI、域名、引擎、用户身份或模型参数。文件移除只从草稿移除，不删除共享素材；已发送消息保留其当时的公开附件摘要，新请求不会重新携带已移除项。
- 接收事务锁定同 tenant/user、未删除、ready 的短剧资产，且只允许当前 canvas 或 asset-library（`canvas_id=0`）图片类型；冻结的内部 URI/storage 元信息只进入 run context。其他用户、其他租户、其他画布、非图片/未就绪/删除资产和含伪造 URL 的请求一律拒绝。消息读取、事件和 SSE 只投影 asset ID/name，绝不返回 URI 或存储元数据。
- Provider 前置检查和实际 generate 均重新读取冻结资产的归属、删除状态与可用 URI；发送后被删除/撤销的引用返回 `IMAGE_REFERENCE_UNAVAILABLE`，不会以过期 URI 调用模型。图片附件与画布已选图片合并后上限四项。文本附件继续视为不可信的 user-role 材料，不会变成 system 指令或创建节点/媒体任务。

实际验证均在已合入本地 develop 的隔离环境完成：

| 范围 | 结果 | 证据 |
| --- | --- | --- |
| 后端附件归属、冻结、移除、投影与删除后复核 | PASS，22 | `p2_attachments.php`：当前画布及素材库图片顺序冻结、跨用户拒绝、消息无 URI、模型上下文无 URI、删除后执行前拒绝、无节点/媒体 run |
| 真实中间件/控制器 HTTP | PASS | `p2_http.php`：拒绝伪造 URL、接受所属 asset ID、公开投影无 URI、删除后仍可 claim 但在执行复核失败、无图/账本副作用 |
| Chrome → 本地上传模拟 → 真实短剧 asset/register → Agent API → 隔离 MySQL | PASS，11 | 新增用例验证本地上传登记后仅发送授权 asset ID；原有模型偏好、SSE、文本附件移除、刷新、歧义、停止、租户切换和文本 DOM 安全用例同时通过 |
| web 附件/状态/读取行为 | PASS，24 | Node 测试确认图片 helper 丢弃 URL/storage 字段、最多四项、错误项拒绝，状态层不接受畸形公开投影 |

浏览器桥接只为该合成身份放行 `asset/register` 这一 POST；其他素材写入和所有生成路由仍拒绝。上传 API 响应本身由浏览器夹具模拟，且使用隔离 internal Docker 网络；因此这证明真实前后端合同、资产授权和撤销语义，**不证明**物理对象转存、视频/音频/PDF/Word 解析、图像理解质量、真实 Provider、真实计费或生产 Worker。

P2 仍未放行：除上述附件图片范围外，A01—A03 的真实图像理解/候选歧义与可追踪文本版本、A09 完整多媒体素材指令、A10 上下文补问、A12 有界结构化工具、A14 单次规划提交，以及真实执行的预算/唯一账本/未知用量、生产调度与对账仍缺完整行为证据。P3—P6 继续 NOT_RUN；不会以本轮附件验收替代这些门槛。

## 26. P2 确认式文本写回与连续会话刷新修复（尚未阶段放行）

用户已确认采用“先展示结果、再确认写回”的文本流程。本轮复核既有服务端 `apply_agent_text`：只允许成功 run 写回该 run 冻结的已选文本节点；节点的内容、提示词和 content revision 任何一项与冻结来源不同即返回冲突，不覆盖用户编辑。确认写回采用 GraphService 的版本化 patch、request receipt 幂等和同一 canvas 锁；只更新文本内容版本，保留节点位置，原始选中文本继续保留在会话引用中。

web `c5688e9` 增加右侧“写回原文本节点”确认框，明确告知原文可追踪与冲突拒绝。浏览器真实隔离流程创建文本节点、选择后发起对话、用 SSE 落库回复、展开原文引用、确认写回，并验证新版本内容与版本号递增、原始内容/版本仍在冻结引用中。停止上一轮后立即发送下一条消息时，测试还发现 Reader 可能与旧 SSE/stop 读取竞争，导致已持久化的新用户消息只能在刷新后显示；改为只在 `busy` 时合并安排一次零延迟的后续读取，避免无界轮询或全画布重算。该即时展示用例已复测通过。

验证：本地 develop 上 Chrome → 受限 HTTP bridge → 隔离 MySQL **12 PASS / 0 FAIL**，含确认写回、文本附件撤销、SSE、刷新、候选歧义、停止、图片附件、租户切换和 XSS 文本渲染；web Node 附件/state/reader 行为套件 **24 PASS / 0 FAIL**。未调用 Provider、未创建媒体任务、未扣费；确认写回不是生产短剧正文/剧集写入，也没有执行生产迁移或部署。

P2 放行门槛仍未满足：A02 现有确认式文本版本证据，A03 仅候选歧义证据；A01 实际图片理解、A09 多格式素材处理、A10 上下文补问、A12 工具修复、A14 规划提交以及真实模型账本、审核 Provider、调度对账仍未完整验收。P3—P6 继续 NOT_RUN。

## 27. P2 未满足项复测与异常模型输出修复（尚未阶段放行）

本轮按 P2 尚未满足项复核代码和行为测试，先修复了一个可在隔离环境复现的缺陷：Provider 已返回结果但 `tool_calls` 不是空数组（例如畸形 JSON 或越权 `delete_canvas` 工具）时，旧 Worker 会把它归为未知上游结果并进入 reconciliation。P2 的合同不允许执行工具，因此该情况是**已知的无效模型输出**，不应重试或伪装成未知。

- `ConversationWorker` 现在将空/超长文本、畸形 `tool_calls` 和任意工具调用统一终结为 `UNSUPPORTED_MODEL_RESPONSE`；不发布 assistant 消息、不重试、不创建媒体任务、不写画布。租约过期或传输异常仍保持 `needs_reconciliation`，不会把真正未知的收费结果误标为失败。
- 入队 `run.queued` 事件包含不可变 P2 intent：`conversation`、`tools=[]`、`media_generation=false`、`graph_mutation=false`。它与原有事件 cursor 共存，避免前端 SSE/刷新因新增事件而漏读或重复读取。十次同 request key 的重放保持同一 run/同一事件/同一 outbox。
- 图片理解链路维持显式已授权 asset 的最多四张、顺序冻结与执行前复核；模型选择在本地市场目录预检查 `requires_vision=true`。文本附件只以 user-role 不可信材料传入；TXT/Markdown 之外、视频/音频及未解析 PDF/Word 在当前 P2 UI/接口明确拒绝，不会被静默上传、作为提示词执行或创建任务。PDF/Word/音视频的内容提取与理解不是本轮已经实现的能力。
- 已给出的画风、比例、时长以受限 `known_creation_constraints` 进入后续 user 消息；隔离 Provider 断言它不会替换用户本轮请求或成为 system/tool 输入。

在 server 本地 `develop` 串行执行 `p2_migrations`、`p2_conversation`、`p2_conversation_concurrency`、`p2_execution`、`p2_settings`、`p2_send`、`p2_http`、`p2_safety`、`p2_worker`、`p2_queue_crash`、`p2_stop`、`p2_stop_race`、`p2_recovery`、`p2_attachments`：**698 PASS / 0 FAIL**。PHP lint 和 diff check 通过。web 本地 `develop` 执行附件/state/reader/SFC 合同：**25 PASS / 0 FAIL**；Chrome → 受限 bridge → 隔离 MySQL：**12 PASS / 0 FAIL**。所有测试使用 internal Docker 网络、隔离数据库、模拟 Provider；未复制私有素材或密钥。

仍不能标记为已全部放行的项目：真实付费 Provider 的视觉质量/多格式解析质量、真实积分账本结算与未知用量对账、外部语义审核 Provider、生产 Worker supervisor/调度及生产迁移/部署。这些需要外部服务或生产授权；本任务边界不调用付费 Provider、不执行生产迁移或部署，故保持 **NOT_RUN**，不以模拟测试替代。P2 的本地安全合同已通过；若 P3 的进入条件要求上述生产级外部验收，则仍为 BLOCKED。

## 28. P2 真实付费文本 Agent 与账本验收（2026-09-22）

用户明确授权本轮真实付费测试，总额上限为 2000 积分。使用 tenant 1 / user 1 / canvas 17 的新建会话提交一条最短纯文本请求：只回复“真实 Agent 验收成功”，不得创建节点、媒体或工具调用。常驻 `short-drama:canvas-agent-worker --tenant=1` 使用市场目录中的 `qwen3.6-plus` 完成一次真实调用。

| 检查项 | 结果 | 真实证据 |
| --- | --- | --- |
| 对话与 SSE/UI 投影 | PASS | run `17` 从 queued → running → submitting → success；右侧面板显示用户请求和“真实 Agent 验收成功” |
| Provider 与账本关联 | PASS | `ai_app_task` `1069` 关联 `aigc_short_drama_canvas_agent_run:17`；`ai_consumption_log` `1091` 的 provider 为 `power_market`、model 为 `qwen3.6-plus`、状态 `success/settled` |
| 真实结算与额度 | PASS | 实际用户与租户扣费均为 `0.623` 积分；用户余额 `874.82 → 874.20`、租户余额 `70567.43 → 70566.81`，低于授权上限 |
| 非媒体约束 | PASS | 本 canvas 的 `aigc_short_drama_canvas_run` 仍为 `0`；事件 intent 固定 `tools=[]`、`media_generation=false`、`graph_mutation=false` |

该真实样本只验证一次纯文本、成功结算和账本关联，不扩大为图片视觉质量、多格式素材解析、取消/退款、未知 Provider 用量、外部语义审核或生产迁移/部署的验收。这些项目仍保持各自的 NOT_RUN/BLOCKED 状态。

## 29. P2 真实图片理解、附件存储修复与 Worker 重启验收（2026-09-22）

用户授权仅在本地 tenant 1 使用画布素材与无敏感测试文件，每次真实调用不超过 2000 积分；本节没有远程生产操作、部署或生产迁移。

首次真实图片调用使用本地上传的仓库静态测试图片，Provider 返回 `InternalError.Algo.InvalidParameter`。账本 `1092` 明确为 `failed/refunded`、实际用户及租户费用均为 `0`；运行 `18` 因当时的上游失败保留为 `needs_reconciliation`，没有把它标作成功或再次自动提交。定位后发现旧 PC 上传响应可携带空 storage 字段，使短剧 asset 的空值覆盖 `tenant_file` 中已验证的对象存储元数据，Provider 因而收到了不支持的数据 URL。

修复提交 `8d1aa5f2e` 与 `b45f64c8c`：资产登记对空 storage 字段回退到受控上传记录；执行前在冻结身份严格校验不变的前提下，仅对同 tenant/user 的 `tenant_file` 恢复缺失的存储元数据。真实 FPM 禁用源码时间戳检查，因此在本机平滑 reload PHP-FPM 后，确认无活动 Agent run 时 TERM 常驻 Worker；supervisor 将 Worker 从 PID `1256124` 重启为 `1256848`。隔离 internal Docker 的 `p2_attachments.php` 和 `p2_worker.php` 复测均 PASS（附件授权/撤销、冻结、无 URI 投影、执行前复核、无媒体副作用与 Worker 有界行为）。

第二次使用同一无敏感图片、独立新会话及默认视觉推理模型 `qwen3.6-plus`，真实结果如下：

| 检查项 | 结果 | 真实证据 |
| --- | --- | --- |
| 已授权图片附件与视觉响应 | PASS | run `19` 完成；右侧面板显示图片内容、配色和构图的实际分析回复；附件公开投影只有名称与 asset ID，不显示 URI |
| 输入/输出审核 | PASS（默认策略范围） | 最新安全审计 `9` 为 `passed`；输入和输出均经过短剧应用专属审核边界。外部语义审核 Provider 仍未配置，不能扩大为语义审核通过 |
| Provider/唯一账本结算 | PASS | `ai_app_task` `1071` 关联 run `19`，`ai_consumption_log` `1093` 为 `power_market` / `qwen3.6-plus` / `success` / `settled`；实际用户与租户费用均为 `2.0034` 积分，低于单次 2000 上限 |
| 不生成媒体或改写画布 | PASS | 事件依序 `run.queued → run.running → run.submitting → run.succeeded`，canvas 17 的媒体 run 数仍为 `0` |
| 常驻 Worker | PASS（本机） | 重启后 Supervisor 重新拉起 tenant 1 Worker，真实图片 run 由新 PID 处理并成功结算 |

本节使真实文本/图片 Provider、实际账本、默认审核边界、图片附件授权与本机 Worker 路径具备行为证据。仍未完成：PDF/Word、视频和音频的内容提取/理解；外部语义审核 Provider；已提交上游请求的真实取消、未知用量查询与退款对账；生产调度/部署。它们继续是 P2 的未放行项，不能被本次图片成功替代。

## 30. P2 本地权威账本对账与真实提交后停止验收（2026-09-22）

本轮在用户明确授权的本地 tenant 1 / user 1 / canvas 17 上验证了提交边界和对账收敛；未执行远程生产操作、部署或生产迁移。真实停止样本 `run 20` 已写入 `run.submitting` 后点击停止，因此系统没有伪称“上游已取消”：它记录 `run.stop_requested` 并进入 `needs_reconciliation`。随后本地权威账本显示关联 `ai_app_task 1072` 为 `success`、消费记录为 `success/settled`，实际用户与租户费用均为 `0.5796` 积分（低于单次 2000 上限）。

为避免这种已知终态永久占用会话，新增有界 `ConversationReconciliation`：只读取同 tenant/user、同 Agent run 的本地 `ai_app_task` 与 `ai_consumption_log`，且仅在任务与账本均为权威终态时收敛；不调用 Provider、不重试、不取消上游、不改写账本、不再次扣费或退款。对于提交后停止但上游已结算的样本，run 收敛为 `failed / UPSTREAM_COMPLETED_AFTER_STOP`，不发布迟到模型回复；对于已失败且已退款的 `run 18`，收敛为 `failed / UPSTREAM_FAILED_REFUNDED`，账本仍为零费用。二者均释放对应 thread 的 active run 并留下 `run.reconciled` 事件。

隔离数据库 `p2_reconciliation.php` 11 PASS，覆盖未知外部结果保持待核实、失败退款收敛、停止后成功结算收敛、线程/outbox 释放、账本不变与幂等重放；既有 `p2_stop.php` 76 PASS。代码 lint 通过。真实本地 DB 复核如下：

| run | 上游/账本终态 | 本地对账后状态 | 实际费用 |
| --- | --- | --- | --- |
| 18 | failed / refunded | failed / `UPSTREAM_FAILED_REFUNDED` | 0 |
| 20 | success / settled，提交后停止 | failed / `UPSTREAM_COMPLETED_AFTER_STOP` | 0.5796 |

这完成了本地可观察终态的账本对账与提交后停止语义，不等同真实 Provider 取消 API、上游未知用量查询、上游退款确认或生产调度。后四项仍为 P2 未放行项；PDF/Word、视频/音频内容理解与外部语义审核 Provider 也仍未完成。

对账合入本地 `develop` 后重新串行执行 P2 的 15 个隔离套件（迁移、会话、并发、执行、偏好、发送、HTTP、安全、Worker、队列故障、停止、停止竞争、恢复、附件、对账），共 **709 PASS / 0 FAIL**；测试环境仍是 internal Docker 网络与隔离数据库，未使用业务素材、供应商密钥或真实积分。随后本机 tenant 1 Worker 以 `--once` 扫描确认 `scanned=0`，且 Supervisor 常驻 Worker 已恢复。这个扫描只能证明本地运行路径可用，不等同生产调度或部署验收。

## 31. P2 原始门槛校正、Skill 实际送达与安全 Markdown（2026-09-22）

### 放行口径纠正

重新读取用户提供的《短剧画布Agent阶段测试与验收清单.md》第 4 节：P2 为 A01—A15，放行条件是问答、文本生成、引用、Skill 与刷新恢复均具备行为证据。此前第 25—30 节及顶部历史增量把后续能力笼统加入 P2，造成错误阻塞。本节明确覆盖那些阶段判断，不删除历史失败证据。

- PDF/Office 解析异常在 P4 R18；完整取消、退款、恢复与未知结果收敛按 P4 的对应条目继续验收。
- 不支持视频分析时明确告知属于 P3 M07 的合法行为，不要求在 P2 假装所有媒体均可理解。
- 用户已确认默认审核策略，现有输入/输出审核边界测试通过；未配置外部语义审核 Provider 不得伪称语义审核通过，也不能无依据扩大为 A01—A15 的前置条件。
- 生产迁移、生产部署、远程操作不是本地 P2 放行前提。本轮依然没有执行这些动作。

### 本轮修复与验证

1. `320ce98fe`：Skill 原先只冻结在 run 中，却未进入实际模型消息。Worker 现在把冻结 Skill 的 id/version/name/definition 作为受限创作材料送入 user-role 上下文；不传内部 model/execution policy，不赋予工具、计费或模型选择权限。隔离 Worker 断言实际模型消息包含 v1 规范，不受后续 v2 更改影响。
2. `28f1d6c03`：使用独立画布应用的真实 Skill 表结构建立隔离同名、同 ID 冲突夹具；实际 send 只解析短剧 Skill。发布 v2 后，旧 run 快照保持 v1，新会话显式选 v2 得到 v2；原请求重放不会重新解析或收费。
3. web `845bba5` / `7d4c737`：增加无第三方依赖的有界 Markdown 文本解析与 Vue 安全渲染。支持标题、表格、列表、代码及粗体；不解析原始 HTML、链接或图片地址，不使用 v-html。实际 Vue SSR 行为测试证明脚本、事件属性、iframe、SVG 等保持转义文本。真实浏览器显示语义 heading/table。
4. 在确认 tenant 1 活动 run 为 0 后，平滑重载本机 PHP-FPM，并 TERM 旧 Agent Worker PID 1258849，由既有 Supervisor 拉起；未重启其他生成 Worker。随后真实 run 21 成功，证明常驻 Worker 实际处理新请求。

### 真实两图与账本证据

仅本地 tenant 1 / user 1 / canvas 17 / thread 15，新上传仓库自带无敏感图片 `app_1.png`、`app_2.png` 为 asset 862、863。经正式 ConversationService 提交 run 21（request key `p2-real-two-images-20260922`），模型 Qwen3.6-Plus 返回两图人物、背景、配色的区别。页面实见“图片对比分析”二级标题及三列表格，内容区未溢出面板。模型实际输出三列而不是提示要求的两列，不伪称精确格式遵循。

- run 21：success；app_task 1073：success；唯一账本 1095：success / settled。
- 实际用户和租户费用均为 **2.037 积分**，低于单次 2000 授权上限。
- canvas 17 媒体 run 数为 0。页面重载/自动保存期间 graph_revision 从 372 变为 374，故本次不以版本号声称画布完全零写入；Agent send/Worker 不改图由隔离行为断言独立证明。
- 本次通过服务端正式业务服务提交，浏览器验收上传、刷新与结果展示；不将其称作本轮从点击发送按钮开始的完整浏览器端到端测试。该按钮/HTTP/SSE链路沿用第 26 节已通过的浏览器证据。

### A01—A15 证据索引

| ID | 状态 | 对应行为证据（隔离与真实范围分开） |
| --- | --- | --- |
| A01 | PASS | 原有 canvas 7 / run 12 两个已选图片节点真实理解；本轮 run 21 两附件真实比较；隔离 Worker 保留顺序并验证无额外媒体任务 |
| A02 | PASS | canvas 14 / run 9 真实确认写回、content_revision=3、冻结原文可展开；第 26 节浏览器版本写回与冲突保护 |
| A03 | PASS | p2_conversation 歧义两候选、无 outbox；浏览器点击候选后显式 node ID 发送 |
| A04 | PASS | 浏览器桥移动 node 2 后，原 run 仍冻结原 ID / 原坐标；Worker 使用冻结原文 |
| A05 | PASS | p2_http / p2_send / p2_recovery 的跨 tenant/user canvas/thread/run/message/event 拒绝与无信息泄漏 |
| A06 | PASS | 本轮 p2_send 同名、同 ID 的独立画布 Skill 无法覆盖短剧解析；Worker 实际收到短剧创作定义 |
| A07 | PASS | 本轮发布 v2、新选用 v2、在途 v1 保持不变；重放十次不重新解析；Worker 从冻结上下文执行 |
| A08 | PASS | p2_send 拒绝“绕过积分/任意模型”Skill；Worker tools=[]，不接受模型返回工具调用 |
| A09 | PASS | 文本附件与节点材料以 user-role 不可信上下文进入模型，不能授权工具/媒体任务；p2_attachments / p2_worker / p2_safety |
| A10 | PASS | 已有真实 run 13/14 使用国风水墨、9:16、15 秒连续回复、不重复询问；本轮 Worker 再验 known_creation_constraints |
| A11 | PASS | 第 26 节真实浏览器桥刷新恢复；p2_recovery 与前端 reader/state 再验读取不产生重复消息/run |
| A12 | PASS | 非法 tool_calls、畸形 JSON 或不存在工具明确 UNSUPPORTED_MODEL_RESPONSE，有限终结、不猜测执行 |
| A13 | PASS | 浏览器租户切换验收；本轮 reader/state 验证旧响应无法写入新 scope |
| A14 | PASS | 十独立并发请求和十次重放，同一消息/run/event/outbox；conversation intent 固定不产生媒体任务 |
| A15 | PASS | 本轮实际 Vue 渲染器 XSS 测试；真实 run 21 在浏览器呈现标题和表格；不执行 HTML |

最终本地 develop 上串行 15 个后端 P2 套件：**718 PASS / 0 FAIL**（migration 48、conversation 63、concurrency 23、execution 71、settings 36、send 30、HTTP 51、safety 11、Worker 109、queue crash 31、stop 76、stop race 52、recovery 84、attachments 22、reconciliation 11）。前端本轮 Markdown/reader/state **22 PASS / 0 FAIL**。隔离测试使用 internal 网络和独立数据库；真实费用仅为上述明确列出的业务样本。第 26 节浏览器桥 12 PASS 是保留的历史证据，不伪称本轮重跑。

**阶段结论：P2 按原清单本地放行，进入 P3。** 当前 P3 尚无专属 M01—M16 全套测试，下一步先补共享能力矩阵与四节点引用行为夹具，不能将已有手工生成或静态合同测试等同 P3 全通过。P4—P6 NOT_RUN。SSE 当前返回持久完整回复，不是逐 token 输出；PDF/Office/音视频解析、外部语义审核、Provider 取消/未知用量查询等能力继续如实保留在后续阶段，不借放行结论宣称已实现。

## 32. P3 首轮连线行为测试与参考上限修复（2026-09-22）

继续原清单 M01—M16，未重做 P0/P2 审计。两仓库 feature/short-drama-optimization，测试在合入本地 develop 后执行。本轮无 API、迁移、权限、计费或节点类型变更；没有调用真实 Provider、扣费、部署或推送。

新增 web `short-drama-p3-connections.test.cjs` 直接加载生产 TypeScript 规则执行（不是正则匹配）：16 种源/目标组合、替代兼容模型、图片/视频完整参考集合总限额、能力文本分隔符、同用途上传+连线去重。测试提交 `01e4daf` / `50c174a`，修复提交 `5af948f`。

实际发现与处理：

- 初跑测试夹具未设置 TypeScript target，Set 展开被降级为旧 JS 语义，产生 3 个视频兼容假失败。仅修正夹具为 ES2022 后，16 个组合全部通过；未为假失败改产品规则。
- 随后稳定复现 3 个产品失败：图片和视频均忽略 `capabilities.max_reference_assets`，三个分别合法的参考可突破总上限；空格分隔 `input_modalities` 因正则双反斜杠被错误解析。
- 规则现按顶层优先、capabilities 回退读取总限额，修正空白分隔符。未扩大默认能力、未修改界面样式、未替换用户当前模型。

验证：新增 P3 前端行为 **21 PASS / 0 FAIL**；加现有连线静态合同 10 项、Markdown 行为 3 项、会话状态行为 11 项，共 **45 PASS / 0 FAIL**。其中静态合同不算端到端证据。后端真实 CanvasService + PointService / 模拟下游的 `p0_generation.php idempotent` **48 PASS / 0 FAIL**：四类节点、十次重放、无重复扣费、删除与乱序完成、历史/资产投影、未知结果不盲重提均保持通过；下游应用服务被模拟，不证明真实媒体 Provider 能力。

| P3 项 | 当前证据与状态 |
| --- | --- |
| M01 | 前端 16 组合 PASS；与服务端共享夹具一致性 NOT_RUN，整项尚未通过 |
| M02 | 前端可选其他兼容模型、保留当前选项 PASS；实际生成选定模型校验仍待验证 |
| M03 | 图片/视频前端合并总上限 PASS（已修复）；服务端提交拦截仍待验证 |
| M04 | 前端同用途 URL 参考去重 PASS；真实 Adapter 输入去重待验证 |
| M11/M12/M13/M16 | 既有隔离 Canvas 生成回归提供部分证据；不得等同 Agent 与手动并发、真实 Adapter、选择旧版本引用的完整验收 |
| 其余 M05—M16 未覆盖部分 | NOT_RUN，仍需逐项实现和行为测试 |

P3 尚未放行。下一切片：跟踪 ShortDramaCanvasService 提交参数及下游模型校验，建立前后端同一参考能力夹具，先补 M01/M02/M03 的服务端行为；随后按顺序推进角色槽位、资产解析与媒体适配闭环。P4—P6 保持 NOT_RUN。

## 33. P3 服务端参考归一化、首尾帧角色修复（2026-09-22）

路径核对：ShortDramaCanvasService 将视频提交至 AigcVideoService 的市场运行时；旧 `generateInternal` 已直接报“旧视频 Provider 提交链路已移除”。因此其中 `assertReferenceAssetsSupported` 不能作为当前线上路径验证证据，后续 M01/M03 必须验证实际 MarketVideoRuntimeService quote/submit。

在此之前发现共享 AigcVideoReferenceAssetService 两个可复现问题：归一化按 URI 去重会合并同图首帧/尾帧；超过 15 个素材时 `array_slice` 静默截断，使下游无法审核完整集合。新增 `p3_reference_assets.php`，首次实际失败于 M05；实现修复后又发现 legacy reference_images 被赋予 reference_image role，会追加第三份，随后修正兼容投影并复测。

- 服务端 `8373015fb` / `d7ee1da3f`：同源首尾帧以角色区分，同角色重复仍去重，顺序保持；legacy 无角色用途的图片投影不会在显式首尾帧之外多算一份。显式不同用途 reference_image 保留，不吞掉用户的角色。
- 超出既有归一化最大数量时明确报错，不静默丢素材；未改变模型本身能力或限额。此处的 15 是既有归一化边界，不是宣称所有模型都支持 15 个参考。
- 无 API/schema/账本变更，无下载或物理素材改写。共享消费者包含独立视频、短剧及市场视频运行时，所以按回归保护技能验证了短剧参考与市场载荷，而不是只测新函数。

本地 develop 验证：`p3_reference_assets.php` **5 PASS**（同用途去重、同图双角色、反向顺序、溢出拒绝、边界接受）；`ShortDramaVideoReferenceContractTest` **24 tests / 57 assertions PASS**；`MarketVideoModelPayloadContractTest` **12 tests / 68 assertions PASS**。后两套含行为与源码合同，不标成完整 Provider 端到端。首次 PHPUnit 因只读测试目录和缺少 web 挂载报 2 个环境错误，改用容器临时 uploads 与只读 web 挂载复测通过；未改变宿主业务 uploads。一次挂载到不存在的只读子目录失败后，改挂已有 uploads 路径，容器退出即移除临时测试文件。

P3 M03/M04/M05/M06 的归一化层有行为证据，但模型能力、总限额报价拒绝、前后端 16 组合一致性与收费前拒绝仍未完整验证，**P3 未放行**。本轮仅 server 源码变化，web 无源码变更；无付费调用、业务迁移、生产部署或推送。未重启本机 FPM/常驻媒体 Worker，不能声称常驻进程已加载本轮共享 PHP 修复。

## 34. P3 实际市场视频校验器行为（2026-09-22）

增量测试 `bae33005b` 直接执行 MarketVideoRuntimeService 当前 reserve 调用的 `assertAssets`（不使用已停用旧视频方法）：合成模型允许图片/视频/音频各 3 个、总量 2 个时，两种素材通过，三种分别合法的素材因总量超限被拒绝；将所选模型限制为图片后，视频参考明确拒绝，不因其他模型可能支持而放行。

隔离 `p3_reference_assets.php` **8 PASS / 0 FAIL**，含之前 5 项归一化回归与新增 3 项实际校验器行为。此测试通过反射调用真实校验方法，不访问远端、不扣费、不写业务库；只证明该校验器，不伪称公开 quote/reserve 端到端通过。

源码定位显示 reserve 在 quoteMarket 与余额操作之前调用 assertAssets，而公开 quote 只解析时长/选择并计算报价，未调用 assertAssets。因此“非法参考在扣费前拒绝”的完整入口行为仍需隔离市场目录/报价夹具验证；“报价也拒绝非法参考”仍未完成。P3 继续未放行，web 本轮无变更，未重启业务进程或发布。

## 35. 公开视频报价参考校验缺口修复（2026-09-22）

用户明确要求修复第 34 节缺口。`62b551bab` 在 MarketVideoRuntimeService::quote 的实际商品/SKU解析后、quoteMarket 前复用 reserve 的 assertAssets 与 assertTextToVideoRatio。非法参考不再获得有效报价，不新增另一套能力规则；无 API/schema/计费算法变更。共享影响范围为所有调用市场视频 quote 的业务，包括独立视频与短剧。

新增隔离 `p3_quote.php` 通过公开 quote 方法和真实测试库市场 product/SKU 查询执行，不模拟 quote、不只反射调用私有方法。首个夹具因视频参考与图片 SKU 不兼容被原有选择逻辑拒绝，修正为匹配 SKU 的图片集合后，修复前稳定复现“三张图超过总上限两张仍返回报价”；修复后通过。

验收：公开 quote **5 PASS**（合法边界、完整集合超限拒绝、不支持的输入模式拒绝、无参考兼容、任务/消费账本零新增）；既有参考校验 **8 PASS**；共享消费者回归 `ShortDramaVideoReferenceContractTest` **24 tests / 57 assertions PASS**、`MarketVideoModelPayloadContractTest` **12 tests / 68 assertions PASS**。全部从已集成本地 develop 执行，隔离网络/数据库，不请求 Provider、不扣真实积分。未通过修改余额或绕过校验使测试通过。

本节公开服务报价缺口已修复；HTTP/UI报价、reserve 完整事务及 P3 其余门槛仍不能由本轮替代，P3 未整体放行。本轮 web 无源码变化。未重启常驻进程、未迁移业务库、未部署/推送。

## 36. P3 公开 reserve 收费前拒绝与预占幂等验收（2026-09-22）

接续第 35 节，server `8715cb6cc` 扩展 `p3_quote.php`，在独立 internal Docker 网络和测试库中调用真实 MarketVideoRuntimeService::quote/reserve；不替换市场目录、reserve 或 PointService。合成 tenant/user 各初始 100 积分，测试事务最终回滚，未复制业务数据或密钥。

- PASS：完整集合三张参考超过所选模型总限额两张，公开 reserve 明确拒绝。
- PASS：所选模型不支持音频时，公开 reserve 明确拒绝；不会以其他模型可能兼容为由放行。
- PASS：上述两类拒绝各自保持任务/消费记录数量及用户/租户余额不变。
- PASS：合法参考边界通过真实 reserve，任务与消费记录各新增一条，合成余额变化严格等于 quote 的租户/用户金额。
- PASS：相同业务键重放返回相同 app_task_id/consumption_id，余额不再变化，不重复建任务或消费记录。

本地 develop 已集成后执行：公开 quote/reserve **13 PASS / 0 FAIL**；参考归一化与实际市场校验器 **8 PASS / 0 FAIL**。本次是实际服务与数据库行为证据，不是静态检查，但不等同 HTTP/UI、Provider submit 或完整 settle/refund 生命周期通过。M02/M03 的服务端提交前校验已补证，M01 的前后端共享 16 组合及其余未覆盖条目仍继续，**P3 未整体放行，P4—P6 NOT_RUN**。仅测试/证据源码变化，无 web 变化、业务迁移、付费调用、部署或推送。

相关运行维护另见 `worker-unbound-result-regression.md`：结果任务 3268 的已退款无绑定目标无限轮询已修复并由真实本地 Worker 正常收尾；不将原始失败生成误记为成功，也不据此替代 P3 门槛。

## 37. P3 下游市场运行时首尾帧槽位二次去重修复（2026-09-22）

核对 M01 的后端映射时发现：GraphService::add_edge 当前只校验结构/端点/角色身份，不读取模型目录；因此不能把其接受连线视为前后端能力矩阵一致，M01 仍未通过。继续追踪市场参考校验又发现 M05 已修归一化之后，MarketVideoRuntimeService::assets 仍按 URL 二次去重，同图 first_frame_image/last_frame_image 因此合成一个槽位。

- 测试 `898388439` 在本地 develop 的隔离环境稳定复现 FAIL：市场投影图片数为 1 而非 2；没有改断言掩盖失败。
- 修复 `e38b3fbcf`：共享市场投影复用归一化后的去重结果，不再次按 URL 合并不同语义角色；同用途重复仍由归一化层去重。不硬编码素材 ID、租户或模型例外。
- PASS：`p3_reference_assets.php` 12 项，包括同图双槽位、同用途去重、支持首尾帧的选定模型接受双槽位、单帧模式明确拒绝双槽位。
- PASS：`p3_quote.php` 13 项，公开报价/预占、拒绝零副作用和预占幂等保持通过。
- PASS：ShortDramaVideoReferenceContractTest 与 MarketVideoModelPayloadContractTest 合计 36 tests / 125 assertions；包括行为和源码合同，不计为 Provider 端到端。
- 环境：只读 server/web 源码挂载，真实 .env 遮盖，internal 网络，runtime/uploads 临时文件系统；没有付费请求、业务写入、迁移、推送或部署。已有 PHPUnit 全目录加载的 ReflectionMethod 警告仍存在，不影响上述测试结果。

本轮仅 server 修改；无 API/schema/UI 变化。未重载常驻 FPM/Worker，不能宣称常驻进程已加载此修复。M05 当前证明归一化、市场投影及校验器，尚不代替最终 Provider 请求和 UI 流程；M01 共享 16 组合、M06 最终请求/报价快照等未覆盖项继续，P3 未整体放行。

## 38. P3 结构化 Provider 请求载荷角色验证（2026-09-22）

server `b4078f4c9` 给 MarketVideoModelPayloadContractTest 新增三个直接执行生产 modelPayload 的行为用例，没有模拟请求构造器，也没有发送 Provider 网络请求。

- PASS / M04 载荷层：同用途节点引用、重复显式参考和 legacy reference_images 只生成一个 reference_image 项。
- PASS / M05 载荷层：同一图片 URI 指定首帧和尾帧时，最终结构化 input.media 保留 first_frame/last_frame 两项，幂等键保留。
- PASS / M06 载荷层：保持素材数组顺序、交换两张图片角色后，载荷角色相应互换、URL 顺序不变；不根据数组位置猜角色。

从已合入的本地 develop，在只读源码、遮盖 .env、internal 网络、临时 runtime/uploads 的容器中执行两套共享回归：**39 tests / 132 assertions PASS**（含本轮新增 3 tests / 7 assertions）。只有测试源码变化，web 无变化；没有付费调用、迁移、业务数据写入、重载、推送或部署。已有全目录加载 ReflectionMethod 警告仍记录为环境警告。

边界：只证明当前 Wan 结构化请求构造路径，不泛化为所有 Provider 协议通过。M06 的 UI 次序操作及报价/hash/快照更新仍 NOT_RUN；M01 共享矩阵、资产所有权/状态解析及其余门槛继续按原清单补齐，P3 未整体放行。

## 39. P3 租户/用户余额不足的公开预占入口验证（2026-09-22）

server `465d231c5` 仅扩展隔离 `p3_quote.php`。先断言合成商品的租户成本与用户售价均大于零，再分别设置测试 tenant 91001 余额 0/user 92001 余额 100，以及反向余额。调用真实 MarketVideoRuntimeService::reserve 与 PointService，不替换余额检查。

- PASS：用户有余额但租户不足时返回包含租户与不足的明确错误。
- PASS：租户有余额但用户不足时返回用户侧不足错误，与租户不足可区分。
- PASS：两类失败均不改变调用前的合成余额，任务/消费记录数量不增，不留下孤立预占任务。
- PASS：恢复合成余额后，合法请求仍可正常预占，同键重放无重复扣费。

本地 develop 合入后，internal 网络/独立测试库执行公开 quote/reserve 套件 **18 PASS / 0 FAIL**，其中本轮新增 5 项。全部 fixture 事务回滚；未调用 Provider、未改业务余额、未迁移/部署/推送。web 无改动；本次无需重载 Worker。

M15 的公开市场服务拒绝路径已有行为证据，但画布/Agent 失败状态保存和 UI 明确呈现仍 NOT_RUN，不能将整项或 P3 阶段宣称全部通过。M01、M06 与其余未满足项继续保留，不跳过门槛进入 P4。

## 40. P3 画布预检失败与未知提交结果分流（2026-09-22）

沿 M15 追踪发现：市场余额预检已经明确拒绝，但 Canvas::submitIdempotent 对所有下游异常统一调用 unknown，造成未进入预占/Provider 的请求也变成 needs_reconciliation。server `a10a152cc` 新增内部 PreSubmissionRejected 类型，只在 MarketVideoRuntimeService 的只读余额预检 RuntimeException 边界抛出；预占事务内或 Provider 提交后的异常不作此分类。不解析错误文案猜测提交是否发生，不修改 PointService 的余额算法。

Canvas 对此已知拒绝持久化 intent failed/PRE_SUBMISSION_REJECTED、run failed/原始预检原因、释放 lease，并同步短剧历史；相同键重放返回原失败 run，不重新调用下游。普通异常仍保留 needs_reconciliation。无新增接口、数据库迁移或前端样式修改；状态使用已有 failed 值。

本地 develop 合入后，在 internal 网络和独立测试库执行：

- PASS：`p3_preflight_rejection.php` 11 项。真实 Canvas/Market reserve/PointService；仅视频应用入口由测试桥替换，以确保绝无 Provider 网络调用。验证失败状态、原因、租约、零市场任务/消费/积分日志、余额不变、三次重放不重提、历史失败与未知异常对照。
- PASS：`p1_generation_intent.php` 35 项，冻结输入、幂等、身份隔离、fencing、迟到回执、过期与图变更保护。
- PASS：`p1_generation_crash.php` 14 项，仅终止隔离测试子进程，不触碰常驻业务 Worker。
- PASS：`p3_quote.php` 18 项，真实市场入口超限/模态/双方余额拒绝与合法预占。
- PASS：`p0_generation.php idempotent` 48 项，四节点、未知受理、乱序/删除结果、历史资产及积分只扣一次。

本次无付费调用、业务库变更、迁移、部署或推送。未重载 FPM/Worker，不能声称线上常驻进程已使用此分类。M15 画布服务与历史保存已补证；实际 AigcVideoService 全路径、HTTP/浏览器失败展示及 Agent 工具触发仍 NOT_RUN。P3 仍未整体放行；下一步需继续补完整能力矩阵/资产引用和 UI 证据，不以本轮服务测试替代。

## 41. P3 实际视频应用预检拒绝贯通（2026-09-22）

server `4c82ab6f8` 为 p3_preflight_rejection 增加 `real-app` 模式，禁用视频服务 class_alias，使用真实 Canvas::submitIdempotent → AigcVideoService::generate → MarketVideoModelRuntimeService → MarketVideoRuntimeService::reserve → PointService 链路。目录/账户为隔离合成数据，无真实供应商密钥，网络 internal。

- PASS：real-app **9 项**，余额不足经实际视频应用捕获后仍保留 PreSubmissionRejected 类型，画布和短剧历史失败、原因明确、租约释放；用户/租户余额、市场任务/消费和积分日志不变。
- PASS：真实视频应用保留一条 failed 且 consumption_id=0 的业务任务用于展示失败；同键三次重放不再创建视频任务。这与“市场任务零新增”不冲突，两者是不同表/生命周期。
- PASS：原测试桥模式 **11 项**重新执行，包含未知提交异常仍 needs_reconciliation 的对照，不因新增 real-app 模式削弱原验收。

两模式均从已集成本地 develop 运行并事务回滚；本轮仅测试源码变化，无业务数据改动、付费调用、迁移、重载、部署或推送。实际视频应用的**预检失败链路**已补证，不扩展为 Provider 成功生成全流程通过。HTTP/UI 明确展示、Agent 工具触发及 P3 其他未满足门槛继续 NOT_RUN，P3 未整体放行。

## 42. P3 失败状态刷新投影与身份隔离（2026-09-22）

server `18111ece0` 扩展同一预检失败套件：真实 Canvas::current 返回原 failed run 与原错误原因；连续读取不改变 graph_revision、run 投影或市场/积分记录；其他 tenant 或 user 均无法通过 runDetail 读取失败任务。现有 nodes metadata 不是本项的权威运行态断言，页面应消费 current.runs；没有把读取服务测试写成浏览器展示已通过。

本地 develop、独立 internal 网络/测试库实测：real-app 模式 **13 PASS**，测试桥模式 **15 PASS**，全部退出 0、fixture 回滚。仅测试源码变化，无业务数据、付费调用、迁移、进程重载、推送或部署。当前失败持久化/刷新/隔离已有行为证据，HTTP/浏览器显示仍 NOT_RUN；P3 其他门槛不变，未整体放行。

## 43. P3 浏览器预检失败展示（2026-09-22）

web 新增 short-drama-preflight-failure-browser.cjs（最终测试修正 `72d720b`），使用独立 headless Chrome context、合成 tenant/user/canvas，拦截全部 API，拒绝外站与生成请求，不访问用户浏览器 profile 或业务数据库。已有本地代理配置保持不变。

首次与诊断复跑均 FAIL/timeout：夹具只返回 current.runs，遗漏页面恢复时调用的 canvas/task，导致后者为空、节点保持旧 queued。诊断确认页面无 JS 异常；补齐 task 的权威失败响应后复测 **4 PASS**，未改产品代码掩盖失败。

PASS：保存的 queued 节点恢复为“生成失败”；错误文字包含“租户积分不足，请联系管理员”；刷新仍显示失败；无生成请求、无未捕获页面异常。实际页面读取链路为 current 中定位 run ID，再 task 读取状态，不将 current.runs 单独返回视为足够浏览器证据。

测试从已集成本地 develop 的 web 执行，退出 0；web 源码仅新增测试，server 仅本证据变化。没有付费请求、业务写入、迁移、重载、部署或推送。该浏览器测试为**合成 HTTP 响应的 UI 行为验证**，与第 41—42 节实际服务/数据库证据分别成立，不伪称浏览器→真实后端端到端已完成。P3 其他能力矩阵、资产和 Agent 工具门槛仍未完成，未整体放行。

## 44. P3 未知参考容量拒绝提交（2026-09-22）

M10 新增测试 `b6e6b448a` 在实际市场 assertAssets 复现 FAIL：合成模型仅声明 supported_asset_types=image、无任何数量上限，仍接受参考请求。共享修复 `e0992ff00` 在已确认支持该类型后检查数量边界：无类型上限、无总上限、也不属于已校验固定一/二帧契约时明确拒绝。保留显式总上限、已知模型合同回退及严格首尾帧路径，不将缺失信息解释为无限参考；无 API/schema/计费算法变更。

测试 `b5382b219` 通过真实市场目录、公开 quote/reserve 与隔离数据库验证未知容量被拒绝；余额、市场任务及消费记录不变。恢复目录能力后原合法预占/重放继续通过，未通过改余额或跳过校验掩盖错误。

本地 develop 合入后实测：参考套件 **13 PASS**；公开 quote/reserve **22 PASS**；共享短剧参考和市场 Provider 载荷回归 **39 tests / 132 assertions PASS**。已有 ReflectionMethod 无效 use 环境警告保留。测试使用 internal 网络、遮盖真实 .env、独立合成数据且事务回滚；无 Provider 调用、真实扣费、业务迁移、推送或部署。

按回归保护与 Provider 集成技能在共享市场运行时修复，覆盖独立视频和短剧共同入口。本轮 web 无修改，未重载 FPM/Worker，不能声称常驻进程已加载。仅补齐 M10 的市场视频服务容量未知分支；资源接口失败、其他模型类型及前端提示仍未完整验证，M10/P3 不作整体放行，P4—P6 NOT_RUN。

## 45. P3 有界参考能力兼容回归（2026-09-22）

测试提交 `24feada8e` 补验第 44 节安全拦截的例外边界，直接执行真实市场 assertAssets，不替换校验器。新增 6 PASS：只有总上限而无单类型上限时边界数量接受、超量拒绝；已知模型固定首尾帧和单帧契约在无数字目录上限时接受；固定首尾帧额外加入第三张图仍拒绝；不含参考素材的请求不被参考容量规则误拦截。

本地 develop 合入后，独立 internal 网络/遮盖 .env 的容器运行 p3_reference_assets.php，合计 **19 PASS / 0 FAIL**。本轮仅后端测试与证据修改，无产品逻辑、API、数据库、前端或常驻进程变更，无付费请求/部署/推送。按回归保护技能补齐合法与非法的对照，不将只有拒绝用例视为兼容性通过。该证据仍为校验器行为，不替代 HTTP、真实 Provider 或其他类型模型；P3 未整体放行，P4—P6 NOT_RUN。

## 46. P3 无 status 同步文本的持久化与重复读取（2026-09-22）

测试 `425ad2fc1` 在既有 p0_generation 中显式验证 M13，不只检查函数源码。下游合成文本返回 content/billing、无 status；实际 Canvas/GenerationIntent/PointService 与独立数据库负责执行和持久化。新增断言：详情仍 success/100 且内容准确，短剧历史同样成功并保留内容；幂等入口还验证原文本节点收到对应 run 结果、重复读取不改变 nodes 或 graph_revision。

从已合入本地 develop 运行：幂等入口 **53 PASS / 0 FAIL**，旧入口 **19 PASS / 0 FAIL**。保留四节点、重放十次、实际积分服务仅扣一次、未知结果不盲重提、乱序完成和删除不复活等既有回归。全部为 internal 网络/独立数据库的合成 Provider 测试，事务回滚，无真实扣费和业务库写入。

M13 的画布服务边界已有直接行为证据，无需新增产品补丁。实际 LLM Provider、HTTP/浏览器生成未运行，不冒充完整真实链路；P3 其他门槛仍未完成，P4—P6 NOT_RUN。本轮仅 server 测试和文档，无 web/API/schema 变化，无常驻进程重载、部署或推送。

## 47. P3 模型与参考变更的生成键冲突验证（2026-09-22）

测试 `3c16cba43` 经实际 Canvas::submitIdempotent/generationPayload/GenerationIntentService 和隔离数据库，验证同一视频生成键在 model_code、resolution、参考集合、首尾帧 role、参考数组顺序分别变化后均返回 IDEMPOTENCY_CONFLICT，不再调用合成下游，且原 request_json 快照不被覆盖。不是只对私有 hash 函数做单测。

本地 develop 集成后 p0_generation.php idempotent **59 PASS / 0 FAIL**，其中本轮新增 6 项；原四节点与计费/乱序/删除回归保留。依回归保护技能保留实际 Canvas 与 PointService，仅下游应用生成服务为测试替身；internal 网络、真实 .env 遮盖、独立数据库事务回滚，无业务数据或付费调用。

重要边界：request_hash 是生成幂等校验，不是 quote_hash。本轮仅证明 M06/M14 的冻结输入与重复提交防护部分，报价变化后的确认失效机制仍未实现/验收，不能把这些 PASS 算成 M14 完整放行。仅 server 测试/文档修改，web/API/schema 不变，无进程重载、部署或推送。P3 未整体放行，P4—P6 NOT_RUN。

## 48. P3 前端同图首尾帧槽位统计修复（2026-09-22）

检查前端能力规则时发现 videoReferenceSummary 仅以类型/URL 去重，candidateReferences 又丢弃 referenceImages.role。新增测试 `8496daa` 实际执行生产 TypeScript，稳定复现两项 FAIL：同 URI 首尾帧计数为 1，以及 composer 对双槽位首尾帧模型兼容列表为空。

web 修复 `caf5dfa` 将角色纳入参考统计身份，保留 referenceImages 的显式角色；未声明角色使用 reference_image 等既有默认用途。同角色重复仍去重、同图不同帧角色分别计数。没有修改连线清理机制、界面样式、本地代理配置或后端 API/schema。

本地 develop 集成后前端规则套件 **24 PASS / 0 FAIL**，包括四种源/目标 16 组合、替代模型、完整总量上限和原同用途去重。遵循前端体验与回归保护技能覆盖生产规则消费者；本轮未运行浏览器生成或 Provider 请求，无付费调用/业务写入/部署/推送。server 仅记录本节；M05 前端统计与兼容筛选补证，不代表全部 UI 操作或全部 Provider 验收通过。最初拟核对的 M10 前端未知能力策略仍待独立实现，未借本轮改动宣称完成；P3 未整体放行，P4—P6 NOT_RUN。

## 49. P3 前端生成检查与关联规则分离（2026-09-22）

web `aa4e34e` 添加 generationInputError，在 runNativeNode 设置 running、保存及调用生成接口前执行。模型目录未加载/无选定模型明确拒绝；视频有参考时，无可知总容量且不属于明确固定帧模式则拒绝，并校验当前选定模型及生成模式。其他兼容模型只允许关联，不替代选定模型的提交资格。没有将此提交规则加入 reconcileConnections，因此不会因新增的未知容量检查清理已有连线。

本地 develop 集成后 **27 项生产 TS 行为测试 PASS**，包含未加载目录阻止生成但关联不删除、未知数量上限拒绝/显式总上限接受、当前模型拒绝而替代模型可接受；另有 **10 项既有源码合同检查 PASS**，两类证据不混同。界面样式、代理、API/schema、后端均未修改，server 仅记录验收。按前端体验与回归保护技能将提交与图关系区分，保留四节点规则及原参考回归。

未做浏览器点击到真实后端的端到端测试，其他媒体模型容量、已加载目录后刷新失败的旧缓存策略仍待补验；不据此完整放行 M10 或 P3。无付费调用、业务数据写入、迁移、进程重载、部署或推送。P4—P6 NOT_RUN。

## 50. P3 附件撤销、授权与文本媒体声明复验（2026-09-22）

恢复已授权的 internal Docker 隔离数据库后，从最新本地 develop 实际执行 `p2_attachments.php` 与 `p2_worker.php`；源码只读挂载、真实 `.env` 遮盖、runtime 使用 tmpfs，测试夹具使用合成租户/用户并在结束后回滚或精确清理。此前测试网络中的数据库容器已停止，本轮仅启动既有 `short-drama-agent-test-db` 容器，核实它连接的是 `short_drama_agent_test`（279 张测试表），没有初始化、迁移或访问本地业务库。

- PASS / M08：附件套件 **22 PASS / 0 FAIL**。提交阶段拒绝伪造字段和跨 tenant/user 图片；当前画布和素材库图片冻结身份及顺序，消息与模型文本上下文不暴露 URI；附件从草稿移除后不会被下一次发送携带；素材在发送后软删除时，真正执行前的二次核验拒绝 `IMAGE_REFERENCE_UNAVAILABLE`，且不创建画布节点或媒体 run。
- PASS / M08：Worker 套件 **109 PASS / 0 FAIL**。再次验证远端图片归属、同用户伪造本地路径、跨用户访问、删除后执行均被拒绝；图片数量在读取字节前受限。模型调用位于已持久化授权之后且不在数据库事务中，失败、过期 lease、重复 Worker 等路径不重复提交。
- PASS / M07：选中图片节点进入文本对话上下文时只携带“媒体理解能力不可用”的受控声明，既不读取媒体 URL、也不伪称识别像素；文本分析不创建媒体任务或改写画布。这是实际 Worker DTO 行为验证，不是提示词字符串检查。

本轮没有真实 Provider、积分账本或浏览器请求；不将隔离模拟 Provider 计为真实端到端成功。M09 仍 **NOT_RUN**：现有实现使用稳定 asset ID/URI 与存储元数据在每次执行前重新经 FileService 取得授权 URL，但尚未用真实私有存储的过期签名 URL 完成刷新验收。模型目录全部显示不可用导致的真实 Provider 成功请求，及其余 M01/M06/M09/M12/M14/M16 门槛仍待补齐，P3 未整体放行，P4—P6 NOT_RUN。

## 51. P3 本地 tenant 1 真实浏览器文本 Provider 与账本验收（2026-09-22）

依用户逐笔确认，在本地 tenant 1 / user 1 的专用画布 18 `P3真实端到端验收-20260922` 执行一条最小文本节点请求，输入为“请只回复：P3真实文本验收通过。”。不上传附件、不引用私有素材、不操作远程生产环境。此前 GPT-5.4 与 Qwen 的早期提交均得到 `model_not_found` 并以零积分失败；本轮在浏览器实际选择 `Qwen3.6-Plus` 后提交，未绕过 UI 或直接构造 Provider 请求。

- PASS：浏览器节点进入生成态后刷新同一页面，节点最终显示 `P3真实文本验收通过。`；页面积分由 **10869.58** 变为 **10869.25**。这验证实际浏览器 → 本地 API/Worker → Provider → 画布投影的成功文本路径。
- PASS：本地业务账本任务 `canvas_run_25`（generation task 974）为 success/100；关联 app task 1076 绑定 `aigc_short_drama_canvas_run:25`，消费 1098 的模型为 `qwen3.6-plus` / `dashscope_compatible`，run_status=success、billing_status=settled。
- PASS：该消费事件恰有一次 `reserve`、一次 `submit`、一次 `settle`，均为 attempt 1；tenant 与 user 实际成本各 **0.327600** 积分（页面按两位数显示 0.33）。没有重放或第二次 Provider 提交。

这只放行了真实文本成功、刷新投影和一次结算的对应部分；它不代表附件解析、图像/视频模型、撤销、退款、取消、过期签名 URL、能力矩阵、报价确认失效或 Agent 对话真实 Provider 路径均已完成。尤其 M01/M06/M09/M12/M14/M16 仍按各自证据状态继续，**P3 未整体放行，P4—P6 NOT_RUN**。本轮真实调用在用户授权的 2000 积分上限内；没有迁移、部署、发布、推送或密钥复制。

## 52. P3 本地 tenant 1 真实 Agent SSE、账本与会话验收（2026-09-22）

依用户逐笔确认，在相同专用画布 18 的右侧 Agent 输入框中，用账号默认推理模型 `Qwen3.6-Plus` 发送最小消息“请只回复：Agent真实Provider验收通过。”；未附加素材、Skill 或生成工具，未直接请求 Provider。

- PASS：浏览器先显示“正在处理…”，随后同一右侧对话区域通过现有事件/刷新路径显示 Agent 回复 `Agent真实Provider验收通过。`，而画布节点不新增、不被改写。
- PASS：本地持久化 thread 16 的 run 22 为 success，thread.active_run_id 回到 0，消息序列严格为 user、assistant 两条，证明完成后会话可继续而不是仅浏览器临时文本。
- PASS：run 22 对应 app task 1077（`aigc_short_drama_canvas_agent_run:22`）和消费 1099；模型 `qwen3.6-plus` / `dashscope_compatible`，billing_status=settled。消费事件恰有一次 attempt 1 的 `reserve`、`submit`、`settle`；tenant/user 各实际结算 **0.525000** 积分，页面账户余额从 10869.25 变为 **10868.73**。

该项补齐真实 Agent Provider、SSE 可见回复、会话持久化与唯一账本的成功路径，不推断附件视觉理解、停止后的真实上游取消/退款、未知结果查询、模型能力矩阵或签名 URL 刷新也已通过。它不调用 Agent 工具，P3 的 M01/M06/M09/M12/M14/M16 等剩余门槛仍按单项继续，**P3 未整体放行，P4—P6 NOT_RUN**。本次在用户授权 2000 积分上限内，未迁移、部署、发布、推送或复制密钥。

## 53. P3 附件中间态、视频声明与取消/对账回归（2026-09-22）

server `449e0346f` 将此前可执行的授权服务覆盖扩展到缺失的中间态和视频语义：不更改生产附件处理、Provider 或计费代码，只让隔离测试直接执行现有真实服务。变更已按分支规则合入本地 develop 后执行，再切回 feature。

- PASS / M08：`p2_attachments.php` **28 PASS / 0 FAIL**。已冻结的图片在 Provider 执行前被改为 `uploading` 或 `transferring` 时，`ConversationImages::urls` 均明确拒绝 `IMAGE_REFERENCE_UNAVAILABLE`；软删除、跨 tenant/user、伪造本地路径与撤销后不再附加亦保持通过。不会将未就绪的文件发送到 Provider。
- PASS / M07：`p2_worker.php` **109 PASS / 0 FAIL**。同一真实冻结上下文同时选择文本、图片和视频节点时，文本 Agent DTO 不携带私有图片/视频 URI；图片和视频都标识 `media_understanding_available=false`，因此不会声称读过像素或视频内容，也不会创建媒体任务或改写画布。
- PASS / 取消与账本终态：`p2_stop.php` **76 PASS / 0 FAIL**，`p2_reconciliation.php` **11 PASS / 0 FAIL**。排队/处理中停止可围栏 Provider 提交并幂等释放会话；已提交后只记录停止请求且不伪称上游已取消；失败退款、已结算的迟到结果和未知终态均经本地 app-task/消费账本对齐，不重复扣费、退款或改写权威账本。

测试在 internal Docker 网络和独立 `short_drama_agent_test` 库执行，真实 `.env` 被遮盖、runtime 为 tmpfs；没有访问 tenant 1 业务数据或产生付费调用。**仍不能把取消/退款宣称为真实 Provider 上游操作 PASS**：当前接入的 Qwen 文本流协议没有可调用、可验证的取消 API 或 Provider 侧用量查询契约；真实 Agent 成功路径已在第 52 节通过，但其停止后的上游取消/退款仍为 `BLOCKED`，不能用本地围栏/账本测试替代。M09 私有签名刷新、M01 共享能力矩阵、M06/M14 报价确认失效、M11 Agent/手动同请求幂等和 M12 完整浏览器映射仍未满足，**P3 未整体放行，P4—P6 NOT_RUN**。

## 54. P3 四节点引用矩阵的服务端写入边界（2026-09-22）

此前浏览器已有 4×4 引用关系筛选，GraphService 只作端点结构校验，错误请求可绕过前端直接写入不应存在的 reference edge。server `1d6cb80b8` 将四节点的**基础、模型无关**引用矩阵写入 GraphService：`text → text/image/video/audio`、`image → text/image/video`、`video → text/video`、`audio → text/video/audio`；其余 reference 边明确返回 `EDGE_CAPABILITY_UNSUPPORTED`。annotation 仍作为无媒体含义的图注边保留，不受该矩阵阻断。

模型专属能力没有被提前塞入图写入：替代模型可以让关联保持，真正提交继续由已选模型的市场目录、模式、容量、首尾帧和资产校验决定，避免破坏 M02 的“关联可保留、生成按当前模型拒绝”语义。

- PASS：本地 develop 的隔离数据库运行新 `p3_connection_matrix.php`，服务端 4×4 **16 个组合**逐一验证允许保存或明确拒绝，另有 annotation 保留 **1 PASS**。
- PASS：`p1_graph_operations.php` **10 PASS / 0 FAIL**，既有同一素材首/尾帧角色、精确移除、布局版本和重放收据保持通过。
- PASS：本地 web develop 使用桌面受管 Node 运行生产 `connection-rules.ts` 行为测试 **27 PASS / 0 FAIL**；覆盖同一 16 组合以及 M02/M03/M04/M05/M10。

这是前后端各自实际生产规则的对照与服务端强制边界，不把两个独立实现错误称为“同一共享源码”。浏览器拖放到真实 API、图片/视频真实 Provider 成功和 M09 私有签名刷新仍未运行；M06/M11/M12/M14/M16 也未完成，故 P3 仍**未整体放行**，P4—P6 NOT_RUN。没有业务数据库写入、付费请求、迁移、部署或推送。

## 55. P3 画布媒体映射、版本保留与唯一扣费回归（2026-09-22）

从本地 develop 的 internal Docker 隔离库运行 `p0_generation.php idempotent`，**57 PASS / 0 FAIL**。测试执行实际 Canvas、GenerationIntent、短剧历史/资产投影和 PointService；仅四类下游 Provider Adapter 被测试替身替换，避免外部请求，所有夹具事务回滚。

- PASS：text/image/video/audio 的 canvas run 都可完成，短剧历史保留 4 条自由画布归属记录；三种媒体分别建立资产，重复详情读取不重复建立资产。
- PASS：实际 tenant/user PointService 各只产生每次接受一次的账本记录；重放十次、未知结果重试、延迟回执、节点删除和乱序完成均不重复扣费，且已完成媒体保留其历史资产版本。
- PASS：模型、分辨率、参考集合、首尾帧角色或顺序变化不能复用相同 generation key，也不能覆盖已冻结请求快照或到达下游。
- 结合第 51 节真实 Qwen 文本调用，canvas run、短剧历史、app task 与消费记录的实际生产映射已确认一条；本节补齐四类节点的服务级投影、资产版本和唯一 PointService 扣费行为。

M12 仍标为**部分 PASS**：该套件的媒体 Adapter 是隔离替身，尚没有一条真实图片/视频/音频 Provider 成功且从浏览器完成的 app-task/consumption/asset 全链路证据。M14 的“报价后确认 hash 失效”尚未实现；现有 generation key 冲突只能保护重复提交，不能替代报价确认。P3 因 M09、M11、M12 完整媒体链路、M14 等仍未整体放行，P4—P6 NOT_RUN；没有真实付费调用、迁移、部署或推送。

## 56. P3 用户选定旧素材版本的下游引用（2026-09-22）

发现素材库选择器已在前端元数据保存 `assetId`，但旧 `runPayload` 只发送 URL，服务端也未按该 ID 重新核验和解析。因此同一节点多版本并存时，浏览器 URL 可被篡改、旧版本也无法成为明确的权威输入。

server `bcf2c4aa4` 与 web `b5a603b` 修复：图片/视频/音频素材库引用均向 payload 发送 `asset_id`；画布服务仅接受当前 tenant/user、当前画布或用户素材库、`ready` 且类型匹配的资产。服务端以资产的 URI/存储元数据重新经 FileService 解析当前 URL，并在 intent 快照与下游输入中保留该 asset ID；传入的浏览器 URL 不再是身份依据。

- PASS：隔离 `p3_asset_version_reference.php` **5 PASS / 0 FAIL**。同一画布的旧/新图片版本并存时，用户明确选择旧资产后，run 快照和模拟 Video Provider 都收到旧 asset ID/URI；新版本不替换旧版本。
- PASS：伪造 URL 或其他用户的 asset ID 在任何 Provider 调用前被拒绝 `CANVAS_REFERENCE_UNAVAILABLE`。
- PASS：本地 web develop 的源码契约确认 image/video/audio 三类素材引用都将 asset ID 保留到 `reference_assets`。

本项为服务端身份/快照与前端 payload 验证，尚未执行浏览器素材选择器到真实媒体 Provider 的付费闭环；M16 的“用户选定旧版本作为下游输入”服务边界通过，完整浏览器/真实 Provider 证据仍待补充。P3 仍受 M09、M11、M12 完整媒体链路、M14 等门槛限制，未整体放行；无真实付费调用、迁移、部署或推送。

## 57. P3 画布幂等提交与视频报价确认（2026-09-22）

server `ced018ea6` 已将公开 `/canvas/run` 入口切换到既有 `GenerationIntentService`，web `7608a46` 为每个节点保存输入签名和 `request_key`：同一输入的浏览器/网络重试复用同一请求键，用户修改输入后生成新键。该改动不让 Agent 模型自动执行工具；Agent 仍是右侧对话，任何生成均须由用户明确操作。

随后 server `5a1b11169` 增加视频报价确认的服务端边界和 `upgrade_20260922_canvas_quote_confirmation.sql`：报价只调用市场 quote，不创建任务或积分冻结；确认令牌绑定 tenant/user/canvas/node/request_key、服务端规范化的模型/分辨率/时长/引用资产身份及目标节点内容版本。模型、分辨率、时长、已选素材身份或目标节点内容变化时，提交返回 `QUOTE_INPUT_CHANGED`，不能使用旧确认；已接受的同 key 重放仍返回既有 run，不需再次报价也不重发 Provider。PC 视频节点在提交前先显示积分确认框，确认后才请求 `/canvas/confirmQuote` 和 `/canvas/run`。

- PASS / M11（手动路径）：本地 develop 执行 `p0_generation.php idempotent` **59 PASS / 0 FAIL**。四节点重放十次仍仅有一次下游提交与一次 PointService 账本；变更模型/分辨率/参考集合/角色/顺序在下游前拒绝。视频删除、乱序完成等旧回归现已使用与产品一致的 quote→confirm→submit 流程。
- PASS / M14（服务边界）：隔离 `p3_quote_confirmation.php` **9 PASS / 0 FAIL**。实际市场 quote 验证不创建 app-task/consumption、不调用 Provider；显式确认可重放；模型、分辨率、时长、引用身份或目标节点内容变更均在 Provider 前失效；匹配确认仅创建一个 generation intent，随后重放不再提交。画布运行态或无关节点导致的全局 graph revision 变化不应使价格确认失效。
- PASS / 本地迁移：在已授权的本地 `x_cn` 开发库执行该增量 SQL，确认只新增 `la_aigc_short_drama_canvas_quote`（15 字段）。未迁移远程/生产库、未部署/推送。
- PASS / PC 静态回归：本地 web develop 的 7 项 Canvas 回归均通过，覆盖 request_key 持久化、视频 quote/confirm UI 调用、运行结果围栏和画布持久化。

仍为 **NOT_RUN**：真实浏览器点击视频确认并完成真实媒体 Provider/Worker/资产闭环（需在具体模型、报价与本次积分明确后逐笔确认）；Agent 与手动**同一个** generation request_key 的 UI 操作闭环尚无 Agent 受确认的生成动作，不能将手动 M11 PASS 扩展为 Agent M11 PASS；M09 私有签名 URL 刷新也仍未具备私有测试存储证据。因此 P3 未整体放行，P4—P6 NOT_RUN。

## 58. 本地画布 15 生成提交失败修复与回归（2026-09-22）

用户报告本地 tenant 1 的画布 15 提示“生成失败”。该次排查不重提生成、不发起新的 Provider 请求。数据库核对显示当时没有对应 `canvas_run`、没有视频消费账本，也没有新的 Provider 提交；故失败发生在本地提交前，未产生本次视频积分扣费。

- 根因：本地业务库 `x_cn` 缺少 `la_aigc_short_drama_canvas_generation_intent`。`/canvas/run` 在 GenerationIntentService 建立幂等意图的第一条查询即失败，因而不能创建 run、任务或下游请求。
- 修复：依已授权的本地增量迁移执行 `upgrade_20260921_canvas_generation_intent.sql`，仅新增该表；执行前不存在、执行后存在 **17** 个字段。没有操作远程/生产库。
- 修复：报价确认哈希移除全局 `graph_revision`，保留模型、分辨率、时长、引用资产身份、request key 与目标节点 `node_content_revision`。这避免 Worker 写入运行状态或无关节点保存后误报 `QUOTE_INPUT_CHANGED`；真实目标输入修改仍会拒绝旧确认。
- 修复：PC `/canvas/run` 失败提示现在读取 API 的 `msg/message`，而非始终显示“节点生成提交失败”，便于直接暴露缺表、报价过期或校验失败的实际原因。
- PASS：本地 develop 的隔离 `p3_quote_confirmation.php` **9 PASS / 0 FAIL**；覆盖报价无任务/无扣费、确认重放、目标输入变更拒绝、匹配确认创建唯一 generation intent、接受后重放不再提交。
- PASS：本地 web develop 的 `short-drama-canvas-idempotent-run.test.cjs` **4 PASS / 0 FAIL**；覆盖 request key、Agent/手动共用提交路径、视频 quote→confirm 和媒体刷新不重提。
- PASS：浏览器重载画布 15 成功。此次只验证页面恢复加载；**不将其记为真实视频 Provider 成功**。

已知边界：画布 15 的下一次真实视频重试仍为 **NOT_RUN**。它需要重新取得有效的即时模型报价，并在实际确认按钮前按用户授权逐笔确认；本记录没有代替那次付费操作。M09、完整 M11 Agent UI、M12 真实媒体浏览器闭环和 P3 其余未满足门槛仍按原状态继续验收。

## 59. P3 私有签名交付与真实视频结果账本复核（2026-09-22）

本轮没有新建生成请求、没有修改业务画布或积分账本。只在本地 `develop` 的隔离 internal Docker 网络复跑签名测试，并对用户已确认的本地 tenant 1 既有视频运行做只读核对。

- PASS / M09 服务实现：`p3_private_signed_url.php` **4 PASS / 0 FAIL**。Qiniu SDK 以惰性、无效域名的测试凭据从稳定 URI 两次重签，第二个 URL 的过期时间递增；`FileService::getFileUrlByStorage` 也走同一签名路径。测试容器没有外网、没有读取业务 `.env` 或真实存储凭据。公开存储明确保持直连路径，避免错误地为公开对象附加签名。
- NOT_RUN / M09 真实私有桶 HTTP：本地 tenant 1 已完成的视频资产 `#864`（`tenant/aliyun`）只读解析两次均为非空且无查询签名、URL 不变化。这证明当前租户这项存储配置是**公开读取**，不是签名刷新失效；因此不存在可用于真实私有签名刷新验收的本地私有对象。不能通过改写该已有资产的存储属性或伪造签名把它计为通过。
- PARTIAL PASS / M12 真实媒体落库：既有的浏览器确认视频任务 `canvas_run #26`（canvas 15、video 节点 `1790044681610`）状态 `success/100`；对应短剧生成任务 `#975`、应用任务 `#1079` 均成功，结果资产 `#864` 为 `ready`，且 `tenant/aliyun`、类型 `video/mp4`。权威消费 `#1101` 的上游任务状态为 `success`、账本 `settled`、实际用户积分 `40`；该值在用户本次 2000 积分上限内。用户提供的画布截图亦显示该视频节点已投影为结果，而不是仍停留在“生成中”。
- 保持 NOT_RUN：本轮没有以独立自动化浏览器再次打开该历史资产，也没有对私有桶发起 HTTP 获取；因此不能将上述真实运行与用户侧可见截图扩大为 M12 的“全部媒体类型、自动浏览器端到端”通过。M11 的 Agent/手动同键机制已有父级同一路径回归，但仍缺少实际浏览器点击 Agent“提交已选节点”再用手动按钮重放的无重复账本证据。

结论：真实媒体结果、资产和结算的**视频单路径**已得到本地只读证据；M09 代码和隔离签名行为通过，但真实私有存储前置条件不具备。P3 仍未整体放行，P4—P6 仍为 NOT_RUN；未部署、发布、推送或访问远程生产。

## 60. P3 Agent 与手动生成同键浏览器闭环（2026-09-22）

server `c9d21a3a8` 只新增 internal Docker 测试桥接模式，web `c45fc6d` 及后续测试修正新增 `short-drama-agent-generation-browser.cjs`。这不是生产路由、配置或模型变更：桥接仅在隔离网络、只读源码、遮盖 `.env` 的容器中同时启用 Agent UI 和 `BrowserMockProvider`；该替身只接受显式 `isolated-browser-mock`，不会连接真实 Provider。

- PASS / M11 浏览器闭环：Chrome → 右侧 Agent “提交已选节点” → 确认弹窗 → 真实 Canvas HTTP/Graph/GenerationIntent 路径。页面从权威文档重载后，手动节点按钮以保存的同一 `request_key` 重放；服务端返回同一 `canvas_run`。最终断言为 **1** 个 Provider receipt、**1** 个 run、租户/用户账本各 **1** 笔，余额分别从 100 到 99 / 98，测试退出 0。
- PASS / 投影保护：初始 Agent 点击已将同步文本结果写入权威画布节点；测试强制重载后才执行手动重放，避免把旧浏览器本地状态误当作重试授权。这样同时覆盖“响应丢失后重试”而不依赖客户端猜测新的请求键。
- 保持边界：此项是浏览器 + 隔离 HTTP/数据库 + 模拟 Provider 的行为验收，不能替代真实 Agent 指挥付费媒体生成；现有真实视频单路径证据仍见第 59 节。没有新增付费调用、业务数据库写入、迁移、部署、发布或推送。

因此 M11 从“仅手动服务/静态路径”提升为 **PASS（隔离浏览器同键闭环）**。P3 仍受真实私有存储 M09 前置条件、以及未覆盖媒体类型/取消退款等独立边界限制，未整体放行，P4—P6 继续 NOT_RUN。

## 61. P3 共享四节点引用能力夹具（2026-09-22）

此前 M01 虽有前端与服务端各自的 4×4 测试，但允许矩阵在两处重复定义，任一侧将来变更都可能让另一侧静默漂移。本轮新增版本化的 `p3_connection_matrix.json` 行为夹具：它只包含模型无关的四节点 reference 边与 annotation 规则，不把模型容量、价格或 Provider 专属能力写入图关系。

- PASS：后端 GraphService 从同一夹具执行 16 种 reference 组合和 1 条 annotation 对照；服务端允许或以 `EDGE_CAPABILITY_UNSUPPORTED` 拒绝的结果全部符合夹具。
- PASS：PC 生产 `connection-rules.ts` 从同一夹具执行相同 16 种组合；并保留 M02、M03、M04、M05、M10 的 11 条独立行为测试，合计 **27 PASS / 0 FAIL**。
- 条件能力边界：替代兼容模型可保留关联、实际提交仍验证用户选定模型；数量未知/目录未加载会在提交前 fail closed。上述行为由既有 11 条生产规则测试覆盖，不将其错误地编码成无条件图边。

验证在两仓库各自已合入本地 develop 后执行：隔离服务端矩阵 **17 PASS / 0 FAIL**，PC 规则 **27 PASS / 0 FAIL**。夹具与测试源码均可随两仓库特性分支交付；没有 API、迁移、计费、业务数据、Provider 调用或常驻进程变更。

因此 **M01 PASS（共享能力夹具）**。P3 仍未整体放行：M09 缺少本地 tenant 1 的真实私有存储对象；M12 仅有真实视频单路径，尚无各受支持媒体类型的完整浏览器闭环；真实 Provider 的取消/退款能力受上游契约限制。P4—P6 继续 NOT_RUN。
