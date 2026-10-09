# 短剧新流程撤回核对记录

日期：2026-10-10。范围：本对话实施的 VNext 方案。任务分支：`feature/aigc-upgrade`。

## 基准与边界

- 后端首个新核心提交：`637b5aff2`，恢复基准：`8ffc67ea5`。
- 前端首个新核心提交：`871b2b9e`，恢复基准：`dcbd4948`。
- 对话记录确认上述两个提交属于“执行短剧优化方案”之后的首批实现。此前老流程功能保留。
- 不改写 Git 历史；在任务分支提交撤回变更。

## 保留的共用修复

- 原有画布剧本就绪后继续下一集；核验任务归属、当前版本及连续性摘要，不依赖视频全部生成。
- 从同项目已归属的历史画布页复用晚到主体和场景素材，仍核对签名与资产状态。
- 规划队列轮转扫描，避免前一页等待任务阻塞后续任务。
- 将声音、视频契约验证从旧构建文件改为源码及实际行为测试。

## 数据与兼容

- 不 DROP 新表，不删除剧本、任务、原始附件、素材、收据、账单或本机配置。删除的是新增安装和升级 SQL 源码。
- 撤回规范正文核心、执行器、修订、制作交接及 PC 专属界面。新建任务恢复原有 generation v3 / content-first v9 流程。
- 仅保留一个已撤回试验任务的只读兼容器：按租户、用户、任务读取已存正文；阻止其重试、修改和进入老引擎执行，不调用模型或结算。
- PRD 作为撤回的设计历史保留并明确标注；旧实施进度文档删除，避免继续按它推进。
- 三个真实新流程小样已失败，原调用状态未知的收据保持原样，不重发。

## 文件核对清单

### server

| 文件 | 处理 |
|---|---|
| `app/api/controller/app/aigc_short_drama/ScriptPlanController.php` | restore-legacy-file |
| `app/apps/aigc_short_drama/manifest.json` | restore-legacy-file |
| `app/apps/aigc_short_drama/migrations/install.sql` | restore-legacy-file |
| `app/apps/aigc_short_drama/migrations/upgrade_20261009_vnext_execution.sql` | delete-new-file |
| `app/apps/aigc_short_drama/migrations/upgrade_20261009_vnext_retry.sql` | delete-new-file |
| `app/common/command/ShortDramaEpisodeWorker.php` | restore-legacy-file |
| `app/common/command/ShortDramaPlanningWorker.php` | keep-shared-fix |
| `app/common/service/app/aigc_short_drama/AigcShortDramaService.php` | restore-legacy-file |
| `app/common/service/app/aigc_short_drama/ShortDramaAttachmentSource.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/ShortDramaCanvasService.php` | restore-legacy-file |
| `app/common/service/app/aigc_short_drama/ShortDramaEpisodeService.php` | restore-legacy-file |
| `app/common/service/app/aigc_short_drama/ShortDramaOutputLimitException.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/ShortDramaSourceBoundary.php` | restore-legacy-file |
| `app/common/service/app/aigc_short_drama/ShortDramaStoryDraft.php` | restore-legacy-file |
| `app/common/service/app/aigc_short_drama/ShortDramaStoryGeneration.php` | restore-legacy-file |
| `app/common/service/app/aigc_short_drama/ShortDramaStructuredResponse.php` | restore-legacy-file |
| `app/common/service/app/aigc_short_drama/canvas_agent/ConversationAssetReuse.php` | keep-shared-fix |
| `app/common/service/app/aigc_short_drama/canvas_agent/ConversationProduction.php` | restore-legacy-file |
| `app/common/service/app/aigc_short_drama/canvas_agent/ConversationSeries.php` | keep-shared-fix |
| `app/common/service/app/aigc_short_drama/canvas_agent/ConversationWorkflow.php` | keep-shared-fix |
| `app/common/service/app/aigc_short_drama/canvas_agent/GenerationIntentService.php` | restore-legacy-file |
| `app/common/service/app/aigc_short_drama/canvas_agent/GraphService.php` | restore-legacy-file |
| `app/common/service/app/aigc_short_drama/vnext/ApplicationWorkflow.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/AttachmentScreenplay.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/AttachmentSourceView.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/AudioHandoff.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/AudioMeasurementWorker.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/AudioMediaLoader.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/AudioMediaProbe.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/AudioProduction.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/AudioTimeline.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/CanvasProductionProjection.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/CanvasSeriesBridge.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/ConstraintRevision.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/ContentCoverage.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/ContentDocument.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/DocxEmbeddedText.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/DocxTextView.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/EntityIdentityRevision.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/EntityMergeRevision.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/EntityRegistryRevision.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/ExecutionStore.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/FactConsumption.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/GenerationPipeline.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/ImageHandoff.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/JobRunner.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/LocalTransaction.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/ManualContentRevision.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/ManualRevisionHistory.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/MissingContentLocation.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/NarrativeHistoryIndex.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/NarrativeLedger.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/PlanningTrace.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/ProductionCompiler.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/PromptProjection.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/ReceiptBuffer.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/ResourceBudget.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/SceneContentRepair.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/SceneReviewExecution.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/SceneScreenplayExecution.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/SceneShotExecution.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/SceneShotRepair.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/SeriesContext.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/SeriesVisualState.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/ShotCompiler.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/SourceTrace.php` | delete-new-file |
| `app/common/service/app/aigc_short_drama/vnext/StructuralRevision.php` | delete-new-file |
| `app/common/service/power/MarketTextModelRuntimeService.php` | restore-legacy-file |
| `app/common/service/power/TextRequestNotSubmittedException.php` | delete-new-file |
| `config/short_drama.php` | restore-legacy-file |
| `docs/short-drama-generation-vnext-prd-20261009.md` | restore-legacy-file |
| `docs/short-drama-vnext-implementation-20261009.md` | delete-new-file |
| `public/install/db/like.sql` | restore-legacy-file |
| `tests/Feature/CanvasEpisodeAdvanceTest.php` | keep-shared-fix |
| `tests/Feature/CanvasLinkedVideoNodeContractTest.php` | keep-shared-fix |
| `tests/Feature/CanvasSeriesProductionTest.php` | keep-shared-fix |
| `tests/Feature/MarketTextAccountConcurrencyTest.php` | delete-new-file |
| `tests/Feature/MarketTextAccountReservationTest.php` | delete-new-file |
| `tests/Feature/MarketTextDurableReceiptTest.php` | delete-new-file |
| `tests/Feature/ShortDramaAttachmentSourceTest.php` | delete-new-file |
| `tests/Feature/ShortDramaFileQaExtractionPersistenceTest.php` | delete-new-file |
| `tests/Feature/ShortDramaFileQaTerminalPersistenceTest.php` | restore-legacy-file |
| `tests/Feature/ShortDramaImportedConfirmationTest.php` | restore-legacy-file |
| `tests/Feature/ShortDramaImportedScriptTest.php` | restore-legacy-file |
| `tests/Feature/ShortDramaPlanningRevisionTraceTest.php` | delete-new-file |
| `tests/Feature/ShortDramaPlanningTraceTest.php` | delete-new-file |
| `tests/Feature/ShortDramaPlanningWorkerQueueTest.php` | delete-new-file |
| `tests/Feature/ShortDramaStoryDraftPersistenceTest.php` | restore-legacy-file |
| `tests/Feature/ShortDramaSubjectMentionTest.php` | keep-shared-fix |
| `tests/Feature/ShortDramaSubjectVoiceContractTest.php` | keep-shared-fix |
| `tests/Feature/ShortDramaVnextApplicationTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextAttachmentSeriesTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextAttachmentSourceViewTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextAttachmentTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextAudioMediaLoaderTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextAudioProductionTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextAudioTimelineTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextCanvasProjectionTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextConcurrentLoadTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextConstraintRevisionTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextContentTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextDocxEmbeddedTextTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextEntityMergeRevisionTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextEntityRegistryRevisionTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextExecutionTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextFactConsumptionTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextIdentityRevisionTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextIdentitySeriesTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextImageHandoffTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextLocalTransactionTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextLongSeriesTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextManualRevisionTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextMigrationTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextMissingContentLocationTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextNarrativeHistoryIndexTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextNarrativeTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextPipelineTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextProductionTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextPromptProjectionTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextResourceBudgetTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextSceneContentRepairTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextSceneReviewTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextSceneScreenplayTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextSceneShotRepairTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextSceneShotTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextSeriesTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextSourceTraceTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextStructuralRevisionTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextVisualStateTest.php` | delete-new-file |
| `tests/Feature/ShortDramaVnextWorkerTest.php` | delete-new-file |
| `tests/fixtures/short_drama_account_fixture.php` | delete-new-file |
| `tests/fixtures/short_drama_account_worker.php` | delete-new-file |
| `tests/fixtures/short_drama_upload_fake_storage.php` | delete-new-file |
| `tests/fixtures/short_drama_vnext_fake_provider.php` | delete-new-file |
| `tests/fixtures/short_drama_vnext_fake_queue.php` | delete-new-file |
| `tests/fixtures/short_drama_vnext_load_worker.php` | delete-new-file |
| `upgrade/20261009_short_drama_vnext_execution.sql` | delete-new-file |

### web

| 文件 | 处理 |
|---|---|
| `pc/api/short_drama.ts` | restore-legacy-file |
| `pc/apps/aigc_short_drama/AttachmentSource.vue` | delete-new-file |
| `pc/apps/aigc_short_drama/AudioProduction.vue` | delete-new-file |
| `pc/apps/aigc_short_drama/AudioTimeline.vue` | delete-new-file |
| `pc/apps/aigc_short_drama/BudgetExtensionEditor.vue` | delete-new-file |
| `pc/apps/aigc_short_drama/ConstraintChoiceEditor.vue` | delete-new-file |
| `pc/apps/aigc_short_drama/EntityMergeEditor.vue` | delete-new-file |
| `pc/apps/aigc_short_drama/IdentityFactsEditor.vue` | delete-new-file |
| `pc/apps/aigc_short_drama/ManualRevisionHistory.vue` | delete-new-file |
| `pc/apps/aigc_short_drama/StructuralEditor.vue` | delete-new-file |
| `pc/apps/aigc_short_drama/attachment-source.mjs` | delete-new-file |
| `pc/apps/aigc_short_drama/audio-production.mjs` | delete-new-file |
| `pc/apps/aigc_short_drama/audio-timeline.mjs` | delete-new-file |
| `pc/apps/aigc_short_drama/budget-extension.mjs` | delete-new-file |
| `pc/apps/aigc_short_drama/canvas/next-episode-action.mjs` | keep-shared-fix |
| `pc/apps/aigc_short_drama/constraint-choice.mjs` | delete-new-file |
| `pc/apps/aigc_short_drama/entity-merge.mjs` | delete-new-file |
| `pc/apps/aigc_short_drama/identity-facts.mjs` | delete-new-file |
| `pc/apps/aigc_short_drama/manual-history.mjs` | delete-new-file |
| `pc/apps/aigc_short_drama/structural-edit.mjs` | delete-new-file |
| `pc/components/short-drama/ShortDramaCanonicalImages.vue` | delete-new-file |
| `pc/components/short-drama/ShortDramaGenerationSnapshot.vue` | delete-new-file |
| `pc/components/short-drama/ShortDramaPlanningTrace.vue` | delete-new-file |
| `pc/components/short-drama/ShortDramaStoryWorkspace.vue` | restore-legacy-file |
| `pc/components/short-drama/canvas/CanvasAgentConversation.vue` | keep-shared-fix |
| `pc/components/short-drama/canvas/ShortDramaCanvasNode.vue` | restore-legacy-file |
| `pc/pages/ai/short-drama/episode.vue` | restore-legacy-file |
| `pc/pages/ai/short-drama/episodes.vue` | restore-legacy-file |
| `pc/pages/ai/short-drama/plan.vue` | restore-legacy-file |
| `pc/tests/short-drama-attachment-source.test.mjs` | delete-new-file |
| `pc/tests/short-drama-audio-production.test.mjs` | delete-new-file |
| `pc/tests/short-drama-audio-timeline.test.mjs` | delete-new-file |
| `pc/tests/short-drama-budget-extension.test.mjs` | delete-new-file |
| `pc/tests/short-drama-canvas-canonical.test.mjs` | delete-new-file |
| `pc/tests/short-drama-canvas-composer.test.cjs` | keep-shared-fix |
| `pc/tests/short-drama-canvas-next-episode.test.mjs` | keep-shared-fix |
| `pc/tests/short-drama-canvas-video-compatibility.test.cjs` | keep-shared-fix |
| `pc/tests/short-drama-constraint-choice.test.mjs` | delete-new-file |
| `pc/tests/short-drama-entity-merge.test.mjs` | delete-new-file |
| `pc/tests/short-drama-episode-recovery.test.mjs` | restore-legacy-file |
| `pc/tests/short-drama-generation-snapshot.test.mjs` | delete-new-file |
| `pc/tests/short-drama-identity-facts.test.mjs` | delete-new-file |
| `pc/tests/short-drama-manual-history.test.mjs` | delete-new-file |
| `pc/tests/short-drama-planning-trace.e2e.mjs` | delete-new-file |
| `pc/tests/short-drama-revision-save.test.mjs` | delete-new-file |
| `pc/tests/short-drama-story-outline-v2.e2e.mjs` | restore-legacy-file |
| `pc/tests/short-drama-structural-edit.test.mjs` | delete-new-file |
| `pc/utils/short-drama-canvas-canonical.mjs` | delete-new-file |
| `pc/utils/short-drama-episode-state.mjs` | restore-legacy-file |
| `pc/utils/short-drama-generation-snapshot.mjs` | delete-new-file |
| `pc/utils/short-drama-revision-save.mjs` | delete-new-file |

## 验证

- 在两个已集成最新任务提交的本地 `develop` 分别验证；PHP 8.0 语法检查通过。
- 后端 341 项 / 9,178 条断言全部通过：覆盖旧生成、设定与大纲保存、内容核验、系列连续性、分集队列、取消与重试、附件解析、人名和声音引用、文本模型回退、提交幂等与画布资产复用。
- 前端 58 项全部通过：覆盖附件发送、下一集入口、队列恢复、视频引用、设置保存及提交失败/取消/完成状态。8 项旧视频测试最初因缺失 imported prompt helper 夹具而失败，补齐依赖后通过；对应产品工作台源码未修改。
- 本地 PC 工作区和恢复后的创作页可打开，Nuxt/Nitro 编译通过；原有重复组件名及 Node 弃用提示仍存在。
- 已成功读取原流程故事设定，读取前后数据库 `request_json/result_json` 哈希一致。
- 已读取失败试验稿全文及指定尾句，界面只读，撤回引擎类不存在，活动试验生成任务为 0。
- 数据库回归验证试验稿不能重试/修改、不能跨租户读取，任务、归档及账单记录不变。
- 保留的老生成核心、统一核验、故事生成/保存、模型运行服务及默认配置与恢复基准完全一致。
- 本地 Supervisor 工作组已正常重启，三个短剧 worker 已重新启动并载入恢复代码。
- 本次不触发真实模型或图片生成，不将上述回归结果宣称为新的真实生成质量验收。
- 三个已撤回试验任务中的两项上游提交状态仍未知；既有记录保持原样，未自动重发或改写结算。
