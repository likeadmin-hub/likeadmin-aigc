# 首页通用创作 Agent：首版实施与验收

任务分支：两个源码库均为 `feature/agent-enhancements`。范围为首页输入框和 PC `aigc_llm` 的 Imagine Agent 工作区。旧版 LLM 页面、无限画布和短剧画布保留原流程。

## 已实现

- Neuron AI 4.1.9 工具调用循环，文本请求走现有模型市场和消费记录。
- 自然问答、方案/剧本；已注册原生图片、视频和 DOCX 导出能力。
- 用户选择对话/图片/视频模型；服务端冻结每轮模型偏好，不自动替换模型。
- 图片/视频/音频上传为当前用户所有的素材；图片通过已选择的视觉模型传入。音视频目前仅可作为素材保存，首批图片/视频适配器只消费参考图。
- 工具搜索、参数说明、参数校验、估价、方案确认；自动模式要求本轮工具积分预算。
- 会话、执行、步骤、事件、作品和偏好持久化；浏览器通过短轮询展示实时回复和任务状态，刷新恢复。
- 会话作品预览、下载、收藏和继续引用；文档生成真实 DOCX，复用租户存储与文件归属。

## 运行与计费边界

用户发送消息即调用所选文本模型，按现有 Token 规则计费。工具确认金额和自动预算只包含图片/视频生成，不包含规划对话 Token。每轮最多 8 次文本模型调用、8 个创作步骤。

提交带 request_id；租户、用户、请求标识组成唯一键。相同内容重试返回原记录，不重新创建执行。浏览器保留未确认提交的标识；工具提交前保存 submitting，结果不明时转 needs_review，不自动重发。已知业务 task_id 只查询恢复。停止阻止新步骤；已提交的任务继续收取结果，原业务服务负责结算。

图片/视频使用原业务服务的真实报价和价格快照，执行前重新检查报价，变更后重新确认。随机加密 IV 不会触发错误的报价变更。Agent 不额外扣生成积分，不伪造 API Key 或开放平台账单。

当前首发文本工具协议为 OpenAI Chat。Responses 和 Anthropic 原生工具往返尚未接入，首页模型目录暂不展示这些协议；旧聊天仍沿用原有协议支持。模型协议兼容不等于上游保证支持所有工具，需真实供应商验收。

## 工具接入方法

`AgentCapabilityCatalog` 是首批经过审核的原生适配器。首页登录用户与开放 API 的密钥准入是两个入口；本地即使未发布开放 API 契约，也可以按原应用权限使用图片/视频工具。共享参数校验、素材归属、估价和原业务服务，不直接让模型访问任意 URL、控制器或凭据。

后续接入试衣、TTS、音乐等，需要增加：参数 schema、当前用户应用权限、模型选项绑定、可靠报价、冻结价格适配、提交和查询、结果归属及失败测试。发布好的系统 API 契约可以复用，但不能仅有 URL 就自动开放收费调用。Skills 可在后续描述创作方法，不承担实际权限或计费。

多步任务目前只执行输入已知的步骤。依赖新生成素材的后续步骤由用户在结果上“继续创作”；自动跨步骤素材依赖编排、视频理解、音频转写、任意文档解析、所有工具自动发现仍是后续扩展。

## 部署

1. 安装/升级 `aigc_llm` 源码，执行应用迁移 `zz_20261013_home_creation_agent.sql`。同一幂等 SQL 同步到升级目录和全新安装库。该迁移只创建 5 张租户/用户隔离的新表。
2. 同步 `api_schema.json`，新增登录态 `app.aigc_llm.agent/*` 用户接口。没有新增管理菜单或扩大角色权限。
3. 在 server 目录运行 PHP 8.2：`php think home-agent:worker`，用现有进程管理器守护。`--once` 仅处理一条，适合检查。图片/视频原任务 Worker 仍需运行。
4. 部署 PC 源码构建结果时遵循项目发布流程。本次只提交源代码，未发布版本包、未推送编译产物。

本地已安装新增表、验证 `home-agent:worker --once`，并在现有 baota 容器启动临时 Worker（日志 `runtime/home-agent-worker.log`）。正式环境仍须配置进程管理器守护；本次没有改动系统守护配置。

## 验证记录

- `HomeAgentProviderTest.php`：真实 Neuron 循环配模拟传输，模型选择、工具参数和结果消息；旧聊天消息规范化保持兼容。
- `HomeAgentContractsTest.php`：预算输入、Schema 禁止身份覆盖、工具闭环、DOCX XML 与转义。
- `HomeAgentDatabaseTest.php`：本地事务回滚，提交幂等、不同内容冲突、租户/用户隔离、过期/重复确认、停止、报价指纹及快照隔离；余额不变。
- `HomeAgentWorkerTest.php`：真实执行 Worker 配模拟创作服务；已提交任务恢复、停止后继续查询、未知提交不重复执行。
- 前端 conversation / composer / run 共 17 项测试通过；PC Nuxt 完整构建通过。构建使用 `/private/tmp/home-agent-verify-nuxt` 独立缓存，避免与运行中的开发服务争用 `.nuxt`。浏览器验证了登录后工作区、创作偏好和模型目录。
- 未调用真实付费供应商。某些本地视频模型缺少有效 SKU，估价会拒绝而不会回退。开放平台旧价格集成测试只允许另一套 `z_cn` 固定夹具，在当前 `x_cn` 环境被保护条件阻止；未绕过该保护。

## 回滚与后续验收

回滚时先停止新 Agent Worker，再回退前后端源码；保留新表及业务任务/文件，不删除账单和作品。旧聊天接口仍可使用。默认卸载保留数据；明确清数据卸载才清除 Agent 数据。

上线前仍需：常驻 Worker 部署、各选定供应商的真实工具调用和生成验收、扣费/退款核对、对象存储上传与 DOCX 下载验收。真实付费测试应由拥有者指定模型和预算后执行。

### 创作方案展示与确认模式

- 前端方案卡片仅展示创作内容、数量、画质、比例、时长和预计算力，不展示原始参数、内部模型编码或工具调用 JSON。
- 对话正文格式化标题、列表和表格；序列化文本与工具返回内容先转换为用户可读文字。
- 工具反馈和最终调度使用相同的预算确认判断。咨询模式显示确认按钮，自动模式在预算内直接执行；超预算或报价变化仍需确认。
- Agent detail 增加只读的 `mode`、`confirmation_reason`（`budget_exceeded` / `plan_changed`），用于解释自动模式暂停原因。不改变现有确认哈希与计费规则。


## 会话上下文（2026-10-13）

首页 Agent 每轮重建上下文，普通聊天、无限画布和短剧画布保持各自实现。

- 近期正文最多 20 条 / 12,000 字符，最新用户请求始终保留。历史用户请求生成带原消息 ID 的需求摘记：保留首条及最近 12 条，每条最多 320 字符，不把助手的生成声明当成事实，不另调用收费模型。首次处理旧会话只回填最近 80 条早期用户消息和首条，完整原文仍可检索。
- 摘记和当前操作对象存于 `aigc_llm_agent_context`，按租户、用户、会话隔离。每轮校验摘记原消息和素材；删除的会话/消息/素材不再作为有效上下文。上传的新素材更新操作对象，生成的新作品成为当前对象；歧义先问用户，切换话题可通过工具清除对象。多个附件不会擅自变成唯一目标。
- 最近 4 次执行、合计最多 8 个步骤每轮从数据库读取，携带实际状态、参数、模型、报价及任务标识。`success` 且无步骤只是对话结束；咨询确认仍只能走原确认接口，记忆工具不能批准或提交任务。
- `read_session_context` 提供历史搜索、按 ID 分段读消息、查找作品、按素材 ID 分段读文档和查询任务状态。每段最多 4,000 字符，返回下一段偏移。生成文档直接读取持久化正文，不请求任意远程 URL，也不下载外部文件。上传的音视频及无正文文件只返回素材信息，不能伪称已理解。
- 视觉输入只附带当前对象/本轮明确读取的图片，每轮最多 8 张；其他旧图片只保留索引，需要时再读。不支持视觉的所选模型不会被悄悄替换。
- Neuron 保留 16,000 token 的历史窗口；传输前另对系统说明、工具结构、消息和图片做保守估算，以 24,000 输入 token 为软预算，仅移除完整旧轮次。当前用户请求和工具往返过大时明确提示分段，不截断工具配对。预算是估算，不是模型真实 tokenizer 或模型最大窗口承诺。

部署使用新增幂等迁移 `app/apps/aigc_llm/migrations/zz_20261013_home_context.sql`，与 `upgrade/20261013_home_agent_context.sql` 、`public/upgrade/20261013_home_agent_context.sql` 和全新安装 SQL 一致。该表是共享业务表、按租户分行，不需要新租户种子数据。本次仅交付手写迁移 SQL 源码，不包含生成的升级包或版本元数据。老会话在首次使用时建立上下文记录，不重写聊天记录或历史任务。

验证：`HomeAgentContextTest.php` 为本地事务回滚测试，覆盖长期需求找回、当前对象、文档分页、任务事实、租户/用户/会话边界、删除与旧数据兼容；不调用模型或提交生成。`HomeAgentProviderTest.php` 和 `HomeAgentPlanningTest.php` 覆盖 Neuron 工具循环及整体上下文预算。

## 第二阶段：音乐与试衣（2026-10-13）

图片、视频、DOCX 的真实创作已由用户确认验收。第二阶段增加首页 Agent 工具 `aigc_music` 与 `aigc_fitting`，继续走 Neuron 的查找、说明、计划、确认、执行流程，不把 API 地址直接交给模型。

- 音乐支持提示词、歌词、曲风、情绪、乐器、纯音乐开关与时长；输入框新增音乐模型偏好，保存并冻结到每轮执行。音乐不代表配音/TTS、声音克隆或音频转写已完成。
- 试衣首批支持上传服装图片及一张自定义模特图、生成一张图片。素材只接受归属当前用户的 file_id，按服装/模特分别传入；使用图片模型偏好，不兼容时拒绝，不静默替换。系统预设模特、多模特组图留待后续。
- 报价复用原业务服务，首页权限与开放 API 密钥权限分离；开放 API 原权限不放宽。音乐确认价格快照绑定原生任务后可在后台恢复读取，既有非 Agent 音乐任务不依赖 Agent 表；试衣复用确认的图片模型、SKU 和试衣规则快照。Agent 本身不重复扣生成费。
- 作品统一收集，音乐可试听/下载，试衣结果按图片展示；继续复用生成状态、咨询模式按钮、自动预算和报价变化重新确认。
- 没有新增数据库表或权限项，没有改画布流程。真实扣费/退款和新工具供应商验收仍需用户指定可用模型和预算；本阶段自动验证不发起付费生成。

本阶段验证记录：

- `HomeAgentExtendedToolsTest.php`：参数边界、素材归属、音乐任务价格持久化与恢复、试衣子任务模型快照，通过。
- `HomeAgentMusicWorkflowTest.php`：仅模拟音乐供应商传输，实际执行原生音乐服务和 Agent Worker；音频作品入库、按确认价格只扣一次、重复查询与收藏，通过。所有测试数据和扣费均事务回滚，没有访问付费供应商。
- `HomeAgentFittingArtifactsTest.php`：试衣父任务关联图片子任务，详情读取实际提交提示词和规格、子任务归属隔离、资产页与会话收藏同步，通过。
- 原有 Agent 合约、任务恢复、图片/视频收藏回归通过；前端 17 项测试、修改的 PHP 语法及 Vue 组件编译通过。浏览器确认音乐标签默认选中可用模型，固定面板高度避免切换标签时浮层收缩关闭。
- 本地只读估价可用：30 秒音乐当前报价 65，单张试衣当前报价 30；这是本地当前配置的探测值，不是固定产品价格。
- 旧 `AigcMusicPricingDisplayContractTest.php` 依赖未安装的 PHPUnit，未运行；开放平台旧夹具限制另一套数据库，未绕过。音乐、试衣的真实供应商生成及失败退款仍待验收，试衣本次未做真实生成端到端测试。
- 本地无活动任务时已重启 Agent Worker 以加载新代码；生产进程守护、TTS/转写、更多工具/模型协议和跨步骤自动编排仍未包含在本阶段。

## 技能浮层展示配置（2026-10-13）

首页与对话输入框技能入口使用悬停浮层，布局参考短剧画布。租户后台各应用“展示配置”可选择最多 5 张技能展示图片和 3 个展示视频；使用原素材库、上传和存储流程。图标取应用封面居中裁剪，预览优先视频，图片未配置时回退应用封面，没有素材时显示空状态。

媒体配置写入现有 `tenant_app_config.extra` 的 `skill_preview_images` / `skill_preview_videos`，无需迁移。旧客户端未提交字段时保留，显式空数组清空；保存仍保留原推荐等设置。Agent config 新增可选 `skill_displays` 映射，只返回当前账号已授权工具与 Agent 自身应用的标题、描述、封面、展示状态和媒体地址白名单，不返回其他 extra 配置；调用、报价和执行权限不变。回滚前后端源码即可，新增 extra 字段可留存。

## 跨会话作品库

Agent 作品列表接口支持 `scope=all`，按当前租户及用户查询全部会话作品；`kind` 筛选类型，`before_id` 按作品 ID 倒序翻页，每页最多 100 条。省略 scope 仍按 session_id 查询，保持对话内作品及已有调用兼容。无需迁移。前端右侧独立维护作品库状态，新建、切换会话不清空；生成完成通知作品库刷新，原消息中的结果仍按会话和步骤关联。

## 集中管理 Agent 技能

租户后台 AIGC 对话配置增加“技能配置”标签，统一维护试衣、图片、视频、音乐、文档、剧本、方案七项技能。可编辑名称、简介、回填提示词、素材说明、启用状态、排序、图片及视频。分类和工具绑定固定，封面仍取对应应用；技能配置不会授权额外工具或改变计费/模型偏好。

复用 AIGC 对话配置 detail/setup 权限，detail 增加 skill_configs，setup 可仅提交 skill_configs；保存到既有 config_json.agent_skills，无需迁移。旧应用展示素材在尚未配置技能时作为默认值，首次保存后各技能独立维护。基础配置保存保留技能配置，技能保存保留基础配置；原公共应用展示表单移除技能素材入口。

技能配置现为 AIGC 对话的独立菜单标签，与基础配置并列，地址 `/aigc-llm/skill-config`。基础配置不再包含技能配置二级标签，保存接口及已有配置数据不变。

技能管理支持增删改查及行内启停。现有 setup 接口分别接受 `skill_create`（名称、关联应用、回填内容及素材）、`skill_configs`（局部修改）、`skill_delete_id`（删除），每次只能提交一种操作。自定义 ID 由服务端生成；关联应用限定已接入的 AIGC 对话/图片/视频/音乐/试衣，不能扩大用户工具权限。内置技能删除标记保留，后续保存不会恢复；自定义技能删除不影响历史会话与作品。单租户最多 100 个有效技能。

展示类型 `preview_type` 为 image/video 二选一；video 模式配套 `video_poster`，公开预览使用 `video_poster_url` 作为加载占位图。切换展示类型保存时清除另一类展示素材。保存校验兼容请求 trim 过滤后的整数字符串，参数错误返回业务提示。

首页技能收藏按租户和用户存储在 Agent preferences.skill_favorites，preferences 接口使用 favorite_skill{id,favorite} 单项更新，不覆盖模型偏好与预算；正常偏好保存保留收藏。收藏不授予工具权限。

## Lightweight multi-step creation

The homepage uses a reviewed allowlist (image, video, music, fitting); studio applications are excluded. `plan_creation_followups` records at most three dependent followup stages in server-owned run preferences._sequence. After collecting owned outputs, the worker atomically advances to chat to prepare the next stage, using actual file IDs. Existing maximum of eight paid steps remains. No new table, migration or API route is needed. Text creation stays inside the model turn; DOCX uses create_document.

Ask mode confirms every newly priced stage; future stages with missing source outputs are explicitly unpriced. Auto mode checks cumulative estimates across the entire run, including already executed steps. Budget overflow or changed pricing requires confirmation. Earlier approval hashes cannot authorize a new stage. Model preferences stay fixed to the submitted run. Intermediate artifacts, replies and text usage records survive continuation. Failure, cancellation, partial results and missing outputs prevent automatic advancement. Missing information pauses the sequence for a new user message.

Run detail adds optional pending_points (currently proposed costs) and sequence (public active/pending/completed titles and state); estimated_points keeps its cumulative meaning. Text model costs remain separate. Old runs without sequence metadata retain their behavior. Offline tests exercise real Neuron and transactional persistence with mocked provider/pricing adapters; live paid multi-step provider acceptance is separate.


## Ordered replies and paid acceptance (2026-10-13)

All homepage Agent tools share durable `stages` in run detail. Each stage exposes only its user-facing reply, streaming state and owned step/artifact IDs. Internal stage boundaries are saved in the existing run preferences JSON; no migration, new endpoint or billing permission is introduced. Provider tool-loop text is appended, not reset; saved plans no longer replace model prose with a generic sentence. Failed streams flush their last delta. Copy becomes available when that stage's text stream ends. The UI displays each stage's text, plan and retained outputs before the next stage. Legacy sequences use their saved reply prefix and last stage boundary without rewriting historical data; prose already overwritten by older versions is not fabricated or restored by this change.

Live local acceptance: tenant/user 1, session 54, run 157 succeeded through AI fitting task 4 (native image task 84, GPT Image 2, 1 image/1K/auto) into video task 51 (Q4 Preview image-to-video, 3 seconds/540p). The second stage used only the newly generated fitting image; consultation required approval separately. Output artifacts 147/148 survived browser reload and appeared in the shared asset panel. Video preview played with a 3-second duration. Image billing row 63 deducted 30 points; video consumption 4219 settled 120 points. No duplicate generation was observed. Conversation consumption rows 4209–4214 and 4216–4218 remain `pending_usage` with no reported tokens: tool generation acceptance passed, but text billing is not yet fully reconciled.

The new timeline is covered by the transactional real-Neuron sequence test with mocked providers: per-stage copy state during deltas, multiple tool-loop replies, no duplicate final text, interrupted-stream tail preservation, ask/auto cumulative budget, two/three stages, output binding, refresh, cancellation, failure and clarification. Browser run 169 checked the prior real tasks through the updated chat flow without submitting new generation; it completed with the full reply retained.


## Audio and text billing acceptance follow-up (2026-10-13)

The common market text transport now requests OpenAI Chat streaming usage by default, including schema-less models. Explicit model stream settings remain respected; optional-parameter compatibility can remove rejected stream options for callers already allowed to retry. Homepage Agent still disables automatic transport retries and model fallback. Usage-only SSE tail chunks are retained; partial usage reports merge counters instead of discarding earlier input tokens. Anthropic/Responses payloads do not receive OpenAI stream options. No token estimates or historical back-charges were introduced.

A real homepage chat using the existing `market_text_12` preference (run 182, consumption 4225) returned 1,999 input + 82 output tokens and settled 20.81 user/tenant points. This verifies the new-request text billing path. A read-only audit found 45 older homepage consumption rows still pending actual usage, including the four music-planning calls; these remain unchanged and require authoritative upstream usage before reconciliation. Their lack of reported usage is not treated as free usage or a zero-price settlement.

Real music acceptance used the selected `music_generation` channel at the minimum accepted 5-second specification, quoted 65 points (one pricing unit): run 181, step 89, native task 3. Planning retained all four tool-loop text responses. The provider accepted one task, then its query endpoint repeatedly returned HTTP 200 / code 0 with “任务处理失败，请稍后重试” and no task status. No native music billing row or audio output exists. Successful audio generation/playback acceptance remains blocked by that upstream response; no duplicate generation was attempted.

The native music provider previously converted query/transfer exceptions into terminal generation failures. Such exceptions now propagate to the existing retryable read/poll boundary. A provider-declared failed task still fails normally. This applies to native music UI, homepage Agent and open API consumers. The known legacy acceptance run was audited into `needs_review` with its original task and pricing records retained; no resubmission, balance adjustment or fabricated result was performed.

Validation: standalone market text SSE usage and music query recovery tests; real native music/Agent/billing workflow with transport mocked and DB rollback (temporary query failure → successful retry, one submit, exactly one settlement, repeat poll, favorite); homepage planning and contract regressions. Existing PHPUnit payload expectations and the open API receipt test were updated, but those legacy runners were not executed (PHPUnit unavailable; receipt fixture requires z_cn and loopback setup). No frontend change, migration, permission expansion or release artifact. Rollback is source-only; added streaming options can also be explicitly configured per model. Production music reservation/settlement and ambiguous remote submissions still require the broader billing audit before rollout.

### Music prompt correction and fresh-session isolation (2026-10-13)

The user's upstream task-detail screenshot confirms task `task_a582b73231da68188328232f` failed with `missing_prompt`. The adapter omitted the prompt when `custom=true` and lyrics were empty. Custom instrumental requests now retain their description; lyric-only and ordinary music payloads remain compatible. The audited run 181/step 89 was finalized as failed with a recorded evidence event, without changing the original native task or charging it.

Real revalidation: run 186/session 81/step 92, native music task 6, upstream `task_482830979ba92f3a0c9a086f`, current preferred `music_generation`, custom instrumental, 5-second requested minimum. The task succeeded, persisted audio artifact 174 and result 5, and settled one music billing row (5) for 65 points. Browser playback verified `paused=false`, advancing playback time, no media error, and actual duration 209.43 seconds. Submission, generation, storage and Agent asset return pass, but the requested 5-second duration is not honored by this provider; exact duration support/quoting remains an acceptance limitation.

New conversations are independent context boundaries. Only the current session's messages, summaries, tasks and artifacts enter the model context. Global model/mode preferences remain settings, and the all-session asset library remains visible without being included as implicit context. A user may explicitly attach an owned asset from another conversation; this imports that file only. New-window reset clears drafts, references, retry identity and stale session route parameters, and invalidates pending history/confirmation/upload responses so they cannot populate the new window. Cross-session context tests cover the same user/tenant, as well as the existing tenant/user ownership checks.

Validation: same-account fresh-session context DB fixtures and existing planning regression passed; 14 frontend conversation/library tests passed; AgentWorkspace script/template compiled. Browser verified that new conversation removes the old session URL and keeps the shared seven-work library. No API, migration or UI styling change.

### Reasoning fallback must not become a reply (2026-10-13)

Reported run 184/message 630 belonged to session 77, the same session as music run 181. The shared text transport separates reasoning SSE from content SSE, but falls back to `reasoning_content` as its returned content when a tool turn has no final text. Homepage Agent previously appended that fallback to its durable replies, exposing internal analysis after the tool round. The Agent now strips responses marked `content_source=reasoning` before publishing snapshots and before constructing Neuron messages. Tool calls and actual usage remain intact; a reasoning-only final response without tools produces a friendly failure rather than an invented answer. Ordinary English/Chinese content and intermediate visible tool-round messages remain unchanged. Other common-transport consumers are unchanged; old persisted replies have not been rewritten.

Validation: new visible-reply regression exercises reasoning SSE suppression, a real Neuron reasoning-only tool round followed by a normal answer, subsequent message history, usage preservation and normal English/Chinese replies. Planning and common SSE usage regressions pass. Real fresh-session run 196/session 88 using the existing selected model returned a normal Chinese video clarification, without internal fields or any generation step. This is a text consultation check, not a new paid video submission.

### Topics inside a conversation (2026-10-13)

Each new request selects a topic before loading the main Agent context. A narrow semantic classification uses the selected text model, current request and up to 12 topic candidates (original request and up to three recent user-request excerpts). Assistant messages, task statuses, costs and media are excluded from this routing input. First requests and explicit reset prefixes do not need classification. Decisions are persisted under run preferences `_topic`, frozen across retries and subsequent sequence phases. Normal metered text usage applies to classification; no model fallback or extra generation authorization is introduced.

Only the chosen topic's message IDs, source summary, task records, artifacts and focus are available to the answering model and context tools. Topic focus is persisted per run, so an older completion cannot overwrite another topic's focus. New topics start empty apart from explicitly attached owned materials and global preferences. Continue decisions load the related chain; unclear/invalid/unavailable routing loads no old topic and blocks creation/sequence tools until a subsequent user message resolves the reference. Legacy messages remain stored and can be selected as an old topic without rewriting their records. Existing artifact-library presentation, task execution, approval and billing contracts remain unchanged; this change does not add concurrent composer input while a run is active.

Validation: local transactional topic fixtures cover unrelated request isolation, resumed chains, explicit reset, ambiguity and invalid topic IDs, bounded retrieval, late completions, explicit references and repeat resolution. Existing context, planning, visible-reply and sequence regressions pass. No schema, permission, frontend style or endpoint change.
