<?php

namespace app\common\service\app\aigc_music;

use app\common\service\FileService;
use app\common\service\update\UpdateSourceClient;
use Exception;

class XhadminAigcMusicProvider implements AigcMusicProviderInterface
{
    private const DEFAULT_CREATE_PATH = '/api/v1/apps/music_generation/create';
    private const DEFAULT_QUERY_PATH = '/api/v1/apps/music_generation/query';
    private const DEFAULT_TIMEOUT = 120;
    private const DEFAULT_CONNECT_TIMEOUT = 30;

    public function lyrics(array $params): array
    {
        return (new MockAigcMusicProvider())->lyrics($params);
    }

    public function mashupLyrics(array $params): array
    {
        return (new MockAigcMusicProvider())->mashupLyrics($params);
    }

    public function cloneVoice(array $params): array
    {
        return (new MockAigcMusicProvider())->cloneVoice($params);
    }

    public function export(array $result, string $type, array $params = []): array
    {
        return (new MockAigcMusicProvider())->export($result, $type, $params);
    }

    public function generate(AigcMusicGenerateRequest $request): AigcMusicGenerateResult
    {
        $taskId = '';
        $submit = [];
        try {
            $config = $this->resolveConfig($request);
            $payload = $this->buildPayload($request, $config);
            $submit = $this->request('POST', $config['create_url'], $config['api_key'], $payload, (int)$config['timeout'], (bool)$config['ssl_verify'], (int)$config['connect_timeout']);
            $taskId = $this->extractTaskId($submit);
            if ($taskId === '') {
                return new AigcMusicGenerateResult(false, [], '供应商未返回任务ID', '', $submit);
            }
            if ((int)$config['poll_attempts'] === 0) {
                return new AigcMusicGenerateResult(true, [], '', $taskId, $submit);
            }
            $task = $this->pollTask($taskId, $config);
            if ($this->isTaskPending($task)) {
                return new AigcMusicGenerateResult(true, [], '', $taskId, ['submit' => $submit, 'query' => $task]);
            }
            return $this->buildResultFromTask($task, $taskId, $request, $config, $submit);
        } catch (\Throwable $e) {
            if ($taskId !== '') {
                return new AigcMusicGenerateResult(true, [], '', $taskId, ['submit' => $submit, 'poll_error' => $this->friendlyError($e->getMessage())]);
            }
            return new AigcMusicGenerateResult(false, [], $this->friendlyError($e->getMessage()), '', ['error' => $e->getMessage()]);
        }
    }

    public function fetchResult(string $taskId, AigcMusicGenerateRequest $request): AigcMusicGenerateResult
    {
        try {
            $config = $this->resolveConfig($request);
            $task = $this->request('GET', $config['query_url'] . '?task_id=' . rawurlencode($taskId), $config['api_key'], [], (int)$config['timeout'], (bool)$config['ssl_verify'], (int)$config['connect_timeout']);
            if ($this->isTaskPending($task)) {
                return new AigcMusicGenerateResult(true, [], '', $taskId, $task);
            }
            return $this->buildResultFromTask($task, $taskId, $request, $config);
        } catch (\Throwable $e) {
            return new AigcMusicGenerateResult(false, [], $this->friendlyError($e->getMessage()), $taskId);
        }
    }

    private function buildResultFromTask(array $task, string $taskId, AigcMusicGenerateRequest $request, array $config, array $submit = []): AigcMusicGenerateResult
    {
        $status = $this->extractTaskStatus($task);
        $items = $this->extractAudioItems($task);
        if (!in_array($status, ['completed', 'success', 'succeeded'], true) && !empty($items)) {
            $status = 'success';
        }
        if (!in_array($status, ['completed', 'success', 'succeeded'], true)) {
            return new AigcMusicGenerateResult(false, [], $this->extractError($task) ?: '供应商任务未完成', $taskId, ['submit' => $submit, 'query' => $task]);
        }
        if (empty($items)) {
            return new AigcMusicGenerateResult(false, [], '供应商未返回音频', $taskId, ['submit' => $submit, 'query' => $task]);
        }

        $results = [];
        foreach (array_slice($items, 0, 1) as $item) {
            $audioUrl = (string)($item['audio_url'] ?? $item['url'] ?? $item['file_url'] ?? '');
            if ($audioUrl === '') {
                continue;
            }
            $stored = AigcMusicAssetService::persistGeneratedAudio($audioUrl, (int)($config['tenant_id'] ?? 0), (int)($config['user_id'] ?? 0));
            $results[] = [
                'title' => (string)($item['title'] ?? $request->title ?: 'AI音乐'),
                'audio_uri' => (string)$stored['uri'],
                'wav_uri' => (string)($item['file_url'] ?? ''),
                'mp4_uri' => (string)($item['video_url'] ?? ''),
                'midi_uri' => (string)($item['midi_url'] ?? ''),
                'timing_uri' => (string)($item['timing_url'] ?? ''),
                'vox_uri' => (string)($item['vox_url'] ?? ''),
                'cover_uri' => (string)($item['cover_url'] ?? ''),
                'duration' => (float)($stored['duration'] ?? $item['duration'] ?? $request->duration),
                'lyrics' => (string)($item['lyric'] ?? $item['lyrics'] ?? $request->lyrics),
                'timing_json' => is_array($item['timing'] ?? null) ? $item['timing'] : [],
                'storage_scope' => (string)($stored['storage_scope'] ?? ''),
                'storage_engine' => (string)($stored['storage_engine'] ?? ''),
                'storage_domain' => (string)($stored['storage_domain'] ?? ''),
                'mime_type' => (string)($stored['mime_type'] ?? 'audio/mpeg'),
                'file_size' => (int)($stored['file_size'] ?? 0),
                'provider_task_id' => $taskId,
                'raw' => $item,
            ];
        }
        if (empty($results)) {
            return new AigcMusicGenerateResult(false, [], '供应商音频格式错误', $taskId, ['submit' => $submit, 'query' => $task]);
        }
        return new AigcMusicGenerateResult(true, $results, '', $taskId, ['submit' => $submit, 'query' => $task]);
    }

    private function resolveConfig(AigcMusicGenerateRequest $request): array
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
        return [
            'api_key' => $apiKey,
            'create_url' => $baseUrl . '/' . ltrim((string)($config['create_path'] ?? self::DEFAULT_CREATE_PATH), '/'),
            'query_url' => $baseUrl . '/' . ltrim((string)($config['query_path'] ?? self::DEFAULT_QUERY_PATH), '/'),
            'timeout' => max(self::DEFAULT_TIMEOUT, (int)($config['timeout'] ?? self::DEFAULT_TIMEOUT)),
            'connect_timeout' => max(5, min(60, (int)($config['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT))),
            'poll_interval' => max(1, (int)($config['poll_interval'] ?? 5)),
            'poll_attempts' => max(0, (int)($config['poll_attempts'] ?? 2)),
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

    private function buildPayload(AigcMusicGenerateRequest $request, array $config): array
    {
        $isCustom = $this->boolValue($request->payload['custom'] ?? $request->lyrics !== '');
        $style = $request->genre ?: trim($request->mood . ', ' . $request->instruments, ' ,');
        $payload = [
            'type' => (string)($request->providerParams['type'] ?? 'generate'),
        ];
        if ($isCustom) {
            $payload['custom'] = true;
            $payload['lyric'] = $request->lyrics;
            $payload['style'] = $style;
            $payload['title'] = $request->title;
        } else {
            $payload['prompt'] = $request->prompt;
            if ($style !== '') {
                $payload['style'] = $style;
            }
        }
        if ($this->boolValue($request->payload['instrumental'] ?? false)) {
            $payload['instrumental'] = true;
        }
        $vocalGender = $this->normalizeVocalGender((string)($request->payload['vocal_gender'] ?? ''));
        if ($vocalGender !== '') {
            $payload['vocal_gender'] = $vocalGender;
        }
        $audioId = trim((string)($request->payload['audio_id'] ?? ''));
        if ($audioId !== '') {
            $payload['audio_id'] = $audioId;
        }
        $audioUrls = $this->audioUrls($request);
        if (!empty($audioUrls)) {
            $payload['audio_urls'] = $audioUrls;
        }
        $personaId = trim((string)($request->payload['persona_id'] ?? ''));
        if ($personaId !== '' && $personaId !== '0') {
            $payload['persona_id'] = $personaId;
        }
        $callbackUrl = trim((string)($request->providerParams['callback_url'] ?? ''));
        if ($callbackUrl !== '') {
            $payload['callback_url'] = $callbackUrl;
        }
        foreach (['style_negative', 'style_influence', 'weirdness', 'audio_weight', 'variation_category'] as $key) {
            if (array_key_exists($key, $request->payload)) {
                $payload[$key] = $request->payload[$key];
            }
        }
        return $this->filterPayload(array_merge($payload, $config['extra_payload']));
    }

    private function audioUrls(AigcMusicGenerateRequest $request): array
    {
        $urls = [];
        foreach (['audio_urls', 'reference_audio_urls'] as $key) {
            if (!is_array($request->payload[$key] ?? null)) {
                continue;
            }
            foreach ($request->payload[$key] as $url) {
                $url = $this->normalizeAssetUrl((string)$url);
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }
        $single = $this->normalizeAssetUrl((string)($request->payload['audio_url'] ?? ''));
        if ($single !== '') {
            $urls[] = $single;
        }
        return array_values(array_unique($urls));
    }

    private function normalizeVocalGender(string $gender): string
    {
        return match (strtolower(trim($gender))) {
            'male', 'man', 'm', '男', '男声' => 'm',
            'female', 'woman', 'f', '女', '女声' => 'f',
            default => '',
        };
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    private function normalizeAssetUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, 'data:audio/')) {
            return $url;
        }
        return FileService::getFileUrl($url);
    }

    private function pollTask(string $taskId, array $config): array
    {
        $last = [];
        for ($i = 0; $i < (int)$config['poll_attempts']; $i++) {
            if ($i > 0) {
                sleep((int)$config['poll_interval']);
            }
            $last = $this->request('GET', $config['query_url'] . '?task_id=' . rawurlencode($taskId), $config['api_key'], [], (int)$config['timeout'], (bool)$config['ssl_verify'], (int)$config['connect_timeout']);
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

    private function request(string $method, string $url, string $apiKey, array $payload = [], int $timeout = 30, bool $sslVerify = false, int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => max(5, min($connectTimeout, $timeout)),
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
            $data['data']['task_id'] ?? null,
            $data['data']['id'] ?? null,
            $data['data']['result']['task_id'] ?? null,
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
            $data['data']['status'] ?? null,
            $data['data']['state'] ?? null,
            $data['data']['result']['status'] ?? null,
            $data['result']['status'] ?? null,
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
            'completed' => 'completed',
            '2', '3', 'failure', 'fail' => 'failed',
            'cancelled', 'cancel' => 'canceled',
            default => $status,
        };
    }

    private function extractAudioItems(array $data): array
    {
        $result = $data['data']['result'] ?? $data['result'] ?? $data['data'] ?? $data;
        $items = [];
        $this->collectAudioItems($result['data'] ?? null, $items);
        $this->collectAudioItems($result['audios'] ?? null, $items);
        $this->collectAudioItems($result['results'] ?? null, $items);
        if (empty($items)) {
            $this->collectAudioItems($result, $items);
        }
        return $items;
    }

    private function collectAudioItems(mixed $value, array &$items): void
    {
        if (is_string($value)) {
            if ($value !== '') {
                $items[] = ['audio_url' => $value];
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }
        if (!array_is_list($value)) {
            foreach (['audio_url', 'file_url', 'url', 'audio'] as $key) {
                if (!empty($value[$key]) && is_string($value[$key])) {
                    $items[] = $value;
                    return;
                }
            }
            return;
        }
        foreach ($value as $item) {
            $this->collectAudioItems($item, $items);
        }
    }

    private function extractError(array $data): string
    {
        $error = $data['error'] ?? $data['data']['error'] ?? null;
        if (is_array($error)) {
            $code = (string)($error['code'] ?? '');
            $message = (string)($error['message'] ?? '');
            return trim($message . ($code !== '' ? ' (' . $code . ')' : ''));
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
            return '音乐生成通道调用失败';
        }
        $lower = strtolower($message);
        return match (true) {
            str_contains($lower, 'certificate key usage'),
            str_contains($lower, 'certificate verify failed'),
            str_contains($lower, 'ssl certificate') => '接口渠道 SSL 证书校验失败，请在系统服务 > 接口渠道关闭 SSL校验，或更换符合规范的 HTTPS 证书',
            str_contains($lower, 'insufficient_points'),
            str_contains($lower, 'http_402'),
            str_contains($message, '余额不足'),
            str_contains($message, '点数余额不足') => '供应商点数余额不足，请联系平台管理员',
            str_contains($lower, 'auth_failed'),
            str_contains($lower, 'api key') => '供应商鉴权失败，请检查系统服务接口渠道配置',
            str_contains($lower, 'queue_limit_exceeded') => '供应商排队任务已满，请稍后再试',
            default => $message,
        };
    }

    private function filterPayload(array $payload): array
    {
        return array_filter($payload, static fn($value) => $value !== null && $value !== '' && $value !== []);
    }
}
