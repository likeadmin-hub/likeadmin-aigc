# 视频效果配置与验证

静态页面 91 条记录按演示素材去重为 90 个效果；重复项为 Outfit check/outfit_check。原分类保留。
`reference-review.json` 记录逐个视频的时长、观察结果与分析方式。分析覆盖每个视频全时段的十个顺序画面；火焰、故障和列车效果额外用每秒六帧检查。指令为依据观察原创编写，不是参考站的服务端提示词。

- 镜头类区分摇摄、平移、光学变焦、移动变焦、升降与环绕。
- 动作类写明预备、发力、运动、结束姿势和肢体连续性。
- 特效类写明产生位置、发展路径、遮挡关系和结束状态。
- 六个明确切换目标画面的效果（火焰、烟雾、立柱、标志、渡鸦、魔法流动）只允许首尾帧。
- `data/video_effects.json` 是源预设，默认停用、无模型绑定；每个租户独立导入并选择其可用模型。已有配置不会因升级自动被覆盖。
- 选择效果自动填充适配模型、生成方式和效果提示词。场景输入仅在为空或仍是上次自动填入的内容时更新。效果提示词可以编辑，保存到任务快照并在重试/再次创作时恢复。
- 默认预览不上传为用户参考素材。效果列表只为可见区域加载视频。

导入脚本 `scripts/import-video-effects.php` 默认只校验。明确传入租户、商品模型、已下载的素材目录及 `--apply` 后才上传素材中心并保存。重跑会替换同标识预设、保留自定义效果及已有托管素材，并复用按内容散列命名的素材记录。素材文件需命名为效果标识加 `.webp` / `.mp4`。

本轮用户要求不消耗算力：自动验证只覆盖配置、输入校验、指令编译、模型请求构造与页面交互。90 个效果均没有进行真实模型输出质量验收，复杂变身、肢体动作、文字/Logo 保真和长镜头应由用户试生成后调整；不保证与参考演示逐帧一致。

Before importing into an existing installation, apply `app/apps/aigc_video/migrations/upgrade_20261012_effect_config_capacity.sql`. The configuration field uses MEDIUMTEXT to retain the full catalog; fresh installs include the same type. New tenants share this table.
