-- Seed the five existing homepage cards as editable, tenant-scoped templates.
-- The migration never overwrites a template that an administrator has already created.
INSERT INTO `la_aigc_short_drama_template`
(`tenant_id`,`title`,`description`,`category`,`cover_asset_id`,`cover_url`,`cover_type`,`workflow_nodes_json`,`workflow_edges_json`,`input_slots_json`,`show_on_home`,`sort`,`status`,`create_time`,`update_time`,`delete_time`)
SELECT tenants.`tenant_id`, seed.`title`, seed.`description`, seed.`category`, 0, seed.`cover_url`, 'image',
       seed.`workflow_nodes_json`, seed.`workflow_edges_json`, seed.`input_slots_json`, 1, seed.`sort`, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0
FROM (
  SELECT DISTINCT `tenant_id`
  FROM `la_tenant_app`
  WHERE `app_code` = 'aigc_short_drama'
) AS tenants
JOIN (
  SELECT '故事脚本' AS `title`, '从一句故事灵感整理出短剧主线、人物关系与分集脚本。' AS `description`, '短剧创作' AS `category`, '/imagine-home/character.jpeg' AS `cover_url`, 500 AS `sort`,
    '[{"key":"inspiration","title":"故事灵感","type":"input","model":"","description":"输入故事主题、人物或冲突"},{"key":"script","title":"剧本大纲","type":"text","model":"","description":"整理故事主线与分集结构"},{"key":"episodes","title":"分集脚本","type":"output","model":"","description":"输出可继续创作的分集内容"}]' AS `workflow_nodes_json`,
    '[{"from":"inspiration","to":"script"},{"from":"script","to":"episodes"}]' AS `workflow_edges_json`,
    '[{"key":"story_idea","label":"故事灵感","type":"text","required":1,"node_key":"inspiration","description":"一句话故事、人物关系或核心冲突"}]' AS `input_slots_json`
  UNION ALL SELECT '角色设定', '根据角色描述或参考图整理人物外观、身份、性格和视觉设定。', '角色创作', '/imagine-home/fashion.jpeg', 400,
    '[{"key":"character_brief","title":"角色描述","type":"input","model":"","description":"输入人物身份、性格和时代背景"},{"key":"character_design","title":"角色设定","type":"text","model":"","description":"整理角色档案与造型方向"},{"key":"character_sheet","title":"角色视觉稿","type":"output","model":"","description":"输出角色形象参考"}]',
    '[{"from":"character_brief","to":"character_design"},{"from":"character_design","to":"character_sheet"}]',
    '[{"key":"character_reference","label":"角色主图","type":"image","required":0,"node_key":"character_brief","description":"可替换为角色参考图或演员形象"},{"key":"character_text","label":"角色描述","type":"text","required":1,"node_key":"character_brief","description":"身份、年龄、性格和造型要求"}]'
  UNION ALL SELECT '镜头分镜', '把剧本场景拆解为镜头节奏、景别、动作和画面提示。', '分镜创作', '/imagine-home/snooker.jpeg', 300,
    '[{"key":"scene_script","title":"场景剧本","type":"input","model":"","description":"输入当前场景、台词和动作"},{"key":"shot_list","title":"镜头清单","type":"text","model":"","description":"拆解镜头顺序与画面提示"},{"key":"storyboard","title":"分镜视觉稿","type":"output","model":"","description":"输出镜头分镜参考"}]',
    '[{"from":"scene_script","to":"shot_list"},{"from":"shot_list","to":"storyboard"}]',
    '[{"key":"scene_text","label":"场景剧本","type":"text","required":1,"node_key":"scene_script","description":"场景、人物动作和台词"},{"key":"scene_reference","label":"场景参考图","type":"image","required":0,"node_key":"scene_script","description":"可替换为场景或构图参考"}]'
  UNION ALL SELECT '视觉氛围', '确定画面色彩、光影、镜头情绪和整体视觉基调。', '视觉设计', '/imagine-home/sunglasses.jpeg', 200,
    '[{"key":"mood_brief","title":"氛围需求","type":"input","model":"","description":"输入题材、情绪与画面关键词"},{"key":"visual_direction","title":"视觉方向","type":"text","model":"","description":"整理色彩、光影与构图方向"},{"key":"key_visual","title":"氛围视觉稿","type":"output","model":"","description":"输出关键视觉参考"}]',
    '[{"from":"mood_brief","to":"visual_direction"},{"from":"visual_direction","to":"key_visual"}]',
    '[{"key":"mood_reference","label":"氛围参考图","type":"image","required":0,"node_key":"mood_brief","description":"可替换为色彩、构图或摄影参考"},{"key":"mood_text","label":"视觉关键词","type":"text","required":1,"node_key":"mood_brief","description":"例如复古、悬疑、暖光或赛博"}]'
  UNION ALL SELECT '竖屏预告', '基于已有素材规划竖屏预告的节奏、文案和成片方向。', '视频预告', '/imagine-home/social.jpeg', 100,
    '[{"key":"source_material","title":"预告素材","type":"input","model":"","description":"输入剧情亮点、视频或图片素材"},{"key":"trailer_plan","title":"预告剪辑方案","type":"text","model":"","description":"规划开头钩子、节奏和字幕文案"},{"key":"trailer_video","title":"竖屏预告片","type":"output","model":"","description":"输出竖屏预告结果"}]',
    '[{"from":"source_material","to":"trailer_plan"},{"from":"trailer_plan","to":"trailer_video"}]',
    '[{"key":"trailer_material","label":"预告素材","type":"video","required":0,"node_key":"source_material","description":"可替换为视频片段或关键画面"},{"key":"trailer_copy","label":"预告文案","type":"text","required":1,"node_key":"source_material","description":"钩子台词、卖点或结尾引导"}]'
) AS seed
WHERE NOT EXISTS (
  SELECT 1 FROM `la_aigc_short_drama_template` existing
  WHERE existing.`tenant_id` = tenants.`tenant_id`
    AND existing.`title` = seed.`title`
    AND existing.`delete_time` = 0
);
