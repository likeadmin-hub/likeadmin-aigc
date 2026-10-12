# 商城 API 契约编译与草稿导入

编译器把现有租户商城目录转换为可追溯注册计划。计划包含每个产品、有效用户售价 SKU、规范化输入、已审核协议路径、辅助 query 注册和逐候选阻断原因。生成草稿不等于接口已开放；此流程不发起请求、不签发测试密钥、不修改开放设置、不发布契约或文档。

## 后台入口

- `GET /platformapi/app/open_platform/marketPlan?tenant_id=1`：只读计划；可传 `product_ids[]`。
- `POST /platformapi/app/open_platform/importMarketContracts`：重新读取目标租户目录后导入符合条件的草稿。

两项操作均注册平台后台专属权限、API schema 和菜单按钮；租户后台没有对应权限。现有非超级管理员须通过正常应用菜单同步及角色授权获得新增权限。

导入体示例：

```json
{"tenant_id":1,"product_ids":[51],"reviews":{}}
```

明确审核的结构修订按产品 ID 提供：

```json
{
  "tenant_id": 1,
  "product_ids": [51],
  "reviews": {
    "51": {
      "review_note": "已核对上游参数与响应声明",
      "input": {"type":"object","properties":{"url":{"type":"string"}},"required":["url"]},
      "example": {"url":"https://example.com/public-post"}
    }
  }
}
```

允许修订的字段只有 `review_note`、`input`、`example`、`responses`。修订不能覆盖路径、执行器、SKU、收费、异步依赖、资源归属、上限或验证回执；不能携带凭据及内部身份字段。源文档中的经济字段仍会保留响应适配阻断，不能用响应结构修订绕过。

## 编译边界

- JSON Schema 保留结构；仅当 `properties` 已明确对象且缺少 `type` 时补充对象类型。
- 字段映射转换 `children`、必填字段、联合类型和结构化约束。名为 `type` 的参数与 Schema 根类型分别识别；纯说明性的 options 文本保留为说明；结构化枚举仍参与校验，不能把说明推断成计费上限。
- 文本模型仅采用声明的已知协议，媒体模型沿现有运行时使用 `/api/v1/tasks`。应用路径采用明确审核的应用和操作清单，新增操作需代码审核。
- 普通固定调用采用有效 `per_call`、`usage_unit_size=1` SKU，`max_units=1` 来源于单调用边界。原生媒体采用注册的 `native_media_v1`，复用用户端 admission quote，固定产品及 SKU，按真实成功数量或对应秒数结算；不会将 Token 字段作为秒数。零售价仍归属商城计费链。
- 异步候选必须声明真实任务 ID 响应；普通应用沿共享任务查询 `/api/v1/tasks/{task_id}`。独立 query、资源列表、共享素材及资源 ID 操作保留权限适配阻断。
- Token、字符及内部 `_pricing_variant` 等派生维度须有对应计量/选择适配器。Token 缺少可靠并发额度边界时保持阻断，不能从价格、描述或默认值推断用量上限；output_second 缺少真实用量保持待用量。
- 成功响应和请求示例必须来自结构化元数据、可解析文档示例或明确审核修订，并通过现有契约验证。包含上游经济字段的响应需先适配。

## 导入与发布

导入按产品锁串行化，契约编码包含产品、协议和 SKU，配置保存编译器版本与源摘要。发现任何既有同编码契约或同 slug 全局文档即跳过，保留已发布、人工编辑及历史版本，不自动覆盖。

每个新契约配套生成全局文档草稿。文档使用当前契约的结构及示例，价格说明引用当前租户有效 SKU，不写死某租户售价，不包含成本、渠道凭据或原始 provider 配置。

后续仍通过现有 `createVerification`、真实网关验收与 `publishContract`。发布需要已发布对应文档、相同契约摘要和示例指纹、完成结算的成功回执及独立积分流水核对。本编译器不生成任何验证回执。

## 本地覆盖快照与验证

2026-10-12 在 f290b06b7 上重新读取本地租户 1：87 产品、179 有效 SKU、191 路径/SKU 候选；19 个候选可形成草稿，172 个候选有阻断，覆盖 12 个产品。此数量是编译能力快照，不是已发布数量，后续目录和适配变化须重新读取计划。

新测试 `tests/open-platform/market-compiler.php` 使用本地数据库守卫与事务回滚，覆盖字段映射、类型联合、明确审核修订、固定单调用、零售价计费、归属与异步缺口、上游经济字段、平台专属权限、导入幂等、保留人工编辑和禁止自动发布/执行。

```sh
docker exec -w /www/wwwroot/likeadmin_aigc_saas/server bt /www/server/php/82/bin/php tests/open-platform/market-compiler.php
```


## 协议、文档与本地发布证据

模型唯一时不新增必填 channel；服务器按固定产品渠道路由，原客户端模型和业务字段保持不变。重复模型存在渠道歧义时要求明确选择，不猜测默认上游。SKU 锁字段可在 root/parameters/params 中匹配，保留原结构中已声明且与此 SKU 一致的默认值，避免调用者必须补充原本可省略的参数。

编译器生成面向接入用户的鉴权、参数、价格、任务、幂等与错误指南，审核摘要和验证回执留在私有配置。公开 OpenAPI 与能力目录只替换编译器响应的内部审核说明，不修改原契约或 schema。

当前仅两项商城能力已经真实验收并发布到本地租户 1：

| 能力 | 契约 | 真实请求 | 本站用户积分 |
|---|---|---|---|
| 音乐风格优化 | market.p12.application_api.s436 v1 | req_24983422a8bae235ed5cca677d0d7861 | 14 |
| 作词 | market.p20.application_api.s444 v1 | req_e92a8ee75f94855319e8fb7092b14381 | 12 |

作词首次真实回执成功结算，但与供应商文档的 data.result.data 数组声明不同；实际为对象。审核契约保留已声明数组与观察到的对象两种结构后，重新执行真实验收，不复用不同契约的旧回执。此阶段实际测试消耗 38 积分，累计授权测试占用 372.64/1000；所有临时密钥均已撤销。发布通过既有草稿预览、独立积分流水、相同示例指纹、文档发布和契约发布流程，没有直接设置 published 或伪造验证记录。

本地仅租户 1 开通上述 12/20 产品；其他租户未新增开放权限。媒体和其他接口的草稿仍不等于可调用，必须继续补齐协议及逐项验收。

f290b06b7 串行运行本地隔离回归：65 项测试与 210 个变更 PHP 文件语法均通过，日志 `/tmp/open-platform-market-regression-final-20261012.log`，详细记录 `runtime/open-platform-acceptance/regression-20261011.json`（文件名沿用，generated_at/commit 为当前证据）。此回归不包含付费上游调用；真实商城证据另存 `local_text_market_fb026b534753.json` 和 `local_text_market_0d03edc78d23.json`。

## 已审核协议结构（注册表 v1 / 编译器 v2）

`OpenMarketProtocolSchemas` 读取版本化 `market-protocol-schemas.v1.json`，不发起上游请求。注册表记录审核的上游源码版本、路径与文件摘要，采用时写入 `config.compiler.schema_sources` 和计划候选的来源记录。

- 已注册图片/视频 `model_task` 缺成功响应且没有显式审核响应覆盖时，采用真实 TaskController 的 submit/query 响应结构。已有响应声明保持原样；通用路由结构不替代未知模型的业务请求字段。
- 查询结构保留 `type/call_type/result/results/usage`，供应商业务扩展允许保留，经济字段仍经独立原始来源、Schema 和运行时 body 门禁。金额字段为 `number`，业务整数与 token 用量不改成金额。
- 编译文档中的响应示例只使用公开占位资产和中性积分值，明确标记结构示例。示例不是费用报价、真实回执或发布验证。查询仍通过任务创建者的本地归属映射，不注册共享查询/资源免费接口。
- 缺请求结构的 factory 文本模型按各自已声明协议提供请求文档：chat 的 `messages`、completions 的 `prompt`、responses 的 `input`、Anthropic 的 `messages/max_tokens`。示例数量不是额度上限，未公开 token 边界仍保留 `actual_usage_boundary_required`。
- `grok_video.submit` / `nano_banana.submit` 的静态请求快照来自实际应用详情和审核过的迁移/驱动源代码；不会自动套用模型响应。Grok 商业单位覆盖与原规格不一致继续经 `OpenSkuConsistency` 阻断，保留现有售价。

`UpstreamPricingService::queryAppDetail` 新增固定应用详情只读能力。只有以后明确执行目录同步时，缺请求结构的 API 才按精确 app/API 身份合并详情白名单字段，每个 app 复用一次详情。此补丁未执行同步、修改 SKU 单位或售价。

`tests/open-platform/market-protocol-schemas.php` 只验证静态编译、neutral typed JSON 示例、原始来源金融门禁、协议分路输入及应用详情商业字段隔离；不 import、publish 或执行生成请求。
