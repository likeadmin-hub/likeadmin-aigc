<?php

namespace app\common\service\app\image_human;

use app\common\service\update\UpdateSourceClient;
use Exception;

class XhadminImageHumanProvider implements ImageHumanProviderInterface
{
    private const DEFAULT_SUBMIT_PATH = '/api/v1/apps/image_human/submit';
    private const DEFAULT_QUERY_PATH = '/api/v1/apps/image_human/query';

    public function submit(ImageHumanGenerateRequest $request): ImageHumanGenerateResult
    {
        try {
            $config = $this->resolveConfig($request);
            $payload = array_filter(array_merge([
                'file_url' => $request->imageUrl,
                'ref_file_url' => $request->audioUrl,
                'prompt' => $request->prompt,
                'duration' => $request->duration > 0 ? $request->duration : null,
                'mode' => $request->mode,
            ], $config['extra_payload'], $request->providerParams['payload'] ?? []), static fn($value) => $value !== null && $value !== '' && $value !== []);
            $data = $this->request('POST', $config['submit_url'], $config['api_key'], $payload, $config['timeout'], (bool)$config['ssl_verify']);
            $taskId = $this->extractTaskId($data);
            if ($taskId === '') {
                return new ImageHumanGenerateResult(false, [], '供应商未返回任务ID', '', false, ['submit' => $data]);
            }
            return new ImageHumanGenerateResult(true, [], '', $taskId, true, ['submit' => $data]);
        } catch (\Throwable $e) {
            return new ImageHumanGenerateResult(false, [], $this->friendlyError($e->getMessage()));
        }
    }

    public function query(string $taskId, ImageHumanGenerateRequest $request): ImageHumanGenerateResult
    {
        try {
            $config = $this->resolveConfig($request);
            $data = $this->request('POST', $config['query_url'], $config['api_key'], [
                'task_id' => is_numeric($taskId) ? (int)$taskId : $taskId,
                'elastic_task_id' => is_numeric($taskId) ? (int)$taskId : $taskId,
            ], $config['timeout'], (bool)$config['ssl_verify']);
            $status = $this->normalizeStatus($this->extractStatus($data));
            $videoUrls = $this->extractMediaUrls($data);
            if ($status !== 'success' && !empty($videoUrls)) {
                $status = 'success';
            }
            if ($status === 'failed') {
                return new ImageHumanGenerateResult(false, [], $this->extractError($data) ?: '供应商任务失败', $taskId, false, ['query' => $data]);
            }
            if ($status !== 'success' || empty($videoUrls)) {
                return new ImageHumanGenerateResult(true, [], '', $taskId, true, ['query' => $data]);
            }
            $videos = [];
            foreach ($videoUrls as $videoUrl) {
                try {
                    $stored = ImageHumanAssetService::persistGeneratedVideo($videoUrl, (int)($config['tenant_id'] ?? 0), (int)($config['user_id'] ?? 0));
                } catch (\Throwable) {
                    $stored = [
                        'uri' => $videoUrl,
                        'width' => 0,
                        'height' => 0,
                        'stored' => false,
                    ];
                }
                $videos[] = array_merge($stored, [
                    'provider_task_id' => $taskId,
                    'duration' => $request->duration,
                ]);
                break;
            }
            return new ImageHumanGenerateResult(true, $videos, '', $taskId, false, ['query' => $data]);
        } catch (\Throwable $e) {
            return new ImageHumanGenerateResult(false, [], $this->friendlyError($e->getMessage()), $taskId);
        }
    }

    private function resolveConfig(ImageHumanGenerateRequest $request): array
    {
        $config = array_merge($request->channelConfig, $request->providerParams['channel_config'] ?? []);
        $providerConfig = is_array($config['provider'] ?? null) ? $config['provider'] : [];
        $source = UpdateSourceClient::getSource();
        $baseUrl = $this->sourceBaseUrl((string)($source['active_base_url'] ?? $source['base_url'] ?? ''));
        if ($baseUrl === '') {
            throw new Exception('请先在系统服务的接口渠道中配置 Base URL');
        }
        $apiKey = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? ''));
        if ($apiKey === '') {
            throw new Exception('请先在系统服务的接口渠道中配置 API Key');
        }
        return [
            'api_key' => $apiKey,
            'submit_url' => $baseUrl . '/' . ltrim((string)($providerConfig['submit_path'] ?? $config['submit_path'] ?? self::DEFAULT_SUBMIT_PATH), '/'),
            'query_url' => $baseUrl . '/' . ltrim((string)($providerConfig['query_path'] ?? $config['query_path'] ?? self::DEFAULT_QUERY_PATH), '/'),
            'timeout' => max(5, (int)($providerConfig['timeout'] ?? $config['timeout'] ?? 60)),
            'tenant_id' => (int)($config['tenant_id'] ?? 0),
            'user_id' => (int)($config['user_id'] ?? 0),
            'ssl_verify' => UpdateSourceClient::sslVerify($source),
            'extra_payload' => is_array($providerConfig['extra_payload'] ?? null) ? $providerConfig['extra_payload'] : [],
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

    private function request(string $method, string $url, string $apiKey, array $payload = [], int $timeout = 30, bool $sslVerify = false): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
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
            $data['elastic_task_id'] ?? null,
            $data['id'] ?? null,
            $data['data']['task_id'] ?? null,
            $data['data']['elastic_task_id'] ?? null,
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

    private function extractStatus(array $data): string
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
            $data['result']['status'] ?? null,
            $data['result']['state'] ?? null,
            $data['data']['result']['status'] ?? null,
            $data['data']['result']['state'] ?? null,
        ] as $value) {
            if (is_scalar($value) && (string)$value !== '') {
                return (string)$value;
            }
        }
        return '';
    }

    private function normalizeStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            '1', 'ok', 'done', 'finished', 'finish', 'complete', 'completed', 'success', 'succeeded' => 'success',
            '2', '3', 'failure', 'fail', 'failed', 'error' => 'failed',
            'cancelled', 'cancel', 'canceled' => 'failed',
            'pending', 'running', 'processing', 'queued', 'created', 'submitted' => 'pending',
            default => strtolower(trim($status)),
        };
    }

    private function extractMediaUrls(array $data): array
    {
        $candidates = [
            $data['result']['videos'] ?? null,
            $data['data']['result']['videos'] ?? null,
            $data['results'] ?? null,
            $data['data']['results'] ?? null,
            $data['videos'] ?? null,
            $data['data']['videos'] ?? null,
            $data['result']['files'] ?? null,
            $data['data']['result']['files'] ?? null,
            $data['files'] ?? null,
            $data['data']['files'] ?? null,
            $data['result']['video_url'] ?? null,
            $data['data']['result']['video_url'] ?? null,
            $data['result']['file_url'] ?? null,
            $data['data']['result']['file_url'] ?? null,
            $data['output']['video_url'] ?? null,
            $data['data']['output']['video_url'] ?? null,
            $data['output']['file_url'] ?? null,
            $data['data']['output']['file_url'] ?? null,
            $data['video_url'] ?? null,
            $data['data']['video_url'] ?? null,
            $data['file_url'] ?? null,
            $data['data']['file_url'] ?? null,
            $data['result']['video'] ?? null,
            $data['data']['result']['video'] ?? null,
            $data['output']['video'] ?? null,
            $data['data']['output']['video'] ?? null,
            $data['result']['url'] ?? null,
            $data['data']['result']['url'] ?? null,
            $data['output']['url'] ?? null,
            $data['data']['output']['url'] ?? null,
            $data['result'] ?? null,
            $data['data']['result'] ?? null,
            $data['output'] ?? null,
            $data['data']['output'] ?? null,
            $data['data'] ?? null,
        ];
        $urls = [];
        foreach ($candidates as $candidate) {
            $this->collectMediaUrls($candidate, $urls);
            if (!empty($urls)) {
                break;
            }
        }
        return array_values(array_unique($urls));
    }

    private function collectMediaUrls(mixed $value, array &$urls): void
    {
        if (is_string($value)) {
            $url = $this->normalizeMediaUrl($value, false);
            if ($url !== '') {
                $urls[] = $url;
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach (['video_url', 'video', 'url', 'uri', 'output', 'file_url', 'download_url', 'origin_url', 'src'] as $key) {
            if (array_key_exists($key, $value)) {
                $url = is_string($value[$key]) ? $this->normalizeMediaUrl($value[$key], true) : '';
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }
        foreach ($value as $item) {
            $this->collectMediaUrls($item, $urls);
        }
    }

    private function normalizeMediaUrl(string $url, bool $fromMediaKey): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('/^(https?:\/\/|data:video\/)/i', $url)) {
            return $url;
        }
        $path = ltrim((string)(parse_url($url, PHP_URL_PATH) ?: $url), '/');
        if ($path === '' || (!str_starts_with($path, 'uploads/') && !str_starts_with($path, 'resource/'))) {
            return '';
        }
        if (!$fromMediaKey && !in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['mp4', 'webm', 'mov', 'm4v'], true)) {
            return '';
        }
        return $url;
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
            return '全驱动数字人通道调用失败';
        }
        $lower = strtolower($message);
        return match (true) {
            str_contains($lower, 'api key'), str_contains($lower, 'unauthorized') => '全驱动数字人通道认证失败，请检查 API Key',
            str_contains($lower, 'timeout'), str_contains($lower, 'timed out') => '全驱动数字人通道响应超时，请稍后重试',
            default => $message,
        };
    }
}
