# 审核合同的共享加载

本机制为已有完整依据的模型提供统一接入，不根据上架、协议名称、示例或指纹相同授予能力。2026-10-29 第七阶段只交付机制、离线回归及图片数组的部分类型证据，没有新增活动模型合同。原有 `generation-contracts.json` 与 90 个匹配合同保持原样。

## 数据与选择

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

未知 schema 关键字、未注册协议、不完整子结构、身份重复和来源指纹不符仍拒绝。点分隔素材路径暂不能编译；已有编码器的复合素材也不能套用平铺数组计数。非 `native_v1` 协议目前只接受能由共享层计数和校验角色的字符串或字符串数组素材。Qwen/Wan 等嵌套业务参数可以使用完整 schema 校验，但嵌套素材路径仍需补齐共享角色、计数与保留检查后才能启用相应合同。

## 编码、校验与计价

审核合同与编码器独立：有素材模式不再强制让审核后的 Qwen 等协议改用 `native_v1`。平铺图片、视频及 Qwen 的现有编码器保留；准备层检查最终业务请求的 schema、条件、素材角色/数量和 SKU 模式，并拒绝未声明的高级参数。传输凭据、市场上下文及幂等字段仍由原运行时负责，不写进业务合同。已有原生合同继续使用原路径。

报价、预占与提交通过同一共享准备器读取合同，工具继续传自己的业务输入，不增加供应商型号分支。SKU 锁定、计价规则、预占上限、失败退款、结算幂等和工具业务限制独立保留；请求合同匹配不会解除像素或 Token 计价阻塞。只读目录审计用 `matched_reviewed_contract` 区分这种匹配，并输出版本/身份/指纹摘要，真实调用和计价验证状态继续单独记录。

## 当前部分结构证据及回退

`market-image-structure-review-20261029.json` 保存 9 个图片数组的元素类型依据。8 项来自身份匹配的公开文档详情；`gpt-image-2 / OpenAIYI` 的文档绑定仍缺失，单独使用此前核实的保存目录声明，不借用 OpenaiM 或 VIP 的合同。其中 Nano Banana 2.1 已有显式 `children.item.type=string`，其余记录的是参数说明中的字符串/图片地址依据；不从示例自动推断数量、模式或条件。

`MarketGenerationDeclarationSchema` 只展开有明确类型的 `children.item` / 对象 `children`，冲突会拒绝，不解析说明文字或示例来生成运行时 schema。原始不透明结构仍记 15 项；按平台显式 children 展开后为 14 项。9 项部分类型证据不等于 9 份完整合同，18 个旧模型的完整合同待核验状态保留。

发现新合同有问题时，可先从当前受信任目录移除相应条目，影响后续选择；保留读取器处理已冻结的任务。已有冻结合同的任务不能直接交给不认识版本 2 的旧代码处理。没有执行任务迁移、历史结果修复或重新计费。
