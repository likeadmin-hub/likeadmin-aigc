# 画布对话修复与部署验收（2026-10-01）

## 本次确认

- Qwen3.6-Plus 真实对话返回 `upstream_error`，GPT-5.4 返回 `INSUFFICIENT_BALANCE`，DeepSeek-V4-Flash 返回上游 `400`。这些失败均有正式任务和消费记录；实际用户扣费为 0。
- GPT 的余额错误来自上游响应，不能据此推断用户积分不足，也不能推断其他模型失败的具体原因。
- 上游尚未恢复成功回复，因此本次真实成功链路验收仍为 BLOCKED；不能以模拟 Provider 通过替代。
- 对账保留既有 `UPSTREAM_FAILED_REFUNDED` 状态码，只在明确的上游余额错误时增加公开原因 `upstream_balance_insufficient`。不传递原始上游信息，不改账单、不追加请求、不写画布。
- PC 悬浮帮助限定在当前首页，使用实时路由避免缓存页面的 Teleport 残留。

## 部署要求

1. 同步发布本次 server 与 web 源码对应的变更；无需数据库迁移。旧前端忽略新增字段，旧事件仍显示通用失败提示。
2. 前端按现有部署流程构建并更新资源。构建产物不进入源码仓库；本次不创建或发布版本包。
3. PHP Worker 为常驻进程。仅替换 PHP 文件不会刷新已加载的类。通过现有宝塔守护配置平滑重载 AI Worker 组，不新增独立守护进程。
4. 重载前确认队列没有正在提交的对话和进行中的 AI 任务。让在途请求完成，不强杀进程，不把未知提交状态重置为可重试。
5. 确认 `scripts/start-ai-task-worker.sh` 管理的 Worker 均恢复，尤其 `short-drama:canvas-agent-worker`。核对进程启动时间和队列状态。
6. 在已授权测试账号上提交一条短文本，核对最终回复、刷新后的历史、消费状态和积分。上游仍报余额不足时，处理调用通道额度后再验收；不要给用户充值或改账单来掩盖通道故障。

## 已执行的定向回归

- 后端 `tests/agent/p2_conversation.php`、`p2_worker.php`、`p2_recovery.php`、`p2_reconciliation.php`：PASS。覆盖幂等发送、重复 Worker、失败不写画布、历史恢复、未知结果保留、退款对账及原因白名单。
- 前端 `help-float-navigation.test.cjs`、`short-drama-conversation-state.test.cjs`、`short-drama-conversation-reader.test.cjs`、`short-drama-conversation-stream.test.cjs`：35 项 PASS。覆盖页面缓存、历史恢复、事件去重、断流补读、状态不倒退和跨租户旧响应隔离。
- 所有脚本必须从包含最新修复的本地 develop 执行。后端本地夹具要求 `SHORT_DRAMA_AGENT_TEST=local-existing`，沿用既有数据库并回滚/精确清理，不在生产库运行夹具测试。

外部模型、网络和服务额度会变化。验收通过表示已验证的链路和故障保护有效，不表示外部服务永远不会失败。
