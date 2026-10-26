# 短剧源码清理边界（2026-10-27）

本次只删除经调用核对的死代码，不迁移、重写或清理业务数据。展示配置、现行接口、权限、任务状态及存储格式保持不变。

## 后端范围

删除 messageOnce、cleanStoryboardResultData、durationHintToSeconds、extractUserTextDurationHint 四个方法中无条件 return 之后不可达的代码，保留方法与现行返回行为。特别保留读取分镜时不自动删改用户镜头的保护。

删除以下 19 个未被生产代码调用的私有方法：

- filterReferencePayloadByAssetTypes
- markGenerationSuccess
- failGenerationTask
- shotImagePromptVisibleLength
- shotVideoFixedPrompt
- readableShotId
- readableShotTimeRange
- firstStoryboardImageAsset
- firstProjectShotImageAsset
- videoPromptHasRequiredMuteConstraints
- normalizeShortDramaVideoChannelParams
- channelModelGroup
- normalizePriceConfig
- priceConfigMap
- floorPriceConfig
- estimateScriptPlanBilling
- buildScriptPlanPrompt
- styleName
- options

## 后台范围

仅移除失效的 canvas-config.vue 页面及 getShortDramaCanvasConfig、setShortDramaCanvasConfig 两个专用请求封装。其后端控制器与当前菜单均已不存在。不改当前基础配置、Agent 配置、展示配置以及九个旧任务分类页面。

## 兼容边界与回滚

保留历史结果结构映射、旧画布结构读取、任务模型快照、导出素材选择、失败恢复、提示词兼容与人工修复工具。删除代码前使用 PHP token 记录所有方法的语义签名，清理后核对除四个不可达尾部外，所有保留方法签名一致。

验证使用既有历史项目和任务测试，并只读检查本地旧剧本的镜头、对白、时长与素材选择。不得为验收调用真实付费生成或修改用户项目。回滚只需还原两端源码提交，不需要数据回滚。
