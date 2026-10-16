# 新画布与上架模型验收 — 2026-10-16

任务分支：`feature/agent-optimization`。范围：当前租户上架的 36 个画布模型选项，各选一个最低有效规格；不是全部价格 SKU 或全部生成模式的穷举实付测试。

## 结果

- 30 个选项成功，6 个上游失败；失败不计为通过。
- 27 份图片/视频/音频结果已转存且 HEAD 返回 200，3 个文本结果返回 OK。
- 39 条实际消耗记录：31 条结算、8 条失败退款；实际扣点合计 **1507.60**。
- 额外记录包括修复前 Flash 被自动切到 Kimi 的成功调用（1.44 点）、修复后 Flash 复测及 Grok 标准版补参考图后复测；没有隐瞒或回写这些历史账单。
- 验收画布为本机项目 11“上架模型最小规格真实验收”，36 个节点刷新后保持 30 成功 / 6 失败。原项目数据与素材均保留。

| 类型 | 模型选项 | 最小规格 | 结果 | 任务 |
| --- | --- | --- | --- | --- |
| text | kimi-k3 (`12`) | 64 输出 token 上限 | 成功 | 5130 |
| text | GPT-5.6 Terra (`11`) | 64 输出 token 上限 | 成功 | 5131 |
| text | DeepSeek-V4-Flash (`4`) | 64 输出 token 上限 | 上游 model_not_found | 5155 |
| text | DeepSeek-V4-Pro (`3`) | 64 输出 token 上限 | 成功 | 5134 |
| image | Nano-Banana 2.1 (`market_image_model:234`) | 固定规格 / 1 张 | 成功 | 5135 |
| image | GPT Image 2.5 (`market_image_model:169`) | 1k / 1 张 | 成功 | 5136 |
| image | GPT Image 2.5 Flare (`market_image_model:168`) | 1k / 1 张 | 成功 | 5137 |
| image | GPT Image 2.5 Sunburst (`market_image_model:167`) | 1k / 1 张 | 成功 | 5138 |
| image | Nano-Banana 2 (`market_image_model:93`) | 固定规格 / 1 张 | 上游生成/提交失败 | 5139 |
| image | Nano-Banana Pro (`market_image_model:92`) | 固定规格 / 1 张 | 成功 | 5140 |
| image | GPT Image 2 (`market_image_model:91`) | 1k / 1 张 | 成功 | 5141 |
| image | GPT Image 2 (`market_image_model:90`) | 1k / 1 张 | 成功 | 5142 |
| image | GPT Image 2 Fast (`market_image_model:89`) | 1k / 1 张 | 成功 | 5143 |
| image | GPT Image 2 Pro (`market_image_model:88`) | 1k / 1 张 | 成功 | 5144 |
| image | Qwen Image 3.0 Pro (`market_image_model:87`) | 1k / 1 张 | 成功 | 5145 |
| image | Qwen Image 3.0 (`market_image_model:86`) | 1k / 1 张 | 成功 | 5146 |
| image | Nano Banana (`market_nano_banana:110:bmFuby1iYW5hbmE=`) | 1k / 1 张 | 成功 | 5147 |
| image | Nano Banana 2 (`market_nano_banana:110:bmFuby1iYW5hbmEtMg==`) | 1k / 1 张 | 成功 | 5148 |
| image | Nano Banana 2 Lite (`market_nano_banana:110:bmFuby1iYW5hbmEtMi1saXRl`) | 1k / 1 张 | 成功 | 5149 |
| image | Nano Banana 2 Lite Official (`market_nano_banana:110:bmFuby1iYW5hbmEtMi1saXRlOm9mZmljaWFs`) | 1k / 1 张 | 成功 | 5150 |
| image | Nano Banana 2 Official (`market_nano_banana:110:bmFuby1iYW5hbmEtMjpvZmZpY2lhbA==`) | 1k / 1 张 | 成功 | 5151 |
| image | Nano Banana Official (`market_nano_banana:110:bmFuby1iYW5hbmE6b2ZmaWNpYWw=`) | 1k / 1 张 | 成功 | 5152 |
| image | Nano Banana Pro (`market_nano_banana:110:bmFuby1iYW5hbmEtcHJv`) | 1k / 1 张 | 成功 | 5153 |
| image | Nano Banana Pro Official (`market_nano_banana:110:bmFuby1iYW5hbmEtcHJvOm9mZmljaWFs`) | 1k / 1 张 | 成功 | 5154 |
| video | Q4 Preview 图生视频 (`market_video_model:235`) | 540p / 3 秒 / image_to_video | 成功 | 5118 |
| video | H3 视频生成 (`market_video_model:98`) | 768P / 4 秒 / text_to_video | 成功 | 5119 |
| video | Grok Video (`market_video_model:97`) | 720p / 6 秒 / text_to_video | 上游生成/提交失败 | 5120 |
| video | VEO 3.1 Fast (`market_video_model:96`) | 720p / 8 秒 / text_to_video | 上游生成/提交失败 | 5121 |
| video | VEO 3.1 Pro (`market_video_model:95`) | 720p / 8 秒 / text_to_video | 上游生成/提交失败 | 5122 |
| video | Wan 3.0 Video (`market_video_model:94`) | 480P / 2 秒 / text_to_video | 成功 | 5123 |
| video | Seedance 2.0 (`market_video_app:seedance:140`) | 480p / 4 秒 / text_to_video | 成功 | 5124 |
| video | Wan 视频生成 (`market_video_app:wan:133`) | 720p / 2 秒 / text_to_video | 上游生成/提交失败 | 5125 |
| video | Grok 视频生成 (`market_video_app:grok_video:108`) | 480p / 1 秒 / image_reference | 成功 | 5156 |
| video | Happy Horse (`market_video_app:happy_horse:105`) | 720p / 3 秒 / text_to_video | 成功 | 5127 |
| video | 全能视频生成 (`market_video_app:full_video:103`) | 480p / 4 秒 / text_to_video | 成功 | 5128 |
| music | 生成音乐（type=generate） (`167`) | 单次音乐生成 | 成功 | 5129 |

## 修复与边界

1. 删除未引用的旧 VueFlow 节点、连线、模板、图构建工具及前端直连供应商适配器。新画布仍需要的数据导入边界保留：旧字段读入后转换成新节点；修复图片 URL 被误判为视频、音频 URI 丢失。无项目删除或数据库批量迁移。
2. 切换模型保留已创建连线和参考素材；按类型、数量及生成模式拦截不合法请求。报价 SKU 跟随实际模式、时长，保留仍有效的显式规格。
3. Grok 标准版仅允许图片参考，限制 1–15 秒；快速版仍支持文生视频。Wan 视频编辑限制 2–10 秒。共享后端在报价/提交前校验，返回每个 SKU 的可用模式和时长。保留市场现有计费单位，没有改历史价格。
4. 明确选择的画布文本模型失败后不再自动换模型；同步与流式请求均保留业务任务关联。Agent 默认路由不改。
5. 文本、图片、视频、音频及重绘恢复尊重终态，忽略旧请求迟到响应；失败不回填旧 URL，网络查询错误保留任务号继续查询，刷新不自动重新生成。
6. 项目已删除/无权访问时不从缓存复活；生成入口在任何计费前验证项目。

## 验证

- PC 画布 Node 测试：237 通过。
- PHP 相关套件分别运行：82 测试、417 断言通过（市场图片/视频协议、Q4、参考素材、短剧时长、文本及 Grok 输入/计费契约）。
- 单独无生成契约：任务恢复 24、高级参数 24、文本选型 7、已删除项目入口 5。
- 只读市场审计：36 个选项、251 个可用 SKU/模式组合通过。非法组合另由负向契约拒绝。
- Nuxt 生产构建通过；浏览器验证刷新、搜索快捷键、定位节点与成功/失败展示。
- 未执行整图自动生成、未修改 PHP 执行配置、未发布版本包。截帧环境 exec/proc_open 限制仍保留。

## 本地复核工具

`app/apps/aigc_canvas/tests/live_local_market_acceptance.php` 默认 plan 只读报价；init 新建验收项目；submit 必须显式 `--allow-paid`；poll 只查已有任务。重复 submit 不自动重提，只有人工核对后 `--retry-after-fix` 才允许新尝试。revise 仅修改指定选项计划并留存旧计划，sync 把验收结果与参数映射为原生节点。

本次逐次请求/响应、旧尝试和计费核对放在本机忽略目录 `runtime/canvas-acceptance-1-1-9/`，不提交运行数据、媒体文件或认证信息。
