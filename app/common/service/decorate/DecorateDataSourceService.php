<?php

namespace app\common\service\decorate;

use app\common\model\article\Article;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\AppFrontendManifestService;
use app\common\service\case_gallery\CaseGalleryService;

class DecorateDataSourceService
{
    public static function catalog(int $tenantId): array
    {
        return [
            ['key' => 'article', 'name' => '文章资讯', 'terminal' => ['mobile', 'pc'], 'params' => ['limit', 'sort']],
            ['key' => 'ai_tools', 'name' => 'AI工具入口', 'terminal' => ['pc'], 'params' => ['limit']],
            ['key' => 'image_cases', 'name' => '图片案例', 'terminal' => ['mobile', 'pc'], 'params' => ['limit']],
            ['key' => 'video_cases', 'name' => '视频案例', 'terminal' => ['mobile', 'pc'], 'params' => ['limit']],
            ['key' => 'digital_human_cases', 'name' => '数字人案例', 'terminal' => ['mobile', 'pc'], 'params' => ['limit']],
            ['key' => 'app_entries', 'name' => '应用入口', 'terminal' => ['mobile', 'pc'], 'params' => ['terminal']],
            ['key' => 'assets', 'name' => '资产/作品', 'terminal' => ['pc'], 'params' => ['limit']],
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
            if (!self::passesVisibility((array)($widget['visibility'] ?? $widget['content']['visibility'] ?? []), $context)) {
                continue;
            }
            $filtered[] = $widget;
            $ref = self::widgetSourceRef($widget);
            if ($ref) {
                $refs[self::sourceCacheKey($ref)] = $ref;
            }
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
            'article' => self::articles($limit, (string)($params['sort'] ?? 'new')),
            'image_cases' => self::cases($tenantId, ['aigc_image'], ['limit' => $limit, 'media_type' => 'image']),
            'video_cases' => self::cases($tenantId, ['aigc_video'], ['limit' => $limit, 'media_type' => 'video']),
            'digital_human_cases' => self::cases($tenantId, ['aigc_digital_human', 'image_human'], ['limit' => $limit]),
            'app_entries' => AppFrontendManifestService::tenantEntries($tenantId, (string)($params['terminal'] ?? $context['terminal'] ?? 'pc')),
            'ai_tools' => self::aiTools($tenantId, $limit),
            'assets' => [],
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
            $resolved[$key] = $row;
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

    private static function articles(int $limit, string $sort): array
    {
        $orderRaw = $sort === 'hot' ? 'click_actual + click_virtual desc, id desc' : 'id desc';
        return Article::field('id,title,desc,abstract,image,author,click_virtual,click_actual,create_time')
            ->where(['is_show' => 1])
            ->orderRaw($orderRaw)
            ->limit($limit)
            ->append(['click'])
            ->hidden(['click_virtual', 'click_actual'])
            ->select()
            ->toArray();
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
