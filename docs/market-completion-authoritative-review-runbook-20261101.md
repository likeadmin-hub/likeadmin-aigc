# 完整方案：正式合同、权威用量核对和真实环境执行步骤

日期2026-11-01。对应[总执行方案](market-full-completion-execution-plan-20261101.md)的 **B01／B02／B03／C01／C02／C03／C04**，精确资源见[合同与计量登记](market-completion-contract-register-20261101.json)。本文是可执行步骤与验收条件，**不是已执行结果，也不是正式供应商合同或操作授权**。

协调者 `/root` 负责Git与运行窗口；`contracts_recovery`负责本地资料审查、去重和核对步骤。所有项目命令必须从对应仓库的local develop执行，且它已包含最新feature提交；编辑前返回feature。子智能体没有自行运行本手册命令、修改真实记录或切分支的权力。

当前仍不执行：真实供应商生成／task query／retry／资产注册、真实市场／价格／模型配置同步或启用、真实账务修复、发布／打包／部署或长期服务重启。daemon1667、真实DB和宿主机不作为故障注入对象。不新建工具或通用跨设备草稿，不要求上游源码仓库，不自动向平台方发送资料请求。

## B01：接收并核对精确合同

### 1. 从登记中选择一个精确资源，不按相似名称合并

- 18个模型按`resource_type=model + generation_type + model_code + channel_code`唯一识别。14模型17条expanded opaque路径是其子集，零opaque路径不代表完整合同到齐。
- 6条应用API按`resource_type=app_api + app_code + api_code`识别，覆盖13个历史选项。Nano八个选项共用`nano_banana/submit`，但具体变体和SKU限制仍独立。
- 模型资源和应用API不可互相授予合同。Nano应用的同名选项不能直接使用Google模型合同；OpenAIYI与OpenaiM不能互换；渠道大小写不归一化猜测。
- 像素product275／SKU380和product277／SKU383是历史`official-33-* / official`身份，**不是**18集合中的`dashscope_compatible/qwen-image-3.0(-pro)`。登记将它们单独保留，不建立虚假的跨渠道依赖。Seedance六个Token SKU才精确关联`seedance/create` product140。
- 275／277在原baseline的native合同观察已为`matched`。执行时仍须重验其精确绑定，但不能要求它们先补齐上述两个不同渠道Qwen3合同；本项明确保留的是像素计量正式依据。
- 最新上游观察仍为批次50的`2026-10-31T18:38:45+08:00`；本日读取历史文件不能刷新该日期。未检查运行环境的trusted-path覆盖，不能凭默认reviewed文件不存在推断线上启用状态。

### 2. 接收输入，先做来源和覆盖审查

owner提供版本化网关schema／validator正式导出，或覆盖同等内容、可绑定版本的正式说明。无需密钥、用户提示词／素材、上游源码仓库或一次真实生成。保留原文件；合成边界例可以单独提交。需要的字段覆盖见[正式资料清单](market-provider-contract-export-request.md)。

每项输入必须具有：精确资源身份／平台数字ID、合同版本与对应网关版本；完整参数和每层对象／数组类型；必填、默认、枚举、数量／长度／数值边界；空值、false、0与额外字段语义；全部模式与素材角色、配额／总配额和互斥；SKU维度／计量／usage／失败结算；结果结构与成功／失败槽；最小合法与拒绝样例。

为每份原文件记录`source.kind/reference/revision/reviewed_at/artifact_sha256`。来源地址不包含认证、query或fragment。文件SHA只证明文件绑定，不证明来源真实、合同完整或上游实现一致；审核者须核对正式发布位置、版本和实际市场网关转换。示例不能成为默认值，单次成功不能替代边界。

停止条件：只有型号展示名、供应商原生文档、非该网关版本、相近渠道资料、示例缺子结构、无SKU上限／usage依据、文件指纹不符，或者内容会泄露密钥／私人素材。保留`waiting_authoritative_contract`，记录确切缺项；不补猜测字段来通过。

### 3. 模型合同：运行已有本地候选工具

只有上述输入已到齐并完成来源复核后，协调者安排执行。输入文件先由协调者放入容器内自有`/tmp/market-contract-input/`；以下两个输出在宿主机`/tmp/`，不得混入发布包或运行目录。

```sh
# 工作目录：server/ 的 local develop；此步骤不访问网络／数据库。
docker exec -w /www/wwwroot/likeadmin-aigc/server baota \
  /www/server/php/82/bin/php tools/market/prepare-generation-contract-candidates.php \
  /tmp/market-contract-input/bundle.json /tmp/market-contract-input/source-artifact.txt \
  > /tmp/market-contract-candidates.json
```

`bundle.json`采用现有`version:1/source/entries`。模型entry包含精确`generation_type/model_code/channel_code/public_model_code/generation_protocol`、完整`declaration.params_schema/material_capabilities`、全部`modes[code].schema/rules`。候选成功只说明机器约束可处理，`activation=review_candidate_only`不等于合同已审核或已启用。

工具只接受本地文件、各文件不超过8MB，不执行源文件。失败应读取本地原材料定位，不能将私有路径、原值或完整拒绝payload贴进聊天。来源不可信、schema关键字／协议未注册、素材路径不可完整表达、模式未覆盖时停止；需要软件支持则只在共享层补一次，并验证消费者，不增加工具专属型号分支。

### 4. 独立审核后编译模型reviewed目录候选

除上一层字段外，entry须提供`revision`和`binding_declaration.params_schema/material_capabilities`。binding必须来自该商品实际保存的**原始声明**，不能换成审核补齐后的schema。核对完才使用`--reviewed`：

```sh
# 工作目录仍为 server/ local develop。--reviewed是审核者声明，不是自动审核证明。
docker exec -w /www/wwwroot/likeadmin-aigc/server baota \
  /www/server/php/82/bin/php tools/market/compile-reviewed-generation-contracts.php \
  /tmp/market-contract-input/bundle.json /tmp/market-contract-input/source-artifact.txt \
  --reviewed > /tmp/market-reviewed-contracts-candidate.json
```

编译器只向stdout生成version2格式，不写trusted目录、不读DB、不联网、不启用型号。检查唯一身份、原声明指纹、完整内容digest、来源版本和每模式的素材路径／分类。输出中的`sku_pricing/live_generation=unverified`必须保留。

应用API资料走既有API专用声明／校验路径；本工具接受的是模型身份，不能把`nano_banana/submit`等6条API伪装成模型导入。`build-generation-contracts.php`要求上游源码checkout，不作为本方案必需步骤；不再次向用户索要不存在的上游仓库。

### 5. 离线回归、审阅和源码交付

协调者从已有`MarketGenerationContractReviewTest`、`MarketGenerationReviewedContractTest`、`MarketNativeDocumentParametersTest`、`MarketNestedImageMaterialsTest`、`MarketMixedVideoLifecycleTest`选取受影响协议族；新增真实合同的合成合法／拒绝样例，不调用provider。用例至少覆盖全部模式、数组子项、false／0／空值、未知额外字段、条件／互斥、角色／配额、SKU冲突、渠道隔离和旧冻结不被新目录替换。

```sh
# 仅由协调者在 server/ local develop执行；按本机现有PHPUnit路径。
docker exec -w /www/wwwroot/likeadmin-aigc/server baota \
  /www/server/php/82/bin/php -d disable_functions= -d memory_limit=1G \
  /tmp/aigc-phpunit-9.phar --bootstrap vendor/autoload.php \
  tests/Feature/MarketGenerationReviewedContractTest.php
```

上例只证明该套件的结果，不替代其他受影响套件；需要显式数据库开关的既有场景由协调者启用并单独记录执行／跳过，不能将跳过视为通过。记录源码feature／develop SHA、命令、输出摘要和证据文件。

将已审真实条目纳入受信任源码目录是另一个可审阅源码改动；候选文件不能直接当活动目录。历史任务保留冻结版本，目录变更不回填旧快照。源码提交、local develop验证、feature推送后仍不等于真实市场配置启用／部署／真实生成完成。

## B02：请求合同、SKU计量和原生绑定分别通过

三者是AND门槛：**精确请求／模式合同 + 可信SKU计量上限／回执 + 使用原生API时匹配的已发布计费绑定**。它们相互独立，不能由一个通过顺带授予另一个；也不能把两个不同渠道的Qwen资源连成同一依赖。

### 像素SKU380／383

1. 对照登记精确核对product275（编辑）／277（文生图）、SKU与版本、真实计费单位／单位大小／舍入。要求覆盖所有合法请求的正式尺寸映射，或单张像素硬上限＋最大输出张数，明确auto尺寸／数量政策。
2. 要求完整输出列表路径、每项URL字段、重复槽语义、失败／隐藏／未转存槽以及缺回执处理。不得拿供应商或浏览器自报宽高当实际计费尺寸。
3. 已有version3实现为`MarketGenerationPixelMeter`。在可信合同及隔离数据下验证：请求／全局上限预占、服务端读取字节测量成功槽、重复URL按槽计量、未完成转存拒绝、缺尺寸／超上限保留核对、明确失败一次释放、同回执重放不再次结算。
4. 固定应用零售价与市场成本分开检查，旧快照／按次路径不重算。若新正式表达超出现有格式，先共享实现／回归，不以新常数代替依据。

### Token SKU210–215

1. 精确对应Seedance product140／`seedance/create`：480p210／211、720p212／213、1080p214／215，每对分别有视频／无视频输入。逐SKU核对所有允许输入，不能只提供某个分辨率／时长的单例。
2. 要求SKU保证最大可收费Token，或覆盖全部合法输入且可证明上界的完整正式公式、usage字段、异常／失败结算、单位大小及舍入。UI预算、视频长度或型号名称不能代替此上限。
3. 已有version2实现在`MarketGenerationMeter`。验证上限预占、指定路径实际usage、缺usage保留`pending_usage`、超上限保留核对、明确失败退款／重复幂等及原生published绑定过期拒绝；不用Token数推断图片／视频成功槽。
4. 沿用`MarketBoundedTokenBillingTest`、`MarketPixelBillingTest`、`MarketNativeBoundedTokenTest`、`MarketNativeMeterExpansionTest`及受影响业务的现有冻结回放。相应显式回滚开关由协调者安排，所有真实财务／价格写入仍为0。

Nano嵌套`input/params/parameters`、原生张数、裸`b64_json`与供应商asset ID，以及H3原生`provider_params.content`合并，只有精确网关依据到齐才接入。标准data URI、统一业务素材或已有共享编码器不授予这些形式。

完成证据为精确合同／SKU／模式、预占与实际量、一次账务、缺量／超界／失败／重放／旧冻结的离线结果。当前登记不含真实上限值、不填充缺失合同、不自动启用真实SKU。

## B03：同步文字未知受理的权威核对

### 1. 限定范围，先执行现有只读检查

协调者按任务实际归属确定tenant、user、app和business-table；下面以tenant1／user1首页Agent举例，不能将该数字硬编码为通用归属。

```sh
# 工作目录：server/ local develop。不得加 --repair-assets 或 --apply。
docker exec -w /www/wwwroot/likeadmin-aigc/server baota \
  /www/server/php/82/bin/php think ai:market-audit \
  --tenant-id=1 --user-id=1 --app-code=aigc_llm \
  --business-table=aigc_llm_agent_run --limit=500 --fail-on-issues \
  > /tmp/market-text-readonly-audit.json

docker exec -w /www/wwwroot/likeadmin-aigc/server baota \
  /www/server/php/82/bin/php think home-agent:health --attention \
  > /tmp/market-agent-readonly-health.json
```

`ai:market-audit`返回1且有issue表示发现待核对项，不是修复成功；其limit至多5000且无翻页选项，超过范围不能宣称全量审计。`home-agent:health`退出0为健康且无attention、3为待人工关注、2为不健康、1为检查异常；分别保留结果。不要为消除退出码重启真实worker。

正文／密钥／原始请求不进入报告。收集本地run／app_task／consumption、归属、冻结版本摘要、现有request/task ID有无、usage来源、回执完整性和账务状态。`AgentTextReceipt::read`会校验租户／用户／消费／任务／run及来源资产权限，仅在授权运行环境读取；它的读取本身不结算、不唤醒或重发。不要把私有加密回执正文贴进公共台账。

### 2. 四类回执分组

| 组 | 已有事实 | 可做的核对／保留状态 | 禁止替代结论 |
| --- | --- | --- | --- |
| 完整可信回执＋可用usage | 归属、完整终止标志、request ID及usage可与冻结请求关联 | 形成现有恢复入口的审阅建议；先隔离验证一次结算／重复不增账，再按真实账务授权处理 | 不因已有正文直接再次收费或把不同模型usage拼接进来 |
| 完整正文但缺usage | 正文可以恢复，实际用量仍未知 | 保留正文及`pending_usage`／核对状态，索要可唯一关联的原账单／回执 | 不估算Token；`settleRecoveredAppTask`保留待usage不等于已收费结案 |
| 部分回执 | 已有片段或ID，缺可靠完整结束／完整usage | 保留片段和证据，`needs_review`；只做权威比对 | 不把部分usage当最终总量，不自动重发或超时退款 |
| 完全无回执 | 无完整回执、usage、供应商ID或足够唯一关联 | 保持未知／`needs_review`，等待权威记录；报告明确“尚无法核对” | 不能凭时间接近、余额差、同名型号或提示词相似判定受理／失败 |

### 3. 接收权威记录并唯一关联

owner提供既有供应商账单／原回执／正式网关追踪导出，注明账号范围、精确请求ID或可证明的本地关联、时间范围、模型／版本、最终状态和usage；无需提供API密钥或用户正文。没有provider ID时，只有正式可证明的关联才能补足，不能由智能体猜选时间最近的一条。

逐条输出：本地ID、资料来源／SHA、关联依据、结论（未受理／明确失败／成功及实际usage／仍未知）、冲突项、建议处置、审核责任人。复核一致后才形成可审阅的真实账务处置预案；**当前不apply、不修改真实账务、不补扣／退款、不改任务终态、不回填用户历史**。用户已确认13:09手动对话和40.42算力变化须原样保留。

`ai:market-audit --repair-assets --apply`是资产修复，不是文字账务核对入口。`settleCopywritingReceipt`只接受文案对应业务归属回执，不是通用Agent人工修账接口；不发明一个并不存在的apply命令。

停止条件：权威来源不可验证、存在多个候选、归属／冻结模型冲突、usage不完整、已有终态／账务冲突，或者需要新的真实provider查询／付费／账务授权。当前代码将失联未确认执行置为`needs_review`而不自动重发；无权威依据时继续等待是正确状态，不能用代码推导供应商是否实际完成。

## C01：真实供应商有限验证（原环境分项E01）

前置输入：对应B01/B02离线通过；owner明确精确资源／合同版本／SKU／模式、允许的生成／query／retry／资产操作、单次及累计预算、正常测试账号、无隐私合成素材、执行窗口和清理范围。旧268算力授权已经结束，本手册不产生新收费授权。

1. 先准备最小代表用例：按已支持的协议／素材结构／计量族及明确差异选择，列出每次最大费用、预期结果与停止点；不对每个相同协议SKU盲目重复强杀。
2. 在实际预算核对后，从正常入口提交一个独立意图。保存同一受理编号、本地任务／消费／供应商ID关联，不因丢响应立即再发。
3. 追踪原worker、实际usage与冻结计价、受管真实字节／MIME／解码、业务结果／父状态、只读历史和明确新意图；不得将两个不相干任务拼成一条链。
4. 对明确支持的失败／退款场景检查一次账务，未知受理立即停止新增生成并进入B03或对应专用核对。达到费用上限、资料／返回结构冲突、归属错误或出现第二份意外扣费即停止。
5. 清理只限明确归属的测试对象／fixture；真实业务记录按批准的保留策略处理，不永久删除来掩盖失败。报告真实质量、协议通过、计量通过分别结论，不由替身结果替代。

完成标准：每个选定场景可沿唯一任务复验，一次账务、合法受管结果与历史均有证据。**当前状态为等待具体执行窗口，未执行以上真实动作。**

## C02：真机与物理跨设备（原环境分项E02）

前置输入：已有受支持微信真机／原生App构建、设备及正常登录会话、批准的测试URL／tenant和独立测试账号；跨设备场景需要两台独立设备／浏览器。优先使用可访问的隔离测试端点及本地供应商替身已有任务，确需新真实生成时受C01约束。

1. 记录设备／系统／终端／构建SHA与测试URL；正常登录，不注入token。检查tenant上下文和会员／应用开放状态，不用合成外壳替代正式壳认证。
2. 经原生picker选合成图片／文档／音视频，核对真实MIME、字节、大小边界和归属；不要请求与测试无关的设备权限。
3. 对已有测试任务观察切后台／前台、断开页面／重开、原受理恢复、受管结果／历史和授权下载；区分页面存活和任务存活。
4. 在两端读取已有服务端持久化的画布／短剧／Agent记录，校验身份、输入和原任务；普通工具本地reuse缓存不承诺跨设备共享，不新增草稿产品。
5. 切换账号／租户后验证迟到请求、上传响应、读重试不会回写或退出新账号；退出测试会话只用正常产品动作。

完成证据：实际设备截图／构建版本、输入文件摘要、同一任务身份、暂停／重开及跨端观察。Node包装器、H5浏览器和MP编译通过不能代替它。没有设备／批准可访问端点时停止；不得将127.0.0.1私有fixture直接开放LAN或擅自扩权来凑真机验证。

## C03：更深恢复与基础设施故障

每项先登记拥有的fixture前缀、进程、对象、环境版本、故障点、预期状态、恢复步骤及最长等待；使用自有隔离环境。以下分项对应原审查E03–E06，不是新增顶层任务ID。

### C03.1 OSS网络与结果源过期（原E03）

已通过的“OSS上传完成、数据库回执未提交时杀worker”不重复计为新缺口。尚需区分以下机制：

1. 在隔离测试传输边界注入**OSS未接受内容即断连／超时**：不得出现成功回执或业务成功；失败／重试状态保留，恢复连接后原任务可继续。
2. 注入**OSS已接受但响应丢失**：重试使用原稳定键，最终字节SHA相同，只有一份当前文件登记／资产／业务结果，冻结计费不变，不创建新的生成任务。
3. 结果源在首次下载前／下载未完成时返回签名过期或403：未完成字节不能暴露正式URI，保留原任务和受理关联。是否刷新URL须有原协议正式依据；本地替身可模拟已有合法刷新，不能自行调用真实供应商query或重生成。
4. 对已完整持久结果再让源地址过期：历史应继续读取受管结果，不回退旧供应商地址、不重复下载／计费。

确定性测试注入只证明该恢复边界；真实OSS网络故障认证仍需批准的独立网络环境。不得修改全局DNS、防火墙、TLS校验或当前云凭据。实际云对象只用随机假租户／用户路径，逐对象记录创建／删除及删除后存在性；清理失败则保留精确owner和键供处理，不扫描删除用户桶内容。

### C03.2 多机与混合版本（原E04）

需要owner提供可核验的两个版本运行实体或已有隔离镜像、独立节点、共用私有DB／存储；不创建另一份feature checkout或临时仓库来违反本机分支规则。没有旧版本运行实体不能用常量模拟冒称混版认证。

1. 记录旧／新worker源码SHA、支持job类型、schema／冻结版本兼容范围；只连接自有前缀和存储根。
2. 由旧版本和新版本同时领取队列，验证旧版本不领取不认识的类型，两个执行者不能同时拥有同一有效租约。
3. 在下载／写入／回执／业务投影的明确边界停止拥有的执行者；另一节点自然租约接管。让旧执行者迟到回调，赢家字节、正式登记、终态和账务不得被改变。
4. 模拟停止新版本、仅保留旧版本的回滚窗口：不支持的新作业应保留等待，不能丢弃、降级运行、退款或重新生成来“恢复”。重新提供兼容版本后原作业收尾。

成功条件：同一原任务最终一套结果和账务；原冻结／人工编辑不变；未知作业未被误领；重复启动不改终态。不同节点／不同版本和同机同版本并发分别记录。遇到真实队列、非自有进程、schema不兼容或未知未结算账务立即停止，不重启daemon1667。

### C03.3 DB、宿主机与持久性（原E05）

需要完全可丢弃的独立DB实例／VM／测试磁盘和恢复点。当前真实DB、用户主机和持久运行环境不得注入故障。

1. 对数据库事务提交前／提交后、文件正式发布前／发布后、业务投影前／后分别定义一次故障点，记录事务与文件检查点；不把所有故障混成“进程被杀”。
2. 仅停止自有测试DB／VM／worker，按预先记录的恢复点重启；核对成功提交是否保留、未提交是否回滚、队列能否接管、正式文件是否完整。
3. 对账为一次预占／结算／释放，未知回执保持未知，不自动重发。用真实文件摘要／MIME／解码和原业务关联验收，不能只比较URL。
4. fsync调用成功、应用进程SIGKILL和真实断电是不同证据；没有相应VM／磁盘故障机制，不宣称断电持久性认证。

所有故障必须可限制于指定隔离实例；若无法证明隔离，或需要停止用户真实服务，停止该分项，保留`waiting_external`。

### C03.4 长期锁／暂存保留与回收（原E06）

1. 先只读产出候选报告，包含精确owner、文件类型／路径摘要、对应任务／资产状态、租约、已知执行者、年龄、内容完整性及可能等待者，不直接删除。
2. 空锁文件不能仅按mtime过期清空；仍有持锁者或等待者时删除会让不同执行者锁住不同inode。无法证明无人使用即拒绝回收。
3. 完整暂存、部分暂存、已发布正式文件分别处理：可验证完整暂存应优先恢复发布；失败／废弃且无活动执行者的自有暂存才进入可审阅删除建议。符号链接／非普通文件／未知路径拒绝。
4. 真正回收须在另行批准的范围内逐owner执行，记录删除前后清单及正在运行／等待写者不受影响的证据；不创建定时自动清理，不全局清理`/tmp`或用户上传目录。

### C03共同清理与不变量

- 清理只能处置本次owner标记的fixture表／根、明确子进程和精确云对象；不能按宽泛前缀删除其他批次或真实对象。
- 未完成字节不暴露正式URI；有效结果不重发生成；当前受管登记／业务结果各最多一份，重复用途与真实输出槽不因URL相同被错误合并。
- 账务／退款幂等、终态不回退、冻结请求／价格／计量不变；消费受管结果投影可按原合同变化，不能错误要求整个消费行永远不变。
- 比较原用户表行摘要与余额，用户确认的合法新活动单独归因；不得为恢复旧计数删除记录、改余额或账务。
- 保存失败证据后，确认自有子进程停止、私有表／根回收、云对象删除。清理失败保留owner清单和未清项，不以“测试结束”假定成功。

## C04：owner部署与实际版本加载（原D01）

这是owner执行阶段，不属于智能体source-only交付动作。前置输入是owner选择的实际部署方式、目标环境、维护窗口、备份／回滚点和负责人；不由智能体创建／上传／发布系统／应用版本包，不自行重启长期服务。

1. 协调者提供两仓feature SHA、原测试所用develop SHA、已通过／失败／待外部任务、必要迁移清单（无则明确无）、依赖／配置差异、受支持队列类型和在途任务兼容要求。
2. owner按其正式方式部署，保留本机proxy、租户存储及私有配置。记录实际加载的后端源码／H5静态资源指纹和worker版本；Git源码存在不等于已运行该版本。
3. 先做正常登录、租户／会员、现有入口、历史和只读审计，复验A03记录的H5分页／resize触发步骤。旧资源差异造成的日志在新版本下有实际证据才可结案。
4. 对仍在队列中的旧任务确认冻结合同和关联保留、兼容worker可以收尾；不支持的新作业等待相应版本，不能删除任务／资产／账务或重新生成来替代恢复。
5. 真机／真实生成部分分别服从C02／C01环境与预算，不能由部署成功顺带认证。发现加载版本不符、迁移不完整、归属／账务异常即停止扩大发布，由owner按回滚点处理。

完成条件是owner提供实际部署／加载记录，以及适用的正常壳／历史／队列复验。若没有该记录，只可记`ready_for_owner`或`waiting_owner_execution`，不能标“全部完成”。回滚仅回兼容代码／部署，不回填历史冻结，不销毁已受理事实。

## 每项结果的保存格式

每次记录顶层任务ID、精确资源／SKU／模式或故障分项、负责人、执行授权／环境输入、源码与实际运行版本、命令／触发步骤、观察到的事实、账务／归属／清理证据、成功／失败／停止原因和下一依赖。来源不含密钥、私有正文或完整敏感请求；记录SHA和最小元数据即可。

`B01/B02`的候选／手册准备完成，与其中真实资源的`waiting_authoritative_contract`并存；`B03`的核对流程准备完成，不等于未知账务已结案；`C01–C04`依赖未提供时继续保持待外部／待授权／待owner状态。只有每个适用任务实际达到其完成条件，才使用“完整方案全部完成”。
