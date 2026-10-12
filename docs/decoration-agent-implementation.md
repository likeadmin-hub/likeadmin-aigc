# AI智能装修 Agent 本地实施记录

批准范围：移动页面/选中组件/新页面，6次文本视觉决策、20工具、5张生图；上传asset/layout/style/generation；候选独立、草稿应用与回滚；普通装修和正式发布保持独立。

## 执行记录

- 前后端feature/ai-decoration-agent分别开发，保留已有AGENTS和依赖等未提交内容。
- 先测试：决策解析RED类不存在->GREEN7检查；确定性编排94断言；前端状态6项；媒体22项93断言。
- 新增6张共享tenant_id/admin_id记录表，迁移不修改任何历史装修数据。已有租户、新安装和租户初始化包含幂等CREATE。
- Ruling: SDK每次单决策Workflow，持久化worker管理有上限的多步循环；不依赖原生工具调用，也不改短剧桥接。
- Ruling: 未能估价的实际用量图片SKU不能授权严格预算生图，需选择可估价规格。
- Ruling: 编排专用文本调用禁用隐式fallback/compatibility/transient重试；未知受理和未核实消费暂停，防止费用未知情况下续跑。
- Root review修复租约fencing、预占与槽绑定原子性、取消循环、创建回滚会话、撤销后图片回填和幂等重放。

## 运行

本地明确核对数据库后：
`php -r 'require "vendor/autoload.php"; (new think\App())->initialize(); app\common\service\database\SqlMigrationExecutor::execute(file_get_contents("upgrade/20261013_decoration_agent.sql"), config("database.connections.mysql.prefix"));'`

`.env`中`[DECORATION_AGENT] ENABLED=true`；`php think decoration:agent-worker`。默认关闭，服务不可用不影响普通装修。宝塔守护进程使用`scripts/start-decoration-agent-worker.sh`及正确PHP8.2路径。 worker只处理独立记录，异常数据库连接退出供进程管理器重启。

## 协议与恢复

API `/tenantapi/decorate.agent/{bootstrap,createSession,sessions,session,quote,start,continue,run,events,cancel,candidate,discard,apply,rollback,retryImage,replaceImage}`。所有租户管理员身份服务端取得，新路由显式继承装修读/保存/新增权限，拒绝unknown-route隐式放行。

事务原子应用/检查点；按内容基线合并不重叠字段。回滚只改草稿。新页面恢复原会话页面；迟到图片核对原图片值；退款预算为0，未知费用不继续。

`tests/decoration-agent/run.php`纯编排；`decision.php`决策；`integration.php`真实本地数据库隔离模板测试，不对原页面执行写入；`evaluation-fixtures.json`24智能性场景，真实模型评测不得用单元桩冒充。

发布时包含新资源目录、增量SQL、worker启动脚本；关闭开关即可退回普通装修。推送和线上发布未获本任务授权。
