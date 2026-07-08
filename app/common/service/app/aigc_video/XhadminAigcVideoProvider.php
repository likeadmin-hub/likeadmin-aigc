<?php

namespace app\common\service\app\aigc_video;

use app\common\service\FileService;
use app\common\service\PointUnitService;
use app\common\service\update\UpdateSourceClient;
use Exception;

class XhadminAigcVideoProvider implements AigcVideoProviderInterface
{
    private const DEFAULT_SUBMIT_PATH = '/api/v1/tasks';
    private const DEFAULT_TASK_PATH = '/api/v1/tasks/{task_id}';

    public function generate(AigcVideoGenerateRequest $request): AigcVideoGenerateResult
    {
        try {
            $config = $this->resolveConfig($request);
            $this->assertSupportedQuantity($request);
            $payload = $this->buildPayload($request, $config);
            $submit = $this->request('POST', $config['submit_url'], $config['api_key'], $payload, (int)$config['timeout'], (bool)$config['ssl_verify']);
            $taskId = $this->extractTaskId($submit);
            if ($taskId === '') {
                return new AigcVideoGenerateResult(false, [], '供应商未返回任务ID');
            }
            if ((int)($config['poll_attempts'] ?? 0) === 0) {
                return new AigcVideoGenerateResult(true, [], '', $taskId);
            }
            $task = $this->pollTask($taskId, $config);
            if ($this->isTaskPending($task)) {
                return new AigcVideoGenerateResult(true, [], '', $taskId);
            }
            return $this->buildResultFromTask($task, $taskId, $request, $config);
        } catch (\Throwable $e) {
            return new AigcVideoGenerateResult(false, [], $this->friendlyError($e->getMessage()));
        }
    }

    public function fetchResult(string $taskId, AigcVideoGenerateRequest $request): AigcVideoGenerateResult
    {
        try {
            $config = $this->resolveConfig($request);
            $task = $this->request('GET', str_replace('{task_id}', rawurlencode($taskId), $config['task_url_template']), $config['api_key'], [], (int)$config['timeout'], (bool)$config['ssl_verify']);
            if ($this->isTaskPending($task)) {
                return new AigcVideoGenerateResult(true, [], '', $taskId);
            }
            return $this->buildResultFromTask($task, $taskId, $request, $config);
        } catch (\Throwable $e) {
            return new AigcVideoGenerateResult(false, [], $this->friendlyError($e->getMessage()), $taskId);
        }
    }

    private function buildResultFromTask(array $task, string $taskId, AigcVideoGenerateRequest $request, array $config): AigcVideoGenerateResult
    {
        $status = $this->extractTaskStatus($task);
        $videoUrls = $this->extractVideoUrls($task);
        if (!in_array($status, ['completed', 'success', 'succeeded'], true) && !empty($videoUrls)) {
            $status = 'success';
        }
        if (!in_array($status, ['completed', 'success', 'succeeded'], true)) {
            return new AigcVideoGenerateResult(false, [], $this->extractError($task) ?: '供应商任务未完成', $taskId);
        }
        if (empty($videoUrls)) {
            return new AigcVideoGenerateResult(false, [], '供应商未返回视频', $taskId);
        }
        $videos = [];
        foreach ($videoUrls as $videoUrl) {
            if (!is_string($videoUrl) || $videoUrl === '') {
                continue;
            }
            $stored = AigcVideoAssetService::persistGeneratedVideo($videoUrl, (int)($config['tenant_id'] ?? 0), (int)($config['user_id'] ?? 0));
            $videos[] = array_merge($stored, [
                'width' => (int)($stored['width'] ?? 0) ?: (int)($request->spec['width'] ?? 0),
                'height' => (int)($stored['height'] ?? 0) ?: (int)($request->spec['height'] ?? 0),
                'provider_task_id' => $taskId,
            ]);
        }
        if (empty($videos)) {
            return new AigcVideoGenerateResult(false, [], '供应商视频格式错误', $taskId);
        }
        return new AigcVideoGenerateResult(true, $videos, '', $taskId);
    }

    private function resolveConfig(AigcVideoGenerateRequest $request): array
    {
        $config = array_merge($request->channelConfig, $request->providerParams['channel_config'] ?? []);
        $source = UpdateSourceClient::getSource();
        $baseUrl = $this->sourceBaseUrl((string)($source['active_base_url'] ?? $source['base_url'] ?? ''));
        if ($baseUrl === '') {
            throw new Exception('请先在系统服务的接口渠道中配置 Base URL');
        }
        $submitPath = (string)($config['submit_path'] ?? self::DEFAULT_SUBMIT_PATH);
        $taskPath = (string)($config['task_path'] ?? self::DEFAULT_TASK_PATH);
        $apiKey = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? ''));
        if ($apiKey === '') {
            throw new Exception('请先在系统服务的接口渠道中配置 API Key');
        }
        return [
            'api_key' => $apiKey,
            'model' => (string)($config['model'] ?? $request->providerParams['model'] ?? 'grok-video'),
            'app_code' => (string)($config['app_code'] ?? $request->channel),
            'submit_url' => $baseUrl . '/' . ltrim($submitPath, '/'),
            'task_url_template' => $baseUrl . '/' . ltrim($taskPath, '/'),
            'asset_group_url' => $baseUrl . '/' . ltrim((string)($config['asset_group_path'] ?? '/api/v1/apps/seedance/createGroup'), '/'),
            'asset_create_url' => $baseUrl . '/' . ltrim((string)($config['asset_create_path'] ?? '/api/v1/apps/seedance/createAsset'), '/'),
            'timeout' => max(5, (int)($config['timeout'] ?? 30)),
            'poll_interval' => max(1, (int)($config['poll_interval'] ?? 2)),
            'poll_attempts' => max(0, (int)($config['poll_attempts'] ?? 30)),
            'upstream_channel' => (string)($config['upstream_channel'] ?? $config['channel'] ?? ''),
            'tenant_id' => (int)($config['tenant_id'] ?? 0),
            'user_id' => (int)($config['user_id'] ?? 0),
            'ssl_verify' => UpdateSourceClient::sslVerify($source),
            'extra_payload' => is_array($config['extra_payload'] ?? null) ? $config['extra_payload'] : [],
            'project_name' => (string)($config['project_name'] ?? 'default'),
            'group_type' => (string)($config['group_type'] ?? 'AIGC'),
            'seedance_asset_id_key' => (string)($config['seedance_asset_id_key'] ?? 'asset_id'),
        ];
    }

    private function sourceBaseUrl(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') {
            return '';
        }
        $parts = parse_url($baseUrl);
        $scheme = (string)($parts['scheme'] ?? 'https');
        $host = (string)($parts['host'] ?? '');
        if ($host === '') {
            return rtrim($baseUrl, '/');
        }
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        return $scheme . '://' . $host . $port;
    }

    private function buildPayload(AigcVideoGenerateRequest $request, array $config): array
    {
        $appCode = (string)($config['app_code'] ?? '');
        if ($appCode === 'seedance2_pro') {
            return $this->buildSeedance2ProPayload($request, $config);
        }
        if ($appCode === 'seedance') {
            return $this->buildSeedancePayload($request, $config);
        }
        if ($appCode === 'wan') {
            return $this->buildWanPayload($request, $config);
        }
        if ($appCode === 'omni_flash_ext') {
            return $this->buildOmniFlashExtPayload($request, $config);
        }
        return $this->filterPayload(array_merge([
            'model' => $request->providerParams['model'] ?? $config['model'],
            'n' => 1,
            'prompt' => $request->prompt,
            'channel' => $request->providerParams['channel'] ?? ($config['upstream_channel'] ?: null),
            'image_urls' => $this->normalizeReferenceImageUrls($request->referenceImages),
            'quality' => $request->providerParams['quality'] ?? $config['quality'] ?? '720p',
            'aspect_ratio' => $request->providerParams['aspect_ratio'] ?? $request->ratio,
            'duration' => (int)($request->providerParams['duration'] ?? ($request->quality ?: 6)),
            'negative_prompt' => $request->negativePrompt ?: null,
        ], $config['extra_payload']));
    }

    private function buildSeedance2ProPayload(AigcVideoGenerateRequest $request, array $config): array
    {
        AigcVideoReferenceAssetService::assertSeedance2ProSupported($request->referenceAssets);
        $assets = $this->groupReferenceAssetUrls($request->referenceAssets);
        $imageUrls = array_slice($assets[AigcVideoReferenceAssetService::TYPE_IMAGE] ?: $this->normalizeReferenceImageUrls($request->referenceImages), 0, 9);
        $videoUrls = array_slice($assets[AigcVideoReferenceAssetService::TYPE_VIDEO] ?? [], 0, 3);
        $videoReferences = $this->buildVideoReferences($request->referenceAssets);
        $audioReferences = $this->buildAudioReferences($request->referenceAssets);
        $aspectRatio = (string)($request->providerParams['aspect_ratio'] ?? $request->providerParams['ratio'] ?? $request->ratio ?: 'adaptive');
        $duration = max(4, min(15, (int)($request->providerParams['duration'] ?? ($request->quality ?: 5))));
        $payload = [
            'prompt' => $request->prompt,
            'mode' => $this->normalizeSeedance2ProMode($request->providerParams['mode'] ?? $config['mode'] ?? 'pro'),
            'aspect_ratio' => $aspectRatio,
            'duration' => $duration,
            'image_urls' => $imageUrls,
            'video_urls' => $videoUrls,
            'video_references' => $videoReferences,
            'audio_references' => $audioReferences,
            'audio_urls' => array_values(array_filter(array_map(static fn(array $item) => (string)($item['url'] ?? ''), $audioReferences))),
            'callback_url' => $request->providerParams['callback_url'] ?? $config['callback_url'] ?? null,
        ];
        return $this->filterPayload(array_merge($payload, $config['extra_payload']));
    }

    private function buildWanPayload(AigcVideoGenerateRequest $request, array $config): array
    {
        $assets = $this->groupReferenceAssetUrls($request->referenceAssets);
        $imageUrls = $assets['image'] ?: $this->normalizeReferenceImageUrls($request->referenceImages);
        $videoUrls = $assets['video'];
        $model = (string)($request->providerParams['model'] ?? $config['model'] ?? '');
        if ($model === '' || $model === 'grok-video') {
            $model = 'wan2.7';
        }
        if (!empty($videoUrls) && !isset($request->providerParams['model'])) {
            $model = 'wan2.7-videoedit';
        } elseif (!empty($imageUrls) && !isset($request->providerParams['model']) && $model === 'wan2.7') {
            $model = 'wan2.7-r2v';
        }
        $payload = [
            'model' => $model,
            'prompt' => $request->prompt,
            'resolution' => $request->providerParams['resolution'] ?? $request->providerParams['quality'] ?? $config['resolution'] ?? $config['quality'] ?? '720p',
            'duration' => (int)($request->providerParams['duration'] ?? ($request->quality ?: 5)),
            'size' => $request->providerParams['size'] ?? $request->providerParams['aspect_ratio'] ?? $request->ratio,
            'image_urls' => $imageUrls,
            'video_urls' => $videoUrls,
            'audio_url' => $assets['audio'][0] ?? null,
            'negative_prompt' => $request->negativePrompt ?: null,
            'prompt_extend' => $request->providerParams['prompt_extend'] ?? null,
            'seed' => $request->providerParams['seed'] ?? null,
            'watermark' => $request->providerParams['watermark'] ?? null,
            'metadata' => $request->providerParams['metadata'] ?? null,
        ];
        if ($model === 'wan2.7-r2v' && !empty($imageUrls)) {
            $payload['image_with_roles'] = array_map(static fn($url) => [
                'url' => $url,
                'role' => 'reference_image',
            ], array_slice($imageUrls, 0, 2));
        }
        return $this->filterPayload(array_merge($payload, $config['extra_payload']));
    }

    private function buildOmniFlashExtPayload(AigcVideoGenerateRequest $request, array $config): array
    {
        $assets = $this->groupReferenceAssetUrls($request->referenceAssets);
        $imageUrls = $assets['image'] ?: $this->normalizeReferenceImageUrls($request->referenceImages);
        return $this->filterPayload(array_merge([
            'prompt' => $request->prompt,
            'duration' => (int)($request->providerParams['duration'] ?? ($request->quality ?: 6)),
            'resolution' => $request->providerParams['resolution'] ?? $request->providerParams['quality'] ?? $config['resolution'] ?? $config['quality'] ?? '720p',
            'aspect_ratio' => $request->providerParams['aspect_ratio'] ?? $request->ratio,
            'size' => $request->providerParams['size'] ?? $request->ratio,
            'image_urls' => $imageUrls,
        ], $config['extra_payload']));
    }

    private function buildSeedancePayload(AigcVideoGenerateRequest $request, array $config): array
    {
        AigcVideoReferenceAssetService::assertSeedanceSupported($request->referenceAssets);
        $uploadedAssetIds = $this->uploadSeedanceAssets($request, $config);
        $hasVideo = false;
        $assetUrls = [
            AigcVideoReferenceAssetService::TYPE_IMAGE => [],
            AigcVideoReferenceAssetService::TYPE_VIDEO => [],
            AigcVideoReferenceAssetService::TYPE_AUDIO => [],
        ];
        $content = [[
            'type' => 'text',
            'text' => $request->prompt,
        ]];
        foreach ($uploadedAssetIds as $item) {
            $type = (string)($item['type'] ?? AigcVideoReferenceAssetService::TYPE_IMAGE);
            if ($type === AigcVideoReferenceAssetService::TYPE_VIDEO) {
                $hasVideo = true;
            }
            if (isset($assetUrls[$type])) {
                $assetUrls[$type][] = $this->seedanceAssetUri((string)($item['asset_id'] ?? ''));
            }
        }
        $configuredModel = (string)($request->providerParams['model'] ?? $config['model'] ?? '');
        if ($configuredModel === 'grok-video') {
            $configuredModel = '';
        }
        $model = $configuredModel !== '' ? $configuredModel : ($hasVideo ? 'seedance-2-video-2-video' : 'seedance-2-text-2-video');
        if ($configuredModel === 'seedance-2-text-2-video' && $hasVideo && empty($request->providerParams['model'])) {
            $model = 'seedance-2-video-2-video';
        }
        return $this->filterPayload(array_merge([
            'model' => $model,
            'ratio' => $request->providerParams['ratio'] ?? $request->ratio,
            'content' => $content,
            'image_urls' => $assetUrls[AigcVideoReferenceAssetService::TYPE_IMAGE],
            'video_urls' => $assetUrls[AigcVideoReferenceAssetService::TYPE_VIDEO],
            'audio_urls' => $assetUrls[AigcVideoReferenceAssetService::TYPE_AUDIO],
            'duration' => (int)($request->providerParams['duration'] ?? ($request->quality ?: 5)),
            'resolution' => $request->providerParams['resolution'] ?? $request->providerParams['quality'] ?? $config['resolution'] ?? $config['quality'] ?? '720p',
            'negative_prompt' => $request->negativePrompt ?: null,
            'seed' => $request->providerParams['seed'] ?? null,
            'watermark' => $request->providerParams['watermark'] ?? null,
            'camera_fixed' => $request->providerParams['camera_fixed'] ?? null,
            'generate_audio' => $request->providerParams['generate_audio'] ?? null,
            'service_tier' => $request->providerParams['service_tier'] ?? null,
        ], $config['extra_payload']));
    }

    private function uploadSeedanceAssets(AigcVideoGenerateRequest $request, array $config): array
    {
        if (empty($request->referenceAssets)) {
            return [];
        }
        $group = $this->request('POST', $config['asset_group_url'], $config['api_key'], [
            'Name' => 'aigc-video-' . date('YmdHis') . '-' . substr(md5($request->prompt . microtime(true)), 0, 6),
            'GroupType' => $config['group_type'] ?: 'AIGC',
            'Description' => mb_substr($request->prompt, 0, 120),
            'ProjectName' => $config['project_name'] ?: 'default',
        ], (int)$config['timeout'], (bool)$config['ssl_verify']);
        $groupId = $this->extractAssetGroupId($group);
        if ($groupId === '') {
            throw new Exception('Seedance素材组创建失败：未返回GroupId');
        }
        $uploaded = [];
        foreach ($request->referenceAssets as $index => $asset) {
            $url = AigcVideoReferenceAssetService::publicUrl($asset);
            if ($url === '') {
                continue;
            }
            $response = $this->request('POST', $config['asset_create_url'], $config['api_key'], [
                'URL' => $url,
                'Name' => $this->seedanceAssetName($asset, $index),
                'GroupId' => $groupId,
                'AssetType' => $this->seedanceAssetType((string)($asset['type'] ?? 'image')),
                'ProjectName' => $config['project_name'] ?: 'default',
            ], (int)$config['timeout'], (bool)$config['ssl_verify']);
            $assetId = $this->extractAssetId($response);
            if ($assetId === '') {
                throw new Exception('Seedance素材上传失败：未返回AssetId');
            }
            $uploaded[] = [
                'type' => (string)($asset['type'] ?? 'image'),
                'asset_id' => $assetId,
            ];
        }
        return $uploaded;
    }

    private function seedanceAssetUri(string $assetId): string
    {
        $assetId = trim($assetId);
        if ($assetId === '' || str_starts_with($assetId, 'asset://')) {
            return $assetId;
        }
        return 'asset://' . $assetId;
    }

    private function groupReferenceAssetUrls(array $assets): array
    {
        $grouped = ['image' => [], 'video' => [], 'audio' => []];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $type = (string)($asset['type'] ?? 'image');
            if (!isset($grouped[$type])) {
                continue;
            }
            $url = AigcVideoReferenceAssetService::publicUrl($asset);
            if ($url !== '' && !in_array($url, $grouped[$type], true)) {
                $grouped[$type][] = $url;
            }
        }
        return $grouped;
    }

    private function buildAudioReferences(array $assets): array
    {
        $items = [];
        foreach ($assets as $asset) {
            if (!is_array($asset) || ($asset['type'] ?? '') !== AigcVideoReferenceAssetService::TYPE_AUDIO) {
                continue;
            }
            $url = AigcVideoReferenceAssetService::publicUrl($asset);
            if ($url === '') {
                continue;
            }
            $item = ['url' => $url];
            foreach (['duration', 'start', 'end'] as $key) {
                if (isset($asset[$key]) && is_numeric($asset[$key])) {
                    $item[$key] = max(0, (float)$asset[$key]);
                }
            }
            $items[] = $item;
            if (count($items) >= 3) {
                break;
            }
        }
        return $items;
    }

    private function buildVideoReferences(array $assets): array
    {
        $items = [];
        foreach ($assets as $asset) {
            if (!is_array($asset) || ($asset['type'] ?? '') !== AigcVideoReferenceAssetService::TYPE_VIDEO) {
                continue;
            }
            $url = AigcVideoReferenceAssetService::publicUrl($asset);
            if ($url === '') {
                continue;
            }
            $item = ['url' => $url];
            foreach (['duration', 'start', 'end'] as $key) {
                if (isset($asset[$key]) && is_numeric($asset[$key])) {
                    $item[$key] = max(0, (float)$asset[$key]);
                }
            }
            $items[] = $item;
            if (count($items) >= 3) {
                break;
            }
        }
        return $items;
    }

    private function normalizeSeedance2ProMode($mode): string
    {
        $mode = strtolower(trim((string)$mode));
        return in_array($mode, ['pro', 'fast'], true) ? $mode : 'pro';
    }

    private function seedanceAssetName(array $asset, int $index): string
    {
        $name = trim((string)($asset['name'] ?? ''));
        if ($name === '') {
            $name = (string)($asset['type'] ?? 'asset') . '-' . ((int)$index + 1);
        }
        return mb_substr($name, 0, 64);
    }

    private function seedanceAssetType(string $type): string
    {
        return match ($type) {
            AigcVideoReferenceAssetService::TYPE_VIDEO => 'Video',
            AigcVideoReferenceAssetService::TYPE_AUDIO => 'Audio',
            default => 'Image',
        };
    }

    private function filterPayload(array $payload): array
    {
        return array_filter($payload, static fn($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function normalizeReferenceImageUrls(array $images): array
    {
        $urls = [];
        foreach ($images as $image) {
            $image = trim((string)$image);
            if ($image === '') {
                continue;
            }
            if (!str_starts_with($image, 'http://') && !str_starts_with($image, 'https://') && !str_starts_with($image, 'data:image/')) {
                $image = FileService::getFileUrl($image);
            }
            if ($image !== '' && !in_array($image, $urls, true)) {
                $urls[] = $image;
            }
        }
        return $urls;
    }

    private function assertSupportedQuantity(AigcVideoGenerateRequest $request): void
    {
        if ($request->quantity !== 1) {
            throw new Exception('当前通道仅支持每次生成1条视频');
        }
    }

    private function pollTask(string $taskId, array $config): array
    {
        $url = str_replace('{task_id}', rawurlencode($taskId), $config['task_url_template']);
        $last = [];
        for ($i = 0; $i < (int)$config['poll_attempts']; $i++) {
            if ($i > 0) {
                sleep((int)$config['poll_interval']);
            }
            $last = $this->request('GET', $url, $config['api_key'], [], (int)$config['timeout'], (bool)$config['ssl_verify']);
            $status = $this->extractTaskStatus($last);
            if (in_array($status, ['completed', 'success', 'succeeded', 'failed', 'error', 'canceled'], true)) {
                return $last;
            }
        }
        return $last ?: ['status' => 'timeout'];
    }

    private function isTaskPending(array $task): bool
    {
        $status = $this->extractTaskStatus($task);
        return $status === '' || in_array($status, ['pending', 'running', 'processing', 'queued', 'created', 'submitted', 'timeout'], true);
    }

    private function request(string $method, string $url, string $apiKey, array $payload = [], int $timeout = 30, bool $sslVerify = false): array
    {
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno) {
            throw new Exception($error ?: '供应商网络请求失败');
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            throw new Exception('供应商响应格式错误');
        }
        if ($httpCode >= 400 || isset($data['error']) || (isset($data['code']) && (int)$data['code'] !== 1)) {
            throw new Exception($this->extractError($data) ?: '供应商请求失败');
        }
        return $data;
    }

    private function extractTaskId(array $data): string
    {
        foreach ([
            $data['task_id'] ?? null,
            $data['id'] ?? null,
            $data['TaskId'] ?? null,
            $data['data']['task_id'] ?? null,
            $data['data']['id'] ?? null,
            $data['data']['TaskId'] ?? null,
            $data['Result']['TaskId'] ?? null,
            $data['Result']['task_id'] ?? null,
            $data['data']['task']['id'] ?? null,
            $data['data']['task']['task_id'] ?? null,
        ] as $value) {
            if (is_scalar($value) && (string)$value !== '') {
                return (string)$value;
            }
        }
        return '';
    }

    private function extractAssetGroupId(array $data): string
    {
        foreach ([
            $data['GroupId'] ?? null,
            $data['group_id'] ?? null,
            $data['id'] ?? null,
            $data['data']['GroupId'] ?? null,
            $data['data']['group_id'] ?? null,
            $data['data']['id'] ?? null,
            $data['Result']['Id'] ?? null,
            $data['Result']['GroupId'] ?? null,
            $data['Result']['group_id'] ?? null,
            $data['data']['result']['Id'] ?? null,
            $data['data']['result']['GroupId'] ?? null,
            $data['data']['Result']['Id'] ?? null,
            $data['data']['Result']['GroupId'] ?? null,
        ] as $value) {
            if (is_scalar($value) && (string)$value !== '') {
                return (string)$value;
            }
        }
        return '';
    }

    private function extractAssetId(array $data): string
    {
        foreach ([
            $data['AssetId'] ?? null,
            $data['asset_id'] ?? null,
            $data['id'] ?? null,
            $data['data']['AssetId'] ?? null,
            $data['data']['asset_id'] ?? null,
            $data['data']['id'] ?? null,
            $data['Result']['AssetId'] ?? null,
            $data['Result']['Id'] ?? null,
            $data['Result']['asset_id'] ?? null,
            $data['data']['result']['AssetId'] ?? null,
            $data['data']['result']['Id'] ?? null,
            $data['data']['result']['asset_id'] ?? null,
            $data['data']['Result']['AssetId'] ?? null,
            $data['data']['Result']['Id'] ?? null,
        ] as $value) {
            if (is_scalar($value) && (string)$value !== '') {
                return (string)$value;
            }
        }
        return '';
    }

    private function extractTaskStatus(array $data): string
    {
        foreach ([
            $data['status'] ?? null,
            $data['state'] ?? null,
            $data['task_status'] ?? null,
            $data['data']['status'] ?? null,
            $data['data']['state'] ?? null,
            $data['data']['task_status'] ?? null,
            $data['data']['result']['status'] ?? null,
            $data['data']['result']['state'] ?? null,
            $data['data']['result']['task_status'] ?? null,
            $data['result']['status'] ?? null,
            $data['result']['state'] ?? null,
            $data['result']['task_status'] ?? null,
            $data['data']['task']['status'] ?? null,
            $data['data']['task']['state'] ?? null,
            $data['data']['task']['task_status'] ?? null,
        ] as $value) {
            if (!is_scalar($value) || (string)$value === '') {
                continue;
            }
            return $this->normalizeStatus((string)$value);
        }
        return '';
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            '1' => 'success',
            '2', '3' => 'failed',
            'done', 'finished', 'finish', 'complete' => 'completed',
            'ok' => 'success',
            'failure', 'fail' => 'failed',
            'cancelled', 'cancel' => 'canceled',
            default => $status,
        };
    }

    private function extractVideoUrls(array $data): array
    {
        $candidates = [
            $data['result']['videos'] ?? null,
            $data['data']['result']['videos'] ?? null,
            $data['data']['urls'] ?? null,
            $data['urls'] ?? null,
            $data['data']['results'] ?? null,
            $data['results'] ?? null,
            $data['data']['videos'] ?? null,
            $data['videos'] ?? null,
            $data['output'] ?? null,
            $data['data']['output'] ?? null,
            $data['data']['result'] ?? null,
            $data['result'] ?? null,
            $data['data']['video_url'] ?? null,
            $data['video_url'] ?? null,
            $data['data']['url'] ?? null,
            $data['url'] ?? null,
        ];
        $urls = [];
        foreach ($candidates as $candidate) {
            $this->collectVideoUrls($candidate, $urls);
            if (!empty($urls)) {
                break;
            }
        }
        return array_values(array_unique($urls));
    }

    private function collectVideoUrls(mixed $value, array &$urls): void
    {
        if (is_string($value)) {
            if ($this->isVideoUrlLike($value)) {
                $urls[] = $value;
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach (['url', 'video_url', 'video', 'uri', 'src', 'origin_url', 'download_url'] as $key) {
            if (!empty($value[$key]) && is_string($value[$key]) && $this->isVideoUrlLike($value[$key])) {
                $urls[] = $value[$key];
            }
        }
        foreach ($value as $item) {
            if (is_string($item)) {
                if ($this->isVideoUrlLike($item)) {
                    $urls[] = $item;
                }
                continue;
            }
            if (is_array($item)) {
                $this->collectVideoUrls($item, $urls);
            }
        }
    }

    private function isVideoUrlLike(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if (str_starts_with($value, 'data:video/')) {
            return true;
        }
        $path = (string)(parse_url($value, PHP_URL_PATH) ?: $value);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true)) {
            return true;
        }
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }

    private function extractError(array $data): string
    {
        $error = $data['error'] ?? $data['data']['error'] ?? null;
        if (is_array($error)) {
            return (string)($error['message'] ?? $error['code'] ?? '');
        }
        if (is_string($error)) {
            return $error;
        }
        $message = (string)($data['message'] ?? $data['data']['message'] ?? $data['msg'] ?? $data['data']['msg'] ?? '');
        return strtolower($message) === 'success' ? '' : $message;
    }

    private function friendlyError(string $message): string
    {
        if ($message === '') {
            return '视频通道调用失败';
        }
        $lower = strtolower($message);
        return match (true) {
            str_contains($lower, 'certificate key usage'),
            str_contains($lower, 'certificate verify failed'),
            str_contains($lower, 'ssl certificate') => '接口渠道 SSL 证书校验失败，请在系统服务 > 接口渠道关闭 SSL校验，或更换符合规范的 HTTPS 证书',
            str_contains($lower, 'insufficient_points'),
            str_contains($message, '余额不足'),
            str_contains($message, '点数余额不足') => '供应商' . PointUnitService::unit() . '余额不足，请联系平台管理员',
            str_contains($lower, 'auth_failed'),
            str_contains($lower, 'api key') => '供应商鉴权失败，请检查系统服务接口渠道配置',
            str_contains($lower, 'queue_limit_exceeded') => '供应商排队任务已满，请稍后再试',
            default => $message,
        };
    }
}
