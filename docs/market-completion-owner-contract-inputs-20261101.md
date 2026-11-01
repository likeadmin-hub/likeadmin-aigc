# 继续完成所需输入：精确合同、计量和权威回执

2026-11-01本地资料核对。对应[总执行方案](market-full-completion-execution-plan-20261101.md)的 **B01-input／B02／B03-evidence／B03-stt-evidence**，执行细节见[现有手册](market-completion-authoritative-review-runbook-20261101.md)，唯一身份见[机器登记](market-completion-contract-register-20261101.json)。本文只提供资料接收、核对和后续处置要求，不是假正式合同、不启用真实配置，也不表示已取得真实账务或供应商操作授权。

本文不包含真实case ID、用户正文／素材、私有服务地址或密钥。下文product／SKU是模型商品／规格业务身份，非用户个案ID；消费／task／run等实际记录的映射只在owner受保护的本机材料中使用，不提交本文件或源码仓库。

## 一、已经找到什么，还不能据此完成什么

| 已核对资料 | 找到的内容 | 不能替代的输入 |
| --- | --- | --- |
| 批次73附件`正式缺口身份及不透明路径-批次50快照.json` | 18精确模型、14模型17条expanded opaque路径、13历史选项和8计量SKU的历史窄快照 | 没有新增完整模式／条件／计量／usage合同；最后上游观察仍为2026-10-31 18:38:45＋08:00 |
| 同附件README、历史121资源／567SKU清单及日志 | 身份追溯和后续替身验收边界；原始列表属于历史基线 | 合成账号／loopback任务回执不是实际待核对记录的供应商权威凭据，不能把旧下一批清单当新待办 |
| `resources/market/generation-contracts.json` | revision为2026-10-25的90个既有native合同；该文件模型键不含下面18个精确型号键 | 不应重做这90个合同，也不能套给另外18个身份；同名近似、跨渠道或不同资源类型不能继承授权 |
| `tests/fixtures/market-platform-contract-flow-review-20261029.json`、批次50原JSON | 18身份的目录／文档可见性、已知字段、声明指纹、不透明子结构与历史准入拒绝 | 指纹一致／文档曾可读不证明完整schema、全部模式或费用上限；这里没有新正式资料 |
| `tests/fixtures/market-structured-public-contracts-20261029.json` | 8条公开本地声明测试输入，文件明确不授予provider认证 | 不能将测试schema升级为正式网关合同 |
| `tests/fixtures/canvas-generation-market-contracts.json` | 2026-10-18获取的25条旧模型／应用声明；六应用有顶层参数／默认值记录 | 相关应用记录没有完整版本化模式合同，不能凭顶层字段、单例或原生功能解锁复杂输入 |
| 275／277的原native身份及批次50计量拒绝 | 原请求合同观察已为`matched`，费用依据仍缺 | 这两个official资源不需要先等待另外两个dashscope Qwen3型号合同；仍需它们自己SKU的像素计量依据 |
| 批次76只读审计和现有回执恢复实现 | 43条文字待核对消费本地恢复回执不存在，上游关联字段非空；1条STT预占与唯一review_run已证明同一父子业务案例 | 非空ID和关联成立不能替代真实usage／明确回执；现有测试回执不属于这些真实记录 |

本次只读复核以上登记、源文件、附件和已保存观察，未查询上游、数据库或私有回执目录；没有发现能够据此直接解除现有正式合同／实际用量阻塞的新材料。默认reviewed目录文件未在仓库出现，运行环境trusted-path覆盖未检查，不能推断线上实际启用状态。

## 二、B01-input：18个模型逐项需要的资料

以下product ID是历史baseline绑定，资料到达时还要核对当前精确身份／原声明；它们不是今天刚同步的目录。所有18项仍需要完整版本化请求合同，空opaque列不是通过。

| 历史product | 类型 | 精确渠道 | 精确型号 | 已明确的不透明结构／缺口 |
| --- | --- | --- | --- | --- |
| 234 | image | Google | nano-banana-2.1 | 无expanded opaque路径，完整模式／条件仍缺 |
| 169 | image | OpenAIYI | gpt-image-2.5 | `image_urls[]`子项 |
| 168 | image | OpenAIYI | gpt-image-2.5-flare | `image_urls[]`子项 |
| 167 | image | OpenAIYI | gpt-image-2.5-sunburst | `image_urls[]`子项 |
| 93 | image | Google | nano-banana-2 | `urls[]`子项 |
| 92 | image | Google | nano-banana-pro | `urls[]`子项 |
| 91 | image | OpenaiM | gpt-image-2 | `image_urls[]`子项 |
| 90 | image | OpenAIYI | gpt-image-2 | `image_urls[]`子项 |
| 89 | image | openaiD | gpt-image-2-fast | 无expanded opaque路径，完整模式／条件仍缺 |
| 88 | image | OpenaiM | gpt-image-2-pro | `image_urls[]`子项 |
| 87 | image | dashscope_compatible | qwen-image-3.0-pro | `input`、`parameters` |
| 86 | image | dashscope_compatible | qwen-image-3.0 | `input`、`parameters` |
| 235 | video | q4_video | q4-preview-video | 无expanded opaque路径，完整模式／版本条件仍缺 |
| 98 | video | minimax | h3-video | 无expanded opaque路径，首尾帧／文本数量／原生content条件仍缺 |
| 97 | video | xAIQ | grok-video | `image_urls[]`子项 |
| 96 | video | xAIQ | veo3.1-fast | `image_urls[]`子项 |
| 95 | video | xAIQ | veo3.1-pro | `image_urls[]`子项 |
| 94 | video | dashscope_compatible | wan3.0-video | `input`、`parameters` |

### 每个精确身份的接收验收字段

可以一份版本化资料覆盖多项，但必须逐项明确适用身份和SKU；不要求18份重复文件。

1. **来源／版本绑定**：模型资源类型、生成类型、精确型号／渠道、平台资源ID、public型号、网关版本、合同revision；原正式文件、正式发布位置、版本与文件SHA256。公开位置不带token、认证query或用户信息。内容相同但来源不可核验时仍不能认证。
2. **完整业务请求schema**：根和每层对象／数组子项的类型、必填、默认、枚举／const、数值／长度／数量边界；额外字段处理；缺失／null／空串／false／0分别含义。需说明市场网关收到的结构，不能只给供应商原始API结构。
3. **全部模式及素材用途**：文本、参考图、首尾帧、视频编辑、音频等实际支持模式；字段完整路径、角色、MIME／尺寸／时长、数量、总量、必需／可选及输入类型。未支持的模式明确说明，不由智能体补造。
4. **条件约束**：互斥、成对、依赖、组合总配额、条件必填、输入改变后哪些字段被忽略／拒绝；同URL不同角色／槽是否仍算两个输入；每个模式可用SKU及锁定维度。
5. **计价／回执**：合法请求对应计量公式和提交前可靠上限、单位／单位大小／舍入；实际usage／成功槽／失败槽／转存状态的确切回执；缺失、部分成功、取消和失败的结算规则。价格可用合成单价说明，不要求敏感实价。
6. **可离线验证的边界样例**：每模式最小合法、缺必填、超限、非法枚举、组合冲突、SKU不匹配和成功／失败／缺量回执。仅一个成功请求样例不满足要求；样例使用合成素材引用，不要求用户作品。

几个必须具体回答的差异：

- Nano／GPT／Grok／VEO：每个数组子项完整类型、渠道差异及模式／数量；Nano顶层声明不能推出嵌套`input/params/parameters`或原生张数。裸`b64_json`／供应商asset ID若支持，必须有确切映射、有效期和归属规则；不支持则明确，不强行开放。
- 两个dashscope Qwen3：完整`input.messages[].content[]`与`parameters`及跨字段条件，含消息／文本／图片数量、`n`、agent与参考图限制，绑定精确网关版本；不要将已知局部规则当完整合同。
- Wan：`input.media`的type／role／数量、首尾帧与参考模式、file／link互斥、视频／音频时长和总配额的精确公式。
- H3：尾帧与首尾帧成对要求、文本项数量、业务素材与原生`provider_params.content`合并或明确拒绝政策；不能凭业务素材已经支持放开原生合并。
- Q4和无opaque的其他型号：完整模式、版本和条件仍须提供，不因缺少不透明路径而跳过。

## 三、6条应用API覆盖13选项：共用接口不共用全部限制

| 历史product | 精确应用／API | 覆盖选项 | 仍需补齐 |
| --- | --- | --- | --- |
| 110 | `nano_banana/submit` | Nano Banana；Nano Banana 2；Nano Banana 2 Lite；Nano Banana 2 Lite Official；Nano Banana 2 Official；Nano Banana Official；Nano Banana Pro；Nano Banana Pro Official，共8项 | 逐变体／SKU的参数与素材数量、原生张数、复杂／嵌套输入、结果与计费；不能与Google模型资源互换 |
| 140 | `seedance/create` | Seedance 2.0 | `content/tools`等完整结构、带／不带视频的条件、素材总配额、每个Token SKU上限与usage |
| 133 | `wan/create` | Wan视频生成 | `media/input/parameters`的实际网关结构、素材type／role、互斥／时长、各SKU映射 |
| 108 | `grok_video/submit` | Grok视频生成 | 完整素材对象／数组、模式和精确SKU约束及回执 |
| 105 | `happy_horse/submit` | Happy Horse | 实际音／视频／图片对象结构、角色／总量、互斥、SKU和结果回执 |
| 103 | `full_video/submit` | 全能视频生成 | `content/tools`等实际结构、角色、条件必填／互斥、总配额与SKU／回执 |

这些是已有部分支持，不是13个完全未开发工具。六API均须提供上一节同等的来源、完整schema／模式、计价／回执和边界例，精确指出某字段确实不适用，而非让智能体猜。应用资料走已有API专用声明／校验路径；现有模型reviewed编译工具不是应用导入器，不能伪装为model条目。

## 四、B02：8个计量SKU需要的独立依据

| resource／SKU | 已有事实 | 必须提供的剩余计量资料 |
| --- | --- | --- |
| product275／SKU380，Qwen图片编辑 | 原生identity为`official-33-c0b38c6fcb29f739b0 / official`；请求合同原观察matched | 编辑所有合法输出尺寸／auto尺寸的正式映射或单张像素硬上限、最大输出张数、真实输出列表／槽及失败／未转存处理、计费单位／大小／舍入 |
| product277／SKU383，Qwen Image文生图 | 原生identity为`official-33-f3baf6c03742072d3a / official`；请求合同原观察matched | 文生图所有合法尺寸／auto尺寸的正式映射或硬上限、最大输出张数和确切结果回执／计价；不能借275或dashscope Qwen3的同名尺寸 |
| product140／SKU210 | 480p，含视频输入 | 对该SKU所有合法输入保证的最大可收费Token，或可证明上界的完整公式；确切usage／失败回执、单位／大小／舍入 |
| product140／SKU211 | 480p，不含视频输入 | 同上，须明确无视频这一变体，不能沿用210而不证明 |
| product140／SKU212 | 720p，含视频输入 | 同上，须覆盖720p含视频的全部允许组合 |
| product140／SKU213 | 720p，不含视频输入 | 同上，须覆盖720p无视频的全部允许组合 |
| product140／SKU214 | 1080p，含视频输入 | 同上，须覆盖1080p含视频的全部允许组合 |
| product140／SKU215 | 1080p，不含视频输入 | 同上，须覆盖1080p无视频的全部允许组合 |

要求是SKU保证的上限，不是UI预算、某次真实用量、任意图片像素或按视频秒数估算的Token。请求合同、可信计量、使用原生API时匹配的published计费绑定分别核对；相同展示名称不能串用，不将8个SKU加成8个模型。

已有version2 Token与version3像素共享实现可复用。资料须能明确映射到现有合同字段：

- Token：`maximum_quantity`、精确`usage_path`、计费单位／单位大小及`ceil/ceil_2`舍入，来源为逐SKU正式保证。不是客户端参数。
- 像素：`maximum_pixels_per_image`、`maximum_images`、`result_path`、`result_item_url`、单位／大小／舍入；实际结算仍须服务端读字节测量成功槽，不信任回执／浏览器自报宽高。
- 新资料超出现有有限格式时，先在共享层实现有依据的新规则并验证消费者，不把公式粗略压成猜测常数；资料不足继续拒绝。

验收包括：同一准备／SKU报价和提交、上限预占、实际usage／像素、重复输出槽、缺量／超界保留核对、明确失败退款、重复回执幂等和旧冻结保持。离线通过不自动改真实价格、SKU上限、上架或已发布合同。

## 五、B03-evidence：43条文字消费的权威资料

现有观察是41条待usage＋2条结算失败；本地可恢复回执均不存在，但关联字段非空。它们是43条**待核对消费**，不是43个确认生成失败或43个不同父run。不能把非空ID当实际usage证明，也不能用其他批次的合成回执配到真实记录。

### Owner需提供的最小字段

每条权威原记录至少包含下面能与本地冻结记录唯一关联的字段。43条可由同一份正式账单／追踪导出覆盖，不要求43份文件，也不要求重跑43次任务。

| 字段组 | 用途与验收 |
| --- | --- |
| 来源范围 | 供应商／网关、账号范围的脱敏稳定标识、正式导出来源、账期／时间及时区、schema／接口版本、原文件摘要；不提供API Key／密码 |
| 唯一原调用 | 原provider request/task ID或正式可证明的网关关联；需保留能在owner受保护映射中连到本地租户／用户、app、消费和原任务的字段 |
| 模型和请求版本 | 精确模型／渠道／协议或API与原冻结一致，不能用事后默认模型／新价格推算旧消费 |
| 最终状态与完整性 | 原调用是否受理、最终成功／明确失败／取消／未知、完整终止标志和完整回执；部分流或连接超时不能自动当失败 |
| 实际用量 | 对应协议的输入／输出Token，必要时cache／reasoning等分类及总量字段的正式含义，或正式说明只给总量时如何计价；必须能和原冻结计费读法对应 |
| 费用与结算依据 | 原计价／单位／舍入及最终计费／释放事实；若实际收费与本地上限／冻结不符，单列冲突，不自动按新单价重算或补扣 |
| 审核轨迹 | 原文件SHA、核对人、依据／工单、逐条关联结论和歧义；不以时间接近、余额差、相同提示词或相同型号认定唯一关联 |

原回执正文若涉及用户内容，可由owner保存在受保护本机文件；审核时只读取获提供的最小范围并输出归属／完整性／usage布尔和摘要，不贴聊天、不提交源码、不发给第三方。没有足够唯一关联时保持待核对，不为了凑43条选最近一条。

### 资料到达后的实际处置边界

1. 先逐条分为：完整可信回执＋usage、完整正文缺usage、部分回执、无可匹配回执；生成来源／关联／原冻结／建议动作的可审阅清单。41和2的处理结论可能不同，不能批量视为同类退款。
2. 当前可以完成本地比对和只读预案；**真实结算、补扣、退款、write-off或修改任务状态分别需要具体动作／金额／记录范围的授权**。准许读取资料不等于准许改账。
3. 本仓没有已验证可对任意Agent文字消费导入凭据并apply的公开通用命令。不得发明`billing:repair`用法，不得使用`repair-assets --apply`或文案业务的`settleCopywritingReceipt`来替代Agent核对；需要实现时须基于权威材料、既有冻结计费及PointService做窄范围恢复，并先隔离验证。
4. 授权执行的范围应引用owner可查看的逐条预案／摘要及清单SHA，明确每条动作、最高变动金额、是否允许关联run被恢复／继续后续收费、是否允许任何provider查询。批量“全部处理”不代替尚未确定的金额／受理结论。
5. 若仍需要向真实provider查询原任务／账单，须另明确允许的只读接口／记录范围、调用上限及执行窗口；不能自行查询、重发或注册资产。没有资料时保留未知，不直接退款，不估算Token。

## 六、B03-stt-evidence：1个STT业务案例需要的原回执

该STT消费、统一task、`audio_transcription`步骤和health唯一`needs_review`父run已由协调者只读join证明同一业务案例。当前是**40点预占、实际结算0**，不是两个故障，不是另一个Token异常，也不是用户已确认的40.42点手动文字对话。本文不披露实际ID。

Owner需要提供：原STT调用的正式受理／最终成功或明确拒绝回执，以及能唯一对应原租户／统一task／消费的原关联；本地映射须含原task_no及归属，不能拿消费ID当统一task ID。成功／拒绝必须来自原始记录，不能人工编一个JSON来表示“我认为成功／失败”。

已有STT导入入口要求的结构是：`tenant_id`、原`task_no`、`receipt.complete=true`、原`receipt.http_status`、原`receipt.provider_receipt`，并在命令中提供操作人及核对理由／工单。它是**原回执容器**而非自证权威的格式。成功结构需符合当前分类器的明确成功及识别结果；明确拒绝亦须有原服务回执。识别正文只留受保护文件，不进入本文或仓库。

具体到现有分类器：成功须是完整回执、HTTP200、原JSON `code=1`，且`data.text`或`data.result.text`为字符串、长度不超过100000字符；language／duration是可保留结果事实，不拿它们猜一笔新计价。明确拒绝须是完整JSON回执、HTTP在200至499范围，且原body明确`code=0`或有error。外部文件中填上这些字段只满足格式，仍必须证明它来自该原调用，而非人工构造的决定。

HTTP5xx、截断、无可靠完成标志或结构不明仍不足结算／退款；STT按原冻结每次调用费用核对，不用Token估算。必须检查已有完整回执是否存在、当前是否仍reserved、是否有活动执行／取消、归属／task_no是否一致。已有完整证据、已结算／退款不得被新人工决定覆盖。

**恢复存在两层真实副作用，需分别明确**：

- 允许对原40点预占依据权威成功／拒绝作一次结算或释放，金额、租户／用户和原记录须由owner可审阅的预案限定。
- `reconcile/importEvidence --apply`可能唤醒原run，由常驻worker继续后续模型分析／工具步骤；需明确是否允许继续、允许哪些后续调用及预算。只准处理旧账时，不能顺带默认批准新的模型费用，也不能为阻止worker擅自停daemon。

没有完整权威回执继续保留预占及`needs_review`，不重新转写、不强设queued/running、不凭等待时间直接退款。清除health attention不是独立的执行理由。

## 七、资料到达后执行什么：使用已有工具

以下为协调者以后在包含最新feature的server local develop执行的步骤，**本文件没有运行这些命令**。输入文件放在owner受保护的本地工作目录，容器内路径由协调者按实际映射准备；不要在路径中嵌入密钥／私有URL。

### 模型合同候选与审核编译

```sh
# 输入是已提供并核对来源的本地version1 bundle和原正式文件；输出只在/tmp。
docker exec -w /www/wwwroot/likeadmin-aigc/server baota \
  /www/server/php/82/bin/php tools/market/prepare-generation-contract-candidates.php \
  /tmp/market-contract-input/bundle.json /tmp/market-contract-input/source-artifact.txt \
  > /tmp/market-contract-candidates.json

# 来源/模式/原声明binding完成独立审核后才使用--reviewed；它不是自动审核证明。
docker exec -w /www/wwwroot/likeadmin-aigc/server baota \
  /www/server/php/82/bin/php tools/market/compile-reviewed-generation-contracts.php \
  /tmp/market-contract-input/bundle.json /tmp/market-contract-input/source-artifact.txt \
  --reviewed > /tmp/market-reviewed-contracts-candidate.json
```

这两个工具仅本地文件→stdout，不访问HTTP／DB、不启用合同。文件各不超过8MB，`source`含可核对正式来源／revision／reviewed_at／artifact_sha256，模型entry含精确身份、完整`declaration`、各模式`schema/rules`；审核编译另需原始`binding_declaration`，不能以补齐schema冒充原声明。应用API不走该模型工具。

输出候选后，按影响复用已有`MarketGenerationContractReviewTest`、`MarketGenerationReviewedContractTest`、嵌套素材／模式／SKU与旧冻结套件；计量复用`MarketPixelBillingTest`、`MarketBoundedTokenBillingTest`和相关原生计量套件。新增用例绑定新正式资料的精确身份和边界，显式数据库测试仅用隔离／回滚数据。通过后才提交受审源码并安排真实配置／部署阶段，不能将候选输出直接覆盖trusted目录或修改真实SKU。

### 文字／STT只读核对

```sh
# 例示的是已核实归属的Agent范围；其它owner须替换为实际批准范围。
docker exec -w /www/wwwroot/likeadmin-aigc/server baota \
  /www/server/php/82/bin/php think ai:market-audit \
  --tenant-id=1 --user-id=1 --app-code=aigc_llm \
  --business-table=aigc_llm_agent_run --limit=500 --fail-on-issues \
  > /tmp/market-text-readonly-audit.json

docker exec -w /www/wwwroot/likeadmin-aigc/server baota \
  /www/server/php/82/bin/php think home-agent:health --attention \
  > /tmp/market-agent-readonly-health.json

# 默认仅audit，最多100条reserved消费；不带--apply和--evidence。
docker exec -w /www/wwwroot/likeadmin-aigc/server baota \
  /www/server/php/82/bin/php think home-agent:stt-reconcile \
  > /tmp/market-stt-readonly-candidates.json
```

这些输出用于归属和异常分类，不是实际结算证据。`ai:market-audit`的非零issue退出码、health的attention均应记录，不通过修改状态或重启服务消除。global health、tenant限定和user限定的范围不同，分别按消费键／run键去重。

### STT权威回执到达后的预览

只有已提供原回执、核对归属，并允许相应本地凭据核对步骤后才做下列预览。`review_stt_tenant_id/review_stt_app_task_id/review_stt_operator/review_stt_reason`由协调者填写已核实的值，不从本文件取得真实case ID；空值立即停止。

```sh
: "${review_stt_tenant_id:?先填写已核实的租户ID}"
: "${review_stt_app_task_id:?先填写原AiAppTask.id，不是消费或run ID}"
: "${review_stt_operator:?先填写审核操作人}"
: "${review_stt_reason:?先填写权威核对依据及工单}"
docker exec -w /www/wwwroot/likeadmin-aigc/server baota \
  /www/server/php/82/bin/php think home-agent:stt-reconcile \
  --tenant="$review_stt_tenant_id" --task="$review_stt_app_task_id" \
  --evidence=/tmp/market-owner-input/stt-original-evidence.json \
  --operator="$review_stt_operator" --reason="$review_stt_reason"
```

此处**没有`--apply`**；它生成settle／refund及原预占金额的具体预案，不代表执行获准。凭据校验路径可能准备本地journal目录，因此不当成严格无文件系统副作用的常规只读命令。真正apply须先得到上述结算／释放及唤醒影响的具体授权，当前不执行。无权威文件时，预览也不能用合成JSON替代。

## 八、owner可以一次性提供／确认的项目

下面集中列清所需输入，避免每到一个型号再问一遍；未提供项目继续待外部，已授权无依赖工作不因此停下。

| 项目 | 一次性输入内容 | 收到后能推进到哪里 |
| --- | --- | --- |
| 正式请求合同 | 上述18精确模型、6API／13选项的现有正式资料；可一份覆盖多项但逐项标适用范围。不具备的明确列出，不索要平台源码 | 来源／身份复核、离线候选／审核编译、共享层必要补齐和边界回归 |
| 计量资料 | 两pixel、六Token SKU的正式上限／公式、usage／结果回执、单位与舍入／失败政策，精确SKU和版本 | 可信预占／结算／失败／重放／旧冻结的隔离验证；不自动改真实价格或启用 |
| 43文字权威记录 | 原账单／回执／网关追踪导出、账号范围／原调用唯一关联和实际usage；受保护本机文件，去掉密钥及无关客户内容 | 逐条唯一匹配、冲突／未明分类及具体处置清单；没有通用apply命令的部分另给窄范围实现预案 |
| 1个STT原回执 | 原成功或明确拒绝回执、原task_no／归属、审核人／依据，正文留受保护文件 | 现有凭据入口校验及结算／释放具体预案；不重复转写 |
| 可选后续实际处置，仅用户追加要求时 | 原scope默认仅权威核对和建议；追加实际处置时，对已形成的逐条可审预案明确记录范围、动作、金额上限；STT另明确是否允许唤醒后续运行及其模型操作／预算 | 才能执行对应真实处置；不强制批准此项才能完成原核对范围，不能用预先笼统“全部处理”代替尚未知事实与金额 |
| 任何新增真实provider读取／生成权限 | 若后续必须查询原记录或做实供应商验收，按总计划C01明确精确操作、账号／资源范围、调用／费用上限和窗口 | 才能执行获准的provider动作；现有合同准备／只读审计不授予这些权限 |

本文件只覆盖合同、计量和权威回执输入；真机／网络可达隔离端点、多机／混版／DB宿主故障与owner部署的具体环境要求仍按总计划C02–C04及手册逐项收集，不能由“提供合同”顺带视为环境或部署已完成。

停止条件：资料来源／身份／版本不符、原始声明被补齐内容替换、多个可能的回执关联、实际量／账务冲突、无可靠计量上限，或需要真实付费／配置／账务／部署动作而未取得对应具体授权。结果应区分“输入清单已准备”和“正式资料／真实处置仍等待”，不把手册完成记成全部完成。
