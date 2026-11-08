# 应用开关与访问契约

应用开关复用现有数据，不新增数据库字段或第二个开关。

- 平台应用中心：`POST /platformapi/app/enable`、`POST /platformapi/app/disable`，参数 `app_code`。权威状态为 `app.status=installed/disabled`。仅基础框架 `system_default` 不允许关闭；默认工具应用仍禁止卸载。
- 租户应用中心：`POST /tenantapi/app/shelf`，参数 `app_code`、`shelf_status=on/off`。权威状态为 `tenant_app.shelf_status`。`enable_status` 保留为租户授权状态，不以新字段替代。
- 应用配置中的“展示状态”统一尊重平台和租户的 `tenant_app_config.status`；任一层隐藏都移除用户入口。各应用原有“创作服务状态”也纳入同一判断：29 类应用使用配置表 `status`，微视频使用 `config_json.status`。任一层停用都关闭用户入口及新的创作请求；缺失配置保留原初始化默认值。配置来源清单与边界见 `tests/app-access/README.md`。
- 用户目录：`GET /api/app/frontend?terminal=pc|uniapp`，继续返回原有数组。一般应用同时满足平台 installed、租户 enabled、租户 shelf on 和原有购买/有效期规则才返回。默认应用也尊重已有租户开关；尚无授权行的默认应用保留首次开通行为。
- PC 和移动端侧栏、工具卡片、装修与横幅使用同一有效目录；应用链接保留 app_code 和模式查询参数。目录未知或加载失败时不默认展示应用入口。直接访问关闭页面仍显示“当前应用已关闭”，不挂载创作组件。
- 租户后台目录：`GET /tenantapi/app/frontend` 使用同一有效入口；`/tenantapi/app/my`、`/tenantapi/app/market` 仍展示关闭应用的真实状态与 `can_shelf`，应用后台管理权限独立于用户端开关，拥有者可以重新开启或查看历史数据。
- 关闭的用户执行 API 返回 `code=0`、`msg=当前应用已关闭`、`data={app_code,app_disabled:1}`。会员拒绝仍返回 `need_membership`/`need_login`，与关闭状态分开。

## 既有任务与附属下载

用户 GET `Task/Result` 的 `lists/detail/status/progress` 保留访问，由原控制器验证租户和用户所有权。音乐 query、短剧 Generation/detail、lists、文案 events、Agent 的 owned run detail/events 等明确历史路径同样保留。视频 Task/refresh 仅对既有消费记录排入查询补偿，允许继续执行；stream、重试、生成和其他创作写请求仍检查应用开关。后台管理、worker、回调、退款和结算流程不套用新的用户端关闭拒绝。

`ai_tool_download` 没有独立 manifest，是既有作品的附属下载能力。新建 ZIP 时使用参数中的来源 `app_code` 检查主应用开关；已颁发的单次 `export?file=...` 凭据可完成历史下载。ZIP 服务继续验证 task/result 的 tenant、user 所有权。

## GEO 遗留展示通道

`aigc_geo` 原有租户应用中心为 display-managed 卡片，`tenant_app_config.status` 控制展示。保留其预览入口：展示为开、平台没有 disabled/removed，且已有租户授权行没有关闭时，可出现在目录，即使尚无有效付费授权。目录可见不授予生成权限；执行 API 继续执行原购买、有效期与会员规则。展示关闭或平台关闭时，入口移除且执行不可用。不得据此将 GEO 改成默认免费应用。

## 更新与并发

默认应用 ensure/sync 仅为新记录初始化 on/enabled，既有记录只更新版本元数据。应用注册、应用包和系统包迁移保留已有平台 disabled 状态及租户 buy/shelf/enable/expire 字段；新应用、新租户记录可正常创建。失败时只恢复已知授权元数据，不重放 SQL。

迁移保护、平台/租户显式开关、应用购买/续期/赠送及 OEM 最终交付数据库事务共用按当前数据库和 prefix 区分的 MySQL session 命名锁。锁可在同一 session 重入，跨 DDL commit 保留，finally 成对释放。竞争中的开关请求明确返回“应用状态正在更新，请稍后重试”，避免旧快照覆盖后台的新选择。锁只位于同步 metadata 方法中，不跨 canonical/runtime 的父子 PHP 进程边界。

## 本地验证

全部命令均创建私有 MySQL 容器，无主机端口，不连接应用数据库；工作区 `.env` 被空文件覆盖。需要本地镜像 `likeadmin-install-worker-test:local` 与 `mysql:8.0`。

```sh
python3 tests/app-access/switch.integration.py

mask_dir=$(mktemp -d)
touch "$mask_dir/mask.env"
python3 tests/runtime-updater/system-service.integration.py \
  --updater --canonical --cases success metadata-failure \
  --app-packages --app-access-switches --env-mask "$mask_dir/mask.env"
rm "$mask_dir/mask.env"
rmdir "$mask_dir"
```

前者验证全部默认工具的租户隔离、平台/租户关闭、三个终端目录、后台上下架及重开、公开配置/生成拒绝、历史读取、更新保护、GEO 预览边界、附属下载及锁竞争。后者走真实签名系统包和应用包、canonical 子进程、成功/metadata 失败与重试、SQL 单次执行及业务记录保护。
