<?php
namespace app\common\service;

/** OEM presentation defaults, based on the owner's selected Higgsfield reference sections. */
class OfficialSiteOemTemplate
{
    public static function modules(): array
    {
        $asset = static fn($name) => '/pc/oem-enterprise/' . $name;
        $block = static fn($key, $title, $description = '') => ['key'=>$key, 'enabled'=>1, 'sort'=>0, 'title'=>$title, 'description'=>$description, 'cards'=>[]];
        $clients = [
            [2, '制作广泛传播的混合现实营销作品', 'ESPN · Vertex CGI'],
            [5, '麦当娜和杜嘉班纳的创意总监', 'Dolce & Gabbana · Sasha Kasiuha'],
            [1, '为 Netflix 和 Hulu 创作的制作工作室', 'Boxel Studio'],
            [6, '为 Lacoste 和 SuperStep 创作的制作工作室', 'Lacoste · Sinecera'],
            [3, '专注于体育和娱乐的 AI 工作室', 'One of One'],
            [4, '韩国领先的创意工作室', 'AdQUA'],
        ];
        $features = [
            [1, '多模型创作', '多模型生图与视频创作', '通过系统已配置的生图与视频通道，使用文字或参考素材发起创作，查看生成结果。', 'image'],
            [2, 'AI 商品图', '为商品制作场景展示图', '上传商品图片，选择场景分类与模板，生成适合电商展示的商品图。', 'image'],
            [3, 'AI 短剧', '从故事设定到分集制作', '整理故事设定与大纲，生成分段剧本，管理人物和分镜，推进分集制作。', 'image'],
            [4, '数字人', '让数字人表达你的内容', '结合人物形象、音频和口播内容，使用对口型或全驱动数字人工具制作视频。', 'image'],
            [5, '画布智能助手', '在画布中协作完成创作', '通过无限画布内的 Agent 与 Skills，辅助文本、图像和视频创作，连接不同创作节点。', 'image'],
            [6, '无限画布', '用节点连接素材与创作流程', '在无限画布中组织素材、对话、生图与视频节点，逐步搭建自己的创作流程。', 'image'],
            [7, '短剧角色', '管理短剧人物与参考形象', '在短剧项目中管理角色设定与参考形象，为分镜和后续制作提供人物素材。', 'image'],
            [8, 'AI 视频剪辑', '组织素材并生成剪辑视频', '使用真人口播混剪、素材混剪和新闻体视频剪辑工具，完成不同场景的视频制作。', 'image'],
            [9, 'AI 音乐', '从歌词与灵感开始音乐创作', '生成歌词与音乐，使用参考音频探索不同声音效果，并导出创作结果。', 'image'],
        ];
        return [
            array_merge($block('oem_hero', "专为企业打造的\nAI原生创意套件", ''), [
                'eyebrow'=>'OEM贴牌', 'media'=>'', 'media_type'=>'video', 'poster'=>'',
                'icon_url'=>'', 'badge'=>$asset('fortune-logo.png'),
            ]),
            array_merge($block('oem_intro', '专为现代创意企业量身打造', '获取平台个性化演示，查看真实用例，了解领先企业如何将制作时间缩短 90%，同时在每份内容上节省数千美元。'), ['button_text'=>'联系销售', 'button_link'=>'/official/oem#oem-packages']),
            array_merge($block('oem_benefits', "用你的品牌，\n开启 AI 创作生意。", '把 AI 创作能力带给你的客户。从品牌展示到客户管理，拥有自己的站点与后台，让创作者、工作室和服务团队专注经营。'), [
                'eyebrow'=>'你的品牌，你的 AI 业务',
                'footnote'=>'应用权益与服务周期以所选套餐为准，AI 创作按实际计费规则消耗资源。',
                'cards'=>[
                    ['title'=>'让客户记住你的品牌', 'description'=>'配置品牌名称、标识和官网内容，打造自己的创作入口。开通即有专属访问地址，也支持后续绑定自定义域名。', 'icon'=>'edit', 'tab_label'=>'专属品牌'],
                    ['title'=>'把创作能力变成服务', 'description'=>'按所选套餐获得对应应用权益，将 AI 创作能力融入你的内容服务与客户业务，省去从零搭建的过程。', 'icon'=>'grid', 'tab_label'=>'应用权益'],
                    ['title'=>'用自己的后台经营', 'description'=>'获得独立站点与管理员账号，在自己的后台管理用户、内容和运营配置，让品牌与客户服务形成完整体验。', 'icon'=>'manage', 'tab_label'=>'独立经营'],
                    ['title'=>'按业务节奏选择与续期', 'description'=>'选择适合当前阶段的套餐与服务周期。业务持续开展时，可为已有站点续期，继续经营熟悉的品牌入口。', 'icon'=>'renew', 'tab_label'=>'灵活续期'],
                ],
            ]),
            array_merge($block('oem_clients', '已有行业领先企业加入'), ['eyebrow'=>'企业客户', 'cards'=>array_map(static fn($row) => [
                'title'=>$row[1], 'description'=>$row[2], 'media'=>'', 'media_type'=>'image',
                'icon_url'=>$asset('logo-primary-'.$row[0].'.webp'),
                'secondary_icon_url'=>$row[0]===1?'':$asset('logo-secondary-'.$row[0].'.webp'),
            ], $clients)]),
            array_merge($block('oem_features', '创意能力'), ['cards'=>array_map(static function($row) use ($asset) {
                return ['tab_label'=>$row[1], 'title'=>$row[2], 'description'=>$row[3], 'preview_layout'=>$row[4],
                    'media'=>'', 'media_type'=>'image',
                    'background_media'=>'', 'background_media_type'=>'image',
                    'preview_media'=>'', 'preview_poster'=>'', 'preview_media_type'=>'video',
                    'icon_url'=>'',
                ];
            }, $features)]),
            array_merge($block('oem_packages', '选择适合您的贴牌套餐', '以您的品牌开启 AI 创作业务。'), ['eyebrow'=>'OEM PACKAGES', 'button_text'=>'立即开通']),
        ];
    }
}
