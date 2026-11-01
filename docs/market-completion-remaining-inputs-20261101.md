# 完整方案剩余依赖：只读资料与配合清单

2026-11-01。API平台及平台源码严格只读。我们不要求修改平台、补字段、关强制路由、重发任务或处理真实账务，也不申请解除只读边界。已取得源码和当前18模型／9渠道／6应用配置，不再索要全套模型文档或密钥。

## 用户只需安排的人和现成资料

| 事项 | 谁配合 | 最小提供内容 | 我们负责 |
| --- | --- | --- | --- |
| 已发布路由依据 | 平台数据库维护人 | 下面A包的现有只读导出路径，或由维护人通过既有只读会话自提（不交会话凭证）；有则附现网运行版本记录 | 精确提取、脱敏、版本和九入口闭合验证 |
| 8规格收费保证 | 平台／供应商合同负责人 | 下面B包的现存合同或规范路径；没有正式保证就明确没有 | 绑定8行、校验覆盖、准备本地准入和回归；不猜上界 |
| 原消费回执 | 平台日志／账务维护人 | 下面C包原43条文字及1条STT的已有可唯一关联回执；不新调供应商 | 使用本机私有索引精确核对，仅给处置建议 |
| 真机与独立环境 | 手机测试人／运维 | 两台独立物理设备及操作者；已支持的微信体验版／原生App；第二独立节点或VM及现存worker两个版本 | 用合成素材准备逐项用例、环境核验和清理，不冒称本机等于多机 |
| 实际站点加载源码 | AIGC部署负责人 | 目标环境、维护窗口、现存备份与回退方式 | 提供两仓源码SHA、差异、验证／回退步骤，owner执行后复验 |

不用在聊天发送密码、API key、短信、用户prompt或素材。资料已经在本机时给目录即可；不知道技术细节时，把本文交给对应维护人即可。现有本地源码修复、隔离验证、构建和feature源码推送授权继续沿用。

Full Video附加收费还缺AIGC租户／用户价及与平台费用的换算依据。业务负责人若已有正式规则，只需给规则路径；未定义时继续限制超过5张普通参考图，不把API侧20直接抄成本地扣费。

## A包：已发布策略与少量原配置

已有：策略1、版本2、九入口强制标记、`final_success_target`、基础模型／渠道映射、Pro可见AceData标签。画布读取draft，不能代替published；正常路由日志也没有执行图。

只读导出以下现有表业务字段（`<prefix>`由维护人使用实际前缀，不执行迁移／Service／任务详情）：

- `ai_smart_route_strategy`：`id/code/status/current_version/delete_time/update_time`。
- `ai_smart_route_strategy_version`：`strategy_id/version/status/graph_snapshot/input_schema/output_schema/billing_policy/published_at/update_time`。
- `ai_smart_route_binding`：`source_type/source_id/strategy_id/status`。

要求精确策略1／`gpt_image_circular_fallback`启用，版本2为当前最高published版本，九个启用model绑定为94/96/97/98/100/102/104/105/106。若版本变动、缺行或重复，记录事实停止，不能换成draft。

graph需保留节点key/type/config中的target_type、target_id、mapping、result_mapping及边from/to和原顺序，附原blob SHA256与UTC采集时间。执行器按边DFS并按入口旋转；截图或目标名单不足。只导出现有published配置及该源码实际消费的业务投影，不要求修改或补全图；input/output_schema与billing_policy按原值（可为null）附带。节点timeout/max_attempts与billing_policy并非该async源码中的额外执行约束，不因元数据缺省新增门槛。

再补`ai_model`中94／98的原`fallback_chain`，以及98的`default_params.channel_mapping`对象：`enabled/channel_id/channel_code/upstream_model`和可选`api_config`业务覆盖。基础Pro请求映射、AceData渠道信息已在本机，不重导。仅对published图实际可达且不在已存证据中的target补其精确schema、业务配置和计价；draft中出现的103不能先算成正式依赖。

来源锚点为固定平台源码`b5887e1c…`的`AiSmartRouteMigrate::createTables`、`SmartRouteStrategyService::version`、`SmartRouteExecutor::orderedTargets/orderedTargetsForSource/prepareAsyncCandidate`、`ModelRouterService::applyChannelMapping`、`ModelFallbackService::loadFallbackChainByPrimaryModelId`。

不使用`SELECT *`，不查凭证、用户正文或任务记录；不输出base_url、api_key、api_secret及认证headers。若api_config夹带认证字段，维护人本机留原hash，导出允许的业务配置和删去字段路径；被删的是业务映射时须标不完整，不能伪装完整。静态字段路径包含prompt/image字样可保留，它们不是用户正文。

现网commit／image digest只有已有发布记录可读时才采集；否则保持部署版本未确认，不拿仓库分支推断现网。

## B包：8个规格的现存收费依据

| AIGC本地product／SKU | 精确资源 | 真正缺项 |
| --- | --- | --- |
| 275／380 | official-33-c0b38c6fcb29f739b0／official，qwen/image-edit | 最大可收费像素／图及最大收费输出张数，或等价完整可证公式 |
| 277／383 | official-33-f3baf6c03742072d3a／official，qwen/text-to-image | 同上；383文生图锁已知 |
| 140／210–215 | seedance/create；480p、720p、1080p各有／无视频输入 | 各变体覆盖标准／快速、frames、adaptive、duration=-1等合法输入的可收费Token硬上界或完整权威公式 |

表内product／SKU为AIGC本地ID，不能拿它们猜平台表ID。原声明、SKU锁、单位、价格公式和usage候选路径已取得，不要求重交。两个像素SKU当前保存meter_contract为null；六个Token同样无硬保证。已知请求尺寸／数量域、1M Token预占及40000 Token/秒估算都不是收费保证。

现有规范还须明确：哪些输出槽收费，缺usage／超量／失败／取消／部分完成如何收费；精确回执字段及版本。六Token已知候选为`data.result.usage.total_tokens`，顶层0不能代替它。只读现有合同、规范、部署记录或原回执，不新发生成或查询。不存在正式依据时写`not_available`，继续保留门槛，不要求修改API填出保证。

来源锚点：`OfficialCatalogService`、`OfficialMediaService::facts/resultUsage`、`OfficialTaskDriver::queryTask`、`OfficialSkuPolicy`、`SeedanceDriver::queryTask/pricingSnapshotOverridePoints/normalizePricingMatrix`。已有本地逐SKU绑定见`tests/fixtures/market-meter-source-candidates-20261101.json`。

## C包：43文字＋1STT原始权威记录

本机已有私有精确索引，不公开真实ID或正文。43条只能确定41个chat completion响应ID及2个response ID，不能凭ID前缀推断平台路由。

维护人以原上游账号范围，在只读数据库中按原ID做大小写敏感的完整相等匹配。对`ai_task.result`仅投影`id/request_id/response.id/message.id`，按原providerRequestId优先级核对，每案报告0／1／多匹配，不能`LIMIT 43`隐藏冲突。仅附唯一关联task的归属、实际model/channel、状态、原usage字段和类型／来源路径、原提交计价摘要及同task原账本／流水状态；不导请求正文／prompt／素材／认证信息。

Factory加工usage可能含估算，不能当供应商原usage；当前价格不能替代提交快照。流式原SSE未保存时明确缺证。正常安全UI不能按这些响应ID过滤；不打开可能触发成本重算或素材探测的任务详情。

STT原出站未发送消费号／幂等键／step／run关联键，两个返回ID为空。需已有网关／反代追踪中能证明原HTTP交换的唯一关联及原回执；没有就保持未知，不能按时间／URL／素材碰匹配。只核对和提建议，不改真实扣费、退款或状态，不重转写。

## 保持未执行的真实验收

API只读边界持续有效：真实生成、供应商query／retry／cancel／素材注册及正式配置启用不执行，不要求你再次授权这些动作。因而原方案中的这些真实验收行仍明确未执行；本地合成测试、源码通过或候选就绪都不能替代它们。没有把这些行删除或写成完成。
