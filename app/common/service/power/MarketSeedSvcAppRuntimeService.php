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
use app\common\service\ai\MarketAppGateService;
use app\common\service\ai\UpstreamErrorMessageService;
use app\common\service\app\aigc_music\AigcMusicAssetService;
use app\common\service\point\PointService;
use app\common\service\update\UpdateSourceClient;
use Exception;
use think\facade\Db;

/** Executes the seedsvc voice-conversion / AI-cover application API. */
class MarketSeedSvcAppRuntimeService
{
    public const APP_CODE = 'aigc_music_cover';
    public const UPSTREAM_APP_CODE = 'seedsvc';
    public const SUBMIT_API_CODE = 'submit';
    private const QUERY_API_CODE = 'query';
    private const MAX_RUNNING_SECONDS = 7200;

    /** @return array<int, array<string, mixed>> */
    public static function options(int $tenantId): array
    {
        $products = PowerMarketProduct::where([
            'resource_type' => PowerMarketService::TYPE_APP_API,
            'upstream_app_code' => self::UPSTREAM_APP_CODE,
            'upstream_api_code' => self::SUBMIT_API_CODE,
            'status' => 1,
        ])->order('id', 'asc')->select()->toArray();
        TenantPowerMarketService::applyProductDisplays($tenantId, $products);
        $options = [];
        foreach ($products as $product) {
            $metadata = self::metadata($product);
            $skus = PowerMarketSku::where([
                'product_id' => (int)$product['id'],
                'status' => 1,
                'sale_status' => 1,
            ])->order('id', 'asc')->select()->toArray();
            foreach ($skus as $sku) {
                $tenant = TenantPowerMarketSkuPrice::where([
                    'tenant_id' => $tenantId,
                    'sku_id' => (int)$sku['id'],
                ])->findOrEmpty();
                if (!$tenant->isEmpty() && (int)$tenant['sale_status'] !== 1) {
                    continue;
                }
                $tenantPrice = $tenant->isEmpty() ? (float)$sku['sale_points'] : (float)$tenant['sale_points'];
                $options[] = [
                    'id' => self::selectionId((int)$product['id'], (int)$sku['id']),
                    'value' => self::selectionId((int)$product['id'], (int)$sku['id']),
                    'label' => (string)($sku['title'] ?: ($product['name'] ?? '音色修改、AI翻唱 API')),
                    'name' => (string)($sku['title'] ?: ($product['name'] ?? '音色修改、AI翻唱 API')),
                    'description' => (string)($product['description'] ?? ''),
                    'display_icon' => (string)($product['display_icon'] ?? ''),
                    'resource_type' => PowerMarketService::TYPE_APP_API,
                    'resource_type_label' => '应用 API',
                    'category_code' => 'audio',
                    'category_name' => '音频生成',
                    'market_product_id' => (int)$product['id'],
                    'market_sku_id' => (int)$sku['id'],
                    'sku_id' => (int)$sku['id'],
                    'sku_key' => (string)$sku['sku_key'],
                    'app_code' => self::UPSTREAM_APP_CODE,
                    'api_code' => self::SUBMIT_API_CODE,
                    'locked_params' => self::arrayValue($sku['locked_params'] ?? []),
                    'selectable_params' => self::arrayValue($sku['selectable_params'] ?? []),
                    'params_schema' => self::arrayValue($metadata['params_schema'] ?? []),
                    'default_params' => self::arrayValue($metadata['default_params'] ?? []),
                    'capabilities' => self::arrayValue($metadata['capabilities'] ?? []),
                    'query_api_code' => self::QUERY_API_CODE,
                    'platform_unit_cost' => self::points((float)$sku['sale_points']),
                    'tenant_unit_price' => self::points($tenantPrice),
                    'usage_unit' => (string)$sku['usage_unit'],
                    'usage_unit_size' => MarketUsageSettlementService::unitSize($sku),
                    'settlement_mode' => MarketUsageSettlementService::isActualUsageSku($sku) ? 'actual_usage' : 'reserved',
                    'enabled' => true,
                    'available' => true,
                    'sort' => (int)$sku['id'],
                ];
            }
        }
        return $options;
    }

    public static function quote(int $tenantId, array $selection, int $quantity = 1): array
    {
        $market = self::resolve($tenantId, $selection);
        $quantity = max(1, $quantity);
        $deferred = MarketUsageSettlementService::isActualUsageSku($market['sku']);
        return [
            'billing_unit' => (string)$market['sku']['usage_unit'],
            'billing_unit_size' => MarketUsageSettlementService::unitSize($market['sku']),
            'quantity' => $deferred ? 0 : $quantity,
            'tenant_unit_points' => self::points((float)$market['sku']['sale_points']),
            'user_unit_points' => self::points((float)$market['tenant_price']),
            'tenant_cost_points' => $deferred ? 0 : self::points((float)$market['sku']['sale_points'] * $quantity),
            'user_charge_points' => $deferred ? 0 : self::points((float)$market['tenant_price'] * $quantity),
            'settlement_mode' => $deferred ? 'actual_usage' : 'reserved',
            'price_source' => 'power_market_app_api',
            'market_product_id' => (int)$market['product']['id'],
            'market_sku_id' => (int)$market['sku']['id'],
            'market_snapshot' => self::snapshot($market),
        ];
    }

    public static function reserve(
        int $tenantId,
        int $userId,
        string $action,
        string $businessTaskId,
        array $selection,
        array $request,
        int $quantity = 1,
        string $appCode = self::APP_CODE,
        string $businessTable = 'aigc_music_cover_task'
    ): array {
        if (MarketAppGateService::requiresGate($appCode)) {
            MarketAppGateService::requireMarket($tenantId, $userId, $appCode, (string)($request['idempotency_key'] ?? $businessTaskId));
        }
        $market = self::resolve($tenantId, $selection);
        $quantity = max(1, $quantity);
        $deferred = MarketUsageSettlementService::isActualUsageSku($market['sku']);
        $tenantCost = $deferred ? 0 : self::points((float)$market['sku']['sale_points'] * $quantity);
        $userPrice = $deferred ? 0 : self::points((float)$market['tenant_price'] * $quantity);
        if (!$deferred) {
            PointService::assertCanConsumeAmounts($tenantId, $userId, $tenantCost, $userPrice);
        }
        $key = sha1($tenantId . '|' . $appCode . '|' . $action . '|' . $businessTaskId . '|seedsvc');
        $existing = self::existingReservation($tenantId, $key);
        if ($existing !== null) {
            return $existing;
        }
        return Db::transaction(function () use ($tenantId, $userId, $action, $businessTaskId, $request, $market, $quantity, $deferred, $tenantCost, $userPrice, $appCode, $businessTable, $key) {
            $existing = self::existingReservation($tenantId, $key, true);
            if ($existing !== null) {
                return $existing;
            }
            $now = time();
            $appTask = AiAppTask::create([
                'task_no' => self::no('AT'),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'app_code' => $appCode,
                'action_code' => $action,
                'business_table' => $businessTable,
                'business_id' => 0,
                'parent_task_id' => 0,
                'status' => 'running',
                'progress' => 10,
                'request_summary' => self::requestSummary($request),
                'result_summary' => [],
                'estimated_tenant_cost' => $tenantCost,
                'estimated_user_price' => $userPrice,
                'actual_tenant_cost' => 0,
                'actual_user_price' => 0,
                'idempotency_key' => $key,
                'create_time' => $now,
                'update_time' => $now,
                'finish_time' => 0,
            ]);
            $consumeNo = self::no('C');
            $consumption = AiConsumptionLog::create([
                'consume_no' => $consumeNo,
                'app_task_id' => (int)$appTask['id'],
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'app_code' => $appCode,
                'action_code' => $action,
                'resource_type' => PowerMarketService::TYPE_APP_API,
                'product_id' => (int)$market['product']['id'],
                'sku_id' => (int)$market['sku']['id'],
                'model_code' => '',
                'api_code' => self::SUBMIT_API_CODE,
                'protocol' => 'application_api',
                'provider' => 'power_market',
                'upstream_request_id' => '',
                'upstream_task_id' => '',
                'quantity' => $deferred ? 0 : $quantity,
                'usage_unit' => (string)$market['sku']['usage_unit'],
                'usage_snapshot' => ['settlement_basis' => $deferred ? 'awaiting_actual_usage' : 'submit_snapshot'],
                'price_snapshot' => self::snapshot($market),
                'request_summary' => self::requestSummary($request),
                'response_summary' => [],
                'run_status' => 'reserved',
                'billing_status' => $deferred ? 'pending_usage' : 'reserved',
                'reserved_tenant_cost' => $tenantCost,
                'reserved_user_price' => $userPrice,
                'actual_tenant_cost' => 0,
                'actual_user_price' => 0,
                'tenant_point_sn' => $consumeNo . '-reserve',
                'user_point_sn' => $consumeNo . '-reserve',
                'error_code' => '',
                'error_message' => '',
                'refresh_requested_at' => 0,
                'create_time' => $now,
                'update_time' => $now,
                'finish_time' => 0,
            ]);
            if (!$deferred) {
                PointService::reserveBusinessAmountsInCurrentTransaction(
                    $tenantId,
                    $userId,
                    $tenantCost,
                    $userPrice,
                    $consumeNo . '-reserve',
                    '音乐翻唱预占',
                    self::extra($appTask, $consumption, 'reserved')
                );
            }
            self::event((int)$consumption['id'], 'reserve', 'success', ['quantity' => $quantity]);
            return [
                'app_task_id' => (int)$appTask['id'],
                'consumption_id' => (int)$consumption['id'],
                'consume_no' => $consumeNo,
                'market_snapshot' => self::snapshot($market),
            ];
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
        if ($context === null) {
            throw new Exception('市场音色修改消耗记录不存在');
        }
        $consumption = $context['consumption'];
        if ((string)$consumption['run_status'] !== 'reserved') {
            return self::response($consumption->toArray());
        }
        $snapshot = self::arrayValue($consumption['price_snapshot'] ?? []);
        try {
            $body = self::request('POST', self::endpoint(self::SUBMIT_API_CODE), self::payload($snapshot, $request, (string)$consumption['consume_no']));
            $taskId = self::taskId($body);
            if ($taskId === '') {
                throw new Exception('音色修改接口未返回任务 ID');
            }
            $requestId = self::requestId($body);
            Db::transaction(function () use ($consumptionId, $taskId, $requestId) {
                $context = self::context($consumptionId, true);
                if ($context === null) {
                    return;
                }
                $row = $context['consumption'];
                if (!in_array((string)$row['billing_status'], ['reserved', 'pending_usage'], true)) {
                    return;
                }
                $row->save([
                    'run_status' => 'running',
                    'upstream_task_id' => $taskId,
                    'upstream_request_id' => $requestId,
                    'update_time' => time(),
                ]);
                self::event((int)$row['id'], 'submit', 'success', ['upstream_task_id' => $taskId]);
            });
            AiTaskJobService::enqueueQueryResult($consumptionId);
            return ['status' => 'running', 'provider_task_id' => $taskId, 'items' => []];
        } catch (\Throwable $e) {
            self::fail($consumptionId, $e->getMessage(), 'submit_failed');
            throw $e instanceof Exception ? $e : new Exception('音乐翻唱接口提交失败');
        }
    }

    public static function refresh(int $consumptionId): array
    {
        $context = self::context($consumptionId, false);
        if ($context === null) {
            throw new Exception('市场音色修改消耗记录不存在');
        }
        $consumption = $context['consumption'];
        if (in_array((string)$consumption['billing_status'], ['settled', 'refunded'], true)) {
            return self::response($consumption->toArray());
        }
        $taskId = trim((string)$consumption['upstream_task_id']);
        $created = self::timestamp($consumption['create_time'] ?? 0);
        $timedOut = $created > 0 && time() - $created >= self::MAX_RUNNING_SECONDS;
        if ($taskId === '') {
            if ($timedOut) {
                self::fail($consumptionId, '音乐翻唱任务未返回上游任务号', 'timeout');
                return self::failureResponse('', '音乐翻唱任务未返回上游任务号');
            }
            return self::response($consumption->toArray());
        }
        try {
            $body = self::request('POST', self::endpoint(self::QUERY_API_CODE), [
                'task_id' => is_numeric($taskId) ? (int)$taskId : $taskId,
            ]);
            $items = self::items($body, (int)$consumption['tenant_id'], (int)$consumption['user_id']);
            if ($items !== []) {
                self::settle($consumptionId, $items, self::requestId($body), $taskId, $body);
                return ['status' => 'success', 'provider_task_id' => $taskId, 'items' => $items];
            }
            $status = self::status($body);
            if (in_array($status, ['failed', 'error', 'canceled', 'cancelled'], true)) {
                $message = self::error($body) ?: '音乐翻唱任务失败';
                self::fail($consumptionId, $message, 'upstream_failed');
                return self::failureResponse($taskId, $message);
            }
            if (AiTaskLifecycleEventService::isTerminalSuccess($status)) {
                $message = '音乐翻唱任务已完成，但未返回可用音频';
                self::fail($consumptionId, $message, 'upstream_result_missing');
                return self::failureResponse($taskId, $message);
            }
            if ($timedOut) {
                self::fail($consumptionId, '音乐翻唱任务处理超时', 'timeout');
                return self::failureResponse($taskId, '音乐翻唱任务处理超时');
            }
            return ['status' => 'running', 'provider_task_id' => $taskId, 'items' => []];
        } catch (\Throwable $e) {
            if ($timedOut) {
                self::fail($consumptionId, $e->getMessage(), 'timeout');
                return self::failureResponse($taskId, $e->getMessage());
            }
            AiTaskLifecycleEventService::record($consumptionId, 'query_error', 'retrying', ['upstream_task_id' => $taskId, 'error' => $e->getMessage()]);
            return ['status' => 'running', 'provider_task_id' => $taskId, 'items' => []];
        }
    }

    public static function cancel(int $consumptionId): void
    {
        self::fail($consumptionId, '用户取消任务', 'canceled');
    }

    public static function fail(int $consumptionId, string $message, string $code = 'failed'): void
    {
        Db::transaction(function () use ($consumptionId, $message, $code) {
            $context = self::context($consumptionId, true);
            if ($context === null) {
                return;
            }
            $consumption = $context['consumption'];
            $task = $context['app_task'];
            if (in_array((string)$consumption['billing_status'], ['settled', 'refunded'], true)) {
                return;
            }
            if ((float)$consumption['reserved_tenant_cost'] > 0 || (float)$consumption['reserved_user_price'] > 0) {
                PointService::releaseReservedBusinessAmountsInCurrentTransaction(
                    (int)$consumption['tenant_id'],
                    (int)$consumption['user_id'],
                    (float)$consumption['reserved_tenant_cost'],
                    (float)$consumption['reserved_user_price'],
                    (string)$consumption['consume_no'] . '-release',
                    '音乐翻唱失败退回',
                    self::extra($task, $consumption, 'refunded')
                );
            }
            $now = time();
            $consumption->save([
                'run_status' => $code === 'canceled' ? 'canceled' : 'failed',
                'billing_status' => 'refunded',
                'error_code' => $code,
                'error_message' => mb_substr($message, 0, 1000),
                'finish_time' => $now,
                'update_time' => $now,
            ]);
            $task->save([
                'status' => $code === 'canceled' ? 'canceled' : 'failed',
                'progress' => 100,
                'result_summary' => ['error' => mb_substr($message, 0, 500)],
                'finish_time' => $now,
                'update_time' => $now,
            ]);
            self::event((int)$consumption['id'], $code === 'canceled' ? 'cancel' : 'refund', 'success', ['reason' => $message]);
        });
    }

    private static function settle(int $consumptionId, array $items, string $requestId, string $taskId, array $response): void
    {
        Db::transaction(function () use ($consumptionId, $items, $requestId, $taskId, $response) {
            $context = self::context($consumptionId, true);
            if ($context === null) {
                return;
            }
            $consumption = $context['consumption'];
            $task = $context['app_task'];
            if (in_array((string)$consumption['billing_status'], ['settled', 'refunded'], true)) {
                return;
            }
            $snapshot = self::arrayValue($consumption['price_snapshot'] ?? []);
            $deferred = MarketUsageSettlementService::isActualUsageSku($snapshot);
            $count = max(1, count($items));
            $tenant = $deferred
                ? MarketUsageSettlementService::price((float)($snapshot['platform_price'] ?? 0), $count, $snapshot)
                : (float)$consumption['reserved_tenant_cost'];
            $user = $deferred
                ? MarketUsageSettlementService::price((float)($snapshot['tenant_price'] ?? 0), $count, $snapshot)
                : (float)$consumption['reserved_user_price'];
            if ($deferred) {
                PointService::assertCanConsumeAmounts((int)$consumption['tenant_id'], (int)$consumption['user_id'], $tenant, $user);
                PointService::consumeBusinessAmountsInCurrentTransaction((int)$consumption['tenant_id'], (int)$consumption['user_id'], $tenant, $user, (string)$consumption['consume_no'], '音乐翻唱实际用量结算', self::extra($task, $consumption, 'settled'));
            } else {
                PointService::settleReservedBusinessAmountsInCurrentTransaction((int)$consumption['tenant_id'], (int)$consumption['user_id'], (float)$consumption['reserved_tenant_cost'], (float)$consumption['reserved_user_price'], $tenant, $user, (string)$consumption['consume_no'], '音乐翻唱结算', self::extra($task, $consumption, 'settled'));
            }
            $now = time();
            $consumption->save([
                'run_status' => 'success',
                'billing_status' => 'settled',
                'upstream_request_id' => $requestId ?: (string)$consumption['upstream_request_id'],
                'upstream_task_id' => $taskId,
                'quantity' => $count,
                'usage_snapshot' => ['audio_count' => $count],
                'response_summary' => ['audio_count' => $count, 'items' => $items],
                'actual_tenant_cost' => $tenant,
                'actual_user_price' => $user,
                'finish_time' => $now,
                'update_time' => $now,
            ]);
            $task->save([
                'status' => 'success',
                'progress' => 100,
                'actual_tenant_cost' => $tenant,
                'actual_user_price' => $user,
                'result_summary' => ['audio_count' => $count, 'items' => $items],
                'finish_time' => $now,
                'update_time' => $now,
            ]);
            self::event((int)$consumption['id'], 'settle', 'success', ['audio_count' => $count]);
        });
    }

    private static function resolve(int $tenantId, array $selection): array
    {
        $productId = (int)($selection['market_product_id'] ?? 0);
        $skuId = (int)($selection['market_sku_id'] ?? $selection['sku_id'] ?? 0);
        $id = (string)($selection['id'] ?? $selection['value'] ?? '');
        if (($productId <= 0 || $skuId <= 0) && preg_match('/^market_seedsvc_app:(\d+):(\d+)$/', $id, $matches)) {
            $productId = (int)$matches[1];
            $skuId = (int)$matches[2];
        }
        $product = PowerMarketProduct::where([
            'id' => $productId,
            'resource_type' => PowerMarketService::TYPE_APP_API,
            'upstream_app_code' => self::UPSTREAM_APP_CODE,
            'upstream_api_code' => self::SUBMIT_API_CODE,
            'status' => 1,
        ])->findOrEmpty();
        if ($product->isEmpty()) {
            throw new Exception('所选音色修改应用 API 已下架');
        }
        $sku = PowerMarketSku::where([
            'id' => $skuId,
            'product_id' => $productId,
            'status' => 1,
            'sale_status' => 1,
        ])->findOrEmpty();
        if ($sku->isEmpty()) {
            throw new Exception('所选音色修改规格已下架');
        }
        $tenant = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => $skuId])->findOrEmpty();
        if (!$tenant->isEmpty() && (int)$tenant['sale_status'] !== 1) {
            throw new Exception('所选音色修改规格暂不可用');
        }
        return [
            'product' => $product->toArray(),
            'sku' => $sku->toArray(),
            'tenant_price' => $tenant->isEmpty() ? (float)$sku['sale_points'] : (float)$tenant['sale_points'],
        ];
    }

    private static function snapshot(array $market): array
    {
        $product = $market['product'];
        $sku = $market['sku'];
        $metadata = self::metadata($product);
        return [
            'product_id' => (int)$product['id'],
            'sku_id' => (int)$sku['id'],
            'sku_key' => (string)$sku['sku_key'],
            'app_code' => self::UPSTREAM_APP_CODE,
            'api_code' => self::SUBMIT_API_CODE,
            'runtime_adapter' => 'seedsvc',
            'locked_params' => self::arrayValue($sku['locked_params'] ?? []),
            'params_schema' => self::arrayValue($metadata['params_schema'] ?? []),
            'query_api_code' => self::QUERY_API_CODE,
            'usage_unit' => (string)$sku['usage_unit'],
            'usage_unit_size' => MarketUsageSettlementService::unitSize($sku),
            'upstream_price' => (float)$sku['upstream_price'],
            'platform_price' => (float)$sku['sale_points'],
            'tenant_price' => (float)$market['tenant_price'],
        ];
    }

    private static function payload(array $snapshot, array $request, string $key): array
    {
        $locked = self::arrayValue($snapshot['locked_params'] ?? []);
        $provider = self::arrayValue($request['provider_params'] ?? []);
        $payload = array_merge($locked, $provider, [
            'mode' => (string)($request['mode'] ?? 'async_query'),
            'ref_audio' => trim((string)($request['ref_audio'] ?? '')),
            'source_audio' => trim((string)($request['source_audio'] ?? '')),
        ]);
        return array_filter($payload, static fn($value) => $value !== '' && $value !== null && $value !== []);
    }

    private static function items(array $data, int $tenantId, int $userId): array
    {
        $urls = AiTaskResultUrlService::collect($data);
        $root = self::arrayValue($data['data'] ?? $data);
        $result = self::arrayValue($root['result'] ?? ($data['result'] ?? []));
        foreach ([
            $data['url'] ?? null,
            $data['audio_url'] ?? null,
            $data['file_url'] ?? null,
            $root['output'] ?? null,
            $root['url'] ?? null,
            $root['audio_url'] ?? null,
            $result['output'] ?? null,
            $result['url'] ?? null,
            $result['audio_url'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && self::validUrl($candidate)) {
                $urls[] = trim($candidate);
            }
        }
        $urls = array_values(array_unique($urls));
        $items = [];
        foreach ($urls as $url) {
            try {
                $stored = AigcMusicAssetService::persistGeneratedAudio($url, $tenantId, $userId);
                $items[] = [
                    'audio_uri' => (string)$stored['uri'],
                    'audio_url' => (string)($stored['url'] ?? ''),
                    'storage_scope' => (string)($stored['storage_scope'] ?? 'tenant'),
                    'storage_engine' => (string)($stored['storage_engine'] ?? 'local'),
                    'storage_domain' => (string)($stored['storage_domain'] ?? ''),
                    'mime_type' => (string)($stored['mime_type'] ?? ''),
                    'file_size' => (int)($stored['file_size'] ?? 0),
                    'duration' => (float)($stored['duration'] ?? 0),
                ];
            } catch (\Throwable) {
                // Ignore expired or non-audio secondary URLs and keep usable results.
            }
        }
        return $items;
    }

    private static function endpoint(string $apiCode): string
    {
        return self::origin() . '/api/v1/apps/' . rawurlencode(self::UPSTREAM_APP_CODE) . '/' . rawurlencode($apiCode);
    }

    private static function origin(): string
    {
        $source = UpdateSourceClient::getSource();
        $parts = parse_url(trim((string)($source['active_base_url'] ?? $source['base_url'] ?? '')));
        $host = (string)($parts['host'] ?? '');
        if ($host === '') {
            throw new Exception('音色修改应用 API 暂不可用');
        }
        return (string)($parts['scheme'] ?? 'https') . '://' . $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
    }

    private static function request(string $method, string $url, array $payload = []): array
    {
        $source = UpdateSourceClient::getSource();
        $key = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? ''));
        if ($key === '') {
            throw new Exception('音色修改应用 API 暂不可用');
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => UpdateSourceClient::sslVerify($source),
            CURLOPT_SSL_VERIFYHOST => UpdateSourceClient::sslVerify($source) ? 2 : 0,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno) {
            throw new Exception($error ?: '音色修改应用网络请求失败');
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            throw new Exception('音色修改应用响应格式错误');
        }
        if ($http >= 400 || isset($data['error']) || (array_key_exists('code', $data) && (int)$data['code'] !== 1)) {
            throw new Exception(self::error($data));
        }
        return $data;
    }

    private static function status(array $data): string
    {
        $root = self::arrayValue($data['data'] ?? $data);
        $result = self::arrayValue($root['result'] ?? []);
        $value = strtolower((string)($data['status'] ?? $root['status'] ?? $root['state'] ?? $root['task_status'] ?? $result['status'] ?? ''));
        return match ($value) {
            'done', 'completed', 'complete', 'success', 'succeeded' => 'success',
            'failed', 'fail', 'error' => 'failed',
            'canceled', 'cancelled' => 'canceled',
            default => $value !== '' ? $value : 'running',
        };
    }

    private static function taskId(array $data): string
    {
        $root = self::arrayValue($data['data'] ?? $data);
        foreach ([$data['task_id'] ?? null, $data['id'] ?? null, $root['task_id'] ?? null, $root['id'] ?? null, $root['elastic_task_id'] ?? null] as $value) {
            if (is_scalar($value) && (string)$value !== '') {
                return (string)$value;
            }
        }
        return '';
    }

    private static function requestId(array $data): string
    {
        $root = self::arrayValue($data['data'] ?? $data);
        return (string)($data['request_id'] ?? $root['request_id'] ?? '');
    }

    private static function error(array $data): string
    {
        return UpstreamErrorMessageService::fromResponse($data) ?: '音色修改应用 API 请求失败';
    }

    private static function validUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        return is_array($parts)
            && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            && trim((string)($parts['host'] ?? '')) !== '';
    }

    private static function response(array $consumption): array
    {
        $summary = self::arrayValue($consumption['response_summary'] ?? []);
        $message = trim((string)($consumption['error_message'] ?? ''));
        $response = [
            'status' => (string)$consumption['run_status'],
            'provider_task_id' => (string)$consumption['upstream_task_id'],
            'items' => (array)($summary['items'] ?? []),
        ];
        return $message === '' ? $response : array_merge($response, ['error' => $message, 'error_msg' => $message]);
    }

    private static function failureResponse(string $taskId, string $message): array
    {
        return ['status' => 'failed', 'provider_task_id' => $taskId, 'items' => [], 'error' => $message, 'error_msg' => $message];
    }

    private static function metadata(array $product): array
    {
        $source = self::arrayValue($product['source_payload'] ?? []);
        return self::arrayValue($source['market_metadata'] ?? []);
    }

    private static function existingReservation(int $tenantId, string $key, bool $lock = false): ?array
    {
        $query = AiAppTask::where(['tenant_id' => $tenantId, 'idempotency_key' => $key]);
        if ($lock) {
            $query->lock(true);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            return null;
        }
        $consumptionQuery = AiConsumptionLog::where('app_task_id', (int)$task['id'])->order('id', 'asc');
        if ($lock) {
            $consumptionQuery->lock(true);
        }
        $consumption = $consumptionQuery->findOrEmpty();
        if ($consumption->isEmpty()) {
            throw new Exception('音色修改任务缺少消耗记录');
        }
        return [
            'app_task_id' => (int)$task['id'],
            'consumption_id' => (int)$consumption['id'],
            'consume_no' => (string)$consumption['consume_no'],
            'market_snapshot' => self::arrayValue($consumption['price_snapshot'] ?? []),
        ];
    }

    private static function context(int $consumptionId, bool $lock): ?array
    {
        $query = AiConsumptionLog::where('id', $consumptionId);
        if ($lock) {
            $query->lock(true);
        }
        $consumption = $query->findOrEmpty();
        if ($consumption->isEmpty()) {
            return null;
        }
        $taskQuery = AiAppTask::where('id', (int)$consumption['app_task_id']);
        if ($lock) {
            $taskQuery->lock(true);
        }
        $task = $taskQuery->findOrEmpty();
        return $task->isEmpty() ? null : ['consumption' => $consumption, 'app_task' => $task];
    }

    private static function event(int $consumptionId, string $type, string $status, array $summary): void
    {
        AiConsumptionEvent::create([
            'consumption_id' => $consumptionId,
            'event_type' => $type,
            'event_status' => $status,
            'attempt_no' => 1,
            'payload_summary' => $summary,
            'payload_ciphertext' => '',
            'http_status' => 0,
            'elapsed_ms' => 0,
            'create_time' => time(),
        ]);
    }

    private static function extra(AiAppTask $task, AiConsumptionLog $consumption, string $stage): array
    {
        return [
            'app_code' => (string)$task['app_code'],
            'app_task_id' => (int)$task['id'],
            'app_task_no' => (string)$task['task_no'],
            'consumption_id' => (int)$consumption['id'],
            'consume_no' => (string)$consumption['consume_no'],
            'billing_stage' => $stage,
        ];
    }

    private static function requestSummary(array $request): array
    {
        return [
            'mode' => (string)($request['mode'] ?? 'async_query'),
            'has_ref_audio' => trim((string)($request['ref_audio'] ?? '')) !== '',
            'has_source_audio' => trim((string)($request['source_audio'] ?? '')) !== '',
        ];
    }

    private static function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private static function timestamp(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int)$value;
        }
        $timestamp = strtotime((string)$value);
        return $timestamp === false ? 0 : $timestamp;
    }

    private static function points(float $value): float
    {
        return round(max(0, $value), 6);
    }

    private static function selectionId(int $productId, int $skuId): string
    {
        return 'market_seedsvc_app:' . $productId . ':' . $skuId;
    }

    private static function no(string $prefix): string
    {
        return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(5)));
    }
}
