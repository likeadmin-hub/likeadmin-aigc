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
- 字段映射转换 `children`、必填字段、联合类型和结构化约束。名为 `type` 的参数与 Schema 根类型分别识别；模糊的 options 文本需要审核。
- 文本模型仅采用声明的已知协议，媒体模型沿现有运行时使用 `/api/v1/tasks`。应用路径采用明确审核的应用和操作清单，新增操作需代码审核。
- 仅有效 `per_call`、`usage_unit_size=1` 的单调用候选可自动生成计费规则，`max_units=1` 来源于单调用边界。零售价仍归属商城计费链。
- 异步候选必须声明真实任务 ID 响应；普通应用沿共享任务查询 `/api/v1/tasks/{task_id}`。独立 query、资源列表、共享素材及资源 ID 操作保留权限适配阻断。
- Token、字符、媒体时长及内部 `_pricing_variant` 等派生 SKU 需专门数量/选择适配器；不能从描述或默认值推断上限。
- 成功响应和请求示例必须来自结构化元数据、可解析文档示例或明确审核修订，并通过现有契约验证。包含上游经济字段的响应需先适配。

## 导入与发布

导入按产品锁串行化，契约编码包含产品、协议和 SKU，配置保存编译器版本与源摘要。发现任何既有同编码契约或同 slug 全局文档即跳过，保留已发布、人工编辑及历史版本，不自动覆盖。

每个新契约配套生成全局文档草稿。文档使用当前契约的结构及示例，价格说明引用当前租户有效 SKU，不写死某租户售价，不包含成本、渠道凭据或原始 provider 配置。

后续仍通过现有 `createVerification`、真实网关验收与 `publishContract`。发布需要已发布对应文档、相同契约摘要和示例指纹、完成结算的成功回执及独立积分流水核对。本编译器不生成任何验证回执。

## 本地覆盖快照与验证

2026-10-12 对本地租户 1 的只读编译结果：87 产品、179 有效 SKU、191 路径/SKU候选；7 个候选可形成草稿，184 个候选有阻断。可生成草稿产品为 51、52、54、56、57、58、63。此数量是编译能力快照，不是已导入或已发布数量，后续目录和适配变化应重新读取计划。

新测试 `tests/open-platform/market-compiler.php` 使用本地数据库守卫与事务回滚，覆盖字段映射、类型联合、明确审核修订、固定单调用、零售价计费、归属与异步缺口、上游经济字段、平台专属权限、导入幂等、保留人工编辑和禁止自动发布/执行。

```sh
docker exec -w /www/wwwroot/likeadmin_aigc_saas/server bt /www/server/php/82/bin/php tests/open-platform/market-compiler.php
```
