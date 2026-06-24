<?php

namespace app\common\service\app\aigc_image;

use app\common\service\FileService;
use app\common\service\update\UpdateSourceClient;
use Exception;
use think\facade\Log;

class XhadminAigcImageProvider implements AigcImageProviderInterface
{
    private const DEFAULT_SUBMIT_PATH = '/api/v1/tasks';
    private const DEFAULT_TASK_PATH = '/api/v1/tasks/{task_id}';

    public function generate(AigcImageGenerateRequest $request): AigcImageGenerateResult
    {
        try {
            $config = $this->resolveConfig($request);
            $this->assertSupportedQuantity($request);
            $payload = $this->buildPayload($request, $config);
            $submit = $this->request('POST', $config['submit_url'], $config['api_key'], $payload, (int)$config['timeout'], (bool)$config['ssl_verify']);
            $taskId = $this->extractTaskId($submit);
            if ($taskId === '') {
                return new AigcImageGenerateResult(false, [], '供应商未返回任务ID');
            }
            if ((int)($config['poll_attempts'] ?? 0) === 0) {
                return new AigcImageGenerateResult(true, [], '', $taskId);
            }
            $task = $this->pollTask($taskId, $config);
            if ($this->isTaskPending($task)) {
                return new AigcImageGenerateResult(true, [], '', $taskId);
            }
            return $this->buildResultFromTask($task, $taskId, $request, $config);
        } catch (\Throwable $e) {
            return new AigcImageGenerateResult(false, [], $this->friendlyError($e->getMessage()));
        }
    }

    public function fetchResult(string $taskId, AigcImageGenerateRequest $request): AigcImageGenerateResult
    {
        try {
            $config = $this->resolveConfig($request);
            $task = $this->request('GET', str_replace('{task_id}', rawurlencode($taskId), $config['task_url_template']), $config['api_key'], [], (int)$config['timeout'], (bool)$config['ssl_verify']);
            if ($this->isTaskPending($task)) {
                return new AigcImageGenerateResult(true, [], '', $taskId);
            }
            return $this->buildResultFromTask($task, $taskId, $request, $config);
        } catch (\Throwable $e) {
            if ($this->isTransientFetchError($e->getMessage())) {
                Log::write('AIGC生图供应商任务查询暂时失败: ' . json_encode([
                    'provider' => 'xhadmin_aigc_image',
                    'task_id' => $taskId,
                    'message' => $e->getMessage(),
                ], JSON_UNESCAPED_UNICODE));
                return new AigcImageGenerateResult(true, [], '', $taskId);
            }
            return new AigcImageGenerateResult(false, [], $this->friendlyError($e->getMessage()), $taskId);
        }
    }

    private function buildResultFromTask(array $task, string $taskId, AigcImageGenerateRequest $request, array $config): AigcImageGenerateResult
    {
            $status = $this->extractTaskStatus($task);
            $imageUrls = $this->extractImageUrls($task);
            if (!in_array($status, ['completed', 'success', 'succeeded'], true)) {
                if (!empty($imageUrls)) {
                    $status = 'success';
                }
            }
            if (!in_array($status, ['completed', 'success', 'succeeded'], true)) {
                $message = $this->extractError($task) ?: '供应商任务未完成';
                $this->logTaskFailure($taskId, $status, $message, $task);
                return new AigcImageGenerateResult(false, [], $message);
            }
            if (!is_array($imageUrls) || empty($imageUrls)) {
                return new AigcImageGenerateResult(false, [], '供应商未返回图片', $taskId);
            }
            $images = [];
            foreach ($imageUrls as $imageUrl) {
                if (!is_string($imageUrl) || $imageUrl === '') {
                    continue;
                }
                try {
                    $stored = AigcImageAssetService::persistGeneratedImage($imageUrl, (int)($config['tenant_id'] ?? 0), (int)($config['user_id'] ?? 0));
                    $images[] = array_merge($stored, ['provider_task_id' => $taskId]);
                } catch (\Throwable) {
                    $images[] = [
                        'uri' => $imageUrl,
                        'width' => (int)($request->spec['width'] ?? 0),
                        'height' => (int)($request->spec['height'] ?? 0),
                        'provider_task_id' => $taskId,
                    ];
                }
            }
            if (empty($images)) {
                return new AigcImageGenerateResult(false, [], '供应商图片格式错误', $taskId);
            }
            return new AigcImageGenerateResult(true, $images, '', $taskId);
    }

    private function resolveConfig(AigcImageGenerateRequest $request): array
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
            'model' => (string)($config['model'] ?? $request->providerParams['model'] ?? 'gpt-image-2'),
            'submit_url' => $baseUrl . '/' . ltrim($submitPath, '/'),
            'task_url_template' => $baseUrl . '/' . ltrim($taskPath, '/'),
            'timeout' => max(5, (int)($config['timeout'] ?? 30)),
            'poll_interval' => max(1, (int)($config['poll_interval'] ?? 2)),
            'poll_attempts' => max(0, (int)($config['poll_attempts'] ?? 30)),
            'upstream_channel' => (string)($config['upstream_channel'] ?? $config['channel'] ?? ''),
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

    private function buildPayload(AigcImageGenerateRequest $request, array $config): array
    {
        $providerParams = $request->providerParams;
        $payload = [
            'model' => $request->providerParams['model'] ?? $config['model'],
            'n' => 1,
            'prompt' => $request->prompt,
            'channel' => $config['upstream_channel'] ?: null,
            'image_urls' => $this->normalizeReferenceImageUrls($request->referenceImages),
            'resolution' => empty($providerParams['omit_resolution']) ? ($providerParams['resolution'] ?? $request->quality) : null,
            'aspect_ratio' => $providerParams['aspect_ratio'] ?? $request->ratio,
            'image_size' => $providerParams['image_size'] ?? $providerParams['resolution'] ?? null,
            'mask_url' => $providerParams['mask_url'] ?? null,
            'quality' => $providerParams['generation_quality'] ?? null,
            'negative_prompt' => $request->negativePrompt ?: null,
            'output_format' => $providerParams['output_format'] ?? null,
            'transparent_background' => $providerParams['transparent_background'] ?? null,
            'background' => $providerParams['background'] ?? null,
            'response_format' => $providerParams['response_format'] ?? null,
        ];
        if (!empty($providerParams['size'])) {
            $payload['size'] = $providerParams['size'];
        }
        return array_filter(array_merge($payload, $config['extra_payload']), static fn($value) => $value !== null && $value !== '' && $value !== []);
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

    private function assertSupportedQuantity(AigcImageGenerateRequest $request): void
    {
        if ($request->quantity !== 1) {
            throw new Exception('当前通道仅支持每次生成1张');
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
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
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

    private function extractImageUrls(array $data): array
    {
        $candidates = [
            $data['result']['images'] ?? null,
            $data['data']['result']['images'] ?? null,
            $data['data']['results'] ?? null,
            $data['results'] ?? null,
            $data['data']['images'] ?? null,
            $data['images'] ?? null,
            $data['output'] ?? null,
            $data['data']['output'] ?? null,
            $data['data']['result'] ?? null,
            $data['result'] ?? null,
            $data['data']['url'] ?? null,
            $data['url'] ?? null,
        ];
        $urls = [];
        foreach ($candidates as $candidate) {
            $this->collectImageUrls($candidate, $urls);
            if (!empty($urls)) {
                break;
            }
        }
        return array_values(array_unique($urls));
    }

    private function collectImageUrls(mixed $value, array &$urls): void
    {
        if (is_string($value)) {
            if ($value !== '') {
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
            foreach (['url', 'image_url', 'image', 'uri', 'src', 'origin_url', 'download_url'] as $key) {
                if (!empty($item[$key]) && is_string($item[$key])) {
                    $urls[] = $item[$key];
                }
            }
        }
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
            return '生图通道调用失败';
        }
        $lower = strtolower($message);
        return match (true) {
            str_contains($lower, 'certificate key usage'),
            str_contains($lower, 'certificate verify failed'),
            str_contains($lower, 'ssl certificate') => '接口渠道 SSL 证书校验失败，请在系统服务 > 接口渠道关闭 SSL校验，或更换符合规范的 HTTPS 证书',
            str_contains($lower, 'insufficient_points'),
            str_contains($message, '余额不足'),
            str_contains($message, '点数余额不足') => '供应商点数余额不足，请联系平台管理员',
            str_contains($lower, 'auth_failed'),
            str_contains($lower, 'api key') => '供应商鉴权失败，请检查系统服务接口渠道配置',
            str_contains($lower, 'queue_limit_exceeded') => '供应商排队任务已满，请稍后再试',
            default => $message,
        };
    }

    private function isTransientFetchError(string $message): bool
    {
        $lower = strtolower($message);
        return str_contains($lower, 'timed out')
            || str_contains($lower, 'timeout')
            || str_contains($lower, 'failed to connect')
            || str_contains($lower, 'could not connect')
            || str_contains($lower, 'couldn\'t connect')
            || str_contains($lower, 'connection refused')
            || str_contains($lower, 'connection reset')
            || str_contains($lower, 'connection aborted')
            || str_contains($lower, 'empty reply')
            || str_contains($lower, 'temporary failure')
            || str_contains($lower, 'name lookup timed out')
            || str_contains($lower, 'resolve host')
            || str_contains($lower, '响应格式错误');
    }

    private function logTaskFailure(string $taskId, string $status, string $message, array $task): void
    {
        $context = $this->sanitizeTaskForLog($task);
        Log::write('AIGC生图供应商任务失败: ' . json_encode([
            'provider' => 'xhadmin_aigc_image',
            'task_id' => $taskId,
            'status' => $status,
            'message' => $message,
            'response' => $context,
        ], JSON_UNESCAPED_UNICODE));
    }

    private function sanitizeTaskForLog(array $task): array
    {
        foreach (['prompt', 'negative_prompt', 'image_urls', 'images', 'url', 'output', 'result'] as $key) {
            if (array_key_exists($key, $task)) {
                unset($task[$key]);
            }
        }
        foreach (['data', 'task'] as $key) {
            if (!isset($task[$key]) || !is_array($task[$key])) {
                continue;
            }
            $task[$key] = $this->sanitizeTaskForLog($task[$key]);
        }
        return $task;
    }
}
