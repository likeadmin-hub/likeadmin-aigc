<?php

declare(strict_types=1);

namespace app\common\service\ai;

use app\common\service\FileService;
use think\facade\Db;

/** Read-only, allowlisted operator projection. Never returns provider payloads/configuration. */
final class AiUsageSupportService
{
    private const EXTRA_SOURCES = [
        'aigc_music_task', 'aigc_llm_message', 'aigc_copywriting_task',
        'aigc_short_drama_script_task', 'aigc_canvas_run',
    ];
    private const INPUT_FIELDS = [
        'title', 'prompt', 'negative_prompt', 'script_text', 'content', 'lyrics',
        'style', 'genre', 'mood', 'instruments', 'quality', 'ratio', 'resolution',
        'duration', 'quantity', 'width', 'height', 'seed',
    ];

    public static function forConsumption(array $usage): array
    {
        $task = Db::name('ai_app_task')->where([
            'id' => (int)($usage['app_task_id'] ?? 0),
            'tenant_id' => (int)($usage['tenant_id'] ?? 0),
            'user_id' => (int)($usage['user_id'] ?? 0),
        ])->find();
        return self::forAppTask($task ?: [], $usage);
    }

    public static function forAppTask(array $task, array $usage = []): array
    {
        $result = [
            'source_available' => false, 'source_status' => '', 'prompt' => '',
            'display_model' => self::modelName($usage), 'inputs' => [],
            'input_media' => [], 'media_results' => [], 'text_result' => '',
        ];
        $tenantId = (int)($task['tenant_id'] ?? 0);
        $userId = (int)($task['user_id'] ?? 0);
        if ($tenantId <= 0 || $userId <= 0 || ($usage !== [] && (
            $tenantId !== (int)($usage['tenant_id'] ?? 0)
            || $userId !== (int)($usage['user_id'] ?? 0)
            || (int)($task['id'] ?? 0) !== (int)($usage['app_task_id'] ?? 0)
        ))) {
            return $result;
        }
        // Platform operators must resolve/sign media using the frozen tenant,
        // then restore the request context even if a storage reader fails.
        $request = request();
        $previousTenant = $request->tenantId ?? null;
        $request->tenantId = $tenantId;
        try {
            $table = (string)($task['business_table'] ?? '');
            $id = (int)($task['business_id'] ?? 0);
            $source = AiTaskRecordService::supportSource($table, $id, $tenantId, $userId);
            if ($source === [] && in_array($table, self::EXTRA_SOURCES, true) && $id > 0) {
                $row = self::scopedRow($table, $id, $tenantId, $userId);
                if ($row !== []) $source = ['row' => $row, 'media_results' => []];
            }
            if ($source === []) return $result;
            $row = $source['row'];
            if ((int)($row['app_task_id'] ?? 0) > 0 && (int)$row['app_task_id'] !== (int)$task['id']) return $result;
            if ($usage !== [] && (int)($row['consumption_id'] ?? 0) > 0 && (int)$row['consumption_id'] !== (int)$usage['id']) return $result;
            $result['source_available'] = true;
            $result['source_status'] = self::text($row['status'] ?? '');
            $inputRow = $row;
            if (in_array($table, ['aigc_short_drama_generation_task', 'aigc_short_drama_script_task', 'aigc_canvas_run'], true)) {
                foreach (self::arrayValue($row['request_json'] ?? $row['params_json'] ?? []) as $key => $value) {
                    if (!isset($inputRow[$key]) || $inputRow[$key] === '') $inputRow[$key] = $value;
                }
            }
            $result['inputs'] = self::inputs($inputRow);
            $result['prompt'] = self::text($inputRow['prompt'] ?? $inputRow['script_text'] ?? $inputRow['text'] ?? '');
            if (self::modelName($usage, true) === '') {
                $result['display_model'] = self::frozenSourceModel($row)
                    ?: (self::text($source['display_model'] ?? $row['model_name'] ?? $row['model'] ?? $row['model_code'] ?? '') ?: $result['display_model']);
            }
            $result['input_media'] = self::inputMedia($inputRow, $tenantId, $userId);
            $result['media_results'] = self::mediaProjection((array)($source['media_results'] ?? []));
            if ($table === 'aigc_llm_message') {
                $parent = self::scopedRow($table, (int)($row['parent_user_message_id'] ?? 0), $tenantId, $userId);
                if (($parent['role'] ?? '') === 'user' && (int)($parent['session_id'] ?? 0) === (int)($row['session_id'] ?? 0)) {
                    $result['prompt'] = self::text($parent['content'] ?? '');
                    $result['inputs'] = ['content' => $result['prompt']];
                } else {
                    $result['inputs'] = [];
                }
                $result['text_result'] = ($row['role'] ?? '') === 'assistant' ? self::text($row['content'] ?? '') : '';
            } else {
                $result['text_result'] = self::text($row['result_text'] ?? $row['output_text'] ?? '');
            }
            if ($table === 'aigc_copywriting_task') {
                $prompt = self::arrayValue($row['prompt_snapshot'] ?? []);
                $result['prompt'] = self::text($prompt['user_prompt'] ?? $prompt['content'] ?? '');
                if ($result['prompt'] !== '') $result['inputs']['prompt'] = $result['prompt'];
            }
            if ($table === 'aigc_music_task') {
                $result['media_results'] = self::resultRows('aigc_music_result', $id, $tenantId, $userId, 'audio');
            }
            // Persisted managed assets cover unified calls without a per-app result table.
            if ($usage !== [] && ($usage['run_status'] ?? '') === 'success' && $result['media_results'] === []) {
                $result['media_results'] = self::managedAssets($task, $usage);
            }
        } catch (\Throwable) {
            // Missing old app/schema or unavailable private storage: no diagnostics/credentials.
        } finally {
            $request->tenantId = $previousTenant;
        }
        if (!in_array($result['source_status'], ['success', 'completed', 'done', 'partial_success', 'succeeded'], true)
            || ($usage !== [] && !in_array($usage['run_status'] ?? '', ['success', 'partial_success'], true))) {
            $result['media_results'] = [];
            $result['text_result'] = '';
        }
        return $result;
    }

    /** Existing operational summaries remain summaries; support content is separate. */
    public static function diagnosticSummary($value): array
    {
        $summary = [];
        foreach (self::arrayValue($value) as $key => $item) {
            if (in_array($key, ['status', 'run_status', 'billing_status', 'transfer_status',
                'task_id', 'app_task_id', 'consumption_id', 'provider_task_id', 'upstream_task_id',
                'provider_request_id', 'upstream_request_id', 'model_code', 'channel', 'quality', 'ratio',
                'quantity', 'duration', 'width', 'height', 'image_count', 'video_count', 'audio_count',
                'asset_count', 'result_count', 'prompt_length', 'negative_prompt_length', 'content_length',
                'output_length', 'message_count', 'system_prompt_length', 'reference_image_count',
                'requested_quantity', 'actual_quantity', 'tenant_cost', 'user_price', 'error_code'], true)
                && is_scalar($item)) {
                $summary[$key] = is_string($item) ? mb_substr($item, 0, 500) : $item;
            }
        }
        return $summary;
    }

    private static function scopedRow(string $table, int $id, int $tenantId, int $userId): array
    {
        if ($id <= 0) return [];
        $query = Db::name($table)->where(['id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId]);
        if (in_array('delete_time', $query->getTableFields(), true)) $query->where('delete_time', 0);
        return $query->find() ?: [];
    }

    private static function inputs(array $row): array
    {
        $inputs = [];
        foreach (self::INPUT_FIELDS as $key) {
            if (isset($row[$key]) && is_scalar($row[$key]) && $row[$key] !== '') {
                $inputs[$key] = is_string($row[$key]) ? self::text($row[$key]) : $row[$key];
            }
        }
        return $inputs;
    }

    private static function inputMedia(array $row, int $tenantId, int $userId): array
    {
        $media = [];
        foreach (['reference_images' => 'image', 'reference_assets' => '', 'image_uri' => 'image', 'image_url' => 'image', 'audio_uri' => 'audio', 'audio_url' => 'audio', 'video_uri' => 'video', 'video_url' => 'video', 'first_frame' => 'image', 'last_frame' => 'image'] as $key => $type) {
            $items = in_array($key, ['reference_images', 'reference_assets'], true) ? self::arrayValue($row[$key] ?? []) : [$row[$key] ?? ''];
            foreach (array_slice($items, 0, 30) as $item) {
                $asset = is_array($item) ? $item : ['url' => $item];
                $kind = $type ?: self::text($asset['type'] ?? '');
                if (!in_array($kind, ['image', 'video', 'audio'], true)) continue;
                $uri = $asset['uri'] ?? $asset['url'] ?? $asset[$kind . '_uri'] ?? '';
                $metadata = $asset + array_intersect_key($row, array_flip(['storage_scope', 'storage_engine', 'storage_domain']));
                if (empty($metadata['storage_engine']) && is_string($uri) && $uri !== '') {
                    try {
                        $file = Db::name('tenant_file')->where(['tenant_id' => $tenantId, 'source_id' => $userId, 'source' => 1, 'uri' => $uri])
                            ->where(static fn ($query) => $query->whereNull('delete_time')->whereOr('delete_time', 0))->find();
                        if ($file) $metadata = array_replace($metadata, array_intersect_key($file, array_flip(['storage_scope', 'storage_engine', 'storage_domain'])));
                    } catch (\Throwable) {
                        // Older installations may have no managed-file metadata.
                    }
                }
                $url = self::url($uri, $metadata);
                if ($url !== '') $media[] = ['type' => $kind, 'url' => $url, 'thumb_url' => $kind === 'image' ? $url : ''];
            }
        }
        $unique = [];
        foreach ($media as $item) $unique[$item['type'] . ':' . $item['url']] = $item;
        return array_values($unique);
    }

    private static function managedAssets(array $task, array $usage): array
    {
        $query = Db::name('ai_task_result_asset')->where([
            'app_task_id' => (int)$task['id'], 'consumption_id' => (int)$usage['id'],
            'tenant_id' => (int)$task['tenant_id'], 'user_id' => (int)$task['user_id'],
        ]);
        $fields = $query->getTableFields();
        if (in_array('delete_time', $fields, true)) $query->where('delete_time', 0);
        if (in_array('transfer_status', $fields, true)) $query->whereIn('transfer_status', ['stored', 'external']);
        $rows = $query->select()->toArray();
        $media = [];
        foreach ($rows as $row) {
            if ((int)($row['external_expire_time'] ?? 0) > 0 && (int)$row['external_expire_time'] <= time() && empty($row['local_uri'])) continue;
            $type = (string)($row['asset_type'] ?? '');
            if (!in_array($type, ['image', 'video', 'audio'], true)) continue;
            $uri = ($row['local_uri'] ?? '') ?: ($row['external_url'] ?? '');
            $url = self::url($uri, $row);
            if ($url !== '') $media[] = ['id' => (int)$row['id'], 'type' => $type, 'url' => $url, 'thumb_url' => $type === 'image' ? $url : ''];
        }
        return $media;
    }

    private static function resultRows(string $table, int $taskId, int $tenantId, int $userId, string $type): array
    {
        $rows = Db::name($table)->where(['task_id' => $taskId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0])->select()->toArray();
        $media = [];
        foreach ($rows as $row) {
            $url = self::url($row[$type . '_uri'] ?? '', $row);
            if ($url !== '') $media[] = ['id' => (int)$row['id'], 'type' => $type, 'url' => $url, 'thumb_url' => self::url($row['cover_uri'] ?? '', $row)];
        }
        return $media;
    }

    private static function mediaProjection(array $rows): array
    {
        $media = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !in_array($row['type'] ?? '', ['image', 'video', 'audio'], true)) continue;
            if (!self::safeMediaUri($row['source_uri'] ?? $row['url'] ?? '', $row)) continue;
            $trusted = self::storedMedia($row);
            $url = self::safeUrl($row['url'] ?? '', $trusted);
            if ($url === '') continue;
            $media[] = ['id' => (int)($row['id'] ?? 0), 'type' => $row['type'], 'url' => $url, 'thumb_url' => self::safeUrl($row['thumb_url'] ?? '', $trusted)];
        }
        return $media;
    }

    private static function url($uri, array $metadata): string
    {
        if (!self::safeMediaUri($uri, $metadata)) return '';
        try {
            return self::safeUrl(FileService::getFileUrlByStorage($uri, (string)($metadata['storage_scope'] ?? ''), (string)($metadata['storage_engine'] ?? ''), (string)($metadata['storage_domain'] ?? '')), self::storedMedia($metadata));
        } catch (\Throwable) {
            return '';
        }
    }

    private static function safeMediaUri($uri, array $metadata): bool
    {
        if (!is_string($uri) || trim($uri) === '') return false;
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $uri) && !preg_match('#^https?://#i', $uri)) return false;
        if (parse_url($uri, PHP_URL_USER) !== null || parse_url($uri, PHP_URL_PASS) !== null) return false;
        parse_str((string)(parse_url($uri, PHP_URL_QUERY) ?? ''), $params);
        foreach (array_keys($params) as $key) {
            if (preg_match('/api.?key|authorization|password|secret|access.token/i', (string)$key)) return false;
            if (preg_match('/token|sign|credential|auth|(?:^|_)key$/i', (string)$key)) {
                $host = parse_url($uri, PHP_URL_HOST);
                $storageHost = parse_url((string)($metadata['storage_domain'] ?? ''), PHP_URL_HOST);
                if (!self::storedMedia($metadata) || !$host || !$storageHost || strcasecmp($host, $storageHost) !== 0) return false;
            }
        }
        return true;
    }

    private static function safeUrl($url, bool $storedMedia = false): string
    {
        if (!is_string($url) || !preg_match('#^https?://#i', $url) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) return '';
        $query = (string)(parse_url($url, PHP_URL_QUERY) ?? '');
        parse_str($query, $params);
        foreach (array_keys($params) as $key) {
            if (preg_match('/api.?key|authorization|password|secret|access.token/i', (string)$key)) return '';
            if (!$storedMedia && preg_match('/token|sign|credential|auth|(?:^|_)key$/i', (string)$key)) return '';
        }
        return $url;
    }

    private static function storedMedia(array $metadata): bool
    {
        return in_array($metadata['storage_scope'] ?? '', ['tenant', 'platform'], true)
            && in_array($metadata['storage_engine'] ?? '', ['qiniu', 'aliyun', 'qcloud'], true);
    }

    private static function frozenSourceModel(array $row): string
    {
        foreach (['model_json', 'model_snapshot'] as $field) {
            $snapshot = self::arrayValue($row[$field] ?? []);
            foreach (['model_name', 'display_name', 'name', 'label'] as $key) {
                if (is_string($snapshot[$key] ?? null) && trim($snapshot[$key]) !== '') return self::text($snapshot[$key]);
            }
        }
        return self::text($row['model_name'] ?? '');
    }

    private static function modelName(array $usage, bool $namesOnly = false): string
    {
        $snapshot = self::arrayValue($usage['price_snapshot'] ?? []);
        foreach ([$snapshot['model_name'] ?? '', $snapshot['product_name'] ?? '', $namesOnly ? '' : ($usage['product_name'] ?? ''), $namesOnly ? '' : ($usage['model_code'] ?? ''), $namesOnly ? '' : ($usage['api_code'] ?? '')] as $name) {
            if (is_string($name) && trim($name) !== '') return self::text($name);
        }
        return '';
    }

    private static function text($value): string
    {
        return is_scalar($value) ? mb_substr((string)$value, 0, 100000) : '';
    }

    private static function arrayValue($value): array
    {
        if (is_array($value)) return $value;
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }
}
