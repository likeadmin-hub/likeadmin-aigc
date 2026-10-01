# 官网子页线上 404：部署路由

## 已复现的现象（2026-10-01）

线上首页和 `/api/pc/config?tenant_id=1` 返回 200，官网配置版本为 6，价格导航启用且链接为 `/pricing`。直接访问 `/pricing?tenant_id=1`、`/official/help?tenant_id=1` 返回 OpenResty HTML 404，说明请求未取得 PC 页面入口。开发服务器自动提供前端路由回退，不能用开发环境可打开证明线上直接访问可用。

## 本次源码修复

- 官网导航、页脚使用 NuxtLink，站内点击由客户端路由处理，外部 HTTPS 地址仍作为外链。
- ThinkPHP `route/app.php` 的根路径及 `/t/{tenant_id}/` PC 白名单补齐 products、scenes、cases、pricing、official、join-opc。
- `docker/nginx/default.conf` 增加对应 PC 页面回退，不覆盖 API、后台、静态资源或回调。

## 已有宝塔 / OpenResty 站点

源码更新不会自动改写已部署站点的 Nginx 配置。如果站点尚未把这些路径回退到 PC 入口，在该站点的 `server {}` 中加入以下规则，并由运维检查配置后重载服务：

```nginx
location ~ ^/(t/[0-9]+/)?(products|scenes|cases|pricing|official|join-opc)(/.*)?$ {
    try_files $uri $uri/ /pc/index.html;
}
```

前提：网站根目录为项目 `server/public`，`/pc/index.html` 是已部署的 PC 入口。已有 `location ^~ /` 或更早匹配的正则 location 可能使该规则不生效，须结合站点完整配置调整顺序；不要覆盖现有 API、PHP、后台、SSL 和回调配置。也可沿用项目 `public/nginx.htaccess` 的统一 ThinkPHP 伪静态回退，此时需部署本次 `route/app.php` 修复。

验收：无登录访问首页点击价格；新标签打开与刷新 `/pricing?tenant_id=1`；访问 `/official/help?tenant_id=1` 和 `/t/1/pricing`；检查 API 和后台入口仍正常。发布源码、更新站点配置与线上验收是分别执行的步骤。
