# 短剧、Canvas 与 Agent 创作链路计费只读审计

审计日期：2026-10-29。源码工作区：/Users/likeadmin/Documents/docker-dir/bt/wwwroot/likeadmin_aigc_saas。审计起点由主任务提供：server develop 7f5a21a27，web develop 2cf51aa2，包含 short-drama-next-optimization。协作期间其他执行者可能修复源码；本文引用是审计时快照，交付前应按函数名复核漂移行号。

本文只审计配置、报价、调用、结算、退款、费用展示与素材复用。未修改业务源码、数据库、Git；仅写本文。未执行模型付费调用、真实退款。宿主机没有 php 命令，本审计未运行 PHP 测试。未进行实际浏览器验收，因此 UI 结论限于源码渲染链路，不能宣称已完成真实视口验收。

## 结论与证据口径

当前已有可复用基础：租户成本与用户收费独立计算、共享 PointService、媒体任务报价/预占/结算/释放、价格快照、Agent 工具报价与确认、提交锁、短剧生成单元回执和历史素材复用。

当前尚未找到租户后台可选的“文本调用由租户承担还是用户承担”或“Agent 文本由租户承担还是用户承担”的业务策略。普通用户调用共享文本运行时，按市场零售价扣用户、市场成本价扣租户。现有“后台管理者调用、userId=0、用户售价置0”的服务端专用路径，不能直接当作普通用户租户补贴功能。

费用展示没有形成从一次创作到全部文本、修复、理解、媒体、退款的统一视图。部分后台已经有可汇总支出，前端仍未消费；媒体费用展示有基础，但状态映射不完整。

本文证据等级：

- A：源码直接证明调用、金额、状态或持久化行为；不等于真实付费上游调用已经验证。
- B：源码搜索、字段或渲染缺项，或者本地数据库只读聚合；需要实际页面、安装环境或来源核验补充。
- C：由明确调用顺序推导的风险场景，尚未执行复现。

所有实施项均为**建议，尚未实现**。承担方策略应由主任务形成完整优化方案后推进；本轮没有盲改承担方或计费行为。模型恢复、array 类型等其他协作任务的修复不计入本文完成范围。

## 一、本地只读配置与账本证据

以下由主任务从本地 z_cn、127.0.0.1 只读检查后传回；仅保留必要聚合和配置键，不包含原始提示词、资产地址、密钥或详细用户账号。

tenant_id=1 的 tenant_app_config.extra 顶层键：

| 应用 | 已有顶层键 | 承担方相关键 |
| --- | --- | --- |
| aigc_canvas | agent_skills、is_recommend、agent_skills_version、skill_preview_images、skill_preview_videos | 未发现 billing/payer/charge/consume 承担方字段 |
| aigc_short_drama | is_recommend | 未发现 billing/payer/charge/consume 承担方字段 |

主任务同时确认三端当前配置未提供承担方选择。源码中对 server/app、web/tenant 的 consume_mode、billing_source 检索亦无匹配；此结果不能排除数据库其他未检查位置存在历史自定义字段，但已检查业务配置和当前运行时均未见生效策略。

本地 ai_consumption_log 按 action/状态的聚合摘录：

| 范围或动作 | 状态 | 条数 | 用户金额合计 | 租户金额合计 |
| --- | --- | ---: | ---: | ---: |
| 短剧 script_plan | settled | 17 | 255.640570 | 255.640570 |
| 短剧 script_plan_repair | settled | 1 | 7.880950 | 7.880950 |
| 短剧 script_plan | pending_usage | 4 | 未在本摘录中给出 | 未在本摘录中给出 |
| model_route_probe | pending_usage | 1 | 未在本摘录中给出 | 未在本摘录中给出 |
| canvas_visual_understanding | settled | 1 | 4.557 | 4.557 |
| design_agent_canvas_loop | settled | 5 | 23.268 | 23.268 |
| skill_clarification | settled | 2 | 6.041 | 6.041 |

主任务另提供媒体账本聚合：subject 5 条/150，scene 2 条/60，shot 10 条/300，threeview 2 条/60；shotvideo settled 2 条/480、refunded 14 条/实际结算金额0；BGM 2 条/130。这里媒体金额按主任务给出的聚合原口径保留，不据此推断每条上游单价、用户与租户两侧的明细等同性。

**这些样本可能包含验收 fixture。它们只能证明本地账本存在这些 action 的结算或待用量记录，不能证明每条记录对应真实付费上游调用，也不能把历史 settled 当成当前新策略已实现的证据。** refunded 的 actual 金额0亦不代表已核对全部预占与退款流水。完整对账仍需消费编号、供应商回执、两侧资金日志与不可变快照的关联检查。

## 二、承担方语义与当前实现

“用户承担”表示租户按供应商/平台成本付费，同时用户按租户零售价付费；不是租户成本变成0。“租户承担”应保留真实成本账，将本次用户应付设为0；不能把原用户零售价叠加到租户成本上再扣一遍。

| 当前入口 | 行为 | 证据 |
| --- | --- | --- |
| 普通用户文本、短剧文本、Canvas 文本与 Agent 文本 | input/output.platform_price 计算 tenant_cost_points；input/output.tenant_price 计算 user_charge_points | server/app/common/service/power/MarketTextModelRuntimeService.php:715-722，quote |
| 租户后台文本工具 | 第五个服务端参数 tenantAdminConsumption 仅允许 tenantId>0、userId=0；把 input/output.tenant_price 置0，保留成本价 | 同文件:157-185，generate |
| 后台 Skill 草稿优化 | 使用 tenantId、userId=0、tenantAdminConsumption=true；返回 billing | server/app/common/service/app/aigc_short_drama/AigcShortDramaService.php:2620-2645，optimizeSkillDraft |
| Canvas 普通用户文本 | 统一委托 MarketTextModelRuntimeService::generate，未加入租户补贴策略 | server/app/common/service/app/aigc_canvas/AigcCanvasService.php:1436-1445，llmText |
| 短剧用户文本 | 冻结有效文本模型后，以真实 userId 调用共享运行时；本轮模型恢复允许用户显式选择，未改变承担关系 | server/app/common/service/app/aigc_short_drama/AigcShortDramaService.php，resolveSelectedModels / generateWithModelFallback |

后台“选择统一文本模型”和“承担此模型费用”是两个不同配置。web/tenant/src/views/apps/aigc_short_drama/config.vue:80-85 只选择文本模型；web/tenant/src/views/apps/aigc_canvas/config.vue:46-53 只选择节点提示词优化开关和模型。

把共用市场 SKU 用户售价改为0会影响其他使用同一 SKU 的应用/入口，不是按应用、动作、任务冻结的完整承担方功能。

## 三、阶段费用表

| 创作阶段 | 当前计费与状态 | 当前承担关系 | 建议方案（尚未实现） |
| --- | --- | --- | --- |
| 输入、手动编辑、选择素材、登记资产、保存画布 | 本地动作，无模型调用扣费 | 无模型费用 | 内部自动保存，不增加确认 |
| Agent 话题判断 | 历史候选存在且非明确重置、未命中图片延续规则时，可能额外一次文本调用 | 普通用户文本两侧计费 | 纳入 Agent 承担方；作为内部辅助运行 |
| Agent 规划与工具循环 | 每次模型回复独立消费；单 provider 最多8次，方案纠正可额外一次但计入8次 | 普通用户文本两侧计费 | 租户后台独立选择 Agent 文本承担方；内部限制调用和费用 |
| 长资料读取/归纳 | 约8000字一段，逐段调用；单文件最多128批，一次最多8文件 | 各段独立文本计费 | 展示读取范围、累计支出与预算；不能承诺仅一次对话费 |
| 短剧故事、剧本、分镜文本 | 按真实输入/输出 Token，形成规划单元回执 | 普通用户文本两侧计费 | 统一短剧文本承担方覆盖创建/续写/修改，保留动作明细 |
| 连贯性审查、对白修复、JSON修复 | 可能是独立文本调用，V3修复有独立 planning unit | 默认与普通文本相同 | 内部质量修复建议租户承担；用户主动重做另列费用 |
| 参考图角色/场景描述 | 独立视觉文本调用，返回 prompt/model/channel，未附 billing | 普通用户文本两侧计费 | 纳入文本策略，显示承担方与完成费用 |
| 手动画布短提示自动优化 | 生图前可能先进行 canvas_prompt_enrichment 文本调用；图报价未包含该项 | 普通用户文本两侧计费 | 优先内部辅助由租户承担；若收用户费必须并入报价/预算 |
| 角色、场景、三视图、分镜图片 | 市场图模型或应用 API；固定价预占，实际用量 SKU 待 usage | 图模型成本/用户售价独立 | 批次列数量、规格、单价、预计或实际用量结算方式 |
| 镜头视频 | 市场视频模型或应用 API，独立任务和账单 | 视频成本/用户售价独立 | 开始本批前明确费用；不确定状态先核对原任务 |
| BGM、配音、转写 | 独立媒体调用；配音/转写有 reserve/settle/release | 媒体成本/用户售价独立 | “Agent文本由租户承担”不能误扩为所有媒体免费 |
| 历史/已生成素材复用 | 符合规则时绑定或复制资产引用，无重新生成收费 | 本次模型费用0 | 明示“复用已有素材，本次0点” |
| 本地FFmpeg合成、ZIP素材包导出 | 当前模型价分支不覆盖导出，旧 price_config 强制清空，金额为0 | 服务器资源由运营承担 | 显示“合成导出免费”；如未来收费另建明确动作与价格 |

阶段证据：

- Agent 话题判断：server/app/common/service/app/aigc_llm/agent/AgentExecutionService.php:297-308；AgentTopicService.php:54-64。
- Agent 8次上限与修复：server/app/common/service/app/aigc_llm/agent/MarketNeuronProvider.php:38-58。
- 长资料：server/app/common/service/app/aigc_llm/agent/AgentLongReadService.php:27-28、89-96、116-124。
- 短剧修复单元：server/app/common/service/app/aigc_short_drama/AigcShortDramaService.php:16895-16904。质量审查是否开启受任务版本/请求快照控制，见 ShortDramaStoryWorkflow.php:12-34；不可笼统宣称所有任务必跑审查。
- 图片理解：server/app/common/service/app/aigc_short_drama/AigcShortDramaService.php:1652-1675。
- 图片预占：server/app/common/service/power/MarketImageModelRuntimeService.php:307-342。实际用量型 SKU 为 pending_usage，不执行固定金额预占。
- 配音：server/app/common/service/app/aigc_llm/agent/AgentSpeechTool.php:129-132、194-198；转写：AgentTranscriptionTool.php:76-79、98-102。
- 导出：server/app/common/service/app/aigc_short_drama/AigcShortDramaService.php:15483-15492 的 generationModelBilling 只识别图/视频；导出回退 estimateGenerationBilling:15394-15430；price_config 保存:821-824 和读取:15803 强制为空。

## 四、问题、触发后果、优先级与建议

### F01：普通用户承担方策略缺失

优先级 P1；证据 A+B。

触发：租户选定统一文本模型，希望为用户承担创作或 Agent 费用。

后果：现有用户入口仍按 SKU 零售价扣用户，选模型不会改变承担方。后台专用 tenantAdminConsumption 也不能让普通用户借用 userId=0。

证据：MarketTextModelRuntimeService.php:157-185、715-722；配置页面见第二节；本地只读配置见第一节。

建议：共享服务按 tenant/app/action 解析承担方，而非控制器常量；主任务可规划短剧文本、Agent文本以及内部辅助动作继承/覆盖策略。策略仅服务端确定；保存任务时冻结策略版本、来源、用户应付与成本价格。不要靠前端传 payer、改共享 SKU 售价或冒用后台身份实现。

### F02：文本最低余额检查与实际支出之间缺少预算保障

优先级 P1；证据 A+C。

触发：用户或租户只有少量余额，或者并发任务消耗余额；一次对话、长资料或修复的实际 Token 成本较高。

后果：上游生成先发生，最终扣费时余额不足，进入 settlement_failed；对运营而言已有上游成本，对用户而言内容/工具是否继续需要明确恢复。

证据：MarketTextModelRuntimeService.php:703-708 只校验正价侧0.01；:231-234 以 quote(0,0)创建记录；:744-748 是 pending_usage 且 reserved金额0；:764-765 收到 usage 后再次检查并扣费；:773-785 保留延迟结算证据。

建议：最终只按真实 usage 结算，另设保守预算、资金保障和有界输出，明确预估不等于扣费。增加并发保障、超预算暂停、待核对恢复。实际用量型图模型也应纳入相同风险评估，不能仅修文本。

### F03：当前自动模式没有新任务金额预算上限

优先级 P1；证据 A。

触发：用户选择 auto，Agent 规划多步骤、后续 sequence 或长资料分析。

后果：单 provider 8次限制不等于整个请求的费用上限；话题分类和多批读取还能增加成本。

证据：server/app/common/service/app/aigc_llm/agent/AgentStore.php:43-46 只保留历史预算；:55 读取新偏好移除 budget；:89-97 新偏好只接受 mode/models/generation。AgentRunCard.vue:37-41 明示累计仅含已估价步骤、后续费用未计入。

建议：租户设置内部最大调用次数/Token/金额；用户可按任务设置媒体总预算。达到边界时暂停剩余步骤、展示已扣与未执行部分。不能通过隐藏步骤让无限自动支出变得不显眼。

### F04：手动画布图片预估遗漏自动提示词优化费用

优先级 P1；证据 A+C。

触发：短提示、direct模式、没有参考图/产品上下文、租户开启优化。

后果：用户看到图片报价，实际可能先产生文本费用；优化成功后图片预检失败，也可能只有辅助文本已经发生费用；优化异常被忽略，主流程继续。

证据：AigcCanvasService.php:591-609 预估仅支持image/video；:619-622 先准备提示词再createRun。server/app/common/service/app/aigc_canvas/prompt/PromptSubmissionService.php:29-30 调自动优化，:61-63 才执行图片路由/预检；PromptEnrichmentService.php:24-39 以真实用户调用 llmText，:45-47吞掉异常；触发条件:98-121。

建议：内部辅助默认租户承担；如果允许收费用户，纳入同一预览和 parent trace。可确定的模型、参数与资产预检尽量在收费辅助调用前完成；已生成优化草稿按内容版本复用。

### F05：已有子步骤支出没有完整接入新前台

优先级 P1；证据 A+B。

触发：短剧任务部分单元付费后失败；Agent 已做多轮规划/读取，但媒体方案尚未确认。

后果：只看父任务或媒体预计费用会遗漏已发生文本费用，用户难以知道取消后为何仍有支出。

证据：server/app/common/service/app/aigc_short_drama/ShortDramaPlanningSpend.php:7-27 汇总 settled/deducted 与 pending 状态；AigcShortDramaService.php:23436-23445 返回 generation_spend（初读时为:23422-23431，协作修改已引起行号漂移，本文引用已复核）；web 全量搜索无 generation_spend 消费。AgentExecutionService.php:308-309、344-345、359-361 把每次消费写入 token_usage_json.calls；新 Agent 组件仅 AgentRunCard.vue:20、36-41 展示工具预估和“对话模型费用另计”。旧 LegacyLlmWorkspace.vue:176-177 有用量/费用提示，新工作区不能据此认为已经展示。

建议：统一费用卡区分已扣文本、已扣媒体、预占、退回、待用量和待核对；从消费日志聚合，不只依赖父任务状态。先接入现有 generation_spend 与 Agent calls 可以较快改善展示，但最终应回查权威消费记录防止快照状态过时。

### F06：生产面板没有覆盖完整账单状态

优先级 P2；证据 A+B。

触发：媒体消费返回 settled、pending_usage、settlement_failed 等状态。

后果：制作面板显示笼统“费用以账单结算为准”，无法清楚区分成功结算、等待用量与结算异常。

证据：web/pc/components/short-drama/ShortDramaProductionPanel.vue:24-25 已展示费用；web/pc/utils/short-drama-production-state.mjs:11-17 只映射 refunded/deducted/reserved/none。面板默认折叠(:42)且终态仅取失败或导出最近5条(:45-48)，不能作为完整账本。

建议：统一计费状态适配；settled显示已结算，pending_usage显示待上游用量，settlement_failed显示待核对；0点区分免费、租户承担、复用。完整账单从单独详情查看，不扩大制作面板到全部技术日志。

### F07：短剧画布对话适配器未返回费用关联引用

优先级 P2；证据 A。

触发：短剧 Canvas Agent 对话完成一次收费文本调用。

后果：底层存在消费账，但业务返回仅正文/工具/安全标志，前端不能靠本次返回直接聚合run费用。

证据：server/app/common/service/app/aigc_short_drama/canvas_agent/MarketTextConversationProvider.php:120 调收费运行时，:133 返回 content/tool_calls/safety_checked，未保留 consumption_id/billing。

建议：消费引用绑定run/stage，由后端汇总后对用户展示；不必把价格信息塞给模型，不增加一次模型调用来解释账单。

### F08：已有素材复用与缓存基础应扩展，不能重做为重生成

优先级 P2；证据 A。

触发：跨集或跨画布使用已完成的角色、三视图、场景，或者反复打开历史项目。

当前基础：ConversationAssetReuse.php:23仅series开启、:40只支持subject/three_view/scene；:15-16签名含prompt/creative_settings/model/parents；:42核对归属及ready资产；:70-81只复制资产引用，不下载不再生成。ConversationWorkflow.php:756-759 复用报价0。

建议：按合法归属、资产状态、内容版本、生成条件扩展其他素材复用；节点变更只标需复查，不自动收费重做。保留 source asset/consumption 引用；旧资源失效须展示真实状态。现有 AgentReadCache.php:5、13-29 是单同步规划阶段缓存，不能把权限或可变任务状态跨任务缓存。

## 五、应保留的现有防重复、快照与恢复边界

- server/app/common/service/app/aigc_llm/agent/AgentExecutionService.php:182-188 按工具和规范参数形成 step_key，避免同方案重复保存。
- 同文件:601-629 在提交前重新校验归属、租约、状态、能力与报价；报价/契约变化回到 awaiting_confirmation，不应为减少弹窗直接绕过。
- server/app/common/service/app/aigc_llm/agent/AgentPlanService.php:94 要求原失败任务无结果且费用处于refunded/none/prechecked才允许重试。
- server/app/common/service/app/aigc_short_drama/ShortDramaPlanningUnit.php:274-278、296-310 已收到回执直接重放本地结果；running中断不直接二次提交；调用不跨DB锁。
- 同文件:225-242 校验失败保留付费回执；显式继续生成独立修复单元。不能将“父任务失败”解释为所有已完成模型调用自动免费。
- server/app/common/service/app/aigc_canvas/CanvasHistoryImportService.php:8、29-30、66-70 只迁移消息，不恢复历史未完成收费动作。
- server/app/common/service/point/PointService.php:31-42、49-85、136-159 统一两侧预占、差额退款/补扣及消费标签；租户与用户金额不能绕开共享服务直接改余额。
- server/app/common/service/point/TenantPointService.php:71-88、UserPointService.php:28-53 已有来源流水检查和账户锁；最终幂等还依赖业务任务/消费状态，不能只靠前端按钮防抖。
- MarketTextModelRuntimeService.php:745 保存 input/output价格快照；延迟结算必须继承同一承担方快照，不能重新读取当前配置改变旧账。

## 六、建议实施阶段与验收

以下为后续建议，尚未实现，最终承担方配置和默认值由主任务完整优化方案决定。

### 阶段1：先定义租户策略与不可变快照

统一租户/应用/动作的文本与 Agent 承担方解析，内部辅助可继承或单独覆盖。策略服务返回真实成本、用户应付、策略版本和来源；服务端写入新任务/消费快照。保留历史账单，不追溯补扣或改算旧任务。

验收：

- 用户余额0、租户充足：租户承担可执行，用户无扣款流水；用户承担在真实上游调用前拒绝。
- 租户不足：两种策略均在上游前拦截，不能把租户承担误解成跳过成本检查。
- 请求伪造承担方、userId、价格字段不改变后台策略。
- 配置修改后，已提交任务、延迟usage、重试、退款沿用原快照；新任务使用新策略。
- 同SKU在其他应用的售价和政策不受影响。

### 阶段2：补足预算、回执与费用关联

复用 AiAppTask/AiConsumptionLog/PointService，不新造余额系统。建立project/run/stage/action/attempt到消费记录的关联，所有辅助调用有purpose与父链路；预算估算和最终真实usage严格区分。内部调用设上限，不确定响应保持待核对，不盲目退款或重放。

验收：

- 一次创作能汇总话题分类、规划、读取、理解、修复和媒体调用；汇总金额与两侧流水一致。
- 重复callback/轮询/页面刷新不会新增消费或退款。
- 并发、余额不足、缺失usage、结算失败、取消、超时不确定、存储失败各有明确状态与恢复路径。
- 明确预占金额、实际扣费与差额退回，不用虚构百分比、估算金额冒充实际扣费。
- fixture与真实上游验收分开，真实验收需要供应商回执与资金流水对应证据。

### 阶段3：把内部工作自动化，保留必要决策

用户主流程集中需求、素材、当前阶段、可用结果和费用。内部路由、上下文整理、格式修复、轮询、转存自动执行，但有界、有账。用户只在意图不清、进入新增收费媒体批次、超过预算、改变模型/规格/数量、主动重新创作时作必要决定。

验收：

- 合法已有素材只绑定/复用，不再外部生成；页面恢复读取原任务。
- 修改文本、角色、场景、参考素材后不把旧结果当新版完成；允许用户继续用旧素材并清楚提示需复查。
- 删除节点或停止仅阻止后续未提交步骤，已提交任务继续核对结果与费用。
- 内部辅助由租户承担时不反复要求用户确认；当用户承担或扩大费用时，仍有明确依据。

### 阶段4：完成前台和后台费用闭环

顶部说明文本/Agent承担方；提交附近显示当前批次报价或真实用量结算方式；任务卡显示已扣、预占、退款、待核对。展开详情再看分项与技术编号。优先接 generation_spend、Agent calls 与统一状态适配，再进行跨端实际页面验收。

验收：

- 部分付费后失败的父任务不再只显示0。
- 自动模式也显示累计费用，后续未估价步骤不会假装已包含。
- 0点明确区分租户承担、复用、免费导出；“对话模型费用另计”附实际可查金额。
- 同一任务在PC/H5/后台金额与状态一致，用户看不到租户上游密钥、内部原始提示词或其他用户资产。
- 真实视口检查提交按钮附近的费用、折叠详情入口、长账单滚动与失败恢复；源码检查不能代替此验收。

## 七、未完成的验证与交付边界

本文没有执行真实端到端模型调用、资金扣除/退款、数据库修复、部署或发布；没有宣称上述建议已经实现。历史本地账本可能包含fixture，只作为动作与账本结构存在的证据。

主任务已整合模型选择/官方排序与 array 类型修复，定向测试和页面检查记录在 [完整流程方案](short-drama-canvas-flow-optimization-2026-10-29.md)。承担方策略、预算、统一费用展示、真实供应商回执验收仍待实施；本次模型菜单页面检查不能代替费用页面或真实结算验收。其他协作修改可能导致行号漂移，排查时以本文函数名定位。
