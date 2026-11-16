# 更新记录容量修复

完整预检记录和更新包清单可能超过 TEXT 的 65,535 字节上限。当前安装 SQL 将 `update_task.preflight_json`、`update_task.result_json`、`update_package.manifest_json` 定义为 MEDIUMTEXT（约 16 MiB）。不截断清单或原始错误。

已有站点由 `upgrade/20261116_update_record_capacity.sql`（及 public 镜像）修复。迁移规划器只接受这三个字段的精确扩容意图，准备阶段通过专用执行器核验实际结构。保留原值、NULL、默认值、字符集、排序规则和注释，已是 MEDIUMTEXT/LONGTEXT 时不执行 DDL；缺表、未知类型、非 InnoDB 或事务内扩容均阻断。

系统下载、预检、写入，以及应用包记录、预检、写入，会在元数据保存与完成事务之前检查容量。普通版本查询、页面展示和日志读取不触发该检查。容量错误单独显示，服务端仍保留完整原始诊断。

## 已卡在旧更新器预检的站点

旧更新器无法执行尚未安装的新迁移。先完整备份站点和数据库，使用本次修复的可信文件，将以下两项按原路径复制到客户 canonical 站点（保留既有文件备份，不替换配置、vendor 或历史迁移）：

- `scripts/migrate-update-record-capacity.php`
- `app/common/service/database/RuntimeUpdateRecordCapacity.php`

用本站 PHP 8.2 执行，先检查输出的数据库目标，再明确应用：

```sh
/www/server/php/82/bin/php scripts/migrate-update-record-capacity.php
/www/server/php/82/bin/php scripts/migrate-update-record-capacity.php --apply
```

默认仅检查。`--apply` 获取本站锁、拒绝未完成更新/旧隔离运行时、备份两张更新元数据表的结构与记录，再仅扩容上述三个字段并验证记录未变。锁规则与 canonical 更新器一致：排除并发更新，允许既有业务 worker 保留读锁；更新器占用时退出，等待其完成后重试。不要修改其他站点或绕过更新授权。完成后回到官方更新流程重新下载/预检。修复工具不会清除失败任务、重置 SQL 日志或自动重放业务迁移。

历史 `app/apps/aigc_image/migrations/install.sql` 的字节仍与签名基线一致，不改写历史迁移。新装系统先创建当前核心更新表，该历史 CREATE IF NOT EXISTS 不会将现有 MEDIUMTEXT 降回 TEXT。

## 本地开发迁移

在本地 develop 合并修复后，通过 Docker 内本站 PHP 执行上述工具；先确认输出目标为本机 `127.0.0.1/z_cn`。该步骤只应用本次容量修复，不能执行客户或共享库迁移。真实数据库回归使用临时 MySQL 容器和独立 fixture 数据库，不读取项目 `.env`。
