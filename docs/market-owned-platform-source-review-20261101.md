# 自有算力平台：18 个精确模型的源码依据与剩余缺项

日期：2026-11-01。范围：用户提供的 `yc_open/platform-api-saas` 平台仓库，只读审查请求、模式、素材和结果合同。没有执行平台代码、迁移、测试、生成或真实任务／账单查询，没有同步目录、价格或启用合同。

## 本轮结论

原登记中的 **18 个模型是“完整合同待核验”的精确身份，不是 18 个平台都没有资料的模型**。现在已经找到平台自己的文档、迁移声明、路由器和实际 driver；应由开发智能体从这些来源整理候选并核对部署，无需让用户填写 schema 或转交一长串技术字段。

- Qwen 3 两款、Q4 的专用校验器可直接提供嵌套请求和多项条件依据。
- H3 的可执行校验已经解释“文本数量”和“尾帧配对”歧义；Wan 的素材类型、数量、互斥和时长默认值也有实际实现。
- OpenAIYI Flare／Sunburst、openaiD Fast 的执行尺寸矩阵已经找到。Fast 的两格公开文档与执行代码冲突，需要明确记录。
- Google Nano、两渠道 GPT Image 2、Grok／VEO 等仍需结合当前保存的模型／渠道配置。通用 driver 没有强制某项限制，不能据此宣布供应商没有限制。

**本轮没有生成、编译或启用新的 reviewed contract，也没有完成线上生成认证。** 下表中的“已找到”是源码与正式文档证据，不代表本地合同已导入。

**2 个像素／6 个 Token SKU 的正式计量依据是独立事项。** 本文不将其与 18 个请求合同混为一项，也不将普通 Qwen 3 的尺寸上界借给 official Qwen 资源。8 个 SKU 的详细计量审查由协调者另附。

## 固定来源与版本边界

主要依据是 [source-develop 固定版本][develop]：

```text
repository: https://gitee.com/yc_open/platform-api-saas.git
source-develop: b5887e1c153111edd4515beb0635e6b66d9978b6
source-master:  c57897b9d6e9555224dd298a4124f0c7da3673be
```

以下链接均固定到 `b5887e1c153111edd4515beb0635e6b66d9978b6`，避免把后续分支变动算作同一份依据。平台文件不是 SaaS 仓库内的同名文件。

共同尚待完成的是：由开发方核对实际服务网关的 commit 或相关文件 SHA256，以及当前模型／渠道绑定、`params_schema`、`default_params.api_config`、渠道 `extra_config`、实际 SKU 绑定。迁移脚本存在不证明迁移已执行；公开目录相同不证明 driver 已部署；文档修改时间也不等于网关版本。核对时只需脱敏业务元数据，不能导出密钥、客户素材或无关账单。

### master 与 develop 的重要差异

已只读对比两个固定版本：

| 表面 | source-develop 相对 source-master 的实际差异 | 对合同提取的影响 |
| --- | --- | --- |
| [DashScopeQwenImageDriver][qwen-driver] | develop 新增整个文件 | master 没有同等的 Qwen 嵌套 validator，不能混用版本。 |
| [ViduQ4VideoDriver][q4-driver] | develop 新增整个文件 | Q4 的严格字段校验和结果解析须绑定 develop 中的实际实现。 |
| [VideoTaskDriver][video-driver] | develop 新增 H3 深校验、Wan defaults／深校验、结果与 usage 解析等 | 不能把 develop 条件说成 master 或已部署网关也已具备。 |
| [DrawDriver][draw-driver] | develop 新增尺寸变换／校验、参考图配置分支、更多结果包装解析 | Fast／Dakka 矩阵及分支须与执行版本对应。 |
| [TaskController][task-controller] | develop 新增 Qwen／Q4／official preflight、JSON 布尔值保留、更多提交／结果逻辑 | 请求校验发生在哪一层、何时发生，随版本不同。 |
| 相关迁移与模型文档 | 多个文件相对 master 有变更 | 不能跨版本拼出一份“当前正式合同”。 |

本次以 develop 为审查基线，不将 master 当作默认线上版本，也不认定 develop 已经部署。

## 18 个精确身份逐项表

表内的型号和渠道独立绑定。同名模型、其他渠道、official 资源或应用 API 均不能互相替代。

| 精确 model / channel | 固定源码／文档来源 | 已找到、可自行提取的规则 | 仍缺或须核对的具体内容 |
| --- | --- | --- | --- |
| `nano-banana-2.1` / `Google` | [独立文档][nano21-doc]；[独立迁移 SQL][nano21-sql]；[DrawDriver::buildRequestParams][draw-driver] | 文生图／参考图编辑；`urls[]` 为 HTTP(S) 图片字符串；`auto` 与 10 种公开比例；`1K/2K/4K`；独立按次 SKU；图片转存后交付。SQL 明确写入 `urls.children.item`。 | 基础 schema 从运行 DB 的 Nano 2 复制，须绑定实际声明；参考图数量、文件边界与空 prompt 的模式条件未有完整规则。文档的 1:1 尺寸是示例，不能推成所有比例的像素硬上限。 |
| `nano-banana-2` / `Google` | [model-63 文档][nano2-doc]明确 Google；[canonical 映射修正][nano-mapping]；[DrawDriver][draw-driver] | `urls` 为图片 URL 字符串列表；文生／参考编辑；`prompt/image_size/aspect_ratio`；`1K/2K/4K`；canonical 映射为 `urls → urls`。 | 当前 Google 渠道的实际映射、默认值及完整参考素材限制。`NanoBananaMigrate` 曾创建 `nano_banana` 渠道，不能拿该渠道代替 Google。 |
| `nano-banana-pro` / `Google` | [model-66 文档][nanopro-doc]；[canonical 映射][nano-mapping]；[DrawDriver][draw-driver] | 与 Nano 2 同组公开字段、分辨率和比例；参考项为图片 URL 字符串。 | Pro 的实际配置、数量和文件／模式条件独立核对，不借 official／VIP 同名合同。 |
| `gpt-image-2.5` / `OpenAIYI` | [DakkaGptImage25Migrate][dakka-migrate]：`MODELS/CAPABILITIES/upsertModel/syncDakkaParams/developerDocContent`；[DrawDriver][draw-driver] | 代码明确绑定 OpenAIYI；继承该渠道 GPT Image 2；`quality=auto`，无 background；公开 `resolution=1k/2k/4k`、`n=1`、参考 URL 数组。 | 继承的是实际 DB 父模型配置，须取得当前原始声明和最终映射；参考图数量／文件边界、原生 n 的实际映射与组合条件仍需整理。 |
| `gpt-image-2.5-flare` / `OpenAIYI` | [Dakka 迁移][dakka-migrate]：`CAPABILITIES/usesDakkaPixelMatrix`；[DrawDriver::dakkaGptImageSize/validateMappedParams][draw-driver] | `quality=low/medium/high`；`background=transparent`；实际比例 × `1k/2k/4k` 矩阵；`1:3/3:1` 没有 4k 映射，会被映射结果校验拒绝。 | 当前继承配置、完整参考素材限制及本型号 SKU／声明绑定。此矩阵不能套到普通 2.5 或其他渠道。 |
| `gpt-image-2.5-sunburst` / `OpenAIYI` | [Dakka 迁移][dakka-migrate]的独立 capability 条目；[DrawDriver::dakkaGptImageSize][draw-driver] | quality 另有 `xhigh/max`；transparent；尺寸矩阵和不支持组合可提取。 | 与 Flare 分开绑定质量档、实际配置和 SKU；仍需完整参考素材限制。 |
| `gpt-image-2` / `OpenaiM` | [model-94 文档][gpt2m-doc]；[GptImage2OpenaiMModelParamsMigrate][gpt2m-migrate]：`buildParamsSchemaJson/buildModelDefaultParamsJson/buildDrawConfigJson`；[用户参数对齐迁移][gpt-align]；[DrawDriver][draw-driver] | 公开参考图最多 16；HTTP(S)／data URI；`n=1`；模型级 resolution 映射；历史 image_size 说明字段；可用顶层 results 交付。 | 4k × 比例的完整可用组合没有执行矩阵，说明仅称部分组合可能拒绝；当前模型／渠道递归覆盖后的配置须核对。 |
| `gpt-image-2` / `OpenAIYI` | [model-96 文档][gpt2yi-doc]；[OpenAIYI 专用迁移][gpt2yi-migrate]；[Dakka CAPABILITIES][dakka-migrate]；[DrawDriver][draw-driver] | 精确渠道、参考 URL 类型、比例／resolution 展示；quality 为 auto、无 background；独立 draw/completions 映射。 | 参考数量／文件限制、实际 resolution 映射和最终父配置／组合条件。不能借用 OpenaiM 的 16 张或 4k 规则。 |
| `gpt-image-2-fast` / `openaiD` | [Fast 文档][fast-doc]；[OpenaiDGptImage2FastMigrate::paramsSchema/defaultParams][fast-migrate]；[DrawDriver::gptImage2FastSize/validateMappedParams][draw-driver] | 文档 prompt ≤5000 字符；参考图可 string 或 array；quality `low/medium/high`；完整执行尺寸矩阵；`size/resolution/image` 别名归一；实际参数映射。 | 文档两格与执行代码冲突，见下节；参考图数量／文件边界未完整；5000 字符是公开限制，通用 driver 没有相同长度强制代码。 |
| `gpt-image-2-pro` / `OpenaiM` | [Pro 文档][gptpro-doc]；[OpenaiMGptImage2ProMigrate::paramsSchema/defaultParams][gptpro-migrate]；[DrawDriver][draw-driver] | 公开最多 16 参考图、`n=1`、`1k/2k/4k`、mask_url；公开型号映射到 `gpt-image-2-official`；结果可顶层 results。 | mask 与参考图必需／尺寸一致／格式等完整条件未给出；实际覆盖配置与 SKU 绑定仍须核对。 |
| `qwen-image-3.0-pro` / `dashscope_compatible` | [BailianWanQwenMigrate::qwenModel][bailian-migrate]；[Pro 文档][qwenpro-doc]；[DashScopeQwenImageDriver][qwen-driver]：`validateRequest/normalizePublicRequest/validSize/queryTask` | 可直接整理嵌套候选：仅 1 条 user 消息、恰 1 text、0–3 image；单项 text/image 互斥；n=1；seed `0..2147483647`；参考图禁 agent；URL／data URI；像素 `512²..2048²`、比例 `1:8..8:1`；choices 到结果列表的解析。 | 当前 DB 的 nested／flat 声明及版本须比对；文档中的 bool／枚举／格式限制和 driver 未强制部分应标明层次。此上界不属于 official 产品 275／277。 |
| `qwen-image-3.0` / `dashscope_compatible` | [Bailian qwenModel][bailian-migrate]的独立 code；[Base 文档][qwen-doc]；[QwenImage3Migrate::paramsSchema/defaultParams][qwen-flat-migrate]；[专用 driver][qwen-driver] | 同一专用 validator 的嵌套结构和条件；还找到后续 flat `prompt/image_urls/aspect_ratio` 到 nested 的转换及 1k／2k 尺寸映射。 | 当前公开 nested 声明与后续 flat 迁移不能假定同时已应用；Base 与 Pro 的原始声明、ID 和 SKU 分别绑定。 |
| `q4-preview-video` / `q4_video` | [ViduQ4VideoDriver::validateRequest/requestBody/queryTask][q4-driver]；[ViduQ4Migrate][q4-migrate]指向独立 SQL；[Q4 文档][q4-doc] | 恰 1 首帧；`3..16` 整数秒；`540p/720p/1080p/2K/4K`；audio/is_rec/watermark 为严格 bool；seed 非负 int；wm_position `1..4`；prompt ≤20000、payload ≤1048576 字符；meta_data 为合法 JSON；请求体 <20MiB；creations 结果规范化。 | 已可整理有明确源码依据的候选；须确认实际 driver=vidu_q4、默认值、五档 SKU 与部署。输入图真实 MIME／尺寸／供应商限制不能以 URL 正则冒充实测。 |
| `h3-video` / `minimax` | [MinimaxH3Migrate::paramsSchema/defaultParams][h3-migrate]；[VideoTaskDriver::validateConfiguredVideoParams][video-driver]；[H3 文档][h3-doc]；已核验原 ID 文档 model99 | 至少 1 个非空 text，**不是恰 1 个**；尾帧须首帧；无 role 图片计首帧；最多 1 首／1 尾／9 参考图／3 视频／3 音频；帧与参考互斥；音频须配图或视频；`4..15` 秒；纯文本禁 adaptive。 | driver 的分辨率包含 1080P，发布 schema／SKU 只有 768P／2K，应取实际准入交集；文档参考媒体每段／总长限制没有网关实测代码；首帧 ratio 强改 adaptive 也未在该 driver 找到。 |
| `grok-video` / `xAIQ` | [model-71 文档][grok-doc]明确 xAIQ；[VideoTaskDriver][video-driver]；[GrokVideoMigrate][grok-migrate]作历史对照 | prompt；720p；duration `6/10/15/20/25/30`；参考图 ≤7；`2:3/3:2/1:1/9:16/16:9`；文生／图生。 | 当前 xAIQ 的实际 `extra_config` 须提取；通用 driver 没有完整素材深校验。迁移创建的是 `xai_grok_video`，不能自动成为当前 xAIQ 的绑定证明。 |
| `veo3.1-fast` / `xAIQ` | [model-72 文档][veofast-doc]；[VeoVideoMigrate 的 schema／defaultParams][veo-migrate]；[VideoTaskDriver][video-driver] | 固定 8 秒；quality `720p/1080p/4k`；TEXT／FIRST&LAST／REFERENCE；首尾模式 1–2 图、参考 ≤3 且仅 16:9；公开比例 16:9／9:16。 | 当前精确 xAIQ 配置；TEXT 含图／各模式互斥等没有完整执行 validator，文档示例也存在 TEXT 带图，不能自行补拒绝条件；型号／质量 SKU 分别核对。 |
| `veo3.1-pro` / `xAIQ` | [model-73 文档][veopro-doc]；[Veo 迁移中的 Pro 独立条目][veo-migrate]；[VideoTaskDriver][video-driver] | 同组模式与素材限制；Pro 独立质量计价条目。 | 同 Fast 的模式条件与部署配置问题；不得使用 Fast 的 SKU 认证 Pro。 |
| `wan3.0-video` / `dashscope_compatible` | [BailianWanQwenMigrate::wanModel][bailian-migrate]；[Wan 文档][wan-doc]；[VideoTaskDriver::applyDashScopeVideoDefaults/validateDashScopeVideoRequest/validDashScopeMediaUrl][video-driver] | prompt／media 至少一项；prompt ≤20000；7 类 media 上限 `1/1/10/5/5/1/1`；帧与参考／file／link 互斥，file/link 互斥；无视频 `2..30`／有视频 `1..30` 或 `-1`；省略 duration 自动 2／1；480P／720P／1080P；bool／seed；HTTP(S)／OSS，图片另可 data URI。 | 参考视频／音频单段 `1..15` 且各总长 ≤15 是文档条件，driver 没有实测；`-1` 实际时长及计量边界仍须正式依据。该 validator **没有要求尾帧必须配首帧**，不能从 H3 借规则。 |

### Fast 尺寸表的明确冲突

| `openaiD/gpt-image-2-fast` 组合 | 固定版本文档 | 固定版本执行代码 |
| --- | --- | --- |
| 3:1 × 1K | `1881x836` | `2016x672` |
| 1:3 × 1K | `887x1774` | `672x2016` |

文档位置为 [model-gpt-image-2-fast.md:82–83][fast-doc]；执行位置为 [DrawDriver::gptImage2FastSize][draw-driver]。候选应从实际执行矩阵提取并保留冲突记录，不能把 Markdown 两格直接编成准入规则，也不能为解决该冲突而改用 OpenAIYI 的 Dakka 矩阵。

## 请求和结果合同的共同事实

这些规则来自固定版本的实际实现，不由示例推断：

1. [TaskController::create/extractTaskBusinessParams/taskSchemaValidationParams/mergeModelDefaultTaskParams][task-controller]：JSON 直接解析保留 bool；存在数组 `params` 时，仅使用该数组的业务字段，空数组也走此分支；平铺业务字段排除 model/channel/callback_url/storage；model/channel 另补回一级 schema 校验；只合入 schema 一级同名的模型默认值。
2. [ParamsSchemaValidateService::validateRequestFirstLevel][first-level]：只校验一级 required、type、enum，未声明字段透传；**不会递归执行 items、媒体计数、长度、条件，也不会把展示用 options 自动当 enum**。integer 允许整数数字字符串，boolean 要求原生 bool。不能把该服务包装成完整 JSON Schema validator。
3. [ModelRouterService::createDriver][router]：渠道 extra_config 与模型 `default_params.api_config` 递归覆盖，模型优先；还存在模型 channel_mapping 和 official 分类。最终 driver 不由展示型号名称决定。公开默认值会去掉内部配置，单看公开文档不能恢复所有实际路由参数。
4. [DrawDriver][draw-driver]：参考图可触发 `when_reference/reference_config`；先做旧字段归一，再按映射构造供应商请求，未映射字段并不保证透传；`omit_n_in_body` 等配置会影响原生数量。其尺寸校验只针对指定 transform，不能替代全部图片请求校验。
5. [VideoTaskDriver][video-driver]：H3／Wan 深校验由配置标记开启；其他视频继续其原有通用路径。不能给 Grok／VEO 自动套上 H3／Wan 条件。
6. 结果由 driver 到平台响应还有一层投影。Draw 将指定图片形状转为 `result.results`；Qwen 从 `output.choices[].message.content[].image` 提取图片；Video 可从 video_url、outputs、content.url 补齐视频；Q4 从 creations 形成 video_url／creations。这是解析能力，不能证明供应商每次都返回完整回执。
7. [TaskController::query/applyTaskQueryResponseShape][task-controller]：查询响应默认在 `data.result`，图片模型可因 `default_params.task_query.image_results_top_level=true` 改为 `data.results`。GPT 类文档写 result.results，不代表运行配置一定如此。usage 展示的是平台任务持久值，不能仅因存在 usage 对象就认定有供应商权威计量证据。

## 已有机器 manifest 能证明什么

[api-doc/online-developer-docs/manifest.json][historical-manifest] 自述来源为 `online xh_ai_developer_doc published snapshot`，`exported_at=2026-08-15T01:33:58.423Z`，包含 document_id、ai_model_id、model_code、slug、文件路径、内容长度和时间字段。它可定位历史文档与资源身份，**不是当前线上 schema／driver／SKU 的正式整体导出**。不能把这个时间字段当所有文件的最新发布时间或部署版本。

其中与本任务有关的历史平台 model ID：

| 精确资源 | 历史平台 model ID |
| --- | ---: |
| Google / nano-banana-2 | 63 |
| Google / nano-banana-pro | 66 |
| OpenaiM / gpt-image-2 | 94 |
| OpenaiM / gpt-image-2-pro | 98 |
| OpenAIYI / gpt-image-2 | 96 |
| openaiD / gpt-image-2-fast | 97 |
| dashscope_compatible / qwen-image-3.0-pro | 100 |
| dashscope_compatible / qwen-image-3.0 | 102 |
| dashscope_compatible / wan3.0-video | 101 |
| minimax / h3-video | 99 |
| xAIQ / grok-video | 71 |
| xAIQ / veo3.1-fast | 72 |
| xAIQ / veo3.1-pro | 73 |

这些不是 SaaS 的 product ID，也不能用于猜本表其余型号的 ID。manifest 还列另一渠道的 Grok，不能混入 xAIQ。

[DeveloperDocPublicService::buildApiPricingForTenant/buildModelPricingForTenant][doc-public] 也已核对：api_pricing 使用 id、app_id、app_code、code，未主动添加 app bind_type；model_pricing 明确添加 `bind_type=model`、model_id、model_code。此前客户端不猜 app bind_type 枚举的边界有真实源码支持。

## 下一步机器化整理方式

以下是开发执行步骤，未在本轮运行：

1. **固定原始证据**：以同一 commit 的 driver、controller、router、validator、相关声明／迁移／文档组成只含业务合同的原始证据集，记录逐文件 Git blob／SHA256、函数和版本；不 `require` 或执行上游类／迁移。master 与 develop 分开保存，不拼接。
2. **绑定当前声明**：从已保存的精确模型／渠道快照核对平台 ID、原始 params_schema 和 material_capabilities，以及可取得的最终非敏感执行映射。复制型迁移必须读取实际源声明，不能猜它当时的 DB 内容。文档与代码冲突、尚未部署的迁移、缺失字段分别登记。
3. **逐身份生成候选**：使用既有 `gateway_validator` 来源，reference 指向固定 Git 版本、revision 为完整 commit SHA，原始证据摘要独立保存；将一级 required 标记和实际深层分支整理为模式 schema／rules，素材路径、角色、默认行为与数量保持精确。保留未强制与未定义项，不把 options、示例、其他渠道规则或 UI 估算升级成正式限制。
4. **使用现有本地工具审核**：由协调者按仓库执行规则集成 develop 后，使用 `tools/market/prepare-generation-contract-candidates.php`，再独立审核 `tools/market/compile-reviewed-generation-contracts.php --reviewed`。binding_declaration 保留真正原始声明，不能用补齐后的 schema 冒充。两工具不执行上游代码、不访问 HTTP／DB、不自动启用配置；它们适用于模型合同，不替代应用 API 或计量合同导入。
5. **保持门槛独立**：请求合同、精确 SKU 计量、发布绑定、真实供应商验收分别结案。Qwen3 的专用尺寸规则不能解除 official 两个像素 SKU；找到通用 usage 提取函数也不能解除 Seedance 六个 Token SKU。

据此，后续状态应从笼统“18 个都等外部资料”改成逐项“源码已取得／候选待整理／部署待绑定／具体条件仍未闭合”。只有完成真实证据绑定、合同编译与相应验收后，才可更改正式准入状态。

[develop]: https://gitee.com/yc_open/platform-api-saas/tree/b5887e1c153111edd4515beb0635e6b66d9978b6
[task-controller]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/api/controller/v1/TaskController.php
[first-level]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/service/ai/ParamsSchemaValidateService.php
[router]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/service/ai/ModelRouterService.php
[draw-driver]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/service/ai/driver/DrawDriver.php
[video-driver]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/service/ai/driver/VideoTaskDriver.php
[qwen-driver]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/service/ai/driver/DashScopeQwenImageDriver.php
[q4-driver]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/service/ai/driver/ViduQ4VideoDriver.php
[nano21-doc]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/api-doc/model-api/nano-banana-2.1.md
[nano21-sql]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/public/install/db/ai_platform_migration_nano_banana_21.sql
[nano2-doc]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/api-doc/model-api/model-63-nano-banana-2.md
[nanopro-doc]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/api-doc/model-api/model-66-nano-banana-pro.md
[nano-mapping]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/command/FixNanoBananaDrawUpstreamMapping.php
[dakka-migrate]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/command/DakkaGptImage25Migrate.php
[gpt2m-doc]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/api-doc/model-api/model-94-gpt-image-2.md
[gpt2m-migrate]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/command/GptImage2OpenaiMModelParamsMigrate.php
[gpt-align]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/command/GptImage2UserParamsAlignMigrate.php
[gpt2yi-doc]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/api-doc/model-api/model-96-gpt-image-2.md
[gpt2yi-migrate]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/command/OpenAIYIGptImage2Migrate.php
[fast-doc]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/api-doc/model-api/model-gpt-image-2-fast.md
[fast-migrate]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/command/OpenaiDGptImage2FastMigrate.php
[gptpro-doc]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/api-doc/model-api/model-gpt-image-2-pro.md
[gptpro-migrate]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/command/OpenaiMGptImage2ProMigrate.php
[bailian-migrate]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/command/BailianWanQwenMigrate.php
[qwen-doc]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/api-doc/model-api/model-qwen-image-3.0.md
[qwenpro-doc]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/api-doc/model-api/model-qwen-image-3.0-pro.md
[qwen-flat-migrate]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/command/QwenImage3Migrate.php
[q4-migrate]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/command/ViduQ4Migrate.php
[q4-doc]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/api-doc/model-api/model-q4-preview-video.md
[h3-migrate]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/command/MinimaxH3Migrate.php
[h3-doc]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/api-doc/model-api/model-h3-video.md
[grok-doc]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/api-doc/model-api/model-71-grok-video.md
[grok-migrate]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/command/GrokVideoMigrate.php
[veo-migrate]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/command/VeoVideoMigrate.php
[veofast-doc]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/api-doc/model-api/model-72-veo3.1-fast.md
[veopro-doc]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/api-doc/model-api/model-73-veo3.1-pro.md
[wan-doc]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/api-doc/model-api/model-wan3.0-video.md
[historical-manifest]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/api-doc/online-developer-docs/manifest.json
[doc-public]: https://gitee.com/yc_open/platform-api-saas/blob/b5887e1c153111edd4515beb0635e6b66d9978b6/server/app/common/service/ai/DeveloperDocPublicService.php
