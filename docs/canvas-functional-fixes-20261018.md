# 无限画布功能修复

1. 文本节点通过现有 assetRegister 接口以 asset_type=text 登记正文快照。服务端校验项目租户与用户归属，正文存于原资产表的 meta_json，不创建物理文件、不接受客户端存储配置；同一项目节点重复登记更新原记录。项目详情可直接恢复正文，原媒体登记行为保持不变。历史仅存在浏览器中的文本资产可重新点击添加进行登记。
2. 项目列表传 page_no/page_size 时返回 lists/count/page_no/page_size，不传分页参数保留旧数组和默认条数。PC 分批读取全部摘要后保留原本的文件夹、搜索与排序语义，再按24项显示；租户端按服务端分页查询。无数据库迁移、路由或权限变化。
3. Agent 音乐作品从原音乐任务恢复提示词、歌词、通道及可用参数。新音乐任务在现有 provider_payload 中记录歌曲模式、纯音乐、语言等公开参数快照，后续回填不读取当前偏好。历史缺少标志的任务只恢复可证实信息，不猜测无法恢复的旧参数。现有备份与修订号机制修复历史空提示词节点，不覆盖用户后续编辑，不跨租户、用户、项目或页面。配音不按音乐恢复生成参数。原通道不在画布当前列表时需要显式选择可用模型。

验证必须在已包含功能提交的本地 develop 执行。PHP 回归脚本使用本地事务/隔离表，不调用模型或修改业务账单：

```sh
php tests/Feature/CanvasAssetsAndPaginationTest.php
php tests/Feature/CanvasArtifactGenerationTest.php
php tests/Feature/CanvasSaveConcurrencyTest.php
php tests/Feature/CanvasConversationScopeTest.php
php app/apps/aigc_canvas/tests/run_market_music_payload_contract.php
```

PC 回归覆盖文本登记失败与迟到回包隔离、205个项目分页读取、旧数组兼容、音频参数保持及生成请求。仅交付源代码，不包含前端构建产物或发布包。

本地验证：PC 画布相关425项回归通过；文本资产与分页23项、作品回填50项、保存并发24项检查通过。会话启动、项目隔离、共享音乐工作流与作品库回归通过；音乐流程使用真实任务与worker、替换供应商传输并回滚事务，未调用外部模型或产生实际扣费。PC Nuxt构建、租户端Vite构建及修改PHP语法检查通过。自动浏览器访问本地页面受策略限制，未进行本轮逐按钮与付费生成验收。
