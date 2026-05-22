<?php

namespace app\common\service\app\aigc_video;

use app\common\service\FileService;
use app\common\service\update\UpdateSourceClient;
use Exception;

class HappyHorseAigcVideoProvider implements AigcVideoProviderInterface
{
    private const DEFAULT_SUBMIT_PATH = '/api/v1/apps/happy_horse/submit';
    private const DEFAULT_QUERY_PATH = '/api/v1/apps/happy_horse/query';

    public function generate(AigcVideoGenerateRequest $request): AigcVideoGenerateResult
    {
        try {
            $config = $this->resolveConfig($request);
            $this->assertSupportedRequest($request);
            $payload = $this->buildPayload($request, $config);
            $submit = $this->request('POST', $config['submit_url'], $config['api_key'], $payload, (int)$config['timeout'], (bool)$config['ssl_verify']);
            $taskId = $this->extractTaskId($submit);
            if ($taskId === '') {
                return new AigcVideoGenerateResult(false, [], 'HappyHorse未返回任务ID');
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
            $task = $this->request('POST', $config['query_url'], $config['api_key'], ['task_id' => $taskId], (int)$config['timeout'], (bool)$config['ssl_verify']);
            if ($this->isTaskPending($task)) {
                return new AigcVideoGenerateResult(true, [], '', $taskId);
            }
            return $this->buildResultFromTask($task, $taskId, $request, $config);
        } catch (\Throwable $e) {
            return new AigcVideoGenerateResult(false, [], $this->friendlyError($e->getMessage()), $taskId);
        }
    }

    private function resolveConfig(AigcVideoGenerateRequest $request): array
    {
        $config = array_merge($request->channelConfig, $request->providerParams['channel_config'] ?? []);
        $source = UpdateSourceClient::getSource();
        $baseUrl = $this->sourceBaseUrl((string)($source['active_base_url'] ?? $source['base_url'] ?? ''));
        if ($baseUrl === '') {
            throw new Exception('请先在系统服务的接口渠道中配置 Base URL');
        }
        $apiKey = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? ''));
        if ($apiKey === '') {
            throw new Exception('请先在系统服务的接口渠道中配置 API Key');
        }
        $submitPath = (string)($config['submit_path'] ?? self::DEFAULT_SUBMIT_PATH);
        $queryPath = (string)($config['query_path'] ?? $config['task_path'] ?? self::DEFAULT_QUERY_PATH);
        return [
            'api_key' => $apiKey,
            'submit_url' => $baseUrl . '/' . ltrim($submitPath, '/'),
            'query_url' => $baseUrl . '/' . ltrim($queryPath, '/'),
            'timeout' => max(5, (int)($config['timeout'] ?? 30)),
            'poll_interval' => max(1, (int)($config['poll_interval'] ?? 2)),
            'poll_attempts' => max(0, (int)($config['poll_attempts'] ?? 0)),
            'tenant_id' => (int)($config['tenant_id'] ?? 0),
            'user_id' => (int)($config['user_id'] ?? 0),
            'ssl_verify' => UpdateSourceClient::sslVerify($source),
            'extra_payload' => is_array($config['extra_payload'] ?? null) ? $config['extra_payload'] : [],
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

    private function assertSupportedRequest(AigcVideoGenerateRequest $request): void
    {
        if ($request->quantity !== 1) {
            throw new Exception('HappyHorse当前仅支持每次生成1条视频');
        }
        $count = count($this->normalizeReferenceImageUrls($request->referenceImages));
        if ($count > 9) {
            throw new Exception('HappyHorse最多支持9张参考图');
        }
    }

    private function buildPayload(AigcVideoGenerateRequest $request, array $config): array
    {
        $media = $this->buildMedia($request->referenceImages);
        $model = $this->resolveModel($request, count($media));
        $resolution = strtoupper((string)($request->providerParams['resolution'] ?? $request->providerParams['quality'] ?? '720P'));
        if (!in_array($resolution, ['720P', '1080P'], true)) {
            $resolution = '720P';
        }
        $duration = $this->resolveDuration($request);
        $payload = [
            'model' => $model,
            'prompt' => $request->prompt,
            'resolution' => $resolution,
            'duration' => $duration,
            'watermark' => (bool)($request->providerParams['watermark'] ?? true),
        ];
        if (in_array($model, ['happyhorse-1.0-t2v', 'happyhorse-1.0-r2v'], true) && $request->ratio !== '') {
            $payload['ratio'] = $request->providerParams['ratio'] ?? $request->providerParams['aspect_ratio'] ?? $request->ratio;
        }
        if (isset($request->providerParams['seed']) && $request->providerParams['seed'] !== '') {
            $payload['seed'] = max(0, min(2147483647, (int)$request->providerParams['seed']));
        }
        if (!empty($media)) {
            $payload['media'] = $media;
        }
        return array_filter(array_merge($payload, $config['extra_payload']), static fn($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function resolveModel(AigcVideoGenerateRequest $request, int $mediaCount): string
    {
        $configured = (string)($request->providerParams['model'] ?? '');
        if (in_array($configured, ['happyhorse-1.0-t2v', 'happyhorse-1.0-i2v', 'happyhorse-1.0-r2v'], true)) {
            return $configured;
        }
        if ($mediaCount === 0) {
            return 'happyhorse-1.0-t2v';
        }
        if ($mediaCount === 1) {
            return 'happyhorse-1.0-i2v';
        }
        return 'happyhorse-1.0-r2v';
    }

    private function resolveDuration(AigcVideoGenerateRequest $request): int
    {
        $value = (string)($request->providerParams['duration'] ?? '');
        if ($value === '') {
            $value = $request->quality;
        }
        if (preg_match('/(?:^|_)(3|5|10|15)(?:s|秒)?$/i', $value, $matches)) {
            return (int)$matches[1];
        }
        $duration = (int)$value;
        return in_array($duration, [3, 5, 10, 15], true) ? $duration : 5;
    }

    private function buildMedia(array $images): array
    {
        $media = [];
        foreach ($this->normalizeReferenceImageUrls($images) as $url) {
            $media[] = ['url' => $url, 'type' => 'image'];
        }
        return $media;
    }

    private function normalizeReferenceImageUrls(array $images): array
    {
        $urls = [];
        foreach ($images as $image) {
            $image = trim((string)$image);
            if ($image === '') {
                continue;
            }
            if (str_starts_with($image, 'data:image/')) {
                throw new Exception('HappyHorse参考图需为已上传图片或公网图片');
            }
            if (!str_starts_with($image, 'http://') && !str_starts_with($image, 'https://')) {
                $image = FileService::getFileUrl($image);
            }
            if ($image !== '' && !in_array($image, $urls, true)) {
                $urls[] = $image;
            }
        }
        return $urls;
    }

    private function pollTask(string $taskId, array $config): array
    {
        $last = [];
        for ($i = 0; $i < (int)$config['poll_attempts']; $i++) {
            if ($i > 0) {
                sleep((int)$config['poll_interval']);
            }
            $last = $this->request('POST', $config['query_url'], $config['api_key'], ['task_id' => $taskId], (int)$config['timeout'], (bool)$config['ssl_verify']);
            $status = $this->extractTaskStatus($last);
            if (in_array($status, ['completed', 'success', 'succeeded', 'failed', 'error', 'canceled'], true)) {
                return $last;
            }
        }
        return $last ?: ['status' => 'timeout'];
    }

    private function buildResultFromTask(array $task, string $taskId, AigcVideoGenerateRequest $request, array $config): AigcVideoGenerateResult
    {
        $status = $this->extractTaskStatus($task);
        $videoUrls = $this->extractVideoUrls($task);
        if (!in_array($status, ['completed', 'success', 'succeeded'], true) && !empty($videoUrls)) {
            $status = 'success';
        }
        if (!in_array($status, ['completed', 'success', 'succeeded'], true)) {
            return new AigcVideoGenerateResult(false, [], $this->extractError($task) ?: 'HappyHorse任务未完成', $taskId);
        }
        if (empty($videoUrls)) {
            return new AigcVideoGenerateResult(false, [], 'HappyHorse未返回视频', $taskId);
        }
        $videos = [];
        foreach ($videoUrls as $videoUrl) {
            try {
                $stored = AigcVideoAssetService::persistGeneratedVideo($videoUrl, (int)($config['tenant_id'] ?? 0), (int)($config['user_id'] ?? 0));
                $videos[] = array_merge($stored, [
                    'width' => (int)($stored['width'] ?? 0) ?: (int)($request->spec['width'] ?? 0),
                    'height' => (int)($stored['height'] ?? 0) ?: (int)($request->spec['height'] ?? 0),
                    'provider_task_id' => $taskId,
                ]);
            } catch (\Throwable) {
                $videos[] = [
                    'uri' => $videoUrl,
                    'width' => (int)($request->spec['width'] ?? 0),
                    'height' => (int)($request->spec['height'] ?? 0),
                    'provider_task_id' => $taskId,
                ];
            }
        }
        return new AigcVideoGenerateResult(true, $videos, '', $taskId);
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
            throw new Exception($error ?: 'HappyHorse网络请求失败');
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            throw new Exception('HappyHorse响应格式错误');
        }
        if ($httpCode >= 400 || isset($data['error']) || $this->hasFailureCode($data)) {
            throw new Exception($this->extractError($data) ?: 'HappyHorse请求失败');
        }
        return $data;
    }

    private function hasFailureCode(array $data): bool
    {
        if (!array_key_exists('code', $data)) {
            return false;
        }
        $code = $data['code'];
        if (is_int($code)) {
            return $code !== 1;
        }
        if (is_string($code) && ctype_digit($code)) {
            return (int)$code !== 1;
        }
        return is_string($code) && $code !== '' && $code !== 'success';
    }

    private function isTaskPending(array $task): bool
    {
        $status = $this->extractTaskStatus($task);
        return $status === '' || in_array($status, ['pending', 'running', 'processing', 'queued', 'created', 'submitted', 'timeout'], true);
    }

    private function extractTaskId(array $data): string
    {
        foreach ([
            $data['task_id'] ?? null,
            $data['id'] ?? null,
            $data['data']['task_id'] ?? null,
            $data['data']['id'] ?? null,
            $data['data']['task']['id'] ?? null,
            $data['data']['task']['task_id'] ?? null,
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
            $data['data']['task']['status'] ?? null,
            $data['data']['task']['state'] ?? null,
            $data['data']['task']['task_status'] ?? null,
        ] as $value) {
            if (is_scalar($value) && (string)$value !== '') {
                return $this->normalizeStatus((string)$value);
            }
        }
        return '';
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            '1', 'ok', 'done', 'finished', 'finish', 'complete' => 'success',
            '2', '3', 'failure', 'fail' => 'failed',
            'cancelled', 'cancel' => 'canceled',
            default => $status,
        };
    }

    private function extractVideoUrls(array $data): array
    {
        $candidates = [
            $data['video_url'] ?? null,
            $data['data']['video_url'] ?? null,
            $data['url'] ?? null,
            $data['data']['url'] ?? null,
            $data['result']['video_url'] ?? null,
            $data['data']['result']['video_url'] ?? null,
            $data['result']['videos'] ?? null,
            $data['data']['result']['videos'] ?? null,
            $data['videos'] ?? null,
            $data['data']['videos'] ?? null,
            $data['results'] ?? null,
            $data['data']['results'] ?? null,
            $data['output'] ?? null,
            $data['data']['output'] ?? null,
            $data['result'] ?? null,
            $data['data']['result'] ?? null,
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
            if ($this->isVideoUrlCandidate($value)) {
                $urls[] = $value;
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $item) {
            if (is_string($item)) {
                if ($item !== '') {
                    $urls[] = $item;
                }
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            foreach (['url', 'video_url', 'video', 'uri', 'src', 'origin_url', 'download_url'] as $key) {
                if (!empty($item[$key]) && is_string($item[$key]) && $this->isVideoUrlCandidate($item[$key])) {
                    $urls[] = $item[$key];
                }
            }
        }
    }

    private function isVideoUrlCandidate(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, 'data:video/')) {
            return true;
        }
        $path = ltrim((string)(parse_url($value, PHP_URL_PATH) ?: $value), '/');
        return (str_starts_with($path, 'uploads/') || str_starts_with($path, 'resource/'))
            && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['mp4', 'webm', 'mov', 'm4v'], true);
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
            return 'HappyHorse视频通道调用失败';
        }
        $lower = strtolower($message);
        return match (true) {
            str_contains($lower, 'certificate key usage'),
            str_contains($lower, 'certificate verify failed'),
            str_contains($lower, 'ssl certificate') => '接口渠道 SSL 证书校验失败，请在系统服务 > 接口渠道关闭 SSL校验，或更换符合规范的 HTTPS 证书',
            str_contains($lower, 'insufficient_points'),
            str_contains($message, '余额不足'),
            str_contains($message, '点数余额不足') => 'HappyHorse点数余额不足，请联系平台管理员',
            str_contains($lower, 'auth_failed'),
            str_contains($lower, 'api key') => 'HappyHorse鉴权失败，请检查系统服务接口渠道配置',
            str_contains($lower, 'app_not_configured') => 'HappyHorse应用未配置服务地址或密钥',
            str_contains($lower, 'billing_params_unmatched') => 'HappyHorse计费档位未匹配，请检查分辨率和时长配置',
            str_contains($lower, 'invalid_request') => 'HappyHorse请求参数不合法',
            default => $message,
        };
    }
}
