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

这是SaaS共享计量器新增的有限格式，不代表当前上游已经提供这些字段或可放开Seedance。当前6个Token SKU仍缺上限、保持报价／准入阻塞；原生透传API的Token门槛仍单独保留。该version2能力不适用于图片Token或像素计价。

受信任SKU的`source_payload.meter_contract`可声明如下合成格式；1000仅是测试数字，禁止用于真实型号：

```json
{"version":2,"basis":"token","rounding":"ceil_2","maximum_quantity":1000,"usage_path":"data.usage.total_tokens"}
```

实际启用前必须有精确型号／渠道或应用／API、SKU、网关版本绑定的正式计费依据，保证`maximum_quantity`覆盖该SKU所有允许输入的实际可收费Token总量。任意客户预算、浏览器参数、按时长／分辨率猜出的公式和同名供应商文档都不是这种保证。无完整依据不能给真实SKU添加此声明；本批没有添加活动合同。

version2只允许Token单位及有效单位大小、1至10^12的整数上限、舍入`ceil`（整点）或`ceil_2`（两位账户精度向上取整）。更细的正金额可能被账户舍成零，因此本版不接受`half_up_6`；version1既有语义不变。`usage_path`仅允许`usage.total_tokens`、`data.usage.total_tokens`、`result.usage.total_tokens`或`data.result.usage.total_tokens`四个明确回执位置之一，不执行表达式或模糊递归。新快照冻结该规则、单位及单价；后续更新不重算旧快照。

共享视频报价／内部准入和预占按上限计费，实际回执按原价格结算、退回差额。预占前同时检查租户和用户余额；没有上限或声明不合法仍拒绝。指定位置无有效正整数回执时保持预占和running状态；超上限回执报错等待核查，不补扣、不截值后冒认结算。重复预占、结算及失败释放保持幂等。Token不能使用固定应用售价覆盖；上限字段不会传给供应商，不能被前端请求覆盖。

## 第44批：图片像素的有上限计量合同

仅适用于普通图片模型共享运行时。以下是SaaS有限格式的合成示例，数字不是任何真实型号的已验证上限：

```json
{"version":3,"basis":"megapixel","rounding":"ceil_2","maximum_pixels_per_image":1000000,"maximum_images":4,"result_path":"data.image_urls","result_item_url":""}
```

启用前须有精确型号／渠道、SKU及网关版本绑定的正式计费合同，保证单张最大像素和最大张数覆盖全部允许请求，并说明成功输出与转存回执。不能用用户预算、浏览器宽高或其他供应商同名尺寸替代上限。当前380、383没有这种声明，继续拒绝；没有写入活动version3合同。若只有逐尺寸映射，仍须完整覆盖自动尺寸、所有合法组合和输出数量，不能从不完整映射提取最大值冒充硬上限。

允许`business_unit`、`megapixel`、`per_megapixel`单位及有效单位大小（至少1）；单张上限是1至10^9的整数，最大张数1至16。仅支持`ceil`或`ceil_2`，版本1旧语义保留。输出列表路径仅允许`images`、`image_urls`、`data.images`、`data.image_urls`、`result.images`、`result.image_urls`、`data.result.images`、`data.result.image_urls`、`data.result.results`；URL字段仅允许空字符串（直接字符串列表）、`url`、`image_url`、`uri`。不允许表达式、递归猜测或混用别名；声明中的其他字段拒绝。

预占物理量为请求张数×单张上限／10^6，结算物理量为服务端实测各成功槽宽×高总和／10^6。相同URL的两个槽分别计数，不能去重少收；同次下载可复用文件。响应或浏览器自报尺寸不用于计费。字段型结果若给出type须为image／image_url；显式成功转存可计量，失败／隐藏槽排除，未知／未完成转存拒绝。缺尺寸、超冻结上限或无有效输出保留预占等待核查，不按猜测值结算。

即时回执在本地处理前持久保存，已存图片及尺寸可继续结算；没有任务ID的回执只重试本地处理，明确失败回执幂等退款。Nano应用与原生透传暂未接入这条物理测量链路，显式拒绝version3；不能将其按张数计量。测试及未实测范围见[批次44台账](market-acceptance/batch-44-20261031.md)。

## 第45批：只读声明变更审计

`MarketGenerationContractReview`新增10个已知公开字段的精确投影比较，覆盖schema、素材合同、条件、默认值、协议与合同／网关版本。报告字段名称、变化字段和结构指纹，不回显原值；区分字段缺失、null、false和0，保留数组顺序，忽略对象键顺序。示例保留在精确指纹中，但不成为默认值或可执行规则。模型以保存的upstream_metadata为依据，应用以自己的直接API声明为依据，文档不可绑定时比较结果为null。

该比较不替代完整合同审查，不自动阻塞或开通模型，也不覆盖白名单以外的所有可能字段。schema一致或整个已知投影一致，都不能把未声明模式／条件、像素上限和Token预算推断为存在。version3诊断现同时校验单位大小并展示上限及回执选择，避免将运行时已拒绝的规则报告为可用。最新来源和未完成项见[批次45台账](market-acceptance/batch-45-20261031.md)。

## 第46批：上限计量的精确账户舍入

version2／version3继续使用既定的ceil或ceil_2，未增加计量格式。计算改为按账户单位整数除法后检查余数，避免先除到固定12位小数导致正费用消失或漏掉分位／整点以上的余数。极小正费用仍按声明向上取整，精确边界不额外增加，免费价格保持0；未计量和version1旧冻结算法保留。

市场价格对浮点输入明确按六位归一化，并对SKU单位／成本／售价及市场价格规则值采用显式字符串数据库绑定，避免默认浮点转串及ORM二次浮点转换丢失声明小数。不重算旧账，不从修复算例推断真实型号已提供上限；当前没有启用version2／version3 SKU。回滚测试和实际未完成项见[批次46台账](market-acceptance/batch-46-20261031.md)。

## 第47批：原生视频模型的Token发布绑定

仅普通video模型资源获得此就绪能力，应用API、图片Token及像素透传不因此放开。可信SKU须先有正式version2合同，再审核发布匹配的原生计费规则；草稿编译不执行发布。新增`billing[].native_meter`绑定单位、单位大小、最大Token、回执路径和舍入，max_units及usage_path也须一致。缺失或过期绑定拒绝，不从请求正文读取计费授权。以下数字均为合成示例：

```json
{"basis":"usage","max_units":1000,"usage_path":"data.usage.total_tokens","native_meter":{"version":2,"maximum_quantity":1000,"usage_path":"data.usage.total_tokens","rounding_scale":2,"usage_unit":"token","usage_unit_size":"1000.000000"}}
```

上限预占和实际计量复用共享版本2，财务缺回执保持pending_usage、超上限保持settlement_failed供核对，明确失败退款。单价和计量在受理快照中冻结，不按后续SKU重算。既有按次／秒及旧冻结语义保留；原生提示词投影补齐，不改变请求正文。实际没有启用或发布此类合同，测试与未实测边界见[批次47](market-acceptance/batch-47-20261031.md)。


## 第48批：Nano像素与普通图片模型Token

Nano应用复用version3像素核验与结算。因原生张数映射未确认，预占使用SKU正式`maximum_images`全局上限，`requested_image_quantity`另存UI数量；不得从UI张数猜造原生字段。固定应用零售价按实际成功槽数，市场成本按服务端实测像素。按次／旧Nano冻结保持。普通图片模型复用version2 Token预占与指定路径实际结算，UI张数与`billing_quantity`分离；固定售价覆盖拒绝。计量图片返回实际落库的价格快照。

缺计量、超上限或受理状态不明保留预占，明确失败退款；先存回执及就绪图片，无任务ID可继续本地恢复。上述均为共享格式就绪；当前活动version2／version3仍均为0，Nano实际张数映射、有效转存和供应商回执未实测。Nano Token、原生图片／应用Token和像素透传继续保留独立门槛。无法确认的合同保留待用户核对；见[批次48](market-acceptance/batch-48-20261031.md)。


## 第49批：Nano及原生媒体的上限计量扩展

Nano业务应用接入既定version2 Token；原生绑定扩展到普通image模型、Nano submit及既有支持的视频生成应用，仍须精确API身份、完整schema、正式SKU上限与匹配发布规则。Nano原生仅接顶层已审核请求，未映射嵌套结构拒绝；不增加任意应用或资源ID适配。

version3原生像素绑定包含最大张数／单张像素、输出路径／URL字段、单位／大小及舍入；发布max_units为SKU最大百万像素，usage_path为声明结果路径。普通模型受理按请求张数上限，Nano按全局最大输出预占。服务端直接读图片字节测量，不上传或改原响应；在本方法锁事务前测量，入账重验执行权及冻结／结果指纹。缺量保留预占，超量等待核对，明确失败释放一次。

原生Token数量与图片张数分开，不以Token量判断部分图片成功；旧冻结及按次／秒路径保留。真实v2／v3启用仍为0，正式依据缺失保持阻塞，测试及未完成项见[批次49](market-acceptance/batch-49-20261031.md)。


## 第50批：有上限图片的提交保护与标准内嵌结果

普通业务的version2／version3图片和Nano任务在有效预占上先锁内占用submitting，重复进入返回已有状态；网关等入口仍沿用自身执行策略。Nano取消在退款锁内复核，迟到request_invalid不释放已占用的新计量任务。已知本地适配器错误在占用前拒绝，未知受理保留预占等待核对，不自动重提或超时退款。

标准data:image/...;base64,...结果可由新Token图片路径接入既有安全图片核验，必须转存成功且有有效尺寸后才可交付；裸b64_json和资源ID无映射仍保留。共用结果URL解析补齐标准字符串列表，不扩大为任意输入／响应语义认证。新规则与计量合同格式无变化，真实v2／v3启用仍为0；复现、验证和未完成项见[批次50](market-acceptance/batch-50-20261031.md)。


## 第51批：有效图片转存与结果任务恢复

未修改或启用任何真实合同。共享结果资产修复图片存储参数传递、按应用队列关联和活租约保护，已存结果把匹配URI及真实存储回执投影回标准摘要和图片业务结果。原始URI保留在资产行，冻结费用和其他回执不变；不根据图片尺寸或合成测试猜造上游规格。

有效PNG经实际结果worker领取／中断／自然租约回收、转存和图片业务回填通过；正常登录的两个新合成项目另经上传保存重开验证。两段不是同一HTTP生成任务，视频／音频仅测标准摘要投影。云端结果转存、上传中断、完整工具交叉及18／2像素／6Token的正式依据继续保留；见[批次51](market-acceptance/batch-51-20261031.md)。


## 第52批：受管结果文件的检查点恢复

正式模型与计量合同未变。结果队列通过内部可选资产ID使用受管检查点，记录源指纹、稳定目标、实际字节SHA256／大小及存储回执；来源或存储变化拒绝自动换目标。已stored资产不因后续业务回填异常重新打开上传。本地真实worker上传后kill和迟到回填失败恢复通过，视频／音频标准容器实例仅认证资源服务／本地文件层，不替代真实任务回执或供应商执行。

短剧上传回写当前节点、视口可选尺寸恢复属于工具可靠性修复，不用于关闭任何模型合同缺口。当前真实v2／v3仍均为0；18完整合同及2像素／6Token仍保持待核对。范围和剩余项见[批次52](market-acceptance/batch-52-20261031.md)。


## 第53批：视频／音乐业务的结果元数据保留

正式合同与费用冻结格式未变。共享结果投影不再用视频存储的未知0尺寸覆盖已有尺寸；有效实测文件大小、时长和字节识别MIME进入标准摘要，音乐生成业务可保留WAV实际结果信息。保留供应商原尺寸不等于服务器测量视频像素，不据此开通像素或Token规格。

两类本地业务worker完成内嵌回执和自有loopback HTTP文件的中断恢复，不涉及供应商生成／查询、正式新合同或全部型号。正常登录页面另用导出合成文件完成上传重开；同一HTTP生成任务整链仍未认证。真实v2／v3启用0，18完整合同及2像素／6Token依据继续待核对；见[批次53](market-acceptance/batch-53-20261031.md)。


## 第54批：迟到转存执行者的终态保护

正式合同及财务冻结格式未变。已完成受管回执按内容校验和、大小、存储信息及文件所有权复用，不允许旧租约的迟到下载重定目标；资产最终保存重新锁定终态，队列仅当前running租约收尾并记录事件。图片内部受管调用保留完整规范回执。

双生产视频worker在新执行者已完成后放行旧下载，同内容／不同内容各保持1文件1登记1业务结果、过期收尾事件0；三类媒体旧中断恢复及正常HTTP上传重开单独通过。这不替代尚未完成时的同键存储并发、存储写入中kill或同一HTTP生成任务整链。真实v2／v3仍0，18完整合同和2像素／6Token正式缺口保持，未刷新上游或收费生成；见[批次54](market-acceptance/batch-54-20261031.md)。
