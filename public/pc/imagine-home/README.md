# 短剧首页参考素材

页面参考：https://www.imagine.art/enterprise （2026-09-13）。

本目录仅包含页面视觉素材；未引入第三方应用脚本、登录逻辑或追踪代码。

- `google-sans-flex.woff2`：参考站公开样式所引用的 Google Sans Flex 字体。
- `character.jpeg` 至 `perfume.jpeg`：参考站公开模板封面，来源 `client-upload.imagine.art` / `asset.imagine.art`。
- 仅保留技能封面：`character.jpeg`、`mint.jpeg`、`fashion.jpeg`、`sunglasses.jpeg`、`snooker.jpeg`、`social.jpeg`、`mascot.jpeg`、`ugc.jpeg`、`perfume.jpeg`。
- 非技能参考图片已在完成备份及校验后物理删除。租户上传的背景、真实作品及素材库数据不属于本目录，未进行清理。

这些卡片目前是前端灵感预览，不表示本平台已经提供对应第三方工作流。中文文案通过平台的 `translatePcText` 和 `locales/ui-text.json` 翻译。用户的提示词、文件和生成请求仍只进入现有短剧服务。

配置位于租户后台「AI短剧 → 基础配置 → 短剧首页风格」。`home_style` 使用已有租户应用 JSON 配置；缺省值为 `default`，不需要新增表或迁移。正式入口 `/ai/short-drama` 读取短剧首页接口返回的配置。
