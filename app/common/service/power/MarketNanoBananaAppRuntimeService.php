<?php

namespace app\common\service\power;

use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionEvent;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\model\power\TenantPowerMarketSkuPrice;
use app\common\service\ai\AiTaskJobService;
use app\common\service\app\aigc_image\AigcImageAssetService;
use app\common\service\point\PointService;
use app\common\service\update\UpdateSourceClient;
use Exception;
use think\facade\Db;

/** Executes the nano_banana image application API sold through the power market. */
class MarketNanoBananaAppRuntimeService
{
    public const APP_CODE = 'aigc_short_drama';
    private const UPSTREAM_APP_CODE = 'nano_banana';
    private const SUBMIT_API_CODE = 'submit';
    private const QUERY_API_CODE = 'query';
    private const MAX_RUNNING_SECONDS = 7200;

    /** @return array<int,array<string,mixed>> */
    public static function options(int $tenantId): array
    {
        // Query is an internal lifecycle dependency. Do not expose a submit
        // product for new tasks when its result contract has been unshelved.
        if (!self::queryAvailable()) {
            return [];
        }
        $products = PowerMarketProduct::where([
            'resource_type' => PowerMarketService::TYPE_APP_API,
            'upstream_app_code' => self::UPSTREAM_APP_CODE,
            'upstream_api_code' => self::SUBMIT_API_CODE,
            'status' => 1,
        ])->select()->toArray();
        $options = [];
        foreach ($products as $product) {
            $capabilities = self::capabilities($product);
            $byModel = [];
            foreach (self::availableSkus($tenantId, (int)$product['id']) as $market) {
                $sku = (array)$market['sku'];
                $locked = self::arrayValue($sku['locked_params'] ?? []);
                $model = trim((string)($locked['model'] ?? ''));
                if ($model === '') {
                    continue;
                }
                $quality = strtolower(trim((string)($locked['resolution'] ?? '')));
                $byModel[$model][] = [
                    'market_sku_id' => (int)$sku['id'],
                    'sku_key' => (string)$sku['sku_key'],
                    'title' => (string)$sku['title'],
                    'quality' => $quality ?: '1k',
                    'resolution' => $quality ?: '1k',
                    'locked_params' => $locked,
                    'platform_unit_cost' => self::points((float)$sku['sale_points']),
                    'tenant_unit_price' => self::points((float)$market['tenant_price']),
                    'usage_unit' => (string)$sku['usage_unit'],
                    'usage_unit_size' => MarketUsageSettlementService::unitSize($sku),
                    'settlement_mode' => MarketUsageSettlementService::isActualUsageSku($sku) ? 'actual_usage' : 'reserved',
                ];
            }
            foreach ($byModel as $model => $skus) {
                $qualities = array_values(array_unique(array_column($skus, 'quality')));
                $first = $skus[0];
                $id = self::selectionId((int)$product['id'], $model);
                $options[] = [
                    'id' => $id,
                    'value' => $id,
                    'channel_code' => $id,
                    'market_product_id' => (int)$product['id'],
                    'resource_type' => PowerMarketService::TYPE_APP_API,
                    'resource_type_label' => '应用 API',
                    'name' => self::displayName($model),
                    'model_code' => $model,
                    'provider_model' => $model,
                    'quality_options' => $qualities,
                    'resolution_options' => array_map(static fn(string $value): array => ['value' => $value, 'label' => self::qualityLabel($value)], $qualities),
                    'default_quality' => (string)($qualities[0] ?? '1k'),
                    'ratio_options' => ['1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3', '5:4', '4:5', '21:9'],
                    'default_ratio' => '1:1',
                    'skus' => $skus,
                    'max_reference_images' => max(0, (int)($capabilities['max_reference_images'] ?? 0)),
                    'supports_reference_images' => !empty($capabilities['supports_vision']),
                    'platform_unit_cost' => min(array_column($skus, 'platform_unit_cost')),
                    'tenant_unit_price' => min(array_column($skus, 'tenant_unit_price')),
                    'usage_unit' => (string)$first['usage_unit'],
                    'enabled' => true,
                    'sort' => (int)$product['id'],
                ];
            }
        }
        return $options;
    }

    public static function isSelection(array $selection): bool
    {
        $nested = self::arrayValue($selection['params'] ?? []);
        foreach (['model_id', 'image_model_id', 'channel', 'channel_code'] as $key) {
            $value = (string)($selection[$key] ?? $nested[$key] ?? '');
            if (str_starts_with($value, 'market_nano_banana:')) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    public static function quote(int $tenantId, array $selection, int $quantity = 1): array
    {
        $market = self::resolve($tenantId, $selection);
        $quantity = max(1, $quantity);
        $deferredUsage = MarketUsageSettlementService::isActualUsageSku($market['sku']);
        return [
            'billing_unit' => (string)$market['sku']['usage_unit'],
            'billing_unit_size' => MarketUsageSettlementService::unitSize($market['sku']),
            'quantity' => $deferredUsage ? 0 : $quantity,
            'tenant_unit_points' => self::points((float)$market['sku']['sale_points']),
            'user_unit_points' => self::points((float)$market['tenant_price']),
            'tenant_cost_points' => $deferredUsage ? 0 : self::points((float)$market['sku']['sale_points'] * $quantity),
            'user_charge_points' => $deferredUsage ? 0 : self::points((float)$market['tenant_price'] * $quantity),
            'settlement_mode' => $deferredUsage ? 'actual_usage' : 'reserved',
            'price_source' => 'power_market_app_api',
            'market_product_id' => (int)$market['product']['id'],
            'market_sku_id' => (int)$market['sku']['id'],
            'market_snapshot' => self::snapshot($market),
        ];
    }

    /** @return array{app_task_id:int,consumption_id:int,consume_no:string,market_snapshot:array<string,mixed>} */
    public static function reserve(int $tenantId, int $userId, string $action, string $businessTaskId, array $selection, array $request, int $quantity = 1): array
    {
        $market = self::resolve($tenantId, $selection);
        $quantity = max(1, $quantity);
        self::assertReferences($market, $request);
        $deferredUsage = MarketUsageSettlementService::isActualUsageSku($market['sku']);
        $tenantCost = $deferredUsage ? 0 : self::points((float)$market['sku']['sale_points'] * $quantity);
        $userPrice = $deferredUsage ? 0 : self::points((float)$market['tenant_price'] * $quantity);
        if (!$deferredUsage) PointService::assertCanConsumeAmounts($tenantId, $userId, $tenantCost, $userPrice);

        return Db::transaction(function () use ($tenantId, $userId, $action, $businessTaskId, $market, $request, $quantity, $tenantCost, $userPrice, $deferredUsage) {
            $now = time();
            $appTask = AiAppTask::create([
                'task_no' => self::no('AT'), 'tenant_id' => $tenantId, 'user_id' => $userId,
                'app_code' => self::APP_CODE, 'action_code' => $action, 'business_table' => 'aigc_short_drama_generation_task', 'business_id' => 0, 'parent_task_id' => 0,
                'status' => 'running', 'progress' => 10, 'request_summary' => self::requestSummary($request), 'result_summary' => [],
                'estimated_tenant_cost' => $tenantCost, 'estimated_user_price' => $userPrice, 'actual_tenant_cost' => 0, 'actual_user_price' => 0,
                'idempotency_key' => sha1($tenantId . '|' . $businessTaskId . '|nano_banana'), 'create_time' => $now, 'update_time' => $now, 'finish_time' => 0,
            ]);
            $consumeNo = self::no('C');
            $consumption = AiConsumptionLog::create([
                'consume_no' => $consumeNo, 'app_task_id' => (int)$appTask['id'], 'tenant_id' => $tenantId, 'user_id' => $userId,
                'app_code' => self::APP_CODE, 'action_code' => $action, 'resource_type' => PowerMarketService::TYPE_APP_API,
                'product_id' => (int)$market['product']['id'], 'sku_id' => (int)$market['sku']['id'], 'model_code' => (string)$market['model'], 'api_code' => self::SUBMIT_API_CODE,
                'protocol' => 'application_api', 'provider' => 'power_market', 'upstream_request_id' => '', 'upstream_task_id' => '', 'quantity' => $deferredUsage ? 0 : $quantity,
                'usage_unit' => (string)$market['sku']['usage_unit'], 'usage_snapshot' => ['settlement_basis' => $deferredUsage ? 'awaiting_actual_usage' : 'submit_snapshot'], 'price_snapshot' => self::snapshot($market), 'request_summary' => self::requestSummary($request), 'response_summary' => [],
                'run_status' => 'reserved', 'billing_status' => $deferredUsage ? 'pending_usage' : 'reserved', 'reserved_tenant_cost' => $tenantCost, 'reserved_user_price' => $userPrice, 'actual_tenant_cost' => 0, 'actual_user_price' => 0,
                'tenant_point_sn' => $consumeNo . '-reserve', 'user_point_sn' => $consumeNo . '-reserve', 'error_code' => '', 'error_message' => '', 'refresh_requested_at' => 0,
                'create_time' => $now, 'update_time' => $now, 'finish_time' => 0,
            ]);
            if (!$deferredUsage) PointService::reserveBusinessAmountsInCurrentTransaction($tenantId, $userId, $tenantCost, $userPrice, $consumeNo . '-reserve', '短剧 nano-banana 生图预占', self::extra($appTask, $consumption, 'reserved'));
            self::event((int)$consumption['id'], 'reserve', 'success', ['quantity' => $quantity, 'settlement_mode' => $deferredUsage ? 'actual_usage' : 'reserved']);
            return ['app_task_id' => (int)$appTask['id'], 'consumption_id' => (int)$consumption['id'], 'consume_no' => $consumeNo, 'market_snapshot' => self::snapshot($market)];
        });
    }

    public static function linkBusinessTask(int $appTaskId, int $businessId): void
    {
        if ($appTaskId > 0 && $businessId > 0) {
            AiAppTask::where('id', $appTaskId)->update(['business_id' => $businessId, 'update_time' => time()]);
        }
    }

    /** @return array<string,mixed> */
    public static function submit(int $consumptionId, array $request): array
    {
        $context = self::context($consumptionId, false);
        if ($context === null) throw new Exception('nano-banana 消耗记录不存在');
        $consumption = $context['consumption'];
        if ((string)$consumption['run_status'] !== 'reserved') return self::response($consumption->toArray());
        $snapshot = self::arrayValue($consumption['price_snapshot'] ?? []);
        try {
            $response = self::request('POST', self::endpoint((string)$snapshot['app_code'], self::SUBMIT_API_CODE), self::payload($snapshot, $request, (string)$consumption['consume_no']));
            $images = self::images($response, (int)$consumption['tenant_id'], (int)$consumption['user_id']);
            $taskId = self::taskId($response);
            $requestId = self::requestId($response);
            Db::transaction(function () use ($consumptionId, $images, $taskId, $requestId) {
                $ctx = self::context($consumptionId, true); if ($ctx === null) return;
                $c = $ctx['consumption']; if (!in_array((string)$c['billing_status'], ['reserved', 'pending_usage'], true)) return;
                $c->save(['run_status' => $images === [] ? 'running' : 'success', 'upstream_task_id' => $taskId, 'upstream_request_id' => $requestId, 'update_time' => time()]);
                self::event((int)$c['id'], 'submit', 'success', ['upstream_task_id' => $taskId, 'image_count' => count($images)]);
            });
            if ($images !== []) {
                self::settle($consumptionId, $images, $requestId, $taskId, $response);
                AiTaskJobService::enqueueProcessResult($consumptionId);
            }
            else AiTaskJobService::enqueueQueryResult($consumptionId);
            if ($images === [] && $taskId === '') throw new Exception('nano-banana 应用 API 未返回任务 ID');
            return ['status' => $images === [] ? 'running' : 'success', 'provider_task_id' => $taskId, 'provider_request_id' => $requestId, 'images' => $images];
        } catch (\Throwable $e) {
            self::fail($consumptionId, $e->getMessage(), 'submit_failed');
            throw $e instanceof Exception ? $e : new Exception('nano-banana 生图提交失败');
        }
    }

    /** @return array<string,mixed> */
    public static function refresh(int $consumptionId): array
    {
        $context = self::context($consumptionId, false);
        if ($context === null) throw new Exception('nano-banana 消耗记录不存在');
        $consumption = $context['consumption'];
        if (!in_array((string)$consumption['billing_status'], ['reserved', 'pending_usage'], true)) return self::response($consumption->toArray());
        $timedOut = (int)$consumption['create_time'] > 0 && time() - (int)$consumption['create_time'] >= self::MAX_RUNNING_SECONDS;
        $taskId = trim((string)$consumption['upstream_task_id']);
        if ($taskId === '') {
            if ($timedOut) {
                self::fail($consumptionId, '图片任务未返回上游任务号', 'timeout');
                return ['status' => 'failed', 'provider_task_id' => '', 'images' => []];
            }
            return self::response($consumption->toArray());
        }
        try {
            $snapshot = self::arrayValue($consumption['price_snapshot'] ?? []);
            $response = self::request('GET', self::endpoint((string)$snapshot['app_code'], self::QUERY_API_CODE) . '?task_id=' . rawurlencode($taskId));
            $images = self::images($response, (int)$consumption['tenant_id'], (int)$consumption['user_id']);
            if ($images !== []) { self::settle($consumptionId, $images, self::requestId($response), $taskId, $response); return ['status' => 'success', 'provider_task_id' => $taskId, 'images' => $images]; }
            if (in_array(self::status($response), ['failed', 'error', 'canceled', 'cancelled'], true)) { self::fail($consumptionId, self::error($response), 'upstream_failed'); return ['status' => 'failed', 'provider_task_id' => $taskId, 'images' => []]; }
            if ($timedOut) { self::fail($consumptionId, '图片任务处理超时', 'timeout'); return ['status' => 'failed', 'provider_task_id' => $taskId, 'images' => []]; }
            return ['status' => 'running', 'provider_task_id' => $taskId, 'images' => []];
        } catch (\Throwable) {
            if ($timedOut) { self::fail($consumptionId, '图片任务处理超时', 'timeout'); return ['status' => 'failed', 'provider_task_id' => $taskId, 'images' => []]; }
            return ['status' => 'running', 'provider_task_id' => $taskId, 'images' => []];
        }
    }

    public static function cancel(int $consumptionId): void
    {
        $context = self::context($consumptionId, false);
        if ($context === null) throw new Exception('nano-banana 消耗记录不存在');
        $consumption = $context['consumption'];
        if (!in_array((string)$consumption['billing_status'], ['reserved', 'pending_usage'], true)) return;
        if ((string)$consumption['run_status'] !== 'reserved') throw new Exception('图片任务已提交至 nano-banana 应用 API，无法取消');
        self::fail($consumptionId, '用户取消任务', 'canceled');
    }

    public static function fail(int $consumptionId, string $message, string $code = 'failed'): void
    {
        Db::transaction(function () use ($consumptionId, $message, $code) {
            $ctx = self::context($consumptionId, true); if ($ctx === null) return;
            $c = $ctx['consumption']; $task = $ctx['app_task'];
            if (in_array((string)$c['billing_status'], ['settled', 'refunded'], true)) return;
            if ((float)$c['reserved_tenant_cost'] > 0 || (float)$c['reserved_user_price'] > 0) PointService::releaseReservedBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], (float)$c['reserved_tenant_cost'], (float)$c['reserved_user_price'], (string)$c['consume_no'] . '-release', '短剧 nano-banana 生图失败退回', self::extra($task, $c, 'refunded'));
            $now = time();
            $c->save(['run_status' => $code === 'canceled' ? 'canceled' : 'failed', 'billing_status' => 'refunded', 'error_code' => $code, 'error_message' => mb_substr($message, 0, 1000), 'finish_time' => $now, 'update_time' => $now]);
            $task->save(['status' => $code === 'canceled' ? 'canceled' : 'failed', 'progress' => 100, 'result_summary' => ['error' => mb_substr($message, 0, 500)], 'finish_time' => $now, 'update_time' => $now]);
            self::event((int)$c['id'], $code === 'canceled' ? 'cancel' : 'refund', 'success', ['reason' => $message]);
        });
    }

    private static function settle(int $consumptionId, array $images, string $requestId, string $taskId, array $response): void
    {
        Db::transaction(function () use ($consumptionId, $images, $requestId, $taskId, $response) {
            $ctx = self::context($consumptionId, true); if ($ctx === null) return;
            $c = $ctx['consumption']; $task = $ctx['app_task']; if (!in_array((string)$c['billing_status'], ['reserved', 'pending_usage'], true)) return;
            $snapshot = self::arrayValue($c['price_snapshot'] ?? []); $quantity = max(1, count($images));
            $deferredUsage = MarketUsageSettlementService::isActualUsageSku($snapshot); $actualUsage = MarketUsageSettlementService::tokenUsage($response);
            if ($deferredUsage && $actualUsage <= 0) { $now = time(); $c->save(['run_status' => 'success', 'billing_status' => 'pending_usage', 'upstream_request_id' => $requestId ?: (string)$c['upstream_request_id'], 'upstream_task_id' => $taskId ?: (string)$c['upstream_task_id'], 'usage_snapshot' => ['settlement_basis' => 'awaiting_actual_usage'], 'response_summary' => ['image_count' => $quantity, 'images' => $images], 'finish_time' => $now, 'update_time' => $now]); $task->save(['status' => 'success', 'progress' => 100, 'finish_time' => $now, 'update_time' => $now]); self::event((int)$c['id'], 'await_usage', 'pending', ['image_count' => $quantity]); return; }
            $billedQuantity = $deferredUsage ? $actualUsage : $quantity; $tenant = $deferredUsage ? MarketUsageSettlementService::price((float)($snapshot['platform_price'] ?? 0), $billedQuantity, $snapshot) : self::points((float)($snapshot['platform_price'] ?? 0) * $quantity); $user = $deferredUsage ? MarketUsageSettlementService::price((float)($snapshot['tenant_price'] ?? 0), $billedQuantity, $snapshot) : self::points((float)($snapshot['tenant_price'] ?? 0) * $quantity);
            if ($deferredUsage) { PointService::assertCanConsumeAmounts((int)$c['tenant_id'], (int)$c['user_id'], $tenant, $user); PointService::consumeBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], $tenant, $user, (string)$c['consume_no'], '短剧 nano-banana 实际用量结算', self::extra($task, $c, 'settled')); } else PointService::settleReservedBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], (float)$c['reserved_tenant_cost'], (float)$c['reserved_user_price'], $tenant, $user, (string)$c['consume_no'], '短剧 nano-banana 生图结算', self::extra($task, $c, 'settled'));
            $now = time();
            $c->save(['run_status' => 'success', 'billing_status' => 'settled', 'upstream_request_id' => $requestId ?: (string)$c['upstream_request_id'], 'upstream_task_id' => $taskId ?: (string)$c['upstream_task_id'], 'quantity' => $billedQuantity, 'usage_snapshot' => ['image_count' => $quantity, 'actual_token_usage' => $deferredUsage ? $actualUsage : 0], 'response_summary' => ['image_count' => $quantity, 'images' => $images], 'actual_tenant_cost' => $tenant, 'actual_user_price' => $user, 'tenant_point_sn' => $deferredUsage ? (string)$c['consume_no'] : (string)$c['tenant_point_sn'], 'user_point_sn' => $deferredUsage ? (string)$c['consume_no'] : (string)$c['user_point_sn'], 'finish_time' => $now, 'update_time' => $now]);
            $task->save(['status' => 'success', 'progress' => 100, 'actual_tenant_cost' => $tenant, 'actual_user_price' => $user, 'finish_time' => $now, 'update_time' => $now]);
            self::event((int)$c['id'], 'settle', 'success', ['image_count' => $quantity]);
        });
    }

    /** @return array{product:array<string,mixed>,sku:array<string,mixed>,tenant_price:float,model:string,reference_limit:int} */
    private static function resolve(int $tenantId, array $selection): array
    {
        [$productId, $model] = self::selection($selection);
        if ($productId <= 0 || $model === '') throw new Exception('请选择 nano-banana 应用 API 模型');
        $product = PowerMarketProduct::where(['id' => $productId, 'resource_type' => PowerMarketService::TYPE_APP_API, 'upstream_app_code' => self::UPSTREAM_APP_CODE, 'upstream_api_code' => self::SUBMIT_API_CODE, 'status' => 1])->findOrEmpty();
        if ($product->isEmpty()) throw new Exception('所选 nano-banana 应用 API 已下架');
        if (!self::queryAvailable()) throw new Exception('nano-banana 应用 API 查询接口已下架');
        $quality = self::quality($selection); $matches = [];
        foreach (self::availableSkus($tenantId, $productId) as $market) {
            $locked = self::arrayValue($market['sku']['locked_params'] ?? []);
            $lockedModel = trim((string)($locked['model'] ?? ''));
            $lockedQuality = strtolower(trim((string)($locked['resolution'] ?? '')));
            if ($lockedModel === $model && ($quality === '' || $lockedQuality === $quality || ($lockedQuality === '' && $quality === '1k'))) $matches[] = $market;
        }
        if ($matches === []) throw new Exception($quality === '' ? '所选 nano-banana 模型暂无可用规格' : '所选 nano-banana 模型不支持 ' . self::qualityLabel($quality));
        $market = $matches[0];
        return ['product' => (array)$product->toArray(), 'sku' => (array)$market['sku'], 'tenant_price' => (float)$market['tenant_price'], 'model' => $model, 'reference_limit' => max(0, (int)(self::capabilities($product->toArray())['max_reference_images'] ?? 0))];
    }

    /** @return array<int,array{sku:array<string,mixed>,tenant_price:float}> */
    private static function availableSkus(int $tenantId, int $productId): array
    {
        $result = [];
        foreach (PowerMarketSku::where(['product_id' => $productId, 'status' => 1, 'sale_status' => 1])->select()->toArray() as $sku) {
            $tenant = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => (int)$sku['id']])->findOrEmpty();
            if (!$tenant->isEmpty() && (int)$tenant['sale_status'] !== 1) continue;
            $result[] = ['sku' => $sku, 'tenant_price' => $tenant->isEmpty() ? (float)$sku['sale_points'] : (float)$tenant['sale_points']];
        }
        return $result;
    }

    private static function payload(array $snapshot, array $request, string $idempotencyKey): array
    {
        $locked = self::arrayValue($snapshot['locked_params'] ?? []);
        $references = array_values(array_filter((array)($request['reference_images'] ?? [])));
        return array_filter(array_merge($locked, [
            'action' => $references === [] ? 'generate' : 'edit', 'prompt' => (string)($request['prompt'] ?? ''), 'image_urls' => $references,
            'resolution' => self::qualityLabel((string)($locked['resolution'] ?? $request['quality'] ?? '1k')),
            'aspect_ratio' => (string)($request['ratio'] ?? 'auto'), 'idempotency_key' => $idempotencyKey,
        ]), static fn($value) => $value !== '' && $value !== null && $value !== []);
    }

    /** @return array<int,array<string,mixed>> */
    private static function images(array $data, int $tenantId, int $userId): array
    {
        $root = self::arrayValue($data['data'] ?? $data); $urls = [];
        foreach ([$data, $root, self::arrayValue($root['response'] ?? [])] as $payload) foreach (['image_url', 'url'] as $key) if (is_string($payload[$key] ?? null) && trim((string)$payload[$key]) !== '') $urls[] = (string)$payload[$key];
        foreach ([$data['data'] ?? [], $root['images'] ?? [], $root['image_urls'] ?? [], $root['results'] ?? [], $root['result'] ?? []] as $rows) foreach ((array)$rows as $row) { $url = is_string($row) ? $row : (string)($row['image_url'] ?? $row['url'] ?? ''); if ($url !== '') $urls[] = $url; }
        $images = [];
        foreach (array_values(array_unique($urls)) as $url) { $stored = AigcImageAssetService::persistGeneratedImage($url, $tenantId, $userId); $images[] = ['image_uri' => (string)$stored['uri'], 'width' => (int)($stored['width'] ?? 0), 'height' => (int)($stored['height'] ?? 0), 'storage_scope' => (string)($stored['storage_scope'] ?? 'tenant'), 'storage_engine' => (string)($stored['storage_engine'] ?? ''), 'storage_domain' => (string)($stored['storage_domain'] ?? '')]; }
        return $images;
    }

    private static function endpoint(string $appCode, string $apiCode): string { return self::origin() . '/api/v1/apps/' . rawurlencode($appCode ?: self::UPSTREAM_APP_CODE) . '/' . rawurlencode($apiCode); }
    private static function queryAvailable(): bool
    {
        return !PowerMarketProduct::where([
            'resource_type' => PowerMarketService::TYPE_APP_API,
            'upstream_app_code' => self::UPSTREAM_APP_CODE,
            'upstream_api_code' => self::QUERY_API_CODE,
            'status' => 1,
        ])->findOrEmpty()->isEmpty();
    }
    private static function origin(): string { $source = UpdateSourceClient::getSource(); $parts = parse_url(trim((string)($source['active_base_url'] ?? $source['base_url'] ?? ''))); $host = (string)($parts['host'] ?? ''); if ($host === '') throw new Exception('nano-banana 应用 API 暂不可用'); return (string)($parts['scheme'] ?? 'https') . '://' . $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : ''); }
    private static function request(string $method, string $url, array $payload = []): array { $source = UpdateSourceClient::getSource(); $key = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? '')); if ($key === '') throw new Exception('nano-banana 应用 API 暂不可用'); $ch = curl_init(); curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Accept: application/json', 'Content-Type: application/json'], CURLOPT_SSL_VERIFYPEER => UpdateSourceClient::sslVerify($source), CURLOPT_SSL_VERIFYHOST => UpdateSourceClient::sslVerify($source) ? 2 : 0]); if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); } $body = curl_exec($ch); $errno = curl_errno($ch); $error = curl_error($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); if ($errno) throw new Exception($error ?: 'nano-banana 应用网络请求失败'); $data = json_decode((string)$body, true); if (!is_array($data)) throw new Exception('nano-banana 应用响应格式错误'); if ($http >= 400 || isset($data['error']) || (isset($data['code']) && (int)$data['code'] !== 1)) throw new Exception(self::error($data)); return $data; }
    private static function snapshot(array $market): array { $product = $market['product']; $sku = $market['sku']; return ['product_id' => (int)$product['id'], 'sku_id' => (int)$sku['id'], 'sku_key' => (string)$sku['sku_key'], 'app_code' => (string)$product['upstream_app_code'], 'api_code' => (string)$product['upstream_api_code'], 'model_code' => (string)$market['model'], 'locked_params' => self::arrayValue($sku['locked_params'] ?? []), 'usage_unit' => (string)$sku['usage_unit'], 'usage_unit_size' => MarketUsageSettlementService::unitSize($sku), 'upstream_price' => (float)$sku['upstream_price'], 'platform_price' => (float)$sku['sale_points'], 'tenant_price' => (float)$market['tenant_price'], 'max_reference_images' => (int)$market['reference_limit']]; }
    private static function selectionId(int $productId, string $model): string { return 'market_nano_banana:' . $productId . ':' . base64_encode($model); }
    /** @return array{0:int,1:string} */ private static function selection(array $selection): array { $nested = self::arrayValue($selection['params'] ?? []); foreach ([$selection['model_id'] ?? '', $selection['image_model_id'] ?? '', $selection['channel'] ?? '', $selection['channel_code'] ?? '', $nested['model_id'] ?? ''] as $value) if (is_string($value) && preg_match('/^market_nano_banana:(\\d+):(.+)$/', $value, $match)) return [(int)$match[1], (string)base64_decode($match[2], true)]; return [0, '']; }
    private static function quality(array $selection): string { $nested = self::arrayValue($selection['params'] ?? []); foreach (['quality', 'resolution'] as $key) { $value = strtolower(trim((string)($selection[$key] ?? $nested[$key] ?? ''))); if ($value !== '') return $value; } return ''; }
    private static function qualityLabel(string $value): string { return strtoupper(trim($value)) ?: '1K'; }
    private static function displayName(string $model): string { return match ($model) { 'nano-banana' => 'Nano Banana', 'nano-banana-2' => 'Nano Banana 2', 'nano-banana-2-lite' => 'Nano Banana 2 Lite', 'nano-banana-pro' => 'Nano Banana Pro', 'nano-banana:official' => 'Nano Banana Official', 'nano-banana-2-lite:official' => 'Nano Banana 2 Lite Official', 'nano-banana-2:official' => 'Nano Banana 2 Official', 'nano-banana-pro:official' => 'Nano Banana Pro Official', default => $model }; }
    private static function capabilities(array $product): array { $source = self::arrayValue($product['source_payload'] ?? []); $resource = self::arrayValue($source['raw']['resource'] ?? $source['resource'] ?? []); return array_merge(self::arrayValue($resource['capabilities'] ?? []), $resource); }
    private static function requestSummary(array $request): array { return ['prompt_length' => mb_strlen((string)($request['prompt'] ?? '')), 'reference_image_count' => count((array)($request['reference_images'] ?? [])), 'ratio' => (string)($request['ratio'] ?? ''), 'quality' => (string)($request['quality'] ?? '')]; }
    private static function assertReferences(array $market, array $request): void { $count = count(array_filter((array)($request['reference_images'] ?? []))); $limit = (int)$market['reference_limit']; if ($count > $limit) throw new Exception($limit > 0 ? '所选 nano-banana 模型最多支持 ' . $limit . ' 张参考图' : '所选 nano-banana 模型不支持参考图'); }
    private static function taskId(array $data): string { $root = self::arrayValue($data['data'] ?? $data); foreach ([$data['task_id'] ?? null, $data['id'] ?? null, $root['task_id'] ?? null, $root['id'] ?? null] as $value) if (is_scalar($value) && (string)$value !== '') return (string)$value; return ''; }
    private static function requestId(array $data): string { $root = self::arrayValue($data['data'] ?? $data); return (string)($data['request_id'] ?? $root['request_id'] ?? ''); }
    private static function status(array $data): string { $root = self::arrayValue($data['data'] ?? $data); return strtolower((string)($data['status'] ?? $root['status'] ?? $root['state'] ?? '')); }
    private static function error(array $data): string { $root = self::arrayValue($data['data'] ?? $data); return mb_substr((string)($data['message'] ?? $data['msg'] ?? $root['message'] ?? $root['msg'] ?? $data['error']['message'] ?? 'nano-banana 应用调用失败'), 0, 1000); }
    private static function response(array $consumption): array { $summary = self::arrayValue($consumption['response_summary'] ?? []); return ['status' => (string)$consumption['run_status'], 'provider_task_id' => (string)$consumption['upstream_task_id'], 'provider_request_id' => (string)$consumption['upstream_request_id'], 'images' => (array)($summary['images'] ?? [])]; }
    private static function context(int $consumptionId, bool $lock): ?array { $query = AiConsumptionLog::where('id', $consumptionId); if ($lock) $query->lock(true); $consumption = $query->findOrEmpty(); if ($consumption->isEmpty()) return null; $taskQuery = AiAppTask::where('id', (int)$consumption['app_task_id']); if ($lock) $taskQuery->lock(true); $task = $taskQuery->findOrEmpty(); return $task->isEmpty() ? null : ['consumption' => $consumption, 'app_task' => $task]; }
    private static function event(int $consumptionId, string $type, string $status, array $summary): void { AiConsumptionEvent::create(['consumption_id' => $consumptionId, 'event_type' => $type, 'event_status' => $status, 'attempt_no' => 1, 'payload_summary' => $summary, 'payload_ciphertext' => '', 'http_status' => 0, 'elapsed_ms' => 0, 'create_time' => time()]); }
    private static function extra(AiAppTask $task, AiConsumptionLog $consumption, string $stage): array { return ['app_code' => self::APP_CODE, 'app_task_id' => (int)$task['id'], 'app_task_no' => (string)$task['task_no'], 'consumption_id' => (int)$consumption['id'], 'consume_no' => (string)$consumption['consume_no'], 'billing_stage' => $stage]; }
    private static function arrayValue($value): array { if (is_array($value)) return $value; if (is_string($value) && $value !== '') { $decoded = json_decode($value, true); return is_array($decoded) ? $decoded : []; } return []; }
    private static function points(float $value): float { return round(max(0, $value), 6); }
    private static function no(string $prefix): string { return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(5))); }
}
