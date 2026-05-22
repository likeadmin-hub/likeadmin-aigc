<?php

namespace app\common\service\app\aigc_digital_human;

use app\common\service\update\UpdateSourceClient;
use Exception;

class XhadminAigcDigitalHumanProvider implements AigcDigitalHumanProviderInterface
{
    private const TTS_PATH = '/api/v1/apps/voice_tts/tts_live';
    private const CLONE_VOICE_PATH = '/api/v1/apps/voice_tts/clone_voice';
    private const LIPSYNC_PATH = '/api/v1/apps/lipsync/submit';
    private const TASK_PATH = '/api/v1/tasks/{task_id}';

    public function generate(AigcDigitalHumanGenerateRequest $request): AigcDigitalHumanGenerateResult
    {
        $tts = $this->submitTts($request);
        return new AigcDigitalHumanGenerateResult(true, [], '', $tts['task_id'] ?? '');
    }

    public function submitTts(AigcDigitalHumanGenerateRequest $request): array
    {
        $config = $this->resolveConfig($request);
        $payload = array_filter(array_merge([
            'text' => $request->scriptText,
            'model' => $request->providerParams['tts_model'] ?? $config['tts_model'],
            'format' => $request->providerParams['tts_format'] ?? 'mp3',
            'reference_id' => (string)($request->voice['provider_asset_id'] ?? ''),
            'normalize' => true,
        ], $config['tts_payload'], $request->providerParams['tts_payload'] ?? []), static fn($value) => $value !== null && $value !== '' && $value !== []);
        $data = $this->request('POST', $config['tts_url'], $config['api_key'], $payload, $config['timeout'], (bool)$config['ssl_verify']);
        $taskId = $this->extractTaskId($data);
        if ($taskId === '') {
            throw new Exception('供应商未返回音频任务ID');
        }
        return ['task_id' => $taskId, 'payload' => $data];
    }

    public function fetchTtsResult(string $taskId, AigcDigitalHumanGenerateRequest $request): array
    {
        $task = $this->fetchTask($taskId, $request);
        return [
            'pending' => $this->isTaskPending($task),
            'success' => $this->isTaskSuccess($task),
            'audio_url' => $this->extractMediaUrl($task, 'audio'),
            'error' => $this->extractError($task),
            'payload' => $task,
        ];
    }

    public function submitLipsync(AigcDigitalHumanGenerateRequest $request, string $audioUrl): array
    {
        $config = $this->resolveConfig($request);
        $payload = array_filter(array_merge([
            'mode' => 'async_query',
            'model' => $request->providerParams['lipsync_model'] ?? $config['lipsync_model'],
            'audio_url' => $audioUrl,
            'video_url' => (string)($request->avatar['media_url'] ?? ''),
        ], $config['lipsync_payload'], $request->providerParams['lipsync_payload'] ?? []), static fn($value) => $value !== null && $value !== '' && $value !== []);
        $data = $this->request('POST', $config['lipsync_url'], $config['api_key'], $payload, $config['timeout'], (bool)$config['ssl_verify']);
        $taskId = $this->extractTaskId($data);
        if ($taskId === '') {
            throw new Exception('供应商未返回视频任务ID');
        }
        return ['task_id' => $taskId, 'payload' => $data];
    }

    public function fetchLipsyncResult(string $taskId, AigcDigitalHumanGenerateRequest $request): array
    {
        $task = $this->fetchTask($taskId, $request);
        return [
            'pending' => $this->isTaskPending($task),
            'success' => $this->isTaskSuccess($task),
            'video_url' => $this->extractMediaUrl($task, 'video'),
            'error' => $this->extractError($task),
            'payload' => $task,
        ];
    }

    public function cloneVoice(array $payload, int $tenantId, int $userId): string
    {
        $request = new AigcDigitalHumanGenerateRequest('', '', channelConfig: [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
        ]);
        $config = $this->resolveConfig($request);
        $requestPayload = array_filter([
            'title' => (string)($payload['title'] ?? $payload['name'] ?? ''),
            'audio_url' => (string)($payload['audio_url'] ?? ''),
            'visibility' => (string)($payload['visibility'] ?? 'private'),
            'tags' => $payload['tags'] ?? [],
            'texts' => $payload['texts'] ?? [],
            'description' => (string)($payload['description'] ?? ''),
            'enhance_audio_quality' => (bool)($payload['enhance_audio_quality'] ?? false),
        ], static fn($value) => $value !== null && $value !== '');
        $data = $this->request('POST', $config['clone_voice_url'], $config['api_key'], $requestPayload, max(60, (int)$config['timeout']), (bool)$config['ssl_verify']);
        $voiceId = $this->extractVoiceId($data);
        if ($voiceId === '') {
            throw new Exception('供应商未返回音色ID');
        }
        return $voiceId;
    }

    private function fetchTask(string $taskId, AigcDigitalHumanGenerateRequest $request): array
    {
        $config = $this->resolveConfig($request);
        return $this->request('GET', str_replace('{task_id}', rawurlencode($taskId), $config['task_url_template']), $config['api_key'], [], $config['timeout'], (bool)$config['ssl_verify']);
    }

    private function resolveConfig(AigcDigitalHumanGenerateRequest $request): array
    {
        $config = array_merge($request->channelConfig, $request->providerParams['channel_config'] ?? []);
        $source = UpdateSourceClient::getSource();
        $baseUrl = $this->sourceBaseUrl((string)($source['active_base_url'] ?? $source['base_url'] ?? ''));
        $apiKey = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? ''));
        if ($baseUrl === '') {
            throw new Exception('请先在系统服务的接口渠道中配置 Base URL');
        }
        if ($apiKey === '') {
            throw new Exception('请先在系统服务的接口渠道中配置 API Key');
        }
        return [
            'api_key' => $apiKey,
            'tts_url' => $baseUrl . '/' . ltrim((string)($config['tts_path'] ?? self::TTS_PATH), '/'),
            'clone_voice_url' => $baseUrl . '/' . ltrim((string)($config['clone_voice_path'] ?? self::CLONE_VOICE_PATH), '/'),
            'lipsync_url' => $baseUrl . '/' . ltrim((string)($config['lipsync_path'] ?? self::LIPSYNC_PATH), '/'),
            'task_url_template' => $baseUrl . '/' . ltrim((string)($config['task_path'] ?? self::TASK_PATH), '/'),
            'tts_model' => (string)($config['tts_model'] ?? 's2-pro'),
            'lipsync_model' => (string)($config['lipsync_model'] ?? $config['model'] ?? 'xiaojiayu1.0'),
            'tts_payload' => is_array($config['tts_payload'] ?? null) ? $config['tts_payload'] : [],
            'lipsync_payload' => is_array($config['lipsync_payload'] ?? null) ? $config['lipsync_payload'] : [],
            'timeout' => max(5, (int)($config['timeout'] ?? 30)),
            'ssl_verify' => UpdateSourceClient::sslVerify($source),
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
            throw new Exception($this->friendlyError($error ?: '供应商网络请求失败'));
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            throw new Exception('供应商响应格式错误');
        }
        if ($httpCode >= 400 || isset($data['error']) || (isset($data['code']) && (int)$data['code'] !== 1)) {
            throw new Exception($this->friendlyError($this->extractError($data) ?: '供应商请求失败'));
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
            $data['data']['task']['task_id'] ?? null,
            $data['data']['result']['task_id'] ?? null,
            $data['result']['task_id'] ?? null,
        ] as $value) {
            if (is_scalar($value) && (string)$value !== '') {
                return (string)$value;
            }
        }
        return '';
    }

    private function extractVoiceId(array $data): string
    {
        foreach ([
            $data['voice_id'] ?? null,
            $data['reference_id'] ?? null,
            $data['model_id'] ?? null,
            $data['id'] ?? null,
            $data['result']['voice_id'] ?? null,
            $data['result']['reference_id'] ?? null,
            $data['result']['model_id'] ?? null,
            $data['result']['id'] ?? null,
            $data['data']['voice_id'] ?? null,
            $data['data']['reference_id'] ?? null,
            $data['data']['id'] ?? null,
            $data['data']['model_id'] ?? null,
            $data['data']['result']['voice_id'] ?? null,
            $data['data']['result']['reference_id'] ?? null,
            $data['data']['result']['model_id'] ?? null,
            $data['data']['result']['id'] ?? null,
            $data['data']['result']['voice']['voice_id'] ?? null,
            $data['data']['result']['voice']['id'] ?? null,
            $data['result']['voice']['voice_id'] ?? null,
            $data['result']['voice']['id'] ?? null,
        ] as $value) {
            if (is_scalar($value) && (string)$value !== '') {
                return (string)$value;
            }
        }
        return '';
    }

    private function extractStatus(array $data): string
    {
        foreach ([$data['status'] ?? null, $data['state'] ?? null, $data['task_status'] ?? null, $data['data']['status'] ?? null, $data['data']['state'] ?? null, $data['data']['task_status'] ?? null] as $value) {
            if (is_scalar($value) && (string)$value !== '') {
                return $this->normalizeStatus((string)$value);
            }
        }
        return '';
    }

    private function normalizeStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            '1', 'ok', 'done', 'finished', 'finish', 'complete', 'completed', 'success', 'succeeded' => 'success',
            '2', '3', 'failure', 'fail', 'failed', 'error' => 'failed',
            'cancelled', 'cancel' => 'canceled',
            default => strtolower(trim($status)),
        };
    }

    private function isTaskPending(array $task): bool
    {
        $status = $this->extractStatus($task);
        return $status === '' || in_array($status, ['pending', 'running', 'processing', 'queued', 'created', 'submitted'], true);
    }

    private function isTaskSuccess(array $task): bool
    {
        return $this->extractStatus($task) === 'success';
    }

    private function extractMediaUrl(array $data, string $kind): string
    {
        $keys = $kind === 'audio'
            ? ['audio_url', 'audio', 'url', 'uri', 'output', 'file_url', 'download_url']
            : ['video_url', 'video', 'url', 'uri', 'output', 'file_url', 'download_url'];
        foreach ([$data['result'] ?? null, $data['data']['result'] ?? null, $data['output'] ?? null, $data['data']['output'] ?? null, $data['data'] ?? null, $data] as $candidate) {
            $url = $this->collectMediaUrl($candidate, $keys, $kind);
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }

    private function collectMediaUrl(mixed $value, array $keys, string $kind): string
    {
        if (is_string($value)) {
            return $this->normalizeMediaUrl($value, $kind, false);
        }
        if (!is_array($value)) {
            return '';
        }
        foreach ($keys as $key) {
            if (!empty($value[$key]) && is_string($value[$key])) {
                $url = $this->normalizeMediaUrl($value[$key], $kind, true);
                if ($url !== '') {
                    return $url;
                }
            }
        }
        foreach ($value as $item) {
            $url = $this->collectMediaUrl($item, $keys, $kind);
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }

    private function normalizeMediaUrl(string $url, string $kind, bool $fromMediaKey): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('/^(https?:\/\/|data:(?:audio|video)\/)/i', $url)) {
            return $url;
        }
        $path = ltrim((string)(parse_url($url, PHP_URL_PATH) ?: $url), '/');
        if ($path === '' || (!str_starts_with($path, 'uploads/') && !str_starts_with($path, 'resource/'))) {
            return '';
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allowed = $kind === 'audio'
            ? ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'opus']
            : ['mp4', 'mov', 'webm', 'm4v'];
        return in_array($ext, $allowed, true) ? $url : '';
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
            return '数字人通道调用失败';
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
}
