[![逻辑帧AIGC 产品介绍：免费开源，一键搭建自己的 AIGC 平台](./.github/media/logicframe-aigc-intro.gif)](./.github/media/logicframe-aigc-intro.mp4)

# 逻辑帧AIGC

**免费开源，一键搭建自己的 AIGC 平台，开启 AI 创业之路。**

把模型、算力、工具应用与用户运营组织在同一套系统里。你可以从创作者工具站、电商内容服务、数字人生产或企业 AI 门户开始，搭建自己的平台，并围绕实际用户需求持续扩展。

[观看完整宣传片 · MP4 / 56秒 / 带配乐](./.github/media/logicframe-aigc-intro.mp4) · [在线体验](https://aigc.likeadmin.cn/ai?tenant_id=1) · [商业授权与工具应用中心](https://api.likeadmin.cn/user_center/aigc_services) · [快速部署](#快速部署) · [部署与运维文档](./docs/deployment-and-operations.md)

## 你可以用它做什么

| 场景 | 可以组织的服务 |
| --- | --- |
| 创作者工具站 | AI 对话、智能文案、图片、视频、音乐与创作工作流 |
| 电商内容服务 | 商品图、商品套图、多角度展示、试衣、模特穿戴与产品宣传视频 |
| 视频内容工作室 | 数字人、微视频、AI 短剧、分镜素材与智能剪辑 |
| 企业 AI 门户 | 根据业务配置应用与模型，统一管理用户、权限和使用记录 |
| 自有品牌平台 | 结合多租户、品牌配置、会员套餐、积分计费和开放 API 开展运营 |

## 34 款应用，按场景组合

当前应用市场包含 34 款应用，覆盖创作、加工、展示与内容制作。具体可用能力以版本、应用开通状态和租户配置为准。

| 能力方向 | 代表应用与功能 |
| --- | --- |
| 智能创作与工作流 | AIGC 对话、智能文案、无限画布、素材引用与节点编排 |
| 图像创作与编辑 | AI 生图、局部重绘、无缝扩图、风格化、去背景、图片翻译、一键消除、换发型、老照片修复 |
| 电商与商品展示 | 商品图、商品套图、商品多角度、AI 试衣、模特穿戴、服饰套图、产品宣传视频 |
| 视频与数字人 | AI 视频、数字人视频、图片数字人、全驱数字人、动作迁移、动作替换、短视频去水印 |
| 音乐与声音 | AI 音乐生成、歌词创作、参考音频、纯音乐与人声、音乐翻唱 |
| 内容制作工作室 | 微视频、AI 短剧、智能剪辑，以及剧本、角色、场景和分镜素材组织 |

## 从创作能力到平台运营

- **多租户与权限**：平台、租户、用户分层管理，支持租户授权、应用开通、菜单与权限配置。
- **应用与模型**：通过应用中心组织服务范围，配置模型通道、前端入口和应用状态。
- **会员与计费**：支持会员套餐、积分充值、消耗记录、账单与用量管理。
- **开放 API**：提供开发者密钥、接口文档、在线调试、调用记录与异步任务管理。
- **品牌与多端**：支持品牌配置，交付平台后台、租户后台、PC 前台、H5 与微信小程序。
- **部署与更新**：支持本地化部署、系统更新、私有更新源、更新校验和长任务处理。

平台源码免费获取；服务器、第三方模型与算力服务的费用按实际接入和使用情况安排。

## 快速部署

### 1. 获取代码

```bash
git clone --branch master --single-branch https://gitee.com/likeadmin/likeadmin-aigc.git
cd likeadmin-aigc
```

也可以使用 [GitHub 镜像](https://github.com/likeadmin-hub/likeadmin-aigc)。开源仓库已经包含管理后台、PC 与 H5 的编译产物，Docker 部署不需要额外获取前端源码或安装 Node.js。

### 2. 准备配置

先安装 Docker Engine 24+ 与 Docker Compose 2.20+，再创建配置文件：

```bash
cp .env.docker.example .env.docker
chmod 600 .env.docker
```

编辑 `.env.docker`，替换所有 `CHANGE_ME` 占位值，填写实际域名、数据库密码、系统密钥与初始管理员信息。配置含义、密钥生成和域名示例见 [Docker 部署指南](./docs/deployment-and-operations.md#docker-compose-部署)。

### 3. 校验并启动

```bash
docker compose --env-file .env.docker config --quiet
docker compose --env-file .env.docker up -d --build
docker compose --env-file .env.docker ps -a
```

默认端口为 `8080`。首次启动会准备数据库和管理员；登录平台后台后，再按业务创建租户、配置应用与模型。

| 入口 | 默认地址 |
| --- | --- |
| 平台后台 | `http://服务器IP:8080/platform/` |
| 租户后台 | `http://服务器IP:8080/admin/` |
| PC 前台 | `http://服务器IP:8080/pc/` |
| H5 | `http://服务器IP:8080/mobile/` |

平台后台使用 `.env.docker` 中配置的管理员账号。已有数据卷不会因为修改配置文件而自动重置管理员密码。

使用宝塔或 Nginx + PHP-FPM 部署时，网站、Worker 与计划任务均需使用 **PHP 8.2**。完整环境要求、源码安装与伪静态配置见下方文档。

## 文档导航

| 文档 | 内容 |
| --- | --- |
| [部署与运维指南](./docs/deployment-and-operations.md) | 运行环境、全新安装、Docker Compose、域名与 HTTPS、宝塔配置 |
| [任务 Worker 与计划任务](./docs/deployment-and-operations.md#ai-任务结果-worker-守护) | 启动入口、守护配置、日志与补偿任务 |
| [更新与发布](./docs/deployment-and-operations.md#更新与发布) | 更新前准备、备份和更新流程 |
| [系统发布检查清单](./docs/system-release-checklist.md) | 开发者打包、校验与发布要求 |
| [画布兼容与升级说明](./docs/canvas-upgrade-compatibility.md) | 历史会话、项目隔离与旧版本兼容 |
| [开发测试要求](./docs/deployment-and-operations.md#开发测试要求) | 测试环境、受控 Provider 与真实验收边界 |

## 开源说明

开源版本允许商业使用，请保留公司版权和 Logo，并遵循 [LICENSE.txt](./LICENSE.txt) 及仓库声明。

当前开源仓库交付后端代码及已编译的前端资源；完整 Vue 前端源码计划在 2.0 版本开放，目前尚未包含在开源交付中。

项目沿用 LikeAdmin 技术生态。相关资源：[算力超市](https://api.likeadmin.cn) · [免费开源框架](https://likeadmin.cn) · [算力超市介绍](https://mp.weixin.qq.com/s/FNEqnnG6IWGotshb2ITgXQ)。

## 年度服务与技术支持

项目的持续维护与迭代需要长期投入。考虑到团队维护精力与资源有限，我们提供**有偿年度服务**，为有持续使用和技术支持需求的用户提供以下权益：

- **工具应用优先体验**：优先体验部分工具应用及新增能力。
- **版本更新优先获取**：优先获取版本更新与功能改进。
- **专属技术支持**：提供专属沟通渠道与技术支持。

具体服务范围、支持方式与年度费用，欢迎联系我们了解。

如需获取商业授权或查看全部 AIGC 工具应用，请前往 [商业授权与 AIGC 工具应用中心](https://api.likeadmin.cn/user_center/aigc_services)。

此外，我们也提供部署、二次开发、应用接入、私有更新源、商业授权与运营支持。

## 参与贡献与交流

欢迎提交 Issue 反馈问题、提出需求，或推荐希望接入的工具应用。报告问题时，请附上版本、运行环境、复现步骤及已去除敏感信息的日志。

欢迎有成熟开源项目经验、熟悉 AI 辅助开发的开发者参与应用接入、问题修复与长期维护。合作报酬、奖励和维护方式可联系沟通，并为参与合作的开发者提供不限量 GPT 账号支持。

- 公众号：**程序员在囧途**
- 联系电话：**18786709420**
