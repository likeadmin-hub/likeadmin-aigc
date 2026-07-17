<?php

namespace app\common\service\app\aigc_video;

use app\common\service\FileService;
use Exception;

class AigcVideoReferenceAssetService
{
    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';
    public const TYPE_AUDIO = 'audio';

    public static function normalize(array $params, int $max = 15): array
    {
        $assets = [];
        foreach ((array)($params['reference_assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $normalized = self::normalizeItem($asset);
            if (!empty($normalized)) {
                $assets[] = $normalized;
            }
        }

        foreach (self::legacyImageAssets($params) as $asset) {
            $normalized = self::normalizeItem($asset);
            if (!empty($normalized)) {
                $assets[] = $normalized;
            }
        }

        foreach (['video_urls' => self::TYPE_VIDEO, 'audio_urls' => self::TYPE_AUDIO] as $key => $type) {
            foreach ((array)($params[$key] ?? []) as $url) {
                $normalized = self::normalizeItem([
                    'type' => $type,
                    'uri' => $url,
                    'url' => $url,
                ]);
                if (!empty($normalized)) {
                    $assets[] = $normalized;
                }
            }
        }

        return array_slice(self::unique($assets), 0, $max);
    }

    public static function images(array $assets): array
    {
        return array_values(array_map(
            static fn(array $asset) => (string)($asset['uri'] ?? $asset['url'] ?? ''),
            array_filter($assets, static fn(array $asset) => ($asset['type'] ?? '') === self::TYPE_IMAGE)
        ));
    }

    public static function publicUrl(array $asset): string
    {
        $url = trim((string)($asset['url'] ?? $asset['uri'] ?? ''));
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, 'data:')) {
            return $url;
        }
        return FileService::getFileUrl($url);
    }

    public static function assertSeedanceSupported(array $assets): void
    {
        $counts = [self::TYPE_IMAGE => 0, self::TYPE_VIDEO => 0, self::TYPE_AUDIO => 0];
        foreach ($assets as $asset) {
            $type = (string)($asset['type'] ?? '');
            if (isset($counts[$type])) {
                $counts[$type]++;
            }
        }
        if ($counts[self::TYPE_IMAGE] > 9) {
            throw new Exception('Seedance最多支持9张参考图片');
        }
        if ($counts[self::TYPE_VIDEO] > 3) {
            throw new Exception('Seedance最多支持3个参考视频');
        }
        if ($counts[self::TYPE_AUDIO] > 3) {
            throw new Exception('Seedance最多支持3段参考音频');
        }
        if (($counts[self::TYPE_IMAGE] + $counts[self::TYPE_VIDEO]) === 0 && $counts[self::TYPE_AUDIO] > 0) {
            throw new Exception('Seedance不能单独使用音频素材，请同时上传图片或视频参考');
        }
    }

    public static function assertSeedance2ProSupported(array $assets): void
    {
        $counts = [self::TYPE_IMAGE => 0, self::TYPE_VIDEO => 0, self::TYPE_AUDIO => 0];
        $audioDuration = 0.0;
        foreach ($assets as $asset) {
            $type = (string)($asset['type'] ?? '');
            if (isset($counts[$type])) {
                $counts[$type]++;
            }
            if ($type === self::TYPE_AUDIO && isset($asset['duration']) && is_numeric($asset['duration'])) {
                $audioDuration += max(0, (float)$asset['duration']);
            }
        }
        if ($counts[self::TYPE_IMAGE] > 9) {
            throw new Exception('Seedance 2.0 Pro最多支持9张参考图片');
        }
        if ($counts[self::TYPE_VIDEO] > 3) {
            throw new Exception('Seedance 2.0 Pro最多支持3个参考视频');
        }
        if ($counts[self::TYPE_AUDIO] > 3) {
            throw new Exception('Seedance 2.0 Pro最多支持3段参考音频');
        }
        if (($counts[self::TYPE_IMAGE] + $counts[self::TYPE_VIDEO]) === 0 && $counts[self::TYPE_AUDIO] > 0) {
            throw new Exception('Seedance 2.0 Pro不能单独使用音频素材，请同时上传图片或视频参考');
        }
        if ($audioDuration > 15) {
            throw new Exception('Seedance 2.0 Pro参考音频总时长不能超过15秒');
        }
    }

    private static function legacyImageAssets(array $params): array
    {
        $assets = [];
        foreach (array_values(array_filter((array)($params['reference_images'] ?? $params['image_urls'] ?? []))) as $image) {
            $assets[] = [
                'type' => self::TYPE_IMAGE,
                'uri' => $image,
                'url' => $image,
                'role' => 'reference_image',
            ];
        }
        foreach ([
            'image' => 'reference_image',
            'first_frame_image' => 'first_frame_image',
            'last_frame_image' => 'last_frame_image',
        ] as $key => $role) {
            $value = trim((string)($params[$key] ?? ''));
            if ($value !== '') {
                $assets[] = [
                    'type' => self::TYPE_IMAGE,
                    'uri' => $value,
                    'url' => $value,
                    'role' => $role,
                ];
            }
        }
        return $assets;
    }

    private static function normalizeItem(array $asset): array
    {
        $type = strtolower(trim((string)($asset['type'] ?? $asset['media_type'] ?? 'image')));
        $type = match ($type) {
            'img', 'picture', 'photo' => self::TYPE_IMAGE,
            'movie', 'mp4' => self::TYPE_VIDEO,
            'sound', 'voice', 'music' => self::TYPE_AUDIO,
            default => $type,
        };
        if (!in_array($type, [self::TYPE_IMAGE, self::TYPE_VIDEO, self::TYPE_AUDIO], true)) {
            return [];
        }
        $uri = trim((string)($asset['uri'] ?? $asset['url'] ?? $asset['path'] ?? ''));
        $url = trim((string)($asset['url'] ?? $asset['uri'] ?? $asset['path'] ?? ''));
        if ($uri === '' && $url === '') {
            return [];
        }
        $normalized = [
            'type' => $type,
            'uri' => $uri !== '' ? $uri : $url,
            'url' => $url !== '' ? $url : $uri,
            'name' => trim((string)($asset['name'] ?? '')),
        ];
        $role = trim((string)($asset['role'] ?? ''));
        $allowedRoles = match ($type) {
            self::TYPE_VIDEO => ['reference_video'],
            self::TYPE_AUDIO => ['reference_audio'],
            default => ['reference_image', 'first_frame_image', 'last_frame_image'],
        };
        if (in_array($role, $allowedRoles, true)) {
            $normalized['role'] = $role;
        }
        $generationMethod = strtolower(trim((string)($asset['generation_method'] ?? '')));
        if (in_array($generationMethod, ['omni_reference', 'start_end', 'multi_frame'], true)) {
            $normalized['generation_method'] = $generationMethod;
        }
        foreach (['duration', 'start', 'end'] as $key) {
            if (isset($asset[$key]) && is_numeric($asset[$key])) {
                $normalized[$key] = max(0, (float)$asset[$key]);
            }
        }
        return $normalized;
    }

    private static function unique(array $assets): array
    {
        $unique = [];
        $seen = [];
        foreach ($assets as $asset) {
            $signature = ($asset['type'] ?? '')
                . '|' . trim((string)($asset['uri'] ?? $asset['url'] ?? ''));
            if ($signature === '|' || isset($seen[$signature])) {
                if (isset($seen[$signature])) {
                    $index = $seen[$signature];
                    $incomingRole = (string)($asset['role'] ?? '');
                    $currentRole = (string)($unique[$index]['role'] ?? '');
                    if (
                        in_array($incomingRole, ['first_frame_image', 'last_frame_image'], true)
                        && !in_array($currentRole, ['first_frame_image', 'last_frame_image'], true)
                    ) {
                        $unique[$index]['role'] = $incomingRole;
                    }
                    if (empty($unique[$index]['generation_method']) && !empty($asset['generation_method'])) {
                        $unique[$index]['generation_method'] = $asset['generation_method'];
                    }
                }
                continue;
            }
            $seen[$signature] = count($unique);
            $unique[] = $asset;
        }
        return $unique;
    }
}
