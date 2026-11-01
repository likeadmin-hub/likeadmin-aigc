# 自有平台源码核对：8个计量规格的已知依据与硬缺项

用户提供的仓库为`https://gitee.com/yc_open/platform-api-saas.git`。本轮只读Git对象，未创建工作树／checkout，未执行上游代码、迁移或测试，未修改该仓库／现网。以下发现固定到develop提交`b5887e1c153111edd4515beb0635e6b66d9978b6`；master为`c57897b9d6e9555224dd298a4124f0c7da3673be`，不可混合为同一合同，更不代表线上已加载develop。

## 现有资料可以直接自提的部分

| 本地product／SKU | 精确身份／变体 | 已能从源码确认 | 仍未证明 |
| --- | --- | --- | --- |
| 275／380 | `official-33-c0b38c6fcb29f739b0 / official`，`qwen/image-edit` | 六尺寸数值映射、请求数量1–4、输出像素探测／整点ceil | 供应商实际输出尺寸／数量与收费硬上界；所有结果路径均实际测量 |
| 277／383 | `official-33-f3baf6c03742072d3a / official`，`qwen/text-to-image` | 六尺寸映射、冻结默认单张、输出像素与ceil | 同上，不能把默认1张当供应商保证 |
| 140／210 | Seedance，480p／含视频 | 原usage与冻结v2单价计算、旧任务矩阵 | 最大可收费Token或覆盖全部合法输入的可证上界公式 |
| 140／211 | Seedance，480p／无视频 | 同上 | 同上 |
| 140／212 | Seedance，720p／含视频 | 同上 | 同上 |
| 140／213 | Seedance，720p／无视频 | 同上 | 同上 |
| 140／214 | Seedance，1080p／含视频 | 同上 | 同上 |
| 140／215 | Seedance，1080p／无视频 | 同上 | 同上 |

275／277的原请求合同观察为matched；本轮没有将它们混同为另外两个`dashscope_compatible/qwen-image-3.0[-pro]`。后者driver的最大像素规则不能授予这两个official SKU。`OfficialCatalogService::modelCode`的source_model哈希与登记中两条完整编码精确一致；上游目录的`falPrice`是价格对照，不证明走Fal驱动。这两条actual profile为Kie jobs。

## 两条Qwen：请求估算域与实际费用必须分开

精确来源：

- `server/app/common/service/ai/official/OfficialCatalogService.php`（modelCode）；`server/resources/official/catalog.json`（两个source_model及字段）。
- `server/app/common/service/ai/official/OfficialMediaService.php`（facts／resultUsage）；`OfficialSkuPolicy.php`；`BillingMeterService.php`。
- `server/app/common/service/ai/driver/OfficialTaskDriver.php`（query结果usage分支）。

源码尺寸映射为512×512、1024×1024、768×1024、576×1024及相应两种横版，映射最大1,048,576像素／图；edit数量1–4。但这是请求／冻结估算范围，结果收集没有按请求数量／尺寸硬拒超输出，不能自动升级成实际收费保证。

还需单独修证的上游行为：`OfficialTaskDriver`先从result读取duration／output_duration／output_video_duration，有正duration时可能跳过resultUsage；`BillingMeterService::pricingMeasurement`在缺output_megapixels时可能沿用冻结megapixels估算。故不能仅凭存在像素探测函数就声称全部图片输出路径均按真实字节结算。本轮只登记，不改上游实际结算或真实价格。

## 六条Seedance：实际路径与缺量行为

精确来源：`server/app/common/service/ai/app/SeedanceDriver.php`的queryTask／pricingSnapshotOverridePoints、`BillingSkuService.php`、`PointsService.php`、`AiTaskDeliveryService.php`、`TaskService.php`、`TaskController.php`。

- 可确认原始`usage.total_tokens ÷ 1,000,000 × 冻结SKU单价`；优先冻结v2 SKU，round至四位，旧任务用原分辨率×视频输入矩阵。标准／快速、frames、adaptive、duration=-1等全部合法输入没有完整上界证明。
- `BillingSkuService`通用媒体1,000,000 Token默认预占和`PointsService`40,000 Token／秒估算，均不是供应商收费硬保证，不能抄成本地maximum_quantity。
- 原始供应商Token可保存在`data.result.usage.total_tokens`；交付过程仅汇总input_tokens/output_tokens时，公开顶层`data.usage.total_tokens`可能为0。路径须按实际同任务回执确认，不能按习惯绑定顶层。
- 缺total_tokens／usage时，driver及AppService可能回退整单位单价；不能将上游行为登记为“缺量必保留待核对”。本地SaaS已有严格缺量保护是本地行为，不证明上游相同。

## 下一步和当前边界

由开发整理两Qwen的源码候选及上界／超输出／缺探测反例；六Token继续保留`blocked_missing_token_budget`，直到有正式可证最大量／完整公式与实际回执政策。现有本地只读观察和源码资料用于缩小缺口，不启用SKU、不调整价格／账务、不发生成或查询任务。

平台源码已提供，不再向用户索要尺寸表、schema或手填Token数字。仅在对应规则与供应商保证确实缺失时，形成精确内部平台处理事项；部署版本、DB冻结和真实回执仍各自核对，不把源代码存在当最终线上认证。
