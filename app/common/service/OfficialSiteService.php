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
    private const TEMPLATE_VERSION = 6;

    public static function get(): array
    {
        $stored = ConfigService::get(self::TYPE, self::KEY, []);
        $config = self::normalize(is_array($stored) ? $stored : []);
        // Existing tenants receive the default template on first read.
        if (!is_array($stored) || $stored === []) {
            ConfigService::set(self::TYPE, self::KEY, self::toStorage($config));
        } elseif ((int)($stored['template_version'] ?? 1) < self::TEMPLATE_VERSION) {
            // Tenant-scoped, recoverable upgrade; do not make the next release
            // infer template history from an already-upgraded public response.
            if (ConfigService::get(self::TYPE, 'config_before_v6', null) === null) {
                ConfigService::set(self::TYPE, 'config_before_v6', $stored);
            }
            ConfigService::set(self::TYPE, self::KEY, self::toStorage($config));
        }
        $config = self::toEditor($config);
        $config['field_schema'] = OfficialSiteFields::schema();
        $config['model_catalog'] = OfficialSiteModelCatalog::get((int)request()->tenantId);
        return $config;
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
        unset($config['field_schema']);
        $config['join_available'] = !empty(\app\common\service\brand\TenantBrandService::packageRows((int)request()->tenantId, true));
        $config['basic']['logo'] = self::fileUrl($config['basic']['logo']);
        $config['basic']['favicon'] = self::fileUrl($config['basic']['favicon']);
        $config['basic']['placeholder'] = self::fileUrl($config['basic']['placeholder']);
        foreach ($config['modules'] as &$module) {
            foreach (['media', 'poster'] as $field) if (array_key_exists($field, $module)) $module[$field] = self::fileUrl((string)($module[$field] ?? ''));
            foreach ($module['cards'] as &$card) {
                foreach (['media', 'poster', 'icon_url'] as $field) if (array_key_exists($field, $card)) $card[$field] = self::fileUrl((string)($card[$field] ?? ''));
                unset($card['internal_note'], $card['admin_only']);
            }
            unset($card);
            foreach ($module['slides'] as &$slide) {
                foreach (['media', 'poster', 'mobile_media', 'mobile_poster'] as $field) $slide[$field] = self::fileUrl((string)($slide[$field] ?? ''));
            }
            unset($slide);
        }
        unset($module);
        return $config;
    }

    private static function normalize(array $input): array
    {
        $defaults = self::defaults();
        if ((int)($input['template_version'] ?? 1) < 4) {
            $previous = array_column(self::v3Defaults()['modules'], null, 'key');
            $newModules = array_column($defaults['modules'], null, 'key');
            foreach (($input['modules'] ?? []) as $i => $module) {
                $old = $previous[$module['key'] ?? ''] ?? [];
                foreach ($old as $key => $value) {
                    if (($module[$key] ?? null) === $value || ($key === 'cards' && self::normalizeCards($module[$key] ?? []) === self::normalizeCards($value))) unset($input['modules'][$i][$key]);
                }
                $input['modules'][$i]['key'] = $module['key'];
                // New layout uses a new ordering scale. Tenant-authored copy/media remains.
                if (isset($newModules[$module['key']])) $input['modules'][$i]['sort'] = $newModules[$module['key']]['sort'];
                if ($module['key'] === 'products' && !empty($module['cards'])) {
                    $oldCards = array_column($old['cards'] ?? [], null, 'link');
                    $customCards = array_column($module['cards'], null, 'link');
                    $upgraded = [];
                    foreach ($newModules['products']['cards'] as $index => $newCard) {
                        $path = $newCard['link'];
                        $custom = $customCards[$path] ?? [];
                        foreach (($oldCards[$path] ?? []) as $field => $value) {
                            if (($custom[$field] ?? null) === $value) unset($custom[$field]);
                        }
                        $upgraded[] = array_merge($newCard, $custom, ['sort' => 100 - $index]);
                        unset($customCards[$path]);
                    }
                    $input['modules'][$i]['cards'] = array_merge($upgraded, array_values($customCards));
                }
            }
            // The new six-entry information architecture replaces old default groups.
            $oldNavigation = self::legacyNavigationDefaults();
            $oldMap = array_column($oldNavigation, null, 'key');
            foreach (($input['navigation'] ?? []) as $i => $entry) {
                $old = $oldMap[$entry['key'] ?? ''] ?? [];
                foreach ($old as $key => $value) if (($entry[$key] ?? null) === $value) unset($input['navigation'][$i][$key]);
                $input['navigation'][$i]['key'] = $entry['key'];
            }
        }
        if ((int)($input['template_version'] ?? 1) < 5) {
            $input = self::repairTemplateRemnants($input, $defaults);
        }
        $enabled = array_key_exists('enabled', $input) ? (int)!empty($input['enabled']) : 1;
        $basic = array_merge($defaults['basic'], is_array($input['basic'] ?? null) ? $input['basic'] : []);
        if ((int)($input['template_version'] ?? 1) < 2) {
            foreach (self::legacyDefaults()['basic'] as $key => $legacyValue) {
                if (isset($defaults['basic'][$key]) && ($basic[$key] ?? null) === $legacyValue) $basic[$key] = $defaults['basic'][$key];
            }
        }
        $basic = array_intersect_key($basic, $defaults['basic']);
        foreach ($basic as $key => &$value) {
            if (in_array($key, ['particles_enabled', 'motion_enabled'], true)) { $value = (int)!empty($value); continue; }
            $value = self::text($value, in_array($key, ['description', 'keywords', 'footer_text'], true) ? 255 : ($key === 'title' ? 80 : 60));
        }
        unset($value);
        // Material paths need more room than display text.
        foreach (['logo', 'favicon', 'placeholder'] as $key) $basic[$key] = self::text($input['basic'][$key] ?? $defaults['basic'][$key], 1024);
        $basic['theme'] = ($basic['theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
        $basic['start_link'] = self::safeLink((string)($input['basic']['start_link'] ?? '/ai'));
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
            if ((int)($input['template_version'] ?? 1) === 2 && self::isDraftDefault($override)) {
                $draftMap = array_column(self::draftDefaults()['modules'], null, 'key');
                $draft = $draftMap[$default['key']];
                $override = array_intersect_key($override, ['enabled' => 1, 'sort' => 1]);
                foreach (['enabled', 'sort'] as $field) {
                    if (($override[$field] ?? null) === $draft[$field]) unset($override[$field]);
                }
            }
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
                'background_media' => self::text($source['background_media'] ?? '', 1024),
                'background_poster' => self::text($source['background_poster'] ?? '', 1024),
                'background_media_type' => ($source['background_media_type'] ?? '') === 'video' ? 'video' : 'image',
                'button_text' => self::text($source['button_text'] ?? '', 40),
                'button_link' => self::safeLink((string)($source['button_link'] ?? '')),
                'autoplay_seconds' => max(3, min(30, (int)($source['autoplay_seconds'] ?? 6))),
                'cards' => self::normalizeCards($source['cards'] ?? []),
                'slides' => $default['key'] === 'hero' ? self::normalizeSlides($source['slides'] ?? []) : [],
            ];
            $modules[] = $raw;
        }
        usort($modules, static fn(array $a, array $b) => $b['sort'] <=> $a['sort']);

        return OfficialSiteFields::clean(['template_version' => self::TEMPLATE_VERSION, 'enabled' => $enabled, 'basic' => $basic, 'navigation' => self::normalizeNavigation($input['navigation'] ?? null), 'modules' => $modules]);
    }

    /** Repair v1/v2/v3 defaults accidentally retained and stamped as v4. */
    private static function repairTemplateRemnants(array $input, array $defaults): array
    {
        $current = array_column($defaults['modules'], null, 'key');
        $history = array_map(static fn($config) => array_column($config['modules'], null, 'key'), [
            self::legacyDefaults(), self::draftDefaults(), self::v3Defaults(),
        ]);
        foreach (($input['modules'] ?? []) as $index => $module) {
            if (!is_array($module) || !isset($current[$module['key'] ?? ''])) continue;
            $key = $module['key'];
            foreach ($history as $version) {
                $old = $version[$key] ?? [];
                foreach (['title', 'description', 'eyebrow', 'button_text', 'button_link'] as $field) {
                    // Explicit blanks and tenant-authored values remain overrides.
                    if (isset($module[$field], $old[$field]) && (string)$old[$field] !== ''
                        && trim((string)$module[$field]) === trim((string)$old[$field])) {
                        $module[$field] = $current[$key][$field] ?? '';
                    }
                }
                if (isset($module['cards'], $old['cards']) && $old['cards'] !== []
                    && self::templateCards($module['cards']) === self::templateCards($old['cards'])) {
                    $module['cards'] = $current[$key]['cards'];
                }
            }
            if ($key === 'products' && is_array($module['cards'] ?? null)) {
                $currentLinks = array_column($current[$key]['cards'], 'link');
                $retired = [];
                foreach ($history as $version) {
                    foreach (($version[$key]['cards'] ?? []) as $oldCard) {
                        if (!in_array($oldCard['link'] ?? '', $currentLinks, true)) $retired[] = self::templateCards([$oldCard])[0];
                    }
                }
                // Only remove exact retired presets. A custom card, even with
                // the same title/path, must survive if copy, media or flags differ.
                $module['cards'] = array_values(array_filter($module['cards'], static function ($card) use ($retired) {
                    return !in_array(self::templateCards([$card])[0], $retired, true);
                }));
            }
            $input['modules'][$index] = $module;
        }
        return $input;
    }

    private static function templateCards(array $cards): array
    {
        $cards = self::normalizeCards($cards);
        foreach ($cards as &$card) unset($card['sort']);
        unset($card);
        return $cards;
    }

    private static function normalizeNavigation($navigation): array
    {
        $map = [];
        foreach (is_array($navigation) ? $navigation : [] as $entry) {
            if (is_array($entry) && isset($entry['key'])) $map[(string)$entry['key']] = $entry;
        }
        $result = [];
        foreach (self::navigationDefaults() as $default) {
            $entry = array_merge($default, $map[$default['key']] ?? []);
            $link = self::safeLink((string)($entry['link'] ?? ''));
            $groups = [];
            foreach (array_slice(is_array($entry['groups']) ? $entry['groups'] : [], 0, 4) as $group) {
                if (!is_array($group)) continue;
                $items = [];
                foreach (array_slice(is_array($group['items'] ?? null) ? $group['items'] : [], 0, 16) as $item) {
                    if (!is_array($item)) continue;
                    $path = self::safeLink((string)($item['link'] ?? ''));
                    $live = ($item['status'] ?? '') === 'live' && $path !== '';
                    $items[] = [
                        'label' => self::text($item['label'] ?? '', 60),
                        'description' => self::text($item['description'] ?? '', 150),
                        'icon_url' => self::text($item['icon_url'] ?? '', 1024),
                        'icon' => in_array($item['icon'] ?? '', ['drama', 'canvas', 'image', 'video', 'audio', 'avatar', 'edit', 'grid', 'book', 'search', 'arrow'], true) ? $item['icon'] : 'grid',
                        'status' => $live ? 'live' : 'planned', 'link' => $live ? $path : '',
                    ];
                }
                $groups[] = ['title' => self::text($group['title'] ?? '', 60), 'items' => $items];
            }
            $result[] = [
                'key' => $default['key'], 'enabled' => (int)!empty($entry['enabled']),
                'sort' => max(0, min(999, (int)$entry['sort'])), 'label' => self::text($entry['label'], 40),
                'mode' => ($entry['mode'] ?? '') === 'link' ? 'link' : 'dropdown',
                'title' => self::text($entry['title'], 100), 'button_text' => self::text($entry['button_text'], 40),
                'button_link' => self::safeLink((string)$entry['button_link']),
                'status' => ($entry['status'] ?? '') === 'live' && $link !== '' ? 'live' : 'planned',
                'link' => ($entry['status'] ?? '') === 'live' ? $link : '', 'groups' => $groups,
            ];
        }
        usort($result, static fn(array $a, array $b) => $b['sort'] <=> $a['sort']);
        return $result;
    }

    private static function navigationDefaults(): array
    {
        return OfficialSiteTemplate::navigation();
    }

    private static function legacyNavigationDefaults(): array
    {
        $item = static fn($label, $icon, $link = '') => ['label' => $label, 'icon' => $icon, 'status' => $link === '' ? 'planned' : 'live', 'link' => $link];
        $group = static fn($title, $items) => ['title' => $title, 'items' => $items];
        $entry = static fn($key, $label, $sort, $title, $path, $groups = [], $mode = 'dropdown') => [
            'key' => $key, 'label' => $label, 'enabled' => 1, 'sort' => $sort, 'mode' => $mode,
            'title' => $title, 'button_text' => $path === '' ? '' : '查看概览', 'button_link' => $path,
            'link' => $mode === 'link' ? $path : '', 'status' => $mode === 'link' && $path !== '' ? 'live' : 'planned', 'groups' => $groups,
        ];
        return [
            $entry('products', '产品', 100, '探索下一代 AI 创作产品', '/#products', [
                $group('AI 视频创作', [$item('数字人视频创作', 'avatar', '/ai/avatar?tab=lip_sync'), $item('AI 短剧', 'drama', '/ai/short-drama'), $item('无限画布', 'canvas', '/app/aigc_canvas'), $item('PPT 转视频', 'video'), $item('手持商品数字人', 'avatar'), $item('Agent 生视频', 'video')]),
                $group('数字人资产', [$item('全驱动数字人', 'avatar', '/ai/avatar?tab=image_human'), $item('声音克隆', 'audio', '/ai/avatar'), $item('定制数字人', 'avatar')]),
                $group('内容增长工具', [$item('灵感创作', 'image', '/app/aigc_llm'), $item('智能视频剪辑', 'edit', '/ai/smart_clip'), $item('AI 商品图', 'image', '/ai/tools/aigc_product_image'), $item('爆款跟创', 'drama'), $item('视频翻译', 'video')]),
            ]),
            $entry('models', '模型', 90, '找到适合你的创作模型', '/ai', [
                $group('创作模型', [$item('图像模型', 'image', '/ai/create?type=image'), $item('视频模型', 'video', '/ai/create?type=video'), $item('对话模型', 'grid', '/app/aigc_llm')]),
                $group('数字人与声音', [$item('数字人模型', 'avatar', '/ai/avatar'), $item('音乐模型', 'audio', '/ai/tools/aigc_music'), $item('交互数字人模型', 'avatar')]),
                $group('自有模型', [$item('自研语音模型', 'audio'), $item('专属企业模型', 'grid')]),
            ]),
            $entry('industries', '行业应用', 80, '为每一种表达，找到创作方式', '/#scenes', [
                $group('内容与营销', [$item('品牌营销', 'avatar', '/ai/avatar'), $item('IP 打造', 'drama', '/ai/short-drama'), $item('电商带货', 'image', '/ai/tools/aigc_product_image')]),
                $group('行业场景', [$item('教育培训口播', 'avatar', '/ai/avatar?tab=lip_sync'), $item('AI 主播', 'avatar', '/ai/avatar'), $item('短剧制作', 'drama', '/ai/short-drama'), $item('交互数字人', 'avatar'), $item('线索获客', 'search')]),
            ]),
            $entry('tools', '创作工具', 70, '从图像到声音，自由连接灵感', '/ai/tools', [
                $group('图像工具', [$item('AI 绘图', 'image', '/ai/create?type=image'), $item('AI 商品图', 'image', '/ai/tools/aigc_product_image'), $item('AI 试衣', 'image', '/ai/tools/aigc_fitting'), $item('老照片修复', 'image', '/ai/tools/aigc_photo_restore')]),
                $group('视频工具', [$item('AI 视频', 'video', '/ai/create?type=video'), $item('智能视频剪辑', 'edit', '/ai/smart_clip'), $item('AI 短剧', 'drama', '/ai/short-drama'), $item('无限画布', 'canvas', '/app/aigc_canvas')]),
                $group('声音与文字', [$item('AI 音乐', 'audio', '/ai/tools/aigc_music'), $item('声音克隆', 'audio', '/ai/avatar'), $item('AI 对话', 'grid', '/app/aigc_llm')]),
            ]),
            $entry('open', '开放平台', 60, '连接你的产品与 AI 创作能力', '', [
                $group('开发者', [$item('平台介绍', 'grid'), $item('开发者文档', 'book'), $item('API 控制台', 'grid'), $item('MCP', 'canvas')]),
            ]),
            $entry('pricing', '价格', 50, '选择适合你的创作方案', '/pricing', [], 'link'),
            $entry('enterprise', '企业服务', 40, '面向团队的创作服务', '', [], 'link'),
            $entry('help', '帮助中心', 30, '创作指南、更新与支持', '', [
                $group('学习与支持', [$item('新手指南', 'book'), $item('使用教程', 'video'), $item('常见问题', 'book'), $item('更新日志', 'book'), $item('联系我们', 'avatar')]),
            ]),
        ];
    }

    private static function normalizeSlides($slides): array
    {
        if (!is_array($slides)) return [];
        $result = [];
        foreach (array_slice($slides, 0, 12) as $index => $slide) {
            if (!is_array($slide)) continue;
            $result[] = [
                'title' => self::text($slide['title'] ?? '', 80),
                'media' => self::text($slide['media'] ?? '', 1024),
                'poster' => self::text($slide['poster'] ?? '', 1024),
                'mobile_media' => self::text($slide['mobile_media'] ?? '', 1024),
                'mobile_poster' => self::text($slide['mobile_poster'] ?? '', 1024),
                'media_type' => ($slide['media_type'] ?? '') === 'image' ? 'image' : 'video',
                'enabled' => (int)!empty($slide['enabled'] ?? 1),
                'sort' => max(0, min(999, (int)($slide['sort'] ?? (100 - $index)))),
            ];
        }
        usort($result, static fn(array $a, array $b) => $b['sort'] <=> $a['sort']);
        return $result;
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
                'tab_label' => self::text($card['tab_label'] ?? (['drama'=>'AI 短剧','video'=>'AI 视频','image'=>'AI 绘图','avatar'=>'数字人','canvas'=>'无限画布','audio'=>'AI 音乐'][$card['icon'] ?? ''] ?? ($card['title'] ?? '')), 40),
                'icon' => self::text($card['icon'] ?? 'grid', 30),
                'icon_url' => self::text($card['icon_url'] ?? '', 1024),
                'model_id' => self::text($card['model_id'] ?? '', 80),
                'enabled' => array_key_exists('enabled', $card) ? (int)!empty($card['enabled']) : 1,
                'description' => self::text($card['description'] ?? '', 300),
                'media' => self::text($card['media'] ?? '', 1024),
                'poster' => self::text($card['poster'] ?? '', 1024),
                'canvas_image' => self::text($card['canvas_image'] ?? '', 1024),
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
        foreach ($config['navigation'] as &$nav) { foreach ($nav['groups'] as &$group) { foreach ($group['items'] as &$item) $item['icon_url'] = FileService::setFileUrl($item['icon_url']); unset($item); } unset($group); } unset($nav);
        foreach ($config['modules'] as &$module) {
            foreach (['media', 'poster'] as $field) if (array_key_exists($field, $module)) $module[$field] = FileService::setFileUrl($module[$field]);
            foreach ($module['cards'] as &$card) {
                foreach (['media', 'poster', 'icon_url'] as $field) if (array_key_exists($field, $card)) $card[$field] = FileService::setFileUrl($card[$field]);
            }
            unset($card);
            foreach ($module['slides'] as &$slide) {
                foreach (['media', 'poster', 'mobile_media', 'mobile_poster'] as $field) $slide[$field] = FileService::setFileUrl($slide[$field]);
            }
            unset($slide);
        }
        unset($module);
        $config['basic']['logo'] = FileService::setFileUrl($config['basic']['logo']);
        $config['basic']['favicon'] = FileService::setFileUrl($config['basic']['favicon']);
        $config['basic']['placeholder'] = FileService::setFileUrl($config['basic']['placeholder']);
        return $config;
    }

    /** Convert stored media paths to URLs expected by the admin material picker. */
    private static function toEditor(array $config): array
    {
        foreach ($config['navigation'] as &$nav) { foreach ($nav['groups'] as &$group) { foreach ($group['items'] as &$item) $item['icon_url'] = self::fileUrl($item['icon_url']); unset($item); } unset($group); } unset($nav);
        $config['basic']['logo'] = self::fileUrl($config['basic']['logo']);
        $config['basic']['favicon'] = self::fileUrl($config['basic']['favicon']);
        $config['basic']['placeholder'] = self::fileUrl($config['basic']['placeholder']);
        foreach ($config['modules'] as &$module) {
            foreach (['media', 'poster'] as $field) if (array_key_exists($field, $module)) $module[$field] = self::fileUrl((string)($module[$field] ?? ''));
            foreach ($module['cards'] as &$card) {
                foreach (['media', 'poster', 'icon_url'] as $field) if (array_key_exists($field, $card)) $card[$field] = self::fileUrl((string)($card[$field] ?? ''));
            }
            unset($card);
            foreach ($module['slides'] as &$slide) {
                foreach (['media', 'poster', 'mobile_media', 'mobile_poster'] as $field) $slide[$field] = self::fileUrl((string)($slide[$field] ?? ''));
            }
            unset($slide);
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
        $link = trim($link);
        if (preg_match('~^/(?!/)[A-Za-z0-9_/?=&.#%:+,-]*$~', $link)) return $link;
        if (preg_match('/[\\\\\x00-\x20]/', $link)) return '';
        $parts = parse_url($link);
        return filter_var($link, FILTER_VALIDATE_URL) && ($parts['scheme'] ?? '') === 'https'
            && empty($parts['user']) && empty($parts['pass']) ? $link : '';
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
            if (!empty($module['media']) || !empty($module['poster']) || !empty($module['background_media']) || !empty($module['background_poster'])) return false;
            $cards = $module['cards'] ?? [];
            if (!is_array($cards)) return false;
            if (count($cards) !== count($legacy['cards'])) return false;
            foreach ($cards as $index => $card) {
                foreach (['title', 'description', 'status', 'link'] as $key) {
                    if (($card[$key] ?? '') !== ($legacy['cards'][$index][$key] ?? '')) return false;
                }
                if (!empty($card['media']) || !empty($card['poster']) || !empty($card['canvas_image'])) return false;
            }
            return true;
        }
        return false;
    }

    /** Only replace the unedited first draft; preserve tenant copy, media and order. */
    private static function isDraftDefault(array $module): bool
    {
        foreach (self::draftDefaults()['modules'] as $draft) {
            if (($module['key'] ?? '') !== $draft['key']) continue;
            foreach (['title', 'description', 'eyebrow', 'button_text', 'button_link', 'media', 'poster', 'background_media', 'background_poster'] as $key) {
                if (($module[$key] ?? '') !== ($draft[$key] ?? '')) return false;
            }
            return self::normalizeCards($module['cards'] ?? []) === self::normalizeCards($draft['cards']);
        }
        return false;
    }

    private static function defaults(): array
    {
        return OfficialSiteTemplate::defaults(self::v3Defaults());
    }

    private static function v3Defaults(): array
    {
        $config = self::draftDefaults();
        $config['basic'] += [
            'particles_enabled' => 1, 'motion_enabled' => 1,
            'nav_tools' => '更多工具', 'nav_resources' => '了解更多',
            'start_text' => '开始创作', 'video_text' => '播放介绍', 'footer_heading' => '让想象力起飞',
        ];
        foreach ($config['modules'] as &$module) {
            if ($module['key'] === 'hero') {
                $module['title'] = "让想象力起飞，\n开启 AI 创作新方式";
                $module['description'] = '数字人、AI 短剧、无限画布。让每个想法，都有成为作品的可能。';
            }
            if ($module['key'] === 'products') {
                $module['button_text'] = '探索全部创作工具';
                $module['button_link'] = '/ai/tools';
            }
            if (in_array($module['key'], ['workflow', 'faq'], true)) $module['enabled'] = 0;
            if ($module['key'] === 'faq') $module['sort'] = 42;
            if ($module['key'] === 'cta') $module['sort'] = 10;
        }
        unset($module);
        $card = static fn($title, $description, $link, $eyebrow, $button = '探索工具') => [
            'title' => $title, 'description' => $description, 'link' => $link, 'eyebrow' => $eyebrow,
            'kind' => 'custom', 'status' => 'live', 'button_text' => $button, 'media' => '',
        ];
        $config['modules'][] = [
            'key' => 'audiences', 'enabled' => 1, 'sort' => 60, 'title' => '', 'eyebrow' => '', 'description' => '',
            'media' => '', 'poster' => '', 'media_type' => 'image', 'button_text' => '', 'button_link' => '',
            'cards' => [
                $card('为每一位创作者', '从一个想法，开始下一次创作', '/ai', '你的创意，现在启程', '开始创作'),
                $card('为每一种创作节奏', '选择适合自己的创作方案', '/pricing', '探索更多创作可能', '查看价格方案'),
            ],
        ];
        $config['modules'][] = [
            'key' => 'tools', 'enabled' => 1, 'sort' => 50, 'title' => '还有更多，等待你的灵感', 'eyebrow' => 'MORE WAYS TO CREATE',
            'description' => '从图像到视频，从音乐到文字。在一个工作台，找到你的下一件创作工具。',
            'media' => '', 'poster' => '', 'media_type' => 'image', 'button_text' => '探索全部工具', 'button_link' => '/ai/tools',
            'cards' => [
                $card('AI 绘图', '描述想象中的画面，借助生图工具探索不同的视觉表达。', '/app/aigc_image', 'IMAGE GENERATION'),
                $card('AI 视频', '通过视频生成工具，把创作想法继续变成动态画面。', '/app/aigc_video', 'VIDEO GENERATION'),
                $card('AI 商品图', '选择商品场景与模板，为产品探索新的展示方式。', '/ai/tools/aigc_product_image', 'PRODUCT PHOTOGRAPHY'),
                $card('智能视频剪辑', '组织真人口播、素材混剪或新闻体视频，继续打磨你的内容。', '/ai/smart_clip', 'SMART VIDEO EDITING'),
                $card('AI 音乐', '从歌词、提示词或参考音频开始，探索音乐与声音创作。', '/ai/tools/aigc_music', 'MUSIC GENERATION'),
                $card('AI 对话', '以多轮对话整理灵感、推敲文案，让创作思路持续展开。', '/app/aigc_llm', 'AI CONVERSATION'),
                $card('AI 试衣', '结合人物与服装素材，预览不同搭配的视觉效果。', '/ai/tools/aigc_fitting', 'VIRTUAL TRY-ON'),
                $card('老照片修复', '修复、上色，让旧照片中的记忆重新清晰起来。', '/ai/tools/aigc_photo_restore', 'PHOTO RESTORATION'),
            ],
        ];
        return $config;
    }

    private static function draftDefaults(): array
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
