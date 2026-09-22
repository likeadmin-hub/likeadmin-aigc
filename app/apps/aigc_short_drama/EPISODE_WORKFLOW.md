# 多集创作与逐集队列

首页创建多集项目时生成整部大纲；确认后冻结大纲与模型参数，创建全部分集记录。独立 Worker 顺序处理每集，关闭浏览器不会停止已确认队列。单集原流程保持兼容。

PC 大纲采用参考页面的居中单列对话布局，基础信息、角色、全局场景、分集情节位于同一文档卡片，底部固定同宽修改输入框，卡片末尾确认。左侧以 01、02 等数字导航；确认后进入文档封面的分集卡片列表，已完成集进入原有左右分栏制作工作台。所有集一次入队，无需逐集点击生成。

每集绑定一个独立制作项目，现有版本、角色、场景、分镜、音视频、导出都以该项目隔离。脚本任务、分镜、生成任务、版本、素材同时写入 episode_id / episode_number；总项目的项目列表隐藏内部制作项目，封面候选汇集已完成集的素材。分集列表使用总项目 ID，制作接口使用对应集的制作项目 ID。

## Redis 队列与独立会话

确认后的首集会写入 `short_drama:jobs` Redis Stream，并由 `short_drama_workers` Consumer Group 投递。MySQL 的分集任务和作业表是最终状态来源，Redis 丢失时 Worker 仍会扫描可执行任务并补投。每次尝试生成独立 `session_id`，只携带整部设定摘要、当前集大纲和有限连续性摘要；不复用上一集聊天历史。

分集租约默认 180 秒，模型网络心跳每 5 秒更新，数据库心跳写入 `heartbeat_at`。连接、限流和临时网络错误自动按 30 秒、120 秒重试两次；模型结果不确定时保留“待核对”依据，不盲目重复调用。单集正文采用受限分镜数量，避免供应商输出窗口截断 JSON，用户仍可在单集工作台继续扩展和编辑分镜。

## 部署源码与迁移

1. 执行应用迁移 `migrations/upgrade_20260909_episode_queue.sql`。相同 SQL 已同步至 `upgrade`、`public/upgrade` 和全新安装数据库脚本，可重复执行。
2. 生产环境仅配置 `scripts/start-ai-task-worker.sh` 这一个 Supervisor / 宝塔守护入口；它会统一启动分集、规划、结果、短剧画布 Agent 与画布子 Agent Worker。不要单独启动 `short-drama:episode-worker` 或为它创建额外守护项。可通过 `PHP_BIN` 指定 PHP，并为在途请求留足停止等待时间。
3. 单次检查使用 `php think short-drama:episode-worker --once`。至少保持一个 Worker 常驻；可启动多个，MySQL 按项目的 advisory lock 保证同一项目仅一个生成调用。
4. Redis 队列迁移为 `migrations/upgrade_20260910_redis_episode_queue.sql`；生产环境执行后再重启 Worker。配置 `short_drama.redis_host`、`short_drama.redis_port`、`short_drama.redis_password` 和 `short_drama.redis_prefix`，默认复用缓存配置。

任务失败时后续集保持 pending。重试保留原制作项目，创建新的脚本任务 ID，并保留原修改意见。模型提交与点数结算使用原有统一文本模型服务。终态结果不会因再次轮询重新调用模型。完成集再次修改时，其初次生成的连续性摘要保持冻结，已经开始的下集不会被追溯改写。

文本生成调用不支持撤回：运行时取消表示“完成本集后暂停”，该次已提交调用正常结算并保留结果；继续时不重新提交已成功的本集。尚未提交的集可以直接取消，无生成调用或新增扣费。

批量导出仅包含已完成集，按集号创建独立导出任务、独立视频或 ZIP。排队任务可在列表查看，每集保留下载标识。导出复用既有素材选定、ffmpeg 和存储服务；没有可导出素材时该集明确失败，不混入其他集素材。

旧项目首次进入列表时在事务内转换。有真实编号剧情和至少四个真实分镜的集可进入制作；不完整或只有代表分镜的集显示失败，不补造剧情。原项目、原结果和旧接口保留用于读取。

## 验证

普通验证：`php vendor/bin/phpunit --bootstrap tests/bootstrap.php --filter 'ShortDrama(OutlineValidation|MultiEpisode|PlanNormalization|TextModel)' tests/Feature`。

本地数据库集成验证：`SHORT_DRAMA_DB_TESTS=1 php vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Feature/ShortDramaEpisodeQueueTest.php`。测试事务回滚，无模型调用；其中创建/修改/重试测试使用本地租户 1、用户 1 的模型配置元数据。

PC 开发服务启动后运行 `node tests/short-drama-episodes.e2e.mjs`。需要 Playwright 和本机 Chrome；可使用 PLAYWRIGHT_MODULE 指定已有依赖绝对路径。测试拦截 API，覆盖大纲修改、确认、逐集列表、失败重试、切集归属、返回、导出和无权限状态，不产生真实扣费。

源码交付不包含前端编译包。真实模型生成效果、数百集长时间运行及付费流水须用目标环境的模型与点数配置继续验证。

## 真实冒烟记录（2026-09-10）

测试项目：`project_id=230`，租户/用户：`1/1`，3 集。大纲任务 `sd_plan_202609100924207859` 成功；确认后创建 3 个分集任务。第 1 集第一次输出被供应商窗口截断，按重试流程重新生成后成功；第 2、3 集由 Worker 按顺序自动继续并成功。最终三集均有独立 `session_id`、供应商请求 ID、角色、场景和 12 个分镜，且不同集制作项目互不覆盖。三次成功调用均有对应 `ai_consumption_log`，状态为 `settled/success`。未生成图片、视频或音频。
