# 按实际内容判断任务进程停机

AI 装修默认可用，历史启用环境变量不再参与判定。统一 `start-ai-task-worker.sh` 管理六类队列；不另建装修守护项。缺少装修数据表时装修 worker 等待，每 30 秒复查，真实连接故障继续退出，不重连重放任务。

更新服务使用已核验更新包与当前选中 generation 的 SHA256 比较。允许静态前端、后台 HTTP 控制器、版本号变化和完全相同的重复文件直接更新；任务代码、共享逻辑、任务可调用的公共 API、真实依赖变化、启动脚本、迁移及任务文件删除要求停止本站任务/cron。未知内容保守判定。返回 `process_impact.requires_stop/reasons`，应用前重新核验，浏览器不能授予绕过权限。

无任务影响的更新绑定由服务端写入安装日志，只发布文件及应用静态桥接资源，不顺带执行历史待迁移 SQL、套餐补字段或全租户默认配置同步。资源来自候选 generation，仍处于原文件/HTML shell 备份回滚边界。首次安装运行时隔离机制保留强制停机。

SQL 前检查位于日志标为 running 之前。拒绝启动 SQL 后仍可停止进程再重试；真实执行中断继续保留未知状态、禁止自动重放。共享文件复制和版本激活前复核停机要求，原签名、授权、重新解压、并发锁与事务恢复机制保持。

## 验证

- `tests/decoration-agent/default-availability.php`：历史 false 开关不遮蔽实际前提检查。
- `worker-readiness.php`：缺表/部分表等待，已安装可用，断线退出；调用真实 worker execute。
- `unified-worker.py`：隔离目录执行真实 sh，六队列启动、停止、重启、子进程退出清理及其他项目隔离。
- `tests/runtime-updater/worker-impact.php`：活跃装修进程下实际复制前端文件；任务变更阻止；选中 generation、删除、SQL、依赖、版本字段、公共 API 和资源副作用判定。
- `before-sql-gate.php`：真实安装器第一次拒绝且 SQL 零次执行，随后安全重试一次。
- `frontend-no-ddl.php`、`frontend-assets-only.php`：无需停机路径跳过结构准备，真实资源安装器部署文件及 shell。
- 原不可变运行时集成、失败矩阵、进程门禁、入口顺序与 worker 退役测试通过；装修确定性 113 断言、决策 7 检查、契约 46 项通过。
- PHP Unit 相关媒体/效果回归 27 测试、267 断言通过；官方 staging 8 测试通过；平台端 2 测试、Prettier、静态 UI 审计通过，官方 build 成功。
- 平台全量 type-check 仍受既有 TS 配置 `importsNotUsedAsValues` / `preserveValueImports` 已移除错误阻挡；未扩大修改范围。

测试仅使用隔离任务/目录或只读检查，不调用收费模型，不执行客户升级或变更正式运行时指针。需要发布并安装包含此修复的新版本后，客户更新页面才能采用新判定；本文件不代表该版本已发布或已在客户站点生效。
