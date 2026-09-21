# 隔离的短剧画布 Agent 测试

只在仓库本地 `develop` 已合并对应 feature 提交且工作区干净时执行。此目录不是生产迁移或部署入口。

## 安全边界

- 使用独立 Docker internal 网络，无外网访问；数据库不发布端口。
- PHP 源码只读挂载，真实 `.env` 用 `/dev/null` 遮盖，runtime 使用 tmpfs。
- bootstrap 只接受镜像中的 `SHORT_DRAMA_AGENT_TEST=isolated-mysql`，强制连接 `mysql-test/short_drama_agent_test` 并核验数据库名。不得将 mysql-test 指向业务服务。
- 模拟生成替换下游服务边界，不替换 CanvasService 或 PointService。没有真实供应商密钥，不能由此证明真实供应商协议正确。
- T1/T2=91001/91002，U1/U2/U3=92001/92002/92003，均为合成测试数据。当前三个脚本用事务回滚；串行运行，勿与其他测试共享这些 ID 并行写入。

## 首次建立（已执行，不要重复创建）

从 server 仓库根目录，在符合分支规则的本地 develop 上：

```sh
docker network create --internal short-drama-agent-test
docker run -d --name short-drama-agent-test-db --network short-drama-agent-test --network-alias mysql-test -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=short_drama_agent_test mysql:8.0
docker build -f app/apps/aigc_short_drama/tests/agent/Dockerfile -t short-drama-agent-test-php:local .
docker exec short-drama-agent-test-db mysqladmin ping
```

镜像构建可能需联网下载依赖；实际测试容器仅加入 internal 网络。等待 MySQL 就绪后才导入结构。下列导出程序在原容器**只读表结构**，不导出数据行、视图或触发器，也不输出密码。管道必须启用 pipefail，源命令失败不能误报成功。

```sh
set -o pipefail
docker exec -w /www/wwwroot/likeadmin-aigc/server baota php app/apps/aigc_short_drama/tests/agent/export_schema.php | docker exec -i short-drama-agent-test-db mysql short_drama_agent_test
```

本次原库缺少音乐表，测试库单独补齐源代码已有的 schema。这不意味着现行业务环境已有音频能力：

```sh
docker exec -i short-drama-agent-test-db mysql short_drama_agent_test < app/apps/aigc_music/migrations/install.sql
docker exec -i short-drama-agent-test-db mysql short_drama_agent_test < app/apps/aigc_short_drama/tests/agent/p1_schema.sql
```

`p1_schema.sql` 是隔离测试草案，**非幂等，不能重复执行，也不能用于业务库**。安装/升级迁移及生命周期一致性尚未完成。

## 重复运行

下面三条使用当前机器已核实的源目录；若机器变化，先核实路径，不能移除 `.env` 遮盖或 internal 网络约束：

```sh
docker run --rm --network short-drama-agent-test -v /Users/panda/Documents/docker-dir/bt/wwwroot/likeadmin-aigc/server:/app:ro -v /dev/null:/app/.env:ro --tmpfs /app/runtime short-drama-agent-test-php:local app/apps/aigc_short_drama/tests/agent/p0_baseline.php
docker run --rm --network short-drama-agent-test -v /Users/panda/Documents/docker-dir/bt/wwwroot/likeadmin-aigc/server:/app:ro -v /dev/null:/app/.env:ro --tmpfs /app/runtime short-drama-agent-test-php:local app/apps/aigc_short_drama/tests/agent/p0_generation.php
docker run --rm --network short-drama-agent-test -v /Users/panda/Documents/docker-dir/bt/wwwroot/likeadmin-aigc/server:/app:ro -v /dev/null:/app/.env:ro --tmpfs /app/runtime short-drama-agent-test-php:local app/apps/aigc_short_drama/tests/agent/p1_graph.php
```

原始结果分别是 7 / 13 / 19 个 PASS；容量保护修复后基线脚本为 10 PASS，保留过期整图覆盖 KNOWN_GAP。201 节点现在明确拒绝、原文档不变，200 节点边界可保存。GraphService 的并发保护仍未接入现有页面，不能宣告旧整图覆盖已修复。

新增中间件/控制器与独立进程并发测试（相同隔离环境，串行运行脚本）：

```sh
docker run --rm --network short-drama-agent-test -v /Users/panda/Documents/docker-dir/bt/wwwroot/likeadmin-aigc/server:/app:ro -v /dev/null:/app/.env:ro --tmpfs /app/runtime short-drama-agent-test-php:local app/apps/aigc_short_drama/tests/agent/p0_controller.php
docker run --rm --network short-drama-agent-test -v /Users/panda/Documents/docker-dir/bt/wwwroot/likeadmin-aigc/server:/app:ro -v /dev/null:/app/.env:ro --tmpfs /app/runtime short-drama-agent-test-php:local app/apps/aigc_short_drama/tests/agent/p1_concurrency.php
```

分别 15 / 7 PASS。控制器测试使用数据库会话令牌，真实 LoginMiddleware/AppAccessMiddleware 和 CanvasController，不替代 Web 路由、租户解析或浏览器测试。它固定旧 Request 的数字转字符串契约，内容严格比较，不采用宽松相等掩盖数据变化。

并发测试同时启动 10 个独立 PHP 进程/连接，经就绪屏障一起提交同 key；另用两个不同 key 竞争相同版本。该测试不能放在一个总事务内，finally 只删除本次创建的确切 canvas ID 和对应租户/用户回执，不影响其他测试画布；测试 fixture 行为会推进自增序号。若进程异常终止，先核对 fixture title/ownership，再单独清理，不要清空整库。连续 5 轮复测退出 0。

以上为初始切片的历史运行结果；最新结果及剩余边界见下节和 `../../docs/canvas-agent-p0-audit.md` 第 9 节。未进行真实付费测试。

## 当前完整回归

在 server 本地 develop（最新 feature 已合入）执行，任一脚本失败即停止：

```sh
for test_file in p0_baseline p0_generation p0_controller p0_http p1_graph p1_graph_wire p1_graph_operations p1_concurrency p1_poster_save p1_save_cas p1_read_recovery p1_revision_integration p1_migrations p1_generation_intent p1_generation_projection p1_manual_authority p1_generation_crash; do
  docker run --rm --network short-drama-agent-test -v /Users/panda/Documents/docker-dir/bt/wwwroot/likeadmin-aigc/server:/app:ro -v /dev/null:/app/.env:ro --tmpfs /app/runtime short-drama-agent-test-php:local app/apps/aigc_short_drama/tests/agent/${test_file}.php || exit
done
docker run --rm --network short-drama-agent-test -v /Users/panda/Documents/docker-dir/bt/wwwroot/likeadmin-aigc/server:/app:ro -v /dev/null:/app/.env:ro --tmpfs /app/runtime short-drama-agent-test-php:local app/apps/aigc_short_drama/tests/agent/p0_generation.php idempotent
```

当前分别 10/16/15/6/19/18/11/10/4/5/10/12 PASS，共 136 断言，exit 0。`p0_http.php` 在容器内部 127.0.0.1:19080 启动真实 HTTP 内核；合成租户/用户为 94001/95001，finally 删除本次确切 fixture。HTTP allowlist 禁止所有生成路由，不暴露宿主机端口。并发脚本同样按确切 fixture 清理，其余主要脚本事务回滚。不要并行执行这些共享 ID 的脚本。

版本集成测试只依赖隔离库已存在的草案 schema；代码不会自动迁移业务库。未迁移 schema 保留内容 token 路径；已版本化文档拒绝缺少 revision 的旧保存。Graph patch 暂未注册 HTTP API。测试末尾 NOT_RUN 是各脚本自己的未覆盖边界，整个阶段以审计报告为准。

前端在 web 本地 develop 执行（已有 localhost:3000 PC 开发服务）：

```sh
CANVAS_TEST_BROWSER_CHANNEL=chrome NODE_PATH=/Users/panda/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules /Users/panda/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node pc/tests/short-drama-canvas-conflict-browser.cjs
```

8 PASS，使用独立临时 headless Chrome context，不访问用户 profile；浏览器 API 全部合成拦截，验证 UI 行为而非真实后端生成。无外部请求、无付费调用。完整浏览器到真实数据库的四节点生成、Provider Adapter、文件转存及故障恢复尚未通过验收。

## 最新前置条件与结果（2026-09-21）

上文 136 断言为历史结果；完整串行命令现为 253 PASS、exit 0，分项见审计报告第 10 节。运行前隔离库需具有真实图版本升级 SQL 和 `tests/agent/p1_generation_schema.sql`（本环境均已执行）。后者仅为隔离草案，含模拟 Provider 接收计数表，绝不可用作业务迁移。真实图升级源码为 `migrations/upgrade_20260921_canvas_graph_revision.sql`，测试只在指定隔离库或明确的临时前缀表执行。没有业务库自动迁移。

真实浏览器持久化/双标签页测试：两仓库均在已合入的 develop，且没有其他数据库夹具并发执行时，从 web 根目录运行：

```sh
CANVAS_TEST_BROWSER_CHANNEL=chrome NODE_PATH=/Users/panda/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules /Users/panda/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node pc/tests/short-drama-canvas-http-browser.cjs
```

7 PASS。`browser_http_bridge.php` 使用专属 94011/95011 合成身份和新建画布，仅 current/save 转发真实隔离 HTTP；其余目录/账户读取是合成夹具，未知写请求和生成被阻止。finally 删除此次精确 fixture，不访问用户 canvas 11，不复制私有素材或密钥。此结果不等同于四类生成的浏览器验收。

## 保留与清理

最新结果以审计报告第 11 节为准：上述后端命令 318 PASS。真实 HTTP 浏览器默认模式 8 项（新增显式 Agent 关闭断言）；带 `CANVAS_TEST_MOCK_GENERATION=1` 的模式已实测 14 PASS，覆盖手工连线、四类模拟生成和真实积分账本。该变量只选择测试路由 `browser_generation_router.php`；Mock 类替换最下游服务，internal 网络、只读源码、遮盖 .env、不发布端口等限制不变。它不会启用真实 Provider。普通模式仍禁止生成。

`p1_generation_crash.php` 仅对自己创建的独立测试子进程发送 SIGKILL，在独立数据库验证已提交数据；finally 只清除此次确切 canvas scope。不要对业务 Worker 运行故障注入。生成意图 SQL 已有正式 source-only 安装/增量/系统升级定义；测试计数表仍只在测试 schema 中，不能带入业务库。全部迁移行为仍限隔离库。

当前保留测试容器/网络用于后续阶段，没有碰业务库或原 Worker。若后续清理，应先核对上述精确容器和网络名称，另行确认是否保留测试证据；不要运行 Docker 全局 prune，也不要删除业务卷。

## P2 内部持久化与执行边界

最新范围见审计第 12 节，P2 尚未放行。四个新增脚本按同一 Docker 命令串行执行：`p2_migrations.php`（34 PASS）、`p2_conversation.php`（57 PASS）、`p2_conversation_concurrency.php`（23 PASS）、`p2_execution.php`（67 PASS）。后面三项需先将 `migrations/upgrade_20260921_canvas_agent_conversations.sql` 仅应用到 `short_drama_agent_test`；不得默认迁移业务库。migration 脚本使用检查为空的 `ag2_*` 精确临时表，结束删除本次创建的表。

conversation/execution 在事务中回滚夹具；concurrency 用真实独立 PHP 进程/连接，结束按本次创建 canvas_id 与合成身份删除精确记录，测试 config 预检查不存在才创建并清理。不要并发执行这些脚本或与浏览器共享数据库夹具同时运行。新增测试不调用 Provider，无真实积分或媒体消费，不代表 HTTP/前端/真实模型已通过。未知执行保持 needs_reconciliation，不在测试外擅自清理或自动重试。

继续执行（第 13 节）：新增 `p2_settings.php` 24、`p2_send.php` 26、`p2_http.php` 21、`p2_worker.php` 44 PASS，追加到既有串行列表，总计 614 PASS。仍使用上述 Docker 隔离命令，从已合入的本地 develop 执行。`p2_http` 在容器内部监听 127.0.0.1:19082，专用 router 仅接受五个会话路由，无生成入口、无发布宿主机端口；生成回复由内部隔离服务夹具模拟，绝不调用真实 Provider。`p2_worker` 不包外层事务，以检查 Provider 调用时确无未提交事务；结束清理本次 canvas/config 精确记录。模型解析的产品/SKU/Skill 合成数据均事务回滚。不应并发使用相同 fixture 身份。

Provider interface 只有隔离测试替身，未注册生产适配或扫描任务。真实计费、安全审核及前端集成未验证，P2 仍未放行。额外可用相同只读源码 Docker 命令执行 `app/apps/aigc_short_drama/tests/canvas_composer_skill.php`，验证共享 Skill 应用的原有参数与必填项行为。

最新第 14 节：`p2_execution` 71、`p2_worker` 70，新增 `p2_queue_crash.php` 31；完整串行共 675 PASS。新脚本沿用相同 Docker 命令和 develop 执行规则，预检查测试 config/outbox 为空，精确终止自己创建的五个 PHP 子进程，最终清理本次 canvas scope 和测试 config。它使用既有隔离 Provider receipt 测试表，绝不针对业务进程执行。不要并发运行共享 91001/92001 fixture 的其他脚本。队列扫描服务尚未注册生产 supervisor，也未连接真实 Provider。

第 15 节新增 `p2_stop.php` 76、`p2_stop_race.php` 52 PASS；`p2_http.php` 现为 25 PASS，测试 router 只增加 stop，共六个会话路由。完整串行曾通过 795 个断言，之后 stop_race 新增 12 个回放竞争断言并单独重跑 52 PASS；各套件最新结果合计 807，扩展后未再次跑全部串行。停止测试沿用上述只读 Docker/隔离数据库/develop 命令，stop 使用事务回滚，stop_race 使用真实独立连接和精确 fixture 清理，不能与其他共享身份脚本并行。独立竞争发现并修复 RR 旧快照事件序号冲突，证据见审计第 15 节。未知上游结果不会被当作已取消或退款，真实 Provider 取消和前端停止仍未验证。
