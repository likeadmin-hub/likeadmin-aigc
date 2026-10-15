# Agent 上下文完整性复查

本次按“理解准确、内容完整、功能稳定优先”复查此前上下文压缩和读取优化，不以降低 token 为目标。未进行真实模型付费评测。

## 核查与修复

| 位置 | 原行为与风险 | 本次处理 |
| --- | --- | --- |
| MarketNeuronProvider | 超过固定 24,000 估算预算时删除较早完整轮次，旧条件可能未进入模型 | 保留全部已组装消息；超过容量在调用前明确拒绝，不删除轮次。优先采用后台模型输入/上下文容量，并预留原有输出额度；未配置时保留 24,000 的保守保护 |
| Neuron 历史层 | 独立的 16,000 窗口可能在 Provider 检查之前裁掉历史 | 关闭该层自动裁剪，由 Agent 的完整输入检查统一保护；未修改 vendor |
| 最近对话窗口 | 字数或条数边界可能拆开前一轮问题与回答；孤立助手消息会被 dialogue 忽略 | 在用户消息边界结束，至少保留紧邻的前一轮交流；异常历史的孤立助手正文作为明确标注角色的数据保留 |
| 话题识别 | 用户目标只给前 500 字；助手回复仅头尾，中间主体或追问可能消失 | 对选中的候选保留完整目标和回复；超容量时沿用澄清路径，不以残缺线索强行分类 |
| 创作焦点 | 目标仅存 600 字或头尾摘录，晚出现的限制丢失 | 新写入目标完整保存；超过存储容量明确拒绝且不覆盖旧目标，既有记录不批量改写 |
| 任务上下文 | 提示词参数仅有 280 字摘录，task_state 再读仍是同一摘录 | 默认预览显式标注截断字段；task_state 工具返回所列任务的完整提示词与请求，避免据缺失条件的预览重建方案 |
| 文档读取缓存 | 文件同大小、同时间戳被替换时可能读到旧内容 | 本地缓存键纳入内容摘要，提取前后核对文件；远程对象没有不可变版本约定，不再仅按数据库元数据复用正文 |
| 长资料分析 | 仍提示摘要 3,000 字、笔记 500 字；分段请求主要依赖当前一句话 | 移除字数目标，注入当前授权话题的近期原消息、历史摘记及需求线索；完整保存原有摘要/笔记/源片段。超容量在本段模型调用前停止并保留游标，允许原有安全续读流程 |

视觉输入容量估算不把 base64 图片字节当成文字 token；仍保留每张图片的估算额度，附图后再检查。模型、供应商协议、工具授权和计费规则未改。

## 保留的合理边界

- 历史消息索引、需求候选和作品目录仍分页。原始消息和资料可按来源 ID、游标补读；摘要和候选不承诺覆盖全部历史。增加 history_partial、next_before 和明确补读说明。
- 摘记 320 字、需求片段 600 字、转写预览 4,000 字仍是有标识的预览；原文并未据此删除，文本分页可重建全文。任务参数的完整补读入口本次已补齐。
- 文档大小/字符容量、视觉素材数量、模型工具循环次数属于资源或执行保护；没有为了“更智能”解除这些边界。触及限制不能声称已经读完。
- 长读原文重叠 1,000 字、完整笔记和源片段仍保留；摘要不是原文，原文匹配也不是语义正确性的证明。
- 原作用域缓存、租户/用户/话题隔离和工具元数据按需读取保留。原 `94c48cf12` 的多数变更是查询/解析复用，并非减少模型输入；上述风险也包含更早的上下文设计，不能全部归因于该提交。
- 短剧主体库编辑时的既有字段长度、标题/错误展示长度属于各自产品/存储契约，本次没有扩展这些边界或改写历史数据。

## 回归验证

全部命令在 feature 提交合入本地 develop 后执行。24 个不同测试文件通过：

1. HomeAgentContextFidelityTest
2. HomeAgentContextTest
3. HomeAgentContextChatTest
4. HomeAgentRequirementMemoryTest
5. HomeAgentUploadedDocumentTest
6. HomeAgentTopicTest
7. HomeAgentLongReadFidelityTest
8. HomeAgentLongReadTest
9. HomeAgentSourceEvidenceTest
10. HomeAgentTextSegmentationTest
11. HomeAgentMediaUnderstandingTest
12. HomeAgentReadCacheTest
13. HomeAgentPlanManagementTest
14. HomeAgentSequenceTest
15. HomeAgentExecutionBoundaryTest
16. HomeAgentResponseRecoveryTest
17. HomeAgentCapabilityIsolationTest
18. HomeAgentBillingRecoveryTest
19. HomeAgentContractsTest
20. HomeAgentResponsesProtocolTest
21. HomeAgentAnthropicProtocolTest
22. HomeAgentProviderTest
23. HomeAgentPlanningTest
24. HomeAgentMessageAttachmentsTest

关键确定性对照：

- 超过原框架窗口的旧要求、末尾更正，在实际 Neuron 工具循环的两次 mock 请求中均完整保留。
- 小容量时 transport 调用次数为 0；指令、工具描述、图片均计入本地检查；不改变原消息/工具结果。
- 长回复前的用户限制与完整回复都进入实际聊天 mock 请求。
- 焦点 600 字之后的否定/例外完整保存；超存储上限不覆盖原目标。
- 话题目标 500 字之后的对象和回复中间的追问完整到达分类回调。
- 同大小、同 mtime 文件替换后，读取到更正正文。
- 任务正向/负向提示词中间条件可以完整补读，估价/状态保持原值。
- 长读 mock 收到前文用户范围；没有摘要字数目标；容量不足时不调用模型、不推进游标、不转入费用待核对。取消/续读、结构/历史固定游标、原文重建继续通过。

第一次 SourceEvidence 回归因 mock 缺少新增只读模型容量解析方法而失败；补齐 mock 后原断言通过，未放宽游标或取消断言。14 个变更 PHP 文件语法检查通过。

模型传输为 mock/纯本地逻辑；数据库测试事务回滚，临时材料清理。测试禁用 curl_exec、curl_multi_exec、fsockopen、pfsockopen、stream_socket_client 与远程 fopen。未触发新的付费任务。

## 账务及运行状态

测试前后余额摘要、消费记录数量和状态摘要、完整用户/租户账务流水摘要、Agent 数量/状态和队列租约比较一致。历史转写待核对 1 条、预留 40 积分；文本 pending_usage 41 条、settlement_failed 2 条均保持原状。没有补扣、退款或重复提交。

沿用此前授权的安全重载流程，确认无活动任务/租约及可执行阶段任务后优雅停止并启动本地 Supervisor Worker 组；停止期间再次核查财务和队列证据一致。Agent PID 从 358596 更新为 362756，启动后单个健康 idle 进程，ready/running/expired_leases 为 0。历史待核对警告仍保留，不能将 healthy 理解为历史费用问题已解决。

临时核查快照：容器 `/tmp/agent-context-audit-before.json`、`agent-context-audit-after.json`、`agent-context-ledger-before.json`、`agent-context-ledger-after.json`；不纳入源码提交。原有结果轮询可正常更新调度字段，本结论不宣称所有运行时字段完全静止。

## 交接

- task: 复查降低 token 相关改动，修复上下文完整性风险。
- branch: `feature/agent-optimization`
- server scope: 上下文组装、话题线索、模型容量保护、文档缓存、长资料输入及对应测试。
- web scope: 无改动，保持 `62eb965926873a16701a014e0fb1ffcba5cf5842`。
- contract changes: 仅内部模型上下文新增完整性/预览元数据及本地容量异常；无 HTTP API、迁移、权限、供应商协议或计费规则变更。
- completed / remaining: 修复、离线回归、安全重载完成；没有进行付费模型回答效果评分。
- server commits: `376f25b1f`、`26b5899fd`，另附本文档提交。
- risks or blockers: 容量估计不是供应商精确分词；未知模型容量使用保守值。超容量会明确停止或澄清，不能用静默丢弃信息换取继续回答。历史摘记/摘要仍需按来源补读，不能保证真实模型永不遗漏或理解错误。完整上下文可能增加实际 token 用量，费用规则不变。
