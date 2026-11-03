# 1.0.428 更新入口接入

原版 1.0.428 会在安装新版更新器前，因旧请求或 Worker 的读锁而拒绝写入。仅发布修复后的 1.0.429 包不能改变这段旧入口代码。

`scripts/repair-updater-428-to-429.php` 是一次性 CLI 接入工具，要求 PHP 8.2，并且只接受 SHA-256 为 `b23bc0fdad7c75b59136906f74c2e14e84a32d58126e6593bf7c2d3872317ffc` 的 428→429 官方包。默认只预览：

```sh
php scripts/repair-updater-428-to-429.php --site-root=/absolute/site --archive=/absolute/system_1.0.429.zip
php scripts/repair-updater-428-to-429.php --site-root=/absolute/site --archive=/absolute/system_1.0.429.zip --apply
```

工具验证原版四个更新器 PHP 文件的指纹、安装包和后台文件清单，拒绝自定义/混合代码、版本不一致、待恢复事务和并发写入。接入只替换四个更新器文件和完整 `public/platform`，原件保存在 `runtime/updater-entry-repair-*`；可捕获的写入异常会恢复原件。不会修改版本标记、执行 SQL、改动环境配置/上传文件或停止任何进程。

接入成功不代表升级完成。刷新平台版本更新页面，再通过正常更新流程安装 1.0.429。更新提交成功后，涉及运行时代码时页面提示手动重启守护进程；用户可以选择暂不重启，升级结果仍然成功。工具重复执行会返回已接入状态。

验证覆盖原版 428 加同一发布包的完整数据库升级、旧读锁存活、预览、指纹拒绝、待恢复日志拒绝、注入失败回滚、后台目录替换、重复执行，以及配置和业务文件保护。测试使用一次性数据库，不连接线上数据库。
