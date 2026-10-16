# 画布生成链路本地验收（2026-10-16）

任务：补齐节点高级参数与生成模式，核对参数传递、结果回填和异常处理。
分支：`feature/agent-optimization`。本地租户 1、数据库 x_cn。未提交、预占、扣费或执行真实生成。

## 本轮完成

- 四类节点按当前模型 schema 展示高级参数：文本最大输出长度；图片渲染质量、背景、水印和提示词扩展；视频种子、音频、水印等；音乐风格影响、人声风格、歌词提示等。逐入口字段见下表，不虚构未声明能力。
- 参数按模型隔离保存，支持恢复模型默认、范围/枚举校验和 SKU 锁定。图片渲染质量与计费分辨率分离；锁定规格不能通过高级参数覆盖。
- 视频模式保留各自语义，视频编辑、音频参考不再合并为全能参考。Grok 应用适配器仅支持 image_urls，因此移除误报的视频/音频参考能力；原 304 个组合中移除 6 个无效组合。
- 修复截图中 Q4 单图经 reference_assets/reference_images 重复计数的问题；统一引用去重，保留首尾帧角色。参数预检在提示词增强、任务和计费之前执行。
- 修复图片 schema 的 JSON 字符串枚举导致 quality 被丢弃；修复画布请求默认 trim 使 false 变空字符串、数字变字符串的问题。仅调整画布请求读取，仍裁剪字符串首尾空白。
- 生成请求统一错误文本并避免重复提示、自动重试；同步失败/取消响应在结果写入前拦截，保留已有结果。回归覆盖多结果追加、排队中状态和失败响应，沿用现有任务轮询合同。

## 仓库和合同边界

- server：画布模型配置、参数校验/映射、请求类型保留、视频素材预检、图片枚举及 Grok 能力修正、契约审计。
- web：节点高级参数面板、模式选择、生成参数组装、错误提示和结果回填回归。
- 配置响应新增可选 `canvas_parameters`；生成请求新增可选 `advanced_params`；画布节点 metadata 新增按模型键保存的 `generationParameters`。旧画布缺失这些字段时沿用原默认行为，旧素材、存储元数据和未知 metadata 保留。
- 无 API 路径、数据库迁移、权限或任务状态枚举变更。Agent、整图自动执行及音乐续写/翻唱等非 generate 操作不在本轮范围。

## 本轮验证

- 只读本地市场审计：36 个入口、298 个有效 SKU/模式组合、587 项高级参数传递检查，0 拒绝；覆盖配置描述、画布归一化、请求编译及引用预检。数据库只读事务回滚，无上游调用。
- 新增独立契约 24 项通过：实际 Request 输入类型、别名去重/首尾帧、false/0、范围/锁定校验、结构化字段、文本/图片/视频/音乐映射和旧请求兼容。
- PHPUnit 模型/协议/能力契约 61 项、278 个断言通过；视频模式、Seedance 素材、音乐请求 3 份独立契约通过。
- 前端 181 项画布回归全部通过，包含实际生成参数组装、多结果回填、排队/失败保护、旧画布保存往返。
- Nuxt 生产构建通过；既有重复组件名、弃用和 compatibilityDate 提示保留。所有运行/构建/测试均在集成最新 feature 提交后的本地 develop 执行，产物不纳入交付。
- 浏览器实测四类节点的高级面板、Q4 seed=0/audio=false 保存刷新保持、越界提示、恢复默认、图片质量和分辨率分离。保留原 10 节点及素材；临时参数恢复默认；未点击生成。历史失败节点未通过新生成覆盖。
- 上轮已验证的图片目录 561 个规格及其余独立协议回归属于历史记录，不计作本轮新增实测。

## 验收边界与剩余事项

未做真实付费生成，不能声称每个上游模型端到端已实测稳定。示例素材可达性、上游排队、生成质量、长任务恢复、回调、失败退款、成功结算以及限流/超时，仍需在明确允许的模型与费用范围内真实验收。本轮参数传递测试不能替代这些验证。

没有声明 schema/容量的模型不添加猜测参数；下表“—”表示未暴露额外高级控件，不表示模型在上游绝对没有高级能力。传输字段、回调、凭据和 SKU 价格字段不开放编辑。市场 schema 缺失的约束仍需提供方合同补全。音乐当前入口仅 type=generate，保留普通/自定义歌词和纯伴奏。PHP exec/proc_open 限制保持不变，真实截帧环境限制未解除。本轮不构成 Imagine 全工作台 1:1 验收声明。

## 当前目录矩阵

通过数表示本地构造通过；同名入口保留各自 SKU/协议。下表来自本轮只读目录审计；共 38 个高级参数描述，按各模型独立统计。

| 类型 | 入口 | 标识 | 通过组合 | 生成模式 | 新增高级控件字段 |
| --- | --- | --- | ---: | --- | --- |
| text | kimi-k3 | 12 | 1 | default | max_tokens |
| text | GPT-5.6 Terra | 11 | 1 | default | — |
| text | DeepSeek-V4-Flash | 4 | 1 | default | — |
| text | DeepSeek-V4-Pro | 3 | 1 | default | — |
| image | Nano-Banana 2.1 | market_image_model:234 | 1 | default | — |
| image | GPT Image 2.5 | market_image_model:169 | 3 | default | quality |
| image | GPT Image 2.5 Flare | market_image_model:168 | 3 | default | quality, background |
| image | GPT Image 2.5 Sunburst | market_image_model:167 | 3 | default | quality, background |
| image | Nano-Banana 2 | market_image_model:93 | 1 | default | — |
| image | Nano-Banana Pro | market_image_model:92 | 1 | default | — |
| image | GPT Image 2 | market_image_model:91 | 3 | default | — |
| image | GPT Image 2 | market_image_model:90 | 3 | default | quality |
| image | GPT Image 2 Fast | market_image_model:89 | 3 | default | quality |
| image | GPT Image 2 Pro | market_image_model:88 | 3 | default | — |
| image | Qwen Image 3.0 Pro | market_image_model:87 | 2 | default | parameters.watermark, parameters.prompt_extend |
| image | Qwen Image 3.0 | market_image_model:86 | 2 | default | parameters.watermark, parameters.prompt_extend |
| image | Nano Banana | market_nano_banana:110:bmFuby1iYW5hbmE= | 1 | default | — |
| image | Nano Banana 2 | market_nano_banana:110:bmFuby1iYW5hbmEtMg== | 1 | default | — |
| image | Nano Banana 2 Lite | market_nano_banana:110:bmFuby1iYW5hbmEtMi1saXRl | 1 | default | — |
| image | Nano Banana 2 Lite Official | market_nano_banana:110:bmFuby1iYW5hbmEtMi1saXRlOm9mZmljaWFs | 1 | default | — |
| image | Nano Banana 2 Official | market_nano_banana:110:bmFuby1iYW5hbmEtMjpvZmZpY2lhbA== | 3 | default | — |
| image | Nano Banana Official | market_nano_banana:110:bmFuby1iYW5hbmE6b2ZmaWNpYWw= | 1 | default | — |
| image | Nano Banana Pro | market_nano_banana:110:bmFuby1iYW5hbmEtcHJv | 1 | default | — |
| image | Nano Banana Pro Official | market_nano_banana:110:bmFuby1iYW5hbmEtcHJvOm9mZmljaWFs | 3 | default | — |
| video | Q4 Preview 图生视频 | market_video_model:235 | 5 | image_to_video | seed, audio, is_rec, wm_url, watermark, wm_position |
| video | H3 视频生成 | market_video_model:98 | 16 | text_to_video, omni_reference, image_to_video, start_end, image_reference, video_edit, multi_frame, audio_reference | — |
| video | Grok Video | market_video_model:97 | 18 | text_to_video, image_to_video, image_reference | — |
| video | VEO 3.1 Fast | market_video_model:96 | 12 | text_to_video, image_to_video, start_end, image_reference | — |
| video | VEO 3.1 Pro | market_video_model:95 | 12 | text_to_video, image_to_video, start_end, image_reference | — |
| video | Wan 3.0 Video | market_video_model:94 | 24 | text_to_video, omni_reference, image_to_video, start_end, image_reference, video_edit, multi_frame, audio_reference | parameters.audio, parameters.watermark |
| video | Seedance 2.0 | market_video_app:seedance:140 | 42 | text_to_video, omni_reference, image_to_video, start_end, image_reference, video_edit, multi_frame | seed, draft, frames, watermark, camera_fixed, generate_audio |
| video | Wan 视频生成 | market_video_app:wan:133 | 42 | text_to_video, omni_reference, image_to_video, start_end, image_reference, video_edit, multi_frame | seed, watermark, prompt_extend, negative_prompt |
| video | Grok 视频生成 | market_video_app:grok_video:108 | 30 | text_to_video, omni_reference, image_to_video, image_reference, multi_frame | — |
| video | Happy Horse | market_video_app:happy_horse:105 | 12 | text_to_video, omni_reference, image_to_video, image_reference, video_edit, multi_frame | seed, watermark |
| video | 全能视频生成 | market_video_app:full_video:103 | 40 | text_to_video, omni_reference, image_to_video, start_end, image_reference, video_edit, multi_frame, audio_reference | aigc_watermark |
| music | 生成音乐（type=generate） | 167 | 1 | default | weirdness, persona_id, lyric_prompt, style_negative, style_influence |
