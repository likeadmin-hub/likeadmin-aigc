<?php

namespace app\common\service;

/**
 * Tenant-owned content for the public PC official site.
 * The schema is deliberately fixed: tenants can operate content without
 * creating arbitrary page structures that the public renderer cannot secure.
 */
class OfficialSiteService
{
    private const TYPE = 'official_site';
    private const KEY = 'config';

    public static function get(): array
    {
        $stored = ConfigService::get(self::TYPE, self::KEY, []);
        $config = self::normalize(is_array($stored) ? $stored : []);
        // Existing tenants receive the default template on first read.
        if (!is_array($stored) || $stored === []) {
            ConfigService::set(self::TYPE, self::KEY, self::toStorage($config));
        }
        return self::toEditor($config);
    }

    public static function save(array $params): array
    {
        $config = self::normalize($params);
        ConfigService::set(self::TYPE, self::KEY, self::toStorage($config));
        self::syncWebsiteBasics($config['basic']);
        return self::get();
    }

    /** Public config deliberately omits back-office and sales-sensitive fields. */
    public static function public(): array
    {
        $config = self::get();
        $config['join_available'] = !empty(\app\common\service\brand\TenantBrandService::packageRows((int)request()->tenantId, true));
        $config['basic']['logo'] = self::fileUrl($config['basic']['logo']);
        $config['basic']['favicon'] = self::fileUrl($config['basic']['favicon']);
        foreach ($config['modules'] as &$module) {
            $module['media'] = self::fileUrl((string)($module['media'] ?? ''));
            $module['poster'] = self::fileUrl((string)($module['poster'] ?? ''));
            foreach ($module['cards'] as &$card) {
                $card['media'] = self::fileUrl((string)($card['media'] ?? ''));
                $card['poster'] = self::fileUrl((string)($card['poster'] ?? ''));
                unset($card['internal_note'], $card['admin_only']);
            }
            unset($card);
        }
        unset($module);
        return $config;
    }

    private static function normalize(array $input): array
    {
        $defaults = self::defaults();
        $enabled = array_key_exists('enabled', $input) ? (int)!empty($input['enabled']) : 1;
        $basic = array_merge($defaults['basic'], is_array($input['basic'] ?? null) ? $input['basic'] : []);
        if ((int)($input['template_version'] ?? 1) < 2) {
            foreach (self::legacyDefaults()['basic'] as $key => $legacyValue) {
                if (isset($defaults['basic'][$key]) && ($basic[$key] ?? null) === $legacyValue) $basic[$key] = $defaults['basic'][$key];
            }
        }
        $basic = array_intersect_key($basic, $defaults['basic']);
        foreach ($basic as $key => &$value) {
            $value = self::text($value, in_array($key, ['description', 'keywords', 'footer_text'], true) ? 255 : ($key === 'title' ? 80 : 60));
        }
        unset($value);
        // Material paths need more room than display text.
        foreach (['logo', 'favicon'] as $key) $basic[$key] = self::text($input['basic'][$key] ?? $defaults['basic'][$key], 1024);
        $basic['accent_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $basic['accent_color']) ? $basic['accent_color'] : '#2563eb';

        $inputModules = is_array($input['modules'] ?? null) ? $input['modules'] : [];
        $moduleMap = [];
        foreach ($inputModules as $module) {
            if (is_array($module) && isset($module['key'])) $moduleMap[(string)$module['key']] = $module;
        }
        $modules = [];
        foreach ($defaults['modules'] as $default) {
            $override = $moduleMap[$default['key']] ?? [];
            // Replace only untouched old template content; keep tenant-authored content.
            if ((int)($input['template_version'] ?? 1) < 2 && self::isLegacyDefault($override)) $override = array_intersect_key($override, ['enabled' => 1, 'sort' => 1]);
            $source = array_merge($default, $override);
            $raw = [
                'key' => $default['key'], 'enabled' => (int)!empty($source['enabled']),
                'sort' => max(0, min(999, (int)$source['sort'])),
                'title' => self::text($source['title'] ?? '', 100),
                'eyebrow' => self::text($source['eyebrow'] ?? '', 60),
                'description' => self::text($source['description'] ?? '', 500),
                'media' => self::text($source['media'] ?? '', 1024),
                'poster' => self::text($source['poster'] ?? '', 1024),
                'media_type' => ($source['media_type'] ?? '') === 'video' ? 'video' : 'image',
                'button_text' => self::text($source['button_text'] ?? '', 40),
                'button_link' => self::safeLink((string)($source['button_link'] ?? '')),
                'cards' => self::normalizeCards($source['cards'] ?? []),
            ];
            $modules[] = $raw;
        }
        usort($modules, static fn(array $a, array $b) => $b['sort'] <=> $a['sort']);

        return ['template_version' => 2, 'enabled' => $enabled, 'basic' => $basic, 'modules' => $modules];
    }

    private static function normalizeCards($cards): array
    {
        if (!is_array($cards)) return [];
        $result = [];
        foreach (array_slice($cards, 0, 24) as $index => $card) {
            if (!is_array($card)) continue;
            $status = (string)($card['status'] ?? 'live');
            if (!in_array($status, ['live', 'planned', 'enterprise'], true)) $status = 'planned';
            $result[] = [
                'title' => self::text($card['title'] ?? '', 80),
                'description' => self::text($card['description'] ?? '', 300),
                'media' => self::text($card['media'] ?? '', 1024),
                'poster' => self::text($card['poster'] ?? '', 1024),
                'media_type' => ($card['media_type'] ?? '') === 'video' ? 'video' : 'image',
                'kind' => in_array($card['kind'] ?? '', ['human', 'drama', 'canvas', 'custom'], true) ? $card['kind'] : 'custom',
                'eyebrow' => self::text($card['eyebrow'] ?? '', 60),
                'button_text' => self::text($card['button_text'] ?? '开始创作', 40),
                'status' => $status,
                'link' => $status === 'live' ? self::safeLink((string)($card['link'] ?? '/ai')) : '',
                'sort' => max(0, min(999, (int)($card['sort'] ?? (100 - $index)))),
            ];
        }
        usort($result, static fn(array $a, array $b) => $b['sort'] <=> $a['sort']);
        return $result;
    }

    private static function toStorage(array $config): array
    {
        foreach ($config['modules'] as &$module) {
            $module['media'] = FileService::setFileUrl($module['media']);
            $module['poster'] = FileService::setFileUrl($module['poster']);
            foreach ($module['cards'] as &$card) {
                $card['media'] = FileService::setFileUrl($card['media']);
                $card['poster'] = FileService::setFileUrl($card['poster']);
            }
            unset($card);
        }
        unset($module);
        $config['basic']['logo'] = FileService::setFileUrl($config['basic']['logo']);
        $config['basic']['favicon'] = FileService::setFileUrl($config['basic']['favicon']);
        return $config;
    }

    /** Convert stored media paths to URLs expected by the admin material picker. */
    private static function toEditor(array $config): array
    {
        $config['basic']['logo'] = self::fileUrl($config['basic']['logo']);
        $config['basic']['favicon'] = self::fileUrl($config['basic']['favicon']);
        foreach ($config['modules'] as &$module) {
            $module['media'] = self::fileUrl((string)($module['media'] ?? ''));
            $module['poster'] = self::fileUrl((string)($module['poster'] ?? ''));
            foreach ($module['cards'] as &$card) {
                $card['media'] = self::fileUrl((string)($card['media'] ?? ''));
                $card['poster'] = self::fileUrl((string)($card['poster'] ?? ''));
            }
            unset($card);
        }
        unset($module);
        return $config;
    }

    private static function syncWebsiteBasics(array $basic): void
    {
        ConfigService::set('website', 'pc_logo', FileService::setFileUrl($basic['logo']));
        ConfigService::set('website', 'pc_title', $basic['title']);
        ConfigService::set('website', 'pc_ico', FileService::setFileUrl($basic['favicon']));
        ConfigService::set('website', 'pc_desc', $basic['description']);
        ConfigService::set('website', 'pc_keywords', $basic['keywords']);
    }

    private static function fileUrl(string $value): string
    {
        return $value === '' ? '' : FileService::getFileUrl($value);
    }

    private static function safeLink(string $link): string
    {
        return preg_match('~^/(?!/)[A-Za-z0-9_/?=&.#%-]*$~', $link) ? $link : '';
    }

    private static function text($value, int $length): string
    {
        return mb_substr(trim((string)$value), 0, $length);
    }

    private static function isLegacyDefault(array $module): bool
    {
        foreach (self::legacyDefaults()['modules'] as $legacy) {
            if (($module['key'] ?? '') !== $legacy['key']) continue;
            foreach (['title', 'description', 'button_text', 'button_link'] as $key) {
                if (($module[$key] ?? '') !== $legacy[$key]) return false;
            }
            if (!empty($module['media']) || !empty($module['poster'])) return false;
            $cards = $module['cards'] ?? [];
            if (!is_array($cards)) return false;
            if (count($cards) !== count($legacy['cards'])) return false;
            foreach ($cards as $index => $card) {
                foreach (['title', 'description', 'status', 'link'] as $key) {
                    if (($card[$key] ?? '') !== ($legacy['cards'][$index][$key] ?? '')) return false;
                }
                if (!empty($card['media']) || !empty($card['poster'])) return false;
            }
            return true;
        }
        return false;
    }

    private static function defaults(): array
    {
        $module = static fn($key, $sort, $eyebrow, $title, $description, $cards = [], $button = '', $link = '') => [
            'key' => $key, 'enabled' => 1, 'sort' => $sort, 'eyebrow' => $eyebrow, 'title' => $title,
            'description' => $description, 'media' => '', 'poster' => '', 'media_type' => 'image',
            'button_text' => $button, 'button_link' => $link, 'cards' => $cards,
        ];
        $card = static fn($title, $description, $link = '', $kind = 'custom', $eyebrow = '') => [
            'title' => $title, 'description' => $description, 'link' => $link, 'kind' => $kind, 'eyebrow' => $eyebrow,
            'status' => 'live', 'button_text' => '开始创作', 'media' => '',
        ];
        $cases = $module('cases', 45, 'CREATIVE NOTES', '每一次创作，都有新的可能', '分享你的创作故事，让灵感被更多人看见。');
        $cases['enabled'] = 0;
        return [
            'basic' => [
                'name' => 'OPC AI', 'logo' => '', 'favicon' => '', 'title' => 'OPC AI · 让想象成为作品',
                'description' => '数字人、AI 短剧、无限画布。把灵感变成画面，把故事带到眼前。',
                'keywords' => 'AI,数字人,AI短剧,无限画布,AI视频,AI创作',
                'accent_color' => '#2563eb', 'workbench_text' => '进入工作台', 'join_text' => '成为合作伙伴',
                'demo_text' => '探索创作可能', 'nav_products' => '产品能力', 'nav_scenes' => '应用场景',
                'nav_faq' => '常见问题', 'nav_pricing' => '价格方案',
                'footer_text' => '从一个灵感，到下一部作品。', 'record_number' => '',
            ],
            'modules' => [
                $module('hero', 100, 'A NEW SPACE FOR YOUR IMAGINATION', "让想象，\n成为作品。", '数字人、AI 短剧、无限画布。连接你的灵感与 AI，让每一个创作想法，都有继续向前的可能。', [], '开始创作', '/ai'),
                $module('products', 90, 'THREE WAYS TO CREATE', '从一个想法，走向无限可能', '不必在工具之间来回切换。在同一个创作空间，让人物开口、让故事展开、让灵感自由连接。', [
                    $card('数字人，让表达更有温度', '选择数字人形象与声音，输入口播脚本，合成数字人视频。也可以从一张图片和参考音频开始，创作全驱动数字人。', '/ai/avatar', 'human', 'DIGITAL HUMAN'),
                    $card('AI 短剧，让故事走进画面', '从故事设定与大纲，到分集剧本、角色和分镜。逐步推进创作，用跨集连续性检查衔接你的故事，再进入单集制作。', '/ai/short-drama', 'drama', 'AI SHORT DRAMA'),
                    $card('无限画布，让灵感自然连接', '把文字、图像、视频与创作节点放在同一张画布。连接素材与生成步骤，以可视化方式编排你的创作流程。', '/app/aigc_canvas', 'canvas', 'INFINITE CANVAS'),
                ]),
                $module('workflow', 80, 'MAKE YOUR NEXT IDEA HAPPEN', '创作的每一步，都更顺手', '从灵感到素材，从素材到作品。按自己的节奏，组织一条可继续迭代的创作路径。', [
                    $card('写下一个想法', '从脚本、故事设定或参考素材开始，确定你想表达的内容。'),
                    $card('选择创作方式', '用数字人传递信息，用短剧展开故事，用画布连接多种能力。'),
                    $card('生成，再打磨', '在工作台查看任务与结果，调整内容，让作品更接近你的想象。'),
                ]),
                $module('scenes', 70, 'BUILT AROUND YOUR IDEAS', '灵感不同，创作同样自由', '为讲述、为展示、为下一次令人眼前一亮的表达。', [
                    $card('让品牌，有自己的表达', '用数字人口播介绍产品、传递品牌信息，形成可反复使用的表达素材。', '/ai/avatar', 'human', 'BRAND & MARKETING'),
                    $card('把故事，拍成系列', '梳理世界观、角色与分集内容，逐步制作属于你的 AI 短剧。', '/ai/short-drama', 'drama', 'STORY & ENTERTAINMENT'),
                    $card('让创意，在画布上生长', '连接参考、提示词和生成结果，探索画面与视频的不同方向。', '/app/aigc_canvas', 'canvas', 'DESIGN & EXPLORATION'),
                ]),
                $module('faq', 60, 'A LITTLE MORE TO KNOW', '关于创作，你可能想知道', '', [
                    $card('第一次使用，从哪里开始？', '进入工作台，选择数字人、AI 短剧或无限画布。登录后按照工具内的提示准备脚本或素材，即可开始创作。'),
                    $card('数字人和全驱数字人有什么区别？', '数字人视频支持选择形象、声音并合成口播；全驱数字人可使用图片与参考音频生成动态人物视频。具体可用模型以工作台为准。'),
                    $card('短剧创作支持哪些步骤？', '支持故事设定、大纲确认、分集剧本、连续性检查以及独立的单集制作。可以按阶段推进，无需一次完成所有内容。'),
                    $card('如何计费和查看生成结果？', '可用模型、消耗规则及套餐以当前租户工作台显示为准。生成后可在对应工具的任务记录中查看进度与结果。'),
                ]),
                $cases,
                $module('cta', 40, 'YOUR NEXT CREATION STARTS HERE', '下一部作品，从这里开始。', '把想法带来，把作品带走。', [], '开始创作', '/ai'),
                $module('pricing', 30, 'PLANS', '选择适合你的创作方案', '可用套餐和额度以实际展示为准。', [], '查看价格方案', '/pricing'),
                $module('join', 20, 'CREATE TOGETHER', '用你的品牌，开启 AI 创作平台', '通过平台提供的套餐，开通独立租户与自己的品牌工作台。', [], '成为合作伙伴', '/join-opc'),
            ],
        ];
    }

    private static function legacyDefaults(): array
    {
        return [
            'basic' => [
                'name' => 'OPC AI', 'logo' => '', 'favicon' => '', 'title' => 'OPC AI - 企业级智能创作平台',
                'description' => '面向创作团队和企业的多模态 AI 工作平台', 'keywords' => 'AI,智能体,工作流,企业知识库',
                'workbench_text' => '进入工作台', 'join_text' => '加盟 OPC 平台',
            ],
            'modules' => [
                ['key' => 'hero', 'enabled' => 1, 'sort' => 100, 'title' => '让 AI 成为增长团队的一部分', 'description' => '从内容创作、数字员工到企业智能化，以统一工作台连接模型、工具、知识和自动化流程。', 'media' => '', 'button_text' => '开始创作', 'button_link' => '/ai', 'cards' => []],
                ['key' => 'products', 'enabled' => 1, 'sort' => 90, 'title' => '产品能力', 'description' => '以可控、可运营的方式接入先进 AI 能力。', 'media' => '', 'button_text' => '查看全部能力', 'button_link' => '/products', 'cards' => [
                    ['title' => '多模态创作', 'description' => '图像、视频、文案、数字人和商品视觉一站完成。', 'status' => 'live', 'link' => '/ai', 'sort' => 100],
                    ['title' => 'AI Agent', 'description' => '面向销售、运营、客服与研发的任务型数字员工。', 'status' => 'planned', 'link' => '', 'sort' => 90],
                    ['title' => '企业知识智能', 'description' => '文档、网页和业务资料形成可信问答与协作能力。', 'status' => 'enterprise', 'link' => '', 'sort' => 80],
                    ['title' => '自动化工作流', 'description' => '通过模型、应用和业务系统编排端到端流程。', 'status' => 'planned', 'link' => '', 'sort' => 70],
                ]],
                ['key' => 'scenes', 'enabled' => 1, 'sort' => 80, 'title' => '应用场景', 'description' => '从个人生产力到企业智能化，按业务目标组合能力。', 'media' => '', 'button_text' => '探索应用场景', 'button_link' => '/scenes', 'cards' => [
                    ['title' => '电商增长', 'description' => '商品图、详情页、短视频与多渠道运营自动化。', 'status' => 'live', 'link' => '/ai/tools', 'sort' => 100],
                    ['title' => '品牌内容工厂', 'description' => '将选题、脚本、素材、审核与分发串为内容流水线。', 'status' => 'planned', 'link' => '', 'sort' => 90],
                    ['title' => '智能客服与销售', 'description' => '基于企业知识和客户上下文提供可追溯服务。', 'status' => 'enterprise', 'link' => '', 'sort' => 80],
                ]],
                ['key' => 'cases', 'enabled' => 1, 'sort' => 70, 'title' => '精选案例', 'description' => '用可复用的方法让 AI 走进每一个业务环节。', 'media' => '', 'button_text' => '查看案例', 'button_link' => '/cases', 'cards' => [
                    ['title' => '全域商品内容', 'description' => '围绕商品建模、视觉生成和素材复用提升上新效率。', 'status' => 'live', 'link' => '/ai', 'sort' => 100],
                    ['title' => '企业知识助理', 'description' => '把散落资料转成权限可控、持续更新的团队助手。', 'status' => 'enterprise', 'link' => '', 'sort' => 90],
                ]],
                ['key' => 'pricing', 'enabled' => 1, 'sort' => 60, 'title' => '灵活的服务方案', 'description' => '按团队规模、应用权限和企业治理需要选择服务。', 'media' => '', 'button_text' => '查看价格方案', 'button_link' => '/pricing', 'cards' => []],
                ['key' => 'join', 'enabled' => 1, 'sort' => 50, 'title' => '加盟 OPC 平台', 'description' => '以自己的品牌销售 AI 应用，自助购买套餐并快速开通独立租户。', 'media' => '', 'button_text' => '立即加盟', 'button_link' => '/join-opc', 'cards' => []],
            ],
        ];
    }
}
