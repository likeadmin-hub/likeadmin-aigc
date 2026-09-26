# PC 微信登录与绑定

租户独立网站应用；默认关闭。仅允许现有账号完成 PC 绑定后登录；不自动注册、不按 UnionID 推断 PC 身份。历史绑定缺少 AppID 时要求原账号重新授权。

## 本地升级与正式升级

先发布服务端代码，再执行结构迁移，最后启用前端入口。项目本地在 Docker bt 内：

```
cd /www/wwwroot/likeadmin_aigc_saas/server
php scripts/migrate-pc-wechat.php          # 只读预检，会显示数据库名称与主机
php scripts/migrate-pc-wechat.php --apply  # 确認本地目标后执行
php scripts/migrate-pc-wechat.php          # 应不再产生 ALTER
```

迁移预检所有 user_auth 及租户分表，保留数据；冲突即停止，不自动去重。每表将添加 appid、建立租户/终端/应用/OpenID 唯一约束，并移除旧的单 OpenID 唯一约束。SQL 文件 `20260926_pc_wechat_identity.sql` 使用项目 SqlMigrationExecutor 的专用标记驱动同一迁移，不能仅用 mysql 客户端执行该文件。安装与新租户建表模板已同步。

后续制作系统升级包时，必须包含该 SQL（结构组）、PcWechatMigration、SqlMigrationExecutor、新授权服务和相关接口；同时包含完整 PC、租户端产物。此次开发不创建正式版本或发布包。

## 配置与接入

租户后台「渠道管理 → PC 网站微信登录」配置已审核网站应用、Secret 和 HTTPS 回调地址，例如 `https://tenant.example/pc/wechat/callback?tenant_id=1`，在微信开放平台登记对应回调域。同源为第一版要求；域名、query、路径方式识别的租户必须一致。不要填平台第三方应用凭证。更换 AppID 必须填写新 Secret，原绑定保留但需重新授权。

AppSecret 使用现有 WechatCredentialService 加密；旧明文配置兼容读取，下一次保存升级为 enc:v1。复用部署已有 credential_key，必须安全备份该密钥。返回掩码，空输入保留原密钥。

登录 POST /api/login/wechatPcAuthorize → 微信 qrconnect → PC /wechat/callback → POST /api/login/wechatPcLogin；绑定使用 /api/user/wechatPcBindAuthorize 和 /api/user/wechatPcBind。授权 POST 包含 verifier（浏览器随机 32 字节十六进制）、origin、return_to；完成 POST 包含 code、state、verifier。state 有效期 600 秒，回调浏览器证明只存 sessionStorage，完整 code、长期 token 不用于回跳 URL。

服务端验证请求租户、AppID/配置版本、用途及绑定用户/原会话。state 在 MySQL advisory lock 下消费；绑定与解绑按租户序列化；不同应用不共享绑定。生产多节点需共享缓存，或配置粘性会话；找不到 state 时拒绝而非降级验证。OAuth 交换超时后要求重发，不重试 code。

## 验证

```
php vendor/bin/phpunit tests/Feature/PcWechatLoginTest.php
php vendor/bin/phpunit tests/Feature/WechatAccountMergeTest.php
PC_WECHAT_MYSQL_TEST=1 php vendor/bin/phpunit tests/Feature/PcWechatMigrationMysqlTest.php
```

MySQL 测试仅支持 localhost/127.0.0.1，使用随机前缀测试表并清理。行为测试使用 SQLite 内存库、隔离缓存及模拟 OAuth，不能证明真实微信授权成功。

上线前须使用真实应用在 Windows/macOS 验证「绑定 → 退出 → 微信登录 → 解绑 → 拒绝微信登录」，包括官方快捷确认和扫码回退。微信开发指南访问受阻，客户端最低版本及快捷登录参数最新规则尚待官方核对，故直接使用现有标准 qrconnect，不自行指定未经核实的快捷登录参数。

故障时关闭 PC 登录开关，保留账号密码/短信登录及全部身份数据；不要回滚到不认识 appid 的旧身份逻辑。日志仅包含租户、操作、结果、错误码及摘要请求编号。
