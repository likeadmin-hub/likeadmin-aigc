# 图片数字人

应用 `aigc_pic_lipsync` 接入算力市场 `pic_lipsync/submit`，模型固定为 `super-lipsync-pro`。文档：https://api.likeadmin.cn/user_center/docs?slug=pic-lipsync-submit

- 快速、标准、MAX分别对应市场SKU的 `quality=fast/standard/max`。产品、SKU和租户上架状态实时校验，平台售价及租户售价由市场读取。
- 音频驱动只提交人物图片及驱动音频；文案驱动提交口播 `content` 和参考音色 `audio_url`，动作描述独立存放在 `prompt`。
- PC复用全驱动数字人的形象库、音色库、上传/历史选择、编辑器和历史创作。选择音色时传 `voice_id`，后端解析已有样本并直接提交 `mode=text`，不创建TTS任务、不预生成驱动音频；上传音频或选择历史上传则提交 `mode=audio`。
- 音色访问限制为当前租户公共音色或当前用户音色。历史上传可用 `driver_voice_id` 显式音频驱动，仍不提交文案；新上传采用当前用户的 `audio_asset_id`。音色标识保存在请求快照中，重新编辑和重试沿用该素材。
- 参考音色的实测时长独立缓存10分钟，保留小数秒，不把精度写回只支持整数秒的共享音色表。计费仍按市场输入音频规则。
- 复用现有人物图片库；参考/驱动音频单独标记为 `pic_lipsync_audio`。所有选择按租户、所有者或公共形象权限核验。
- 上传音频由服务端探测时长，未知时长不提交。按输入音频秒数预占，任务成功后结合上游输入用量结算，失败释放预占。作品保存后才结算。
- 共享任务 worker 自动查询和回写业务作品；列表只读本地状态。重复 `request_key` 不创建新业务任务。
- PC第三个标签、统一创作记录、平台配置与跨租户任务、租户配置与任务、移动创作与作品页均有入口。

## 安装和本地验证

可通过应用中心安装并开通免费默认套餐。新租户仍通过应用中心开通，上下架和权限沿用应用生命周期；卸载清理仅删除本应用业务表，不删除共享素材。

全新安装 SQL、应用 migrations、upgrade 与 public/upgrade 含相同建表及默认套餐。安装流程调用 AppRegistryService，同步前端入口、API声明和菜单，避免手工写菜单表。

仅在合入本地 develop 后执行：

```sh
docker exec bt php /www/wwwroot/likeadmin_aigc_saas/server/scripts/migrate-picture-digital-human.php --apply --database=z_cn --tenant=1 --sync-market
```

脚本拒绝非本地数据库，市场刷新只影响 `pic_lipsync`，不会刷新其他产品。发布时仍需正常系统/应用打包和前端构建；本地源码验证不代表线上部署。

本地仅处理本应用可运行 `php think ai:task-worker --app=aigc_pic_lipsync`。不指定 `--app` 时保持现有共享 worker 行为，租约、重试、结果处理逻辑一致。
