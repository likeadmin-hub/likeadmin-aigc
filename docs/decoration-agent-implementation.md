# AI智能装修 Agent 本地实施记录

批准范围：移动页面/选中组件/新页面，6次文本视觉决策、20工具、5张生图；上传asset/layout/style/generation；候选独立、草稿应用与回滚；普通装修和正式发布保持独立。

## 执行记录

- 前后端feature/ai-decoration-agent分别开发，保留已有AGENTS和依赖等未提交内容。
- 先测试：决策解析RED类不存在->GREEN7检查；确定性编排113断言；前端状态8项；媒体22项99断言。
- 新增6张共享tenant_id/admin_id记录表，迁移不修改任何历史装修数据。已有租户升级和新安装包含幂等CREATE；新租户使用共享表，不在租户初始化中重复DDL，避免隐式提交改变原初始化事务。
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

`tests/decoration-agent/run.php`纯编排；`decision.php`决策；`integration.php`真实本地数据库隔离模板测试，不对原页面执行写入；`evaluation-fixtures.json`25智能性场景，真实模型评测不得用单元桩冒充。

发布时包含新资源目录、增量SQL、worker启动脚本；关闭开关即可退回普通装修。推送和线上发布未获本任务授权。

- Ruling: 共享Agent记录覆盖所有新租户，撤回tenantData重复CREATE；不改变原租户初始化。
- 实际模型路由验证：2、175、176被上游拒绝，175/176返回INSUFFICIENT_BALANCE，3次实际消费均为0。未通过20真实场景/90%验收，不以程序测试替代。


## 最后集成修订

候选接口返回自身绑定的resolved_sources，应用/回滚返回刷新后的来源数据，解析结果不写回装修JSON。历史附件恢复为数组，显式引用时保留原用途；图片理解发送经租户鉴权重验后的临时data URI，兼容本地和受保护素材地址，日志及会话不保存base64。应用目录复用当前租户可用uniapp入口及其真实meta.pages。每步和每次生图前重新校验管理员可用性和装修权限。

前后端共享协议类型位于web/packages/decoration-core/agent-types.ts，后端结构化决策、契约和操作校验在app/common/service/decorate/agent/。契约mobile-agent-v1的SHA256由导出脚本生成；浏览器编译值与服务端不一致仅阻止AI，普通编辑仍可用。六张共享表以tenant_id/admin_id隔离，新安装和既有安装升级均包含CREATE IF NOT EXISTS，新租户无需独立建表。

预算为追加调用授权：启动前估算，下次调用前核验累计实际消费与本次预计追加；当前调用实际消费可能高于估价，不能把估价称为硬封顶。成功消费不会因停止/放弃/回滚退款。不支持定价估算的实际用量生图规格阻止自动提交，不改变普通用户消费路径。

## 真实模型评测状态

已固定本地租户1，实际调用Qwen3.6-Plus(id2)、GPT5.6 Luna(id175)、Terra(id176)。任务3888/3889/3890，消费记录3968/3969/3970：均failed/refunded，实际消费0；175、176明确返回INSUFFICIENT_BALANCE。尚无成功智能性案例（0/20），不能宣称达到90%。未执行真实收费生图及真实截图理解验收。程序、数据库和浏览器验收证明工程行为，不替代模型效果。

恢复评测使用独立未启用模板，固定模型，不自动换模型：
`DECORATION_EVAL_PAID=1 DECORATION_EVAL_TEMPLATE=<isolated-template-id> php tests/decoration-agent/real-evaluation.php <case-count> <start-index> <model-id>`。
默认仅跑1例，累计预算警戒200积分；人工预览及生图供应商结果仍需另行验收。该脚本只生成候选，遇图片请求先取消自动后续，避免批量验收意外启动收费图片任务。费用授权环境变量是测试保护，不用于生产接口。


## 本地源码运行与版本运行区别

当前站点已启用immutable Composer generation，常规`php think`读取该已安装版本而非工作区新代码，因此开发验收用本地develop源码入口：
`php -r 'require "vendor/autoload.php"; (new think\App())->console->run();' -- decoration:agent-worker --once --tenant=1`。
已验证输出processed=0；没有修改站点generation指针。正式交付包安装后使用常规worker脚本；发布前必须核验实际加载类路径及命令，不把本地源码通过当作已上线。

最新验证：确定性113断言、决策7检查、媒体22测试99断言、前端状态8测试、数据库28断言（隔离测试模板，原页面未变）；租户/H5/mp-weixin构建通过，小程序输出264模板/101主题页宿主/7组件检查通过。前端全量151测试150通过，唯一失败为已在origin/develop复现的app-assets-dev-proxy静态资源404；未扩大本任务修改范围。类型检查受到原项目旧vue-tsc/TS配置及依赖问题限制，改动SFC提取脚本无新增错误。

检查点恢复仅接受apply/create/image，undo_create和rollback为审计记录；新建页撤销后的重做使用candidate.apply重新建页，禁止反向合并undo_create覆盖原页面。

旧页面无ID组件在显式增删/移动/复制时只在候选中补齐稳定标识；选中范围仅处理对应子树。已验证移动后下一操作仍定位同一组件，普通字段调整不补写ID，原草稿不做批量迁移。

冲突返回具体组件中文名称及修改类别，并在Agent失败响应data.message保留提示，兼容现有请求封装reject(data)，避免仅在短暂toast中显示失败原因。旧候选遇到后续保存/发布内容变化时应重新生成，不绕过冲突保护。

成功图片的重新生成使用retryImage(regenerate=true)显式新尝试，沿用本轮冻结模型和剩余预算、计入5张尝试上限，捕获当前候选图片值后才允许晚到回填；失败重试和待核实提交仍保持原限制。
