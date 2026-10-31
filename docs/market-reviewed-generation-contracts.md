# 审核合同的共享加载

本机制为已有完整依据的模型提供统一接入，不根据上架、协议名称、示例或指纹相同授予能力。2026-10-29 第七阶段只交付机制、离线回归及图片数组的部分类型证据，没有新增活动模型合同。原有 `generation-contracts.json` 与 90 个匹配合同保持原样。

## 数据与选择

共享视频目录的 `generation_variants` 仅把已注册的业务档位与当前可售 `market_sku_ids` 绑定，供各入口复用档位、分辨率和时长投影；`mode_options` 继续兼容既有消费者。它不授予新模型能力，不代替下列完整原生合同，也不替代准入时的SKU、素材、参数和计价检查。目录不包含这项绑定时，前端不按相似型号猜测关联；新的协议或计价维度仍须在共享层补充支持并验证。

`MarketGenerationReviewedContract` 读取受信任的本地 `resources/market/reviewed-generation-contracts.json`，可通过 `MARKET_REVIEWED_GENERATION_CONTRACTS_PATH` 指定受信任文件。文件不存在时使用原有路径；候选工具的输出不能直接作为此文件。目录格式为 `version: 2`、`contracts: [...]`，只允许同一类型、型号、渠道有一个当前条目。更换版本通过替换此条目完成，不根据标题、相近型号、渠道大小写或版本号排序猜测。

每条合同包含：

| 字段 | 含义 |
| --- | --- |
| `format_version` | 当前为 2；未知格式拒绝 |
| `generation_type`、`model_code`、`channel_code` | 本地模型资源的精确身份，不接受应用 API 身份 |
| `public_model_code` | 元数据中的公开型号，须同时匹配 |
| `revision` | 经审核合同的版本，不代表已验证真实生成 |
| `generation_protocol` | 已注册的共享编码器，仍检查类型和显式协议冲突 |
| `declaration_fingerprint` | **原始**平台参数及素材声明的完整指纹，保留描述和示例 |
| `material_capabilities`、`modes` | 独立复核的完整素材模式、模式参数 schema 与条件规则 |
| `source` | 原始依据的类型、位置、版本、复核日期及原始文件 SHA-256 |
| `contract_digest` | 包括模式、来源、身份与版本在内的完整内容指纹 |
| `sku_pricing`、`live_generation` | 编译结果仍为 `unverified`；不替代计价或真实调用核验 |

来源文件指纹只确认文件绑定，不证明来源真实、合同完整或上游实现一致。这些需要独立复核，不能把捕获时间伪装成上游合同版本。供应商文档还须核对市场网关转换后的接口，不能仅凭型号相似直接套用。

原始 `params_schema`、`material_capabilities` 与平台校正不被补充合同覆盖。共享图片/视频元数据在读取持久商品时选择审核合同，将完整合同保存在内部 `_reviewed_generation_contract` 中；实际快照构造器原样冻结这一选择及既有价格。型号、渠道、原始声明、内容指纹或格式不符时拒绝使用，不静默退回宽松旧路径。

已经冻结的合同不重新读取当前目录，目录更新或移除不会替换它。没有此内部记录的历史快照也不自动补入新合同。目录文件缓存只存在于一次目录读取范围，实际准入重新读取，不能沿用上一次目录请求的缓存。此内部字段由服务端生成，不是客户端可指定的业务参数；内容指纹不是认证签名。

## 编译流程

沿用现有候选 bundle 的 `version: 1`、`source` 和 `entries`。每项仍需完整的 `declaration.params_schema`、`declaration.material_capabilities`、`modes`，另外提供：

- `revision`：经审核合同版本。
- `binding_declaration.params_schema`：当前商品实际保存的原始参数声明，不能换成补齐后的 schema。
- `binding_declaration.material_capabilities`：同一商品原始素材声明；确实没有时为 `[]`。

完整合成格式和型号/渠道隔离例子见 `MarketGenerationReviewedContractTest::bundle`；这些是测试身份，不能替换真实模型合同。

```sh
# 只生成待审核候选。
php tools/market/prepare-generation-contract-candidates.php bundle.json original-source-artifact > /tmp/candidates.json
# 仅在来源与合同已经复核后编译版本 2 格式，输出仍在临时文件。
php tools/market/compile-reviewed-generation-contracts.php bundle.json original-source-artifact --reviewed > /tmp/reviewed-contracts.json
```

两条命令只读本地文件并向标准输出写入，不执行源文件、不访问网络或数据库、不自动启用合同。第二条的 `--reviewed` 是调用方对已有审核的显式声明，不是自动审核证明。将真实条目纳入受信任目录前，须完成来源、字段覆盖和协议族离线回归，并按仓库规则在 feature 提交、本地 develop 验证后交付源码。

未知 schema 关键字、未注册协议、不完整子结构、身份重复和来源指纹不符仍拒绝。第八阶段允许审核后的图片合同在 `qwen_image_v1` / `image_messages_v1` 使用只读点分隔路径及数组 `*`，例如 `input.messages.*.content.*.image`。路径必须在完整模式 schema 中指向字符串或字符串数组，复用素材数量、角色、平台校正与 SKU 校验；实际嵌套请求仍由已有编码器生成。表达式、递归通配、找不到的路径、嵌套写入及未实现的复合主体不能套用这种计数。第九阶段的视频混合分类另有以下有限规则，并不支持通用路径表达式。

### 第九阶段：混合素材分类

审核后的 `wan_video_media_v1` / `video_input_parameters_v1` 支持 `input.media.*.url`，H3 支持 `content.*.image_url.url` 等由现有编码器生成的路径。混合数组的字段须提供 `item_match`，在最后一个 `*` 所指数组项上匹配固定字符串，例如：

```json
{"field":"input.media.*.url","kind":"video","role":"reference","maximum":2,"item_match":{"type":"reference_video"}}
```

`maximum: 2` 仅是合成格式示例，不是任何真实模型的已核实上限。分类键必须由完整 schema 声明为必填字符串，值必须在 enum/const 中，并与所选协议编码器的类型及角色精确一致。Wan 使用 `reference_image` / `reference_video` / `reference_audio` / `first_frame` / `last_frame`；H3 同时匹配 `type` 和 `role`。同一路径的分类不能重叠；未被当前模式覆盖的数组项、错误角色、超限、平台校正及 SKU 模式/输入标记冲突继续拒绝。相同 URL 的首尾帧仍是两个输入槽。

Wan 系列的 `file` / `link` 仅在完整审核模式显式声明对应 kind 和 type 时，才将业务 `reference_assets` 编码为相应 media 项。声明缺失时保持拒绝，不能因共享编码器存在就直接启用。文件/链接逐项限额及与其他素材是否互斥由模式字段及规则校验，文件和链接需 HTTP(S) 地址且不允许 URL 凭据，原有业务素材总量 15 仍保留。文件复用已有存储路径转公开 URL 的逻辑，链接须提供绝对地址；不下载、不探测文档，也不把它们计为图片或视频。其他供应商协议没有因此获得文件能力。

共享准备器还比较显式原生嵌套素材参数与实际编码结果，现有编码器无法完整保留时拒绝，并提示使用统一素材输入。例如 H3 的业务 image/video/audio 输入可用，现有编码器不合并 `provider_params.content` 的素材；这类输入明确拒绝，不能称为所有原生参数形式均可用。业务素材和原生素材同时提供时也拒绝，避免覆盖或重复计费。

## 编码、校验与计价

审核合同与编码器独立：有素材模式不再强制让审核后的 Qwen 等协议改用 `native_v1`。平铺图片、视频及 Qwen 的现有编码器保留；准备层检查最终业务请求的 schema、条件、素材角色/数量和 SKU 模式，并拒绝未声明的高级参数。传输凭据、市场上下文及幂等字段仍由原运行时负责，不写进业务合同。已有原生合同继续使用原路径。

报价、预占与提交通过同一共享准备器读取合同，工具继续传自己的业务输入，不增加供应商型号分支。SKU 锁定、计价规则、预占上限、失败退款、结算幂等和工具业务限制独立保留；请求合同匹配不会解除像素或 Token 计价阻塞。只读目录审计用 `matched_reviewed_contract` 区分这种匹配，并输出版本/身份/指纹摘要，真实调用和计价验证状态继续单独记录。

第九阶段将素材计量输入提取为共享纯函数，识别 Wan 的 `reference_image` / `reference_video` 及已有模型/应用编码。保留重复素材槽，排除提示词、回调、文件/链接，不信任客户端素材 duration。已有安全媒体探测用于实际输入时长；需要时长却仍是 `asset://` 的输入拒绝报价，不用未知时长冒充零。测试只用服务端传入的假探测器，没有请求真实素材。计价公式和旧任务冻结的 `meter_context` 不变。

审核后的 Wan 时长从所选模式的 `parameters.duration` 读取，避免误判为不支持时长；SKU 固定时长优先，正时长协商仍沿用既有业务规则。智能时长只接受完整 schema 明确给出的值，不从说明或示例授予；实际用量结算要求保留。审核 schema 不再用部分公开说明追加枚举。没有完整审核合同的旧协议继续原有行为。

## 当前部分结构证据及回退

`market-image-structure-review-20261029.json` 保存 9 个图片数组的元素类型依据。8 项来自身份匹配的公开文档详情；`gpt-image-2 / OpenAIYI` 的文档绑定仍缺失，单独使用此前核实的保存目录声明，不借用 OpenaiM 或 VIP 的合同。其中 Nano Banana 2.1 已有显式 `children.item.type=string`，其余记录的是参数说明中的字符串/图片地址依据；不从示例自动推断数量、模式或条件。

`MarketGenerationDeclarationSchema` 只展开有明确类型的 `children.item`、对象 `children` 及数组直接声明对象子字段的 `children`，冲突会拒绝，不解析说明文字或示例来生成运行时 schema。第八阶段共享旧协议准备会验证显式 children，但不会按新发现的结构重建对象或删除开放字段；false/0、未声明的开放子字段及原始声明保留。新图片快照的 `generation_validation_revision=8` 使 Qwen 参考图模式的 `agent` 选择按公开规则拒绝；已有冻结快照继续保留原有效 direct 选择。原始不透明结构仍记 15 项；按平台显式 children 展开后为 14 项。9 项部分类型证据不等于 9 份完整合同，18 个旧模型的完整合同待核验状态保留。

当前完整资料的外部缺口和精确资源清单见[提供给平台方的合同资料清单](market-provider-contract-export-request.md)。没有将该清单发送给服务方，也没有放宽候选来源要求来制造已验证原生合同。

发现新合同有问题时，可先从当前受信任目录移除相应条目，影响后续选择；保留读取器处理已冻结的任务。已有冻结合同的任务不能直接交给不认识版本 2 的旧代码处理。没有执行任务迁移、历史结果修复或重新计费。


## 第43批：Token视频的有上限计量合同

这是SaaS共享计量器新增的有限格式，不代表当前上游已经提供这些字段或可放开Seedance。当前6个Token SKU仍缺上限、保持报价／准入阻塞；原生透传API的Token门槛仍单独保留。图片Token和像素计价没有获得此能力。

受信任SKU的`source_payload.meter_contract`可声明如下合成格式；1000仅是测试数字，禁止用于真实型号：

```json
{"version":2,"basis":"token","rounding":"ceil_2","maximum_quantity":1000,"usage_path":"data.usage.total_tokens"}
```

实际启用前必须有精确型号／渠道或应用／API、SKU、网关版本绑定的正式计费依据，保证`maximum_quantity`覆盖该SKU所有允许输入的实际可收费Token总量。任意客户预算、浏览器参数、按时长／分辨率猜出的公式和同名供应商文档都不是这种保证。无完整依据不能给真实SKU添加此声明；本批没有添加活动合同。

version2只允许Token单位及有效单位大小、1至10^12的整数上限、舍入`ceil`（整点）或`ceil_2`（两位账户精度向上取整）。更细的正金额可能被账户舍成零，因此本版不接受`half_up_6`；version1既有语义不变。`usage_path`仅允许`usage.total_tokens`、`data.usage.total_tokens`、`result.usage.total_tokens`或`data.result.usage.total_tokens`四个明确回执位置之一，不执行表达式或模糊递归。新快照冻结该规则、单位及单价；后续更新不重算旧快照。

共享视频报价／内部准入和预占按上限计费，实际回执按原价格结算、退回差额。预占前同时检查租户和用户余额；没有上限或声明不合法仍拒绝。指定位置无有效正整数回执时保持预占和running状态；超上限回执报错等待核查，不补扣、不截值后冒认结算。重复预占、结算及失败释放保持幂等。Token不能使用固定应用售价覆盖；上限字段不会传给供应商，不能被前端请求覆盖。
