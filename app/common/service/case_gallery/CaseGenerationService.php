<?php

namespace app\common\service\case_gallery;

use app\common\model\app\aigc_image\AigcImageTask;
use app\common\model\app\aigc_video\AigcVideoTask;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanTask;
use app\common\service\FileService;

/** A case contains editable creation inputs, never billing, credentials or provider receipts. */
final class CaseGenerationService
{
    private const INPUTS = [
        'prompt', 'negative_prompt', 'style', 'channel', 'model_id', 'resource_type',
        'market_product_id', 'market_sku_id', 'variant', 'quality', 'resolution', 'ratio',
        'quantity', 'duration', 'mode', 'generation_method', 'material_mode',
        'advanced_params', 'provider_params', 'reference_images', 'reference_assets',
        'effect_id', 'effect_prompt', 'audio', 'is_rec', 'watermark', 'seed', 'wm_position', 'wm_url',
        'avatar_id', 'voice_id', 'script_text', 'title', 'audio_uri', 'audio_url', 'speed', 'drive_mode', 'driver_audio_uri',
    ];

    public static function request(array $input): array
    {
        $request = self::safe(array_intersect_key($input, array_flip(self::INPUTS)));
        if (isset($request['reference_images'])) {
            $request['reference_images'] = array_values(array_filter(array_map(
                [self::class, 'uri'], (array)$request['reference_images']
            )));
        }
        if (isset($request['reference_assets'])) {
            $assets = [];
            foreach ((array)$request['reference_assets'] as $asset) {
                if (!is_array($asset)) continue;
                $asset = array_intersect_key($asset, array_flip([
                    'type', 'media_type', 'uri', 'url', 'name', 'role', 'field', 'reference_slot_id',
                    'duration', 'width', 'height', 'storage_scope', 'storage_engine', 'storage_domain',
                ]));
                $uri = self::uri($asset['uri'] ?? $asset['url'] ?? '');
                $type = (string)($asset['type'] ?? $asset['media_type'] ?? 'image');
                if ($uri === '' || !in_array($type, ['image', 'video', 'audio', 'file'], true)) continue;
                $asset['uri'] = $uri;
                $asset['type'] = $type;
                unset($asset['url']);
                $assets[] = $asset;
            }
            $request['reference_assets'] = $assets;
        }
        return $request;
    }

    public static function fromTask(array $task, string $appCode): array
    {
        $model = self::array($task['model_json'] ?? []);
        $provider = self::safe(self::array($task['provider_params_json'] ?? []));
        $request = array_intersect_key($task, array_flip(self::INPUTS));
        $request['model_id'] = $model['model_id'] ?? $task['channel'] ?? '';
        if ($appCode === 'aigc_image') {
            $raw = self::array($task['provider_params_json'] ?? []);
            $request['material_mode'] = $raw['_material_mode'] ?? '';
            if (str_starts_with((string)($task['channel'] ?? ''), 'market_nano_banana:')) {
                foreach (['variant', 'market_sku_id'] as $key) {
                    if (array_key_exists($key, $provider)) $request[$key] = $provider[$key];
                    unset($provider[$key]);
                }
            }
            $request['provider_params'] = $provider;
        } elseif ($appCode === 'aigc_video') {
            foreach (['advanced_params', 'provider_params', 'material_mode', 'generation_method', 'mode', 'resource_type'] as $key) {
                if (array_key_exists($key, $model)) $request[$key] = $model[$key];
            }
            $request = array_replace($request, self::request(self::array($model['q4_options'] ?? [])));
            $request['duration'] = $task['request_duration'] ?? $model['duration'] ?? $task['duration'] ?? 0;
            $effect = self::array($model['effect_snapshot'] ?? []);
            if (!empty($effect['id'])) {
                $request['effect_id'] = $effect['id'];
                $request['effect_prompt'] = $effect['instruction'] ?? '';
            }
        } elseif ($appCode === 'aigc_digital_human') {
            $request['prompt'] = $task['script_text'] ?? '';
            $payload = self::array($task['provider_payload_json'] ?? []);
            $request['drive_mode'] = $payload['drive_mode'] ?? ((int)($task['voice_id'] ?? 0) > 0 ? 'text' : 'audio');
            if ($request['drive_mode'] === 'audio') $request['audio_uri'] = $task['tts_audio_uri'] ?? '';
        }
        return self::config(['model_name' => $task['model_name'] ?? $task['model'] ?? '', 'generation_request' => $request]);
    }

    public static function config(array $config): array
    {
        $input = isset($config['generation_request']) ? self::array($config['generation_request']) : $config;
        // Legacy cases used channel as the routing identity and model as a display name.
        if (!isset($input['model_id']) && !empty($input['channel'])) $input['model_id'] = $input['channel'];
        $request = self::request($input);
        $legacy = array_intersect_key($config, array_flip(['model', 'model_name', 'channel', 'quantity', 'ratio', 'quality', 'duration', 'fields', 'avatar_id', 'voice_id']));
        return self::safe(array_replace($legacy, array_intersect_key($request, array_flip(['channel', 'quantity', 'ratio', 'quality', 'duration', 'avatar_id', 'voice_id'])), [
            'schema_version' => 1, 'generation_request' => $request,
        ]));
    }

    /** Recover old snapshots in batches, scoped by tenant and app, without polling or writes. */
    public static function recoverRows(array $rows): array
    {
        $models = ['aigc_image' => AigcImageTask::class, 'aigc_video' => AigcVideoTask::class, 'aigc_digital_human' => AigcDigitalHumanTask::class];
        $groups = [];
        foreach ($rows as $row) {
            if (isset($row['config_json']['generation_request']) || empty($row['source_task_id']) || !isset($models[$row['app_code']])) continue;
            $groups[(int)$row['tenant_id']][$row['app_code']][] = (int)$row['source_task_id'];
        }
        $tasks = [];
        foreach ($groups as $tenantId => $apps) foreach ($apps as $app => $ids) {
            foreach ($models[$app]::where(['tenant_id' => $tenantId, 'delete_time' => 0, 'status' => 'success'])->whereIn('id', array_unique($ids))->select()->toArray() as $task) {
                $tasks[$tenantId][$app][(int)$task['id']] = $task;
            }
        }
        foreach ($rows as &$row) {
            if (isset($row['config_json']['generation_request'])) continue;
            $task = $tasks[(int)$row['tenant_id']][$row['app_code']][(int)($row['source_task_id'] ?? 0)] ?? null;
            if (!$task) continue;
            $config = self::fromTask($task, $row['app_code']);
            $legacy = self::config(self::array($row['config_json'] ?? []));
            $config['generation_request'] = array_replace($config['generation_request'], $legacy['generation_request']);
            $row['config_json'] = array_replace($config, array_diff_key($legacy, ['generation_request' => 1]));
            if (empty($row['reference_images'])) $row['reference_images'] = $config['generation_request']['reference_images'] ?? [];
        }
        unset($row);
        return $rows;
    }

    public static function present(array $row): array
    {
        $config = self::config(self::array($row['config_json'] ?? []));
        $request = $config['generation_request'];
        $request['prompt'] = (string)($row['prompt'] ?? '');
        $request['reference_images'] = array_values((array)($row['reference_images'] ?? $request['reference_images'] ?? []));
        foreach ($request['reference_assets'] ?? [] as $i => $asset) {
            $request['reference_assets'][$i]['url'] = FileService::getFileUrlByStorage($asset['uri'], (string)($asset['storage_scope'] ?? ''), (string)($asset['storage_engine'] ?? ''), (string)($asset['storage_domain'] ?? ''));
        }
        $row['config_json'] = $config;
        $row['generation_request'] = $request;
        $row['reference_images'] = $request['reference_images'];
        $row['reference_image_urls'] = array_map([FileService::class, 'getFileUrl'], $request['reference_images']);
        return $row;
    }

    private static function safe(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && (str_starts_with($key, '_') || preg_match('/(?:api[_-]?key|authorization|password|secret|token|credential|signature|billing|pricing|cost_points|idempotency)/i', $key))) continue;
            $result[$key] = is_array($item) ? self::safe($item) : $item;
        }
        return $result;
    }

    private static function uri($value): string
    {
        if (!is_string($value)) return '';
        $value = trim($value);
        if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $value) && !preg_match('~^https?://~i', $value)) return '';
        return $value;
    }

    private static function array($value): array
    {
        if (!is_array($value)) $value = json_decode((string)$value, true);
        return is_array($value) ? $value : [];
    }
}
