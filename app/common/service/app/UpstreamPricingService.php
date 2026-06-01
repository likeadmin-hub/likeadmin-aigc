<?php

namespace app\common\service\app;

use app\common\service\update\UpdateSourceClient;
use Exception;

class UpstreamPricingService
{
    private const TIMEOUT = 20;
    private const MAX_BATCH_SIZE = 100;

    public static function queryModel(string $model, string $channel = ''): array
    {
        $model = trim($model);
        if ($model === '') {
            throw new Exception('模型编码不能为空');
        }
        $params = [
            'type' => 'model',
            'model' => $model,
        ];
        $channel = trim($channel);
        if ($channel !== '') {
            $params['channel'] = $channel;
        }
        return self::normalizeItem(self::request('GET', '/api/v1/pricing', $params));
    }

    public static function queryAppApi(string $appCode, string $apiCode): array
    {
        $appCode = trim($appCode);
        $apiCode = trim($apiCode);
        if ($appCode === '' || $apiCode === '') {
            throw new Exception('应用编码和接口编码不能为空');
        }
        return self::normalizeItem(self::request('GET', '/api/v1/pricing', [
            'type' => 'app_api',
            'app_code' => $appCode,
            'api_code' => $apiCode,
        ]));
    }

    public static function queryBatch(array $items): array
    {
        $payloadItems = [];
        $localKeys = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $payload = self::normalizeRequestItem($item);
            if (empty($payload)) {
                continue;
            }
            $localKeys[] = (string)($item['local_key'] ?? '');
            $payloadItems[] = $payload;
            if (count($payloadItems) >= self::MAX_BATCH_SIZE) {
                break;
            }
        }
        if (empty($payloadItems)) {
            throw new Exception('请选择要查询的模型');
        }

        $response = self::request('POST', '/api/v1/pricing/batch', ['items' => $payloadItems]);
        $rows = $response['items'] ?? [];
        if (!is_array($rows)) {
            $rows = [];
        }
        $normalized = [];
        foreach ($payloadItems as $index => $payloadItem) {
            $row = $rows[$index] ?? [];
            $item = is_array($row) ? $row : [];
            $normalized[] = array_merge(self::normalizeItem($item), [
                'local_key' => (string)($localKeys[$index] ?? ''),
                'request' => self::withoutLocalMeta($payloadItem),
            ]);
        }
        return [
            'items' => $normalized,
            'requested_at' => date('Y-m-d H:i:s'),
            'source_base_url' => self::sourceOrigin(),
        ];
    }

    private static function normalizeRequestItem(array $item): array
    {
        $type = trim((string)($item['type'] ?? 'model'));
        if ($type === 'app_api') {
            $appCode = trim((string)($item['app_code'] ?? ''));
            $apiCode = trim((string)($item['api_code'] ?? ''));
            if ($appCode === '' || $apiCode === '') {
                return [];
            }
            $payload = [
                'type' => 'app_api',
                'app_code' => $appCode,
                'api_code' => $apiCode,
            ];
            return self::appendSpecContext($payload, $item);
        }

        $model = trim((string)($item['model'] ?? $item['model_code'] ?? ''));
        if ($model === '') {
            return [];
        }
        $payload = [
            'type' => 'model',
            'model' => $model,
        ];
        $channel = trim((string)($item['channel'] ?? $item['channel_code'] ?? ''));
        if ($channel !== '') {
            $payload['channel'] = $channel;
        }
        return self::appendSpecContext($payload, $item);
    }

    private static function appendSpecContext(array $payload, array $item): array
    {
        foreach (['app_code', 'api_code', 'provider', 'local_key', 'quality', 'quality_label', 'ratio', 'size', 'aspect_ratio', 'resolution', 'duration', 'width', 'height'] as $field) {
            if (array_key_exists($field, $item) && $item[$field] !== '' && $item[$field] !== null) {
                $payload[$field] = $item[$field];
            }
        }
        if (is_array($item['provider_params'] ?? null)) {
            $payload['provider_params'] = $item['provider_params'];
        }
        if (is_array($item['provider_params_json'] ?? null)) {
            $payload['provider_params_json'] = $item['provider_params_json'];
        }
        if (is_array($item['spec'] ?? null)) {
            $payload['spec'] = $item['spec'];
        }
        return $payload;
    }

    private static function request(string $method, string $path, array $payload = []): array
    {
        $source = UpdateSourceClient::getSource();
        $origin = self::sourceOrigin($source);
        if ($origin === '') {
            throw new Exception('请先在系统服务 > 接口渠道中配置 Base URL');
        }
        $apiKey = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? ''));
        if ($apiKey === '') {
            throw new Exception('请先在系统服务 > 接口渠道中配置 API Key');
        }

        $url = $origin . '/' . ltrim($path, '/');
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
        ];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $method === 'GET' && $payload ? $url . '?' . http_build_query($payload) : $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => UpdateSourceClient::sslVerify($source),
            CURLOPT_SSL_VERIFYHOST => UpdateSourceClient::sslVerify($source) ? 2 : 0,
        ]);
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno) {
            throw new Exception(self::friendlyError($error ?: '上游价格查询失败'));
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            throw new Exception('上游价格接口响应格式错误');
        }
        if ($status >= 400 || (isset($data['code']) && (int)$data['code'] !== 1)) {
            throw new Exception(self::friendlyError((string)($data['msg'] ?? $data['message'] ?? '上游价格查询失败')));
        }
        $result = $data['data'] ?? $data;
        return is_array($result) ? self::sanitize($result) : [];
    }

    private static function normalizeItem(array $row): array
    {
        $row = self::sanitize($row);
        return [
            'available' => (bool)($row['available'] ?? false),
            'type' => (string)($row['type'] ?? 'model'),
            'resource' => is_array($row['resource'] ?? null) ? $row['resource'] : [],
            'pricing' => is_array($row['pricing'] ?? null) ? $row['pricing'] : [],
            'price_view' => is_array($row['price_view'] ?? null) ? $row['price_view'] : [],
            'pricing_source' => is_array($row['pricing_source'] ?? null) ? $row['pricing_source'] : [],
            'error_code' => (string)($row['error_code'] ?? ''),
            'message' => (string)($row['message'] ?? $row['msg'] ?? ''),
            'raw' => $row,
            'requested_at' => date('Y-m-d H:i:s'),
            'source_base_url' => self::sourceOrigin(),
        ];
    }

    private static function sourceOrigin(array $source = []): string
    {
        $source = $source ?: UpdateSourceClient::getSource();
        $baseUrl = trim((string)($source['active_base_url'] ?? $source['base_url'] ?? ''));
        if ($baseUrl === '') {
            return '';
        }
        $parts = parse_url($baseUrl);
        $host = (string)($parts['host'] ?? '');
        if ($host === '') {
            return rtrim($baseUrl, '/');
        }
        $scheme = (string)($parts['scheme'] ?? 'https');
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        return $scheme . '://' . $host . $port;
    }

    private static function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/(key|token|secret|authorization)/i', (string)$key)) {
                $data[$key] = '******';
                continue;
            }
            if (is_array($value)) {
                $data[$key] = self::sanitize($value);
            }
        }
        return $data;
    }

    private static function withoutLocalMeta(array $item): array
    {
        unset($item['local_key']);
        return $item;
    }

    private static function friendlyError(string $message): string
    {
        $lower = strtolower($message);
        return match (true) {
            str_contains($lower, 'ssl certificate'), str_contains($lower, 'certificate') => '接口渠道 SSL 证书校验失败，请在系统服务 > 接口渠道关闭 SSL校验，或更换符合规范的 HTTPS 证书',
            str_contains($lower, 'api key'), str_contains($lower, 'unauthorized'), str_contains($lower, 'forbidden') => '上游价格接口鉴权失败，请检查系统服务 > 接口渠道配置',
            str_contains($lower, 'timed out'), str_contains($lower, 'timeout') => '上游价格接口请求超时',
            default => $message !== '' ? $message : '上游价格查询失败',
        };
    }
}
