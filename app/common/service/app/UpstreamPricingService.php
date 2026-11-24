<?php

namespace app\common\service\app;

use app\common\service\update\UpdateSourceClient;
use Exception;
use app\common\service\power\MarketCatalogBadges;

class UpstreamPricingService
{
    // Pricing is a catalogue operation rather than an interactive model call.
    // Give an individual small batch enough time to finish, while the market
    // service keeps the batches bounded so one slow catalogue response cannot
    // block the entire sync.
    private const TIMEOUT = 35;
    private const MAX_BATCH_SIZE = 100;

    public static function queryAccountBalance(): array
    {
        $source = self::sourceConfiguration();
        $origin = self::sourceOrigin($source);
        if (!in_array(strtolower((string)parse_url($origin, PHP_URL_SCHEME)), ['https', 'http'], true)
            || !parse_url($origin, PHP_URL_HOST)) {
            $origin = '';
        }
        $balance = [
            'status' => 'unconfigured',
            'environment' => empty($source['dev_mode']) ? 'online' : 'development',
            'origin' => $origin,
            'available_points' => null,
            'checked_at' => null,
        ];
        if ($origin === '' || empty($source['active_api_key'])) {
            return $balance;
        }

        $balance['status'] = 'unavailable';
        try {
            $response = self::request('GET', '/api/v1/user/balance', [], 6, $source);
        } catch (Exception) {
            return $balance;
        }

        $points = $response['available_points'] ?? null;
        if (!is_numeric($points) || !is_finite((float)$points)) {
            return $balance;
        }
        $balance['status'] = 'available';
        $balance['available_points'] = (float)$points;
        $balance['checked_at'] = time();
        return $balance;
    }

    public static function queryModel(string $model, string $channel = ''): array
    {
        $model = trim($model);
        if ($model === '') {
            throw new Exception('模型编码不能为空');
        }
        $item = [
            'type' => 'model',
            'model' => $model,
        ];
        $channel = trim($channel);
        if ($channel !== '') {
            $item['channel'] = $channel;
        }
        $result = self::queryBatch([$item]);
        return $result['items'][0] ?? self::normalizeItem([]);
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

    public static function queryModels(string $type = '', bool $detail = false, int $timeout = self::TIMEOUT): array
    {
        if ($timeout<1 || $timeout>60) throw new Exception('模型目录读取超时须为 1 至 60 秒');
        $params = [];
        $type = trim($type);
        if ($type !== '') {
            $params['type'] = $type;
        }
        if ($detail) {
            $params['detail'] = 1;
        }
        $response = self::request('GET', '/api/v1/models', $params, $timeout);
        $rows = $response['data'] ?? $response['items'] ?? $response['models'] ?? $response;
        $models = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        if (!$detail || !$models) return $models;
        return MarketCatalogBadges::models($models, self::publicBadgeRows('modelListPublic', ['type_code' => $type], $timeout));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function queryApps(int $timeout = self::TIMEOUT): array
    {
        $response = self::request('GET', '/api/v1/apps', [], $timeout);
        $rows = $response['data'] ?? $response['items'] ?? $response['apps'] ?? $response;
        $apps = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        return $apps ? MarketCatalogBadges::applications($apps, self::publicBadgeRows('appListPublic', [], $timeout)) : [];
    }

    /** Older pricing catalogs publish their badges only on the same origin's public market. */
    private static function publicBadgeRows(string $action, array $params, int $timeout): array
    {
        $rows = [];
        try {
            for ($page = 1; ; $page++) {
                $data = self::request('GET', '/api/user_center/' . $action, $params + [
                    'terminal' => 4, 'page_no' => $page, 'page_size' => 500,
                ], min(8, $timeout));
                $batch = $data['lists'] ?? [];
                if (!is_array($batch) || !$batch) break;
                array_push($rows, ...array_filter($batch, 'is_array'));
                if ($action === 'appListPublic' || count($rows) >= (int)($data['count'] ?? 0)) break;
                if ((int)($data['page_no'] ?? 0) !== $page) break;
            }
        } catch (\Exception $error) {
            // The display catalog is optional; keep successful pricing reads usable.
            \think\facade\Log::warning('上游公开标签目录读取失败：' . $action);
        }
        return $rows;
    }

    /** Read published API request metadata; never executes an application. */
    public static function queryAppDetail(string $appCode): array
    {
        $appCode = trim($appCode);
        if (!preg_match('/^[a-z0-9_-]+$/D', $appCode)) throw new Exception('应用编码无效');
        return self::request('GET', '/api/v1/apps/' . $appCode);
    }

    public static function queryDeveloperDocDetail(string $slug): array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return [];
        }
        return self::request('GET', '/api/user_center/developerDocDetail', [
            'slug' => $slug,
            'terminal' => 4,
        ]);
    }

    /** Published documentation only; an ID is never an instruction to execute a model/application. */
    public static function queryDeveloperDocDetailByModelId($modelId): array
    {
        return self::request('GET', '/api/user_center/developerDocDetail', [
            'model_id' => self::documentId($modelId),
            'terminal' => 4,
        ]);
    }

    public static function queryDeveloperDocDetailByAppApiId($appApiId): array
    {
        return self::request('GET', '/api/user_center/developerDocDetail', [
            'app_api_id' => self::documentId($appApiId),
            'terminal' => 4,
        ]);
    }

    private static function documentId($value): int
    {
        // Do not let PHP's scalar coercion turn true, decimals or suffixed IDs into another resource.
        if ((!is_int($value) && (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)))
            || ($id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false) {
            throw new \InvalidArgumentException('平台文档资源 ID 必须为正整数');
        }
        return $id;
    }

    public static function queryDeveloperDocTree(): array
    {
        $response = self::request('GET', '/api/user_center/developerDocTree', [
            'terminal' => 4,
        ]);
        $tree = $response['tree'] ?? $response['data']['tree'] ?? $response;
        return is_array($tree) ? $tree : [];
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
        if (!is_array($rows) || count($rows) !== count($payloadItems)) {
            throw new Exception('上游价格批次返回不完整，已保留原有价格');
        }
        $normalized = [];
        foreach ($payloadItems as $index => $payloadItem) {
            $row = $rows[$index] ?? [];
            if (!is_array($row) || !array_key_exists('available', $row)) throw new Exception('上游价格批次缺少可用状态，已保留原有价格');
            $item = $row;
            $normalized[] = array_merge(self::normalizeItem($item, $payloadItem), [
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
        foreach (['app_code', 'api_code', 'provider', 'local_key', 'quality', 'quality_label', 'ratio', 'size', 'aspect_ratio', 'resolution', 'duration', 'mode', 'width', 'height'] as $field) {
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

    private static function request(
        string $method,
        string $path,
        array $payload = [],
        int $timeout = self::TIMEOUT,
        ?array $source = null
    ): array
    {
        $state = function_exists('request') ? request()->upstreamCatalogBatch : null;
        $key = self::readKey($method, $path, $payload);
        if (is_array($state) && array_key_exists($key, $state['responses'])) {
            $cached = $state['responses'][$key];
            if ($cached instanceof \Throwable) throw $cached;
            return $cached;
        }
        if (is_array($state) && self::isMetadataPath($path)) {
            if ($state['deadline'] === null) {
                $state['deadline'] = microtime(true) + $state['metadata_budget'];
                request()->upstreamCatalogBatch = $state;
            }
            $remaining = $state['deadline'] - microtime(true);
            if ($remaining < 1) throw new Exception('上游说明读取超过本批次时限');
            $timeout = min($timeout, 5, (int)ceil($remaining));
        }

        $source ??= self::sourceConfiguration();
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
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_ENCODING => '',
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
        return self::decodeRead((string)$body, $status);
    }

    private static function decodeRead(string $body, int $status): array
    {
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


    /** Scope all memoized reads to this synchronization and restore it even on failure. */
    public static function withCatalogBatch(callable $read, int $metadataBudget = 15): array
    {
        if (!function_exists('request')) return $read();
        $request = request();
        $previous = $request->upstreamCatalogBatch;
        $request->upstreamCatalogBatch = ['deadline' => null, 'metadata_budget' => $metadataBudget, 'responses' => []];
        try { return $read(); }
        finally { $request->upstreamCatalogBatch = $previous; }
    }

    private static function readKey(string $method, string $path, array $payload): string
    {
        return hash('sha256', json_encode([$method, $path, $payload], JSON_THROW_ON_ERROR));
    }

    private static function isMetadataPath(string $path): bool
    {
        return str_starts_with($path, '/api/user_center/developerDoc') || str_starts_with($path, '/api/v1/apps/');
    }

    public static function prefetchMetadata(array $models = [], array $apps = []): void
    {
        $jobs = [];
        foreach ($models as $model) {
            $id = \app\common\service\power\MarketGenerationSourceIdentity::modelId($model);
            if ($id > 0) $jobs[] = ['GET', '/api/user_center/developerDocDetail', ['model_id' => $id, 'terminal' => 4]];
        }
        foreach ($apps as $app) {
            $code = trim((string)($app['code'] ?? ''));
            if (!preg_match('/^[a-z0-9_-]+$/D', $code)) continue;
            $jobs[] = ['GET', '/api/v1/apps/'.$code, []];
            foreach ((array)($app['apis'] ?? []) as $api) {
                if (!is_array($api)) continue;
                $id = \app\common\service\power\MarketGenerationSourceIdentity::appApiId($api);
                if ($id > 0) $jobs[] = ['GET', '/api/user_center/developerDocDetail', ['app_api_id' => $id, 'terminal' => 4]];
            }
        }
        $state = function_exists('request') ? request()->upstreamCatalogBatch : null;
        if (is_array($state)) {
            $state['deadline'] = microtime(true) + $state['metadata_budget'];
            request()->upstreamCatalogBatch = $state;
        }
        self::prefetch($jobs, true);
    }

    /** Prime the existing queryBatch decoder; keep each chunk's error isolated. */
    public static function prefetchPricing(array $chunks): void
    {
        $jobs = [];
        foreach ($chunks as $chunk) {
            $items = [];
            foreach ($chunk as $item) {
                $payload = self::normalizeRequestItem($item);
                if ($payload) $items[] = $payload;
            }
            if ($items) $jobs[] = ['POST', '/api/v1/pricing/batch', ['items' => array_slice($items, 0, self::MAX_BATCH_SIZE)]];
        }
        self::prefetch($jobs, false);
    }

    private static function prefetch(array $requests, bool $metadata): void
    {
        $state = function_exists('request') ? request()->upstreamCatalogBatch : null;
        if (!is_array($state) || !$requests) return;
        $jobs = [];
        foreach ($requests as $job) {
            $key = self::readKey(...$job);
            if (!array_key_exists($key, $state['responses'])) $jobs[$key] = $job;
        }
        if (!$jobs) return;
        $source = self::sourceConfiguration();
        $state = request()->upstreamCatalogBatch;
        $origin = self::sourceOrigin($source);
        $apiKey = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? ''));
        if ($origin === '' || $apiKey === '') throw new Exception('请先配置接口渠道');
        $budget = $metadata ? max(0, $state['deadline'] - microtime(true)) : 15;
        $results = UpstreamCatalogReadBatch::run($jobs, static function (array $job, float $remaining) use ($source, $origin, $apiKey, $metadata) {
            [$method, $path, $params] = $job;
            $url = $origin.$path;
            $headers = ['Authorization: Bearer '.$apiKey, 'Accept: application/json'];
            $handle = curl_init();
            curl_setopt_array($handle, [
                CURLOPT_URL => $method === 'GET' && $params ? $url.'?'.http_build_query($params) : $url,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_ENCODING => '',
                CURLOPT_CONNECTTIMEOUT_MS => min(5000, max(1, (int)ceil($remaining * 1000))),
                CURLOPT_TIMEOUT_MS => max(1, (int)ceil(min($metadata ? 5 : 15, $remaining) * 1000)),
                CURLOPT_SSL_VERIFYPEER => UpdateSourceClient::sslVerify($source),
                CURLOPT_SSL_VERIFYHOST => UpdateSourceClient::sslVerify($source) ? 2 : 0,
            ]);
            if ($method === 'POST') {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($handle, CURLOPT_POST, true);
                curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
            return $handle;
        }, static fn(string $body, int $status): array => self::decodeRead($body, $status), $budget);
        $state['responses'] = array_replace($state['responses'], $results);
        request()->upstreamCatalogBatch = $state;
    }

    private static function normalizeItem(array $row, array $request = []): array
    {
        $row = self::sanitize($row);
        return [
            'available' => (bool)($row['available'] ?? false),
            'type' => (string)($row['type'] ?? 'model'),
            'resource' => is_array($row['resource'] ?? null) ? $row['resource'] : [],
            'pricing' => is_array($row['pricing'] ?? null) ? $row['pricing'] : [],
            'pricing_v2' => is_array($row['pricing_v2'] ?? null) ? $row['pricing_v2'] : [],
            'price_view' => is_array($row['price_view'] ?? null) ? $row['price_view'] : [],
            'pricing_source' => is_array($row['pricing_source'] ?? null) ? $row['pricing_source'] : [],
            'error_code' => (string)($row['error_code'] ?? ''),
            'message' => (string)($row['message'] ?? $row['msg'] ?? ''),
            'raw' => $row,
            'requested_at' => date('Y-m-d H:i:s'),
            'source_base_url' => self::sourceOrigin(),
        ];
    }

    private static function sourceConfiguration(): array
    {
        $state = function_exists('request') ? request()->upstreamCatalogBatch : null;
        if (!is_array($state)) return UpdateSourceClient::getSource();
        if (array_key_exists('source', $state)) return $state['source'];
        $source = UpdateSourceClient::getSource();
        $state['source'] = $source;
        request()->upstreamCatalogBatch = $state;
        return $source;
    }

    private static function sourceOrigin(array $source = []): string
    {
        $source = $source ?: self::sourceConfiguration();
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
            $keyName = strtolower((string)$key);
            // SKU/local keys identify a public billable specification. They must survive
            // sanitization so distinct upstream prices cannot collapse into one SKU.
            $isPublicIdentifier = in_array($keyName, [
                'sku_key', 'local_key', 'max_tokens', 'prompt_tokens', 'completion_tokens',
                'total_tokens', 'input_tokens', 'output_tokens', 'token_usage',
            ], true);
            if (!$isPublicIdentifier && preg_match('/^(?:api_?key|access_?token|refresh_?token|id_?token|authorization|secret|api_?secret|client_?secret|private_?key|password|token|key)$/i', $keyName)) {
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
