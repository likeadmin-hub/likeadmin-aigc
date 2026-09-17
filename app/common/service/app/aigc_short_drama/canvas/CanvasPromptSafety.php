<?php

namespace app\common\service\app\aigc_short_drama\canvas;

/**
 * Baseline prompt protection for the isolated canvas domain.
 * Tenant-configured moderation can be added here later without borrowing the
 * original short-drama service's private policy or exposing its configuration.
 */
final class CanvasPromptSafety
{
    /** @param string $content User or model-produced content before a provider submission. */
    public static function assertAllowed(string $content): void
    {
        $normalised = mb_strtolower($content, 'UTF-8');
        foreach (['api_key', 'api key', 'secret key', 'system prompt', 'system_prompt', 'ignore previous instructions'] as $needle) {
            if (str_contains($normalised, $needle)) {
                CanvasPolicy::fail('CONTENT_BLOCKED', '内容不符合创作规范，请调整后重试');
            }
        }
    }
}
