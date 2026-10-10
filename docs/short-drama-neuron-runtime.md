# 短剧 Agent 的 Neuron PHP 运行时

本次替换只覆盖原 Deep Agents 的对话决策桥接。原桥接调用一次平台计费 Provider，再通过终止工具提交经过校验的决策；没有启用 Python 文件工具、Shell、子代理或自动摘要。本实现使用真实 `neuron-core/neuron-ai` 4.1.9 SDK 的有限 Workflow：预检构建并校验图，运行一个 Node，返回 StopEvent。

## 请求、业务及历史兼容

- 新接收任务使用 `neuron_v1`。已接收的 `deepagents_v1` 快照由同一 PHP 桥接执行，保留原快照、事件引擎标签及模型回执。租户配置中的旧标签只在接收下一次请求时映射为新标签。
- `legacy`、`decision_v1` 的处理方式保留。历史会话的记忆、暂停状态及工作流设置继续由现有数据库维护。
- 平台 `MarketTextConversationProvider` 仍负责模型路由、多模态输入、内容审查、用量记录及计费。SDK 不创建模型客户端、不增加模型请求，不接管平台业务工具执行。
- Worker 在预检前冻结完整请求和 SSE 回调，随后仍由现有提交授权、事务、租约及 fence 流程处理。Node 原样保留回执字段，仅规范化已校验的决策 JSON。
- 每次运行创建独立 Workflow 和内存状态。单节点最多提交一次；提交前标记已消耗，异常和未知付费结果不得由 SDK 重试。平台原有明确拒绝后的模型候选处理规则保留。
- 删除 `DeepAgentConversationProvider`、`agent_runtime/short_drama` 下全部 Python 文件和 requirements，以及 Python 路径配置。其他模块已有的 Python 测试脚本与本次运行时无关，保留。

## 环境及启用条件

PHP 最低版本为 8.1；本轮实际验证环境为 PHP 8.2.34、MySQL 8。当前工作区 `bt` 容器为 PHP 8.0.26，**不能直接部署本分支**：Composer 的平台检查会阻止应用启动。不能通过忽略平台要求部署。

锁文件只新增 `neuron-core/neuron-ai` 4.1.9 和 `psr/event-dispatcher` 1.0.0；没有升级或删除现有依赖。部署时须将锁文件及匹配的 vendor 一起发布，并在目标 PHP 8.1+ 环境执行：

```sh
composer check-platform-reqs
php -r 'require "vendor/autoload.php"; exit(class_exists("NeuronAI\\Workflow\\Workflow") ? 0 : 1);'
```

本次 Neuron 替换不需要数据库迁移。作为前置集成的短剧 feat 需要依次执行 `upgrade_20261009_canvas_pages.sql`、`upgrade_20261009_canvas_production.sql`；本轮仅在专用测试数据库副本执行，原开发数据库没有修改。发布需依据目标库的实际迁移状态处理这两项前置变更。

回退默认配置只影响未来接收的任务。已接收的 `neuron_v1` 和 `deepagents_v1` 队列须继续使用包含兼容桥接的代码完成，不能在未排空这些任务时删除 Neuron 依赖或切回不识别这些快照的旧代码。

## 验证及剩余限制

全部测试在隔离代码目录及私有 MySQL 副本运行，未升级 `bt`、未修改原业务数据，未调用外部付费模型、图片或视频服务。

前置集成基线为 `074fc6706`，替换代码提交为 `18dfc6535`；最终回归在隔离后端 `develop` 的合并提交 `0b3d534da` 上执行。测试期间使用专用 Redis 容器，未使用现有业务缓存。代码分支 `feature/neuron-agent` 已导入原后端与前端仓库，原工作区仍停留在此前的 `develop`。

- 替换核心及真实数据库 Worker：25 tests / 379 assertions，通过。覆盖三个引擎各八轮对话、历史快照、幂等、SSE、记忆、暂停恢复、卡片锚点、错误作用域、预检失败及未知结果不重试。
- 扩展数据库验收及制作流程回归：81 tests / 741 assertions，通过，无跳过。覆盖创作设置、材料、脚本恢复、脚本提交幂等、文件问答终态、草稿确认重放、制作单元持久化及 Prompt 持久化。测试镜像补齐 Redis 扩展后运行，队列使用测试 Mock。
- 原业务 Unit：16 tests / 83 assertions，通过。
- 同环境全量 Feature + Unit：替换前 1,297 项，替换后 1,305 项；新增失败为零。两边均有 63 errors、20 failures、1 risky，替换后另有 72 skipped。逐项按测试名称和结果比较，原失败完全一致。**全量测试没有全部通过。**
- 受影响的 PC 对话、文档发送、阶段交接及流式显示：82 项通过。全部短剧前端脚本直接运行得到 350 pass / 31 fail，包含缺少 Playwright 的浏览器脚本以及已有断言/测试桩问题；Neuron 未修改这些前端文件。
- PC `nuxt generate` 静态编译通过，生成 131 条路由。未发布生成资源。
- Composer 配置与 PHP 8.2 平台检查通过。依赖审计报告现有五个 Symfony 包的九条 advisories，本次新增两个包不在告警列表；未为了本次替换升级其他依赖。
- 独立代码审查指出并已修复 Worker 回调冻结时序问题，最终复核无新增 P1/P2。

全量 PHPUnit、前端脚本失败详情及同环境比较结果保存在隔离验证目录 `integrations/neuron/`。以上结果证明替换核心与已执行回归成立；不能据此声称全量测试全绿、真实上游模型端到端成功或当前 PHP 8.0 环境可无缝上线。
