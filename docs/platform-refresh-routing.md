# 平台端所有页面刷新 404：部署与验证

## 原因与修复范围

平台前端使用 history 路由，浏览器刷新或直接打开任意 `/platform/...` 页面时，必须返回 `public/platform/index.html`。站内跳转成功不能证明服务端回退规则正确。

本次修复为整个 `/platform/` 命名空间设置优先静态回退，覆盖所有菜单和多级页面，不按具体菜单逐个添加规则。现有平台静态资源仍按原路径读取；其他请求继续使用 ThinkPHP 伪静态。

2026-10-08 线上检查中，`/platform/`、`/platform/login` 返回平台入口；`/platform/system-service/license` 和 `/platform/foo/LICENSE` 返回 HTTP 404 的租户错误页面，尾斜杠版本与 `/index.php?s=/platform/system-service/license` 正常，`README.md` 结尾也受影响。这说明至少存在服务端文件名匹配/错误页处理干扰。未读取线上完整 Nginx 配置，因此不能把这些探测当作所有平台页面故障的完整原因。统一平台回退同时避免这类正则 location 抢占页面请求。

## 已有宝塔 / OpenResty 站点

更新源码不会自动改写宝塔已经保存的站点配置。`public/nginx.htaccess` 现在是 **server 级**完整伪静态模板，不应再放在 `location / {}` 内。

在站点 `server {}` 中加入或更新以下规则；已有 `/platform/` 规则时修改原规则，不要重复添加：

```nginx
location = /platform {
    try_files /platform/index.html =404;
}

location ^~ /platform/ {
    try_files $uri $uri/ /platform/index.html;
}
```

网站根目录必须为项目 `server/public`，且已部署 `public/platform/index.html`。如果使用整份新模板，替换旧伪静态规则，保留站点原有 API、PHP、SSL 等配置并确保仅有一个 `location /`。旧的 server 级 `if (!-e ...)` / 通配 rewrite 会先于 location 执行，需将它移入 `location /`，模板已完成此调整。Docker 自带的 `docker/nginx/default.conf` 已有 `/platform/` 优先回退。

使用该站点实际运行的 Nginx/OpenResty 执行 `nginx -t`，通过后重载，再进行线上验收。配置加载和线上刷新验收均完成后，才算线上生效。

## 验证

独立真实 Nginx 回归（临时端口、临时目录，不改已有站点、不重载服务）：

```sh
python3 tests/routing/platform_refresh_nginx.py --nginx /path/to/nginx
```

测试覆盖平台根入口、登录页、多级菜单页、授权页、带查询参数的页面、易被文件名规则拦截的页面、平台 JS 资源，并检查平台 API、租户 API、租户后台和 PC 请求继续使用原动态入口。

线上验收时使用已有平台登录会话，分别在不同模块点击页面后刷新，并将当前地址复制到新标签直接打开。页面应保留当前菜单和参数，不显示租户错误页；未登录时应进入平台登录页。同时确认 `/platformapi/config/getConfig` 和已存在的平台 JS/CSS 资源正常。
