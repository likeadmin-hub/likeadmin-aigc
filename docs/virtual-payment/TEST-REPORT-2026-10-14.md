# 虚拟支付与套餐优惠验收记录（2026-10-14）

## 交付范围与边界

实现租户虚拟支付配置、算力/会员优惠和道具配置、共享报价及一次优惠占用、原生小程序支付适配、验签/服务端查单、权益发放、两类发货核对、退款与安全回收、有限额的维护任务，以及 PC/H5/小程序购买界面。保留 OEM、普通支付和已有业务。

真实扣款、真实退款：按用户约定 **SKIPPED**。真实 iOS/Android 设备的微信 SDK 唤起及渠道商业资格：**未实测**，现有证据是原生适配/SFC 测试、模拟 SDK 与小程序生产构建，不能替代真机验证。

## 隔离环境

- PHP 8.2.34 / MySQL 8，真实 ThinkPHP 认证、SQL、订单服务与执行命令。
- 单元/集成数据库：`oem_virtual_dev`；HTTP 验收数据库：`oem_e2e_virtual_20261014`。
- 独立服务：`likeadmin-virtual-acceptance`，仅 `127.0.0.1:19875`；代码位于 `runtime/virtual-acceptance/20261014/server`。
- 外部支付网关、许可证发行端使用测试适配器。业务服务不模拟、不直接改付款状态；模拟付款只改变外部渠道账本，随后由正式查单/验签/发放流程处理。
- PC 使用真实购买组件与真实 HTTP/数据库，Nuxt 外壳和展示性上下文由夹具提供；H5/租户/平台使用完整构建页面。Chrome 无头浏览器运行，不操作用户生产浏览器。
- 独立站点内的构建文件为验收专用；主站 public、业务 runtime、现有任务未部署或切换。

## 结果矩阵

| 项目 | 结果 | 证据 |
|---|---|---|
| 金额、OR 时限、一次优惠、活动保存/重启 | PASS | promotion-policy.php |
| 真实充值/会员建单、报价变化、成交权益快照、原单恢复 | PASS | promotion-orders.integration.php、HTTP 流程 |
| 6 进程并发优惠预占 | PASS | promotion-concurrency.integration.php |
| 普通微信/支付宝渠道原配置、关闭/迟到/重复到账 | PASS | package-payment.integration.php |
| 账号合并、跨租户/用户隔离、次数保留 | PASS | package-identity.integration.php、WechatAccountMergeTest |
| AppID/环境/配置密钥、登录 session、原始签名字节 | PASS | virtualpay-gateway.integration.php |
| 验签、错误金额/身份、Apple 无微信交易号、重复通知 | PASS | virtualpay-payment.integration.php |
| 支付已到账但权益失败恢复、两类发货、未知结果保留 | PASS | virtualpay-delivery.integration.php |
| 6 进程支付核对只发放/记账一次 | PASS | virtualpay-concurrency.integration.php |
| 固定退款单号、查单优先、未知/处理中/成功、权益回收 | PASS | virtualpay-recovery.integration.php、HTTP 流程 |
| 运行任务、冻结/叠加权益保护、退款不返还优惠 | PASS | recovery + HTTP |
| 租约接管、关闭确认、停止页面后维护核对 | PASS | virtualpay-maintenance.integration.php |
| 后付款退款查单、交付前退款、原微信身份恢复、并发退款终态、版本切换 | PASS | virtualpay-review-regressions.integration.php，8 场景 RED→GREEN |
| 内部 SQL/路径错误不泄露租户订单页 | PASS | maintenance 中 RED→GREEN 回归 |
| 新装、升级、自定义表前缀、重复迁移及新租户菜单 | PASS | promotion-schema.integration.py |
| PC 扫码二维码可解析、服务器金额、到账后原价 | PASS | virtualpay-browser-http.cjs |
| H5 会员优惠、支付宝预开窗口、查单到账 | PASS | virtualpay-admin-h5-browser.cjs |
| 租户配置/套餐编辑/空密钥保存、平台原登录工作台 | PASS | 同上，真实后端页面 |
| 租户草稿保护、失败重试、重启/退款确认、390px/键盘 | PASS | virtual-payment-browser.cjs |
| PC 既有与新增自动测试 | PASS | 724/724 |
| UniApp 既有与新增自动测试 | PASS | 96/96 |
| 租户既有与新增自动测试 | PASS | 52/52 |
| 平台既有自动测试 | PASS | 5/5 |
| 微信授权/账号合并/小程序管理与隐私回归 | PASS | 33 tests / 131 assertions |
| OEM 建单付款、预支付、网关、退款、开通回归 | PASS | 5 组真实后端集成测试 |
| PC/租户/平台/H5/MP-WEIXIN 生产构建 | PASS | 隔离输出，不执行业务发布复制脚本 |
| 变更 PHP 语法、Git diff whitespace | PASS | final-backend.json / git diff --check |
| 全项目类型检查 | 存在基线阻塞 | tenant 原 tsconfig 已移除选项 TS5102；PC 原 ModelCapabilities 类型问题 |
| premium 全项目严格审计 | 存在基线问题 | 3 处无关装修/官网按钮；本次支付范围无新增发现 |
| 实际扣款 / 实际退款 | SKIPPED | 用户约定 |
| iOS/Android 真机微信支付唤起 | 未实测 | 不以 SDK 模拟/构建替代 |

## 可复现入口与证据

后端测试在只读挂载代码的隔离容器运行，设置 `OEM_DISPOSABLE=1`、`PHP_DATABASE_HOSTNAME=oem-mysql`、数据库 `oem_virtual_dev`、测试专用凭据密钥及 file cache。参考 `tests/Support/OemDisposable.php` 的保护条件。正式环境禁止使用测试网关。

HTTP 验收先在独立数据库执行 `tests/Support/VirtualPayAcceptance/seed.php`，再运行 `python3 tests/virtualpay-http-acceptance.py`；前端按顺序运行 `tests/virtualpay-browser-http.cjs`、`tests/virtualpay-admin-h5-browser.cjs`。重复整组前重新生成测试用户和套餐，不重置生产订单。

Node 回归须分别在 `web/pc`、`web/uniapp`、`web/tenant` 目录执行 `node --test tests/*.test.cjs`，避免工作目录依赖造成假失败。浏览器需已安装 Chrome、Playwright、sharp/jsQR（测试依赖）。本机浏览器脚本使用 `/tmp/oem-type-tools/node_modules/jsqr`，迁移机器时须调整测试依赖路径。

原始日志/截图本机保留在 `/tmp/virtual-pay-ui-checks/`：`final-backend.json`、`task8-regressions.json`、`task8-schema.log`、`task8-http-final.log`、`task8-*-final.log`，以及 `http-browser/` 与 `browser/`。部分关键结果复制在本报告同目录的 `evidence/`，供提交后审阅。

## 最终审查与提交

独立只读审查使用 gpt-6-astra，审查范围后端 `51f625816..7d05f64fe`、前端 `3f072920..c972fb53`。发现 4 项 Important，无 Critical、无待办 Minor；同一修复批次逐项 RED→GREEN：

1. 已有付款记录后再查到渠道退款，现在仍进入退款核对；退款中不允许旧交付任务继续发放。
2. 恢复支付须使用原 OpenID，另一个已合法绑定同业务账号的微信身份也不能重签旧尝试。
3. 退款通知等待渠道时，worker 先完成退款的交错不会把 success/recovered 改回 processing/manual。
4. 渠道请求期间发生 runtime generation 变化，退款请求、通知、查单及异常记录停止旧版本写入。

新增 8 个真实 SQL 场景均观察到失败，再修复通过；全套相关后端、前端与 HTTP/浏览器回归重新验证。审查修复提交 `e4a8e4f10` 已合入后端本地 develop `00dec089a`；前端本地 develop `62f433c3` 包含 `c972fb53`。验收后端独立运行目录 HEAD 为 `00dec089a`，业务源码与待验收提交一致。随后仅提交本文档与证据，不改业务源码。完整哈希见 `evidence/manifest.json`。

当前没有推送或发布；版本号、主站运行代次及业务资产没有切换。仍需后续获准发布时完成真实小程序配置、真机验收、部署迁移与线上 cron 验证。

实施裁决与审查未覆盖项的完整记录见 [DECISIONS.md](DECISIONS.md)，运维步骤见 [OPERATIONS.md](OPERATIONS.md)。

## 统一启动入口补充验收

按用户要求，虚拟支付核对已补入 `scripts/start-ai-task-worker.sh`，不启动额外的整套 cron。新增循环与原七类队列分开监控，单轮失败继续间隔重试，循环退出只恢复自身；停止时等待当前有界核对完成，不再启动下一轮。

- `tests/virtualpay-unified-worker.py`：使用真实统一脚本、临时目录和假 PHP 进程，验证启动、失败后重试、单轮不重叠、七类 worker 不重启、父进程 TERM 后排空。先观察缺少入口及停止期间重启迭代的失败，再修复通过。
- 同一测试的 `--group`、`--dash`、`--dash --group`：进程组 TERM、POSIX dash 下排空及停止派发均通过。
- `tests/decoration-agent/unified-worker.py`：更新已有 OEM worker 数量为七类，保留原启动、停止、重启、关键子进程失败收尾和其他项目隔离断言，全部通过。
- `tests/virtualpay-command-lock.integration.php`：真实 MySQL 持有正式维护命令的数据库锁，同时启动两个独立 PHP 命令竞争，均立即跳过执行，且不能释放原持有者的锁。覆盖统一入口与独立 cron 并存防重。
- Shell 语法及 Git diff 检查通过。仅本地合入，没有调用主项目启动脚本、重启现有业务或触碰运行中的任务；真实站点需在后续正常排空更新窗口加载脚本。

历史验收清单及证据哈希保持原样，本节对应后续统一入口修补，未重新构建或发布任何前端资产。
