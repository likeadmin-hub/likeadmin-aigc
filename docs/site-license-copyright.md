# 平台站点授权与租户版权

本次仅实现 AIGC 消费者端的授权展示、在线资格缓存与租户版权策略。未修改算力市场项目，不增加应用商业准入，不改动会员、计费、任务与原应用订阅。

## 开发及迁移

前后端功能分支均为 `feature/site-license-copyright`，实现已分别提交并合入本地 `develop`。发布时先部署后端和迁移，再部署前端；不得从开发目录发布未提交产物。

```sh
cd server
php -r 'require "vendor/autoload.php"; (new \think\App())->initialize(); \app\common\service\database\SqlMigrationExecutor::execute(file_get_contents("upgrade/20261008_site_license_copyright.sql"), config("database.connections.mysql.prefix"));'
php think site-license:refresh
```

迁移在 `upgrade/` 与 `public/upgrade/` 内容一致，全新安装 SQL 同时包含缓存表和刷新任务。旧环境缺少调度表时补建，已有表和任务不会被覆盖。同一迁移可重复执行，刷新任务按 command 去重。

缓存是平台全局数据，因此不复制到租户分表或新租户 SQL。刷新接口使用现有 `core_update_license` 授权管理权限，不新增菜单。部署环境须已有每分钟执行 `php think crontab` 的调度；数据库任务启用不代表宿主机调度已运行。

本地 Docker `bt` / `z_cn` 已迁移；为本地验证启动了每分钟执行现有 crontab 的临时循环，不写入宿主机或生产 crontab。平台/租户预览 5173/5174 读取 `/tmp/aigc-license-{platform,tenant}-build`，原编译文件保持原样；PC 3000 使用 develop 源码。

## 接口

- `GET /platformapi/upgrade.upgrade/licenseInfo`：保留 status、payload、machine，增加 site、site_license、issuer、site_ip。source 只返回地址与公钥是否配置，不含 API Key。
- `POST /platformapi/upgrade.upgrade/licenseRefresh`：可选 `{site_ip: "服务器出口IP"}`，返回最新授权信息。修改 IP 会作废旧缓存；不配置时仅未绑定 IP 的证书能参与在线资格同步。
- 租户 `getCopyright` 保持 `[{key,value}]` 数组形式，内容是实际生效版权。
- 租户站点信息、启动配置，以及 PC/H5 配置增加 `copyright_policy` 与最小 `site_license` 展示信息。
- `copyright_policy`：`{source: "tenant_custom"|"license_issuer"|"builtin", can_customize: boolean, items: [{key,value}], reason_code: string}`。
- `setCopyright` 拒绝时返回现有失败信封，data.error_code 为 `COMMERCIAL_LICENSE_REQUIRED`。后端检查不可被前端绕过。
- 官网 public/preview 增加 copyright_policy。商业版沿用官网现有版权显示方式；免费版版权位使用策略结果，备案号和营销文案不变。

租户及公共接口不包含完整证书、签名、API Key、机器指纹。签发版权由 text/url 映射至 key/value，签发方明确空数组保持为空；前端不能因长度为零回填默认版权。无可信 issuer 时回退产品内置版权，绝不回退租户自定义值。

## 协议与权限

原始 JSON 证书先验签再解析，保存原文，不转换空对象为数组。只有已验签的 commercial / is_perpetual=true / expires_at=0 组合视为永久商业。旧证书仍沿用原到期行为，但不被当作永久商业证书。平台域名通过签名域名与本机指纹匹配恢复，租户 Host 不参与资格计算。

授权请求使用显式配置的可信更新源、公钥和销售账号 API Key；issuer.api_base_url 不会改变渠道。完整响应移除最外层 signature 后验签，再检查请求 nonce、版本、域名、机器、签发时间、协议版本与到期时间。

正常按 refresh_after、最长 24 小时刷新。只有网络不可用才允许读取未到期、上下文匹配的旧允许缓存，最多七天。撤销、绑定错误和证书更新要求立即取消旧允许结果；认证、请求或验签错误暂停自定义权限。证书刷新最多一次，成功后再同步权限，失败不恢复旧允许状态。锁、同步代次及签发时间阻止迟到结果覆盖新拒绝。

系统更新权益及云订阅错误独立于版权资格；有既有有效允许缓存时不因这些错误撤销版权，且不伪装为网络离线。应用 apps 字段不新增本地准入检查。

## 验证结果（2026-10-08）

- `php tests/license/protocol.php`：完整签名、JSON 类型、篡改、nonce、版本、绑定、七天边界、免费证书期限及错误信封通过。
- `php tests/license/cache.integration.php`：真实数据库事务内的签名响应夹具验证通过，全部夹具写入回滚。覆盖商业版权保存、免费直接保存拒绝、恢复原配置、网络缓存、撤销/绑定/认证/验签错误、迟到成功、证书自动刷新及 issuer 更新。
- `python3 tests/install-schema-parity.integration.py`：独立临时 MySQL 的新装、自定义前缀、旧版本升级、新租户及重复迁移通过。
- 平台、租户、PC、H5 生产构建通过；平台授权页和租户只读版权页面已浏览器检查。非授权管理员调用刷新接口被拒绝。
- 真实 `https://api.likeadmin.cn/aigc/v1/license/access` 返回 code=0 / LICENSE_INVALID，带签名，但不能通过本地当前公钥验证。本地继续显示免费版与验签失败，未授予商业权限。真实商业成功、撤销和换绑仍需与该渠道匹配的有效证书、销售账号凭据及可信公钥；这些场景目前只有签名夹具回归，不能宣称线上联调完成。

四端构建均未运行发布复制脚本；未推送远程 develop/master，未发布生产。
