# 数字人对口型模式修复

内置通道对应算力市场 `lipsync/submit` 的模型：

| 通道 | 名称 | 模型 |
| --- | --- | --- |
| master | 大师版 | xiaojiayu1.0 |
| all | 全能版 | xiaojiayu2.0 |
| free | 体验版 | xiaojiayu3.0 |

通道选择决定提交模型，旧规格参数中的模型不能覆盖它。保存基础配置保留各通道模型。已有任务继续使用创建时记录的模型；自定义模型和其他供应商不套用内置映射。

修复 SQL 同步到应用迁移、系统升级和完整安装快照，不修改价格、开关、排序或历史任务。新租户读取平台通道，不单独创建模型配置。

先合入本地 `develop`，再预览本地迁移：

```sh
php scripts/migrate-digital-human-lipsync-models.php
php scripts/migrate-digital-human-lipsync-models.php --apply --database=<本地数据库名>
DIGITAL_HUMAN_MYSQL_TEST=1 php vendor/bin/phpunit tests/Feature/DigitalHumanLipsyncModelTest.php
```

此脚本仅允许连接本机数据库，执行时必须指定与当前连接一致的数据库名。正常请求只在内存中兼容旧配置，不执行数据库修复。

本修复保证 SaaS 提交到算力市场的公开模型标识正确；算力市场内部使用哪个推理服务由其自身的路由决定。
