# API 管理与租户授权

平台和租户均使用一级「API 管理」，包含 API 设置、页面管理、接口契约、文档管理、调用与异常。平台调用记录默认查询全部租户，可筛选；平台页面和文档保留租户范围选择。微信授权菜单保持独立。

单租户授权入口位于平台租户列表「API 配置」，弹窗锁定租户，显示授权、调用上限、工具范围和控制台功能。租户可在授权范围内暂停或恢复本站调用。商城是「加入 API 调用」入口，模型卡片和应用接口价格详情提供独立开关，按能力设置，各 SKU 共用。商品上下架、价格、契约发布与 API 选择分别管理。

`open_platform_setting.enabled` 保存平台授权；配置 JSON 的 `tenant_enabled` 保存租户暂停状态。`OpenStore::settings()` 的 `enabled` 始终为实际调用状态，兼容原调用链。旧记录缺少 `tenant_enabled` 时默认为运行，但未授权租户仍不可调用。`grant_limits`、`grant_features`、`grant_apps` 保存平台授予上限；旧记录从原租户配置派生基线，首次保存固化，不能回退到更大的全局范围。

无新增数据库列或表。内置应用版本 1.0.1 通过原 AppRegistryService 安装或更新流程同步两端菜单与权限，并通过 DefaultAppService 覆盖新租户及存量租户；保留租户自建菜单。界面必须与服务端共同部署。

本地验证：

- `tests/open-platform/tenant-access-controls.php`：事务回滚测试，验证授权、暂停、旧授权上限、商城 API 选择及未改变 SKU。
- `tests/open-platform/admin-permission.php`：只读角色不能调用新增写接口。
- `tests/open-platform/install-fresh.php`：隔离表前缀下验证重复安装、菜单和新租户默认关闭。
- 前端 `tenant/tests/api-management-navigation.test.cjs`：两端主菜单及应用中心快捷入口兼容。

本地运行环境从 develop 执行 `tests/open-platform/install-local.php` 同步模块注册和菜单。上述测试不触发供应商生成或实际计费。系统更新包发布按独立发布流程执行。

若本地存在 `.composer-runtime/current.json`，Nginx 会继续加载已安装的固定版本，合入 develop 不等于切换 HTTP 运行代码。源码验收使用 `tests/open-platform/source-router.php` 的 loopback 预览，通过独立临时代理连接，验收结束删除代理和临时账号。不要为了预览修改当前版本指针。

本次浏览器验收已验证两端 API 一级菜单、租户列表弹窗加载与保存入库、租户 API 设置、商城 API 选择关闭及恢复；原租户设置已恢复，临时验收账号及会话已清理。前端使用显式 `app/open_platform/*` 路由，权限仍依据对应控制器动作检查。
