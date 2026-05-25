1.0.53 增加视频生成通道、任务素材字段和前端构建产物。数据库迁移为幂等新增字段与内置通道/规格 upsert；如需回滚，请先停用 wan、seedance、omni_flash_ext 通道，再回退代码版本和 public 构建目录。
