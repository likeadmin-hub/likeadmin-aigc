# 1.0.390 release

1. 微信小程序管理、配置和授权仅对已取得有效商业授权的平台开放。
2. 未开通时显示说明并隐藏小程序操作入口，访问受限页面时提供友好提示。
3. 图片数字人应用适配最新系统版本，保持安装与升级兼容。

Exact step: 1.0.389 -> 1.0.390. No SQL migrations.
Backend feature: 584c9c008 and 562427289. Frontend feature: db64623.
The package excludes updater-owned upgrade/version.json markers.
Existing WeChat credentials and tenant app access states are preserved.
