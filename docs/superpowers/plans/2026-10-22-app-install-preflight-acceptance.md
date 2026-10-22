# 应用安装预检与安全重试修复验收

## 问题与结论

客户截图：`aigc_music` 1.0.2 解压预检通过，安装时提示“上次 SQL 执行结果未知，禁止自动重放”。截图说明命中了先前失败留下的执行保护，不能据此判断首次失败原因；不能把禁用的 unzip/tar 当作根因，因为 ZipArchive 已可用。

代码确认的问题：父进程在启动应用准备子进程之前便把 SQL 标记为 running；子进程初始化、元数据事务引擎检查、迁移计划解析尚未通过时也会遗留该标记。旧预检未检查这些条件，失败后弹窗仍显示预检通过。原回滚回执丢失首次错误与操作绑定。

## 修复

- 预检实际探测 PHP CLI、必要扩展、Composer 自动加载和所需子进程函数；检查元数据事务引擎、全部迁移文件及既有迁移状态。
- 普通部署的预检复用安装暂存算法检查原签名摘要对应的 SQL 回执，不更换摘要以绕过既有保护。
- 普通部署与旧版运行时部署均先在候选解释器中完成只读检查，再设置 SQL running。执行前检查失败回滚文件并保留 pending，可修复环境后重试。
- 全部迁移计划在任何 DDL 或 running 迁移标记写入前完成解析，避免后续文件静态错误造成前面文件已执行。
- 普通部署的回滚回执保留首次错误及操作绑定；真正执行后的中断继续禁止自动重放。
- 管理端执行失败后持续显示错误并禁用本轮执行按钮，重新预检后才允许再次执行；环境表显示 PHP CLI 探测结果。

后端功能提交：`3a70e88bb`，本地 develop 合并：`2ff6d6cbc`。前端依赖该后端的 `environment.runtime` 字段；功能提交 `6fba02cd`，本地 develop 合并 `cbc8e982`。

## 验证证据

实际环境：PHP 8.2；独立 Docker MySQL 8 数据库；新建容器和专属网络，不发布宿主端口，不连接客户数据库，不重启现有业务进程。

1. `app-preparation-gate.php`：只读检查失败未运行 SQL、文件回滚、pending 保留、原错误保留；解除故障重试仅执行一次 SQL；无效 PHP CLI 清晰拒绝。
2. `app-migration-preflight.php`：不连接数据库即可验证全部文件，拒绝后续不安全文件；历史签名字节只接管、不重放；历史文件改变拒绝。
3. `direct-site.php`：成功/回滚/回复丢失恢复、真正 SQL 不确定时重试不重放、读锁、签名和受保护路径通过。
4. `before-sql-gate.php`、`migration-plan.php`、`app-sql-ownership.php` 均通过。
5. 禁用 proc_open 的 PHP 进程在预检阶段返回缺失函数名称，通过。
6. 仓库 32 个应用的全部迁移文件按当前历史基线验证通过，包含 aigc_music。
7. `python3 tests/runtime-updater/system-service.integration.py --updater --canonical --app-packages --cases success` 退出 0。真实 AppPackage API 完成 A 安装、B 安装及更新、A 卸载后同包重装、预检拒绝 MyISAM、预检后引擎故障在候选检查中拒绝、恢复环境后同包重试、最终元数据失败回滚重试。迁移计数 A=1/B=1；受保护业务状态不变。
8. 不加 `--canonical` 的旧部署路径同样退出 0，完成同一组真实安装测试。
9. 平台 Vite 生产构建成功，输出仅到项目 runtime；存在既有依赖/分块体积警告。未进行客户站点浏览器验收。

日志：项目根目录 `runtime/app-install-real-canonical.log`、`runtime/app-install-real-legacy.log`、`runtime/app-install-platform-build.log`。测试容器/数据库/网络已清理。源代码已合入本地 develop；没有推送、发布或部署到客户站点。

## 客户站点处理步骤

1. 先备份站点文件、数据库、更新回执与失败日志，保留当前应用包。部署包含此次修复的前后端系统版本后再重新预检。
2. 查看**首次**失败信息，不以随后“禁止自动重放”提示替代首错。没有日志界面时，可由站点管理员在自己的数据库执行下列只读查询；按真实表前缀替换 `la_`：

```sql
SELECT id, package_id, app_code, version, status, error, create_time
FROM la_update_task
WHERE type = 'app' AND action = 'apply' AND app_code = 'aigc_music'
ORDER BY id ASC;

SELECT migration_key, status, error, update_time
FROM la_app_migration
WHERE scope = 'platform' AND tenant_id = 0 AND app_code = 'aigc_music'
ORDER BY id;
```

同时保留站点 `runtime/update_receipts` 的对应回执；旧运行时部署还需保留 `.composer-runtime` 中的日志。反馈时不提供 .env、数据库密码或支付密钥。

3. 新预检如明确提示 PHP CLI/扩展/子进程函数、文件权限或事务引擎问题，按具体错误修复。仅缺 unzip/tar 且 ZipArchive 可用时，无需开放 exec、shell_exec 等所有禁用函数。数据库引擎或结构问题需单独备份核对后修复，不盲目批量改表。
4. **新版本执行前检查失败、SQL 仍为 pending**：修复明确错误后重新预检并重试，已覆盖真实测试。
5. **既有记录仍为 running/执行结果未知**：此次修复不会自动清除该保护。需将首错、迁移记录、实际表结构和相关业务数据一起核对，确认哪些步骤执行过，再制定针对性的恢复方案。不得删除回执、直接把 running 改 success，或整包重跑 SQL。仅凭当前截图尚不能判定该客户记录可安全解锁。
