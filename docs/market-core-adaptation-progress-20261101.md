# 核心模型适配：Q4首条链与后续差异

2026-11-01。以用户最新要求为准：只做现有工具必需适配，先Q4一条链，再按差异处理其它型号；增强验收暂不推进。API平台及平台源码严格只读。用户已明确真实生成由其后续手动测试，智能体不代生成、不追加授权询问。

## Q4已经完成的本地链

目标为AIGC商品235、平台模型108，`q4-preview-video / q4_video`，不是另一个`vidu-q4 / Official`型号。

1. 当前本地商品／SKU可用。单首帧、720p、5秒经本地只读报价、素材检查、prepare、冻结重放至提交计划均通过，SKU321，报价350算力；默认审核合同目录未启用新候选，原Q4规则本身已可准备请求。
2. 复现真实PC handler缺陷：旧`q4_options`在恢复时只进入页面状态，未进入实际请求；显式seed等还被默认策略覆盖。旧代码13项中4个新反例实际失败，证据`/tmp/b82-q4-pc-before.log`。
3. 最小修复仅在精确Q4型号保留当前声明允许的显式参数，旧字段按现行advanced/provider优先级迁移；未知、锁定和owned字段继续排除。其它视频默认策略保留，没有恢复旧专用面板。真实恢复→报价→提交handler及相关五组97项通过。
4. 同一份真实SFC handler生成的请求，以当前商品／SKU进入实际`AigcVideoService::estimate`、参数应用、prepare和冻结重放；估价／提交准备的原生请求SHA相同，false／0／seed123保留。执行仅到transport计划，未发送、未预占或扣费。初次集成夹具固定1080p造成SKU／价格预期不符，已校正夹具为720p；旧失败保留，不据此修改业务价格。
5. 当前PC源码预览`http://localhost:3000/ai/create?type=video&tenant_id=1`已正常登录，选中Q4 Preview，上传自备合成首帧，720p／5秒／关闭音频，页面实际显示350算力、生成按钮可用。没有点击生成。首帧仅用于本次手测，未改用户原任务或素材。

**通过范围是当前源码的提交前链路。** 没有把模拟API响应或提交计划当成真实供应商生成／结果认证。真实生成、同任务结果保存和再次复用由用户手测后再判断。正式站点静态产物未覆盖，长期worker1667未重启；预览不是发布。

## 已收尾的下载修复

前一轮已发现的普通视频／音频短响应问题现已修复：HTTP长度、分块终结、超时、大小、重定向最终头及关闭句柄均经38项回归通过；两类媒体各一次原worker短体→自然重试→同URL续租恢复通过，自有资源清理完成。普通路径25／45秒现为总时限；OpenSafeHttp安全路径保留。没有继续扩大数据库故障、混版或多机矩阵。

## 其它型号按差异处理

- 复用已有证据：其余17型号中15个已有原声明、未附加新候选时的prepare／replay成功记录，不重做同一套准备测试。
- H3、Wan原路径在旧证据中仅附候选验证，故本轮仅补这两个缺口：当前本地原声明的文本输入均经实际estimate／prepare／冻结重放通过，未启用新候选、未发HTTP、未写任务。H3选SKU120，Wan选SKU105。只证明这两个输入，不声称全部模式或真实结果已过。
- Grok／Veo旧直接prepare的超数量观察与公共quote准入分开；已有公共素材限额守卫和相关测试，不把直接prepare观察当作正常工具失败，只有实际差异才继续修。
- 九强制路由的完整执行／成本认证、8特殊计费规格及Full Video超过5张参考图费用绑定仍有单独依据门槛。它们不能被概括为“9型号必然不能请求”，也不阻塞Q4。真正使用到对应规格时才按精确差异补证，不索取整套平台资料。

## 当前需要用户配合

Q4不缺新的模型文档、路由图或特殊计量资料。用户手测已明确由其自行完成；我们保留已准备页面，等实际结果后只处理这一任务的具体错误。正式站点需要加载源码时，由owner按既有方式部署，我们不覆盖正式编译产物或发布版本包。

后续如某一型号确实缺依据，只追加精确型号／渠道、实际失败步骤及最小所需字段，集中更新本文；原[完整清单](market-completion-remaining-inputs-20261101.md)作为历史完整范围参考，不要求用户现在填写。43文字＋1STT、真机、多机／混版／断电等留在增强或历史事项，不作为当前模型适配前置。

## 源码与证据

- server应用源码：`980c887221714a6530ec58e7d6925ba5b02e618c`；本轮Q4后端无应用源码改动。
- web：`6c7def507017da9d1b5e23ce62e6d5e0d9a84dd6`；范围为Q4历史恢复、共享参数投影中的Q4分支及回归测试。
- 只读当前Q4：`/tmp/b82-q4-current-readonly.json`。
- 同一SFC请求与后端：`/tmp/b82-q4-pc-chain-requests.json`、`/tmp/b82-q4-pc-backend-final.json`。
- 聚焦回归：`/tmp/b82-q4-pc-focused.log`；H3／Wan：`/tmp/b82-video-model-differences-readonly.json`。
- 提交前截图：`/tmp/b82-q4-ready.jpg`；无隐私合成首帧：`/tmp/b82-q4-first-frame.png`。
- 原数据复核：143原任务、4080 LLM／Agent记录的数量和行哈希保持，余额4332.54；`/tmp/b82-original-data-final-audit.json`。

```text
task: 现有Q4视频工具提交前闭环及必要型号差异
branch: feature/short-drama-next-optimization
server scope: 本轮只读诊断、核心进度说明；前轮下载修复已验证
web scope: Q4历史恢复/参数投影/实际handler回归
contract changes: 无公开API/迁移/权限/路由/产品入口变化
completed / remaining: 提交前链通过，真实生成由用户手测；增强验收暂不推进
validation: PC聚焦97项；同SFC请求本地estimate/prepare相同；H3/Wan原路径两个缺口补齐
server commit: 980c887221714a6530ec58e7d6925ba5b02e618c (应用源码)
web commit: 6c7def507017da9d1b5e23ce62e6d5e0d9a84dd6
risks or blockers: 真实供应商结果未由智能体验证；正式资源和长期worker未重载
```
