# Agent 原文核对与长资料结构分段验证

## 交付范围

- task: 增加关键事实的原文引句核对，为新长资料任务提供无损结构分段。
- branch: `feature/agent-optimization`
- server scope: AgentContextService、AgentExecutionService、AgentLongReadService、新增 AgentSourceVerifier / AgentTextSegmenter，以及对应离线回归。
- web scope: 无变更；保持 `62eb965926873a16701a014e0fb1ffcba5cf5842`。
- contract changes: 无 HTTP 接口、数据库迁移、权限或计费规则变更。现有 read_session_context 工具增加 verify_source 动作；新长读任务 JSON 增加可选 segmentation 标记及边界元数据。
- completed / remaining: 实现、离线回归、本地安全重载完成；真实模型理解准确率未进行付费评测。
- server commits: `affd2017d`、`95469d752`、`459879aba`。

## 行为与兼容性

原文核对复用当前用户、租户、会话和话题的来源访问校验。支持原始消息、文本资料及 PDF 提取文字。一次读取有界窗口，避免拼接两次读取期间可能变化的来源。返回精确引句匹配、周边文字、位置及窗口摘要；重复引句只判定零、一、至少两处，避免无界扫描所有命中。

`semantic_verified` 始终为 false：文字存在不代表事实理解正确、用户已批准或任务已执行。没有命中只描述当前窗口。PDF 仅核对提取文字，不能证明扫描图片内容；窗口摘要不是整份文件签名。工具可用且有提示约束，并非对每条最终回答强制执行的语义验证器。

新长资料任务优先按段落、换行及句末分段，兼顾 Markdown 标题/表格和 CSV 引号内多行字段。返回的内容不删改原文，游标按实际消费字符推进。无安全边界时保留有界硬切分并标记 forced_split。结构规则不是模型语义理解，不保证所有跨段条件始终在同一片段。

普通读取仍为原有 4,000 字窗口；历史长读任务未带新标记时沿用旧分页与游标。取消、续读、完成后继续对话沿用原有安全流程。不改变原素材或存储记录。

质量优先：分段可能比固定窗口产生更多片段，原文核对也可能增加模型工具往返；本次没有节省 token 或降低实际费用的承诺，计费规则保持原样。

## 验证结果

在合入本地 develop 后运行，18 个离线测试文件全部通过：

- HomeAgentTextSegmentationTest
- HomeAgentSourceEvidenceTest
- HomeAgentContextChatTest
- HomeAgentContextTest
- HomeAgentTopicTest
- HomeAgentRequirementMemoryTest
- HomeAgentLongReadFidelityTest
- HomeAgentLongReadTest
- HomeAgentUploadedDocumentTest
- HomeAgentMediaUnderstandingTest
- HomeAgentBillingRecoveryTest
- HomeAgentPlanManagementTest
- HomeAgentSequenceTest
- HomeAgentExecutionBoundaryTest（17 个场景）
- HomeAgentResponseRecoveryTest（18 个场景）
- HomeAgentCapabilityIsolationTest
- HomeAgentContractsTest
- HomeAgentReadCacheTest

覆盖 Unicode 原文重建、段落/标题/表格/多行 CSV、硬切分、历史游标、跨窗口引句、来源更改/删除、重复引句、真实本地 PDF 文字提取、消息角色、跨租户/用户/会话/话题隔离、取消续读幂等、完成后继续对话，以及既有方案/多步/计费恢复契约。

PHP 语法检查通过；一致性窗口修正后重跑 8 个相关测试，重复引句优化后重跑 SourceEvidence 与 ContextChat，均通过。模型调用使用 mock；数据库场景回滚；临时 PDF 在 finally 中清理。验证命令禁用 curl_exec、curl_multi_exec、fsockopen、pfsockopen、stream_socket_client 和远程 fopen。没有提交新的付费任务。

## 本地重载及账务保护

确认 Agent 无活动任务、无活动租约，且无可执行的短剧阶段/规划/导出任务后，通过现有 Supervisor 组优雅停止并启动。未直接杀死 Agent 子进程。Agent PID 从 352220 更新为 358596，Supervisor 程序 PID 为 358400；超过两分钟后及最终复核仍为单个健康 idle Agent，ready / running / expired_leases 均为 0。

验证前与重载后的快照比较：用户及租户余额摘要、消费记录数量与财务摘要、用户/租户完整账务流水摘要、Agent 记录数量与状态摘要、队列状态及租约均一致。历史转写待核对 1 条、预留 40 积分；文本 pending_usage 41 条、settlement_failed 2 条，保持原状。没有补扣、退款、人工核销或重复提交。原有 process_result 后台轮询仍可能更新调度时间，本结论不宣称全部运行时字段不变。

容器临时核查证据：`/tmp/agent-source-before.json` / `agent-source-final.json` 与 `agent-source-ledger-before.json` / `agent-source-ledger-final.json`。证据不纳入交付源代码。

## 风险与边界

离线测试证明已覆盖场景的来源隔离、无损分段、游标及恢复契约，不证明真实模型回答一定无遗漏或理解偏差。真实模型效果需另行授权评估；历史待核对记录继续等待供应商核查。生成产物、运行时数据和图片视频素材均不纳入本次提交。
