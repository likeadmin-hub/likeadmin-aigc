<?php

namespace app\common\service\app\aigc_canvas\agent\generation;

use app\common\service\app\aigc_canvas\AigcCanvasAgentService;
use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_canvas\agent\billing\CanvasAgentEntitlementService;
use app\common\service\app\aigc_canvas\agent\delivery\DeliveryItemTaskSyncService;
use app\common\service\app\aigc_canvas\agent\model\CanvasGenerationPricingService;
use app\common\service\app\aigc_canvas\agent\model\CanvasModelRouterService;
use app\common\service\app\aigc_canvas\agent\prompt\PromptSubmissionException;
use Exception;

/**
 * A normalized facade over power-market media tasks. Provider selection,
 * reservation, submit and callback handling remain in the existing runtimes.
 */
final class CanvasGenerationTaskCenterService
{
    public static function schema(int $tenantId): array
    {
        $overview = CanvasModelRouterService::marketOverview($tenantId);
        return [
            'mode' => 'power_market',
            'generators' => array_map(static function (string $type) use ($overview): array {
                $item = is_array($overview[$type] ?? null) ? $overview[$type] : [];
                $options = array_values(array_filter((array)($item['options'] ?? []), 'is_array'));
                return [
                    'type' => $type,
                    'available' => !empty($options),
                    'channel_count' => count($options),
                    'default' => (string)($item['default'] ?? ''),
                ];
            }, ['image', 'video', 'music']),
        ];
    }

    public static function create(int $tenantId, int $userId, array $params): array
    {
        $type = self::type($params);
        $toolCode = 'generate_' . ($type === 'music' ? 'music' : $type);
        $pricing = CanvasGenerationPricingService::estimate($tenantId, $toolCode, $params);
        if (empty($pricing['available'])) {
            throw new Exception(self::failureMessage('configuration', (string)($pricing['message'] ?? 'No usable power-market channel')));
        }
        $entitlement = CanvasAgentEntitlementService::precheck($tenantId, $userId, $pricing);
        if (empty($entitlement['can_submit'])) {
            throw new Exception(self::failureMessage('balance', (string)($entitlement['message'] ?? 'Insufficient balance')));
        }
        try {
            $result = match ($type) {
                'video' => AigcCanvasService::generateVideo($tenantId, $userId, $params),
                'music' => AigcCanvasService::generateMusic($tenantId, $userId, $params),
                default => AigcCanvasService::generateImage($tenantId, $userId, $params),
            };
        } catch (PromptSubmissionException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new Exception(self::failureMessage('submit', $e->getMessage()));
        }
        $normalized = self::normalize($type, $result, $pricing);
        if (in_array($type, ['image', 'video'], true)) {
            $submitted = (array)($result['submitted_input'] ?? []);
            $normalized['preflight'] = (array)($submitted['preflight'] ?? $result['preflight'] ?? []);
            $normalized['prompt_hash'] = (string)($submitted['prompt_hash'] ?? $result['prompt_hash'] ?? '');
            $normalized['compiler_version'] = (string)($submitted['compiler_version'] ?? $result['compiler_version'] ?? '');
            $normalized['compiled_prompt'] = (string)($submitted['compiled_prompt'] ?? $result['compiled_prompt'] ?? '');
            $normalized['prompt_spec_json'] = (array)($submitted['prompt_spec_json'] ?? $result['prompt_spec_json'] ?? []);
            $normalized['creative_spec_json'] = (array)($submitted['creative_spec_json'] ?? $result['creative_spec_json'] ?? []);
            $normalized['evidence_ids'] = array_values((array)($submitted['evidence_ids'] ?? $result['evidence_ids'] ?? []));
            $normalized['claim_ids'] = array_values((array)($submitted['claim_ids'] ?? $result['claim_ids'] ?? []));
            $normalized['submitted_input'] = self::mediaSnapshot($submitted, $type);
        }
        if ((int)($params['delivery_item_id'] ?? 0) > 0) {
            DeliveryItemTaskSyncService::syncGenerationTask($tenantId, $userId, $normalized, (int)$params['delivery_item_id']);
        }
        return $normalized;
    }

    public static function query(int $tenantId, int $userId, array $params): array
    {
        $result = AigcCanvasAgentService::generationDetail($tenantId, $userId, $params);
        if ($result === []) {
            throw new Exception('Task not found');
        }
        $normalized = self::normalize((string)($result['mode'] ?? self::type($params)), $result);
        DeliveryItemTaskSyncService::syncGenerationTask($tenantId, $userId, $normalized, (int)($params['delivery_item_id'] ?? 0));
        return $normalized;
    }

    public static function cancel(int $tenantId, int $userId, array $params): array
    {
        $normalized = self::normalize(self::type($params), AigcCanvasAgentService::cancelGeneration($tenantId, $userId, $params));
        DeliveryItemTaskSyncService::syncGenerationTask($tenantId, $userId, $normalized, (int)($params['delivery_item_id'] ?? 0));
        return $normalized;
    }

    public static function retry(int $tenantId, int $userId, array $params): array
    {
        $normalized = self::normalize(self::type($params), AigcCanvasAgentService::retryGeneration($tenantId, $userId, $params));
        DeliveryItemTaskSyncService::syncGenerationTask($tenantId, $userId, $normalized, (int)($params['delivery_item_id'] ?? 0));
        return $normalized;
    }

    public static function recover(int $tenantId, int $userId, array $params): array
    {
        $result = self::query($tenantId, $userId, $params);
        $result['recovered'] = true;
        return $result;
    }

    private static function type(array $params): string
    {
        $value = strtolower(trim(implode(' ', array_map('strval', [
            $params['type'] ?? '', $params['mode'] ?? '', $params['media_type'] ?? '', $params['task_type'] ?? '',
        ]))));
        if (str_contains($value, 'video') || str_contains($value, '视频')) {
            return 'video';
        }
        if (str_contains($value, 'music') || str_contains($value, 'audio') || str_contains($value, '音乐')) {
            return 'music';
        }
        return 'image';
    }

    private static function normalize(string $type, array $result, array $pricing = []): array
    {
        $status = (string)($result['status'] ?? 'running');
        $error = (string)($result['error'] ?? '');
        return [
            'request_id' => (string)($result['request_id'] ?? ''),
            'task_id' => (string)($result['task_id'] ?? $result['id'] ?? ''),
            'power_task_id' => (string)($result['provider_task_id'] ?? $result['task_id'] ?? ''),
            'model_type' => $type,
            'resource_type' => 'power_market',
            'status' => $status,
            'progress' => (int)($result['progress'] ?? (in_array($status, ['success', 'failed', 'canceled'], true) ? 100 : 0)),
            'result_assets' => array_values((array)($result['assets'] ?? $result['output_assets'] ?? $result['results'] ?? [])),
            'results' => array_values((array)($result['assets'] ?? $result['output_assets'] ?? $result['results'] ?? [])),
            'error_code' => $error === '' ? '' : self::failureLayer($error),
            'error_message' => $error,
            'billing_status' => (string)($result['billing_status'] ?? ($status === 'failed' ? 'failed' : 'reserved')),
            'estimated_points' => (float)($pricing['estimated_points'] ?? $pricing['user_charge_points'] ?? 0),
        ];
    }

    private static function failureMessage(string $layer, string $message): string
    {
        return '[' . $layer . '] ' . trim($message);
    }

    private static function failureLayer(string $error): string
    {
        $text = mb_strtolower($error, 'UTF-8');
        if (str_contains($text, '余额') || str_contains($text, '积分') || str_contains($text, 'balance')) return 'balance';
        if (str_contains($text, '回调') || str_contains($text, 'callback')) return 'callback';
        if (str_contains($text, '存储') || str_contains($text, 'asset')) return 'asset';
        if (str_contains($text, '超时') || str_contains($text, 'upstream') || str_contains($text, 'provider')) return 'runtime';
        if (str_contains($text, '参数') || str_contains($text, 'ratio') || str_contains($text, '规格')) return 'submit';
        return 'configuration';
    }

    private static function mediaSnapshot(array $params, string $type = 'image'): array
    {
        $keys = [
            'prompt', 'compiled_prompt', 'prompt_spec_json', 'creative_spec_json', 'prompt_hash', 'compiler_version',
            'prompt_mode', 'evidence_ids', 'claim_ids', 'preflight', 'ratio', 'quantity', 'reference_images',
            'reference_assets', 'channel', 'model_id', 'market_product_id', 'market_sku_id', 'sku_id',
            'model_selection_explicit',
            'target_element_id', 'section_key', 'section_index', 'batch_id', 'request_id',
            'original_user_request', 'prompt_enrichment', 'prompt_language',
        ];
        if ($type === 'video') {
            $keys = array_merge($keys, ['duration', 'video_urls', 'audio_urls', 'camera_movement', 'motion', 'continuity_constraints']);
        }
        return array_intersect_key($params, array_flip($keys));
    }
}
