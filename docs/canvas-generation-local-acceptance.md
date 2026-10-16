# 画布生成链路本地验收（2026-10-16）

分支 `feature/agent-optimization`，本地租户 1、数据库 x_cn。未提交、预占、扣费或执行真实生成。

## 修复

- 音乐请求保留普通/自定义模式、歌词、人声、风格和纯伴奏，按市场契约使用 lyric；任务快照保存 schema/defaults，未声明 duration 的入口不发送时长。旧任务快照及短剧默认 BGM 行为保留。SKU 锁定操作不允许 provider_params 覆盖。
- 图片目录补齐已有适配器的 8 个 Nano Banana 应用模型，保留既有 12 个模型 API。
- 前端音乐时长跟随所选 SKU，不再虚构 30–300 秒列表。重复点击运行拦截；不可用模型保留原结果并提示。
- 文本断流不盲目重新提交；已取得任务 ID 时查询原任务。仅明确 404/405 的流式端点允许非流式回退。
- 图片/视频/音频同步及轮询回填全部结果，保留旧素材、存储元数据，重复轮询不重复追加。音频改为提交后通过任务 ID 后台轮询。

## 验证

- audit_local_market_generation.php 在数据库只读事务内验证 36 个入口、304 组 SKU/模式的报价和请求构造，全部通过，无上游网络调用。
- 既有图片目录契约验证 20 个入口、561 个规格组合通过，包含参考图/提示词/规格映射。
- PHPUnit 模型/协议/能力契约 61 项、278 个断言通过。
- 8 份独立契约通过：图片目录、视频模式、视频应用、Seedance 素材、文本多模态、文本路由、文本流、音乐请求。三份旧断言更新为当前 typed content、Anthropic content blocks 和解析后的 vision 变量；没有改动这些已验证运行协议。
- 前端 169 项画布回归通过；Nuxt 生产构建通过，已有重复组件名、弃用和 compatibilityDate 提示保留。
- 没有 API 路径、迁移、权限或任务状态契约变更；Agent 和整图自动执行未开发。PHP 执行限制保持不变。

## 验收边界

未做真实付费生成，不能声称每个上游模型已经实测稳定。示例素材可达性、上游排队、生成质量、长任务恢复、回调、失败退款、成功结算以及限流/超时，需要在明确允许的模型与费用范围内真实验收。音乐当前接入 type=generate；续写/翻唱等 operation 不属于现有画布入口。本轮没有增加市场所有可选高级字段的编辑界面。

## 当前目录矩阵

通过数仅表示离线构造通过；同名入口保留各自 SKU/协议。字段来自适配器输出，不包含凭据、提示词或真实素材地址。

| 类型 | 入口 | 标识 | 通过组合 | 生成模式 | 请求字段 |
| --- | --- | --- | ---: | --- | --- |
| text | kimi-k3 | 12 | 1 | default | max_tokens, messages, model |
| text | GPT-5.6 Terra | 11 | 1 | default | input, instructions, max_output_tokens, model |
| text | DeepSeek-V4-Flash | 4 | 1 | default | max_tokens, messages, model |
| text | DeepSeek-V4-Pro | 3 | 1 | default | max_tokens, messages, model |
| image | Nano-Banana 2.1 | market_image_model:234 | 1 | default | aspect_ratio, channel, idempotency_key, image_size, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, sku_id, sku_key |
| image | GPT Image 2.5 | market_image_model:169 | 3 | default | aspect_ratio, channel, idempotency_key, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, resolution, sku_id, sku_key |
| image | GPT Image 2.5 Flare | market_image_model:168 | 3 | default | aspect_ratio, channel, idempotency_key, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, resolution, sku_id, sku_key |
| image | GPT Image 2.5 Sunburst | market_image_model:167 | 3 | default | aspect_ratio, channel, idempotency_key, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, resolution, sku_id, sku_key |
| image | Nano-Banana 2 | market_image_model:93 | 1 | default | aspect_ratio, channel, idempotency_key, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, sku_id, sku_key |
| image | Nano-Banana Pro | market_image_model:92 | 1 | default | aspect_ratio, channel, idempotency_key, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, sku_id, sku_key |
| image | GPT Image 2 | market_image_model:91 | 3 | default | aspect_ratio, channel, idempotency_key, image_size, market_product_id, market_sku_id, market_sku_key, model, n, price_source, pricing_sku_key, prompt, resolution, sku_id, sku_key |
| image | GPT Image 2 | market_image_model:90 | 3 | default | aspect_ratio, channel, idempotency_key, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, resolution, sku_id, sku_key |
| image | GPT Image 2 Fast | market_image_model:89 | 3 | default | aspect_ratio, channel, idempotency_key, image_size, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, sku_id, sku_key |
| image | GPT Image 2 Pro | market_image_model:88 | 3 | default | aspect_ratio, channel, idempotency_key, image_size, market_product_id, market_sku_id, market_sku_key, model, n, price_source, pricing_sku_key, prompt, sku_id, sku_key |
| image | Qwen Image 3.0 Pro | market_image_model:87 | 2 | default | channel, idempotency_key, input, market_product_id, market_sku_id, market_sku_key, model, parameters, price_source, pricing_sku_key, sku_id |
| image | Qwen Image 3.0 | market_image_model:86 | 2 | default | channel, idempotency_key, input, market_product_id, market_sku_id, market_sku_key, model, parameters, price_source, pricing_sku_key, sku_id |
| image | Nano Banana | market_nano_banana:110:bmFuby1iYW5hbmE= | 1 | default | action, aspect_ratio, idempotency_key, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, resolution, sku_id, sku_key |
| image | Nano Banana 2 | market_nano_banana:110:bmFuby1iYW5hbmEtMg== | 1 | default | action, aspect_ratio, idempotency_key, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, resolution, sku_id, sku_key |
| image | Nano Banana 2 Lite | market_nano_banana:110:bmFuby1iYW5hbmEtMi1saXRl | 1 | default | action, aspect_ratio, idempotency_key, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, resolution, sku_id, sku_key |
| image | Nano Banana 2 Lite Official | market_nano_banana:110:bmFuby1iYW5hbmEtMi1saXRlOm9mZmljaWFs | 1 | default | action, aspect_ratio, idempotency_key, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, resolution, sku_id, sku_key |
| image | Nano Banana 2 Official | market_nano_banana:110:bmFuby1iYW5hbmEtMjpvZmZpY2lhbA== | 3 | default | action, aspect_ratio, idempotency_key, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, resolution, sku_id, sku_key |
| image | Nano Banana Official | market_nano_banana:110:bmFuby1iYW5hbmE6b2ZmaWNpYWw= | 1 | default | action, aspect_ratio, idempotency_key, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, resolution, sku_id, sku_key |
| image | Nano Banana Pro | market_nano_banana:110:bmFuby1iYW5hbmEtcHJv | 1 | default | action, aspect_ratio, idempotency_key, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, resolution, sku_id, sku_key |
| image | Nano Banana Pro Official | market_nano_banana:110:bmFuby1iYW5hbmEtcHJvOm9mZmljaWFs | 3 | default | action, aspect_ratio, idempotency_key, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, resolution, sku_id, sku_key |
| video | Q4 Preview 图生视频 | market_video_model:235 | 5 | image_to_video | audio, channel, duration, idempotency_key, images, is_rec, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, resolution, seed, sku_id, sku_key, watermark, wm_position |
| video | H3 视频生成 | market_video_model:98 | 16 | text_to_video, omni_reference, image_to_video, start_end, image_reference, video_edit, multi_frame, audio_reference | content, duration, model, ratio, resolution |
| video | Grok Video | market_video_model:97 | 18 | text_to_video, image_to_video, image_reference | aspect_ratio, channel, duration, idempotency_key, image_urls, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, quality, sku_id, sku_key |
| video | VEO 3.1 Fast | market_video_model:96 | 12 | text_to_video, image_to_video, start_end, image_reference | aspect_ratio, channel, duration, generation_type, idempotency_key, image_urls, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, quality, sku_id, sku_key |
| video | VEO 3.1 Pro | market_video_model:95 | 12 | text_to_video, image_to_video, start_end, image_reference | aspect_ratio, channel, duration, generation_type, idempotency_key, image_urls, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, quality, sku_id, sku_key |
| video | Wan 3.0 Video | market_video_model:94 | 24 | text_to_video, omni_reference, image_to_video, start_end, image_reference, video_edit, multi_frame, audio_reference | channel, idempotency_key, input, market_product_id, market_sku_id, market_sku_key, model, parameters, price_source, pricing_sku_key, sku_id, sku_key |
| video | Seedance 2.0 | market_video_app:seedance:140 | 42 | text_to_video, omni_reference, image_to_video, start_end, image_reference, video_edit, multi_frame | content, duration, idempotency_key, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, ratio, resolution, sku_id, sku_key |
| video | Wan 视频生成 | market_video_app:wan:133 | 42 | text_to_video, omni_reference, image_to_video, start_end, image_reference, video_edit, multi_frame | duration, idempotency_key, image_with_roles, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, resolution, size, sku_id, sku_key, video_urls |
| video | Grok 视频生成 | market_video_app:grok_video:108 | 36 | text_to_video, omni_reference, image_to_video, image_reference, video_edit, multi_frame | aspect_ratio, duration, idempotency_key, image_urls, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, prompt, resolution, sku_id, sku_key |
| video | Happy Horse | market_video_app:happy_horse:105 | 12 | text_to_video, omni_reference, image_to_video, image_reference, video_edit, multi_frame | duration, idempotency_key, market_product_id, market_sku_id, market_sku_key, media, model, price_source, pricing_sku_key, prompt, ratio, resolution, sku_id, sku_key |
| video | 全能视频生成 | market_video_app:full_video:103 | 40 | text_to_video, omni_reference, image_to_video, start_end, image_reference, video_edit, multi_frame, audio_reference | content, duration, idempotency_key, market_product_id, market_sku_id, market_sku_key, model, price_source, pricing_sku_key, ratio, resolution, sku_id, sku_key |
| music | 生成音乐（type=generate） | 167 | 1 | default | custom, idempotency_key, instrumental, lyric, market_product_id, market_sku_id, market_sku_key, price_source, pricing_sku_key, prompt, sku_id, sku_key, style, title, type |
