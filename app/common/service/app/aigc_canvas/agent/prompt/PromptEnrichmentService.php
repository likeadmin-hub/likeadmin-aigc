<?php

namespace app\common\service\app\aigc_canvas\agent\prompt;

use app\common\service\app\aigc_canvas\AigcCanvasService;

/**
 * Expands a terse, non-product image request before the deterministic compiler
 * renders the provider prompt. It cannot bypass policy or preflight checks.
 */
final class PromptEnrichmentService
{
    private const MAX_REQUEST_LENGTH = 180;

    public static function enrichDirect(int $tenantId, int $userId, array $input): array
    {
        if (!self::shouldEnrich($tenantId, $userId, $input)) {
            return $input;
        }

        $request = self::request($input);
        try {
            $config = AigcCanvasService::agentConfig($tenantId);
            $result = AigcCanvasService::llmText($tenantId, $userId, array_filter([
                'content' => json_encode([
                    'request' => $request,
                    'ratio' => (string)($input['ratio'] ?? $input['size'] ?? ''),
                    'prompt_language' => (string)($input['prompt_language'] ?? 'zh-CN'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'system_prompt' => self::systemPrompt(),
                'response_format' => ['type' => 'json_object'],
                'max_tokens' => 320,
                'request_timeout_seconds' => 15,
                'enable_thinking' => false,
                'model_code' => (string)($config['router_model_code'] ?? ''),
                'source_app_code' => AigcCanvasService::APP_CODE,
                'source_type' => 'canvas_prompt_enrichment',
                'action_code' => 'canvas_prompt_enrichment',
            ], static fn($value): bool => $value !== ''));
            $draft = self::normalizeResponse((string)($result['content'] ?? ''));
            if ($draft === []) {
                return $input;
            }
            $input = self::applyDraft($input, $draft);
        } catch (\Throwable) {
            // Enrichment improves quality but must not make a valid image request unavailable.
        }
        return $input;
    }

    /**
     * Applies a normalized enrichment draft without allowing it to replace the
     * canonical request. The model draft is supplementary and can be imperfect;
     * the user-requested subject and constraints must always reach the provider.
     */
    public static function applyDraft(array $input, array $draft): array
    {
        $request = self::request($input);
        $originalRequest = trim((string)($input['original_user_request'] ?? $request));
        $input['original_user_request'] = $originalRequest !== '' ? $originalRequest : $request;
        $input['visual_direction'] = array_values(array_unique(array_merge(
            self::strings($input['visual_direction'] ?? []),
            self::strings($draft['visual_direction'] ?? [])
        )));
        $input['prompt_enrichment'] = [
            'applied' => true,
            'version' => 'direct-visual-v1',
            'original_request' => $input['original_user_request'],
            'description' => (string)($draft['description'] ?? ''),
            'visual_direction' => self::strings($draft['visual_direction'] ?? []),
        ];
        return $input;
    }

    /** Public and deterministic for contract tests; no model call occurs here. */
    public static function normalizeResponse(string $content): array
    {
        $text = trim($content);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $json = json_decode($text, true);
        if (!is_array($json) && preg_match('/\{.*\}/s', $text, $match)) {
            $json = json_decode($match[0], true);
        }
        if (!is_array($json)) {
            return [];
        }
        $description = self::clean((string)($json['description'] ?? $json['prompt_draft'] ?? ''));
        if ($description === '') {
            return [];
        }
        return [
            'description' => mb_substr($description, 0, 700, 'UTF-8'),
            'visual_direction' => array_slice(self::strings($json['visual_direction'] ?? []), 0, 8),
        ];
    }

    private static function shouldEnrich(int $tenantId, int $userId, array $input): bool
    {
        if ($tenantId <= 0 || $userId <= 0 || (string)($input['prompt_mode'] ?? $input['compile_mode'] ?? 'direct') !== 'direct') {
            return false;
        }
        $delivery = is_array($input['delivery'] ?? null) ? $input['delivery'] : [];
        if (!empty($input['prompt_enrichment']['applied']) || !empty($input['reference_images']) || !empty($input['reference_assets'])
            || !empty($input['product_identity']) || !empty($input['creative_context']) || !empty($input['section'])
            || preg_match('/(?:ecommerce|product|listing|商品|电商|详情页|主图|卖点)/u', (string)($delivery['type'] ?? '')) === 1) {
            return false;
        }
        $request = self::request($input);
        if ($request === '' || mb_strlen($request, 'UTF-8') > self::MAX_REQUEST_LENGTH) {
            return false;
        }
        $config = AigcCanvasService::agentConfig($tenantId);
        if (empty($config['router_available']) || empty($config['router_enabled'])) {
            return false;
        }
        // This service is reached only after an image task has been selected. At
        // that point routing may already have reduced "generate a dog image" to
        // "a dog", so generation-keyword matching would silently skip LLM
        // enrichment for the exact terse requests it is meant to improve.
        return true;
    }

    private static function request(array $input): string
    {
        return trim((string)($input['user_request'] ?? $input['creative_intent'] ?? $input['raw_prompt'] ?? $input['prompt'] ?? ''));
    }

    private static function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a visual prompt planner. Turn a terse Chinese image request into a concrete, natural Chinese visual brief.

Return JSON only:
{"description":"...","visual_direction":["...","..."]}

Rules:
1. Preserve the requested subject, action, explicit style, aspect ratio, and constraints. Do not change the creative intent.
2. When the user only names a subject, add suitable pose, environment, composition, lighting, depth of field, color, and texture so the image is complete. These are creative choices, not user-provided facts.
3. Do not output Markdown, explanations, prices, certifications, specifications, performance claims, medical claims, comparative claims, or text that should appear inside the image.
4. description must be Chinese, 80-260 Chinese characters. visual_direction contains at most 6 short Chinese visual directions.
PROMPT;
    }

    private static function strings($value): array
    {
        $values = is_array($value) ? $value : [$value];
        $result = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = self::clean((string)$value);
            if ($value !== '') {
                $result[] = mb_substr($value, 0, 180, 'UTF-8');
            }
        }
        return array_values(array_unique($result));
    }

    private static function clean(string $value): string
    {
        $value = self::normalizeUtf8($value);
        // Keep generated prompts portable for market providers with legacy text storage.
        $value = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $value) ?? $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return trim($value, " \t\n\r\0\x0B,，;；。");
    }

    private static function normalizeUtf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $source = mb_detect_encoding($value, ['GB18030', 'GBK', 'BIG-5'], true);
        if ($source !== false) {
            return mb_convert_encoding($value, 'UTF-8', $source);
        }
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        return is_string($clean) ? $clean : '';
    }
}
