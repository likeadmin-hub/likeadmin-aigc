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
for test_file in p0_baseline p0_generation p0_controller p0_http p1_graph p1_graph_wire p1_graph_operations p1_concurrency p1_poster_save p1_save_cas p1_read_recovery p1_revision_integration; do
  docker run --rm --network short-drama-agent-test -v /Users/panda/Documents/docker-dir/bt/wwwroot/likeadmin-aigc/server:/app:ro -v /dev/null:/app/.env:ro --tmpfs /app/runtime short-drama-agent-test-php:local app/apps/aigc_short_drama/tests/agent/${test_file}.php || exit
done
```

当前分别 10/16/15/6/19/18/11/10/4/5/10/12 PASS，共 136 断言，exit 0。`p0_http.php` 在容器内部 127.0.0.1:19080 启动真实 HTTP 内核；合成租户/用户为 94001/95001，finally 删除本次确切 fixture。HTTP allowlist 禁止所有生成路由，不暴露宿主机端口。并发脚本同样按确切 fixture 清理，其余主要脚本事务回滚。不要并行执行这些共享 ID 的脚本。

版本集成测试只依赖隔离库已存在的草案 schema；代码不会自动迁移业务库。未迁移 schema 保留内容 token 路径；已版本化文档拒绝缺少 revision 的旧保存。Graph patch 暂未注册 HTTP API。测试末尾 NOT_RUN 是各脚本自己的未覆盖边界，整个阶段以审计报告为准。

前端在 web 本地 develop 执行（已有 localhost:3000 PC 开发服务）：

```sh
CANVAS_TEST_BROWSER_CHANNEL=chrome NODE_PATH=/Users/panda/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules /Users/panda/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node pc/tests/short-drama-canvas-conflict-browser.cjs
```

8 PASS，使用独立临时 headless Chrome context，不访问用户 profile；浏览器 API 全部合成拦截，验证 UI 行为而非真实后端生成。无外部请求、无付费调用。完整浏览器到真实数据库的四节点生成、Provider Adapter、文件转存及故障恢复尚未通过验收。

## 保留与清理

当前保留测试容器/网络用于后续阶段，没有碰业务库或原 Worker。若后续清理，应先核对上述精确容器和网络名称，另行确认是否保留测试证据；不要运行 Docker 全局 prune，也不要删除业务卷。
