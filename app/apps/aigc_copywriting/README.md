# 智能文案运行说明

智能文案是系统内置能力。正常安装、系统升级及创建租户时自动初始化，无需平台安装、购买、开通或租户上架。每个租户拥有独立分类、工具、提示词、草稿和发布版本。

## 入口与默认内容

- PC：`/app/aigc_copywriting`；功能：`/app/aigc_copywriting/tool/{tool_code}`。
- UniApp：`/apps/aigc_copywriting/pages/index/index`；功能：`/apps/aigc_copywriting/pages/tool/index?code={tool_code}`。
- 租户后台：智能文案菜单中的功能、分类、首页装修和生成记录。入口沿用当前租户身份和登录机制。
- 内置12项：文案提取、文案创作、违禁词优化、文案仿原创、错别字检测、文案改写、智能写作、带货文案、口播脚本、短视频标题、小红书文章、公众号文章。
- 默认4分类：风险优化、创作输出、平台图文、内容提取。内置恢复先写入草稿，需发布才影响用户页面；重复初始化保留租户修改、隐藏及删除状态。

## 部署与进程

随系统迁移执行表结构、系统注册、权限菜单和租户模板初始化。已有环境需要检查或补齐时，在正确的系统目录及数据库配置下运行：

```sh
php think copywriting:repair
php think copywriting:repair --apply
# 仅补齐一个租户：
php think copywriting:repair --apply --tenant=1
```

第一条只说明操作，不写入。修复命令不调用模型。不要用应用中心安装流程替代系统初始化。

部署后由进程管理器保持专用文本 worker 运行：

```sh
php think copywriting:worker
# 单轮检查：
php think copywriting:worker --once
```

每个 worker 一次领取一条生成任务，可使用多个独立进程扩容。任务表使用租约；已发送但未确认的任务不会重新调用供应商。进程每30秒扫描待核对回执。更换进程前先等待正在生成的任务结束；不要为本应用重启其他应用的共享推理进程。

PC/H5流式订阅携带认证头，不在URL传递长期令牌。生产反向代理须允许 SSE 分块及时到达；订阅在约25秒后重连同一任务。小程序优先分块请求，不支持分块时读取同一任务的增量快照。两者均不会触发新的付费生成。

## 模型、生成与费用

模型选项来自当前租户可用文本模型。服务端固定所选模型身份、价格及版本快照；排队期间配置变化会拒绝执行，要求用户重新选择，不自动换供应商或模型。DeepSeek 文案请求关闭思考模式，内部推理不作为最终正文。

复制不调用模型。重新生成使用原任务输入、当前选择的模型及新的幂等键，创建关联原任务的新记录，按实际用量结算。网络重试沿用原幂等键；恢复订阅和历史查询不收费。

回执先加密持久化到 `runtime/copywriting-receipts/`，再通过公共消费记录的原价格快照结算。该目录需可写、持久化，使用系统已有 OpenVault 配置；不能作为可丢弃的临时缓存清空。缺失可信 usage 的记录保持待核对，不估算扣费、退款或重发。

平台超级管理员可使用 `/platformapi/app/aigc_copywriting/task/recheck` 核对原回执、`review` 记录审阅说明；后者不会结算、退款或修改金额。实际消费金额以公共消费记录为准。业务失败与已发生的供应商费用分别保存。

## DIY与版本恢复

首页与每个工具页分别保存草稿、预览和发布。支持字段、提示词、布局、Grid/Flex、四边间距、颜色、尺寸、内部部件、交互状态和终端覆盖。高级样式仅接受能力目录允许的声明；禁止任意脚本及页面外选择器。

保存携带草稿版本，冲突不覆盖；发布创建不可变快照。回滚复制旧版本形成新的发布记录，历史任务保留原输入、页面版本及模型。功能下架阻止新任务，保留旧结果和账务。

## 本地验证

```sh
php tests/copywriting/contract.php
php tests/copywriting/lifecycle.php
php tests/copywriting/publishing.php
php tests/copywriting/runtime.php
php tests/copywriting/worker.php
php tests/copywriting/recovery.php
```

行为测试使用本地事务回滚，不调用供应商。完整新系统安装、生产代理、小程序真机和各模型的实际可用性仍应在对应交付环境验收；编译成功不代表这些环境已经通过。
