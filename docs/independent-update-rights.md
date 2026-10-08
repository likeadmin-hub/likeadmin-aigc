# 消费者 AIGC 独立更新服务

## 范围及协议

商业资格、版权自定义和系统更新服务独立。Schema 3 证书的 `update_mode=annual/lifetime/tenant_policy`、`update_until`、`update_rights_revision` 用于更新展示；旧证书缺少模式时显示旧版协议，不从日期零推断永久更新。原始证书与完整响应验签后才解析。证书修订号不再当作最高系统版本。

`site_license.updates` / 更新 overview 的 `updates` 为 `{update_mode, update_until, update_rights_revision, update_state, can_update}`。年度截止为排他边界；在线权限缓存不能延长年度更新期。`can_update` 表示更新服务状态，不替代具体版本的 `can_download`。

系统 versions/package 使用平台绑定域名及机器、可选出口 IP 和显式可信渠道；源地址、凭据、公钥、证书与绑定变化隔离包授权。系统响应必须验签；失败接口保留 `data.error_code`。`LICENSE_REDOWNLOAD_REQUIRED` 作废旧允许缓存，最多刷新证书一次后重试。手动刷新授权先调用兼容旧版本的证书刷新，再同步权限。不改变客户名称、Logo、版权配置或可信公钥。

逐版本展示 `can_download/deny_reason/deny_message`；自动选版只考虑明确允许的版本，保留桥接和步进条件。年度到期不统一阻止已获版本。免费更新关闭只影响系统包，不影响已安装系统、商业版权或任务。

## 包授权与安装

`system_package_grant` 为全局私有表：本地包 ID、上下文摘要、完整原始签名响应、创建与更新时间。签名响应可能含一小时下载凭证，不返回前端，不写进更新任务日志或公共目录。上游交易表及迁移不进入本项目。

包响应必须提供 site_grant（证书编号、版本、domains、机器指纹、version_id、issued_at、expires_at）和主/备用文件的签名摘要。授权有效期最多一小时，校验目标版本、文件格式及摘要。下载凭证失效最多重新鉴权一次；其他错误不伪装成凭证过期。

预检前校验压缩包摘要及本站授权。安装时无条件从原始压缩包重新预检和解压，再次核验本站授权后才写文件/执行 SQL；原有预检状态不能绕过检查。有效签名授权允许离线执行到其原始失效时间；过期、证书/源变更或旧包缺失授权时，必须在线对同一目标和摘要重新授权。签名篡改、跨站点授权和摘要错误直接拒绝，不能回退旧允许。

旧 upgrade/downloadPkg 入口统一接入当前签名更新服务，原 indexapi 授权不再参与执行。旧独立终端包/完整包类型不伪装成步进包；当前上游未提供这些类型时明确拒绝。

## 安装、升级和本地迁移

新装：public/install/db/like.sql。升级：upgrade/20261008_independent_update_rights.sql 与 public/upgrade 同名文件，内容一致、可重复执行。全球表不加入 tenantData.sql。

沿用 SqlMigrationExecutor::execute(sql, 当前配置前缀)，只在已经核对主机和库名的本地开发库运行。本次不生成发布包，不执行线上迁移。后续打系统包时必须把该 SQL 同步放入包的 sql/structure 并重新计算签名。

## 验证入口

- php tests/system-update/protocol.php：签名原文、JSON 对象、站点/版本/证书绑定、一小时边界、主/备用摘要及年度时效。
- php tests/system-update/integration.php：数据库事务回滚夹具，验证平台/租户 Host 隔离、私有证据持久化、过期重新授权、换证、拒绝安装、结构化拒绝及自动选版。
- php tests/license/protocol.php 和 php tests/license/cache.integration.php：原版权、撤销和缓存回归。
- python3 tests/install-schema-parity.integration.py：独立临时 MySQL，新装、自定义前缀、旧版升级和重复执行。

## 上游限制

当前上游版本列表已经提供逐版本资格；包接口仍按 current_version 选择下一步升级包，当前版本/旧版本返回 has_update=false，未明确提供重装/完整包分支。客户端不伪造当前版本来取得历史包，也不提供虚假的重装按钮。需上游补目标版本下载/重装契约后才能完成真实旧版重装验收。消费者已有一小时本站签名包的离线安装校验，不新增离线文件上传页面。

真实商业成功、续费、撤销、免费开关和下载联调需要与更新源匹配的证书、销售账号凭据及可信公钥。签名夹具测试不等于真实上游联调成功。

## 本地验收记录（2026-10-08）

后端与平台前端已合入本地 develop。只对 127.0.0.1/z_cn 执行包授权表迁移，连续执行两次成功。26 项独立协议断言、19 项事务集成断言、原签名/版权缓存与续费刷新回归、12 项 OEM 更新保护断言通过。独立临时 MySQL 的新装、自定义前缀、旧版升级、重复执行与新租户验证通过（各 324 张表）。平台构建、局部 ESLint 和授权/更新状态展示测试通过，未运行发布复制脚本。

真实 system/versions 调用：本地证书绑定与验签有效，但上游响应未通过当前可信公钥验证（LICENSE_SIGNATURE_INVALID）。未授予更新包权限，未执行真实系统安装；真实商业/续费/撤销/下载联调仍待匹配的更新源信任配置。
