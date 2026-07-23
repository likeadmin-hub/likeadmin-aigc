<?php

namespace app\common\service\power;

use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionEvent;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\model\power\TenantPowerMarketSkuPrice;
use app\common\service\ai\AiTaskJobService;
use app\common\service\app\aigc_music\AigcMusicAssetService;
use app\common\service\point\PointService;
use app\common\service\update\UpdateSourceClient;
use Exception;
use think\facade\Db;

/** Executes the music_generation application API sold in the power market. */
class MarketMusicAppRuntimeService
{
    public const APP_CODE = 'aigc_short_drama';
    private const UPSTREAM_APP_CODE = 'music_generation';
    private const CREATE_API_CODE = 'create';
    private const GENERATE_TYPE = 'generate';
    private const MAX_RUNNING_SECONDS = 7200;

    public static function availability(int $tenantId): array
    {
        try {
            $market = self::resolve($tenantId, []);
            return [
                'resource_type' => 'app_api',
                'resource_type_label' => '应用 API',
                'name' => '音乐生成应用 API',
                'required_for' => '用于短剧背景音乐生成',
                'available' => true,
                'channel_ready' => true,
                'ready' => true,
                'market_product_id' => (int)$market['product']['id'],
                'market_sku_id' => (int)$market['sku']['id'],
                'message' => '可用',
            ];
        } catch (\Throwable $e) {
            return [
                'resource_type' => 'app_api',
                'resource_type_label' => '应用 API',
                'name' => '音乐生成应用 API',
                'required_for' => '用于短剧背景音乐生成',
                'available' => false,
                'channel_ready' => false,
                'ready' => false,
                'market_product_id' => 0,
                'market_sku_id' => 0,
                'message' => $e->getMessage(),
            ];
        }
    }

    public static function quote(int $tenantId, array $selection = []): array
    {
        $market = self::resolve($tenantId, $selection);
        $deferredUsage = MarketUsageSettlementService::isActualUsageSku($market['sku']);
        return [
            'billing_unit' => (string)$market['sku']['usage_unit'],
            'billing_unit_size' => MarketUsageSettlementService::unitSize($market['sku']),
            'quantity' => $deferredUsage ? 0 : 1,
            'tenant_unit_points' => self::points((float)$market['sku']['sale_points']),
            'user_unit_points' => self::points((float)$market['tenant_price']),
            'tenant_cost_points' => $deferredUsage ? 0 : self::points((float)$market['sku']['sale_points']),
            'user_charge_points' => $deferredUsage ? 0 : self::points((float)$market['tenant_price']),
            'settlement_mode' => $deferredUsage ? 'actual_usage' : 'reserved',
            'price_source' => 'power_market_app_api',
            'market_product_id' => (int)$market['product']['id'],
            'market_sku_id' => (int)$market['sku']['id'],
            'market_snapshot' => self::snapshot($market),
        ];
    }

    /** @return array{app_task_id:int,consumption_id:int,consume_no:string,market_snapshot:array<string,mixed>} */
    public static function reserve(int $tenantId, int $userId, string $businessTaskId, array $selection, array $request): array
    {
        $market = self::resolve($tenantId, $selection);
        $deferredUsage = MarketUsageSettlementService::isActualUsageSku($market['sku']);
        $tenantCost = $deferredUsage ? 0 : self::points((float)$market['sku']['sale_points']);
        $userPrice = $deferredUsage ? 0 : self::points((float)$market['tenant_price']);
        if (!$deferredUsage) PointService::assertCanConsumeAmounts($tenantId, $userId, $tenantCost, $userPrice);

        return Db::transaction(function () use ($tenantId, $userId, $businessTaskId, $request, $market, $tenantCost, $userPrice, $deferredUsage) {
            $now = time();
            $appTask = AiAppTask::create([
                'task_no' => self::no('AT'), 'tenant_id' => $tenantId, 'user_id' => $userId,
                'app_code' => self::APP_CODE, 'action_code' => 'bgm_audio',
                'business_table' => 'aigc_short_drama_generation_task', 'business_id' => 0, 'parent_task_id' => 0,
                'status' => 'running', 'progress' => 10,
                'request_summary' => self::requestSummary($request), 'result_summary' => [],
                'estimated_tenant_cost' => $tenantCost, 'estimated_user_price' => $userPrice,
                'actual_tenant_cost' => 0, 'actual_user_price' => 0,
                'idempotency_key' => sha1($tenantId . '|' . $businessTaskId . '|music'),
                'create_time' => $now, 'update_time' => $now, 'finish_time' => 0,
            ]);
            $consumeNo = self::no('C');
            $consumption = AiConsumptionLog::create([
                'consume_no' => $consumeNo, 'app_task_id' => (int)$appTask['id'], 'tenant_id' => $tenantId, 'user_id' => $userId,
                'app_code' => self::APP_CODE, 'action_code' => 'bgm_audio', 'resource_type' => 'app_api',
                'product_id' => (int)$market['product']['id'], 'sku_id' => (int)$market['sku']['id'],
                'model_code' => '', 'api_code' => self::CREATE_API_CODE, 'protocol' => 'application_api', 'provider' => 'power_market',
                'upstream_request_id' => '', 'upstream_task_id' => '', 'quantity' => $deferredUsage ? 0 : 1, 'usage_unit' => (string)$market['sku']['usage_unit'],
                'usage_snapshot' => ['settlement_basis' => $deferredUsage ? 'awaiting_actual_usage' : 'submit_snapshot'], 'price_snapshot' => self::snapshot($market), 'request_summary' => self::requestSummary($request), 'response_summary' => [],
                'run_status' => 'reserved', 'billing_status' => $deferredUsage ? 'pending_usage' : 'reserved',
                'reserved_tenant_cost' => $tenantCost, 'reserved_user_price' => $userPrice, 'actual_tenant_cost' => 0, 'actual_user_price' => 0,
                'tenant_point_sn' => $consumeNo . '-reserve', 'user_point_sn' => $consumeNo . '-reserve',
                'error_code' => '', 'error_message' => '', 'refresh_requested_at' => 0,
                'create_time' => $now, 'update_time' => $now, 'finish_time' => 0,
            ]);
            if (!$deferredUsage) PointService::reserveBusinessAmountsInCurrentTransaction($tenantId, $userId, $tenantCost, $userPrice, $consumeNo . '-reserve', '短剧背景音乐预占', self::extra($appTask, $consumption, 'reserved'));
            self::event((int)$consumption['id'], 'reserve', 'success', ['settlement_mode' => $deferredUsage ? 'actual_usage' : 'reserved']);
            return ['app_task_id' => (int)$appTask['id'], 'consumption_id' => (int)$consumption['id'], 'consume_no' => $consumeNo, 'market_snapshot' => self::snapshot($market)];
        });
    }

    public static function linkBusinessTask(int $appTaskId, int $businessId): void
    {
        if ($appTaskId > 0 && $businessId > 0) {
            AiAppTask::where('id', $appTaskId)->update(['business_id' => $businessId, 'update_time' => time()]);
        }
    }

    public static function submit(int $consumptionId, array $request): array
    {
        $context = self::context($consumptionId, false);
        if ($context === null) throw new Exception('市场音乐消耗记录不存在');
        $consumption = $context['consumption'];
        if ((string)$consumption['run_status'] !== 'reserved') return self::response($consumption->toArray());
        $snapshot = self::arrayValue($consumption['price_snapshot'] ?? []);
        try {
            $response = self::request('POST', self::endpoint((string)$snapshot['app_code'], self::CREATE_API_CODE), self::payload($snapshot, $request, (string)$consumption['consume_no']));
            $taskId = self::taskId($response);
            if ($taskId === '') throw new Exception('音乐生成应用未返回任务 ID');
            $requestId = self::requestId($response);
            Db::transaction(function () use ($consumptionId, $taskId, $requestId) {
                $ctx = self::context($consumptionId, true); if ($ctx === null) return;
                $c = $ctx['consumption']; if (!in_array((string)$c['billing_status'], ['reserved', 'pending_usage'], true)) return;
                $c->save(['run_status' => 'running', 'upstream_task_id' => $taskId, 'upstream_request_id' => $requestId, 'update_time' => time()]);
                self::event((int)$c['id'], 'submit', 'success', ['upstream_task_id' => $taskId]);
            });
            AiTaskJobService::enqueueQueryResult($consumptionId);
            return ['status' => 'running', 'provider_task_id' => $taskId, 'items' => []];
        } catch (\Throwable $e) {
            self::fail($consumptionId, $e->getMessage(), 'submit_failed');
            throw $e instanceof Exception ? $e : new Exception('音乐生成应用提交失败');
        }
    }

    public static function refresh(int $consumptionId): array
    {
        $context = self::context($consumptionId, false);
        if ($context === null) throw new Exception('市场音乐消耗记录不存在');
        $consumption = $context['consumption'];
        if (!in_array((string)$consumption['billing_status'], ['reserved', 'pending_usage'], true)) return self::response($consumption->toArray());
        // Model access returns a formatted date for timestamps. Read the raw
        // field so new tasks are not treated as if they were created in year 2026.
        $createdAt = (int)$consumption->getData('create_time');
        $timedOut = $createdAt > 0 && time() - $createdAt >= self::MAX_RUNNING_SECONDS;
        $taskId = trim((string)$consumption['upstream_task_id']);
        if ($taskId === '') {
            if ($timedOut) {
                self::fail($consumptionId, '音乐任务未返回上游任务号', 'timeout');
                return ['status' => 'failed', 'provider_task_id' => '', 'items' => []];
            }
            return self::response($consumption->toArray());
        }
        try {
            $snapshot = self::arrayValue($consumption['price_snapshot'] ?? []);
            $response = self::request('GET', self::endpoint((string)$snapshot['app_code'], 'query') . '?task_id=' . rawurlencode($taskId));
            $items = self::items($response, (int)$consumption['tenant_id'], (int)$consumption['user_id']);
            if ($items !== []) {
                self::settle($consumptionId, $items, self::requestId($response), $taskId, $response);
                return ['status' => 'success', 'provider_task_id' => $taskId, 'items' => $items];
            }
            if (in_array(self::status($response), ['failed', 'error', 'canceled', 'cancelled'], true)) {
                self::fail($consumptionId, self::error($response), 'upstream_failed');
                return ['status' => 'failed', 'provider_task_id' => $taskId, 'items' => []];
            }
            if ($timedOut) { self::fail($consumptionId, '音乐任务处理超时', 'timeout'); return ['status' => 'failed', 'provider_task_id' => $taskId, 'items' => []]; }
            return ['status' => 'running', 'provider_task_id' => $taskId, 'items' => []];
        } catch (\Throwable) {
            if ($timedOut) { self::fail($consumptionId, '音乐任务处理超时', 'timeout'); return ['status' => 'failed', 'provider_task_id' => $taskId, 'items' => []]; }
            return ['status' => 'running', 'provider_task_id' => $taskId, 'items' => []];
        }
    }

    public static function cancel(int $consumptionId): void
    {
        $context = self::context($consumptionId, false);
        if ($context === null) throw new Exception('市场音乐消耗记录不存在');
        $consumption = $context['consumption'];
        if (!in_array((string)$consumption['billing_status'], ['reserved', 'pending_usage'], true)) return;
        if ((string)$consumption['run_status'] !== 'reserved') throw new Exception('背景音乐任务已提交至应用 API，无法取消');
        self::fail($consumptionId, '用户取消任务', 'canceled');
    }

    public static function fail(int $consumptionId, string $message, string $code = 'failed'): void
    {
        Db::transaction(function () use ($consumptionId, $message, $code) {
            $ctx = self::context($consumptionId, true); if ($ctx === null) return;
            $c = $ctx['consumption']; $task = $ctx['app_task'];
            if (in_array((string)$c['billing_status'], ['settled', 'refunded'], true)) return;
            if ((float)$c['reserved_tenant_cost'] > 0 || (float)$c['reserved_user_price'] > 0) PointService::releaseReservedBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], (float)$c['reserved_tenant_cost'], (float)$c['reserved_user_price'], (string)$c['consume_no'] . '-release', '短剧背景音乐失败退回', self::extra($task, $c, 'refunded'));
            $now = time();
            $c->save(['run_status' => $code === 'canceled' ? 'canceled' : 'failed', 'billing_status' => 'refunded', 'error_code' => $code, 'error_message' => mb_substr($message, 0, 1000), 'finish_time' => $now, 'update_time' => $now]);
            $task->save(['status' => $code === 'canceled' ? 'canceled' : 'failed', 'progress' => 100, 'result_summary' => ['error' => mb_substr($message, 0, 500)], 'finish_time' => $now, 'update_time' => $now]);
            self::event((int)$c['id'], $code === 'canceled' ? 'cancel' : 'refund', 'success', ['reason' => $message]);
        });
    }

    private static function settle(int $consumptionId, array $items, string $requestId, string $taskId, array $response): void
    {
        Db::transaction(function () use ($consumptionId, $items, $requestId, $taskId, $response) {
            $ctx = self::context($consumptionId, true); if ($ctx === null) return;
            $c = $ctx['consumption']; $task = $ctx['app_task'];
            if (!in_array((string)$c['billing_status'], ['reserved', 'pending_usage'], true)) return;
            $snapshot = self::arrayValue($c['price_snapshot'] ?? []);
            $deferredUsage = MarketUsageSettlementService::isActualUsageSku($snapshot); $actualUsage = MarketUsageSettlementService::tokenUsage($response);
            if ($deferredUsage && $actualUsage <= 0) { $now = time(); $c->save(['run_status' => 'success', 'billing_status' => 'pending_usage', 'upstream_request_id' => $requestId ?: (string)$c['upstream_request_id'], 'upstream_task_id' => $taskId, 'usage_snapshot' => ['settlement_basis' => 'awaiting_actual_usage'], 'response_summary' => ['audio_count' => count($items), 'items' => $items], 'finish_time' => $now, 'update_time' => $now]); $task->save(['status' => 'success', 'progress' => 100, 'finish_time' => $now, 'update_time' => $now]); self::event((int)$c['id'], 'await_usage', 'pending', ['audio_count' => count($items)]); return; }
            $billedQuantity = $deferredUsage ? $actualUsage : 1; $tenant = $deferredUsage ? MarketUsageSettlementService::price((float)($snapshot['platform_price'] ?? 0), $billedQuantity, $snapshot) : self::points((float)($snapshot['platform_price'] ?? 0)); $user = $deferredUsage ? MarketUsageSettlementService::price((float)($snapshot['tenant_price'] ?? 0), $billedQuantity, $snapshot) : self::points((float)($snapshot['tenant_price'] ?? 0));
            if ($deferredUsage) { PointService::assertCanConsumeAmounts((int)$c['tenant_id'], (int)$c['user_id'], $tenant, $user); PointService::consumeBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], $tenant, $user, (string)$c['consume_no'], '短剧背景音乐实际用量结算', self::extra($task, $c, 'settled')); } else PointService::settleReservedBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], (float)$c['reserved_tenant_cost'], (float)$c['reserved_user_price'], $tenant, $user, (string)$c['consume_no'], '短剧背景音乐结算', self::extra($task, $c, 'settled'));
            $now = time();
            $c->save(['run_status' => 'success', 'billing_status' => 'settled', 'upstream_request_id' => $requestId ?: (string)$c['upstream_request_id'], 'upstream_task_id' => $taskId, 'quantity' => $billedQuantity, 'usage_snapshot' => ['audio_count' => count($items), 'actual_token_usage' => $deferredUsage ? $actualUsage : 0], 'response_summary' => ['audio_count' => count($items), 'items' => $items], 'actual_tenant_cost' => $tenant, 'actual_user_price' => $user, 'tenant_point_sn' => $deferredUsage ? (string)$c['consume_no'] : (string)$c['consume_no'] . '-reserve', 'user_point_sn' => $deferredUsage ? (string)$c['consume_no'] : (string)$c['consume_no'] . '-reserve', 'finish_time' => $now, 'update_time' => $now]);
            $task->save(['status' => 'success', 'progress' => 100, 'actual_tenant_cost' => $tenant, 'actual_user_price' => $user, 'finish_time' => $now, 'update_time' => $now]);
            self::event((int)$c['id'], 'settle', 'success', ['audio_count' => count($items)]);
        });
    }

    private static function resolve(int $tenantId, array $selection): array
    {
        $skuId = (int)($selection['market_sku_id'] ?? $selection['sku_id'] ?? 0);
        $products = PowerMarketProduct::where(['resource_type' => PowerMarketService::TYPE_APP_API, 'upstream_app_code' => self::UPSTREAM_APP_CODE, 'upstream_api_code' => self::CREATE_API_CODE, 'status' => 1])->order('id', 'asc')->select()->toArray();
        foreach ($products as $product) {
            $skus = PowerMarketSku::where(['product_id' => (int)$product['id'], 'status' => 1, 'sale_status' => 1])->order('id', 'asc')->select()->toArray();
            foreach ($skus as $sku) {
                $locked = self::arrayValue($sku['locked_params'] ?? []);
                if (($skuId > 0 && (int)$sku['id'] !== $skuId) || ($skuId <= 0 && (string)($locked['type'] ?? '') !== self::GENERATE_TYPE)) continue;
                $tenant = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => (int)$sku['id']])->findOrEmpty();
                if (!$tenant->isEmpty() && (int)$tenant['sale_status'] !== 1) continue;
                return ['product' => $product, 'sku' => $sku, 'tenant_price' => $tenant->isEmpty() ? (float)$sku['sale_points'] : (float)$tenant['sale_points']];
            }
        }
        throw new Exception('暂无租户已上架的音乐生成应用 API');
    }

    private static function payload(array $snapshot, array $request, string $idempotencyKey): array
    {
        $payload = array_merge(self::arrayValue($snapshot['locked_params'] ?? []), [
            'title' => trim((string)($request['title'] ?? '短剧背景音乐')),
            'prompt' => trim((string)($request['prompt'] ?? '')),
            'lyrics' => '', 'instrumental' => true, 'custom' => false,
            'genre' => trim((string)($request['genre'] ?? '')), 'mood' => trim((string)($request['mood'] ?? '')),
            'instruments' => trim((string)($request['instruments'] ?? '')), 'duration' => max(5, min(600, (int)($request['duration'] ?? 60))),
            'idempotency_key' => $idempotencyKey,
        ]);
        return array_filter($payload, static fn($value) => $value !== '' && $value !== null && $value !== []);
    }

    private static function items(array $data, int $tenantId, int $userId): array
    {
        $root = self::arrayValue($data['data'] ?? $data);
        $candidates = [$root['results'] ?? [], $root['audios'] ?? [], $root['data'] ?? [], $root['result']['results'] ?? [], $root['result']['data'] ?? []];
        $urls = [];
        foreach ($candidates as $rows) foreach ((array)$rows as $row) { $url = is_string($row) ? $row : (string)($row['audio_url'] ?? $row['file_url'] ?? $row['url'] ?? $row['audio'] ?? ''); if ($url !== '') $urls[] = $url; }
        $items = [];
        foreach (array_values(array_unique($urls)) as $url) {
            try {
                $stored = AigcMusicAssetService::persistGeneratedAudio($url, $tenantId, $userId);
                $items[] = ['audio_uri' => (string)$stored['uri'], 'audio_url' => (string)($stored['url'] ?? ''), 'storage_scope' => (string)($stored['storage_scope'] ?? 'tenant'), 'storage_engine' => (string)($stored['storage_engine'] ?? ''), 'storage_domain' => (string)($stored['storage_domain'] ?? ''), 'mime_type' => (string)($stored['mime_type'] ?? ''), 'file_size' => (int)($stored['file_size'] ?? 0), 'duration' => (float)($stored['duration'] ?? 0)];
                break;
            } catch (\Throwable) {
                // Providers may return an expired secondary preview URL alongside
                // the durable generated file. Keep every result that persisted.
            }
        }
        return $items;
    }

    private static function endpoint(string $appCode, string $apiCode): string { return self::origin() . '/api/v1/apps/' . rawurlencode($appCode ?: self::UPSTREAM_APP_CODE) . '/' . rawurlencode($apiCode); }
    private static function origin(): string { $source = UpdateSourceClient::getSource(); $parts = parse_url(trim((string)($source['active_base_url'] ?? $source['base_url'] ?? ''))); $host = (string)($parts['host'] ?? ''); if ($host === '') throw new Exception('音乐生成应用 API 暂不可用'); return (string)($parts['scheme'] ?? 'https') . '://' . $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : ''); }
    private static function request(string $method, string $url, array $payload = []): array { $source = UpdateSourceClient::getSource(); $key = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? '')); if ($key === '') throw new Exception('音乐生成应用 API 暂不可用'); $ch = curl_init(); curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Accept: application/json', 'Content-Type: application/json'], CURLOPT_SSL_VERIFYPEER => UpdateSourceClient::sslVerify($source), CURLOPT_SSL_VERIFYHOST => UpdateSourceClient::sslVerify($source) ? 2 : 0]); if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); } $body = curl_exec($ch); $errno = curl_errno($ch); $error = curl_error($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); if ($errno) throw new Exception($error ?: '音乐生成应用网络请求失败'); $data = json_decode((string)$body, true); if (!is_array($data)) throw new Exception('音乐生成应用响应格式错误'); if ($http >= 400 || isset($data['error']) || (isset($data['code']) && (int)$data['code'] !== 1)) throw new Exception(self::error($data)); return $data; }
    private static function snapshot(array $market): array { $product = $market['product']; $sku = $market['sku']; return ['product_id' => (int)$product['id'], 'sku_id' => (int)$sku['id'], 'sku_key' => (string)$sku['sku_key'], 'app_code' => (string)$product['upstream_app_code'], 'api_code' => (string)$product['upstream_api_code'], 'locked_params' => self::arrayValue($sku['locked_params'] ?? []), 'usage_unit' => (string)$sku['usage_unit'], 'usage_unit_size' => MarketUsageSettlementService::unitSize($sku), 'upstream_price' => (float)$sku['upstream_price'], 'platform_price' => (float)$sku['sale_points'], 'tenant_price' => (float)$market['tenant_price']]; }
    private static function requestSummary(array $request): array { return ['prompt_length' => mb_strlen((string)($request['prompt'] ?? '')), 'duration' => (int)($request['duration'] ?? 0), 'instrumental' => true]; }
    private static function taskId(array $data): string { $root = self::arrayValue($data['data'] ?? $data); foreach ([$data['task_id'] ?? null, $data['id'] ?? null, $root['task_id'] ?? null, $root['id'] ?? null, $root['result']['task_id'] ?? null] as $value) if (is_scalar($value) && (string)$value !== '') return (string)$value; return ''; }
    private static function requestId(array $data): string { $root = self::arrayValue($data['data'] ?? $data); return (string)($data['request_id'] ?? $root['request_id'] ?? ''); }
    private static function status(array $data): string { $root = self::arrayValue($data['data'] ?? $data); return strtolower((string)($data['status'] ?? $root['status'] ?? $root['state'] ?? $root['result']['status'] ?? '')); }
    private static function error(array $data): string { $root = self::arrayValue($data['data'] ?? $data); return mb_substr((string)($data['message'] ?? $data['msg'] ?? $root['message'] ?? $root['msg'] ?? $data['error']['message'] ?? '音乐生成应用调用失败'), 0, 1000); }
    private static function response(array $consumption): array { $summary = self::arrayValue($consumption['response_summary'] ?? []); return ['status' => (string)$consumption['run_status'], 'provider_task_id' => (string)$consumption['upstream_task_id'], 'items' => (array)($summary['items'] ?? [])]; }
    private static function context(int $consumptionId, bool $lock): ?array { $query = AiConsumptionLog::where('id', $consumptionId); if ($lock) $query->lock(true); $consumption = $query->findOrEmpty(); if ($consumption->isEmpty()) return null; $taskQuery = AiAppTask::where('id', (int)$consumption['app_task_id']); if ($lock) $taskQuery->lock(true); $task = $taskQuery->findOrEmpty(); return $task->isEmpty() ? null : ['consumption' => $consumption, 'app_task' => $task]; }
    private static function event(int $consumptionId, string $type, string $status, array $summary): void { AiConsumptionEvent::create(['consumption_id' => $consumptionId, 'event_type' => $type, 'event_status' => $status, 'attempt_no' => 1, 'payload_summary' => $summary, 'payload_ciphertext' => '', 'http_status' => 0, 'elapsed_ms' => 0, 'create_time' => time()]); }
    private static function extra(AiAppTask $task, AiConsumptionLog $consumption, string $stage): array { return ['app_code' => self::APP_CODE, 'app_task_id' => (int)$task['id'], 'app_task_no' => (string)$task['task_no'], 'consumption_id' => (int)$consumption['id'], 'consume_no' => (string)$consumption['consume_no'], 'billing_stage' => $stage]; }
    private static function arrayValue($value): array { if (is_array($value)) return $value; if (is_string($value) && $value !== '') { $decoded = json_decode($value, true); return is_array($decoded) ? $decoded : []; } return []; }
    private static function points(float $value): float { return round(max(0, $value), 6); }
    private static function no(string $prefix): string { return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(5))); }
}
