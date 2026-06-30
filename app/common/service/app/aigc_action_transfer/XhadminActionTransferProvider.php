<?php

namespace app\common\service\app\aigc_action_transfer;

use app\common\service\app\smart_clip\SmartClipAssetService;
use app\common\service\update\UpdateSourceClient;
use Exception;

class XhadminActionTransferProvider
{
    private const SUBMIT_PATH = '/api/v1/apps/action_transfer/submit';
    private const QUERY_PATH = '/api/v1/apps/action_transfer/query';

    public function submit(array $payload, array $context = []): ActionTransferGenerateResult
    {
        try {
            $config = $this->resolveConfig();
            $payload = array_filter(array_merge(['type' => 'action_transfer'], $payload), static fn($value) => $value !== null && $value !== '' && $value !== []);
            $data = $this->request('POST', $config['base_url'] . self::SUBMIT_PATH, $config['api_key'], $payload, $config);
            $taskId = $this->extractTaskId($data);
            if ($taskId === '') {
                return new ActionTransferGenerateResult(false, 'failed', '', [], '供应商未返回任务ID', $data);
            }
            return new ActionTransferGenerateResult(true, $this->normalizeStatus($this->extractStatus($data) ?: 'running'), $taskId, [], '', $data, $this->extractUsage($data), true);
        } catch (\Throwable $e) {
            return new ActionTransferGenerateResult(false, 'failed', '', [], $this->friendlyError($e->getMessage()));
        }
    }

    public function query(string $taskId, int $tenantId, int $userId): ActionTransferGenerateResult
    {
        try {
            $config = $this->resolveConfig();
            $data = $this->request('POST', $config['base_url'] . self::QUERY_PATH, $config['api_key'], ['task_id' => $taskId], $config, true);
            $status = $this->normalizeStatus($this->extractStatus($data));
            $videoUrls = $this->extractVideoUrls($data);
            if ($status !== 'success' && !empty($videoUrls)) {
                $status = 'success';
            }
            if ($status === 'failed') {
                return new ActionTransferGenerateResult(false, 'failed', $taskId, [], $this->extractError($data) ?: '动作迁移任务失败', $data, $this->extractUsage($data));
            }
            if ($status !== 'success') {
                return new ActionTransferGenerateResult(true, 'running', $taskId, [], '', $data, $this->extractUsage($data), true);
            }
            if (empty($videoUrls)) {
                return new ActionTransferGenerateResult(false, 'failed', $taskId, [], '供应商未返回视频', $data, $this->extractUsage($data));
            }
            $videos = [];
            foreach (array_slice($videoUrls, 0, 1) as $url) {
                $stored = SmartClipAssetService::persistGeneratedVideo($url, $tenantId, $userId);
                $videos[] = array_merge($stored, [
                    'provider_task_id' => $taskId,
                    'duration' => (float)$this->firstValue($data, ['data.result.duration', 'data.result.data.duration', 'result.duration', 'duration']),
                    'raw_url' => $url,
                    'raw' => $data,
                ]);
            }
            return new ActionTransferGenerateResult(true, 'success', $taskId, $videos, '', $data, $this->extractUsage($data));
        } catch (\Throwable $e) {
            return new ActionTransferGenerateResult(false, 'failed', $taskId, [], $this->friendlyError($e->getMessage()));
        }
    }

    private function resolveConfig(): array
    {
        $source = UpdateSourceClient::getSource();
        $baseUrl = $this->sourceBaseUrl((string)($source['active_base_url'] ?? $source['base_url'] ?? ''));
        if ($baseUrl === '') {
            throw new Exception('请先在系统服务 > 接口渠道中配置 Base URL');
        }
        $apiKey = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? ''));
        if ($apiKey === '') {
            throw new Exception('请先在系统服务 > 接口渠道中配置 API Key');
        }
        return [
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'timeout' => 60,
            'ssl_verify' => UpdateSourceClient::sslVerify($source),
        ];
    }

    private function request(string $method, string $url, string $apiKey, array $payload, array $config, bool $allowBusinessError = false): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => (int)$config['timeout'],
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => (bool)$config['ssl_verify'],
            CURLOPT_SSL_VERIFYHOST => (bool)$config['ssl_verify'] ? 2 : 0,
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
        $ok = in_array((int)($data['code'] ?? 0), [0, 1, 200], true) || (($data['success'] ?? null) === true);
        if (!$allowBusinessError && ($httpCode >= 400 || !$ok)) {
            throw new Exception($this->extractError($data) ?: ('供应商请求失败' . ($httpCode ? '，HTTP ' . $httpCode : '')));
        }
        return $data;
    }

    private function extractTaskId(array $data): string
    {
        foreach (['data.task_id', 'data.result.task_id', 'task_id', 'result.task_id', 'data.id'] as $path) {
            $value = $this->firstValue($data, [$path]);
            if ($value !== null && $value !== '') {
                return (string)$value;
            }
        }
        return '';
    }

    private function extractStatus(array $data): string
    {
        foreach (['data.result.status', 'data.result.data.status', 'data.status', 'result.status', 'status'] as $path) {
            $value = $this->firstValue($data, [$path]);
            if ($value !== null && $value !== '') {
                return (string)$value;
            }
        }
        return '';
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'done', 'completed', 'complete', 'success', 'succeeded' => 'success',
            'failed', 'fail', 'error' => 'failed',
            'canceled', 'cancelled' => 'canceled',
            'pending', 'queued' => 'pending',
            default => 'running',
        };
    }

    private function extractVideoUrls(array $data): array
    {
        $urls = [];
        foreach (['data.result.data.video_url', 'data.result.data.output_url', 'data.result.data.url', 'data.result.video_url', 'data.video_url', 'result.video_url', 'video_url', 'output_url', 'url'] as $path) {
            $value = $this->firstValue($data, [$path]);
            if (is_string($value) && $value !== '') {
                $urls[] = $value;
            }
        }
        foreach (['data.result.data.results', 'data.results', 'results'] as $path) {
            $value = $this->firstValue($data, [$path]);
            if (!is_array($value)) {
                continue;
            }
            foreach ($value as $item) {
                if (is_string($item) && $item !== '') {
                    $urls[] = $item;
                } elseif (is_array($item)) {
                    foreach (['video_url', 'output_url', 'url'] as $key) {
                        if (!empty($item[$key]) && is_string($item[$key])) {
                            $urls[] = $item[$key];
                        }
                    }
                }
            }
        }
        return array_values(array_unique(array_filter($urls, static fn($url) => str_starts_with((string)$url, 'http://') || str_starts_with((string)$url, 'https://') || str_starts_with((string)$url, 'data:video/'))));
    }

    private function extractUsage(array $data): array
    {
        $usage = $this->firstValue($data, ['data.usage', 'usage']);
        return is_array($usage) ? $usage : [];
    }

    private function extractError(array $data): string
    {
        foreach (['msg', 'message', 'error', 'data.error', 'data.message', 'data.result.error', 'data.result.message'] as $path) {
            $value = $this->firstValue($data, [$path]);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return '';
    }

    private function firstValue(array $data, array $paths): mixed
    {
        foreach ($paths as $path) {
            $cursor = $data;
            foreach (explode('.', $path) as $part) {
                if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                    $cursor = null;
                    break;
                }
                $cursor = $cursor[$part];
            }
            if ($cursor !== null && $cursor !== '') {
                return $cursor;
            }
        }
        return null;
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

    private function friendlyError(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return '动作迁移服务暂不可用，请稍后重试';
        }
        if (stripos($message, 'api key') !== false || stripos($message, 'authorization') !== false || stripos($message, 'bearer') !== false) {
            return '请先检查系统服务 > 接口渠道配置';
        }
        return mb_substr($message, 0, 200);
    }
}
