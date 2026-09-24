# 短剧画布 Agent 本地验收

所有验收均复用现有本地 Baota 服务、应用当前配置的本地数据库和现有 Worker。不得创建 Docker 测试网络、测试数据库、测试镜像、额外容器或独立进程守护。

## 前置条件

- `baota` 容器、MySQL、PHP 和既有 `short-drama-canvas-agent` Worker 均为运行态。
- 应用已有的短剧画布迁移必须先由正常本地升级流程应用；测试不会自动执行迁移，也不会补建表。
- 并发与崩溃测试需要测试专用的 `la_aigc_short_drama_test_provider_receipt` 表。若当前本地库缺少此表，先核对目标库，再手动执行 `fixtures/provider_receipt.sql`；不要在生产库执行。
- 所有会写入夹具的脚本必须显式传入 `SHORT_DRAMA_AGENT_TEST=local-existing`；未传入时会在任何写入前退出。
- 脚本只使用固定的本地验收夹具范围并在结束时回滚或按精确 ID 清理。若发现夹具范围已被占用，必须停止，不得清空业务表或覆盖用户画布。
- 默认不调用真实 Provider。涉及付费模型、真实素材或取消/退款的验收，必须遵循当次用户授权的积分上限与材料范围。

## 运行后端验收

从 `server` 本地 `develop`（已合入对应 feature 且工作区干净）执行。以下命令只进入当前 Baota 容器，不会创建新容器：

```sh
docker exec -e SHORT_DRAMA_AGENT_TEST=local-existing -w /www/wwwroot/likeadmin_aigc_saas/server bt \
  php app/apps/aigc_short_drama/tests/agent/p0_baseline.php
```

按阶段串行运行其他脚本，任一失败立即停止；不要并行运行共享夹具：

```sh
for test_file in p0_generation p0_controller p0_http p1_graph p1_graph_wire p1_graph_operations p1_concurrency p1_poster_save p1_save_cas p1_read_recovery p1_revision_integration p1_migrations p1_generation_intent p1_generation_projection p1_manual_authority p1_generation_crash p2_migrations p2_conversation p2_conversation_concurrency p2_execution p2_settings p2_send p2_http p2_worker p2_queue_crash p2_stop p2_stop_race p2_recovery p2_attachments p2_safety p2_reconciliation p3_asset_version_reference p3_connection_matrix p3_preflight_rejection p3_private_signed_url p3_quote p3_quote_confirmation p3_reference_assets p6_local_compatibility; do
  docker exec -e SHORT_DRAMA_AGENT_TEST=local-existing -w /www/wwwroot/likeadmin_aigc_saas/server bt \
    php "app/apps/aigc_short_drama/tests/agent/${test_file}.php" || exit 1
done
```

执行前后均需记录 `docker ps`、Worker 状态与精确夹具范围；不允许为测试重启或故障注入业务 Worker。

## 浏览器验收

三份 PC 浏览器脚本会通过 `docker exec baota` 启动受限本地 HTTP 桥，并使用本机的 PC 开发服务。它们不会创建镜像、网络或数据库：

```sh
CANVAS_TEST_BROWSER_CHANNEL=chrome NODE_PATH=/Users/panda/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules \
  /Users/panda/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node \
  pc/tests/short-drama-agent-http-browser.cjs
```

需要覆盖四类节点的模拟回归时，仅允许在当前本地运行态显式设置 `CANVAS_TEST_MOCK_GENERATION=1`；该开关只替换测试路径的最下游 Provider，不代表真实 Provider 验收。

## 清理与记录

- 验收结束检查夹具是否按脚本预期回滚或精确清理。
- 不运行 Docker 全局清理，不删除 Baota、业务卷、现有 Worker 或用户画布。
- 结果必须如实记录为 PASS、FAIL、BLOCKED 或 NOT_RUN；本地模拟通过不能替代真实 Provider、计费、审核或上游取消协议的证据。
