# 短剧提示词工作区

## 入口与规则维护

租户端入口：`/admin/aigc-short-drama/prompt`。仅覆盖 `aigc_short_drama`，没有改变其他应用配置。

文档版（format_version=3）按剧本创作、主体创作、场景创作、分镜创作排序；辅助创作默认收起。
主页面为剧本策划与改写、分镜编排与画面描述、主体参考图、三视图／多角度图、场景参考图、分镜图片、分镜视频。
辅助创作为识图提取主体、识图提取场景、背景音乐。数量由实际调用归属决定，不是固定产品条数。
每项标明“用于哪里”和“可以调整什么”，只有一份正文；版本／实发请求／回滚在“记录与恢复”。

`prompts/documents.json` 是有效规则到创作文档的机器可读对应表：每个 sections 项保留适用条件，映射到 catalog 的来源方法、使用入口和默认正文。
extra_defaults 收录从 PC 三视图、音乐乐器补全、时间线图片及系统消息中抽取的剩余正文；原始中英文不翻译。
`prompts/catalog.json` 保存实际默认正文、中文用途、触发条件及源函数；后台不得另抄一份默认值。
新增规则须同时添加 `used_at`、`trigger`，并在实际调用处用 `ShortDramaPromptCatalog::text` 替换对应文本。
英文默认规则保持原文。动态剧情／画风／主体／分镜资料自动组装，不要求租户编写英文变量。
输出结构、引用绑定、实际时长、模型能力、计费和素材选择不属于可编辑创作规则。

## 版本与隔离

`ShortDramaPromptWorkspace` 保留 legacy 和 workspace 旧规则版；只有确认迁移保存才启用 documents。
文档模式为 custom（替换该环节默认创作区域）、inherit（平台文档 → 应用默认）和 application（直接使用应用默认，绕过平台）。
文档 custom 正文不能为空；空白、未知条件、旧模板变量、超长内容、版本冲突均报错，不静默退回默认。
条件标题 `【适用：人物主体】` 等由服务器解析，无标题文字适用于该环节所有任务。不是把人物、物品、空镜、多集规则无条件合并。
旧规则版仍保留原先显式空字符串语义。
新任务创建时捕获 `_prompt_snapshot`；执行和失败重试沿用快照。主动创建的新生成任务捕获当前配置。
多集生产开始时捕获系列快照，后续分集沿用。上线前的旧任务无法还原未知历史配置，会显式标记缺少快照。
快照不暴露在普通用户响应，也不允许客户端提供它来覆盖服务端版本。同步执行作用域通过 `finally` 恢复，防止常驻工作进程跨租户串用。

保存携带 fingerprint；并发修改产生冲突，不接受覆盖。版本表只追加，回滚也创建新版本。
旧自定义配置保持旧执行方式，确认迁移前不自动启用新规则。首次保存归档旧基线，能够回滚到兼容模式。
启用新版后，旧配置接口更改提示词会明确报错，其他配置仍可正常保存。

## 请求预览和记录

预览与执行复用应用层的 script、repair、image、video、vision、music 组装函数，不调用提供商、不创建生成任务、不扣点。
最近生成记录保存真实应用请求、已用规则、来源、版本指纹和输入上下文；使用记录对比时不读取后来变更的项目来替换这些输入。
手工填写旧任务编号的预览使用当前项目内容，页面明确区分重组结果和历史实发记录。
模型渠道的后续参数适配、默认参数及 HTTP 请求以模型运行日志为准，不将应用预览冒充最终 HTTP 报文。
自动补全创作要求归属相应文档，仅在缺失条件满足时参与；质检修复沿用同一任务快照。音乐避免事项写在音乐正文中，不展示无效的独立负面参数。
PC 三视图不再提交第二份内置要求；prompt_input_version=3 只表示输入协议，应用默认仍由后端目录补入以兼容原表现。
编辑与提交数据不再经过展示用的关键词清洗。新文档任务对自动补全字段记录 `_prompt_field_sources`；只有明确标为应用补全的字段才可排除，来源不明的历史资料保留并在预览正文说明。

## 恢复与兼容接口

- GET detail?format_version=3 返回文档目录、用途、有效正文、应用默认、来源及迁移问题。
- POST save/preview 接收 format_version=3、document_settings、fingerprint；兼容既有 overrides 请求，但文档版启用后拒绝旧版保存。
- POST restoreApplication 接收 target（文档 key 或 all）、fingerprint。默认仅返回差异；confirm=true 才原子保存为新版本。
- “恢复应用默认”从当前代码目录读取，不是继承平台、不删除历史。回滚历史版本使用目标有效快照并新增版本。
- 旧自定义原文按归属带入草稿，不经 LLM／翻译；无法自动映射的模板和未生效音乐负面字段列为迁移问题，必须逐项核对确认。
- 版本快照包含 format_version、documents、document_settings、默认目录 hash、条件词典、额外默认文本和 assembler_version；任务冻结整个快照。
- 文档版复用已有版本表／请求表的 JSON 字段，没有新增表；首次使用工作区仍需下述原有迁移。

配置及生成记录接口均校验当前租户及原有配置权限；跨租户版本号和请求 ID 不可读取。
请求记录包含用户创作内容，仅租户有权限的管理员可查看；不要将这些原文打印到公共日志。

## 数据库与验收

升级脚本：`app/apps/aigc_short_drama/migrations/upgrade_20260913_prompt_workspace.sql`，另同步通用升级目录和新安装 SQL。
新增两张共享、tenant_id 隔离的表：`aigc_short_drama_prompt_revision` 和 `aigc_short_drama_prompt_request`。
部署源代码前需先执行迁移；缺表明确失败，不悄悄显示保存成功。此变更不包含打包或发布。

默认请求基线保存在 `tests/fixtures/short_drama_prompt_baseline.json`，不要为了通过测试而直接改写基线。
测试主要入口：

```sh
php vendor/bin/phpunit --filter 'ShortDrama(Prompt|ScriptPromptConfig|ScriptTimeline|VideoAudio|StoryboardSelection)'
SHORT_DRAMA_PROMPT_DB_TESTS=1 php vendor/bin/phpunit --filter ShortDramaPromptPersistenceTest
```

文本模型捕获测试在真实调用边界使用替身停止，不连接提供商。
图片／视频／音乐测试检查共享组装器输出中的实际自定义原文，并检查被替换默认不再出现；持久化测试覆盖文档保存、冲突、当前项／全部应用默认恢复、回滚、迁移、冻结输入预览和租户隔离。
数据库测试使用专用虚拟租户并在事务结束回滚；不要用真实租户生成任务作为计费测试。
修改默认规则、组装入口或数据清洗时必须重跑时长、音频、素材选择回归，不承诺模型百分之百遵从自然语言规则。
