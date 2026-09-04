<?php

namespace app\common\service\power;

use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionEvent;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\model\power\TenantPowerMarketSkuPrice;
use app\common\service\ai\AiTaskJobService;
use app\common\service\ai\AiTaskLifecycleEventService;
use app\common\service\ai\AiTaskResultUrlService;
use app\common\service\ai\UpstreamErrorMessageService;
use app\common\service\app\aigc_video\AigcVideoAssetService;
use app\common\service\point\PointService;
use app\common\service\update\UpdateSourceClient;
use Exception;
use think\facade\Db;

/** Runtime adapter for the short-video watermark-removal application API. */
class MarketGenericImageAppRuntimeService
{
    public const APP_CODE = 'aigc_watermark_removal';
    public const UPSTREAM_APP_CODE = 'watermark_removal';
    public const UPSTREAM_API_CODE = 'remove';
    private const MAX_RUNNING_SECONDS = 7200;

    public static function options(int $tenantId, string $upstreamAppCode = ''): array
    {
        $query = PowerMarketProduct::where(['resource_type' => PowerMarketService::TYPE_APP_API, 'status' => 1]);
        $query->where('upstream_app_code', $upstreamAppCode !== '' ? $upstreamAppCode : self::UPSTREAM_APP_CODE);
        $products = $query->order('id', 'asc')->select()->toArray();
        TenantPowerMarketService::applyProductDisplays($tenantId, $products);
        $options = [];
        foreach ($products as $product) {
            if (strtolower((string)$product['upstream_app_code']) !== self::UPSTREAM_APP_CODE
                || strtolower((string)$product['upstream_api_code']) !== self::UPSTREAM_API_CODE) continue;
            $metadata = self::metadata($product);
            foreach (PowerMarketSku::where(['product_id' => (int)$product['id'], 'status' => 1, 'sale_status' => 1])->order('id', 'asc')->select()->toArray() as $sku) {
                $tenant = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => (int)$sku['id']])->findOrEmpty();
                if (!$tenant->isEmpty() && (int)$tenant['sale_status'] !== 1) continue;
                $locked = self::arrayValue($sku['locked_params'] ?? []);
                $options[] = [
                    'id' => self::selectionId((int)$product['id'], (int)$sku['id']),
                    'value' => self::selectionId((int)$product['id'], (int)$sku['id']),
                    'market_product_id' => (int)$product['id'],
                    'market_sku_id' => (int)$sku['id'],
                    'sku_id' => (int)$sku['id'],
                    'sku_key' => (string)$sku['sku_key'],
                    'resource_type' => PowerMarketService::TYPE_APP_API,
                    'resource_type_label' => '应用 API',
                    'category_code' => 'video',
                    'category_name' => '短视频处理',
                    'app_code' => (string)$product['upstream_app_code'],
                    'api_code' => (string)$product['upstream_api_code'],
                    'name' => (string)($sku['title'] ?: $product['name']),
                    'description' => (string)($product['description'] ?? ''),
                    'display_icon' => (string)($product['display_icon'] ?? ''),
                    'locked_params' => $locked,
                    'selectable_params' => self::arrayValue($sku['selectable_params'] ?? []),
                    'params_schema' => self::arrayValue($metadata['params_schema'] ?? []),
                    'default_params' => self::arrayValue($metadata['default_params'] ?? []),
                    'capabilities' => self::arrayValue($metadata['capabilities'] ?? []),
                    'query_path' => (string)($metadata['query_path'] ?? $metadata['capabilities']['query_path'] ?? ''),
                    'platform_unit_cost' => self::points((float)$sku['sale_points']),
                    'tenant_unit_price' => self::points($tenant->isEmpty() ? (float)$sku['sale_points'] : (float)$tenant['sale_points']),
                    'usage_unit' => (string)$sku['usage_unit'],
                    'usage_unit_size' => MarketUsageSettlementService::unitSize($sku),
                    'settlement_mode' => MarketUsageSettlementService::isActualUsageSku($sku) ? 'actual_usage' : 'reserved',
                    'enabled' => true,
                    'available' => true,
                    'status' => 1,
                    'sort' => (int)$sku['id'],
                ];
            }
        }
        return $options;
    }

    public static function isSelection(array $selection): bool
    {
        $value = (string)($selection['id'] ?? $selection['value'] ?? $selection['channel'] ?? $selection['channel_code'] ?? '');
        return str_starts_with($value, 'market_watermark_app:') || ((int)($selection['market_product_id'] ?? 0) > 0 && (int)($selection['market_sku_id'] ?? $selection['sku_id'] ?? 0) > 0);
    }

    public static function quote(int $tenantId, array $selection, int $quantity = 1): array
    {
        $market = self::resolve($tenantId, $selection);
        $quantity = max(1, $quantity);
        $deferred = MarketUsageSettlementService::isActualUsageSku($market['sku']);
        return [
            'billing_unit' => (string)$market['sku']['usage_unit'], 'billing_unit_size' => MarketUsageSettlementService::unitSize($market['sku']),
            'quantity' => $deferred ? 0 : $quantity, 'tenant_unit_points' => self::points((float)$market['sku']['sale_points']), 'user_unit_points' => self::points((float)$market['tenant_price']),
            'tenant_cost_points' => $deferred ? 0 : self::points((float)$market['sku']['sale_points'] * $quantity), 'user_charge_points' => $deferred ? 0 : self::points((float)$market['tenant_price'] * $quantity),
            'settlement_mode' => $deferred ? 'actual_usage' : 'reserved', 'price_source' => 'power_market_app_api', 'market_product_id' => (int)$market['product']['id'], 'market_sku_id' => (int)$market['sku']['id'], 'market_snapshot' => self::snapshot($market),
        ];
    }

    public static function reserve(int $tenantId, int $userId, string $action, string $businessTaskId, array $selection, array $request, int $quantity = 1, string $appCode = self::APP_CODE, string $businessTable = 'aigc_watermark_removal_task'): array
    {
        $market = self::resolve($tenantId, $selection);
        $quantity = max(1, $quantity);
        $deferred = MarketUsageSettlementService::isActualUsageSku($market['sku']);
        $tenantCost = $deferred ? 0 : self::points((float)$market['sku']['sale_points'] * $quantity);
        $userPrice = $deferred ? 0 : self::points((float)$market['tenant_price'] * $quantity);
        if (!$deferred) PointService::assertCanConsumeAmounts($tenantId, $userId, $tenantCost, $userPrice);
        $key = sha1($tenantId . '|' . $appCode . '|' . $action . '|' . $businessTaskId . '|watermark_removal');
        $existing = self::existingReservation($tenantId, $key);
        if ($existing !== null) return $existing;
        return Db::transaction(function () use ($tenantId, $userId, $action, $businessTaskId, $selection, $request, $quantity, $market, $deferred, $tenantCost, $userPrice, $appCode, $businessTable, $key) {
            $existing = self::existingReservation($tenantId, $key, true); if ($existing !== null) return $existing;
            $now = time();
            $appTask = AiAppTask::create(['task_no' => self::no('AT'), 'tenant_id' => $tenantId, 'user_id' => $userId, 'app_code' => $appCode, 'action_code' => $action, 'business_table' => $businessTable, 'business_id' => 0, 'parent_task_id' => 0, 'status' => 'running', 'progress' => 10, 'request_summary' => self::requestSummary($request), 'result_summary' => [], 'estimated_tenant_cost' => $tenantCost, 'estimated_user_price' => $userPrice, 'actual_tenant_cost' => 0, 'actual_user_price' => 0, 'idempotency_key' => $key, 'create_time' => $now, 'update_time' => $now, 'finish_time' => 0]);
            $consumeNo = self::no('C');
            $consumption = AiConsumptionLog::create(['consume_no' => $consumeNo, 'app_task_id' => (int)$appTask['id'], 'tenant_id' => $tenantId, 'user_id' => $userId, 'app_code' => $appCode, 'action_code' => $action, 'resource_type' => PowerMarketService::TYPE_APP_API, 'product_id' => (int)$market['product']['id'], 'sku_id' => (int)$market['sku']['id'], 'model_code' => '', 'api_code' => (string)$market['product']['upstream_api_code'], 'protocol' => 'application_api', 'provider' => 'power_market', 'upstream_request_id' => '', 'upstream_task_id' => '', 'quantity' => $deferred ? 0 : $quantity, 'usage_unit' => (string)$market['sku']['usage_unit'], 'usage_snapshot' => ['settlement_basis' => $deferred ? 'awaiting_actual_usage' : 'submit_snapshot'], 'price_snapshot' => self::snapshot($market), 'request_summary' => self::requestSummary($request), 'response_summary' => [], 'run_status' => 'reserved', 'billing_status' => $deferred ? 'pending_usage' : 'reserved', 'reserved_tenant_cost' => $tenantCost, 'reserved_user_price' => $userPrice, 'actual_tenant_cost' => 0, 'actual_user_price' => 0, 'tenant_point_sn' => $consumeNo . '-reserve', 'user_point_sn' => $consumeNo . '-reserve', 'error_code' => '', 'error_message' => '', 'refresh_requested_at' => 0, 'create_time' => $now, 'update_time' => $now, 'finish_time' => 0]);
            if (!$deferred) PointService::reserveBusinessAmountsInCurrentTransaction($tenantId, $userId, $tenantCost, $userPrice, $consumeNo . '-reserve', '短视频去水印预占', self::extra($appTask, $consumption, 'reserved'));
            self::event((int)$consumption['id'], 'reserve', 'success', ['quantity' => $quantity]);
            return ['app_task_id' => (int)$appTask['id'], 'consumption_id' => (int)$consumption['id'], 'consume_no' => $consumeNo, 'market_snapshot' => self::snapshot($market)];
        });
    }

    public static function linkBusinessTask(int $appTaskId, int $businessId): void { if ($appTaskId > 0 && $businessId > 0) AiAppTask::where('id', $appTaskId)->update(['business_id' => $businessId, 'update_time' => time()]); }

    public static function submit(int $consumptionId, array $request): array
    {
        $ctx = self::context($consumptionId, false); if ($ctx === null) throw new Exception('短视频去水印消耗记录不存在');
        $c = $ctx['consumption']; if ((string)$c['run_status'] !== 'reserved') return self::response($c->toArray());
        $snapshot = self::arrayValue($c['price_snapshot'] ?? []);
        try {
            $body = self::request('POST', self::endpoint($snapshot, false), self::payload($snapshot, $request, (string)$c['consume_no']));
            $assets = self::assets($body, (int)$c['tenant_id'], (int)$c['user_id'], [(string)($request['url'] ?? $request['source_url'] ?? '')]);
            $taskId = self::taskId($body);
            $requestId = self::requestId($body);
            Db::transaction(function () use ($consumptionId, $taskId, $requestId, $assets) { $ctx = self::context($consumptionId, true); if ($ctx === null) return; $c = $ctx['consumption']; $c->save(['run_status' => $assets === [] ? 'running' : 'success', 'upstream_task_id' => $taskId, 'upstream_request_id' => $requestId, 'update_time' => time()]); self::event((int)$c['id'], 'submit', 'success', ['upstream_task_id' => $taskId, 'video_count' => count($assets)]); });
            if ($assets !== []) { self::settle($consumptionId, $assets, $requestId, $taskId, $body); AiTaskJobService::enqueueProcessResult($consumptionId); return ['status' => 'success', 'provider_task_id' => $taskId, 'videos' => $assets]; }
            if ($taskId === '') throw new Exception('短视频去水印接口未返回任务 ID 或视频地址');
            AiTaskJobService::enqueueQueryResult($consumptionId);
            return ['status' => 'running', 'provider_task_id' => $taskId, 'videos' => []];
        } catch (\Throwable $e) { self::fail($consumptionId, $e->getMessage(), 'submit_failed'); throw $e instanceof Exception ? $e : new Exception('去水印任务提交失败'); }
    }

    public static function refresh(int $consumptionId): array
    {
        $ctx = self::context($consumptionId, false); if ($ctx === null) throw new Exception('短视频去水印消耗记录不存在');
        $c = $ctx['consumption']; if (in_array((string)$c['billing_status'], ['settled', 'refunded'], true)) return self::response($c->toArray());
        $taskId = trim((string)$c['upstream_task_id']); $snapshot = self::arrayValue($c['price_snapshot'] ?? []);
        $created = self::timestamp($c['create_time'] ?? 0); $timedOut = $created > 0 && time() - $created >= self::MAX_RUNNING_SECONDS;
        if ($taskId === '') { if ($timedOut) { self::fail($consumptionId, '去水印任务超时', 'timeout'); return self::failureResponse('', '去水印任务超时'); } return self::response($c->toArray()); }
        try {
            $body = self::request((string)($snapshot['query_method'] ?? 'GET'), self::endpoint($snapshot, true, $taskId), (string)($snapshot['query_method'] ?? 'GET') === 'POST' ? ['task_id' => $taskId] : [], true);
            $requestSummary = self::arrayValue($c['request_summary'] ?? []);
            $images = self::assets($body, (int)$c['tenant_id'], (int)$c['user_id'], [(string)($requestSummary['url'] ?? '')]);
            $status = self::status($body);
            if ($images !== []) { self::settle($consumptionId, $images, self::requestId($body), $taskId, $body); return ['status' => 'success', 'provider_task_id' => $taskId, 'videos' => $images]; }
            if (in_array($status, ['failed', 'error', 'canceled', 'cancelled'], true)) { $message = self::error($body) ?: '去水印任务失败'; self::fail($consumptionId, $message, 'upstream_failed'); return self::failureResponse($taskId, $message); }
            if ($timedOut) { self::fail($consumptionId, '去水印任务处理超时', 'timeout'); return self::failureResponse($taskId, '去水印任务处理超时'); }
            return ['status' => 'running', 'provider_task_id' => $taskId, 'videos' => []];
        } catch (\Throwable $e) { if ($timedOut) { self::fail($consumptionId, $e->getMessage(), 'timeout'); return self::failureResponse($taskId, $e->getMessage()); } return ['status' => 'running', 'provider_task_id' => $taskId, 'videos' => []]; }
    }

    public static function cancel(int $consumptionId): void { self::fail($consumptionId, '用户取消任务', 'canceled'); }

    public static function fail(int $consumptionId, string $message, string $code = 'failed'): void
    {
        Db::transaction(function () use ($consumptionId, $message, $code) { $ctx = self::context($consumptionId, true); if ($ctx === null) return; $c = $ctx['consumption']; $task = $ctx['app_task']; if (in_array((string)$c['billing_status'], ['settled', 'refunded'], true)) return; if ((float)$c['reserved_tenant_cost'] > 0 || (float)$c['reserved_user_price'] > 0) PointService::releaseReservedBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], (float)$c['reserved_tenant_cost'], (float)$c['reserved_user_price'], (string)$c['consume_no'] . '-release', '去水印失败退回', self::extra($task, $c, 'refunded')); $now = time(); $c->save(['run_status' => $code === 'canceled' ? 'canceled' : 'failed', 'billing_status' => 'refunded', 'error_code' => $code, 'error_message' => mb_substr($message, 0, 1000), 'finish_time' => $now, 'update_time' => $now]); $task->save(['status' => $code === 'canceled' ? 'canceled' : 'failed', 'progress' => 100, 'result_summary' => ['error' => mb_substr($message, 0, 500)], 'finish_time' => $now, 'update_time' => $now]); self::event((int)$c['id'], $code === 'canceled' ? 'cancel' : 'refund', 'success', ['reason' => $message]); });
    }

    private static function settle(int $consumptionId, array $images, string $requestId, string $taskId, array $response): void
    {
        Db::transaction(function () use ($consumptionId, $images, $requestId, $taskId, $response) { $ctx = self::context($consumptionId, true); if ($ctx === null) return; $c = $ctx['consumption']; $task = $ctx['app_task']; if (in_array((string)$c['billing_status'], ['settled', 'refunded'], true)) return; $snapshot = self::arrayValue($c['price_snapshot'] ?? []); $deferred = MarketUsageSettlementService::isActualUsageSku($snapshot); $count = max(1, count($images)); $tenant = $deferred ? MarketUsageSettlementService::price((float)($snapshot['platform_price'] ?? 0), $count, $snapshot) : (float)$c['reserved_tenant_cost']; $user = $deferred ? MarketUsageSettlementService::price((float)($snapshot['tenant_price'] ?? 0), $count, $snapshot) : (float)$c['reserved_user_price']; if ($deferred) { PointService::assertCanConsumeAmounts((int)$c['tenant_id'], (int)$c['user_id'], $tenant, $user); PointService::consumeBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], $tenant, $user, (string)$c['consume_no'], '短视频去水印实际用量结算', self::extra($task, $c, 'settled')); } else PointService::settleReservedBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], (float)$c['reserved_tenant_cost'], (float)$c['reserved_user_price'], $tenant, $user, (string)$c['consume_no'], '短视频去水印结算', self::extra($task, $c, 'settled')); $now = time(); $c->save(['run_status' => 'success', 'billing_status' => 'settled', 'upstream_request_id' => $requestId ?: (string)$c['upstream_request_id'], 'upstream_task_id' => $taskId, 'quantity' => $count, 'response_summary' => ['video_count' => $count, 'videos' => $images], 'actual_tenant_cost' => $tenant, 'actual_user_price' => $user, 'finish_time' => $now, 'update_time' => $now]); $task->save(['status' => 'success', 'progress' => 100, 'actual_tenant_cost' => $tenant, 'actual_user_price' => $user, 'result_summary' => ['video_count' => $count, 'videos' => $images], 'finish_time' => $now, 'update_time' => $now]); self::event((int)$c['id'], 'settle', 'success', ['video_count' => $count]); });
    }

    private static function resolve(int $tenantId, array $selection): array
    {
        $productId = (int)($selection['market_product_id'] ?? 0); $skuId = (int)($selection['market_sku_id'] ?? $selection['sku_id'] ?? 0); $id = (string)($selection['id'] ?? $selection['value'] ?? $selection['channel'] ?? $selection['channel_code'] ?? '');
        if (($productId <= 0 || $skuId <= 0) && preg_match('/^market_watermark_app:(\d+):(\d+)$/', $id, $m)) { $productId = (int)$m[1]; $skuId = (int)$m[2]; }
        $product = PowerMarketProduct::where(['id' => $productId, 'resource_type' => PowerMarketService::TYPE_APP_API, 'upstream_app_code' => self::UPSTREAM_APP_CODE, 'upstream_api_code' => self::UPSTREAM_API_CODE, 'status' => 1])->findOrEmpty(); if ($product->isEmpty()) throw new Exception('所选短视频去水印应用 API 已下架');
        $sku = PowerMarketSku::where(['id' => $skuId, 'product_id' => $productId, 'status' => 1, 'sale_status' => 1])->findOrEmpty(); if ($sku->isEmpty()) throw new Exception('所选短视频去水印规格已下架');
        $tenant = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => $skuId])->findOrEmpty(); if (!$tenant->isEmpty() && (int)$tenant['sale_status'] !== 1) throw new Exception('所选短视频去水印规格暂不可用');
        return ['product' => $product->toArray(), 'sku' => $sku->toArray(), 'tenant_price' => $tenant->isEmpty() ? (float)$sku['sale_points'] : (float)$tenant['sale_points']];
    }

    private static function snapshot(array $market): array { $p = $market['product']; $s = $market['sku']; $m = self::metadata($p); return ['product_id' => (int)$p['id'], 'sku_id' => (int)$s['id'], 'sku_key' => (string)$s['sku_key'], 'app_code' => (string)$p['upstream_app_code'], 'api_code' => (string)$p['upstream_api_code'], 'runtime_adapter' => 'watermark_removal', 'locked_params' => self::arrayValue($s['locked_params'] ?? []), 'params_schema' => self::arrayValue($m['params_schema'] ?? []), 'query_path' => (string)($m['query_path'] ?? $m['capabilities']['query_path'] ?? '/api/v1/tasks/{task_id}'), 'query_method' => 'GET', 'usage_unit' => (string)$s['usage_unit'], 'usage_unit_size' => MarketUsageSettlementService::unitSize($s), 'upstream_price' => (float)$s['upstream_price'], 'platform_price' => (float)$s['sale_points'], 'tenant_price' => (float)$market['tenant_price']]; }
    private static function payload(array $snapshot, array $request, string $key): array { $url = trim((string)($request['url'] ?? $request['source_url'] ?? '')); return ['url' => $url]; }
    private static function endpoint(array $snapshot, bool $query, string $taskId = ''): string { $origin = self::origin(); if ($query) { $path = str_replace('{task_id}', rawurlencode($taskId), (string)($snapshot['query_path'] ?? '/api/v1/tasks/{task_id}')); return $origin . '/' . ltrim($path, '/'); } return $origin . '/api/v1/apps/' . rawurlencode((string)$snapshot['app_code']) . '/' . rawurlencode((string)$snapshot['api_code']); }
    private static function marketContext(array $s): array { return ['market_product_id' => (int)$s['product_id'], 'market_sku_id' => (int)$s['sku_id'], 'sku_id' => (int)$s['sku_id'], 'sku_key' => (string)$s['sku_key'], 'price_source' => 'power_market_app_api']; }
    private static function assets(array $data, int $tenantId, int $userId, array $excluded = []): array { $excluded = array_values(array_filter(array_map('trim', $excluded))); $payload = self::arrayValue($data['data'] ?? []); if (isset($payload['result']) && is_array($payload['result'])) $payload = $payload['result']; elseif (isset($data['result']) && is_array($data['result'])) $payload = $data['result']; $raw = trim((string)($data['data'] ?? '')); $urls = preg_match('#^(?:https?://|data:video/)#i', $raw) === 1 ? [$raw] : AiTaskResultUrlService::collect($payload !== [] ? $payload : $data); $urls = array_values(array_diff($urls, $excluded)); $items = []; foreach (array_values(array_unique($urls)) as $url) { $stored = AigcVideoAssetService::persistGeneratedVideo($url, $tenantId, $userId); $items[] = ['video_uri' => (string)$stored['uri'], 'width' => (int)($stored['width'] ?? 0), 'height' => (int)($stored['height'] ?? 0), 'storage_scope' => (string)($stored['storage_scope'] ?? 'tenant'), 'storage_engine' => (string)($stored['storage_engine'] ?? 'local'), 'storage_domain' => (string)($stored['storage_domain'] ?? '')]; } return $items; }
    private static function request(string $method, string $url, array $payload = [], bool $allowError = false): array { $source = UpdateSourceClient::getSource(); $key = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? '')); if ($key === '') throw new Exception('去水印应用 API 暂不可用'); $ch = curl_init(); curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Accept: application/json', 'Content-Type: application/json'], CURLOPT_SSL_VERIFYPEER => UpdateSourceClient::sslVerify($source), CURLOPT_SSL_VERIFYHOST => UpdateSourceClient::sslVerify($source) ? 2 : 0]); if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); } $body = curl_exec($ch); $errno = curl_errno($ch); $error = curl_error($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); if ($errno) throw new Exception($error ?: '去水印应用网络请求失败'); $data = json_decode((string)$body, true); if (!is_array($data)) throw new Exception('去水印应用响应格式错误'); if (!$allowError && ($http >= 400 || isset($data['error']) || (isset($data['code']) && (int)$data['code'] !== 1))) throw new Exception(self::error($data)); return $data; }
    private static function response(array $c): array { $s = self::arrayValue($c['response_summary'] ?? []); $message = trim((string)($c['error_message'] ?? '')); $out = ['status' => (string)$c['run_status'], 'provider_task_id' => (string)$c['upstream_task_id'], 'videos' => (array)($s['videos'] ?? [])]; return $message === '' ? $out : array_merge($out, ['error' => $message, 'error_msg' => $message]); }
    private static function failureResponse(string $id, string $message): array { return ['status' => 'failed', 'provider_task_id' => $id, 'videos' => [], 'error' => $message, 'error_msg' => $message]; }
    private static function status(array $d): string { $root = self::arrayValue($d['data'] ?? $d); $v = strtolower((string)($d['status'] ?? $root['status'] ?? $root['state'] ?? $root['task_status'] ?? '')); if ($v === '' && array_key_exists('code', $d) && (int)$d['code'] === 0) return 'failed'; return match ($v) { 'done', 'completed', 'complete', 'success', 'succeeded' => 'success', 'failed', 'fail', 'error' => 'failed', 'canceled', 'cancelled' => 'canceled', default => $v ?: 'running' }; }
    private static function taskId(array $d): string { $root = self::arrayValue($d['data'] ?? $d); foreach ([$d['task_id'] ?? null, $d['id'] ?? null, $root['task_id'] ?? null, $root['id'] ?? null, $root['taskId'] ?? null] as $v) if (is_scalar($v) && (string)$v !== '') return (string)$v; return ''; }
    private static function requestId(array $d): string { $root = self::arrayValue($d['data'] ?? $d); return (string)($d['request_id'] ?? $root['request_id'] ?? ''); }
    private static function error(array $d): string { return UpstreamErrorMessageService::fromResponse($d) ?: '短视频去水印应用 API 请求失败'; }
    private static function metadata(array $p): array { $source = self::arrayValue($p['source_payload'] ?? []); return self::arrayValue($source['market_metadata'] ?? []); }
    private static function origin(): string { $s = UpdateSourceClient::getSource(); $parts = parse_url(trim((string)($s['active_base_url'] ?? $s['base_url'] ?? ''))); $host = (string)($parts['host'] ?? ''); if ($host === '') throw new Exception('去水印应用 API 暂不可用'); return (string)($parts['scheme'] ?? 'https') . '://' . $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : ''); }
    private static function existingReservation(int $tenantId, string $key, bool $lock = false): ?array { $q = AiAppTask::where(['tenant_id' => $tenantId, 'idempotency_key' => $key]); if ($lock) $q->lock(true); $task = $q->findOrEmpty(); if ($task->isEmpty()) return null; $q2 = AiConsumptionLog::where('app_task_id', (int)$task['id'])->order('id', 'asc'); if ($lock) $q2->lock(true); $c = $q2->findOrEmpty(); if ($c->isEmpty()) throw new Exception('短视频去水印任务缺少消耗记录'); return ['app_task_id' => (int)$task['id'], 'consumption_id' => (int)$c['id'], 'consume_no' => (string)$c['consume_no'], 'market_snapshot' => self::arrayValue($c['price_snapshot'] ?? [])]; }
    private static function context(int $id, bool $lock): ?array { $q = AiConsumptionLog::where('id', $id); if ($lock) $q->lock(true); $c = $q->findOrEmpty(); if ($c->isEmpty()) return null; $q2 = AiAppTask::where('id', (int)$c['app_task_id']); if ($lock) $q2->lock(true); $t = $q2->findOrEmpty(); return $t->isEmpty() ? null : ['consumption' => $c, 'app_task' => $t]; }
    private static function event(int $id, string $type, string $status, array $summary): void { AiConsumptionEvent::create(['consumption_id' => $id, 'event_type' => $type, 'event_status' => $status, 'attempt_no' => 1, 'payload_summary' => $summary, 'payload_ciphertext' => '', 'http_status' => 0, 'elapsed_ms' => 0, 'create_time' => time()]); }
    private static function extra(AiAppTask $t, AiConsumptionLog $c, string $stage): array { return ['app_code' => (string)$t['app_code'], 'app_task_id' => (int)$t['id'], 'app_task_no' => (string)$t['task_no'], 'consumption_id' => (int)$c['id'], 'consume_no' => (string)$c['consume_no'], 'billing_stage' => $stage]; }
    private static function requestSummary(array $r): array { return ['url' => trim((string)($r['url'] ?? $r['source_url'] ?? ''))]; }
    private static function arrayValue(mixed $v): array { if (is_array($v)) return $v; if (is_string($v) && $v !== '') { $d = json_decode($v, true); return is_array($d) ? $d : []; } return []; }
    private static function timestamp(mixed $v): int { if (is_numeric($v)) return (int)$v; $t = strtotime((string)$v); return $t === false ? 0 : $t; }
    private static function points(float $v): float { return round(max(0, $v), 6); }
    private static function selectionId(int $p, int $s): string { return 'market_watermark_app:' . $p . ':' . $s; }
    private static function no(string $p): string { return $p . date('YmdHis') . strtoupper(bin2hex(random_bytes(5))); }
}
