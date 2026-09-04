<?php

namespace app\common\service\decorate;

use app\common\model\decorate\DecoratePage;
use app\common\model\decorate\DecorateTabbar;
use app\common\model\decorate\DecorateTemplate;
use app\common\service\membership\MembershipService;
use app\common\service\ConfigService;
use RuntimeException;
use think\facade\Db;
use ZipArchive;

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

        $pages = DecoratePage::where([
            'tenant_id' => $tenantId,
            'template_id' => (int)$source['id'],
        ])->select();
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
        DecoratePage::where([
            'tenant_id' => $tenantId,
            'template_id' => $templateId,
        ])->delete();
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
        Db::transaction(function () use ($tenantId, $templateId, $template) {
            $pages = DecoratePage::where([
                'tenant_id' => $tenantId,
                'template_id' => $templateId,
            ])->select();
            foreach ($pages as $page) {
                if ((int)($page['draft_deleted'] ?? 0) === 1) {
                    continue;
                }
                // Validate every page before changing any published value. A bad
                // page therefore cannot leave the template partially published.
                self::validatePagePayload(
                    (string)($page['draft_data'] ?: $page['data'] ?: '[]'),
                    (string)($page['draft_meta'] ?: $page['meta'] ?: '')
                );
            }

            $history = self::publishedHistoryPayload($template);
            $currentSnapshot = self::capturePublishedSnapshot($template, $pages);
            if ($currentSnapshot !== null) {
                array_unshift($history, $currentSnapshot);
                $history = array_slice($history, 0, 10);
            }
            $template->save([
                'published_settings' => $template['draft_settings'] ?: self::encodeJson(self::defaultSettings()),
                'published_pages' => self::encodeJson(self::publishedPageStructure($pages)),
                'published_history' => self::encodeJson($history),
                'publish_status' => 'published',
            ]);
            foreach ($pages as $page) {
                if ((int)($page['draft_deleted'] ?? 0) === 1) {
                    continue;
                }
                $page->save([
                    'published_data' => $page['draft_data'] ?: $page['data'] ?: '[]',
                    'published_meta' => $page['draft_meta'] ?: $page['meta'] ?: self::encodeJson(self::defaultPageMeta((string)$page['name'])),
                ]);
            }

            if ((int)$template['is_active'] === 1) {
                self::syncLegacyPublishedData($tenantId, $templateId);
            }
        });
    }

    public static function pageLists(int $tenantId, int $templateId, string $terminal): array
    {
        self::assertTerminal($terminal);
        self::ensureTemplatePages($templateId, $tenantId);
        return DecoratePage::where([
            'tenant_id' => $tenantId,
            'template_id' => $templateId,
            'terminal' => $terminal,
        ])->where('draft_deleted', 0)->order(['is_home' => 'desc', 'sort' => 'asc', 'id' => 'asc'])->select()->toArray();
    }

    public static function pageDetail(int $tenantId, array $params): array
    {
        $template = self::getTemplate($tenantId, (int)($params['template_id'] ?? 0));
        self::ensureTemplatePages((int)$template['id'], $tenantId);
        $requestedTerminal = isset($params['terminal']) && $params['terminal'] !== ''
            ? (string)$params['terminal']
            : null;
        if ($requestedTerminal !== null) {
            self::assertTerminal($requestedTerminal);
        }

        $query = DecoratePage::where([
            'tenant_id' => $tenantId,
            'template_id' => (int)$template['id'],
        ])->where('draft_deleted', 0);
        if ($requestedTerminal !== null) {
            $query->where('terminal', $requestedTerminal);
        }
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
        if ($page->isEmpty()) {
            throw new RuntimeException('装修页面不存在');
        }
        $data = $page->toArray();
        // Detail is consumed by both the editor and preview. Always resolve the
        // draft payload here so mobile and PC use the same data-source contract.
        $sourcePage = $data;
        $sourcePage['data'] = $data['draft_data'] ?: $data['data'] ?: '[]';
        $sourcePage['meta'] = $data['draft_meta'] ?: $data['meta'] ?: '[]';
        $resolved = DecorateDataSourceService::applyToPage(
            $sourcePage,
            self::decorateContext($tenantId, (string)($data['terminal'] ?? $params['terminal'] ?? self::TERMINAL_MOBILE), [
                'preview' => true,
                'query' => (array)($params['query'] ?? [])
            ])
        );
        $data['resolved_sources'] = $resolved['resolved_sources'] ?? [];
        return $data;
    }

    public static function savePage(int $tenantId, array $params): array
    {
        $template = self::getTemplate($tenantId, (int)($params['template_id'] ?? 0));
        $page = DecoratePage::where([
            'id' => (int)($params['id'] ?? 0),
            'tenant_id' => $tenantId,
            'template_id' => (int)$template['id'],
        ])->where('draft_deleted', 0)->findOrEmpty();
        if ($page->isEmpty()) {
            throw new RuntimeException('装修页面不存在');
        }
        self::assertPageTerminal($page, $params);

        $draftData = (string)($params['data'] ?? $params['draft_data'] ?? '[]');
        $draftMeta = (string)($params['meta'] ?? $params['draft_meta'] ?? '');
        self::validatePagePayload($draftData, $draftMeta);
        self::assertExpectedUpdateTime($page, $params);

        $page->save([
            'name' => (string)($params['name'] ?? $page['name']),
            'draft_data' => $draftData,
            'draft_meta' => $draftMeta,
        ]);
        DecorateTemplate::where([
            'tenant_id' => $tenantId,
            'id' => (int)$template['id'],
        ])->update(['publish_status' => 'draft']);
        return $page->toArray();
    }

    public static function updatePageBase(int $tenantId, array $params): array
    {
        $template = self::getTemplate($tenantId, (int)($params['template_id'] ?? 0));
        $page = DecoratePage::where([
            'id' => (int)($params['id'] ?? 0),
            'tenant_id' => $tenantId,
            'template_id' => (int)$template['id'],
        ])->where('draft_deleted', 0)->findOrEmpty();
        if ($page->isEmpty()) {
            throw new RuntimeException('装修页面不存在');
        }
        self::assertPageTerminal($page, $params);
        self::assertExpectedUpdateTime($page, $params);
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
            'tenant_id' => $tenantId,
            'template_id' => (int)$template['id'],
            'page_code' => $pageCode,
        ])->where('draft_deleted', 0)->where('id', '<>', (int)$page['id'])->findOrEmpty();
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
            DecorateTemplate::where([
                'tenant_id' => $tenantId,
                'id' => (int)$template['id'],
            ])->update(['publish_status' => 'draft']);
        }
        return $page->toArray();
    }

    /**
     * Keep exactly one draft homepage for each template terminal.
     * System pages remain protected from deletion even after a custom page is
     * selected as the homepage.
     */
    public static function setPageHome(int $tenantId, array $params): array
    {
        $template = self::getTemplate($tenantId, (int)($params['template_id'] ?? 0));
        $page = DecoratePage::where([
            'id' => (int)($params['id'] ?? 0),
            'tenant_id' => $tenantId,
            'template_id' => (int)$template['id'],
        ])->where('draft_deleted', 0)->findOrEmpty();
        if ($page->isEmpty()) {
            throw new RuntimeException('装修页面不存在');
        }
        self::assertPageTerminal($page, $params);
        self::assertExpectedUpdateTime($page, $params);

        return Db::transaction(function () use ($tenantId, $template, $page) {
            DecoratePage::where([
                'tenant_id' => $tenantId,
                'template_id' => (int)$template['id'],
                'terminal' => (string)$page['terminal'],
            ])->where('draft_deleted', 0)->update(['is_home' => 0]);

            $page->save(['is_home' => 1]);
            DecorateTemplate::where([
                'tenant_id' => $tenantId,
                'id' => (int)$template['id'],
            ])->update(['publish_status' => 'draft']);

            return $page->toArray();
        });
    }

    public static function addPage(int $tenantId, array $params): array
    {
        $template = self::getTemplate($tenantId, (int)($params['template_id'] ?? 0));
        $terminal = (string)($params['terminal'] ?? self::TERMINAL_MOBILE);
        self::assertTerminal($terminal);
        $name = trim((string)($params['name'] ?? ''));
        if ($name === '') {
            $name = '自定义页面';
        }
        $pageCode = self::normalizePageCode((string)($params['page_code'] ?? 'page_' . time()));
        if (DecoratePage::where([
            'tenant_id' => $tenantId,
            'template_id' => (int)$template['id'],
            'page_code' => $pageCode,
        ])->findOrEmpty()->isEmpty() === false) {
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
            'draft_deleted' => 0,
        ]);
        DecorateTemplate::where([
            'tenant_id' => $tenantId,
            'id' => (int)$template['id'],
        ])->update(['publish_status' => 'draft']);
        return $page->toArray();
    }

    public static function copyPage(int $tenantId, int $pageId): array
    {
        // Resolve the source page inside the current tenant before copying. The
        // page id alone is not an authorization boundary.
        $page = DecoratePage::where(['id' => $pageId, 'tenant_id' => $tenantId])->where('draft_deleted', 0)->findOrEmpty();
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
        DecorateTemplate::where([
            'tenant_id' => $tenantId,
            'id' => (int)$page['template_id'],
        ])->update(['publish_status' => 'draft']);
        return $copy->toArray();
    }

    public static function deletePage(int $tenantId, int $pageId): void
    {
        $page = DecoratePage::where(['id' => $pageId, 'tenant_id' => $tenantId])->where('draft_deleted', 0)->findOrEmpty();
        if ($page->isEmpty()) {
            throw new RuntimeException('装修页面不存在');
        }
        self::getTemplate($tenantId, (int)$page['template_id']);
        if ((int)$page['is_system'] === 1) {
            throw new RuntimeException('系统页面不能删除');
        }
        $templateId = (int)$page['template_id'];
        // The record remains until the next publish. Published page structure
        // keeps the live route resolvable while a draft deletion is pending.
        $page->save(['draft_deleted' => 1]);
        DecorateTemplate::where([
            'tenant_id' => $tenantId,
            'id' => $templateId,
        ])->update(['publish_status' => 'draft']);
    }

    public static function linkLists(int $tenantId, int $templateId = 0, string $terminal = self::TERMINAL_MOBILE): array
    {
        self::assertTerminal($terminal);
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

    public static function activePublishedPage(int $tenantId, string $terminal, string $pageCode = '', int $type = 0, string $channel = 'h5', bool $preview = false, int $templateId = 0, int $pageId = 0, array $context = []): array
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
                'tenant_id' => $tenantId,
                'id' => $pageId,
                'template_id' => (int)$template['id'],
                'terminal' => $terminal,
            ])->where('draft_deleted', 0)->findOrEmpty();
        } elseif ($preview) {
            $page = DecoratePage::where([
                'tenant_id' => $tenantId,
                'template_id' => (int)$template['id'],
                'terminal' => $terminal,
                'page_code' => $pageCode,
                'channel' => $channel,
            ])->where('draft_deleted', 0)->findOrEmpty();
            if ($page->isEmpty()) {
                $page = DecoratePage::where([
                    'tenant_id' => $tenantId,
                    'template_id' => (int)$template['id'],
                    'terminal' => $terminal,
                    'page_code' => $pageCode,
                    'channel' => self::CHANNEL_COMMON,
                ])->where('draft_deleted', 0)->findOrEmpty();
            }
        } else {
            $published = self::findPublishedPage($template, $terminal, $pageCode, $channel);
            if ($published === null) {
                return [];
            }
            $page = DecoratePage::where([
                'tenant_id' => $tenantId,
                'template_id' => (int)$template['id'],
                'id' => (int)$published['id'],
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
            // Page identity and status also come from the immutable template
            // snapshot so unpublished draft renames/deletions cannot affect
            // public H5, mini-program, or PC routes.
            $data = array_replace($data, $published);
            $data['data'] = $data['published_data'] ?: $data['data'];
            $data['meta'] = $data['published_meta'] ?: $data['meta'];
        }
        if ($channel !== self::CHANNEL_COMMON) {
            $data = self::mergeChannelOverlay($data, $tenantId, (int)$template['id'], $terminal, $pageCode, $channel, $preview);
        }
        return DecorateDataSourceService::applyToPage($data, self::decorateContext($tenantId, $terminal, $context));
    }

    /**
     * New channel rows may store a compact meta overlay instead of a complete
     * copy. Existing channel rows without this marker remain full overrides.
     */
    private static function mergeChannelOverlay(array $page, int $tenantId, int $templateId, string $terminal, string $pageCode, string $channel, bool $preview): array
    {
        $overlayMeta = self::decodeJson((string)($page['meta'] ?? ''), []);
        $overlay = (array)($overlayMeta[0]['content']['channel_overlay'] ?? []);
        if (!$overlay) {
            return $page;
        }
        $common = DecoratePage::where([
            'tenant_id' => $tenantId,
            'template_id' => $templateId,
            'terminal' => $terminal,
            'page_code' => $pageCode,
            'channel' => self::CHANNEL_COMMON,
        ])->where('draft_deleted', 0)->findOrEmpty();
        if ($common->isEmpty()) {
            return $page;
        }
        $commonData = self::decodeJson((string)($preview ? ($common['draft_data'] ?: $common['data']) : ($common['published_data'] ?: $common['data'])), []);
        $commonMeta = self::decodeJson((string)($preview ? ($common['draft_meta'] ?: $common['meta']) : ($common['published_meta'] ?: $common['meta'])), []);
        $metaPatch = (array)($overlay['page_meta_patch'] ?? []);
        if ($metaPatch && isset($commonMeta[0]) && is_array($commonMeta[0])) {
            $commonMeta[0]['content'] = array_replace((array)($commonMeta[0]['content'] ?? []), $metaPatch);
        }
        $page['data'] = self::encodeJson(self::mergeWidgetOverrides($commonData, (array)($overlay['widget_overrides'] ?? [])));
        $page['meta'] = self::encodeJson($commonMeta);
        $page['channel'] = $channel;
        return $page;
    }

    private static function mergeWidgetOverrides(array $widgets, array $overrides): array
    {
        foreach ($widgets as $index => $widget) {
            if (!is_array($widget)) {
                continue;
            }
            $id = (string)($widget['id'] ?? '');
            if ($id !== '' && isset($overrides[$id]) && is_array($overrides[$id])) {
                $patch = $overrides[$id];
                $widgets[$index] = array_replace($widget, $patch);
                if (isset($patch['content']) && is_array($patch['content'])) {
                    $widgets[$index]['content'] = array_replace((array)($widget['content'] ?? []), $patch['content']);
                }
                if (isset($patch['styles']) && is_array($patch['styles'])) {
                    $widgets[$index]['styles'] = array_replace((array)($widget['styles'] ?? []), $patch['styles']);
                }
            }
            if (isset($widgets[$index]['children']) && is_array($widgets[$index]['children'])) {
                $widgets[$index]['children'] = self::mergeWidgetOverrides($widgets[$index]['children'], $overrides);
            }
        }
        return $widgets;
    }

    public static function publishedHistory(int $tenantId, int $templateId): array
    {
        $template = self::getTemplate($tenantId, $templateId);
        return array_map(static function (array $snapshot): array {
            return [
                'id' => (string)($snapshot['id'] ?? ''),
                'published_at' => (int)($snapshot['published_at'] ?? 0),
                'page_count' => count((array)($snapshot['pages'] ?? [])),
            ];
        }, self::publishedHistoryPayload($template));
    }

    public static function rollback(int $tenantId, int $templateId, string $historyId): void
    {
        $template = self::getTemplate($tenantId, $templateId);
        $history = self::publishedHistoryPayload($template);
        $target = null;
        foreach ($history as $snapshot) {
            if (hash_equals((string)($snapshot['id'] ?? ''), $historyId)) {
                $target = $snapshot;
                break;
            }
        }
        if ($target === null || empty($target['pages']) || !is_array($target['pages'])) {
            throw new RuntimeException('发布历史不存在或已失效');
        }

        Db::transaction(function () use ($tenantId, $templateId, $template, $history, $target) {
            $allPages = DecoratePage::where([
                'tenant_id' => $tenantId,
                'template_id' => $templateId,
            ])->select();
            $targetPages = (array)$target['pages'];
            $targetIds = array_map(static fn (array $page): int => (int)($page['id'] ?? 0), $targetPages);
            $currentIds = array_map(static fn ($page): int => (int)$page['id'], $allPages->all());
            if (in_array(0, $targetIds, true) || array_diff($targetIds, $currentIds)) {
                throw new RuntimeException('历史版本包含已不存在的页面，无法回退');
            }

            $nextHistory = $history;
            $currentSnapshot = self::capturePublishedSnapshot($template, $allPages);
            if ($currentSnapshot !== null) {
                array_unshift($nextHistory, $currentSnapshot);
            }
            $nextHistory = array_slice($nextHistory, 0, 10);
            $template->save([
                'published_settings' => self::encodeJson((array)($target['settings'] ?? self::defaultSettings())),
                'published_pages' => self::encodeJson((array)($target['structure'] ?? [])),
                'published_history' => self::encodeJson($nextHistory),
                // A rollback changes only the formal snapshot. If the editor
                // already has a draft, keep that state visible and intact.
                'publish_status' => (string)($template['publish_status'] ?: 'published'),
            ]);
            foreach ($targetPages as $pageData) {
                DecoratePage::where([
                    'tenant_id' => $tenantId,
                    'template_id' => $templateId,
                    'id' => (int)$pageData['id'],
                ])->update([
                    // Keep draft identity and deletion state untouched. Formal
                    // routes read identity from published_pages, so rollback
                    // remains isolated from an editor's in-progress changes.
                    'published_data' => (string)($pageData['data'] ?? '[]'),
                    'published_meta' => (string)($pageData['meta'] ?? ''),
                ]);
            }
            if ((int)$template['is_active'] === 1) {
                self::syncLegacyPublishedData($tenantId, $templateId);
            }
        });
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
            'list' => DecorateTabbar::getTabbarLists($tenantId),
        ];
    }

    public static function activeMobileStyle(int $tenantId): array
    {
        $template = self::activeTemplate($tenantId);
        $settings = self::decodeJson($template['published_settings'], []);
        if (!empty($settings['mobile_style'])) {
            return $settings['mobile_style'];
        }
        $legacy = self::legacySettings($tenantId);
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

    /**
     * Mark a template as having unpublished draft changes.
     *
     * This is used by legacy page-save endpoints that now write only the
     * page draft snapshot. Resolving the template through the tenant boundary
     * keeps those endpoints from changing another tenant's template state.
     */
    public static function markDraft(int $tenantId, int $templateId): void
    {
        if ($templateId <= 0) {
            return;
        }
        self::getTemplate($tenantId, $templateId);
        DecorateTemplate::where([
            'tenant_id' => $tenantId,
            'id' => $templateId,
        ])->update(['publish_status' => 'draft']);
    }

    public static function exportPackage(int $tenantId, int $templateId): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('请开启 PHP zip 扩展后再导出模板');
        }
        $template = self::getTemplate($tenantId, $templateId);
        self::ensureTemplatePages((int)$template['id'], $tenantId);
        $pages = DecoratePage::where([
                'tenant_id' => $tenantId,
                'template_id' => (int)$template['id'],
            ])
            ->order(['terminal' => 'asc', 'is_home' => 'desc', 'sort' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray();

        $manifest = [
            'schema_version' => '1.0',
            'package_type' => 'likeadmin.decorate.template',
            'terminal' => 'mixed',
            'name' => (string)$template['name'],
            'export_time' => date('c'),
            'page_count' => count($pages),
        ];
        $payload = [
            'template' => [
                'name' => (string)$template['name'],
                'cover' => (string)$template['cover'],
                'settings' => self::decodeJson($template['draft_settings'] ?: $template['published_settings'], self::defaultSettings()),
            ],
            'pages' => array_map(function ($page) {
                unset($page['id'], $page['tenant_id'], $page['template_id'], $page['create_time'], $page['update_time'], $page['delete_time']);
                return $page;
            }, $pages),
        ];

        $dir = runtime_path() . 'decorate_export/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = 'decorate_template_' . date('YmdHis') . '.ladtpl.zip';
        $path = $dir . $filename;
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('模板包创建失败');
        }
        $zip->addFromString('manifest.json', self::encodeJson($manifest));
        $zip->addFromString('template.json', self::encodeJson($payload));
        $zip->addFromString('assets/.keep', '');
        $zip->close();

        return [
            'filename' => $filename,
            'mime' => 'application/zip',
            'content' => base64_encode((string)file_get_contents($path)),
        ];
    }

    public static function importPackage(int $tenantId, string $base64, string $filename = ''): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('请开启 PHP zip 扩展后再导入模板');
        }
        if ($base64 === '') {
            throw new RuntimeException('请上传模板包');
        }
        $raw = base64_decode(preg_replace('/^data:.*?;base64,/', '', $base64), true);
        if ($raw === false || strlen($raw) > 20 * 1024 * 1024) {
            throw new RuntimeException('模板包无效或超过20MB');
        }
        $dir = runtime_path() . 'decorate_import/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($path, $raw);

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('模板包读取失败');
        }
        self::assertSafeTemplateZip($zip);
        $manifest = json_decode((string)$zip->getFromName('manifest.json'), true);
        $payload = json_decode((string)$zip->getFromName('template.json'), true);
        $zip->close();

        if (($manifest['package_type'] ?? '') !== 'likeadmin.decorate.template' || ($manifest['schema_version'] ?? '') !== '1.0') {
            throw new RuntimeException('模板包版本不支持');
        }
        if (!is_array($payload) || empty($payload['template']) || !is_array($payload['pages'] ?? null)) {
            throw new RuntimeException('模板包数据不完整');
        }

        $allowedWidgets = self::allowedWidgetNames();
        foreach ((array)$payload['pages'] as $page) {
            $terminal = (string)($page['terminal'] ?? '');
            if (!in_array($terminal, [self::TERMINAL_MOBILE, self::TERMINAL_PC], true)) {
                throw new RuntimeException('模板包终端无效');
            }
            foreach (self::decodeJson((string)($page['data'] ?? $page['draft_data'] ?? '[]'), []) as $widget) {
                $name = (string)($widget['name'] ?? '');
                if ($name !== '' && !in_array($name, $allowedWidgets, true)) {
                    throw new RuntimeException('模板包包含未知组件：' . $name);
                }
            }
        }

        return Db::transaction(function () use ($tenantId, $payload, $filename) {
            $settings = (array)($payload['template']['settings'] ?? self::defaultSettings());
            $template = DecorateTemplate::create([
                'tenant_id' => $tenantId,
                'name' => trim((string)($payload['template']['name'] ?? '导入模板')) ?: ('导入模板 ' . date('mdHis')),
                'cover' => (string)($payload['template']['cover'] ?? ''),
                'status' => 1,
                'is_active' => 0,
                'publish_status' => 'draft',
                'draft_settings' => self::encodeJson(array_replace_recursive(self::defaultSettings(), $settings)),
                'published_settings' => self::encodeJson(array_replace_recursive(self::defaultSettings(), $settings)),
            ]);

            foreach ((array)$payload['pages'] as $page) {
                $pageCode = self::normalizePageCode((string)($page['page_code'] ?? 'page_' . uniqid()));
                DecoratePage::create([
                    'tenant_id' => $tenantId,
                    'template_id' => (int)$template['id'],
                    'terminal' => (string)($page['terminal'] ?? self::TERMINAL_MOBILE),
                    'channel' => (string)($page['channel'] ?? self::CHANNEL_COMMON),
                    'page_code' => $pageCode,
                    'page_type' => (string)($page['page_type'] ?? 'custom'),
                    'route_path' => (string)($page['route_path'] ?? ''),
                    'is_home' => (int)($page['is_home'] ?? 0),
                    'is_system' => (int)($page['is_system'] ?? 0),
                    'status' => (int)($page['status'] ?? 1),
                    'sort' => (int)($page['sort'] ?? 0),
                    'type' => (int)($page['type'] ?? 0),
                    'name' => (string)($page['name'] ?? '装修页面'),
                    'data' => (string)($page['data'] ?? $page['draft_data'] ?? '[]'),
                    'meta' => (string)($page['meta'] ?? $page['draft_meta'] ?? ''),
                    'draft_data' => (string)($page['draft_data'] ?? $page['data'] ?? '[]'),
                    'draft_meta' => (string)($page['draft_meta'] ?? $page['meta'] ?? ''),
                    'published_data' => (string)($page['published_data'] ?? $page['data'] ?? '[]'),
                    'published_meta' => (string)($page['published_meta'] ?? $page['meta'] ?? ''),
                ]);
            }
            self::ensureTemplatePages((int)$template['id'], $tenantId);
            return $template->toArray();
        });
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

    private static function publishedHistoryPayload(DecorateTemplate $template): array
    {
        $history = self::decodeJson((string)($template['published_history'] ?? ''), []);
        return is_array($history) ? array_values(array_filter($history, 'is_array')) : [];
    }

    private static function publishedPageStructure(iterable $pages): array
    {
        $result = [];
        foreach ($pages as $page) {
            if ((int)($page['draft_deleted'] ?? 0) === 1) {
                continue;
            }
            $result[] = [
                'id' => (int)$page['id'],
                'terminal' => (string)$page['terminal'],
                'channel' => (string)$page['channel'],
                'page_code' => (string)$page['page_code'],
                'page_type' => (string)$page['page_type'],
                'route_path' => (string)$page['route_path'],
                'is_home' => (int)$page['is_home'],
                'is_system' => (int)$page['is_system'],
                'status' => (int)$page['status'],
                'sort' => (int)$page['sort'],
                'type' => (int)$page['type'],
                'name' => (string)$page['name'],
            ];
        }
        return $result;
    }

    private static function capturePublishedSnapshot(DecorateTemplate $template, iterable $pages): ?array
    {
        $structure = self::decodeJson((string)($template['published_pages'] ?? ''), []);
        if (!is_array($structure) || empty($structure)) {
            return null;
        }
        $byId = [];
        foreach ($pages as $page) {
            $byId[(int)$page['id']] = $page;
        }
        $snapshotPages = [];
        foreach ($structure as $item) {
            $page = $byId[(int)($item['id'] ?? 0)] ?? null;
            if ($page === null) {
                return null;
            }
            $snapshotPages[] = array_merge($item, [
                'data' => (string)($page['published_data'] ?: $page['data'] ?: '[]'),
                'meta' => (string)($page['published_meta'] ?: $page['meta'] ?: ''),
            ]);
        }
        return [
            'id' => bin2hex(random_bytes(8)),
            'published_at' => time(),
            'settings' => self::decodeJson((string)($template['published_settings'] ?? ''), self::defaultSettings()),
            'structure' => $structure,
            'pages' => $snapshotPages,
        ];
    }

    private static function findPublishedPage(DecorateTemplate $template, string $terminal, string $pageCode, string $channel): ?array
    {
        $pages = self::decodeJson((string)($template['published_pages'] ?? ''), []);
        if (is_array($pages) && !empty($pages)) {
            $matches = array_values(array_filter($pages, static function (array $page) use ($terminal, $pageCode, $channel): bool {
                return (string)($page['terminal'] ?? '') === $terminal
                    && (string)($page['page_code'] ?? '') === $pageCode
                    && in_array((string)($page['channel'] ?? self::CHANNEL_COMMON), [$channel, self::CHANNEL_COMMON], true);
            }));
            usort($matches, static fn (array $a, array $b): int => ((string)($a['channel'] ?? '') === $channel ? -1 : 1));
            if (!empty($matches)) {
                return $matches[0];
            }
            // A snapshot can be present while an older publish omitted a page
            // or channel entry. Continue to the immutable page columns instead
            // of returning an empty result and triggering the legacy article
            // fallback in the mobile client.
        }

        // Existing installations may not have a snapshot until their first
        // explicit publish; retain the previous published-field behavior.
        $page = DecoratePage::where([
            'tenant_id' => (int)$template['tenant_id'],
            'template_id' => (int)$template['id'],
            'terminal' => $terminal,
            'page_code' => $pageCode,
            'channel' => $channel,
        ])->where('draft_deleted', 0)->findOrEmpty();
        if ($page->isEmpty()) {
            $page = DecoratePage::where([
                'tenant_id' => (int)$template['tenant_id'],
                'template_id' => (int)$template['id'],
                'terminal' => $terminal,
                'page_code' => $pageCode,
                'channel' => self::CHANNEL_COMMON,
            ])->where('draft_deleted', 0)->findOrEmpty();
        }
        return $page->isEmpty() ? null : $page->toArray();
    }

    private static function activeTemplate(int $tenantId): DecorateTemplate
    {
        return self::getTemplate($tenantId, 0);
    }

    private static function decorateContext(int $tenantId, string $terminal, array $context = []): array
    {
        $request = request();
        $userId = (int)($context['user_id'] ?? $request->userId ?? 0);
        $membership = $userId > 0 ? MembershipService::status($tenantId, $userId) : ['member_status' => MembershipService::MEMBER_NONE];
        return array_replace([
            'tenant_id' => $tenantId,
            'terminal' => $terminal,
            'path' => (string)($request->pathinfo() ?? ''),
            'query' => $request->get(),
            'user_id' => $userId,
            'user_bucket' => $userId > 0 ? crc32((string)$userId) % 100 : 0,
            'login_status' => $userId > 0 ? 'logged_in' : 'guest',
            'membership_status' => (string)($membership['member_status'] ?? MembershipService::MEMBER_NONE),
        ], $context);
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
        $settings = self::legacySettings($tenantId);
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
            4 => ['pc_home', self::TERMINAL_PC, 'pc_home', '/', 1, 1, 'PC首页', ['pc-sidebar', 'pc-home-hero-grid', 'pc-tool-carousel', 'pc-case-feed']],
        ];

        foreach ($map as $type => $item) {
            [$pageCode, $terminal, $pageType, $routePath, $isHome, $isSystem, $name, $widgets] = $item;
            $page = DecoratePage::where([
                'tenant_id' => $tenantId,
                'template_id' => $templateId,
                'page_code' => $pageCode,
            ])->findOrEmpty();
            if (!$page->isEmpty()) {
                self::ensurePageWidgets($page, $widgets);
                continue;
            }

            // Only migrate an unowned legacy page for this tenant. A page from
            // another template must never be adopted accidentally.
            $legacy = DecoratePage::where([
                'tenant_id' => $tenantId,
                'template_id' => 0,
                'type' => $type,
            ])->findOrEmpty();
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
        $pages = DecoratePage::where([
            'tenant_id' => $tenantId,
            'template_id' => $templateId,
        ])->select();
        foreach ($pages as $page) {
            $page->save([
                'data' => $page['published_data'] ?: $page['draft_data'] ?: $page['data'],
                'meta' => $page['published_meta'] ?: $page['draft_meta'] ?: $page['meta'],
            ]);
        }
    }

    private static function legacySettings(int $tenantId = 0): array
    {
        // Legacy style/tabbar data is tenant-scoped. Tenant 0 is reserved for
        // platform defaults used when seeding a new tenant.
        $stylePage = DecoratePage::where([
            'tenant_id' => $tenantId,
            'type' => 5,
        ])->findOrEmpty();
        if ($stylePage->isEmpty() && $tenantId > 0) {
            $stylePage = DecoratePage::withoutGlobalScope()->where(['tenant_id' => 0, 'type' => 5])->findOrEmpty();
        }
        $tabbar = DecorateTabbar::getTabbarLists($tenantId);
        if (empty($tabbar) && $tenantId > 0) {
            $tabbar = DecorateTabbar::getTabbarLists(0);
        }
        return [
            'mobile_style' => $stylePage->isEmpty() ? [] : self::decodeJson($stylePage['data'], []),
            'mobile_tabbar' => [
                'style' => ConfigService::get('tabbar', 'style', config('project.decorate.tabbar_style')),
                'list' => $tabbar,
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
                'show_nav' => 1,
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
            return self::defaultWidgetData($name);
        }, $widgetNames);
    }

    private static function ensurePageWidgets(DecoratePage $page, array $widgetNames): void
    {
        $updates = [];
        // Normalization belongs to the editable draft. The published snapshot
        // must remain immutable until an explicit template publish operation.
        foreach (['draft_data'] as $field) {
            $rawDraft = trim((string)($page[$field] ?? ''));
            if ($rawDraft === '') {
                // Older rows may only have data/meta. Seed the draft from the
                // legacy payload, preferring the formal payload when the old
                // data column is empty. Never write back to published_data.
                $rawDraft = trim((string)($page['data'] ?? ''));
                if ($rawDraft === '') {
                    $rawDraft = trim((string)($page['published_data'] ?? ''));
                }
                if ($rawDraft === '') {
                    $rawDraft = self::encodeJson(self::generatePageData($widgetNames));
                }
                $updates[$field] = $rawDraft;
            }
        }
        if (!empty($updates)) {
            $page->save($updates);
            $templateId = (int)($page['template_id'] ?? 0);
            $tenantId = (int)($page['tenant_id'] ?? 0);
            if ($templateId > 0 && $tenantId > 0) {
                DecorateTemplate::where([
                    'tenant_id' => $tenantId,
                    'id' => $templateId,
                ])->update(['publish_status' => 'draft']);
            }
        }
    }

    private static function defaultWidgetData(string $name): array
    {
        $widget = [
            'id' => uniqid('diy_', true),
            'title' => $name,
            'name' => $name,
            'content' => ['enabled' => 1],
            'styles' => [],
        ];

        if ($name === 'pc-banner') {
            $widget['title'] = '首页轮播图';
            $widget['content'] = ['enabled' => 1, 'data' => [
                ['image' => '', 'name' => 'AI 创作工作台', 'description' => '图片、视频、数字人与工具入口统一聚合', 'is_show' => '1', 'link' => ['path' => '/ai/create']],
                ['image' => '', 'name' => '热门 AI 工具', 'description' => '精选高频创作能力，快速进入创作流程', 'is_show' => '1', 'link' => ['path' => '/ai/tools']],
                ['image' => '', 'name' => '灵感案例', 'description' => '查看案例并一键做同款', 'is_show' => '1', 'link' => ['path' => '/ai']],
            ]];
            $widget['styles'] = [
                'layout' => ['mode' => 'flow', 'x' => 60, 'y' => 60, 'w' => 1120, 'h' => 360, 'z' => 1, 'locked' => false, 'hidden' => false, 'snap' => 8],
            ];
        }
        if ($name === 'pc-sidebar') {
            $widget['title'] = 'PC侧栏';
            $widget['content'] = [
                'enabled' => 1,
                'logo' => 'LA',
                'items' => [
                    ['key' => 'inspiration', 'title' => '灵感', 'path' => '/ai'],
                    ['key' => 'create', 'title' => '创作', 'path' => '/ai/create'],
                    ['key' => 'short-drama', 'title' => '短剧', 'path' => ''],
                    ['key' => 'avatar', 'title' => '数字人', 'path' => '/ai/avatar'],
                    ['key' => 'tools', 'title' => '工具', 'path' => '/ai/tools'],
                    ['key' => 'assets', 'title' => '资产', 'path' => '/ai/assets'],
                ],
                'footer' => [
                    ['key' => 'vip', 'title' => '开通会员', 'path' => '/ai/membership'],
                    ['key' => 'user', 'title' => '个人中心', 'path' => '/user/info'],
                    ['key' => 'api', 'title' => 'API', 'path' => ''],
                    ['key' => 'notice', 'title' => '消息', 'path' => ''],
                    ['key' => 'mobile', 'title' => '手机', 'path' => ''],
                    ['key' => 'language', 'title' => '语言', 'path' => ''],
                ],
            ];
            $widget['styles'] = [
                'layout' => ['mode' => 'flow', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 900, 'z' => 1, 'locked' => false, 'hidden' => false, 'snap' => 8],
            ];
        }
        if ($name === 'pc-home-hero-grid') {
            $widget['title'] = '首页功能入口';
            $widget['content'] = [
                'enabled' => 1,
                'banners' => [
                    ['title' => 'AI 创作介绍', 'description' => '模型、应用、资产与灵感统一入口', 'image' => '', 'link' => ['path' => '/ai/tools']],
                ],
                'features' => [
                    ['title' => 'AI TV', 'description' => '全新功能，助力短剧创作。', 'image' => '', 'link' => ['path' => '/ai/create']],
                    ['title' => '视频生成', 'description' => '创意视频，一键生成', 'image' => '', 'link' => ['path' => '/ai/create?type=video']],
                    ['title' => '图片生成', 'description' => '智能绘制，即刻成图', 'image' => '', 'link' => ['path' => '/ai/create?type=image']],
                ],
            ];
            $widget['styles'] = [
                'layout' => ['mode' => 'flow', 'x' => 130, 'y' => 42, 'w' => 1884, 'h' => 240, 'z' => 2, 'locked' => false, 'hidden' => false, 'snap' => 8],
            ];
        }
        if ($name === 'pc-tool-config') {
            $widget['title'] = '工具配置';
            $widget['content'] = ['enabled' => 1, 'data' => []];
            $widget['styles'] = [
                'layout' => ['mode' => 'flow', 'x' => 60, 'y' => 980, 'w' => 1120, 'h' => 220, 'z' => 1, 'locked' => false, 'hidden' => true, 'snap' => 8],
            ];
        }
        if ($name === 'pc-hero-entry') {
            $widget['title'] = 'AI首页入口';
            $widget['content'] = [
                'enabled' => 1,
                'title' => 'AI 创作工作台',
                'description' => '图片、视频、数字人与工具入口统一聚合',
                'primary_text' => '开始创作',
                'primary_link' => ['path' => '/ai/create'],
                'secondary_text' => '查看工具',
                'secondary_link' => ['path' => '/ai/tools'],
            ];
            $widget['styles'] = [
                'layout' => ['mode' => 'flow', 'x' => 60, 'y' => 440, 'w' => 1120, 'h' => 220, 'z' => 1, 'locked' => false, 'hidden' => false, 'snap' => 8],
            ];
        }
        if ($name === 'pc-tool-carousel') {
            $widget['title'] = '工具轮播';
            $widget['content'] = [
                'enabled' => 1,
                'title' => '热门工具',
                'source_key' => 'ai_tools',
                'source_params' => ['limit' => 12],
                'data' => [],
            ];
            $widget['styles'] = [
                'layout' => ['mode' => 'flow', 'x' => 60, 'y' => 700, 'w' => 1120, 'h' => 280, 'z' => 1, 'locked' => false, 'hidden' => false, 'snap' => 8],
            ];
        }
        if ($name === 'pc-case-feed') {
            $widget['title'] = '案例流';
            $widget['content'] = [
                'enabled' => 1,
                'title' => '灵感案例',
                'source_key' => 'image_cases',
                'source_params' => ['limit' => 20],
                'tabs' => [
                    ['name' => '图片', 'source_key' => 'image_cases'],
                    ['name' => '视频', 'source_key' => 'video_cases'],
                    ['name' => '数字人', 'source_key' => 'digital_human_cases'],
                ],
            ];
            $widget['styles'] = [
                'layout' => ['mode' => 'flow', 'x' => 60, 'y' => 1020, 'w' => 1120, 'h' => 420, 'z' => 1, 'locked' => false, 'hidden' => false, 'snap' => 8],
            ];
        }

        return $widget;
    }

    private static function allowedWidgetNames(): array
    {
        return [
            'search', 'fixed-search', 'banner', 'carousel-search', 'nav', 'news', 'user-info', 'my-service', 'user-banner',
            'customer-service', 'middle-banner', 'title-bar', 'notice', 'list-nav',
            'divider', 'image-hotspot', 'image-showcase', 'page-meta', 'pc-banner', 'pc-tool-config',
            'pc-hero-entry', 'pc-tool-carousel', 'pc-case-feed', 'pc-rich-media',
            'pc-sidebar', 'pc-home-hero-grid', 'pc-text', 'pc-button',
            'rich-text', 'spacer', 'audio-player', 'button-group', 'tabs', 'video-player', 'floating-action', 'popup-promo',
            'section', 'grid', 'stack', 'horizontal-scroll', 'masonry', 'card',
            'image-nav', 'magic-grid', 'tutorial-carousel', 'case-feed', 'app-collection',
            'creation-entry-grid', 'recent-tasks', 'media-card', 'user-hero', 'membership-card',
            'user-stats', 'account-quota', 'asset-entries', 'service-grid',
        ];
    }

    private static function assertSafeTemplateZip(ZipArchive $zip): void
    {
        $totalSize = 0;
        $required = ['manifest.json' => false, 'template.json' => false];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string)($stat['name'] ?? '');
            $totalSize += (int)($stat['size'] ?? 0);
            if ($name === '' || str_starts_with($name, '/') || preg_match('/^[a-zA-Z]:[\/\\\\]/', $name) || str_contains($name, '..')) {
                throw new RuntimeException('模板包包含非法路径');
            }
            if (!str_starts_with($name, 'assets/') && !array_key_exists($name, $required)) {
                throw new RuntimeException('模板包包含未知文件');
            }
            if (array_key_exists($name, $required)) {
                $required[$name] = true;
            }
        }
        if ($totalSize > 20 * 1024 * 1024 || in_array(false, $required, true)) {
            throw new RuntimeException('模板包内容无效');
        }
    }

    private static function normalizePageCode(string $code): string
    {
        $code = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $code));
        return trim($code, '_') ?: 'page_' . time();
    }

    private static function isListArray(array $value): bool
    {
        $index = 0;
        foreach ($value as $key => $_item) {
            if ($key !== $index++) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validate the persisted page contract before accepting a draft or
     * publishing it. Unknown widgets are retained for legacy compatibility;
     * the frontend renders them as a non-executable placeholder.
     */
    private static function validatePagePayload(string $dataJson, string $metaJson): void
    {
        if (strlen($dataJson) > 5 * 1024 * 1024 || strlen($metaJson) > 512 * 1024) {
            throw new RuntimeException('装修数据超过大小限制');
        }

        $data = json_decode($dataJson === '' ? '[]' : $dataJson, true);
        if (!is_array($data) || !self::isListArray($data)) {
            throw new RuntimeException('页面组件数据格式无效');
        }
        self::validateWidgets($data);

        if ($metaJson !== '') {
            $meta = json_decode($metaJson, true);
            if (!is_array($meta) || (!self::isListArray($meta) && !isset($meta['content']))) {
                throw new RuntimeException('页面设置格式无效');
            }
            $metaItems = self::isListArray($meta) ? $meta : [$meta];
            foreach ($metaItems as $item) {
                if (!is_array($item)) {
                    throw new RuntimeException('页面设置格式无效');
                }
                if (isset($item['content']) && !is_array($item['content'])) {
                    throw new RuntimeException('页面设置内容格式无效');
                }
                if (isset($item['styles']) && !is_array($item['styles'])) {
                    throw new RuntimeException('页面设置样式格式无效');
                }
            }
        }
    }

    /**
     * Validate recursive mobile layout payloads without rejecting legacy or
     * future widget names. Unknown widgets remain inert client placeholders.
     */
    private static function validateWidgets(array $widgets, int $depth = 0, array &$ids = []): void
    {
        if ($depth > 4) {
            throw new RuntimeException('组件嵌套层级不能超过4层');
        }
        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                throw new RuntimeException('页面组件数据格式无效');
            }
            $name = trim((string)($widget['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('页面组件名称不能为空');
            }
            if (isset($widget['id']) && !is_scalar($widget['id'])) {
                throw new RuntimeException('组件标识格式无效');
            }
            $id = trim((string)($widget['id'] ?? ''));
            if ($id !== '') {
                if (isset($ids[$id])) {
                    throw new RuntimeException('组件标识不能重复');
                }
                $ids[$id] = true;
            }
            $content = $widget['content'] ?? [];
            if (!is_array($content)) {
                throw new RuntimeException('组件内容格式无效');
            }
            if (isset($widget['styles']) && !is_array($widget['styles'])) {
                throw new RuntimeException('组件样式格式无效');
            }
            foreach (['item_gap', 'padding_top', 'padding_bottom', 'padding_horizontal', 'radius_top', 'radius_right', 'radius_bottom', 'radius_left', 'opacity'] as $styleField) {
                if (isset($widget['styles'][$styleField]) && (!is_numeric($widget['styles'][$styleField]) || (float)$widget['styles'][$styleField] < 0 || (float)$widget['styles'][$styleField] > 999)) {
                    throw new RuntimeException('组件样式数值无效');
                }
            }
            if (isset($content['data_mode']) && !in_array((string)$content['data_mode'], ['manual', 'source', 'hybrid'], true)) {
                throw new RuntimeException('组件数据模式无效');
            }
            foreach (['data', 'fallback_data'] as $field) {
                if (isset($content[$field]) && !is_array($content[$field])) {
                    throw new RuntimeException('组件列表数据格式无效');
                }
            }
            if (isset($content['source_params']) && !is_array($content['source_params'])) {
                throw new RuntimeException('组件数据源参数格式无效');
            }
            foreach (['video_url', 'audio_url'] as $urlField) {
                $url = trim((string)($content[$urlField] ?? ''));
                if ($url !== '' && preg_match('/^(?:javascript|data):/i', $url)) {
                    throw new RuntimeException('媒体地址协议无效');
                }
            }
            if ($name === 'rich-text' && preg_match('/<\s*(?:script|iframe|object|embed)\b|\son\w+\s*=/i', (string)($content['html'] ?? ''))) {
                throw new RuntimeException('富文本包含不安全标签');
            }
            if ($name === 'image-hotspot' && isset($content['areas'])) {
                if (!is_array($content['areas']) || count($content['areas']) > 30) {
                    throw new RuntimeException('图片热区配置无效');
                }
                foreach ($content['areas'] as $area) {
                    if (!is_array($area)) {
                        throw new RuntimeException('图片热区格式无效');
                    }
                    foreach (['left', 'top', 'width', 'height'] as $coordinate) {
                        if (!isset($area[$coordinate]) || !is_numeric($area[$coordinate]) || (float)$area[$coordinate] < 0 || (float)$area[$coordinate] > 100) {
                            throw new RuntimeException('图片热区坐标无效');
                        }
                    }
                }
            }
            if (isset($widget['children'])) {
                if (!in_array($name, ['section', 'grid', 'stack', 'horizontal-scroll', 'masonry', 'card'], true)) {
                    throw new RuntimeException('仅布局容器支持子组件');
                }
                if (!is_array($widget['children']) || !self::isListArray($widget['children'])) {
                    throw new RuntimeException('容器子组件格式无效');
                }
                self::validateWidgets($widget['children'], $depth + 1, $ids);
            }
        }
    }

    private static function assertExpectedUpdateTime(DecoratePage $page, array $params): void
    {
        $expected = $params['expected_update_time'] ?? $params['update_time'] ?? null;
        if ($expected === null || $expected === '') {
            return;
        }
        $actual = (string)($page['update_time'] ?? '');
        if ($actual !== (string)$expected) {
            throw new RuntimeException('页面已被其他人修改，请刷新后再保存');
        }
    }

    private static function assertTerminal(string $terminal): void
    {
        if (!in_array($terminal, [self::TERMINAL_MOBILE, self::TERMINAL_PC], true)) {
            throw new RuntimeException('页面终端无效');
        }
    }

    private static function assertPageTerminal(DecoratePage $page, array $params): void
    {
        if (!array_key_exists('terminal', $params) || $params['terminal'] === '') {
            return;
        }
        $terminal = (string)$params['terminal'];
        self::assertTerminal($terminal);
        if ((string)$page['terminal'] !== $terminal) {
            throw new RuntimeException('页面终端不匹配');
        }
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
