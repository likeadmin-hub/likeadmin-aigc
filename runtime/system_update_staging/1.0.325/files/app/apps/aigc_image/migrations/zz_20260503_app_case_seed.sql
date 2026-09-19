INSERT INTO `la_app_case`
(`tenant_id`, `app_code`, `title`, `prompt`, `media_type`, `cover_uri`, `media_uri`, `reference_images`, `config_json`, `source_task_id`, `source_result_id`, `status`, `sort`, `create_time`, `update_time`, `delete_time`)
SELECT
  tenant.`tenant_id`,
  'aigc_image',
  seed.`title`,
  seed.`prompt`,
  seed.`media_type`,
  seed.`cover_uri`,
  seed.`cover_uri`,
  '[]',
  seed.`config_json`,
  0,
  0,
  1,
  seed.`sort`,
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP(),
  0
FROM (
  SELECT DISTINCT `tenant_id`
  FROM `la_tenant_app`
  WHERE `app_code` = 'aigc_image'
    AND `enable_status` = 'enabled'
) tenant
JOIN (
  SELECT 240 AS `sort`, '未来机械人像' AS `title`, 'image' AS `media_type`, 'uploads/app_case/aigc_image/default/card-1.png' AS `cover_uri`, '未来机械风格女性半身肖像，金属细节，冷调蓝光，电影级布光，超高精度，主体突出，背景虚化，高级时尚感' AS `prompt`, '{"channel":"OpenaiM","quantity":1,"ratio":"1:1","quality":"1k","fields":["OpenaiM","1张","1:1","1k"]}' AS `config_json`
  UNION ALL SELECT 230, '电影感少年肖像', 'image', 'uploads/app_case/aigc_image/default/card-2.png', '少年人像特写，电影感构图，柔和侧逆光，肤质真实，浅景深，胶片色调，安静克制的情绪表达', '{"channel":"OpenaiM","quantity":1,"ratio":"3:4","quality":"1k","fields":["OpenaiM","1张","3:4","1k"]}'
  UNION ALL SELECT 220, '暗黑巨龙飞行', 'image', 'uploads/app_case/aigc_image/default/card-3.png', '暗黑奇幻巨龙掠过天空，乌云与闪电交织，史诗级场景，强烈体积光，动态冲击，细节丰富', '{"channel":"OpenaiM","quantity":1,"ratio":"16:9","quality":"1k","fields":["OpenaiM","1张","16:9","1k"]}'
  UNION ALL SELECT 210, '赛博朋克短发角色', 'image', 'uploads/app_case/aigc_image/default/card-4.png', '赛博朋克短发女性角色，霓虹城市夜景，紫蓝撞色灯光，皮革与金属材质，电影剧照风格，细节锐利', '{"channel":"OpenaiM","quantity":1,"ratio":"3:4","quality":"1k","fields":["OpenaiM","1张","3:4","1k"]}'
  UNION ALL SELECT 200, '月面赛车场景', 'video', 'uploads/app_case/aigc_image/default/card-5.png', '月球表面未来赛车高速穿梭，镜头低机位跟拍，尘埃扬起，远处地球悬挂天际，科幻大片质感', '{"model":"OpenaiM","ratio":"16:9","duration":"5s","quality":"1k","fields":["OpenaiM","16:9","5s","1k"]}'
  UNION ALL SELECT 190, '梦幻云朵萌宠', 'image', 'uploads/app_case/aigc_image/default/card-6.png', '梦幻云朵中的可爱萌宠，柔软蓬松质感，粉蓝配色，童话氛围，治愈系插画质感，光线轻盈', '{"channel":"OpenaiM","quantity":1,"ratio":"1:1","quality":"1k","fields":["OpenaiM","1张","1:1","1k"]}'
  UNION ALL SELECT 180, '复古人像质感', 'image', 'uploads/app_case/aigc_image/default/card-7.png', '复古人像写真，暖棕胶片色调，细腻颗粒感，经典布光，人物神态自然，高级杂志封面风格', '{"channel":"OpenaiM","quantity":1,"ratio":"3:4","quality":"1k","fields":["OpenaiM","1张","3:4","1k"]}'
  UNION ALL SELECT 170, '夜景人物汽车大片', 'video', 'uploads/app_case/aigc_image/default/card-8.png', '都市夜景中人物与跑车同框，镜头缓慢推进，霓虹反射，潮流广告片风格，速度与情绪并存', '{"model":"OpenaiM","ratio":"16:9","duration":"5s","quality":"1k","fields":["OpenaiM","16:9","5s","1k"]}'
  UNION ALL SELECT 160, '未来农场机器人', 'image', 'uploads/app_case/aigc_image/default/card-9.png', '未来智能农场中的机器人作业场景，干净通透的自然光，科技与生态融合，写实风格，构图开阔', '{"channel":"OpenaiM","quantity":1,"ratio":"16:9","quality":"1k","fields":["OpenaiM","1张","16:9","1k"]}'
  UNION ALL SELECT 150, '雪山蒸汽火车', 'video', 'uploads/app_case/aigc_image/default/card-10.png', '雪山之间蒸汽火车穿越而过，镜头横向追随，白雾与雪粒飞散，复古冒险电影质感，场景壮阔', '{"model":"OpenaiM","ratio":"16:9","duration":"5s","quality":"1k","fields":["OpenaiM","16:9","5s","1k"]}'
  UNION ALL SELECT 140, '城市地铁电影帧', 'video', 'uploads/app_case/aigc_image/default/card-11.png', '城市地铁站电影帧，人物独自行走，冷暖对比灯光，镜头缓慢平移，情绪克制，都市叙事感', '{"model":"OpenaiM","ratio":"16:9","duration":"5s","quality":"1k","fields":["OpenaiM","16:9","5s","1k"]}'
  UNION ALL SELECT 130, '彩色手作玩偶', 'image', 'uploads/app_case/aigc_image/default/card-12.png', '彩色手作玩偶陈列，手工布料与针脚细节，活泼高饱和配色，温暖自然光，商业拍摄质感', '{"channel":"OpenaiM","quantity":1,"ratio":"1:1","quality":"1k","fields":["OpenaiM","1张","1:1","1k"]}'
  UNION ALL SELECT 120, '银翼城市漫游', 'image', 'uploads/app_case/aigc_image/default/card-1.png', '银翼风格未来都市漫游，超高楼宇与飞行器穿梭，霓虹雾气，电影级远景，氛围浓烈', '{"channel":"OpenaiM","quantity":1,"ratio":"16:9","quality":"1k","fields":["OpenaiM","1张","16:9","1k"]}'
  UNION ALL SELECT 110, '海边杂志封面', 'image', 'uploads/app_case/aigc_image/default/card-2.png', '海边时尚杂志封面人像，逆光金色日落，轻风发丝，服装高级简洁，大片质感', '{"channel":"OpenaiM","quantity":1,"ratio":"3:4","quality":"1k","fields":["OpenaiM","1张","3:4","1k"]}'
  UNION ALL SELECT 100, '熔岩龙巢穴', 'image', 'uploads/app_case/aigc_image/default/card-3.png', '熔岩洞穴中的巨龙巢穴，炽热火光与岩浆纹理，奇幻电影场景，压迫感十足，细节丰富', '{"channel":"OpenaiM","quantity":1,"ratio":"16:9","quality":"1k","fields":["OpenaiM","1张","16:9","1k"]}'
  UNION ALL SELECT 90, '虚拟偶像舞台', 'video', 'uploads/app_case/aigc_image/default/card-4.png', '虚拟偶像站上全息舞台，镜头绕场缓慢环绕，灯光节奏变化，科技演唱会氛围，绚丽动感', '{"model":"OpenaiM","ratio":"16:9","duration":"5s","quality":"1k","fields":["OpenaiM","16:9","5s","1k"]}'
  UNION ALL SELECT 80, '星际机甲追逐', 'video', 'uploads/app_case/aigc_image/default/card-5.png', '星际机甲在荒漠星球高速追逐，低机位推镜，沙尘翻涌，冲击感强烈，科幻预告片质感', '{"model":"OpenaiM","ratio":"16:9","duration":"5s","quality":"1k","fields":["OpenaiM","16:9","5s","1k"]}'
  UNION ALL SELECT 70, '奶油云朵小屋', 'image', 'uploads/app_case/aigc_image/default/card-6.png', '奶油色云朵上的童话小屋，软萌可爱，暖光包裹，梦境感十足，细节精致', '{"channel":"OpenaiM","quantity":1,"ratio":"1:1","quality":"1k","fields":["OpenaiM","1张","1:1","1k"]}'
  UNION ALL SELECT 60, '老电影肖像集', 'image', 'uploads/app_case/aigc_image/default/card-7.png', '老电影风格室内肖像，柔焦，胶片颗粒，年代色彩，人物情绪安静内敛，复古审美', '{"channel":"OpenaiM","quantity":1,"ratio":"3:4","quality":"1k","fields":["OpenaiM","1张","3:4","1k"]}'
  UNION ALL SELECT 50, '霓虹街头跑车', 'video', 'uploads/app_case/aigc_image/default/card-8.png', '霓虹街头跑车疾驰，镜头快速跟拍切换，雨夜反光地面，酷感广告片质感，速度线明显', '{"model":"OpenaiM","ratio":"16:9","duration":"5s","quality":"1k","fields":["OpenaiM","16:9","5s","1k"]}'
  UNION ALL SELECT 40, '温室机器人采摘', 'image', 'uploads/app_case/aigc_image/default/card-9.png', '未来温室中机器人采摘果实，玻璃穹顶通透，绿色植物茂盛，科技农业写实风格', '{"channel":"OpenaiM","quantity":1,"ratio":"16:9","quality":"1k","fields":["OpenaiM","1张","16:9","1k"]}'
  UNION ALL SELECT 30, '极地列车远征', 'video', 'uploads/app_case/aigc_image/default/card-10.png', '极地列车穿越雪暴，远景转近景，空气中充满寒雾，冒险电影镜头语言，氛围紧张', '{"model":"OpenaiM","ratio":"16:9","duration":"5s","quality":"1k","fields":["OpenaiM","16:9","5s","1k"]}'
  UNION ALL SELECT 20, '城市列车情绪片段', 'video', 'uploads/app_case/aigc_image/default/card-11.png', '城市列车车厢内人物沉思，镜头缓慢推进，窗外光影掠过脸部，情绪短片氛围', '{"model":"OpenaiM","ratio":"16:9","duration":"5s","quality":"1k","fields":["OpenaiM","16:9","5s","1k"]}'
  UNION ALL SELECT 10, '缤纷手作陈列', 'image', 'uploads/app_case/aigc_image/default/card-12.png', '缤纷手作玩偶与摆件陈列台，高饱和产品摄影，柔和自然光，商业电商主图质感', '{"channel":"OpenaiM","quantity":1,"ratio":"1:1","quality":"1k","fields":["OpenaiM","1张","1:1","1k"]}'
) seed
WHERE NOT EXISTS (
  SELECT 1
  FROM `la_app_case` existed
  WHERE existed.`tenant_id` = tenant.`tenant_id`
    AND existed.`app_code` = 'aigc_image'
    AND existed.`title` = seed.`title`
    AND existed.`delete_time` = 0
);
