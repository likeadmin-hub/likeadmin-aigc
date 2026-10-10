# 本地 PHP 8.2 与 Neuron 集成

2026-10-10 将后端、前端的 `feature/aigc-upgrade` 和 Neuron 替换分别合入原工作区本地 `develop`。没有推送远端或发布系统升级包。

## 项目入口

- `z.cn` / `*.z.cn` 使用 `enable-php-82.conf` 和 `/tmp/php-cgi-82.sock`。实际 HTTP 验证为 PHP 8.2.34，Neuron、Redis、Zip、GD、Fileinfo 均加载成功。
- 结果 worker 保留 `--app=aigc_pic_lipsync --sleep=2`；定时任务通过 `scripts/start-project-cron.sh` 使用绝对 PHP 8.2 路径；短剧 Agent 使用同一版本。
- 原五队列入口及独立 episode/planning 入口默认 PHP 8.2。原先未运行的 episode/planning 队列保持未运行，避免意外领取历史任务。
- 项目独立 Supervisor 位于 `runtime/php82-supervisord.conf`，socket 为 `/tmp/aigc-local-php82-supervisor.sock`。宝塔本项目的 `ai-task` 条目通过 `scripts/start-project-supervisor.sh` 使用同一配置，重启等待旧实例退出，避免重复消费者。
- 只有任务进程通过 `PHPRC` 使用 `runtime/php82-worker.ini`，开放停止所需 pcntl 信号函数。全局 `/usr/bin/php`、PHP 8.0 配置及其他网站配置保持不变。

```sh
/www/server/panel/pyenv/bin/supervisorctl \
  -c /www/wwwroot/likeadmin_aigc_saas/server/runtime/php82-supervisord.conf status
```

配置属于当前本地容器；不能据此声称其他环境已切换。

## 数据和资源

确认本地 `127.0.0.1:3306/z_cn` 后，依次执行 `upgrade_20261009_canvas_pages.sql`、`upgrade_20261009_canvas_production.sql`，仅创建新表。Neuron 替换自身无迁移。历史任务和余额未修改，业务环境文件未改动。

PC 使用同源 `/api` 编译后写入本项目 public。保留旧 hash 资源，防止已打开页面加载旧 chunk 时发生 404。构建产物留在本地，没有作为系统发行包提交或发布。

## 验证

目标 PHP 8.2 的 SDK 测试 10 tests / 46 assertions，数据库副本验收 81 tests / 741 assertions，前端受影响范围 82 tests，均通过。PC 编译通过。数据库验收使用独立 MySQL、Redis 和受控 Provider，没有请求付费模型。

全量测试及历史失败对比，以 `integrations/php82-activation/` 最终报告为准。上述范围通过不代表全量通过，也不代表真实付费模型端到端验收完成。
