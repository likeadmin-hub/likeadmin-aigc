<?php

namespace app\common\service\decorate;

use app\common\model\article\Article;
use app\common\model\ai\AiAppTask;
use app\common\model\user\User;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\AppFrontendManifestService;
use app\common\service\case_gallery\CaseGalleryService;
use app\common\service\membership\MembershipService;

class DecorateDataSourceService
{
    public static function catalog(int $tenantId): array
    {
        return [
            ['key' => 'article', 'name' => '文章资讯', 'terminal' => ['mobile', 'pc'], 'params' => ['limit', 'sort']],
            ['key' => 'ai_tools', 'name' => 'AI工具入口', 'terminal' => ['mobile', 'pc'], 'params' => ['limit']],
            ['key' => 'image_cases', 'name' => '图片案例', 'terminal' => ['mobile', 'pc'], 'params' => ['limit']],
            ['key' => 'video_cases', 'name' => '视频案例', 'terminal' => ['mobile', 'pc'], 'params' => ['limit']],
            ['key' => 'digital_human_cases', 'name' => '数字人案例', 'terminal' => ['mobile', 'pc'], 'params' => ['limit']],
            ['key' => 'app_entries', 'name' => '应用入口', 'terminal' => ['mobile', 'pc'], 'params' => ['terminal']],
            ['key' => 'assets', 'name' => '资产/作品', 'terminal' => ['mobile', 'pc'], 'params' => ['limit']],
            ['key' => 'recent_tasks', 'name' => '最近创作', 'terminal' => ['mobile'], 'params' => ['limit']],
            ['key' => 'user_profile', 'name' => '用户资料', 'terminal' => ['mobile'], 'params' => []],
            ['key' => 'membership_summary', 'name' => '会员摘要', 'terminal' => ['mobile'], 'params' => []],
            ['key' => 'account_summary', 'name' => '账户额度', 'terminal' => ['mobile'], 'params' => []],
            ['key' => 'user_assets', 'name' => '用户资产', 'terminal' => ['mobile'], 'params' => ['limit']],
        ];
    }

    public static function applyToPage(array $page, array $context = []): array
    {
        $meta = self::decodeJson((string)($page['meta'] ?? ''), []);
        $pageVisibility = (array)($page['visibility'] ?? $meta[0]['content']['visibility'] ?? []);
        if (!self::passesVisibility($pageVisibility, $context)) {
            $page['data'] = '[]';
            $page['resolved_sources'] = [];
            return $page;
        }

        $data = self::decodeJson((string)($page['data'] ?? '[]'), []);
        $filtered = [];
        $refs = [];
        foreach ($data as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            // Historical published snapshots may contain only `{name, title,
            // content:{}}`. The editor fills defaults client-side, but public
            // H5/mini-program requests must resolve the same standard source
            // before collecting refs; otherwise widgets such as news render
            // as empty in the published app.
            $widget = self::normalizeWidgetRuntime($widget);
            if (!self::passesVisibility((array)($widget['visibility'] ?? $widget['content']['visibility'] ?? []), $context)) {
                continue;
            }
            $filteredWidget = self::filterWidget($widget, $context);
            $filtered[] = $filteredWidget;
            self::collectWidgetRefs($filteredWidget, $refs, $context);
        }

        $page['data'] = self::encodeJson($filtered);
        $page['resolved_sources'] = self::resolveRefs((int)($context['tenant_id'] ?? 0), array_values($refs), $context);
        return $page;
    }

    public static function passesVisibility(array $visibility, array $context): bool
    {
        $rules = (array)($visibility['rules'] ?? []);
        if (!$rules) {
            return true;
        }
        $mode = (string)($visibility['mode'] ?? 'all');
        $results = array_map(fn($rule) => self::matchRule((array)$rule, $context), $rules);
        return $mode === 'any' ? in_array(true, $results, true) : !in_array(false, $results, true);
    }

    public static function resolve(string $key, array $params, int $tenantId, array $context = []): array
    {
        $limit = max(1, min(50, (int)($params['limit'] ?? 12)));
        return match ($key) {
            'article' => self::articles($tenantId, $limit, (string)($params['sort'] ?? 'new')),
            'image_cases' => self::cases($tenantId, ['aigc_image'], ['limit' => $limit, 'media_type' => 'image']),
            'video_cases' => self::cases($tenantId, ['aigc_video'], ['limit' => $limit, 'media_type' => 'video']),
            'digital_human_cases' => self::cases($tenantId, ['aigc_digital_human', 'image_human'], ['limit' => $limit]),
            'app_entries' => AppFrontendManifestService::tenantEntries($tenantId, (string)($params['terminal'] ?? $context['terminal'] ?? 'pc')),
            'ai_tools' => self::aiTools($tenantId, $limit),
            'assets' => [],
            'recent_tasks' => self::recentTasks($tenantId, (int)($context['user_id'] ?? 0), $limit),
            'user_profile' => self::userProfile($tenantId, (int)($context['user_id'] ?? 0)),
            'membership_summary' => self::membershipSummary($tenantId, (int)($context['user_id'] ?? 0)),
            'account_summary' => self::accountSummary($tenantId, (int)($context['user_id'] ?? 0)),
            'user_assets' => self::userAssets($tenantId, (int)($context['user_id'] ?? 0), $limit),
            default => [],
        };
    }

    private static function resolveRefs(int $tenantId, array $refs, array $context): array
    {
        $resolved = [];
        foreach ($refs as $ref) {
            $key = (string)($ref['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $row = [
                'key' => $key,
                'params' => (array)($ref['params'] ?? []),
                'items' => self::resolve($key, (array)($ref['params'] ?? []), $tenantId, $context),
            ];
            $resolved[self::sourceCacheKey($ref)] = $row;
            // Keep the legacy key alias for existing clients, but do not let a
            // later widget with different params silently replace the first row.
            if (!array_key_exists($key, $resolved)) {
                $resolved[$key] = $row;
            }
        }
        return $resolved;
    }

    private static function widgetSourceRef(array $widget): array
    {
        $content = (array)($widget['content'] ?? []);
        $source = (array)($content['source'] ?? []);
        $key = (string)($content['source_key'] ?? $source['key'] ?? '');
        if ($key === '') {
            return [];
        }
        return [
            'key' => $key,
            'params' => (array)($content['source_params'] ?? $source['params'] ?? []),
        ];
    }

    private static function collectWidgetRefs(array $widget, array &$refs, array $context): void
    {
        $ref = self::widgetSourceRef($widget);
        if ($ref) {
            $refs[self::sourceCacheKey($ref)] = $ref;
        }
        foreach ((array)($widget['children'] ?? []) as $child) {
            if (is_array($child) && self::passesVisibility((array)($child['visibility'] ?? $child['content']['visibility'] ?? []), $context)) {
                self::collectWidgetRefs($child, $refs, $context);
            }
        }
    }

    private static function filterWidget(array $widget, array $context): array
    {
        $widget = self::normalizeWidgetRuntime($widget);
        if (!isset($widget['children']) || !is_array($widget['children'])) {
            return $widget;
        }
        $children = [];
        foreach ($widget['children'] as $child) {
            if (!is_array($child) || !self::passesVisibility((array)($child['visibility'] ?? $child['content']['visibility'] ?? []), $context)) {
                continue;
            }
            $children[] = self::filterWidget($child, $context);
        }
        $widget['children'] = $children;
        return $widget;
    }

    /**
     * Fill only the non-destructive runtime defaults needed for data-source
     * resolution. Draft/published JSON remains unchanged in storage.
     */
    private static function normalizeWidgetRuntime(array $widget): array
    {
        $name = (string)($widget['name'] ?? '');
        $content = (array)($widget['content'] ?? []);
        $sourceDefaults = [
            'news' => 'article',
            'case-feed' => 'image_cases',
            'app-collection' => 'app_entries',
            'creation-entry-grid' => 'ai_tools',
            'recent-tasks' => 'recent_tasks',
            'user-hero' => 'user_profile',
            'user-stats' => 'user_profile',
            'membership-card' => 'membership_summary',
            'account-quota' => 'account_summary',
            'asset-entries' => 'user_assets',
        ];
        if (($content['source_key'] ?? '') === '' && isset($sourceDefaults[$name])) {
            $content['source_key'] = $sourceDefaults[$name];
            if (!array_key_exists('data_mode', $content)) {
                $content['data_mode'] = 'hybrid';
            }
            if (!isset($content['source_params']) || !is_array($content['source_params'])) {
                $content['source_params'] = [];
            }
        }
        if (!array_key_exists('enabled', $content)) {
            $content['enabled'] = 1;
        }
        $widget['content'] = $content;
        return $widget;
    }

    private static function sourceCacheKey(array $ref): string
    {
        return md5(json_encode($ref, JSON_UNESCAPED_UNICODE));
    }

    private static function matchRule(array $rule, array $context): bool
    {
        $field = (string)($rule['field'] ?? '');
        $operator = (string)($rule['operator'] ?? 'eq');
        $expected = $rule['value'] ?? null;
        $actual = self::contextValue($field, $context);

        if ($field === 'time_range') {
            $now = time();
            $start = strtotime((string)($rule['start'] ?? '')) ?: 0;
            $end = strtotime((string)($rule['end'] ?? '')) ?: 0;
            return (!$start || $now >= $start) && (!$end || $now <= $end);
        }
        if ($field === 'user_bucket') {
            $bucket = (int)($context['user_bucket'] ?? 0);
            return $bucket >= (int)($rule['min'] ?? 0) && $bucket <= (int)($rule['max'] ?? 99);
        }

        return match ($operator) {
            'neq' => (string)$actual !== (string)$expected,
            'in' => in_array((string)$actual, array_map('strval', (array)$expected), true),
            'contains' => str_contains((string)$actual, (string)$expected),
            default => (string)$actual === (string)$expected,
        };
    }

    private static function contextValue(string $field, array $context)
    {
        if (str_starts_with($field, 'query.')) {
            $name = substr($field, 6);
            return $context['query'][$name] ?? '';
        }
        return $context[$field] ?? '';
    }

    private static function articles(int $tenantId, int $limit, string $sort): array
    {
        $orderRaw = $sort === 'hot' ? 'click_actual + click_virtual desc, id desc' : 'id desc';
        $query = Article::withoutGlobalScope()
            ->field('id,title,desc,abstract,image,author,click_virtual,click_actual,create_time')
            ->where(['is_show' => 1, 'tenant_id' => $tenantId])
            ->orderRaw($orderRaw)
            ->limit($limit);
        $articles = $query->append(['click'])->hidden(['click_virtual', 'click_actual'])->select()->toArray();

        if ($articles === [] && $tenantId > 0) {
            $articles = Article::withoutGlobalScope()
                ->field('id,title,desc,abstract,image,author,click_virtual,click_actual,create_time')
                ->where(['is_show' => 1, 'tenant_id' => 0])
                ->orderRaw($orderRaw)
                ->limit($limit)
                ->append(['click'])
                ->hidden(['click_virtual', 'click_actual'])
                ->select()
                ->toArray();
        }

        return $articles;
    }

    private static function cases(int $tenantId, array $appCodes, array $params): array
    {
        try {
            return CaseGalleryService::listsByAppCodes($tenantId, $appCodes, $params, true);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function aiTools(int $tenantId, int $limit = 12): array
    {
        $cards = [];
        $paths = self::aiToolPaths($tenantId);
        foreach (AppDisplayConfigService::lists($tenantId) as $display) {
            if ((int)($display['status'] ?? 1) !== 1 || (int)($display['is_recommend'] ?? 0) !== 1) {
                continue;
            }
            $appCode = (string)($display['app_code'] ?? '');
            if ($appCode === '' || ($paths !== [] && !isset($paths[$appCode]))) {
                continue;
            }
            $cards[] = [
                'id' => $appCode,
                'app_code' => $appCode,
                'path' => self::aiToolPath($appCode, $paths[$appCode] ?? ''),
                'title' => $display['title'] ?? $appCode,
                'description' => $display['description'] ?? '',
                'cover' => $display['cover_url'] ?: '',
                'virtual_use_count' => $display['virtual_use_count'] ?? '',
                'sort' => (int)($display['sort'] ?? 0),
                'is_recommend' => 1,
            ];
        }
        return array_slice($cards, 0, max(1, min(50, $limit)));
    }

    private static function userProfile(int $tenantId, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $user = User::field('id,nickname,avatar,user_money')->where(['id' => $userId, 'tenant_id' => $tenantId])->findOrEmpty();
        if ($user->isEmpty()) {
            return [];
        }
        $row = $user->toArray();
        return [[
            'id' => (int)$row['id'],
            'title' => (string)($row['nickname'] ?: '用户'),
            'name' => (string)($row['nickname'] ?: '用户'),
            'image' => (string)($row['avatar'] ?? ''),
            'quota' => (string)($row['user_money'] ?? '0'),
        ]];
    }

    private static function membershipSummary(int $tenantId, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $membership = MembershipService::status($tenantId, $userId);
        return [[
            'title' => (string)($membership['membership_plan'] ?: '普通用户'),
            'sub_title' => (string)($membership['member_status_text'] ?? ''),
            'tag' => (int)($membership['is_member'] ?? 0) === 1 ? '会员' : '',
            'expire_time' => (string)($membership['member_expire_time_text'] ?? ''),
        ]];
    }

    private static function accountSummary(int $tenantId, int $userId): array
    {
        $profile = self::userProfile($tenantId, $userId);
        if (!$profile) {
            return [];
        }
        return [[
            'title' => '可用点数',
            'sub_title' => (string)($profile[0]['quota'] ?? '0'),
            'tag' => '点数',
        ]];
    }

    private static function recentTasks(int $tenantId, int $userId, int $limit): array
    {
        if ($userId <= 0) {
            return [];
        }
        try {
            return AiAppTask::field('id,app_code,action_code,status,progress,create_time')
                ->where(['tenant_id' => $tenantId, 'user_id' => $userId])
                ->order('id', 'desc')->limit($limit)->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function userAssets(int $tenantId, int $userId, int $limit): array
    {
        return array_map(static function (array $task): array {
            return [
                'id' => (int)($task['id'] ?? 0),
                'title' => (string)($task['app_code'] ?: 'AI作品'),
                'sub_title' => (string)($task['status'] ?? ''),
                'tag' => (string)($task['action_code'] ?? ''),
            ];
        }, self::recentTasks($tenantId, $userId, $limit));
    }

    private static function aiToolPaths(int $tenantId): array
    {
        try {
            $entries = AppFrontendManifestService::tenantEntries($tenantId, 'pc');
        } catch (\Throwable $e) {
            return [];
        }

        $paths = [];
        foreach ($entries as $entry) {
            $appCode = trim((string)($entry['app_code'] ?? ''));
            $path = trim((string)($entry['path'] ?? ''));
            if ($appCode !== '' && $path !== '' && !isset($paths[$appCode])) {
                $paths[$appCode] = $path;
            }
        }
        return $paths;
    }

    private static function aiToolPath(string $appCode, string $manifestPath = ''): string
    {
        return match ($appCode) {
            'aigc_image' => '/ai/create?type=image',
            'aigc_video' => '/ai/create?type=video',
            'aigc_digital_human' => '/ai/avatar',
            'image_human' => '/ai/avatar?tab=image_human',
            'smart_clip' => '/ai/smart_clip',
            'aigc_canvas' => '/app/aigc_canvas',
            'aigc_llm' => '/app/aigc_llm',
            'aigc_short_drama' => '/ai/short-drama',
            default => $manifestPath !== '' ? $manifestPath : '/ai/tools/' . $appCode,
        };
    }

    private static function decodeJson(string $value, array $default): array
    {
        $data = json_decode($value, true);
        return is_array($data) ? $data : $default;
    }

    private static function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
