# 短剧画布 Agent 本地验收

本文的 `p0` 至 `p6` 脚本用于复用现有本地 Baota 服务、应用当前配置的本地数据库和现有 Worker。执行这类现有服务验收时，不额外创建业务 Worker 或独立守护，不向业务进程注入崩溃故障。

PHPUnit 自动化测试可使用独立 MySQL、Redis、测试镜像与临时测试网络，优先通过数据库副本隔离业务数据。测试代码目录、连接、缓存和生命周期必须与当前业务分开，结束后只清理本次创建的资源。隔离测试不能代替现有服务或真实 Provider 的验收。

## 前置条件

- 当前本地 Baota 容器、MySQL 和所需 Worker 均为运行态；容器名以实际配置为准，当前工作区为 `bt`。
- 网站、验收 CLI 和对应 Worker 使用 PHP 8.2。短剧对话执行使用 `neuron-core/neuron-ai` PHP SDK，不安装 Deep Agents 或 Python requirements；在共享宝塔环境中不要直接调用可能仍指向 PHP 8.0 的全局 `php`。
- 应用已有的短剧画布迁移必须先由正常本地升级流程应用；测试不会自动执行迁移，也不会补建表。
- 并发与崩溃测试需要测试专用的 `la_aigc_short_drama_test_provider_receipt` 表。若当前本地库缺少此表，先核对目标库，再手动执行 `fixtures/provider_receipt.sql`；不要在生产库执行。
- 所有会写入夹具的脚本必须显式传入 `SHORT_DRAMA_AGENT_TEST=local-existing`；未传入时会在任何写入前退出。
- 脚本只使用固定的本地验收夹具范围并在结束时回滚或按精确 ID 清理。若发现夹具范围已被占用，必须停止，不得清空业务表或覆盖用户画布。
- 默认不调用真实 Provider。涉及付费模型、真实素材或取消/退款的验收，必须遵循当次用户授权的积分上限与材料范围。

## 运行后端验收

从 `server` 本地 `develop`（已合入对应 feature 且工作区干净）执行。以下命令只进入当前 Baota 容器，不会创建新容器：

```sh
docker exec -e SHORT_DRAMA_AGENT_TEST=local-existing -w /www/wwwroot/likeadmin_aigc_saas/server bt \
  /www/server/php/82/bin/php app/apps/aigc_short_drama/tests/agent/p0_baseline.php
```

按阶段串行运行其他脚本，任一失败立即停止；不要并行运行共享夹具：

```sh
for test_file in p0_generation p0_controller p0_http p1_graph p1_graph_wire p1_graph_operations p1_concurrency p1_poster_save p1_save_cas p1_read_recovery p1_revision_integration p1_migrations p1_generation_intent p1_generation_projection p1_manual_authority p1_generation_crash p2_migrations p2_conversation p2_conversation_concurrency p2_execution p2_settings p2_send p2_http p2_worker p2_queue_crash p2_stop p2_stop_race p2_recovery p2_attachments p2_safety p2_reconciliation p3_asset_version_reference p3_connection_matrix p3_preflight_rejection p3_private_signed_url p3_quote p3_quote_confirmation p3_reference_assets p4_agent_auto_recovery p4_agent_dependency_scheduler p4_agent_generation_mode p5_agent_node_layout p5_compact_canvas_projection p5_multi_skill_workflow p5_shared_submission_templates p6_local_compatibility; do
  docker exec -e SHORT_DRAMA_AGENT_TEST=local-existing -w /www/wwwroot/likeadmin_aigc_saas/server bt \
    /www/server/php/82/bin/php "app/apps/aigc_short_drama/tests/agent/${test_file}.php" || exit 1
done
```

执行前后均需记录 `docker ps`、Worker 状态与精确夹具范围；不允许为测试重启或故障注入业务 Worker。

## 浏览器验收

PC 浏览器脚本通过 `docker exec` 启动受限本地 HTTP 桥，并使用本机的 PC 开发服务。现有脚本仍写死旧容器 `baota`、旧目录 `/www/wwwroot/likeadmin-aigc/server` 和 PHP 8.0 路径，不能直接用于当前工作区验收；执行前必须先适配为实际容器 `bt`、目录 `/www/wwwroot/likeadmin_aigc_saas/server` 和 `/www/server/php/82/bin/php`。未适配时记录为 BLOCKED，不得算作 PHP 8.2 浏览器验收通过。

适配完成后，从前端 `web` 仓库执行，使用本机已安装 Playwright 的 Node.js 环境；脚本不会创建镜像、网络或数据库：

```sh
CANVAS_TEST_BROWSER_CHANNEL=chrome node pc/tests/short-drama-agent-http-browser.cjs
```

需要覆盖四类节点的模拟回归时，仅允许在当前本地运行态显式设置 `CANVAS_TEST_MOCK_GENERATION=1`；该开关只替换测试路径的最下游 Provider，不代表真实 Provider 验收。

## 清理与记录

- 验收结束检查夹具是否按脚本预期回滚或精确清理。
- 不运行 Docker 全局清理，不删除 Baota、业务卷、现有 Worker 或用户画布。
- 结果必须如实记录为 PASS、FAIL、BLOCKED 或 NOT_RUN；本地模拟通过不能替代真实 Provider、计费、审核或上游取消协议的证据。
