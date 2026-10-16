# 平台域名入口、后台访问限制与刷新路由

## 当前访问规则

- 直接打开配置的平台域名 `/`，返回 HTTP 302 跳转到 `/platform/`。
- 平台页面和 `/platformapi/` 只接受 `.env` 中 `[PROJECT] HTTP_HOST` 精确匹配的域名；支持逗号分隔的多个平台域名，忽略大小写、协议和端口。
- 租户子域名、独立绑定域名以及其他非平台域名不能打开 `/platform`、`/platform/...` 或 `/platform/index.html`，页面返回 HTTP 404；平台 API 返回 HTTP 403。
- 平台域名未配置时，平台后台拒绝访问。安装程序会写入该配置，老站点部署前需确认配置完整。
- 租户首页、租户后台、`/t/{tenant_id}`、`tenant_id` 参数/请求头访问方式继续保留。平台主域名携带明确租户标识时仍可打开对应租户前台。
- 微信授权中转、消息与支付回调继续走原处理流程。平台管理员选择租户的 API 参数继续可用，仍需通过原登录和权限校验。

PHP 域名检查使用实际 `Host`，不信任客户端传入的 `X-Forwarded-Host`。反向代理应保留原始 Host。平台域名的子域树仍保留给租户子域名分配，不能因为域名别名校验保留了这个范围，就放行其平台后台访问。

## Nginx / 宝塔部署

`public/nginx.htaccess` 是 **server 级**完整伪静态模板，不能放进 `location / {}`。网站根目录应为本项目 `server/public`。

已有的 `/platform/` 直接静态回退会绕过 PHP 域名检查，必须与 PHP 修复一并替换：

1. 备份站点原伪静态配置；更新本项目的 `public/nginx.htaccess` 对应规则，保留站点的 SSL、PHP、PC 自定义规则和其他业务配置。
2. `/`、`/platform` 和 `/platform/` 下的页面都进入 ThinkPHP；包括已存在的 `platform/index.html`，不能通过 `try_files $uri` 直接返回。
3. `/platform/assets/` 和图片、字体、JS/CSS 等资源继续直接读取。不存在的资源返回真实 404，不能返回 HTML；隐藏文件和 PHP 源码不可静态读取。
4. 只保留一份 `/` 和 `/platform/` location。移除旧的 server 级 `if (!-e ...)` 通配 rewrite，使用模板中的 location 级规则，防止抢先改写。
5. 执行实际 Nginx/OpenResty 的 `nginx -t`，通过后重载，再检查访问结果。

Docker 部署使用同样策略，模板为 `docker/nginx/default.conf`。修改代码或模板不会自动更新客户宝塔中已保存的站点配置；仅更新 PHP 无法阻止静态平台入口绕过。

原先为修复 history 路由刷新而直接回退到静态 `platform/index.html` 的方案，已由经过 PHP 域名校验的页面回退替代。页面名称如 `system-service/license`、`foo/LICENSE`、`foo/README.md` 仍可正常刷新，不受全局文件名正则 location 抢占。

## 验证

PHP 行为回归：

```sh
php runtime/verification/phpunit-9.phar -c phpunit.xml.dist tests/Feature/PlatformDomainAccessTest.php
```

隔离真实 Nginx 回归使用临时目录、临时端口和模拟动态入口，不改已有站点、不连接业务数据库：

```sh
python3 tests/routing/platform_refresh_nginx.py --nginx /path/to/nginx
python3 tests/routing/platform_refresh_nginx.py --nginx /path/to/nginx --template docker/nginx/default.conf
```

实际站点验收应同时覆盖平台主域名和租户域名：平台 `/` 跳转，后台多级菜单直接打开与刷新，租户访问平台页面 404、平台 API 403，租户自身首页与 API 正常。额外检查 `/platform/index.html`、旧的 `index.php?s=/platform/...` 入口和带 `X-Forwarded-Host` 的请求。已有平台登录态、SSO 及微信授权流程保持可用。

代码回归、本地配置重载与客户线上部署是不同验证阶段，应分别记录。

## 同批交付：PC 启动配置接口 500

截图中的 `/api/pc/config?tenant_id=1` 报 `Class "app\api\logic\OemBrandService" not found`。`PcLogic` 调用 `OemBrandService::read()` 时缺少正确导入，PHP 因而将其解析到当前 logic 命名空间。

修复位于 `app/api/logic/PcLogic.php`：导入 `app\common\service\OemBrandService`。提交 `57ef7bedd` 已在 `feature/platform-refresh-404` 的祖先中，该分支同时包含此次平台刷新修复，无需重复应用代码补丁。

2026-10-08 实际请求验证：本地 `Host: z.cn` 的 `/api/pc/config?tenant_id=1` 返回 HTTP 200、`code=1`，响应包含 `is_oem`、`website`、`login`、`official_site` 等启动配置；线上 `opc.likeadmin.cn` 同接口仍返回 HTTP 500 和上述类不存在错误。

部署时一并更新 `app/api/logic/PcLogic.php`，确认 `app/common/service/OemBrandService.php` 已存在；如果 PHP OPcache 未自动校验文件，需要按站点现有方式刷新缓存或重载对应 PHP-FPM。然后重新请求该配置接口，要求 HTTP 200、`code=1`，并刷新 PC 首页确认品牌、导航和官网内容正常加载。此项 PHP 部署验收与平台 Nginx 配置加载验收分别确认。
