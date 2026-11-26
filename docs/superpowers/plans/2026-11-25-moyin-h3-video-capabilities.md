# 魔音 H3 视频生成能力修复交接

这项修复针对 LikeAdmin AIGC SaaS 的 `moyin` 渠道模型 `minimax-h3-768-ms` 和 `minimax-h3-1080p-c1`。修正后端返回的视频能力，让 PC「视频创作」显示 **文生视频**和**全能参考**。前一版试改的代码和测试已撤回，以下步骤从当前工作区基线执行。

## 业务合同

| 生成方式 | 模式值 | 输入 |
| --- | --- | --- |
| 文生视频 | `text_to_video` | 仅提示词作为创作内容，素材为空 |
| 全能参考 | `omni_reference` | 提示词与 1–9 张参考图，可搭配 0–3 段参考音频 |

图片参考和多帧参考统一归到全能参考。全能参考的图片角色为 `reference_image`，音频角色为 `reference_audio`。单张参考图和多张参考图走同一入口，至少有一张图才能添加音频。两款模型均无需显示首帧图生、独立尾帧、首尾帧、视频编辑或独立音频选项。上游驱动对参考视频、首帧和尾帧角色以及仅音频的内容组合已有校验。 [上游请求驱动](../../../../../likeadmin-api/server/app/common/service/ai/driver/MoyinH3VideoTaskDriver.php)

后端商品和可售 SKU 的 `generation_modes` 应依次返回 `['text_to_video', 'omni_reference']`。兼容字段 `input_modes` 也只给出这两种方式。素材能力应为 `supported_asset_types = ['image', 'audio']`、`max_reference_images = 9`、`max_reference_audios = 3`、`max_reference_videos = 0`、`max_reference_assets = 12`、`reference_audio_requires_visual = true`、`supports_first_last_frame = false`。

## 基线复现

在本地租户 1 上执行 PC 读取的视频配置请求。`--resolve` 将域名固定到本机。

```bash
curl --noproxy '*' -fsS --resolve z.cn:80:127.0.0.1 \
  'http://z.cn/api/app.aigc_video.config/detail?tenant_id=1&catalog_format=channels' |
  node -e 'const {data}=JSON.parse(require("node:fs").readFileSync(0,"utf8"));const codes=["minimax-h3-768-ms","minimax-h3-1080p-c1","h3-video"];for(const m of data.option_config.channels.filter(m=>codes.includes(m.model_code)))console.log(m.model_code,m.channel_code,m.generation_modes,m.supported_asset_types,m.supports_first_last_frame)'
```

2026-11-25 撤回改动后，768P 的配置接口仍返回 8 个模式，包括 `image_to_video`、`start_end`、`image_reference`、`multi_frame`、`video_edit` 和 `audio_reference`。可售 SKU 返回同样的模式，素材包含 `video`，首尾帧标志为 `true`。该本地租户目录未上架 1080P C1，可通过构造 `resource_type = model`、`upstream_channel_code = moyin` 和对应型号的隔离用例覆盖这款模型。

## 实现步骤

- [ ] 先为两个精确型号各写一个隔离用例，断言商品与 SKU 的模式、兼容字段、参考素材额度及首尾帧标志。为 `h3-video` 的 `minimax` 渠道补充首尾帧能力仍有效的对照断言。先运行用例，确认 768P 或合成商品在当前代码下因额外模式或素材能力而失败。
- [ ] 在 `MarketVideoRuntimeService` 声明两个 `moyin` 型号的能力，匹配市场商品的资源类型、渠道和精确型号。检查 `buildMetadata()`、`generationModes()`、`declaredGenerationModes()`、`inputModes()`、`supportedAssetTypes()` 和首尾帧标志的解析路径，让商品和 SKU 使用同一份能力。现有推断会从 `content` 或文档拆出素材方式，并对共享 H3 协议补出首尾帧和多帧参考。仅填入 `generation_modes` 仍可能进入补充模式的分支。 [后端解析](../../../app/common/service/power/MarketVideoRuntimeService.php)
- [ ] 核对 `sellableGenerationModes()`、`inputMode()` 和 `skuSupportsInputMode()`。全能参考带图时，内部 SKU 匹配会归类为 `image_reference`，这个值可以继续作为内部输入类别。目录和报价都应让文生视频和全能参考使用该型号现有的输出 SKU。对于历史 `image_reference` 与 `multi_frame` 请求，建议在准入时按普通参考图归入 `omni_reference`，避免影响历史重用。 [SKU 解析](../../../app/common/service/power/MarketVideoRuntimeService.php)
- [ ] 在 `assertAssets()` 和现有上游内容编码链路验证素材限制。出现 `first_frame_image` 或 `last_frame_image` 时拒绝，即使请求声明 `omni_reference`。检查省略生成方式时的仅音频输入，确保仍然要求参考图。检查准备后的 H3 `content`，文本与普通参考图和参考音频使用驱动认可的角色。 [素材准入和请求准备](../../../app/common/service/power/MarketVideoRuntimeService.php)
- [ ] 验证 PC 使用后端结果直接渲染。配置接口经 `AigcVideoService` 返回市场视频目录，`create.vue` 调用 `videoMethods()` 读取 `generation_modes`。用真实本地配置响应检查菜单、无首尾帧槽，以及普通单图和多图自动选择全能参考。业务代码改在 `server/`，`web/` 与 `likeadmin-api` 用于核对消费端和上游合同。 [配置接口](../../../app/api/controller/app/aigc_video/ConfigController.php) · [PC 创作页](../../../../web/pc/pages/ai/create.vue#L894) · [PC 模式解析](../../../../web/pc/apps/aigc_video/model-contract.ts#L92)

## 验收用例

| 用例 | 预期 |
| --- | --- |
| 两款模型的商品、SKU 和 PC 菜单 | 商品与 SKU 只声明两种方式，菜单按顺序显示「文生视频」「全能参考」，无首尾帧槽 |
| 纯提示词 | 文生视频报价匹配可售输出 SKU |
| 1 张图、9 张图、9 张图加 3 段音频 | 全能参考均可报价，与同规格文生视频匹配同一输出 SKU |
| 仅音频，包括省略生成方式的请求 | 准入拒绝并提示至少搭配一张参考图 |
| 10 张图、4 段音频、参考视频 | 准入拒绝 |
| 纯文生视频带素材，或请求首帧图生、首尾帧、视频编辑 | 准入拒绝 |
| 全能参考携带首帧或尾帧角色 | 准入拒绝 |
| 历史图片参考和多帧参考请求使用普通参考图 | 验证拟采用的兼容策略，新菜单仍只有两项 |
| `h3-video` 的 `minimax` 渠道 | 原有首尾帧能力仍可见 |

可以沿用 `tests/Feature/MarketVideoModelPayloadContractTest.php` 的用例风格，也可使用依赖 `vendor/autoload.php` 的独立 PHP 回归脚本。本地 `bt` 容器默认 `php` 为 8.0，SaaS 依赖要求至少 8.2，运行后端测试时使用 `/www/server/php/82/bin/php`。

```bash
docker exec -w /www/wwwroot/likeadmin_aigc_saas/server bt \
  /www/server/php/82/bin/php -l app/common/service/power/MarketVideoRuntimeService.php
docker exec -w /www/wwwroot/likeadmin_aigc_saas/server bt \
  /www/server/php/82/bin/php -d auto_prepend_file=vendor/autoload.php tests/video-schema-capabilities.php
git -C server diff --check
(cd web/pc && node --test tests/video-studio-model-contract.test.cjs tests/video-automatic-method.test.mjs)
```

前一版试改期间，768P 已通过本地 PC 配置接口、前端模式解析和只读报价用例，1080P C1 通过隔离素材合同用例。现已恢复基线，接手后的验收需重新执行。记录哪些型号使用真实本地配置，哪些使用隔离用例，以及跳过的真实生成测试。用本地隔离数据和只读报价即可验证本次调整，避免提交付费生成。
