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
        $requestPayload = [];
        try {
            $config = $this->resolveConfig($request);
            $imageUrl = $this->assertHttpsFileUrl($request->imageUrl, '人物图片');
            $audioUrl = $this->assertHttpsFileUrl($request->audioUrl, '参考音频');
            $payload = array_filter(array_merge(
                $config['extra_payload'],
                $request->providerParams['payload'] ?? [],
                [
                    'file_url' => $imageUrl,
                    'ref_file_url' => $audioUrl,
                    'script_text' => $request->scriptText,
                    'prompt' => $request->prompt,
                    'duration' => $request->duration > 0 ? $request->duration : null,
                    'mode' => $request->mode,
                ]
            ), static fn($value) => $value !== null && $value !== '' && $value !== []);
            $requestPayload = ['submit_request' => $this->payloadSummary($payload)];
            $data = $this->request('POST', $config['submit_url'], $config['api_key'], $payload, $config['timeout'], (bool)$config['ssl_verify']);
            $taskId = $this->extractTaskId($data);
            if ($taskId === '') {
                return new ImageHumanGenerateResult(false, [], '供应商未返回任务ID', '', false, array_merge($requestPayload, ['submit' => $data]));
            }
            return new ImageHumanGenerateResult(true, [], '', $taskId, true, array_merge($requestPayload, ['submit' => $data]));
        } catch (\Throwable $e) {
            return new ImageHumanGenerateResult(false, [], $this->friendlyError($e->getMessage()), '', false, $requestPayload);
        }
    }

    public function query(string $taskId, ImageHumanGenerateRequest $request): ImageHumanGenerateResult
    {
        $queryPayload = [];
        try {
            $config = $this->resolveConfig($request);
            $data = $this->request('POST', $config['query_url'], $config['api_key'], [
                'task_id' => is_numeric($taskId) ? (int)$taskId : $taskId,
                'elastic_task_id' => is_numeric($taskId) ? (int)$taskId : $taskId,
            ], $config['timeout'], (bool)$config['ssl_verify'], true);
            $queryPayload = ['query' => $data];
            if (isset($data['code']) && (int)$data['code'] !== 1) {
                return new ImageHumanGenerateResult(false, [], $this->extractError($data) ?: '供应商任务失败', $taskId, false, $queryPayload);
            }
            $status = $this->normalizeStatus($this->extractStatus($data));
            $videoUrls = $this->extractMediaUrls($data);
            if ($status !== 'success' && !empty($videoUrls)) {
                $status = 'success';
            }
            if ($status === 'failed') {
                return new ImageHumanGenerateResult(false, [], $this->extractError($data) ?: '供应商任务失败', $taskId, false, $queryPayload);
            }
            if ($status !== 'success' || empty($videoUrls)) {
                return new ImageHumanGenerateResult(true, [], '', $taskId, true, $queryPayload);
            }
            $videos = [];
            foreach ($videoUrls as $videoUrl) {
                try {
                    $stored = ImageHumanAssetService::persistGeneratedVideo($videoUrl, (int)($config['tenant_id'] ?? 0), (int)($config['user_id'] ?? 0));
                } catch (\Throwable $e) {
                    $stored = [
                        'uri' => $videoUrl,
                        'width' => 0,
                        'height' => 0,
                        'stored' => false,
                        'persist_error' => $this->friendlyError($e->getMessage()),
                    ];
                }
                $videos[] = array_merge($stored, [
                    'provider_task_id' => $taskId,
                    'duration' => $request->duration,
                ]);
                break;
            }
            return new ImageHumanGenerateResult(true, $videos, '', $taskId, false, $queryPayload);
        } catch (\Throwable $e) {
            $message = $this->friendlyError($e->getMessage());
            if ($this->isRetryableQueryError($message)) {
                return new ImageHumanGenerateResult(true, [], '', $taskId, true, [
                    'query_error' => [
                        'message' => $message,
                        'retryable' => true,
                    ],
                ]);
            }
            return new ImageHumanGenerateResult(false, [], $message, $taskId, false, array_merge($queryPayload, [
                'query_error' => [
                    'message' => $message,
                    'retryable' => false,
                ],
            ]));
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

    private function assertHttpsFileUrl(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new Exception($label . '必须是 HTTPS 在线文件地址');
        }
        $url = trim($value);
        if ($url === '') {
            throw new Exception('请上传' . $label);
        }
        if (preg_match('/^(blob:|data:)/i', $url)) {
            throw new Exception($label . '不能使用本地临时地址，请先上传到对象存储');
        }
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            throw new Exception($label . '不能使用本地地址，请配置 HTTPS 文件域名或对象存储');
        }
        if (!str_starts_with($url, 'https://')) {
            throw new Exception($label . '必须是 HTTPS 在线文件地址，请先上传到对象存储或配置 HTTPS 文件域名');
        }
        return $url;
    }

    private function payloadSummary(array $payload): array
    {
        $summary = [];
        foreach (['file_url', 'ref_file_url', 'duration', 'mode'] as $key) {
            if (array_key_exists($key, $payload)) {
                $summary[$key] = $payload[$key];
            }
        }
        if (array_key_exists('script_text', $payload)) {
            $summary['script_text_length'] = mb_strlen((string)$payload['script_text']);
        }
        if (array_key_exists('prompt', $payload)) {
            $summary['prompt_length'] = mb_strlen((string)$payload['prompt']);
        }
        return $summary;
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

    private function request(string $method, string $url, string $apiKey, array $payload = [], int $timeout = 30, bool $sslVerify = false, bool $allowBusinessError = false): array
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
        if ($httpCode >= 400 || isset($data['error']) || (!$allowBusinessError && isset($data['code']) && (int)$data['code'] !== 1)) {
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
            $data['result']['output_url'] ?? null,
            $data['data']['result']['output_url'] ?? null,
            $data['result']['data']['output_url'] ?? null,
            $data['data']['result']['data']['output_url'] ?? null,
            $data['result']['file_url'] ?? null,
            $data['data']['result']['file_url'] ?? null,
            $data['output']['video_url'] ?? null,
            $data['data']['output']['video_url'] ?? null,
            $data['output']['output_url'] ?? null,
            $data['data']['output']['output_url'] ?? null,
            $data['output']['file_url'] ?? null,
            $data['data']['output']['file_url'] ?? null,
            $data['video_url'] ?? null,
            $data['data']['video_url'] ?? null,
            $data['output_url'] ?? null,
            $data['data']['output_url'] ?? null,
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
        foreach (['video_url', 'output_url', 'video', 'url', 'uri', 'output', 'file_url', 'download_url', 'origin_url', 'src'] as $key) {
            if (array_key_exists($key, $value)) {
                $url = is_string($value[$key]) ? $this->normalizeMediaUrl($value[$key], $this->isVideoUrlKey($key)) : '';
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
        if (preg_match('/^data:video\//i', $url)) {
            return $url;
        }
        $path = ltrim((string)(parse_url($url, PHP_URL_PATH) ?: $url), '/');
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (preg_match('/^https?:\/\//i', $url)) {
            return ($fromMediaKey || in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true)) ? $url : '';
        }
        if ($path === '' || (!str_starts_with($path, 'uploads/') && !str_starts_with($path, 'resource/'))) {
            return '';
        }
        if (!$fromMediaKey && !in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true)) {
            return '';
        }
        return $url;
    }

    private function isVideoUrlKey(string $key): bool
    {
        return in_array($key, ['video_url', 'output_url', 'video', 'file_url', 'download_url', 'origin_url', 'src'], true);
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

    private function isRetryableQueryError(string $message): bool
    {
        $lower = strtolower($message);
        return str_contains($lower, 'timeout')
            || str_contains($lower, 'timed out')
            || str_contains($message, '响应超时')
            || str_contains($message, '网络请求失败');
    }
}
