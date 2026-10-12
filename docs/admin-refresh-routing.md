# 租户后台刷新与直接访问

租户后台使用 history 路由。点击菜单由浏览器内路由处理，但刷新 `/admin/user/user`、`/admin/channel/overview` 等地址时，服务器必须重新返回 `public/admin/index.html`。

仅有 ThinkPHP 的 `location /` 伪静态时，深层菜单会进入 PHP 租户识别；在平台主域名、缺少租户参数或存在正则 location 干扰的部署上，静态后台入口可以打开，刷新却返回租户 404 页面。不能根据站内点击成功判断刷新已经正常。

`public/nginx.htaccess` 为宝塔 / OpenResty 的 server 级完整伪静态模板，包含平台和租户后台回退。`/admin` 与 `/admin/` 下的客户端路由返回后台入口，已有资源直接读取；缺失 assets、隐藏文件和 PHP 源码不回退成 HTML。业务 API、微信回调及其他请求继续沿用 ThinkPHP 路由和原鉴权、租户校验。

## 已有宝塔站点

代码或前端更新不会自动改写宝塔保存的伪静态配置。先备份当前站点配置，保留已有应用专属规则，再将模板中的 `/admin` 精确 location 与 `/admin/` 前缀 location 加入站点 server 块或其伪静态 include。不要放入另一个 `location / {}`，不要重复添加同一路径的 location。

若平台后台也有刷新问题，一并核对模板中的 `/platform` 回退，详见 [平台刷新部署说明](platform-refresh-routing.md)。Docker 的 `docker/nginx/default.conf` 已提供 `/admin/` 页面回退，修改配置后须检查语法并按当前部署方式重载对应 Nginx 服务。

当前本地宝塔的示例（其他服务器替换为实际路径）：

```sh
/www/server/nginx/sbin/nginx -t
# 语法检查通过后，按宝塔现有操作方式重载 Nginx。
```

仅修改本项目站点规则；不修改全局 PHP、其他站点配置、租户绑定、菜单权限或业务数据。

## 验证

使用已有登录会话，在用户管理、系统设置、渠道总览等不同模块分别点击菜单、刷新，再把当前地址直接打开。刷新后应保留菜单、query 参数和 hash；无权限或不存在的页面仍由前端按原规则处理。未登录用户仍进入登录页。验证 `/tenantapi`、`/platformapi` 和微信回调仍走后端，不被页面回退截获。

`tenant_id`、`tenantId` 或 `/t/{tenant_id}/admin/...` 的租户上下文必须保留；静态页面回退不会为业务 API 创建租户，也不会解除停用、域名绑定或租户标识不匹配检查。若页面加载后 API 返回租户错误，应继续核对原域名绑定和访问标识，不能把租户校验关闭当作修复。

隔离 Nginx 回归验证（不连接业务数据库、不改现有站点）：

```sh
python3 tests/routing/platform_refresh_nginx.py --nginx /path/to/nginx
```

该脚本覆盖后台多级菜单、静态资源、敏感文件拒绝、API 和回调边界。测试通过或本地站点重载不代表客户线上配置已更新，必须分别记录部署与验收状态。
