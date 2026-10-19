# 微视频应用确认规格

来源：当前会话用户逐项确认，并明确要求直接开发及真实生成、单段重试、合并验收。

- 新应用 `aigc_micro_video`，接入现有应用安装、权限、会员、租户、素材和模型市场积分链路。
- 主任务表与分段任务表；独立尝试记录保留消费和上游关联。成功片段不重做，只有明确失败片段可创建新尝试，未知提交先核对。
- 后台：基础、视觉/脚本模型、可用视频模型多选及默认模型、创作方向管理（封面/示例/创作规则/一致性约束/启停）、提示词配置和任务详情。
- 用户选择视频模型，能力/规格/初步费用联动。多图上传，一句话主题或遵循提示词，智能提示词辅助。
- 两步提交：填写后开始分段；查看每段时长、完整提示词、参考图片、起止状态和衔接要求，编辑确认、重新报价后提交生成。
- 每个生成片段内部按镜头时间轴编排：例如一个15秒片段使用0-3秒、3-6秒、6-10秒、10-15秒。至少两个镜头，各段独立从0秒计时，连续覆盖本段时长；镜头时间是创作约束，最终时长仍以实际返回视频为准。
- 整片共享人物/商品/场景/光线等连续性设定；各段携带共同约束、相邻状态及参考素材。遵循模式保留用户意图。
- 生成后按整片展示，内部错误不外泄；可恢复中断显示待继续，继续生成只恢复未完成项。后台可查分段与尝试详情。
- 合成只顺序直接拼接，保留实际时长和原音轨，不主动变速、裁剪、填充、增加字幕/配音/BGM/转场。必要媒体格式统一，最终时长经 ffprobe 实测。
- PC 参考现有 ImagineVideoStudio：左表单、右创作方向/历史创作，提交后切历史；移动端独立纵向页面，H5/小程序共用逻辑。
- 所有当前主题及自定义皮肤使用现有运行时 tokens，所有弹层/错误/忙碌/焦点/底栏状态适配；主题变化不能丢输入。
- 文字说明：所选时长为创作目标，最终时长以实际返回并合成的视频为准。
- 本地 feature 开发，分开提交 web/server 并合入本地 develop 验收，不推送远程 develop/master、不发布。
- 验收须区分纯测试、隔离恢复测试、真实模型、浏览器及小程序运行证据；实际不可运行部分明确记录，不以构建替代。

## 共用接口合同 v1

现有项目路由格式 `/app.aigc_micro_video.{controller}/{action}`，服务端三端 scene 注册。

### 用户

- GET `config/detail`: `{status, models, default_model_id, default_quality, default_ratio, target_duration_options, max_images, max_segments, directions, duration_notice}`。models 元素：`{id, label, description, max_reference_images, specs:[{quality,ratio,duration,generation_method,market_product_id,market_sku_id,resource_type,user_charge_points}]}`。不返回后台模板/成本/凭证。
- GET `direction/lists`: 方向数组 `{id,code,name,description,cover_image,cover_url,example_video,prompt?}`；用户不返回内部 prompt。
- POST `prompt/write`: form，返回 `{prompt,planning_points}`。
- POST `generate/estimate`: form（初步）或 `{id,plan_revision}`（确认/续作），返回 `{user_charge_points,planning_points?,quote_token?,approximate}`。
- POST `plan/create`: form + `idempotency_key`，返回主任务详情。异步规划，读取 detail 等待。
- POST `plan/save`: `{id,plan_revision,segments}`，保存并推进版本，返回详情。
- POST `generate/index`: `{id,plan_revision,quote_token,idempotency_key}`，冻结版本并排队，不同步生成。
- POST `task/retry`: `{id,quote_token,idempotency_key}`，只恢复需要恢复的执行，返回详情。
- GET `task/lists`: `{lists:[detail],count}`；GET `task/detail?id=`；POST `task/delete`。

form：`{model_id,quality,ratio,target_duration,mode:'theme'|'prompt',theme,prompt,images:[{uri,url?,name?,role?}],direction_id}`。

detail：`{id,title,status,status_text,can_continue,params,prompt,analysis,continuity,plan_revision,segments,estimate,result_url,cover_url,actual_duration,create_time,update_time}`。状态 `planning,awaiting_confirmation,running,waiting_continue,merging,success,failed,canceled`；段落只在待确认时返回用户，在运行/历史隐藏。segments 元素：`{id,sort,title,duration,prompt,image_indices:[0-based],start_state,end_state,continuity}`。用户 images 展示使用 params.images。状态 mapping 由服务端提供 status_text。raw error 和供应商信息只后台返回。

### 后台

- GET `config/detail` 同上另有 `{available_models,text_models,config}`；text_models 使用 MarketTextModelRuntimeService.modelGroups 原结构。config：`{status,video_model_ids,default_model_id,vision_model_id,script_model_id,default_quality,default_ratio,target_duration_options,max_images,max_segments,prompt_template,analysis_template,planning_template,continuity_template,retry_template}`。
- POST `config/setup` 保存 config 字段；方向 `lists/save/status/delete`：`{id,code,name,description,prompt,continuity_prompt,cover_image,example_video,status,sort,is_builtin}`。
- GET `task/lists`, GET `task/detail`（包含所有段及尝试日志），POST `task/retry`（可指定 `segment_id`，也可只重试合并）。

后台/PC/移动端遵循此合同。接口若需调整必须先同步三端实现者。
