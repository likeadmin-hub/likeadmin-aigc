# 商品视频平台内置灵感模板

- 租户后台「商品视频 → 灵感模板」将本站已有模板设为内置、更新内置，或编辑/下架/删除平台内置项。该操作有独立的租户后台权限，接口校验当前租户归属；共享内置项由平台统一维护。
- 相同平台全部已启用商品视频的租户共享平台案例，租户原有模板和权限不改变。用户一键同款保留模式、商品素材顺序/角色、商品信息、视频描述、地区、语言、声音和模型规格，创建自己的独立草稿；商品来源链接不保存到模板。
- 素材直接引用原公开 OSS/COS/七牛文件。只新增草稿、案例及素材权限元数据，不下载、复制或重新上传图片/视频。私有存储、本地文件和临时签名链接不能设为内置；前台地址只能从批准的源定义解析，不能使用租户凭证代签共享素材。
- 发布产生 `app/apps/aigc_product_video/data/inspirations/*.json` 源定义。跨独立部署更新必须带上这些 JSON 和增量迁移；同一平台新增租户无需复制模板。定义使用稳定算力市场资源标识，在目标安装映射模型，缺失或不支持的模型/规格会明确拒绝同款。
- 新增应用表 `aigc_product_video_builtin`，将稳定内置键/版本映射到唯一平台案例。源 JSON 是内置内容的权威定义；升级安装及首次读取会幂等同步。重复更新不覆盖租户模板、套餐、上下架选择或用户草稿。
- 下架/删除保留墓碑及历史媒体引用。系统删除原作品/素材记录时不会删除共享对象；外部 OSS 清理规则也要保留这些素材。文件依旧占用原 OSS 的一份容量，正常访问会消耗带宽/浏览器缓存，不是零存储或零流量。

验证：`PRODUCT_VIDEO_LOCAL_TEST=x_cn php tests/product-video-builtins.php`（显式本地数据库、事务回滚、临时源目录、无联网/模型调用）；同时运行模板/资产/安装升级回归测试。不得把运行产生的媒体、数据库备份或编译输出作为源码交付。

## Manual authoring and record administration

Platform and tenant admins can upload product images and a preview video, then choose normal/advanced mode, description, audience and exact market model specifications. Advanced manual descriptions use the existing direct-description workflow and do not fabricate an AI analysis plan. Model capabilities determine the image limit. Parallel uploads preserve selection order, and templates cannot be saved during upload.

Platform authoring always creates a platform builtin, independent of tenant query filters, and requires public OSS assets. Tenant authoring stays tenant-owned. File IDs are authorized by terminal, tenant and media type; browser URLs and storage fields cannot grant access.

Video records support status, mode, user, time, description and real task identity queries. Record, system task and upstream task IDs are separate. Previews and common settings appear in the list. Deletion soft-deletes completed records only, retaining billing receipts, templates and physical files; active, uncertain or unfinished chapter tasks are protected.
