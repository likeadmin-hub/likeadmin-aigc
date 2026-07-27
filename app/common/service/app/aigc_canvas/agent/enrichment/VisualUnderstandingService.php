<?php

namespace app\common\service\app\aigc_canvas\agent\enrichment;

use app\common\service\power\MarketTextModelRuntimeService;
use app\common\service\FileService;
use think\facade\Db;
use Throwable;

/** Provides a bounded, provider-neutral visual insight for referenced assets. */
final class VisualUnderstandingService
{
    private const MAX_ASSETS = 3;

    /** @return array<string, mixed> */
    public static function analyze(int $tenantId, int $userId, array $assets, string $request = '', array $options = []): array
    {
        $items = array_slice(self::normalizeAssets($assets), 0, self::MAX_ASSETS);
        $insights = [];
        $errors = [];
        $runner = is_callable($options['vision_runner'] ?? null) ? $options['vision_runner'] : null;
        foreach ($items as $asset) {
            if (!in_array($asset['asset_type'], ['image', 'logo'], true) || $asset['url'] === '') {
                $insights[] = self::metadataInsight($asset);
                continue;
            }
            try {
                $result = $runner
                    ? $runner($asset, $request, $options)
                    : self::requestVision($tenantId, $userId, $asset, $request, $options);
                $insight = ProductFactPolicy::sanitizeInsight(array_merge(self::metadataInsight($asset), self::decodeInsight($result), [
                    'asset_key' => $asset['asset_key'],
                    'asset_type' => $asset['asset_type'],
                ]));
                $insight['evidence_catalog'] = ProductFactPolicy::evidenceCatalog($insight);
                $insights[] = $insight;
            } catch (Throwable $e) {
                $insights[] = self::metadataInsight($asset);
                $errors[] = ['asset_key' => $asset['asset_key'], 'message' => mb_substr($e->getMessage(), 0, 300, 'UTF-8')];
            }
        }
        $ready = array_values(array_filter($insights, static fn(array $item): bool => (float)($item['confidence'] ?? 0) > 0));
        return [
            'status' => $errors === [] ? 'success' : ($ready === [] ? 'failed' : 'partial'),
            'asset_count' => count($items),
            'insights' => $insights,
            'errors' => $errors,
            'model_version' => (string)($options['model_version'] ?? 'vision_describe'),
        ];
    }

    /** @return array<string, mixed> */
    private static function requestVision(int $tenantId, int $userId, array $asset, string $request, array $options): array
    {
        $prompt = 'Analyze the reference image for a design workflow. Return JSON only with subject_type, visible_facts, visual_style, composition, ocr_text, confidence. Write all descriptions in concise Chinese, except visible brand names or text in the image. Describe only directly visible facts. Never infer performance, material grade, certification, price, medical/effectiveness claims, dimensions, or specifications. User request: ' . mb_substr($request, 0, 500, 'UTF-8');
        return MarketTextModelRuntimeService::generate($tenantId, $userId, [
            'content' => $prompt,
            'system_prompt' => 'You are a visual understanding service. Output compact valid JSON only. Use visible observations and no marketing claims.',
            'reference_images' => [self::providerReferenceUrl((string)$asset['url'], $tenantId)],
            'requires_vision' => true,
            'action_code' => 'canvas_visual_understanding',
            'source_app_code' => 'aigc_canvas',
            'business_table' => 'aigc_canvas_run',
            'request_timeout_seconds' => max(10, min(120, (int)($options['request_timeout_seconds'] ?? 60))),
            'model_selection' => $options['model_selection'] ?? '',
            // Some compatible vision channels advertise JSON mode but return
            // an unusable envelope for multimodal requests. decodeInsight
            // still validates and extracts JSON from the normal response.
            'max_tokens' => 256,
        ]);
    }

    private static function decodeInsight($result): array
    {
        if (is_array($result) && isset($result['content'])) {
            $result = $result['content'];
        }
        if (is_array($result)) {
            return $result;
        }
        $text = trim((string)$result);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/\{.*\}/su', $text, $match) === 1) {
            $decoded = json_decode($match[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /** @return array<int, array<string, mixed>> */
    private static function normalizeAssets(array $assets): array
    {
        $result = [];
        foreach ($assets as $asset) {
            $row = is_array($asset) ? $asset : ['url' => (string)$asset];
            $data = is_array($row['data'] ?? null) ? $row['data'] : [];
            $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            $url = trim((string)($row['url'] ?? $row['uri'] ?? $row['image_url'] ?? $row['file_url'] ?? $data['url'] ?? $data['image_url'] ?? $metadata['url'] ?? $metadata['image'] ?? ''));
            $mime = strtolower(trim((string)($row['mime'] ?? $row['mime_type'] ?? $data['mime'] ?? $metadata['mime'] ?? '')));
            $type = self::assetType($url, $mime, (string)($row['type'] ?? $row['asset_type'] ?? $data['type'] ?? $metadata['asset_type'] ?? ''));
            $result[] = [
                'asset_key' => trim((string)($row['asset_key'] ?? $row['id'] ?? $row['file_id'] ?? sha1($url . '|' . ($row['name'] ?? '')))),
                'asset_type' => $type,
                'url' => $url,
                'name' => trim((string)($row['name'] ?? $row['filename'] ?? $row['title'] ?? $data['name'] ?? $metadata['title'] ?? '')),
                'width' => max(0, (int)($row['width'] ?? $data['width'] ?? $metadata['width'] ?? 0)),
                'height' => max(0, (int)($row['height'] ?? $data['height'] ?? $metadata['height'] ?? 0)),
            ];
        }
        return array_values(array_filter($result, static fn(array $asset): bool => $asset['url'] !== '' || $asset['name'] !== ''));
    }

    private static function metadataInsight(array $asset): array
    {
        $insight = ProductFactPolicy::sanitizeInsight([
            'asset_key' => $asset['asset_key'],
            'asset_type' => $asset['asset_type'],
            'subject_type' => $asset['asset_type'] === 'logo' ? 'logo' : 'unknown',
            'visible_facts' => [],
            'visual_style' => [],
            'composition' => array_filter([
                'subject_position' => $asset['width'] > 0 && $asset['height'] > 0 ? 'unknown' : '',
            ]),
            'ocr_text' => [],
            'confidence' => 0,
            'fact_sources' => [],
        ]);
        $insight['evidence_catalog'] = [];
        return $insight;
    }

    private static function assetType(string $url, string $mime, string $declared): string
    {
        $declared = strtolower(trim($declared));
        if (in_array($declared, ['image', 'video', 'logo', 'file'], true)) {
            return $declared;
        }
        $value = strtolower($mime . ' ' . (string)parse_url($url, PHP_URL_PATH));
        if (str_contains($value, 'video') || preg_match('/\.(mp4|mov|webm|avi)$/', $value)) return 'video';
        if (str_contains($value, 'image') || preg_match('/\.(jpg|jpeg|png|webp|gif|svg)$/', $value)) return 'image';
        return 'file';
    }

    private static function providerReferenceUrl(string $url, int $tenantId): string
    {
        if ($url === '' || preg_match('/^(https?:\/\/|data:image\/)/i', $url) === 1) {
            return $url;
        }
        $uri = ltrim($url, '/');
        try {
            $file = Db::name('tenant_file')->where(['tenant_id' => $tenantId, 'uri' => $uri])->order('id', 'desc')->find();
            if (!empty($file)) {
                return FileService::getFileUrlByStorage($uri, (string)($file['storage_scope'] ?? ''), (string)($file['storage_engine'] ?? ''), (string)($file['storage_domain'] ?? ''));
            }
        } catch (\Throwable) {
            // The vision call can still use the active storage configuration.
        }
        return FileService::getFileUrl($uri);
    }
}
