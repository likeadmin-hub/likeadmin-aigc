<?php

namespace app\common\service\app\aigc_canvas\agent\memory;

use app\common\model\app\aigc_canvas\AigcCanvasProject;
use Exception;
use think\facade\Db;

final class BrandMemoryService
{
    private const MEMORY_TYPE = 'brand';
    private const MEMORY_KEY = 'brand_profile';

    public static function detail(int $tenantId, int $userId, array $params = []): array
    {
        $projectId = (int)($params['project_id'] ?? 0);
        if ($projectId > 0) {
            self::assertProject($tenantId, $userId, $projectId);
        }
        $row = $projectId > 0 ? self::findRow($tenantId, $projectId) : [];
        $profile = self::defaultProfile();
        if (!empty($row)) {
            $json = json_decode((string)($row['memory_json'] ?? ''), true);
            $profile = array_merge($profile, is_array($json) ? $json : []);
        }
        $profile = self::normalizeProfile($profile);
        return [
            'project_id' => $projectId,
            'configured' => self::isConfigured($profile),
            'profile' => $profile,
            'summary' => self::summary($profile),
            'prompt_context' => self::promptContext($profile),
            'version' => (int)($row['version'] ?? 1),
            'update_time' => (int)($row['update_time'] ?? 0),
        ];
    }

    public static function save(int $tenantId, int $userId, array $params): array
    {
        $projectId = (int)($params['project_id'] ?? 0);
        if ($projectId <= 0) {
            throw new Exception('请先选择画布项目');
        }
        self::assertProject($tenantId, $userId, $projectId);

        if (!empty($params['clear'])) {
            Db::name('aigc_canvas_agent_memory')
                ->where([
                    'tenant_id' => $tenantId,
                    'project_id' => $projectId,
                    'memory_type' => self::MEMORY_TYPE,
                    'memory_key' => self::MEMORY_KEY,
                    'delete_time' => 0,
                ])
                ->update(['delete_time' => time(), 'update_time' => time()]);
            return self::detail($tenantId, $userId, ['project_id' => $projectId]);
        }

        $profile = self::normalizeProfile(is_array($params['profile'] ?? null) ? $params['profile'] : $params);
        $now = time();
        $exists = self::findRow($tenantId, $projectId);
        $version = max(1, (int)($exists['version'] ?? 0) + 1);
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'memory_type' => self::MEMORY_TYPE,
            'memory_key' => self::MEMORY_KEY,
            'memory_json' => json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source_json' => json_encode([
                'source' => (string)($params['source'] ?? 'user_edit'),
                'saved_by' => $userId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'summary' => self::summary($profile),
            'version' => $version,
            'update_time' => $now,
        ];
        if (!empty($exists)) {
            Db::name('aigc_canvas_agent_memory')->where('id', (int)$exists['id'])->update($data);
        } else {
            $data['create_time'] = $now;
            $data['delete_time'] = 0;
            Db::name('aigc_canvas_agent_memory')->insert($data);
        }
        return self::detail($tenantId, $userId, ['project_id' => $projectId]);
    }

    public static function context(int $tenantId, int $userId, int $projectId): array
    {
        if ($tenantId <= 0 || $userId <= 0 || $projectId <= 0) {
            return [];
        }
        try {
            $detail = self::detail($tenantId, $userId, ['project_id' => $projectId]);
        } catch (Exception) {
            return [];
        }
        return !empty($detail['configured']) ? $detail : [];
    }

    public static function promptContext(array $profile): string
    {
        $profile = self::normalizeProfile($profile);
        if (!self::isConfigured($profile)) {
            return '';
        }
        $lines = [];
        if ($profile['brand_name'] !== '') {
            $lines[] = '品牌名称：' . $profile['brand_name'];
        }
        if (!empty($profile['brand_colors'])) {
            $lines[] = '品牌色：' . implode('、', $profile['brand_colors']);
        }
        if (!empty($profile['brand_fonts'])) {
            $lines[] = '字体偏好：' . implode('、', $profile['brand_fonts']);
        }
        if ($profile['official_url'] !== '') {
            $lines[] = '品牌官网：' . $profile['official_url'];
        }
        if (!empty($profile['reference_urls'])) {
            $lines[] = '参考链接：' . implode('、', $profile['reference_urls']);
        }
        foreach ([
            'tone' => '语气',
            'target_audience' => '目标人群',
            'visual_style' => '视觉风格',
            'negative_style' => '避免风格',
        ] as $key => $label) {
            if ($profile[$key] !== '') {
                $lines[] = $label . '：' . $profile[$key];
            }
        }
        if (!empty($profile['logo_assets'])) {
            $lines[] = 'Logo/品牌素材：' . count($profile['logo_assets']) . ' 个';
        }
        return implode("\n", $lines);
    }

    public static function mergeAsset(int $tenantId, int $userId, int $projectId, array $asset, array $params = []): array
    {
        if ($projectId <= 0) {
            return [];
        }
        self::assertProject($tenantId, $userId, $projectId);

        $detail = self::detail($tenantId, $userId, ['project_id' => $projectId]);
        $profile = self::normalizeProfile((array)($detail['profile'] ?? []));
        $assetType = (string)($asset['asset_type'] ?? $params['asset_type'] ?? $params['type'] ?? '');
        $url = trim((string)($asset['url'] ?? $asset['uri'] ?? $params['url'] ?? $params['uri'] ?? ''));
        $title = trim((string)($asset['title'] ?? $params['title'] ?? $params['name'] ?? ''));

        if ($url !== '' && in_array($assetType, ['brand_logo', 'logo', 'brand_asset'], true)) {
            $profile['logo_assets'][] = [
                'url' => $url,
                'type' => 'image',
                'name' => $title,
            ];
        }

        $officialUrl = trim((string)($params['official_url'] ?? $params['brand_url'] ?? ''));
        if ($officialUrl !== '') {
            $profile['official_url'] = $officialUrl;
        }

        $referenceUrl = trim((string)($params['reference_url'] ?? ''));
        if ($referenceUrl !== '') {
            $profile['reference_urls'][] = $referenceUrl;
        }

        if (!empty($params['brand_name'])) {
            $profile['brand_name'] = (string)$params['brand_name'];
        }

        return self::save($tenantId, $userId, [
            'project_id' => $projectId,
            'profile' => $profile,
            'source' => (string)($params['source'] ?? 'asset_register'),
        ]);
    }

    private static function findRow(int $tenantId, int $projectId): array
    {
        $row = Db::name('aigc_canvas_agent_memory')
            ->where([
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'memory_type' => self::MEMORY_TYPE,
                'memory_key' => self::MEMORY_KEY,
                'delete_time' => 0,
            ])
            ->find();
        return is_array($row) ? $row : [];
    }

    private static function assertProject(int $tenantId, int $userId, int $projectId): void
    {
        $exists = AigcCanvasProject::where([
            'id' => $projectId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($exists->isEmpty()) {
            throw new Exception('画布项目不存在');
        }
    }

    private static function defaultProfile(): array
    {
        return [
            'brand_name' => '',
            'brand_colors' => [],
            'brand_fonts' => [],
            'logo_assets' => [],
            'official_url' => '',
            'reference_urls' => [],
            'tone' => '',
            'target_audience' => '',
            'visual_style' => '',
            'negative_style' => '',
        ];
    }

    private static function normalizeProfile(array $profile): array
    {
        return [
            'brand_name' => mb_substr(trim((string)($profile['brand_name'] ?? '')), 0, 80, 'UTF-8'),
            'brand_colors' => self::normalizeColorList($profile['brand_colors'] ?? []),
            'brand_fonts' => self::normalizeTextList($profile['brand_fonts'] ?? [], 12, 40),
            'logo_assets' => self::normalizeAssetList($profile['logo_assets'] ?? []),
            'official_url' => self::normalizeUrl((string)($profile['official_url'] ?? '')),
            'reference_urls' => self::normalizeUrlList($profile['reference_urls'] ?? []),
            'tone' => mb_substr(trim((string)($profile['tone'] ?? '')), 0, 160, 'UTF-8'),
            'target_audience' => mb_substr(trim((string)($profile['target_audience'] ?? '')), 0, 160, 'UTF-8'),
            'visual_style' => mb_substr(trim((string)($profile['visual_style'] ?? '')), 0, 240, 'UTF-8'),
            'negative_style' => mb_substr(trim((string)($profile['negative_style'] ?? '')), 0, 240, 'UTF-8'),
        ];
    }

    private static function normalizeColorList($value): array
    {
        $items = self::asList($value);
        $colors = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                continue;
            }
            $color = trim((string)$item);
            if ($color !== '' && $color[0] !== '#') {
                $color = '#' . $color;
            }
            if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
                $colors[] = strtoupper($color);
            }
        }
        return array_values(array_unique(array_slice($colors, 0, 8)));
    }

    private static function normalizeTextList($value, int $limit, int $length): array
    {
        $items = self::asList($value);
        $result = [];
        foreach ($items as $item) {
            $text = mb_substr(trim((string)$item), 0, $length, 'UTF-8');
            if ($text !== '') {
                $result[] = $text;
            }
        }
        return array_values(array_unique(array_slice($result, 0, $limit)));
    }

    private static function normalizeAssetList($value): array
    {
        $items = self::asList($value);
        $assets = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $url = trim($item);
                if ($url !== '') {
                    $assets[] = ['url' => mb_substr($url, 0, 500, 'UTF-8'), 'type' => 'image'];
                }
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $url = trim((string)($item['url'] ?? $item['uri'] ?? ''));
            if ($url === '') {
                continue;
            }
            $assets[] = [
                'url' => mb_substr($url, 0, 500, 'UTF-8'),
                'type' => (string)($item['type'] ?? 'image'),
                'name' => mb_substr(trim((string)($item['name'] ?? '')), 0, 80, 'UTF-8'),
            ];
        }
        return array_slice($assets, 0, 12);
    }

    private static function normalizeUrlList($value): array
    {
        $urls = [];
        foreach (self::asList($value) as $item) {
            $url = self::normalizeUrl(is_array($item) ? (string)($item['url'] ?? '') : (string)$item);
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        return array_values(array_unique(array_slice($urls, 0, 12)));
    }

    private static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }
        return filter_var($url, FILTER_VALIDATE_URL) ? mb_substr($url, 0, 500, 'UTF-8') : '';
    }

    private static function asList($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            return preg_split('/[,，\n]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        return is_array($value) ? $value : [];
    }

    private static function isConfigured(array $profile): bool
    {
        foreach ($profile as $value) {
            if (is_array($value) ? !empty($value) : trim((string)$value) !== '') {
                return true;
            }
        }
        return false;
    }

    private static function summary(array $profile): string
    {
        return mb_substr(str_replace("\n", '；', self::promptContext($profile)), 0, 1000, 'UTF-8');
    }
}
