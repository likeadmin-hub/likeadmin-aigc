<?php
namespace app\common\service;

/** Public presentation defaults; reference testimonials retain their OpenArt attribution. */
class OfficialSiteTemplate
{
    public static function defaults(array $previous): array
    {
        $card = static fn($title, $description, $link = '', $icon = 'grid') => [
            'title' => $title, 'tab_label' => $title, 'description' => $description, 'link' => $link, 'icon' => $icon,
            'kind' => 'custom', 'status' => 'live', 'button_text' => '开始创作', 'media' => '',
        ];
        $block = static fn($key, $sort, $title, $description = '', $cards = [], $button = '', $link = '') => [
            'key' => $key, 'enabled' => 1, 'sort' => $sort, 'title' => $title, 'description' => $description,
            'cards' => $cards, 'button_text' => $button, 'button_link' => $link, 'autoplay_seconds' => 6,
        ];
        $features = [
            $card('AI 短剧', '从故事构思、人物设定到分镜制作，把灵感串联成完整的视觉故事。', '/ai/short-drama', 'drama'),
            $card('AI 视频', '从文字或参考画面出发，选择视频模型，让静止的想法动起来。', '/ai/create?type=video', 'video'),
            $card('AI 绘图', '描述你想象中的画面，结合参考图片与不同模型，探索新的视觉表达。', '/ai/create?type=image', 'image'),
            $card('数字人', '让人物出镜表达。结合形象、声音与口播文案，制作数字人视频。', '/ai/avatar', 'avatar'),
            $card('无限画布', '把图像、视频与创作节点连接在一张画布上，让灵感自由延伸。', '/app/aigc_canvas', 'canvas'),
            $card('AI 音乐', '从歌词、提示词或参考音频开始，为你的作品探索适合的音乐。', '/ai/tools/aigc_music', 'audio'),
        ];
        $scenes = [
            $card('短剧故事', '让角色走进故事，让分镜连接情节。', '/ai/short-drama', 'drama'),
            $card('数字人口播', '将文案和声音变成有形象的表达。', '/ai/avatar?tab=lip_sync', 'avatar'),
            $card('商品展示', '为产品生成不同场景的视觉素材。', '/ai/tools/aigc_product_image', 'image'),
            $card('创意视频', '用文字与参考图探索动态画面。', '/ai/create?type=video', 'video'),
            $card('社交内容', '结合脚本与素材组织视频表达。', '/ai/smart_clip', 'edit'),
            $card('品牌视觉', '从灵感发散到图像生成，在画布上持续创作。', '/app/aigc_canvas', 'canvas'),
            $card('音乐创作', '让画面拥有自己的声音。', '/ai/tools/aigc_music', 'audio'),
            $card('时尚搭配', '结合人物与服装，预览不同穿搭效果。', '/ai/tools/aigc_fitting', 'image'),
        ];
        $tools = [
            $card('AI 商品图', '上传商品、选择场景，探索更适合产品的展示方式。', '/ai/tools/aigc_product_image', 'image'),
            $card('AI 试衣', '结合人物与服装素材，预览搭配效果。', '/ai/tools/aigc_fitting', 'avatar'),
            $card('老照片修复', '修复、上色，让旧照片中的记忆重新清晰起来。', '/ai/tools/aigc_photo_restore', 'edit'),
            $card('智能视频剪辑', '组织口播、混剪与新闻体内容，继续打磨作品。', '/ai/smart_clip', 'video'),
            $card('AI 对话', '整理灵感、推敲文案，持续完善你的创作思路。', '/app/aigc_llm', 'book'),
            $card('全驱动数字人', '从人物形象与驱动素材出发，探索数字人表达。', '/ai/avatar?tab=image_human', 'avatar'),
        ];
        $basic = array_merge($previous['basic'], [
            'theme' => 'light', 'motion_enabled' => 1, 'placeholder' => '', 'start_link' => '/ai',
            'start_text' => '开始创作', 'footer_heading' => '让想象成为作品。', 'footer_wordmark' => '',
            'models_empty_text' => '更多创作模型即将上线', 'nav_close_text' => '关闭菜单',
        ]);
        $modules = [
            $block('promo', 999, '从一个灵感，开始你的下一部作品', '', [], '探索工具', '/ai/tools'),
            $block('hero', 990, "让每一个想法\n成为视觉故事", '数字人、AI 短剧、无限画布。图像、视频与声音，在一个平台自由创作。', array_merge($features, [$tools[0], $tools[1]]), '开始创作', '/ai'),
            $block('partners', 980, '一站连接多种创作能力', '', array_map(static fn($c) => $card($c['title'], '', $c['link'], $c['icon']), $features)),
            $block('showcase', 970, '你的创作，现在开场', '', $scenes, '开始创作', '/ai'),
            $block('scenes', 960, "想象中的画面\n都可以从这里开始", '', $scenes, '探索创作场景', '/ai'),
            $block('products', 950, "一个平台，无限故事", '从构思到表达，让每一个创作环节自然衔接。', $features),
            $block('models', 940, "多种 AI 模型\n一个创作平台", '连接算力市场已上架的生图与生视频模型，选择适合你的创作能力。', [], '探索模型', '/ai'),
            $block('tools', 930, "让创作更进一步的 AI 工具", '从商品视觉到视频剪辑，在细节中完善你的作品。', $tools),
            self::testimonials(),
            $block('faq', 910, '你可能想了解', '', [
                $card('我可以使用哪些创作工具？', '平台提供 AI 短剧、数字人、无限画布，以及图像、视频、音乐等创作工具。实际可用功能以工作台展示为准。'),
                $card('如何开始制作 AI 短剧？', '进入 AI 短剧工作室，从故事与角色设定开始，再逐步完成分镜和画面制作。'),
                $card('无限画布适合什么工作？', '适合需要组织多个素材和创作步骤的工作。你可以在画布上组合节点、调整连接并迭代内容。'),
                $card('可以使用自己的图片和视频吗？', '支持上传素材的工具可以使用你的参考图片、视频或音频，请以具体工具的输入要求为准。'),
                $card('有哪些图像和视频模型？', '官网模型区展示当前租户算力市场中可用的生图与生视频模型，具体规格与消耗以创作页面为准。'),
                $card('创作需要多少积分？', '不同模型、规格与工具的消耗可能不同，请在提交任务前查看页面显示的费用。'),
                $card('数字人可以制作什么内容？', '可以结合人物形象、声音与文案制作口播内容，也可以使用全驱动数字人能力。'),
                $card('如何选择适合自己的方案？', '查看价格页面的会员或充值方案，并结合你的创作频率和实际需求选择。'),
            ]),
            $block('news', 900, '创作指南与灵感', '', [
                $card('探索你的创作工作台', '从图像、视频到数字人，找到适合的工具，开始下一次创作。', '/ai', 'grid'),
                $card('从灵感到故事', '了解短剧、画布与数字人的创作方式。', '/official/help', 'book'),
            ]),
            $block('cta', 890, '把想象，变成下一件作品', '你的故事，值得被看见。', [], '开始创作', '/ai'),
            $block('open', 0, '开放平台', '将 AI 创作能力连接到你的业务。开放方式与接入说明由平台运营方配置。', [], '返回工作台', '/ai'),
            $block('enterprise', 0, '企业服务', '面向团队的创作需求、服务方案与联系方式，可由租户在后台配置。', [], '了解价格方案', '/pricing'),
            $block('help', 0, '创作帮助', '选择一个工具开始创作，或查看下方常见问题。', $features, '开始创作', '/ai'),
            $block('footer', 0, '开始创作', '', $features),
        ];
        foreach ($previous['modules'] as $m) if (in_array($m['key'], ['pricing', 'join'], true)) $modules[] = $m;
        return ['basic' => $basic, 'modules' => array_merge($modules, OfficialSiteOemTemplate::modules())];
    }

    /** Reference copy supplied by the owner; editable independently for each tenant. */
    public static function testimonials(): array
    {
        $reviews = [
            ['Scorpy', '实用的 AI 工具一站集齐——图像与视频生成、编辑等等……无需多份订阅。'],
            ['Richard M.', '客服总是快速又贴心……平台也不断加入新功能和工具，越来越好用。'],
            ['Jan C.', '我试过很多 AI 平台……但在角色创建、图生视频质量和模型多样性方面，OpenArt 依然是最出色的。'],
            ['Mark', '我用 OpenArt 制作了我的第一支 AI 音乐视频……他们的支持团队是我遇到过最棒的帮助。'],
            ['Rasmus', '我对支持服务非常满意——我的问题很快得到解决，团队让整个过程变得轻松。'],
            ['Jon S.', '在众多 AI 平台中，OpenArt 脱颖而出，是绝佳之选——我会向任何创作者推荐它。'],
        ];
        return [
            'key' => 'cases', 'enabled' => 1, 'sort' => 920,
            'title' => "深受喜爱 创作者\n全球", 'highlight_text' => '创作者',
            'description' => '独立创作者和全球品牌团队每天都在 OpenArt 上产出作品。',
            'autoplay_seconds' => 35,
            'cards' => array_map(static fn($review) => [
                'title' => $review[0], 'description' => $review[1],
                'source' => 'Trustpilot', 'rating' => 5, 'avatar' => '',
            ], $reviews),
        ];
    }

    public static function navigation(): array
    {
        $descriptions = ['AI 短剧'=>'从故事与角色到分镜制作','无限画布'=>'连接素材、节点与创作流程','数字人'=>'让人物形象出镜表达','智能视频剪辑'=>'组织口播与视频素材','AI 音乐'=>'探索歌词、旋律与声音','AI 绘图'=>'从文字或参考图片生成画面','AI 商品图'=>'为商品探索新的展示场景','AI 试衣'=>'预览人物与服装搭配','老照片修复'=>'修复、上色与还原细节','AI 视频'=>'将想法变成动态画面','对口型数字人'=>'结合脚本与声音制作口播','全驱动数字人'=>'探索形象驱动与动态表达'];
        $item = static fn($label, $icon, $link) => ['label' => $label, 'description' => $descriptions[$label] ?? '', 'icon' => $icon, 'status' => 'live', 'link' => $link];
        $group = static fn($title, $items) => ['title' => $title, 'items' => $items];
        $entry = static fn($key, $label, $sort, $link, $groups = []) => [
            'key' => $key, 'label' => $label, 'enabled' => 1, 'sort' => $sort,
            'mode' => $groups || $key === 'models' ? 'dropdown' : 'link',
            'title' => $label, 'button_text' => '开始创作', 'button_link' => '/ai',
            'link' => $link, 'status' => 'live', 'groups' => $groups,
        ];
        return [
            $entry('tools', '应用工具', 100, '/ai/tools', [
                $group('工作室', [$item('AI 短剧', 'drama', '/ai/short-drama'), $item('无限画布', 'canvas', '/app/aigc_canvas'), $item('数字人', 'avatar', '/ai/avatar'), $item('智能视频剪辑', 'edit', '/ai/smart_clip'), $item('AI 音乐', 'audio', '/ai/tools/aigc_music')]),
                $group('图片', [$item('AI 绘图', 'image', '/ai/create?type=image'), $item('AI 商品图', 'image', '/ai/tools/aigc_product_image'), $item('AI 试衣', 'avatar', '/ai/tools/aigc_fitting'), $item('老照片修复', 'edit', '/ai/tools/aigc_photo_restore')]),
                $group('视频', [$item('AI 视频', 'video', '/ai/create?type=video'), $item('对口型数字人', 'avatar', '/ai/avatar?tab=lip_sync'), $item('全驱动数字人', 'avatar', '/ai/avatar?tab=image_human')]),
            ]),
            $entry('models', '模型', 90, '/ai'),
            array_merge($entry('open', 'API', 80, '', [
                $group('API', [
                    array_merge($item('平台首页', 'grid', ''), ['status' => 'planned']),
                    array_merge($item('文档中心', 'book', ''), ['status' => 'planned']),
                ]),
            ]), ['button_text' => '', 'button_link' => '']),
            $entry('pricing', '价格', 70, '/pricing'),
            $entry('enterprise', '企业服务', 60, '/official/enterprise'),
            $entry('oem', 'OEM贴牌', 55, '/official/oem'),
            $entry('help', '帮助', 50, '/official/help'),
        ];
    }
}
