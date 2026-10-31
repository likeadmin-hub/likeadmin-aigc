# 共享生成调用与任务结果合同（版本1）

本次实现对应[实施方案](market-generation-unification-plan-20261031.md)及[批次57证据](market-acceptance/batch-57-20261031.md)。保留现有应用路由、权限、业务任务ID和父任务编排；新增响应字段为可选增补。`api_schema.json` 目前声明的是路由／方法／权限，没有响应字段结构；本次无新路由、表结构、菜单、权限或应用安装／版本发布。

## 服务端调用

`MarketGenerationRuntime::selection(type, selection)` 以现有 `MarketGenerationAdapterRegistry` 为唯一适配器路由。工具仍负责形成最终提示词、素材和可信业务关联；共享层委托既有请求准备、合同验证、计量、预占、提交、查询和取消服务，不建立第二套任务或扣费。

```php
$runtime = MarketGenerationRuntime::selection('video', $selection);
$quote = $runtime->quote($tenantId);
$reservation = $runtime->reserve($tenantId, $userId, $appCode, $action,
    $businessTable, (string)$businessId, $request);
$runtime->bind($reservation['app_task_id'], $businessId, $tenantId, $userId);
$accepted = $runtime->submit($reservation['consumption_id'], $request, $tenantId, $userId);
// 后续恢复按受理消费的冻结快照路由，先验证任务及消费的租户和用户。
$frozen = MarketGenerationRuntime::consumption($consumptionId, $tenantId, $userId);
$result = $frozen->refresh($consumptionId, $tenantId, $userId);
```

图片张数必须为正整数；图片数字人等以输入秒数计费的适配器保留小数。应用售价覆盖、音乐已确认报价快照只由服务端业务策略提供；适配器不支持时明确拒绝，不能丢弃覆盖、截断数量或回退到另一规格。Nano 的专用历史修复调用及非市场渠道保留原约定。

调用幂等仍由既有消费业务键、短剧意图／报价确认、应用父子任务及专用工作流承担。共享层不把来自任意入口的相似提示词自动判成同一次任务；普通画布现有提交路由没有新增通用客户端请求键合同，不能据此宣称所有业务HTTP重复点击／丢包情形均已统一。

## 用户结果响应

单次生成可增补 `generation_task`；复合或多阶段工具增补 `generation_tasks` 与 `generation_task_refs`，父任务的 `status`、零售价、组成结果和确认步骤保留。

| 字段 | 语义 |
| --- | --- |
| `schema_version` | 固定1；未来不兼容变更须新版本 |
| `task_ref` / `app_task_id` | `app_task:<AiAppTask.id>`；不能由业务任务、消费、上游或资产ID猜测 |
| `status` / `execution_status` | 可展示状态／底层应用任务原始状态；生成、结算或强制转存未完成时延迟展示成功 |
| `stage` | `queued`、`confirmation`、`generating`、`settling`、`transferring`、`ready`、`failed`、`canceled` |
| `polling_required` | 是否继续按原应用查询接口读取；部分完成父任务也可能仍需轮询 |
| `billing_status` / `delivery_status` | 本地持久记录投影；不在读取中重新计费 |
| `result_contract` | `managed_assets` 或兼容的 `legacy_business` |
| `results` | 仅明确的输出资产；包含独立 `result_asset_id`、媒体类型、URI／展示URL、scope／engine／domain、尺寸／时长／MIME等 |
| `error` | 规范化错误；不返回请求、密钥、冻结成本或供应商原始载荷 |
| `created_at` / `finished_at` | Unix秒；兼容模型日期访问器返回字符串的情况 |

`GenerationTaskResultService::read/readMany` 只读本人本租户的持久任务、消费及资产，不查询供应商。列表采用批量投影；不加载大体积请求、计价快照或内联媒体正文。转存要求仍按原业务合同判断，去水印的既有可选转存规则保留。

已受理后，结果投影暂时不可用不能进入上层退款／重新提交分支：已知身份返回 `reconciling` 的待恢复视图，复合视图用 `generation_tasks_pending=true`，旧格式包装器保留该标志。旧记录缺少合法统一关联时可退回明确的旧业务接口，不制造新的统一任务身份。

前端 `components/ai-generation/generationTask.ts` 统一验证身份、结果和轮询。子任务成功不能完成仍在编排的父任务；新受管结果不从输入／参考URL或供应商旧别名寻找替代结果。画布只持久化轻量 `generationTaskRef`，不把实时视图写入冻结回执或意图输入签名，新尝试清除旧引用。

## 已接入与保留边界

| 入口 | 共享部分 | 保留部分 |
| --- | --- | --- |
| 原生图片／视频、普通画布 | 报价与生成运行；版本1任务；三媒体后台结果写回 | 各自业务任务／历史API；原生图片特殊修复 |
| 短剧画布 | 间接复用原生运行与任务视图；共享轮询／结果读取 | 报价确认、请求意图、图版本、当前节点及旧任务保护；后台扫描 |
| 商品图、风格化、修复、试衣、去背景、翻译、清除、套图、多角度、写真、扩图、局部重绘、服装试穿、宣传视频 | 原生生成与售价覆盖；有序子任务视图 | 父任务、模块、批次、业务结果归属；发型沿用原生图片 |
| 音乐、图片数字人、去水印 | 对应已支持市场运行与受理／详情／列表任务视图 | 歌曲操作及歌词、音色和素材专用协议 |
| 音乐翻唱 | 共享应用运行、受理／详情／列表视图、已结算受管音频后台业务写回 | 原歌曲与目标音色素材、历史新尝试及业务结果表 |
| 数字人、微视频 | 市场生成步骤复用运行；公开只读步骤视图；微视频终态唤醒 | 头像／音色／TTS、分段、首尾帧、确认、合成和父状态 |
| 专用剪辑、全驱数字人、文字流及非市场供应商 | 保留既有业务接口 | 不强塞入图片／视频市场生成合同；本次未重写这些专用协议 |

普通画布自动保存只合并同节点／类型／taskId／开始时间／一致统一引用的已完成结果字段，保留用户的提示词、模型、声音、质量和布局修改；换任务、手动替换、删除和原有版本冲突规则保留。

正式合同和像素／Token证据缺口仍按原准入阻塞，不因本次接口增补启用。真实上游收费调用、全工具逐型号／模式／SKU交叉、混合版本常驻worker与更多专用操作不在本次隔离替身验收结论内。既有常驻worker未重启，验证使用本批拥有的独立新进程。


## 批次58工具复核增补

新图片快照增加内部 `image_reference_slot_revision=1`，在旧模型编码器逐位置解析重复图片，旧冻结不升级；素材分类不明的附加计费仍拒绝。视频模型本地内容防重按已有显式请求键及应用归属匹配，新键不并入旧尝试，同键仍复用；无显式键保留原防重规则。这没有为普通画布新增通用客户端请求键。宣传视频优先具体单图素材声明。工具定时器统一读取完成条件，历史空输入和音乐普通选项／引用按保存值恢复。详见[批次58范围与证据](market-acceptance/batch-58-20261031.md)。

## 批次59多阶段工具增补

翻唱加入相同运行和结果合同，生产结果worker按拥有的关联投影父结果；数字人重复受理保留统一步骤身份，内部JSON保存音频／文字驱动意图。微视频发现档位和确认前只读报价使用不进入执行快照的提示词占位，单图方式与宣传视频共享具体声明选择；原业务的分段确认与合成仍保留。40个组合替身任务通过，微视频真实规划／首尾帧、文字TTS和音乐专用操作未冒认认证。详见[批次59范围与证据](market-acceptance/batch-59-20261101.md)。

## 批次60阶段结果增补

数字人内部受理JSON保存request_duration，TTS实测结果不改变防重身份。微视频帧图完成后由相同结果作业登记统一资产，保留已测媒体信息；业务详情可为旧的已结算、缺登记图片排入本地恢复，既有微视频查询、方案／帧图确认和合成协议保持。帧图报价占位不进入执行选择。文字TTS、原生规划流、三帧两视频、音乐克隆及8项辅助操作正常登录隔离链通过；22条消费结算与12份媒体资产分别统计。真实供应商、完整矩阵与正式依据保持独立。[批次60证据及边界](market-acceptance/batch-60-20261101.md)。
