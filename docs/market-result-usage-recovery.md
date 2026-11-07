# 已生成媒体等待实际用量的任务恢复

2026-11-07，分支 `feature/mini-program-optimization`。

## 问题与修复范围

线上视频任务 386 的业务状态为 success，已有可播放的视频；统一任务仍为 running / settling，billing_status 为 pending。这说明生成和交付已经完成，但账务尚未完成，不能通过前端强制显示成功来解决。

共享调度器原先仅凭 run_status=success 就跳过适配器；查询作业也随之结束。视频按实际时长结算、图片和音乐等待实际 Token 用量时，都会进入这一状态。补偿命令原先又无法重新打开被误标为 success 的查询作业。

本次修改：

1. 市场异步媒体已生成、但账务仍为 reserved / pending_usage 时，继续执行原适配器的查询或本地媒体时长恢复。
2. 查询作业保持等待，直到原有结算路径完成。结果登记与转存继续执行，避免相互等待。
3. 现有补偿命令可以唤醒上述误结束作业，沿用原消费记录和作业身份。
4. 兼容缺少 protocol / model_type 的旧画布、短剧视频回执：从已冻结的实际时长合同和已有视频结果识别恢复路径。
5. 只读审计同时报告 success + reserved 的此类媒体任务。

未修改前端、价格、计量规则、点数服务、接口响应结构、数据表、迁移、权限、菜单或应用清单。已结算、已退款、失败、取消、非市场提供方和文本用量复核保留既有处理。

## 验证

所有运行检查均在包含 feature 提交的本地 develop 执行，完成后返回 feature。

- 204 项 PHPUnit 测试通过，覆盖查询状态、统一任务投影、视频实际时长、图片模型、图片应用、视频 Token 上限、原生接口与文本用量复核。
- 6 类离线路由检查通过：视频、图片、音乐、口型、画布、短剧。验证等待回执期间保留查询与结果登记，回执就绪后继续，已结算和已退款任务不再次查询。
- 视频数据库回滚测试同时覆盖现代及旧版回执，使用本地 32×32、1.04 秒视频。验证等待转存、保留预占、补测时长、幂等结算、原作业恢复、资产不重复登记和最终停止轮询。
- 音乐工作流及 9 个终态/退款场景通过，数据库均回滚。
- 修改的 PHP 文件语法和 diff 空白检查通过。

PHPUnit 文件：MarketPendingUsageWorkerTest、MarketMeasuredVideoCompletionTest、GenerationTaskResultTest、AiTaskUnboundResultTest、MarketImageTokenBillingTest、MarketNanoTokenBillingTest、MarketBoundedTokenBillingTest、MarketNativeBoundedTokenTest、MarketTextUsageReviewTest。

数据库回归须使用本地 x_cn / z_cn，并显式设置对应测试开关：AIGC_TRANSFER_CHECKPOINT_DB_TESTS、AIGC_IMAGE_TOKEN_DB_TESTS、AIGC_NANO_TOKEN_DB_TESTS、AIGC_VIDEO_PREPARATION_DB_TESTS、AIGC_NATIVE_PREPARATION_DB_TESTS 为 1；AGENT_MODEL_DB_TESTS 为 local-existing。实测视频测试还需 AIGC_TRANSFER_MEDIA_DIR 指向包含 measured-1.04.mp4 的临时目录。

## 只读历史检查与上线边界

本地完整查询 success 且 billing_status 为 reserved / pending_usage 的市场消费记录，发现同类媒体记录 4 条：画布消费 5306；短剧消费 5656、5662、5825。新判断均可识别。另有 232 条其他待用量记录，主要为文本用量复核，未自动结算或更改。

没有执行全局补偿命令，没有修改本地或线上历史任务，没有真实生成请求或线上账务操作。

本次仅交付源码，未部署线上。线上已有任务需更新代码，并确保结果 worker 与既有 ai:usage_reconcile 补偿任务运行。实际时长恢复还依赖可用的 ffprobe、PHP proc_open 和受管视频可读性；本次没有改变线上运行配置，也未验证其环境是否满足这些条件。

回退时可回退本次源码提交；无数据结构或价格变更。回退会恢复旧的待用量作业提前结束行为。
