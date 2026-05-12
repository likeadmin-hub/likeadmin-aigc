<?php

namespace app\common\service\decorate;

use app\common\model\decorate\DecoratePage;
use app\common\model\decorate\DecorateTabbar;
use app\common\model\decorate\DecorateTemplate;
use app\common\service\ConfigService;
use RuntimeException;

class DecorateTemplateService
{
    public const TERMINAL_MOBILE = 'mobile';
    public const TERMINAL_PC = 'pc';
    public const CHANNEL_COMMON = 'common';

    public static function lists(int $tenantId): array
    {
        self::ensureDefaultTemplate($tenantId);
        return DecorateTemplate::where('tenant_id', $tenantId)
            ->order(['is_active' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();
    }

    public static function detail(int $tenantId, int $templateId = 0, string $terminal = self::TERMINAL_MOBILE): array
    {
        $template = self::getTemplate($tenantId, $templateId);
        self::ensureTemplatePages((int)$template['id'], $tenantId);

        return [
            'template' => $template->toArray(),
            'settings' => self::decodeJson($template['draft_settings'], self::defaultSettings()),
            'pages' => self::pageLists($tenantId, (int)$template['id'], $terminal),
        ];
    }

    public static function add(int $tenantId, array $params): array
    {
        self::ensureDefaultTemplate($tenantId);
        $name = trim((string)($params['name'] ?? ''));
        if ($name === '') {
            $name = '新建模板';
        }
        $settings = self::defaultSettings();
        $template = DecorateTemplate::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'cover' => (string)($params['cover'] ?? ''),
            'status' => 1,
            'is_active' => 0,
            'publish_status' => 'draft',
            'draft_settings' => self::encodeJson($settings),
            'published_settings' => self::encodeJson($settings),
        ]);
        self::ensureTemplatePages((int)$template['id'], $tenantId, true);
        return $template->toArray();
    }

    public static function edit(int $tenantId, array $params): void
    {
        $template = self::getTemplate($tenantId, (int)($params['id'] ?? 0));
        $data = [];
        if (isset($params['name'])) {
            $data['name'] = trim((string)$params['name']);
        }
        if (isset($params['cover'])) {
            $data['cover'] = (string)$params['cover'];
        }
        if ($data) {
            $template->save($data);
        }
    }

    public static function copy(int $tenantId, int $templateId): array
    {
        $source = self::getTemplate($tenantId, $templateId);
        $template = DecorateTemplate::create([
            'tenant_id' => $tenantId,
            'name' => $source['name'] . ' 副本',
            'cover' => $source['cover'],
            'status' => 1,
            'is_active' => 0,
            'publish_status' => 'draft',
            'draft_settings' => $source['draft_settings'],
            'published_settings' => $source['published_settings'],
        ]);

        $pages = DecoratePage::where('template_id', (int)$source['id'])->select();
        foreach ($pages as $page) {
            $data = $page->toArray();
            unset($data['id'], $data['create_time'], $data['update_time']);
            $data['template_id'] = (int)$template['id'];
            DecoratePage::create($data);
        }

        return $template->toArray();
    }

    public static function delete(int $tenantId, int $templateId): void
    {
        $template = self::getTemplate($tenantId, $templateId);
        if ((int)$template['is_active'] === 1) {
            throw new RuntimeException('启用中的模板不能删除');
        }
        DecoratePage::where('template_id', $templateId)->delete();
        $template->delete();
    }

    public static function enable(int $tenantId, int $templateId): void
    {
        $template = self::getTemplate($tenantId, $templateId);
        if ($template['publish_status'] !== 'published') {
            self::publish($tenantId, $templateId);
            $template = self::getTemplate($tenantId, $templateId);
        }

        DecorateTemplate::where('tenant_id', $tenantId)->update(['is_active' => 0]);
        $template->save(['is_active' => 1]);
        self::syncLegacyPublishedData($tenantId, $templateId);
    }

    public static function publish(int $tenantId, int $templateId): void
    {
        $template = self::getTemplate($tenantId, $templateId);
        $template->save([
            'published_settings' => $template['draft_settings'],
            'publish_status' => 'published',
        ]);

        $pages = DecoratePage::where('template_id', $templateId)->select();
        foreach ($pages as $page) {
            $page->save([
                'published_data' => $page['draft_data'] ?: $page['data'],
                'published_meta' => $page['draft_meta'] ?: $page['meta'],
            ]);
        }

        if ((int)$template['is_active'] === 1) {
            self::syncLegacyPublishedData($tenantId, $templateId);
        }
    }

    public static function pageLists(int $tenantId, int $templateId, string $terminal): array
    {
        self::ensureTemplatePages($templateId, $tenantId);
        return DecoratePage::where([
            'template_id' => $templateId,
            'terminal' => $terminal,
        ])->order(['is_home' => 'desc', 'sort' => 'asc', 'id' => 'asc'])->select()->toArray();
    }

    public static function pageDetail(int $tenantId, array $params): array
    {
        $template = self::getTemplate($tenantId, (int)($params['template_id'] ?? 0));
        self::ensureTemplatePages((int)$template['id'], $tenantId);

        $query = DecoratePage::where('template_id', (int)$template['id']);
        if (!empty($params['id'])) {
            $query->where('id', (int)$params['id']);
        } elseif (!empty($params['page_code'])) {
            $query->where('page_code', (string)$params['page_code']);
        } elseif (!empty($params['type'])) {
            $query->where('type', (int)$params['type']);
        } else {
            $query->where('is_home', 1)->where('terminal', (string)($params['terminal'] ?? self::TERMINAL_MOBILE));
        }

        $page = $query->findOrEmpty();
        return $page->toArray();
    }

    public static function savePage(int $tenantId, array $params): array
    {
        $template = self::getTemplate($tenantId, (int)($params['template_id'] ?? 0));
        $page = DecoratePage::where([
            'id' => (int)($params['id'] ?? 0),
            'template_id' => (int)$template['id'],
        ])->findOrEmpty();
        if ($page->isEmpty()) {
            throw new RuntimeException('装修页面不存在');
        }

        $page->save([
            'name' => (string)($params['name'] ?? $page['name']),
            'draft_data' => (string)($params['data'] ?? $params['draft_data'] ?? '[]'),
            'draft_meta' => (string)($params['meta'] ?? $params['draft_meta'] ?? ''),
            'data' => (string)($params['data'] ?? $params['draft_data'] ?? '[]'),
            'meta' => (string)($params['meta'] ?? $params['draft_meta'] ?? ''),
        ]);
        DecorateTemplate::where('id', (int)$template['id'])->update(['publish_status' => 'draft']);
        return $page->toArray();
    }

    public static function updatePageBase(int $tenantId, array $params): array
    {
        $template = self::getTemplate($tenantId, (int)($params['template_id'] ?? 0));
        $page = DecoratePage::where([
            'id' => (int)($params['id'] ?? 0),
            'template_id' => (int)$template['id'],
        ])->findOrEmpty();
        if ($page->isEmpty()) {
            throw new RuntimeException('装修页面不存在');
        }
        if ((int)$page['is_system'] === 1 && isset($params['page_code'])) {
            unset($params['page_code']);
        }

        $data = [];
        if (isset($params['name'])) {
            $data['name'] = trim((string)$params['name']);
        }
        if (isset($params['status'])) {
            $data['status'] = (int)$params['status'];
        }
        if (isset($params['sort'])) {
            $data['sort'] = (int)$params['sort'];
        }
        if (isset($params['page_code'])) {
            $pageCode = self::normalizePageCode((string)$params['page_code']);
            $exists = DecoratePage::where([
                'template_id' => (int)$template['id'],
                'page_code' => $pageCode,
            ])->where('id', '<>', (int)$page['id'])->findOrEmpty();
            if (!$exists->isEmpty()) {
                throw new RuntimeException('页面标识已存在');
            }
            $data['page_code'] = $pageCode;
            if ((string)$page['page_type'] === 'custom') {
                $data['route_path'] = $page['terminal'] === self::TERMINAL_PC ? '/page/' . $pageCode : '/pages/diy/diy';
            }
        }
        if ($data) {
            $page->save($data);
            DecorateTemplate::where('id', (int)$template['id'])->update(['publish_status' => 'draft']);
        }
        return $page->toArray();
    }

    public static function addPage(int $tenantId, array $params): array
    {
        $template = self::getTemplate($tenantId, (int)($params['template_id'] ?? 0));
        $terminal = (string)($params['terminal'] ?? self::TERMINAL_MOBILE);
        $name = trim((string)($params['name'] ?? ''));
        if ($name === '') {
            $name = '自定义页面';
        }
        $pageCode = self::normalizePageCode((string)($params['page_code'] ?? 'page_' . time()));
        if (DecoratePage::where(['template_id' => (int)$template['id'], 'page_code' => $pageCode])->findOrEmpty()->isEmpty() === false) {
            throw new RuntimeException('页面标识已存在');
        }
        $page = DecoratePage::create([
            'tenant_id' => $tenantId,
            'template_id' => (int)$template['id'],
            'terminal' => $terminal,
            'channel' => self::CHANNEL_COMMON,
            'page_code' => $pageCode,
            'page_type' => 'custom',
            'route_path' => $terminal === self::TERMINAL_PC ? '/page/' . $pageCode : '/pages/diy/diy',
            'is_home' => 0,
            'is_system' => 0,
            'status' => 1,
            'sort' => 100,
            'type' => 10,
            'name' => $name,
            'data' => '[]',
            'meta' => self::encodeJson(self::defaultPageMeta($name)),
            'draft_data' => '[]',
            'draft_meta' => self::encodeJson(self::defaultPageMeta($name)),
            'published_data' => '[]',
            'published_meta' => self::encodeJson(self::defaultPageMeta($name)),
        ]);
        DecorateTemplate::where('id', (int)$template['id'])->update(['publish_status' => 'draft']);
        return $page->toArray();
    }

    public static function copyPage(int $tenantId, int $pageId): array
    {
        $page = DecoratePage::where('id', $pageId)->findOrEmpty();
        if ($page->isEmpty()) {
            throw new RuntimeException('装修页面不存在');
        }
        self::getTemplate($tenantId, (int)$page['template_id']);
        $data = $page->toArray();
        unset($data['id'], $data['create_time'], $data['update_time']);
        $data['tenant_id'] = $tenantId;
        $data['is_home'] = 0;
        $data['is_system'] = 0;
        $data['name'] = $page['name'] . ' 副本';
        $data['page_code'] = self::normalizePageCode($page['page_code'] . '_copy_' . time());
        $data['page_type'] = 'custom';
        $data['route_path'] = $page['terminal'] === self::TERMINAL_PC ? '/page/' . $data['page_code'] : '/pages/diy/diy';
        $copy = DecoratePage::create($data);
        DecorateTemplate::where('id', (int)$page['template_id'])->update(['publish_status' => 'draft']);
        return $copy->toArray();
    }

    public static function deletePage(int $tenantId, int $pageId): void
    {
        $page = DecoratePage::where('id', $pageId)->findOrEmpty();
        if ($page->isEmpty()) {
            throw new RuntimeException('装修页面不存在');
        }
        self::getTemplate($tenantId, (int)$page['template_id']);
        if ((int)$page['is_system'] === 1) {
            throw new RuntimeException('系统页面不能删除');
        }
        $templateId = (int)$page['template_id'];
        $page->delete();
        DecorateTemplate::where('id', $templateId)->update(['publish_status' => 'draft']);
    }

    public static function linkLists(int $tenantId, int $templateId = 0, string $terminal = self::TERMINAL_MOBILE): array
    {
        $template = self::getTemplate($tenantId, $templateId);
        $pages = self::pageLists($tenantId, (int)$template['id'], $terminal);
        return array_map(function ($page) use ($terminal) {
            $path = $page['route_path'];
            $query = [];
            if ($terminal === self::TERMINAL_MOBILE && $page['page_type'] === 'custom') {
                $path = '/pages/diy/diy';
                $query = ['code' => $page['page_code']];
            }
            return [
                'id' => $page['id'],
                'name' => $page['name'],
                'path' => $path,
                'query' => $query,
                'type' => 'decorate',
                'terminal' => $terminal,
                'page_code' => $page['page_code'],
                'canTab' => (int)$page['is_home'] === 1 || in_array($page['page_code'], ['home', 'user'], true),
            ];
        }, $pages);
    }

    public static function activePublishedPage(int $tenantId, string $terminal, string $pageCode = '', int $type = 0, string $channel = 'h5', bool $preview = false, int $templateId = 0, int $pageId = 0): array
    {
        $template = $preview && $templateId > 0 ? self::getTemplate($tenantId, $templateId) : self::activeTemplate($tenantId);
        self::ensureTemplatePages((int)$template['id'], $tenantId);
        if ($pageCode === '' && $type > 0) {
            $pageCode = self::typeToPageCode($type);
            if ($type === 4) {
                $terminal = self::TERMINAL_PC;
            }
        }
        if ($pageCode === '') {
            $pageCode = $terminal === self::TERMINAL_PC ? 'pc_home' : 'home';
        }

        if ($preview && $pageId > 0) {
            $page = DecoratePage::where([
                'id' => $pageId,
                'template_id' => (int)$template['id'],
            ])->findOrEmpty();
        } else {
            $page = DecoratePage::where([
                'template_id' => (int)$template['id'],
                'terminal' => $terminal,
                'page_code' => $pageCode,
                'channel' => $channel,
            ])->findOrEmpty();
        }
        if ($page->isEmpty()) {
            $page = DecoratePage::where([
                'template_id' => (int)$template['id'],
                'terminal' => $terminal,
                'page_code' => $pageCode,
                'channel' => self::CHANNEL_COMMON,
            ])->findOrEmpty();
        }
        if ($page->isEmpty()) {
            return [];
        }

        $data = $page->toArray();
        if ($preview) {
            $data['data'] = $data['draft_data'] ?: $data['data'];
            $data['meta'] = $data['draft_meta'] ?: $data['meta'];
        } else {
            $data['data'] = $data['published_data'] ?: $data['data'];
            $data['meta'] = $data['published_meta'] ?: $data['meta'];
        }
        return $data;
    }

    public static function activeMobileTabbar(int $tenantId): array
    {
        $template = self::activeTemplate($tenantId);
        $settings = self::decodeJson($template['published_settings'], []);
        if (!empty($settings['mobile_tabbar'])) {
            return $settings['mobile_tabbar'];
        }
        return [
            'style' => ConfigService::get('tabbar', 'style', config('project.decorate.tabbar_style')),
            'list' => DecorateTabbar::getTabbarLists(),
        ];
    }

    public static function activeMobileStyle(int $tenantId): array
    {
        $template = self::activeTemplate($tenantId);
        $settings = self::decodeJson($template['published_settings'], []);
        if (!empty($settings['mobile_style'])) {
            return $settings['mobile_style'];
        }
        $legacy = self::legacySettings();
        return $legacy['mobile_style'] ?: [
            'themeColorId' => 1,
            'topTextColor' => 'white',
            'navigationBarColor' => '#2F80ED',
            'themeColor1' => '#2F80ED',
            'themeColor2' => '#56CCF2',
            'buttonColor' => 'white',
        ];
    }

    public static function saveSettings(int $tenantId, int $templateId, array $settings): void
    {
        $template = self::getTemplate($tenantId, $templateId);
        $old = self::decodeJson($template['draft_settings'], self::defaultSettings());
        $template->save([
            'draft_settings' => self::encodeJson(array_replace_recursive($old, $settings)),
            'publish_status' => 'draft',
        ]);
    }

    private static function getTemplate(int $tenantId, int $templateId = 0): DecorateTemplate
    {
        self::ensureDefaultTemplate($tenantId);
        $query = DecorateTemplate::where('tenant_id', $tenantId);
        if ($templateId > 0) {
            $query->where('id', $templateId);
        } else {
            $query->where('is_active', 1);
        }
        $template = $query->findOrEmpty();
        if ($template->isEmpty()) {
            throw new RuntimeException('装修模板不存在');
        }
        return $template;
    }

    private static function activeTemplate(int $tenantId): DecorateTemplate
    {
        return self::getTemplate($tenantId, 0);
    }

    public static function ensureDefaultTemplate(int $tenantId): void
    {
        if ($tenantId <= 0) {
            return;
        }
        $exists = DecorateTemplate::where('tenant_id', $tenantId)->findOrEmpty();
        if (!$exists->isEmpty()) {
            return;
        }
        $settings = self::legacySettings();
        $template = DecorateTemplate::create([
            'tenant_id' => $tenantId,
            'name' => '默认模板',
            'cover' => '',
            'status' => 1,
            'is_active' => 1,
            'publish_status' => 'published',
            'draft_settings' => self::encodeJson($settings),
            'published_settings' => self::encodeJson($settings),
        ]);
        self::ensureTemplatePages((int)$template['id'], $tenantId, true);
        self::syncLegacyPublishedData($tenantId, (int)$template['id']);
    }

    private static function ensureTemplatePages(int $templateId, int $tenantId, bool $forceCreate = false): void
    {
        $map = [
            1 => ['home', self::TERMINAL_MOBILE, 'mobile_home', '/pages/index/index', 1, 1, '系统首页', ['search', 'banner', 'nav', 'news']],
            2 => ['user', self::TERMINAL_MOBILE, 'mobile_user', '/pages/user/user', 0, 1, '个人中心', ['user-info', 'my-service', 'user-banner']],
            3 => ['service', self::TERMINAL_MOBILE, 'mobile_service', '/pages/customer_service/customer_service', 0, 1, '客服设置', ['customer-service']],
            4 => ['pc_home', self::TERMINAL_PC, 'pc_home', '/', 1, 1, 'PC首页', ['pc-banner']],
        ];

        foreach ($map as $type => $item) {
            [$pageCode, $terminal, $pageType, $routePath, $isHome, $isSystem, $name, $widgets] = $item;
            $page = DecoratePage::where(['template_id' => $templateId, 'page_code' => $pageCode])->findOrEmpty();
            if (!$page->isEmpty()) {
                continue;
            }

            $legacy = DecoratePage::where(['type' => $type])->findOrEmpty();
            if ($legacy->isEmpty()) {
                $legacy = DecoratePage::withoutGlobalScope()->where(['tenant_id' => 0, 'type' => $type])->findOrEmpty();
            }

            $data = $legacy->isEmpty() ? self::encodeJson(self::generatePageData($widgets)) : (string)$legacy['data'];
            $meta = $legacy->isEmpty() ? self::encodeJson(self::defaultPageMeta($name)) : (string)$legacy['meta'];
            if (!$forceCreate && !$legacy->isEmpty() && (int)$legacy['template_id'] === 0) {
                $legacy->save([
                    'template_id' => $templateId,
                    'terminal' => $terminal,
                    'channel' => self::CHANNEL_COMMON,
                    'page_code' => $pageCode,
                    'page_type' => $pageType,
                    'route_path' => $routePath,
                    'is_home' => $isHome,
                    'is_system' => $isSystem,
                    'status' => 1,
                    'sort' => $type * 10,
                    'draft_data' => $data,
                    'draft_meta' => $meta,
                    'published_data' => $data,
                    'published_meta' => $meta,
                ]);
                continue;
            }

            DecoratePage::create([
                'tenant_id' => $tenantId,
                'template_id' => $templateId,
                'terminal' => $terminal,
                'channel' => self::CHANNEL_COMMON,
                'page_code' => $pageCode,
                'page_type' => $pageType,
                'route_path' => $routePath,
                'is_home' => $isHome,
                'is_system' => $isSystem,
                'status' => 1,
                'sort' => $type * 10,
                'type' => $type,
                'name' => $name,
                'data' => $data,
                'meta' => $meta,
                'draft_data' => $data,
                'draft_meta' => $meta,
                'published_data' => $data,
                'published_meta' => $meta,
            ]);
        }
    }

    private static function syncLegacyPublishedData(int $tenantId, int $templateId): void
    {
        $pages = DecoratePage::where('template_id', $templateId)->select();
        foreach ($pages as $page) {
            $page->save([
                'data' => $page['published_data'] ?: $page['draft_data'] ?: $page['data'],
                'meta' => $page['published_meta'] ?: $page['draft_meta'] ?: $page['meta'],
            ]);
        }
    }

    private static function legacySettings(): array
    {
        $stylePage = DecoratePage::where(['type' => 5])->findOrEmpty();
        return [
            'mobile_style' => $stylePage->isEmpty() ? [] : self::decodeJson($stylePage['data'], []),
            'mobile_tabbar' => [
                'style' => ConfigService::get('tabbar', 'style', config('project.decorate.tabbar_style')),
                'list' => DecorateTabbar::getTabbarLists(),
            ],
            'pc_style' => [],
        ];
    }

    private static function defaultSettings(): array
    {
        return [
            'mobile_style' => [],
            'mobile_tabbar' => [
                'style' => [
                    'default_color' => '#999999',
                    'selected_color' => '#4173ff',
                ],
                'list' => [],
            ],
            'pc_style' => [],
        ];
    }

    private static function typeToPageCode(int $type): string
    {
        return [
            1 => 'home',
            2 => 'user',
            3 => 'service',
            4 => 'pc_home',
        ][$type] ?? 'home';
    }

    private static function defaultPageMeta(string $title): array
    {
        return [[
            'title' => '页面设置',
            'name' => 'page-meta',
            'content' => [
                'title_type' => 1,
                'title' => $title,
                'title_img' => '',
                'nav_bg_color' => '#ffffff',
                'bg_type' => 0,
                'bg_color' => '',
                'bg_image' => '',
                'text_color' => '2',
            ],
            'styles' => [],
        ]];
    }

    private static function generatePageData(array $widgetNames): array
    {
        return array_map(function ($name) {
            return [
                'id' => uniqid('diy_', true),
                'title' => $name,
                'name' => $name,
                'content' => ['enabled' => 1],
                'styles' => [],
            ];
        }, $widgetNames);
    }

    private static function normalizePageCode(string $code): string
    {
        $code = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $code));
        return trim($code, '_') ?: 'page_' . time();
    }

    private static function decodeJson($value, array $default): array
    {
        if (is_array($value)) {
            return $value;
        }
        $data = json_decode((string)$value, true);
        return is_array($data) ? $data : $default;
    }

    private static function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
