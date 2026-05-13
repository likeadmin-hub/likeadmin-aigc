<?php

namespace app\common\service\app\aigc_llm;

use app\common\service\update\UpdateSourceClient;
use Exception;

class OpenAiCompatibleLlmProvider implements AigcLlmProviderInterface
{
    private const DEFAULT_BASE_URL = 'https://api.xhadmin.cn';
    private const DEFAULT_STREAM_PATH = '/api/v1/chat/completions';

    public function stream(AigcLlmGenerateRequest $request): \Generator
    {
        $config = $this->resolveConfig($request);
        $payload = $this->buildPayload($request, $config);
        foreach ($this->requestStream($config['url'], $config['api_key'], $payload, (int)$config['timeout'], (bool)$config['ssl_verify']) as $event) {
            if (($event['type'] ?? '') === 'error') {
                throw new Exception((string)($event['message'] ?? '供应商请求失败'));
            }
            yield $event;
        }
    }

    private function resolveConfig(AigcLlmGenerateRequest $request): array
    {
        $config = $request->channelConfig;
        $source = UpdateSourceClient::getSource();
        $baseUrl = trim((string)($config['base_url'] ?? $config['endpoint'] ?? ''));
        if ($baseUrl === '') {
            $baseUrl = $this->sourceBaseUrl((string)($source['active_base_url'] ?? $source['base_url'] ?? '')) ?: self::DEFAULT_BASE_URL;
        }
        $streamPath = (string)($config['stream_path'] ?? $config['path'] ?? self::DEFAULT_STREAM_PATH);
        $apiKey = trim((string)($config['api_key'] ?? ''));
        if ($apiKey === '') {
            $apiKey = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? ''));
        }
        if ($apiKey === '') {
            throw new Exception('请先在通道配置中填写 API Key');
        }
        return [
            'url' => rtrim($baseUrl, '/') . '/' . ltrim($streamPath, '/'),
            'api_key' => $apiKey,
            'timeout' => max(10, (int)($config['timeout'] ?? 120)),
            'ssl_verify' => (int)($config['ssl_verify'] ?? $source['ssl_verify'] ?? 0) === 1,
        ];
    }

    private function buildPayload(AigcLlmGenerateRequest $request, array $config): array
    {
        $modelConfig = $request->modelConfig['config_json'] ?? [];
        $messages = [];
        if ($request->systemPrompt !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $request->systemPrompt,
            ];
        }
        foreach ($request->messages as $message) {
            $role = (string)($message['role'] ?? '');
            if (!in_array($role, ['user', 'assistant', 'system'], true)) {
                continue;
            }
            $messages[] = [
                'role' => $role,
                'content' => $this->normalizeMessageContent($message['content'] ?? ''),
            ];
        }
        $payload = [
            'model' => (string)($request->modelConfig['model'] ?? $request->modelCode),
            'messages' => $messages,
            'stream' => true,
            'stream_options' => [
                'include_usage' => true,
            ],
        ];
        foreach (['temperature', 'max_tokens', 'top_p', 'presence_penalty', 'frequency_penalty', 'enable_search', 'enable_thinking'] as $key) {
            if (array_key_exists($key, $modelConfig)) {
                $payload[$key] = $modelConfig[$key];
            }
        }
        if (isset($modelConfig['stream_options']) && is_array($modelConfig['stream_options'])) {
            $payload['stream_options'] = array_merge($payload['stream_options'], $modelConfig['stream_options']);
        }
        return $payload;
    }

    private function normalizeMessageContent($content)
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }
        $parts = [];
        foreach ($content as $part) {
            if (!is_array($part)) {
                continue;
            }
            $type = (string)($part['type'] ?? '');
            if ($type === 'text') {
                $parts[] = [
                    'type' => 'text',
                    'text' => (string)($part['text'] ?? ''),
                ];
            } elseif ($type === 'image_url') {
                $url = (string)($part['image_url']['url'] ?? $part['url'] ?? '');
                if ($url !== '') {
                    $parts[] = [
                        'type' => 'image_url',
                        'image_url' => ['url' => $url],
                    ];
                }
            }
        }
        return $parts ?: '';
    }

    private function requestStream(string $url, string $apiKey, array $payload, int $timeout, bool $sslVerify): \Generator
    {
        $queue = [];
        $buffer = '';
        $responseBody = '';
        $receivedValidEvent = false;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ],
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$queue, &$buffer, &$responseBody, &$receivedValidEvent) {
                $responseBody .= $chunk;
                $buffer .= str_replace("\r\n", "\n", $chunk);
                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $block = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);
                    $event = $this->parseSseBlock($block);
                    if ($event !== null) {
                        $receivedValidEvent = true;
                        $queue[] = $event;
                    }
                }
                return strlen($chunk);
            },
        ]);
        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);
        $running = null;
        do {
            do {
                $status = curl_multi_exec($mh, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            while ($event = array_shift($queue)) {
                yield $event;
            }

            if ($running) {
                curl_multi_select($mh, 0.2);
            }
        } while ($running && $status === CURLM_OK);

        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($buffer !== '') {
            $event = $this->parseSseBlock($buffer);
            if ($event !== null) {
                $receivedValidEvent = true;
                $queue[] = $event;
            }
        }
        while ($event = array_shift($queue)) {
            yield $event;
        }
        curl_multi_remove_handle($mh, $ch);
        curl_multi_close($mh);
        curl_close($ch);

        if ($errno) {
            throw new Exception($this->friendlyError($error ?: '供应商网络请求失败'));
        }
        if ($httpCode >= 400) {
            throw new Exception($this->friendlyError($this->extractError($responseBody) ?: '供应商请求失败'));
        }
        if (!$receivedValidEvent) {
            throw new Exception($this->friendlyError($this->extractError($responseBody) ?: '供应商没有返回有效的流式内容'));
        }
    }

    private function parseSseBlock(string $block): ?array
    {
        $lines = preg_split('/\r?\n/', trim($block));
        $data = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, 'data:')) {
                $data[] = trim(substr($line, 5));
            }
        }
        $text = trim(implode("\n", $data));
        if ($text === '' || $text === '[DONE]') {
            return null;
        }
        $json = json_decode($text, true);
        if (!is_array($json)) {
            return null;
        }
        if (isset($json['error'])) {
            return [
                'type' => 'error',
                'message' => $this->friendlyError($this->extractError($json)),
            ];
        }
        $choice = $json['choices'][0] ?? [];
        $delta = (string)($choice['delta']['content'] ?? '');
        if ($delta !== '') {
            return [
                'type' => 'delta',
                'content' => $delta,
                'provider_request_id' => (string)($json['id'] ?? ''),
            ];
        }
        if (isset($json['usage']) && is_array($json['usage'])) {
            return [
                'type' => 'usage',
                'usage' => [
                    'prompt_tokens' => (int)($json['usage']['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int)($json['usage']['completion_tokens'] ?? 0),
                    'total_tokens' => (int)($json['usage']['total_tokens'] ?? 0),
                    'estimated' => false,
                ],
                'provider_request_id' => (string)($json['id'] ?? ''),
            ];
        }
        if (!empty($choice['finish_reason'])) {
            return [
                'type' => 'done',
                'finish_reason' => (string)$choice['finish_reason'],
                'provider_request_id' => (string)($json['id'] ?? ''),
            ];
        }
        return null;
    }

    private function extractError($data): string
    {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : $data;
        }
        if (is_array($data)) {
            $error = $data['error'] ?? $data;
            if (is_array($error)) {
                return (string)($error['message'] ?? $error['code'] ?? $error['type'] ?? '');
            }
            return (string)($data['message'] ?? $data['msg'] ?? '');
        }
        return trim(strip_tags((string)$data));
    }

    private function friendlyError(string $message): string
    {
        $lower = strtolower($message);
        $map = [
            'certificate key usage' => '接口渠道 SSL 证书校验失败，请关闭 SSL校验，或更换符合规范的 HTTPS 证书',
            'certificate verify failed' => '接口渠道 SSL 证书校验失败，请关闭 SSL校验，或更换符合规范的 HTTPS 证书',
            'ssl certificate' => '接口渠道 SSL 证书校验失败，请关闭 SSL校验，或更换符合规范的 HTTPS 证书',
            'insufficient_points' => '接口渠道点数余额不足',
            'key_quota_exceeded' => 'API Key 点数额度不足',
            'auth_failed' => 'API Key 无效或已失效',
            'unauthorized' => 'API Key 无效或已失效',
            'permission_denied' => 'API Key 无权调用该模型',
            'queue_limit_exceeded' => '供应商排队任务已达上限，请稍后重试',
            'invalid_request' => '供应商请求参数错误',
            'not_found' => '供应商模型或接口不存在',
        ];
        foreach ($map as $needle => $friendly) {
            if (str_contains($lower, $needle)) {
                return $friendly;
            }
        }
        return mb_substr($message !== '' ? $message : '供应商请求失败', 0, 120, 'UTF-8');
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
}
