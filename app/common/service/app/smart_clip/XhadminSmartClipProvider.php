<?php

namespace app\common\service\app\smart_clip;

use app\common\service\update\UpdateSourceClient;
use Exception;

class XhadminSmartClipProviderException extends Exception
{
    public array $response;

    public function __construct(string $message, array $response = [])
    {
        parent::__construct($message);
        $this->response = $response;
    }
}

class XhadminSmartClipProvider implements SmartClipProviderInterface
{
    private const TEMPLATE_PATH = '/api/v1/apps/smart_clip/template';
    private const TEMPLATE_DETAIL_PATH = '/api/v1/apps/smart_clip/template_detail';
    private const TASK_PATH = '/api/v1/tasks/{task_id}';

    public function templateLists(string $scene, array $params = []): array
    {
        $config = $this->resolveConfig([]);
        $query = array_filter([
            'scene' => $scene,
            'pageSize' => (int)($params['pageSize'] ?? $params['page_size'] ?? 10),
            'sid' => (string)($params['sid'] ?? ''),
            'searchKey' => (string)($params['searchKey'] ?? $params['search_key'] ?? ''),
            'searchValue' => (string)($params['searchValue'] ?? $params['search_value'] ?? ''),
            'sortBy' => (string)($params['sortBy'] ?? $params['sort_by'] ?? 'desc'),
        ], static fn($value) => $value !== '' && $value !== 0);
        $response = $this->request('GET', $config['base_url'] . self::TEMPLATE_PATH . '?' . http_build_query($query), $config['api_key'], [], $config);
        return $this->responseData($response);
    }

    public function templateDetail(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            throw new Exception('请选择模板');
        }
        $config = $this->resolveConfig([]);
        $response = $this->request('GET', $config['base_url'] . self::TEMPLATE_DETAIL_PATH . '?id=' . rawurlencode($id), $config['api_key'], [], $config);
        return $this->responseData($response);
    }

    public function generate(SmartClipGenerateRequest $request): SmartClipGenerateResult
    {
        try {
            $config = $this->resolveConfig($request->channelConfig);
            $payload = $this->buildPayload($request);
            $submit = $this->request('POST', $config['base_url'] . '/api/v1/apps/smart_clip/' . $request->api, $config['api_key'], $payload, $config);
            $data = $this->responseData($submit);
            $taskId = $this->extractTaskId($data);
            if ($taskId === '') {
                return new SmartClipGenerateResult(false, [], '供应商未返回任务ID', '', $submit);
            }
            return new SmartClipGenerateResult(true, [], '', $taskId, $submit);
        } catch (\Throwable $e) {
            $raw = $e instanceof XhadminSmartClipProviderException ? $e->response : [];
            return new SmartClipGenerateResult(false, [], $this->friendlyError($e->getMessage()), '', $raw);
        }
    }

    public function fetchResult(string $taskId, SmartClipGenerateRequest $request): SmartClipGenerateResult
    {
        try {
            $config = $this->resolveConfig($request->channelConfig);
            $task = $this->request('GET', str_replace('{task_id}', rawurlencode($taskId), $config['task_url_template']), $config['api_key'], [], $config);
            if ($this->isTaskPending($task)) {
                return new SmartClipGenerateResult(true, [], '', $taskId, $task);
            }
            $status = $this->extractTaskStatus($task);
            $videoUrls = $this->extractVideoUrls($task);
            if (!in_array($status, ['success', 'completed', 'succeeded'], true) && !empty($videoUrls)) {
                $status = 'success';
            }
            if (!in_array($status, ['success', 'completed', 'succeeded'], true)) {
                return new SmartClipGenerateResult(false, [], $this->extractError($task) ?: '剪辑任务未完成', $taskId, $task);
            }
            if (empty($videoUrls)) {
                return new SmartClipGenerateResult(false, [], '供应商未返回视频', $taskId, $task);
            }
            $videos = [];
            foreach ($videoUrls as $url) {
                $stored = SmartClipAssetService::persistGeneratedVideo($url, (int)($config['tenant_id'] ?? 0), (int)($config['user_id'] ?? 0));
                $videos[] = array_merge($stored, [
                    'provider_task_id' => $taskId,
                    'duration' => (float)$this->extractResultValue($task, ['duration', 'result.duration', 'data.result.duration', 'data.result.data.duration']),
                    'cover_url' => (string)$this->extractResultValue($task, ['cover_url', 'coverUrl', 'result.cover_url', 'result.coverUrl', 'data.result.cover_url', 'data.result.coverUrl', 'data.result.data.cover_url', 'data.result.data.coverUrl']),
                    'raw' => $task,
                ]);
            }
            return new SmartClipGenerateResult(true, $videos, '', $taskId, $task);
        } catch (\Throwable $e) {
            return new SmartClipGenerateResult(false, [], $this->friendlyError($e->getMessage()), $taskId);
        }
    }

    private function buildPayload(SmartClipGenerateRequest $request): array
    {
        $payload = array_merge([
            'styleId' => $request->styleId,
            'videoUrl' => $request->videoUrl ?: null,
            'audioUrl' => $request->audioUrl ?: null,
            'title' => $request->title !== '' ? $request->title : null,
            'language' => $request->language ?: null,
            'materials' => $request->materials ?: null,
            'introduceCard' => $request->introduceCard ?: null,
            'packRules' => $request->packRules ?: null,
            'processRules' => $request->processRules ?: null,
            'structLayers' => $request->structLayers ?: null,
            'subtitle' => $request->subtitle ?: null,
            'callbackUrl' => $request->callbackUrl ?: null,
        ], $request->providerParams['extra_payload'] ?? []);
        $payload = $this->normalizeFirstFrameCover($payload);
        if (isset($payload['packRules']) && is_array($payload['packRules'])) {
            $payload['packRules'] = $this->normalizePackRules($payload['packRules']);
        }
        if (isset($payload['processRules']) && is_array($payload['processRules'])) {
            $payload['processRules'] = $this->normalizeProcessRules($payload['processRules']);
        }
        if (isset($payload['structLayers']) && is_array($payload['structLayers'])) {
            $payload['structLayers'] = $this->normalizeStructLayers($payload['structLayers']);
            $payload['structLayers'] = $this->filterTemplateStructLayers((string)$request->styleId, $payload['structLayers']);
        }
        if ($request->api === 'realman_broadcast') {
            unset($payload['materials']);
        }
        return $this->filterPayload($payload);
    }

    private function normalizeFirstFrameCover(array $payload): array
    {
        $cover = $payload['firstFrameCover'] ?? null;
        if (isset($payload['processRules']) && is_array($payload['processRules']) && array_key_exists('firstFrameCover', $payload['processRules'])) {
            $cover = $payload['processRules']['firstFrameCover'];
            unset($payload['processRules']['firstFrameCover']);
        }
        if ($cover === null || $cover === '' || $cover === []) {
            unset($payload['firstFrameCover']);
            return $payload;
        }
        if (!is_array($cover)) {
            $enabled = $this->boolValue($cover);
            if (!$enabled) {
                unset($payload['firstFrameCover']);
                return $payload;
            }
            $cover = ['coverSwitch' => true];
        }
        if (array_key_exists('coverSwitch', $cover)) {
            $cover['coverSwitch'] = $this->boolValue($cover['coverSwitch']);
        }
        $payload['firstFrameCover'] = $cover;
        return $payload;
    }

    private function normalizePackRules(array $rules): array
    {
        if (empty($rules['backgroundMusic']) || !is_array($rules['backgroundMusic'])) {
            return $rules;
        }
        $music = $rules['backgroundMusic'];
        if (array_key_exists('audioSwitch', $music)) {
            $music['audioSwitch'] = $this->boolValue($music['audioSwitch']);
        }
        if (!array_key_exists('volume', $music) || $music['volume'] === '') {
            $music['volume'] = 0.3;
        }
        if (is_numeric($music['volume'])) {
            $music['volume'] = round(max(0, min(1, (float)$music['volume'])), 1);
        } else {
            $music['volume'] = 0.3;
        }
        $rules['backgroundMusic'] = $music;
        return $rules;
    }

    private function normalizeProcessRules(array $rules): array
    {
        if (array_key_exists('firstFrameCover', $rules) && !is_array($rules['firstFrameCover'])) {
            $rules['firstFrameCover'] = [
                'coverSwitch' => $this->boolValue($rules['firstFrameCover']),
            ];
        }
        return $rules;
    }

    private function normalizeStructLayers(array $layers): array
    {
        $map = [
            'title' => 'headerLayer',
            'header' => 'headerLayer',
            'headerLayer' => 'headerLayer',
            'subtitle' => 'subtitleLayer',
            'subtitleLayer' => 'subtitleLayer',
            'introduceCard' => 'ipLayer',
            'introduce_card' => 'ipLayer',
            'ip' => 'ipLayer',
            'ipLayer' => 'ipLayer',
            'background' => 'backgroundLayer',
            'backgroundLayer' => 'backgroundLayer',
            'digitalHuman' => 'figureLayer',
            'digital_human' => 'figureLayer',
            'figure' => 'figureLayer',
            'figureLayer' => 'figureLayer',
        ];
        $normalized = [];
        foreach ($layers as $layer) {
            if (!is_array($layer)) {
                continue;
            }
            $code = trim((string)($layer['markCode'] ?? $layer['mark_code'] ?? ''));
            if ($code === '') {
                $key = trim((string)($layer['key'] ?? $layer['code'] ?? $layer['name'] ?? ''));
                $code = $map[$key] ?? '';
            }
            if ($code === '') {
                continue;
            }
            $item = ['markCode' => $code];
            if (array_key_exists('visible', $layer)) {
                $item['visible'] = $this->boolValue($layer['visible']);
            } elseif (array_key_exists('show', $layer)) {
                $item['visible'] = $this->boolValue($layer['show']);
            } elseif (array_key_exists('switch', $layer)) {
                $item['visible'] = $this->boolValue($layer['switch']);
            } elseif (array_key_exists('enabled', $layer)) {
                $item['visible'] = $this->boolValue($layer['enabled']);
            } else {
                $item['visible'] = true;
            }
            $normalized[] = $item;
        }
        return $normalized;
    }

    private function filterTemplateStructLayers(string $styleId, array $layers): array
    {
        $available = $this->templateAvailableLayers($styleId);
        if (empty($available)) {
            return $layers;
        }
        return array_values(array_filter($layers, static function ($layer) use ($available) {
            return is_array($layer) && in_array((string)($layer['markCode'] ?? ''), $available, true);
        }));
    }

    private function templateAvailableLayers(string $styleId): array
    {
        if ($styleId === '') {
            return [];
        }
        try {
            $detail = $this->templateDetail($styleId);
        } catch (\Throwable) {
            return [];
        }
        $editInfo = $detail['videoStructInfo']['editInfo']
            ?? $detail['video_struct_info']['edit_info']
            ?? $detail['video_struct_info']['editInfo']
            ?? $detail['editInfo']
            ?? [];
        if (!is_array($editInfo)) {
            return [];
        }
        $available = [];
        foreach (['headerLayer', 'subtitleLayer', 'ipLayer', 'figureLayer', 'backgroundLayer'] as $code) {
            if (!array_key_exists($code, $editInfo)) {
                continue;
            }
            $layer = $editInfo[$code];
            if (is_array($layer) && !empty($layer)) {
                $available[] = $code;
            }
        }
        return $available;
    }

    private function boolValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function resolveConfig(array $channelConfig): array
    {
        $source = UpdateSourceClient::getSource();
        $baseUrl = $this->sourceBaseUrl((string)($source['active_base_url'] ?? $source['base_url'] ?? ''));
        if ($baseUrl === '') {
            throw new Exception('请先在系统服务的接口渠道中配置 Base URL');
        }
        $apiKey = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? ''));
        if ($apiKey === '') {
            throw new Exception('请先在系统服务的接口渠道中配置 API Key');
        }
        $taskPath = (string)($channelConfig['task_path'] ?? self::TASK_PATH);
        return [
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'task_url_template' => $baseUrl . '/' . ltrim($taskPath, '/'),
            'timeout' => max(5, (int)($channelConfig['timeout'] ?? 30)),
            'ssl_verify' => UpdateSourceClient::sslVerify($source),
            'tenant_id' => (int)($channelConfig['tenant_id'] ?? 0),
            'user_id' => (int)($channelConfig['user_id'] ?? 0),
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

    private function request(string $method, string $url, string $apiKey, array $payload, array $config): array
    {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int)$config['timeout'],
            CURLOPT_SSL_VERIFYPEER => (bool)$config['ssl_verify'],
            CURLOPT_SSL_VERIFYHOST => (bool)$config['ssl_verify'] ? 2 : 0,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno) {
            throw new Exception('接口请求失败：' . $error);
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            throw new Exception('接口响应格式错误');
        }
        if ($httpCode >= 400) {
            throw new XhadminSmartClipProviderException((string)($data['message'] ?? $data['msg'] ?? '接口请求失败'), $data);
        }
        $code = $data['code'] ?? ($data['result']['code'] ?? 1);
        if (!in_array((string)$code, ['1', 'success', 'Succeed', 'ok', '200'], true)) {
            throw new XhadminSmartClipProviderException((string)($data['message'] ?? $data['msg'] ?? $data['result']['message'] ?? $data['result']['msg'] ?? '接口业务失败'), $data);
        }
        return $data;
    }

    private function responseData(array $response): array
    {
        if (isset($response['data']['result']['data']) && is_array($response['data']['result']['data'])) {
            return $response['data']['result']['data'];
        }
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }
        if (isset($response['result']['data']) && is_array($response['result']['data'])) {
            return $response['result']['data'];
        }
        if (isset($response['result']) && is_array($response['result'])) {
            return $response['result'];
        }
        return $response;
    }

    private function extractTaskId(array $data): string
    {
        foreach (['task_id', 'taskId', 'id'] as $key) {
            $value = trim((string)($data[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function isTaskPending(array $task): bool
    {
        return in_array($this->extractTaskStatus($task), ['queued', 'pending', 'processing', 'running'], true);
    }

    private function extractTaskStatus(array $task): string
    {
        $status = strtolower(trim((string)$this->extractResultValue($task, ['status', 'data.status', 'result.status'])));
        return $status ?: 'processing';
    }

    private function extractVideoUrls(array $task): array
    {
        $values = [];
        foreach ([
            'video_url',
            'videoUrl',
            'result.video_url',
            'result.videoUrl',
            'result.data.video_url',
            'result.data.videoUrl',
            'data.video_url',
            'data.videoUrl',
            'data.result.video_url',
            'data.result.videoUrl',
            'data.result.data.video_url',
            'data.result.data.videoUrl',
        ] as $path) {
            $value = $this->extractResultValue($task, [$path]);
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }
        foreach (['videos', 'video_urls', 'data.videos', 'data.video_urls', 'result.videos', 'result.video_urls', 'data.result.videos', 'data.result.video_urls'] as $path) {
            $items = $this->extractResultValue($task, [$path]);
            if (is_array($items)) {
                foreach ($items as $item) {
                    $url = is_array($item) ? (string)($item['url'] ?? $item['video_url'] ?? $item['videoUrl'] ?? '') : (string)$item;
                    if ($url !== '') {
                        $values[] = $url;
                    }
                }
            }
        }
        return array_values(array_unique($values));
    }

    private function extractError(array $task): string
    {
        foreach (['error', 'data.error', 'result.error'] as $path) {
            $error = $this->extractResultValue($task, [$path]);
            if (is_array($error)) {
                $code = trim((string)($error['code'] ?? ''));
                $message = trim((string)($error['message'] ?? $error['msg'] ?? ''));
                if ($code !== '' && $message !== '') {
                    return $code . '：' . $message;
                }
                if ($message !== '') {
                    return $message;
                }
                if ($code !== '') {
                    return $code;
                }
            }
        }
        return (string)$this->extractResultValue($task, [
            'error.message',
            'error.msg',
            'data.error.message',
            'data.error.msg',
            'result.error.message',
            'result.error.msg',
            'error',
            'data.error',
            'result.error',
            'message',
            'data.message',
            'result.message',
            'msg',
            'data.msg',
            'result.msg',
        ]);
    }

    private function extractResultValue(array $data, array $paths)
    {
        foreach ($paths as $path) {
            $cursor = $data;
            foreach (explode('.', $path) as $segment) {
                if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                    $cursor = null;
                    break;
                }
                $cursor = $cursor[$segment];
            }
            if ($cursor !== null && $cursor !== '') {
                return $cursor;
            }
        }
        return null;
    }

    private function filterPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $value = $this->filterPayload($value);
                $payload[$key] = $value;
            }
            if ($value === null || $value === '' || $value === []) {
                unset($payload[$key]);
            }
        }
        return $payload;
    }

    private function friendlyError(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return '智能剪辑服务暂不可用';
        }
        if (str_contains($message, '401') || str_contains(strtolower($message), 'unauthorized')) {
            return '智能剪辑接口鉴权失败，请检查接口渠道 API Key';
        }
        if (str_contains(strtolower($message), 'timeout')) {
            return '智能剪辑接口响应超时，请稍后重试';
        }
        if (preg_match('/field:\s*([\'"]?)([^\'"\s|]+)\1\s*\|\s*(.+)$/i', $message, $match)) {
            return trim('上游参数错误：' . $match[2] . ' ' . $match[3]);
        }
        return mb_substr($message, 0, 160);
    }
}
