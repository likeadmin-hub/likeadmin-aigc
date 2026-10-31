# 提供给算力市场服务方的合同资料清单

目的：在 SaaS 共享准备层接入同协议模型，使短剧、画布、独立生图和独立视频复用参数、素材、SKU 与计价校验。无需提供源码仓库、密钥、用户提示词或用户素材，也不需要发起生成。可以提供版本化机器合同导出，或覆盖相同内容的完整、可绑定版本的正式规则说明。

## 待补齐的 18 个模型身份

同名不同渠道分别绑定，应用 API 与模型资源不能互相替代。

| 类型 | 型号 | 渠道 |
| --- | --- | --- |
| image | nano-banana-2.1 | Google |
| image | nano-banana-2 | Google |
| image | nano-banana-pro | Google |
| image | gpt-image-2.5 | OpenAIYI |
| image | gpt-image-2.5-flare | OpenAIYI |
| image | gpt-image-2.5-sunburst | OpenAIYI |
| image | gpt-image-2 | OpenAIYI |
| image | gpt-image-2 | OpenaiM |
| image | gpt-image-2-fast | openaiD |
| image | gpt-image-2-pro | OpenaiM |
| image | qwen-image-3.0 | dashscope_compatible |
| image | qwen-image-3.0-pro | dashscope_compatible |
| video | q4-preview-video | q4_video |
| video | h3-video | minimax |
| video | grok-video | xAIQ |
| video | veo3.1-fast | xAIQ |
| video | veo3.1-pro | xAIQ |
| video | wan3.0-video | dashscope_compatible |

## 应用提交 API

另外核对 Nano Banana `submit`、Seedance `create`、Wan `create`、Grok Video `submit`、Happy Horse `submit`、全能视频 `submit`。精确应用编码分别为 `nano_banana`、`seedance`、`wan`、`grok_video`、`happy_horse`、`full_video`。这 6 条接口覆盖本地 13 个选项，Nano Banana 的 8 个规格选项共用同一 API，但仍需各自的 SKU 与素材限制。

## 每项资料必须说明

1. **身份和版本**：资源类型、生成类型、公开型号/渠道或应用/API 编码、平台数字资源 ID、合同版本及对应网关版本。不能只给展示名称或近似型号。
2. **实际网关业务请求**：根字段和每层对象/数组子项的类型、必填、枚举、默认值、数值/长度/数量边界、额外字段处理、空值与 false/0 的含义。请区分市场网关格式和供应商原始格式。
3. **素材模式**：文本、参考图、首尾帧、视频编辑、音频/主体/分镜等实际支持的模式；每个素材的字段路径、角色、数量、输入类型、必须/可选及允许组合。
4. **条件规则**：互斥、成对、依赖、组合总配额、输入类型决定的参数限制，以及各模式中哪些参数会被忽略或拒绝。说明文字中的条件不能遗漏在顶层字段列表之外。
5. **SKU 与计价规则**：SKU 锁定维度、适用模式、计费单位/单位大小、数量计算、参考图附加费用、按像素/实测时长/Token 结算条件、缺失回执处理和提交前可确定的费用上限。可使用合成单价说明公式，无需提供敏感价格。
6. **实测素材要求**：MIME、尺寸、时长、格式、视频/音频总时长、供应商素材 ID 有效性及归属等；说明哪些由提交前验证、哪些由供应商验证。
7. **边界样例**：每种模式的最小合法参数、缺必填、超限、非法枚举、组合冲突、对应 SKU 下拒绝的样例。仅需合成数据，不能只提供一个成功请求示例。

## 当前尤其需要确认

- Nano/GPT/Grok/VEO 的素材数组子结构与完整数量、模式、渠道差异；不能把 OpenAIYI 的 GPT Image 2 替换成 OpenaiM 或 VIP。
- Qwen 的 `input.messages[].content[]` 与 `parameters` 完整结构和条件。已知公开规则包括单条用户消息、一个文本项、最多 3 个图片项、`n=1`、参考图模式禁用 `agent`，仍需与完整版本化合同绑定。
- Wan 的 `input.media` 类型/角色/计数、首尾帧与参考模式互斥、文件/链接互斥，以及参考视频模式时“总时长限制”的精确公式。
- H3 概述中的尾帧能力与详细规则中的首尾帧成对条件、文本项数量；Q4 的完整模式与版本条件。
- 应用中的 `tools`、`content`、`media`、视频/音频对象和供应商资产 ID；明确每层 schema，不能以示例代替类型。
- 2 个像素 SKU 的可信像素上下文、Seedance 6 个 Token SKU 的提交前预算上限。当前 SaaS 保留相应报价/提交阻塞。

## 交付格式与 SaaS 验证

可按[审核合同说明](market-reviewed-generation-contracts.md)提供 `source`、`entries`、`declaration`、各模式 `schema` / `rules`，保留版本与原始依据文件。SaaS 会另外绑定本地实际保存的原始声明，不能用补齐后的 schema 冒充原始平台响应。若平台使用暂未实现的校验关键字或新的素材结构，会在共享层补充一次并验证受影响入口，不在各工具增加供应商分支。

当前目录/文档和离线构造结果只能说明对应证据，不能代替上述缺失资料。公开文档本轮可见范围发生变化，先前已核实的记录会保留；文档暂不可读不代表模型不可用。资料齐全后可完成合同导入与离线验收，真实上游行为仍单独标注未经实测。此清单未自动发送给任何人。


## 2026-10-31 第43批复核补充

已再次从当前市场详细目录和文档接口核对上述18个精确身份，目录声明与本地一致，仍有14个模型的17条不透明结构路径，未取得完整模式／条件合同。定价元数据查询没有补回可靠的用量上限，当前2像素／6Token规格仍拒绝；没有发送本资料清单给平台方。

像素计价需补充每个尺寸名称的真实宽高映射、自动尺寸／数量上限及实际输出尺寸回执规则；当前只知道像素累加公式，不能套用其他供应商同名尺寸。Token计价需补充按SKU保证的最大可收费Token总量，或具备可靠上界且覆盖所有允许输入的完整正式公式，并指定实际用量回执。SaaS已有version2固定上限计量支持，格式见[共享审核合同说明](market-reviewed-generation-contracts.md#第43批token视频的有上限计量合同)；合成格式不证明上游已经支持，不向真实SKU写入猜测值。
