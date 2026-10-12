# expansion 本地 develop 合并验收（2026-10-12）

本次仅将已核对的 `feature/aigc-expansion` 合入前后端各自的本地 `develop`，并修复与现有开放平台的兼容问题。没有推送远端、合入 master、制作发布包或调用付费上游。原有无关工作区文件保持未暂存。

## 合并基线与检查点

| 仓库 | 原本地 develop | 远端 expansion 头 | 合并提交 | 验收检查点 |
|---|---|---|---|---|
| server | aa30ced38414896e57d4cf4e1bce89d1f209cb10 | ca9f45d77579cff5c662339eeb120cb81aab82cd | eeba3f81d427ba9ae12a529817854da851ddd803 | 03ed7567a3b8eab7456f40dcedbfb592c41c2924 |
| web | 8978f2eae5e47cadf64b05a03b1813d259056cce | 2675153a54fc527a8e93943029373d864c779c4d | 38693edde0e1eb85c7800b68fe01406f36759eae | d912ab9a538ceb2ef6fc9067fc95a16991756bd2 |

两项 expansion 头均通过 `git merge-base --is-ancestor` 检查，且再次 `git ls-remote --heads origin '*expansion*'` 与上述头一致。server 的共享业务代码验收检查点为 `3f9e45ae7`；此后仅修正两项测试初始化与进程隔离，不改业务源码。web 隔离构建源为 `38693edd`；此后仅调整两个测试文件。

## 合并兼容处理

- 图片服务使用新增页面批次去重，同时保留新建任务的开放平台任务、消费关联钩子；回放页面任务不重复触发钩子。
- `AigcImageSubmission` 在可信开放调用上下文中忽略页面 `batch_key`，由网关 `Idempotency-Key` 管理 API 幂等，避免两个开放请求共享页面原任务。
- 视频服务保留受理时冻结的价格、SKU、用量及模型选择；视频效果在受理时解析、编译、检查并固定到服务端快照。执行已受理请求不重新读取效果配置、不重复追加效果指令。旧快照仍可执行。
- 首页同时保留新增 reduced-motion 初始化与共享侧栏状态。个人中心默认收起，手动展开跨个人页面保持，离开后再次进入恢复默认收起。
- 音乐测试注入实际媒体辅助模块；短剧主题测试改从既有短剧调色来源读取，并保留原颜色断言。SVG 上传测试保留独立进程，禁用无关父进程全局状态恢复。

## 验证结果

| 范围 | 结果 | 证据 |
|---|---|---|
| PC、租户端、平台端隔离构建 | 全部通过，输出仅在 /private/tmp | /tmp/expansion-merge-pc-build.log、/tmp/expansion-merge-tenant-build.log、/tmp/expansion-merge-platform-build.log |
| 前端回归 | 275/275，通过；0 失败、0 跳过 | /tmp/expansion-web-unit-final.log、/tmp/expansion-web-validation-report.json |
| 开放平台串行回归 | 74/74，通过；274 项 PHP 语法检查通过 | /tmp/expansion-open-platform-regression.log；runtime/open-platform-acceptance/regression-20261011.json（文件名沿用，报告记录当前验收） |
| expansion 后端 PHPUnit | 69/69，609 个断言；两项本地数据库用例显式开启并回滚 | /tmp/expansion-phpunit-final.log |
| 视频效果事务集成 | 配置持久化、租户隔离、旧配置写入兼容、三类上游载荷含效果指令通过；全部回滚 | scripts/check-video-effects.php，经本地数据库范围检查后运行 |
| 外观事务集成 | 自定义皮肤、预设名称及背景、旧客户端兼容、乐观锁及归属验证通过；全部回滚 | /tmp/expansion-appearance-integration.log |
| 素材事务集成 | 来源保留、标签、跨租户/用户隔离、回收与恢复、原子批次及分页通过；全部回滚 | tests/workspace-assets/integration.php，经本地数据库范围检查后运行 |

PHPUnit 从已有工作树借用 PHPUnit 9.6.36 的开发依赖映射，应用及生产依赖始终加载当前 server；4 个应用 Reflection 路径均核实在当前 `server/app`。未安装依赖、修改 vendor 或降低断言。最终运行有 30 秒时间限制、最多 3 个 PHP 进程限制；实际 1.029 秒、最大 2 个 PHP 进程、退出 0。

静态外观、i18n、主题、启动及装修检查命令均退出 0；i18n/主题仍会列出历史审查条目，不能据此声称全项目已无硬编码或语义类型问题。

## 浏览器验收

本地预览 `http://127.0.0.1:19875` 使用隔离构建 PC 输出和当前 server 源码；用户自行重新登录 xhadmin001，未读取或输出完整登录 Token/API Key。

- 新首页图像模型和价格加载、Agent 对话入口及其作品空态加载正常。
- 素材库加载现有资源，首批 20/293，标签及分类筛选入口可见；未上传、删除、收藏或修改标签。
- 个人中心默认收起导航；手动展开后切换 API 密钥保持展开。创建密钥弹窗和数字步进控件遵循当前主题；取消，无新密钥。
- 请求详情面板宽 680px，分为概览、计费与流水、客户回调；真实请求消费及积分流水显示正常。
- 账单共 105 个汇总记录，20 条/页；第二页实际选中并加载。概览与文档中心可正常刷新，API 参考显示已发布接口和参数说明。
- 浏览器错误日志为空；已恢复临时 1280×900 测试视口。

截图：`/tmp/expansion-local-merge-home-20261012.png`、`/tmp/expansion-local-merge-developer-20261012.png`。

## 补测运行器事故与恢复核对

补测中临时运行器错误地 include 了 PHPUnit CLI 入口，SVG 独立进程恢复 included-files 后再次执行整套用例，形成递归启动。本次已精确暂停并终止该测试树的 110 个进程；未停止其他项目服务。此期间本地 MySQL 日志出现 crash recovery，随后恢复 ready 状态。

修正为纯开发依赖 autoload、直接调用 Command::main，并使用真实隔离函数标志、直接执行路径及 include 排除保护。先单独 SVG 复测通过（1 项、7 个断言、最大 2 个 PHP 进程），再限时运行全部 69 项通过。

恢复后以只读一致性快照核对：已发布契约、文档、开放设置、视频原配置、文件原字段哈希不变；测试账户积分仍为 9,993,772.02；相关 fixture 数据无残留。再次执行事务集成后相同核对仍通过。证据：`/tmp/expansion-post-test-health.json`、`/tmp/expansion-post-test-health-final.json`。HTTP 预览返回 200，登录后真实统计可刷新。

## 验收边界

本次完成本地分支合并及兼容回归，不等同于新效果的真实生成质量验收、Agent 自动工具执行/真实作品生成或开放平台 P0–P7 全量发布。没有导入或启用默认关闭的 90 个效果，没有扩大租户权限、补扣历史账单或消耗新增付费调用积分。原先开放平台待验收项沿用既有记录。


## 本地迁移验收

在 `develop / 3f9e45ae7` 合入后，通过 Docker `bt`、PHP 8.2 执行，数据库严格限定为 `127.0.0.1 / z_cn`（前缀 `la_`）。以下 5 项 SQL 按顺序各执行两遍，第一遍应用、第二遍验证幂等；第二遍受影响表的数据哈希完全一致，均为 **PASS**。菜单 DML 使用独立事务，锁等待上限 10 秒。

- `upgrade/20261012_workspace_asset_state.sql`：创建账户资产状态表，按租户、用户和资产标识唯一关联。
- `upgrade/20261012_workspace_asset_labels.sql`：创建账户标签表，按租户和用户唯一关联。
- `upgrade/20261012_workspace_local_uploads.sql`：添加 tenant_file.upload_origin，并按既有目录及音乐上传证据回填本地上传来源。
- `app/apps/aigc_video/migrations/upgrade_20261012_effect_config_capacity.sql`：将视频 config_json 扩为 mediumtext，保留原配置。
- `app/apps/aigc_video/migrations/upgrade_20261012_effect_configuration_menu.sql`：增补视频效果配置菜单，并用 NOT EXISTS 复制已有基础配置角色授权。

执行和两遍验收均成功后，新增两条标准 `app_migration` 成功记录（`scope=platform`、`app_code=aigc_video`、`tenant_id=0`、`status=success`），没有覆盖既有记录：

- `upgrade_20261012_effect_config_capacity.sql`
- `upgrade_20261012_effect_configuration_menu.sql`

迁移前后保护数据保持不变：5 个已发布契约、12 篇已发布数据库文档、2 条开放配置及视频配置的原始哈希；文件表仍为 1017 行，原有字段哈希不变；用户与租户积分总值不变。未调用上游、未产生账务消费、未导入 90 个效果预设、未启用租户或模型、未执行发布或全量同步。

迁移后串行 `video-price.php` 和 `image-submission-scope.php` 回滚用例均 PASS，无供应商调用或预占消费。

原始证据：`/tmp/expansion-local-migration-report.json`；`/tmp/expansion-local-migration-report.log` 为空。

