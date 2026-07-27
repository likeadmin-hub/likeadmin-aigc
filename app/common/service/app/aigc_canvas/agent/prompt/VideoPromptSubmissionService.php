<?php

namespace app\common\service\app\aigc_canvas\agent\prompt;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_canvas\agent\model\CanvasModelRouterService;

/** Single compiler and preflight gateway for Canvas video requests. */
final class VideoPromptSubmissionService
{
    public static function withTrustedRetrySnapshot(array $input): array
    {
        $input['__video_retry_ticket'] = new VideoPromptRetryTicket();
        return $input;
    }

    public static function prepareVideoRequest(int $tenantId, array $input): array
    {
        $userId = (int)($input['__request_user_id'] ?? 0);
        $trustedRetry = ($input['__video_retry_ticket'] ?? null) instanceof VideoPromptRetryTicket;
        unset($input['__request_user_id'], $input['__video_retry_ticket']);
        try {
            if ($trustedRetry && self::hasSnapshot($input)) {
                $trace = VideoPromptSpecCompiler::reuseSnapshot($input);
            } else {
                if (self::mode($input) === 'direct') {
                    $input = self::enrichDirect($tenantId, $userId, $input);
                }
                $trace = VideoPromptSpecCompiler::compile($input);
            }
            $prepared = array_merge($input, [
                'prompt' => $trace['compiled_prompt'], 'compiled_prompt' => $trace['compiled_prompt'],
                'creative_spec_json' => $trace['creative_spec_json'], 'prompt_spec_json' => $trace['prompt_spec_json'],
                'compiler_version' => $trace['compiler_version'], 'prompt_hash' => $trace['prompt_hash'],
                'evidence_ids' => $trace['evidence_ids'], 'claim_ids' => $trace['claim_ids'], 'submission_prepared' => true,
            ]);
            $prepared = CanvasModelRouterService::applyToToolInput($tenantId, 'generate_video', $prepared);
            $prepared['preflight'] = self::preflight($tenantId, $prepared);
            return $prepared;
        } catch (PromptSubmissionException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            throw new PromptSubmissionException($e->getMessage(), ['provider_error_code' => 'video_prompt_rejected']);
        }
    }

    /** Public and deterministic for contract tests; no model call occurs here. */
    public static function normalizeEnrichment(string $content): array
    {
        $text = trim(preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content);
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $json = json_decode($text, true);
        if (!is_array($json) && preg_match('/\{.*\}/s', $text, $match)) $json = json_decode($match[0], true);
        if (!is_array($json)) return [];
        $description = trim((string)($json['description'] ?? $json['prompt_draft'] ?? ''));
        if ($description === '') return [];
        return [
            'description' => mb_substr($description, 0, 900, 'UTF-8'),
            'temporal_direction' => self::strings($json['temporal_direction'] ?? $json['shot_direction'] ?? []),
            'camera_movement' => mb_substr(trim((string)($json['camera_movement'] ?? '')), 0, 180, 'UTF-8'),
            'motion' => mb_substr(trim((string)($json['motion'] ?? '')), 0, 260, 'UTF-8'),
            'continuity' => self::strings($json['continuity'] ?? []),
        ];
    }

    private static function enrichDirect(int $tenantId, int $userId, array $input): array
    {
        if ($userId <= 0 || !empty($input['reference_assets']) || !empty($input['reference_images']) || !empty($input['creative_context'])) return $input;
        $request = trim((string)($input['user_request'] ?? $input['prompt'] ?? ''));
        if ($request === '' || mb_strlen($request, 'UTF-8') > 220) return $input;
        $config = AigcCanvasService::agentConfig($tenantId);
        if (empty($config['router_enabled']) || empty($config['router_available'])) return $input;
        try {
            $result = AigcCanvasService::llmText($tenantId, $userId, [
                'content' => json_encode(['request' => $request, 'ratio' => (string)($input['ratio'] ?? ''), 'duration' => (int)($input['duration'] ?? 0)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'system_prompt' => self::enrichmentPrompt(), 'response_format' => ['type' => 'json_object'],
                'max_tokens' => 420, 'request_timeout_seconds' => 15, 'enable_thinking' => false,
                'model_code' => (string)($config['router_model_code'] ?? ''),
                'source_app_code' => AigcCanvasService::APP_CODE, 'source_type' => 'canvas_video_prompt_enrichment',
                'action_code' => 'canvas_video_prompt_enrichment',
            ]);
            $draft = self::normalizeEnrichment((string)($result['content'] ?? ''));
            if ($draft === []) return $input;
            $input['original_user_request'] = $request;
            $input['user_request'] = (string)$draft['description'];
            $input['prompt'] = (string)$draft['description'];
            $input['video_direction'] = (array)$draft['temporal_direction'];
            $input['camera_movement'] = (string)$draft['camera_movement'];
            $input['motion'] = (string)$draft['motion'];
            $input['continuity_constraints'] = (array)$draft['continuity'];
            $input['prompt_enrichment'] = ['applied' => true, 'version' => 'video-visual-v1', 'original_request' => $request, 'description' => $draft['description']];
        } catch (\Throwable) {
            // A valid video request must remain submitable when the text model is unavailable.
        }
        return $input;
    }

    private static function preflight(int $tenantId, array $input): array
    {
        try {
            VideoPromptSpecCompiler::assertSubmission($input);
        } catch (\InvalidArgumentException $e) {
            throw new PromptSubmissionException($e->getMessage(), ['provider_error_code' => 'video_prompt_invalid']);
        }
        $option = self::option((array)((CanvasModelRouterService::marketOverview($tenantId)['video']['options'] ?? [])), $input);
        if ($option !== []) {
            self::assertOption('ratio', (string)($input['ratio'] ?? ''), self::options($option, 'ratio_options'), $input);
            self::assertOption('duration', (string)((int)($input['duration'] ?? 0)), self::options($option, 'duration_options'), $input);
            $assets = self::assets($input);
            foreach (['image', 'video', 'audio'] as $type) {
                $limit = max(0, (int)($option['max_reference_' . $type . 's'] ?? 0));
                if (count($assets[$type]) > $limit) {
                    throw new PromptSubmissionException('The selected video model does not support this reference asset count', ['provider_error_code' => 'video_reference_limit_exceeded']);
                }
            }
        }
        return ['stage' => 'preflight', 'status' => 'passed', 'model' => (string)($input['channel'] ?? $input['model_id'] ?? ''), 'ratio' => (string)($input['ratio'] ?? ''), 'duration' => (int)($input['duration'] ?? 0), 'submitted_prompt_hash' => (string)($input['prompt_hash'] ?? '')];
    }

    private static function assertOption(string $field, string $value, array $options, array $input): void
    {
        if ($value === '' || $value === '0' || $options === [] || in_array($value, $options, true)) return;
        throw new PromptSubmissionException('The selected video model does not support the requested ' . $field, ['provider_error_code' => 'video_' . $field . '_not_supported', 'submitted_prompt_hash' => (string)($input['prompt_hash'] ?? '')]);
    }

    private static function option(array $options, array $input): array
    {
        $values = array_filter([(string)($input['channel'] ?? ''), (string)($input['model_id'] ?? ''), (string)($input['market_product_id'] ?? '')]);
        foreach ($options as $option) foreach (['id', 'value', 'code', 'market_product_id'] as $key) if (is_array($option) && in_array((string)($option[$key] ?? ''), $values, true)) return $option;
        return [];
    }

    private static function options(array $option, string $field): array
    {
        $values = (array)($option[$field] ?? []);
        foreach ((array)($option['specs'] ?? []) as $spec) $values = array_merge($values, (array)($spec[$field] ?? []));
        return array_values(array_unique(array_filter(array_map(static fn($value): string => is_array($value) ? (string)($value['value'] ?? $value['duration'] ?? $value['ratio'] ?? '') : (string)$value, $values))));
    }

    private static function assets(array $input): array
    {
        $assets = ['image' => [], 'video' => [], 'audio' => []];
        foreach ((array)($input['reference_assets'] ?? []) as $asset) if (is_array($asset) && isset($assets[(string)($asset['type'] ?? '')])) $assets[(string)$asset['type']][] = $asset;
        foreach ((array)($input['reference_images'] ?? $input['image_urls'] ?? []) as $url) if ((string)$url !== '') $assets['image'][] = $url;
        foreach (['video_urls' => 'video', 'audio_urls' => 'audio'] as $key => $type) foreach ((array)($input[$key] ?? []) as $url) if ((string)$url !== '') $assets[$type][] = $url;
        return $assets;
    }

    private static function hasSnapshot(array $input): bool { return (string)($input['compiler_version'] ?? '') === VideoPromptSpecCompiler::VERSION && !empty($input['prompt_spec_json']) && !empty($input['prompt_hash']) && trim((string)($input['prompt'] ?? '')) !== ''; }
    private static function mode(array $input): string { $mode = (string)($input['video_prompt_mode'] ?? $input['prompt_mode'] ?? 'direct'); return in_array($mode, ['direct', 'planned', 'edit', 'retry'], true) ? $mode : 'direct'; }
    private static function strings($value): array { $items = is_array($value) ? $value : [$value]; return array_values(array_filter(array_map(static fn($item): string => is_scalar($item) ? trim((string)$item) : '', $items))); }
    private static function enrichmentPrompt(): string { return 'Return JSON only with description, temporal_direction, camera_movement, motion, continuity. Expand the short Chinese video request into a Chinese video brief. Preserve intent. Include action progression, shot size or composition, camera movement, lighting and temporal continuity. Do not add price, certification, specification, performance, medical, comparative claims, markdown or text inside the video. description must be Chinese.'; }
}

final class VideoPromptRetryTicket
{
}
