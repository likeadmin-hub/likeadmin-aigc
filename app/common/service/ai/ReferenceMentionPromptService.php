<?php

namespace app\common\service\ai;

/**
 * Compiles UI mention chips into provider-neutral media placeholders.
 * This operates on the transient submission payload only; user-authored
 * project content remains unchanged.
 */
class ReferenceMentionPromptService
{
    public static function compile(array $params): array
    {
        $references = self::references($params);
        $mentions = array_values(array_filter((array)($params['selected_mentions'] ?? []), 'is_array'));
        if ($references === []) {
            return $params;
        }

        $compiled = [];
        $byUrl = [];
        $byAssetId = [];
        $typeNumbers = ['image' => 0, 'audio' => 0, 'video' => 0];
        foreach ($references as $reference) {
            $type = (string)$reference['type'];
            $typeNumbers[$type] = ($typeNumbers[$type] ?? 0) + 1;
            $token = '@' . self::label($type) . $typeNumbers[$type];
            $compiled[] = $reference + ['token' => $token];
            if ($reference['url'] !== '') {
                $byUrl[$reference['url']] = $token;
            }
            if ($reference['asset_id'] !== '') {
                $byAssetId[$reference['asset_id']] = $token;
            }
        }

        $nameTokens = [];
        $usedTokens = [];
        foreach ($mentions as $mention) {
            $token = self::tokenForMention($mention, $compiled, $byUrl, $byAssetId, $usedTokens);
            if ($token === null) {
                continue;
            }
            $name = trim((string)($mention['name'] ?? ''));
            if ($name !== '') {
                $nameTokens[$name] = $token;
            }
        }

        $original = trim((string)($params['prompt'] ?? $params['content'] ?? $params['message'] ?? ''));
        $providerPrompt = $original;
        foreach ($nameTokens as $name => $token) {
            $providerPrompt = str_replace('@' . $name, $token, $providerPrompt);
        }
        $tokens = array_values(array_unique(array_column($compiled, 'token')));
        $providerPrompt = self::replaceBareReferenceMarks($providerPrompt, $tokens);
        $hasReferenceMention = self::containsReferenceToken($providerPrompt);
        if ($hasReferenceMention && $tokens !== [] && !str_contains($providerPrompt, '参考素材：')) {
            $providerPrompt = trim($providerPrompt . "\n参考素材：" . implode(' ', $tokens));
        }

        if (!$hasReferenceMention) {
            return $params;
        }

        $params['source_prompt'] = $original;
        $params['prompt'] = $providerPrompt;
        $params['reference_mentions'] = $compiled;
        return $params;
    }

    /**
     * A bare @ is the lightweight mention form used by the creation composer.
     * Convert only standalone marks, keeping email addresses and normal words intact.
     *
     * @param array<int,string> $tokens
     */
    private static function replaceBareReferenceMarks(string $prompt, array $tokens): string
    {
        if ($prompt === '' || $tokens === [] || !str_contains($prompt, '@')) {
            return $prompt;
        }

        $cursor = 0;
        return (string)preg_replace_callback(
            '/(?<![\\p{L}\\p{N}_])@(?=\\s|$|[，。！？、；：,.!?;:])/u',
            static function () use (&$cursor, $tokens): string {
                $token = $tokens[$cursor] ?? '';
                if ($token !== '') {
                    $cursor++;
                    return $token;
                }
                return '@';
            },
            $prompt
        );
    }

    private static function references(array $params): array
    {
        $references = [];
        $seen = [];
        $append = static function ($item, string $fallbackType = 'image') use (&$references, &$seen): void {
            if (!is_array($item)) {
                $item = ['url' => $item];
            }
            $url = trim((string)($item['url'] ?? $item['uri'] ?? $item['path'] ?? ''));
            if ($url === '') {
                return;
            }
            $type = self::type((string)($item['type'] ?? $item['media_type'] ?? $item['asset_type'] ?? $fallbackType));
            $assetId = trim((string)($item['asset_id'] ?? $item['id'] ?? ''));
            $key = $type . '|' . $url;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $references[] = ['type' => $type, 'url' => $url, 'asset_id' => $assetId];
        };
        foreach ((array)($params['reference_assets'] ?? []) as $item) {
            $append($item);
        }
        foreach ((array)($params['reference_images'] ?? $params['image_urls'] ?? []) as $item) {
            $append($item, 'image');
        }
        foreach ((array)($params['video_urls'] ?? []) as $item) {
            $append($item, 'video');
        }
        foreach ((array)($params['audio_urls'] ?? []) as $item) {
            $append($item, 'audio');
        }
        return $references;
    }

    private static function tokenForMention(array $mention, array $references, array $byUrl, array $byAssetId, array &$usedTokens): ?string
    {
        $assetId = trim((string)($mention['asset_id'] ?? $mention['id'] ?? ''));
        if ($assetId !== '' && isset($byAssetId[$assetId])) {
            return $usedTokens[$byAssetId[$assetId]] = $byAssetId[$assetId];
        }
        $url = trim((string)($mention['url'] ?? $mention['uri'] ?? ''));
        if ($url !== '' && isset($byUrl[$url])) {
            return $usedTokens[$byUrl[$url]] = $byUrl[$url];
        }
        $type = self::type((string)($mention['type'] ?? $mention['asset_type'] ?? 'image'));
        foreach ($references as $reference) {
            if ($reference['type'] !== $type || isset($usedTokens[$reference['token']])) {
                continue;
            }
            return $usedTokens[$reference['token']] = $reference['token'];
        }
        return null;
    }

    private static function containsReferenceToken(string $prompt): bool
    {
        return preg_match('/@(图片|音频|视频)\d+/u', $prompt) === 1;
    }

    private static function type(string $type): string
    {
        $type = strtolower(trim($type));
        if (str_contains($type, 'audio') || str_contains($type, 'sound') || str_contains($type, 'music')) {
            return 'audio';
        }
        if (str_contains($type, 'video')) {
            return 'video';
        }
        return 'image';
    }

    private static function label(string $type): string
    {
        return match ($type) {
            'audio' => '音频',
            'video' => '视频',
            default => '图片',
        };
    }
}
