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

/**
 * Executes image models sold through the power market. Business apps select a
 * market SKU; this runtime owns supplier access and settlement.
 */
class MarketImageModelRuntimeService
{
    public const APP_CODE = 'aigc_short_drama';
    private const SUBMIT_PATH = '/api/v1/tasks';
    private const TASK_PATH = '/api/v1/tasks/{task_id}';

    /** @return array{key:string,label:string,type:string,options:array<int,array<string,mixed>>,default:string} */
    public static function modelGroup(int $tenantId): array
    {
        $options = self::options($tenantId);
        return [
            'key' => 'image',
            'label' => '生图模型',
            'type' => 'image',
            'description' => '通过算力市场图片模型生成主体图、场景图和分镜图',
            'options' => $options,
            'default' => (string)($options[0]['id'] ?? ''),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function options(int $tenantId): array
    {
        $products = PowerMarketProduct::where([
            'resource_type' => PowerMarketService::TYPE_MODEL,
            'model_type' => 'image',
            'status' => 1,
        ])->order(['update_time' => 'desc', 'id' => 'desc'])->select()->toArray();
        $options = [];
        foreach ($products as $product) {
            $meta = self::metadata($product);
            $skus = [];
            foreach (self::availableSkus($tenantId, (int)$product['id']) as $market) {
                $sku = $market['sku'];
                $locked = self::arrayValue($sku['locked_params'] ?? []);
                $quality = self::firstValue($locked, ['quality', 'resolution', 'image_size']);
                $skus[] = [
                    'market_sku_id' => (int)$sku['id'],
                    'sku_key' => (string)$sku['sku_key'],
                    'title' => (string)$sku['title'],
                    'quality' => $quality,
                    'resolution' => $quality,
                    'ratio_options' => self::ratioOptions($locked, $meta),
                    'locked_params' => $locked,
                    'platform_unit_cost' => self::points((float)$sku['sale_points']),
                    'tenant_unit_price' => self::points((float)$market['tenant_price']),
                    'usage_unit' => (string)$sku['usage_unit'],
                    'usage_unit_size' => MarketUsageSettlementService::unitSize($sku),
                    'settlement_mode' => MarketUsageSettlementService::isActualUsageSku($sku) ? 'actual_usage' : 'reserved',
                ];
            }
            if ($skus === []) {
                continue;
            }
            $qualities = array_values(array_unique(array_filter(array_map(static fn(array $item): string => (string)$item['quality'], $skus))));
            $ratios = array_values(array_unique(array_merge(...array_map(static fn(array $item): array => (array)$item['ratio_options'], $skus))));
            $first = $skus[0];
            $modelId = self::modelId((int)$product['id']);
            $options[] = [
                'id' => $modelId,
                'value' => $modelId,
                'market_product_id' => (int)$product['id'],
                'market_sku_id' => 0,
                'name' => (string)$product['name'],
                'model_code' => (string)$product['upstream_model_code'],
                'channel_code' => $modelId,
                'provider_model' => (string)$product['upstream_model_code'],
                'quality_options' => $qualities,
                'resolution_options' => array_map(static fn(string $quality): array => ['value' => $quality, 'label' => self::qualityLabel($quality)], $qualities),
                'ratio_options' => $ratios,
                'default_quality' => (string)($qualities[0] ?? ''),
                'default_ratio' => (string)($first['ratio_options'][0] ?? ''),
                'skus' => $skus,
                'max_reference_images' => self::referenceLimit($meta),
                'supports_reference_images' => self::referenceLimit($meta) > 0,
                'platform_unit_cost' => min(array_column($skus, 'platform_unit_cost')),
                'tenant_unit_price' => min(array_column($skus, 'tenant_unit_price')),
                'usage_unit' => (string)$first['usage_unit'],
                'enabled' => true,
                'sort' => (int)$product['id'],
            ];
        }
        return $options;
    }

    /** @return array<string, mixed> */
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
            'price_source' => 'power_market_sku',
            'market_product_id' => (int)$market['product']['id'],
            'market_sku_id' => (int)$market['sku']['id'],
            'market_snapshot' => self::snapshot($market),
        ];
    }

    /** @return array{app_task_id:int,consumption_id:int,consume_no:string,market_snapshot:array<string,mixed>} */
    public static function reserve(
        int $tenantId,
        int $userId,
        string $action,
        string $businessTaskId,
        array $selection,
        array $request,
        int $quantity = 1
    ): array {
        $market = self::resolve($tenantId, $selection);
        $quantity = max(1, $quantity);
        self::assertReferenceImagesAllowed($market, $request);
        $deferredUsage = MarketUsageSettlementService::isActualUsageSku($market['sku']);
        $tenantCost = $deferredUsage ? 0 : self::points((float)$market['sku']['sale_points'] * $quantity);
        $userPrice = $deferredUsage ? 0 : self::points((float)$market['tenant_price'] * $quantity);
        if (!$deferredUsage) PointService::assertCanConsumeAmounts($tenantId, $userId, $tenantCost, $userPrice);

        return Db::transaction(function () use ($tenantId, $userId, $action, $businessTaskId, $request, $quantity, $market, $tenantCost, $userPrice, $deferredUsage) {
            $now = time();
            $appTask = AiAppTask::create([
                'task_no' => self::no('AT'), 'tenant_id' => $tenantId, 'user_id' => $userId,
                'app_code' => self::APP_CODE, 'action_code' => $action,
                'business_table' => 'aigc_short_drama_generation_task', 'business_id' => 0, 'parent_task_id' => 0,
                'status' => 'running', 'progress' => 10,
                'request_summary' => self::requestSummary($request), 'result_summary' => [],
                'estimated_tenant_cost' => $tenantCost, 'estimated_user_price' => $userPrice,
                'actual_tenant_cost' => 0, 'actual_user_price' => 0,
                'idempotency_key' => sha1($tenantId . '|' . $businessTaskId . '|image|' . microtime(true)),
                'create_time' => $now, 'update_time' => $now, 'finish_time' => 0,
            ]);
            $consumeNo = self::no('C');
            $consumption = AiConsumptionLog::create([
                'consume_no' => $consumeNo, 'app_task_id' => (int)$appTask['id'], 'tenant_id' => $tenantId, 'user_id' => $userId,
                'app_code' => self::APP_CODE, 'action_code' => $action, 'resource_type' => 'model',
                'product_id' => (int)$market['product']['id'], 'sku_id' => (int)$market['sku']['id'],
                'model_code' => (string)$market['product']['upstream_model_code'], 'api_code' => (string)$market['product']['upstream_channel_code'],
                'protocol' => 'image_generate', 'provider' => 'power_market', 'upstream_request_id' => '', 'upstream_task_id' => '',
                'quantity' => $deferredUsage ? 0 : $quantity, 'usage_unit' => (string)$market['sku']['usage_unit'], 'usage_snapshot' => ['settlement_basis' => $deferredUsage ? 'awaiting_actual_usage' : 'submit_snapshot'],
                'price_snapshot' => self::snapshot($market), 'request_summary' => self::requestSummary($request), 'response_summary' => [],
                'run_status' => 'reserved', 'billing_status' => $deferredUsage ? 'pending_usage' : 'reserved',
                'reserved_tenant_cost' => $tenantCost, 'reserved_user_price' => $userPrice,
                'actual_tenant_cost' => 0, 'actual_user_price' => 0,
                'tenant_point_sn' => $consumeNo . '-reserve', 'user_point_sn' => $consumeNo . '-reserve',
                'error_code' => '', 'error_message' => '', 'refresh_requested_at' => 0,
                'create_time' => $now, 'update_time' => $now, 'finish_time' => 0,
            ]);
            if (!$deferredUsage) PointService::reserveBusinessAmountsInCurrentTransaction($tenantId, $userId, $tenantCost, $userPrice, $consumeNo . '-reserve', '短剧图片模型预占', self::extra($appTask, $consumption, 'reserved'));
            self::event((int)$consumption['id'], 'reserve', 'success', ['quantity' => $quantity, 'settlement_mode' => $deferredUsage ? 'actual_usage' : 'reserved']);
            return ['app_task_id' => (int)$appTask['id'], 'consumption_id' => (int)$consumption['id'], 'consume_no' => $consumeNo, 'market_snapshot' => self::snapshot($market)];
        });
    }

    /** @return array<string,mixed> */
    public static function submit(int $consumptionId, array $request): array
    {
        $context = self::context($consumptionId, false);
        if ($context === null) {
            throw new Exception('市场图片消耗记录不存在');
        }
        $consumption = $context['consumption'];
        if ((string)$consumption['run_status'] !== 'reserved') {
            return self::responseFromConsumption($consumption->toArray());
        }
        $price = (array)$consumption['price_snapshot'];
        $payload = self::payload($price, $request, (string)$consumption['consume_no']);
        $started = microtime(true);
        try {
            $response = self::request('POST', self::origin() . self::SUBMIT_PATH, $payload);
            $taskId = self::taskId($response);
            $images = self::images($response, (int)$consumption['tenant_id'], (int)$consumption['user_id']);
            $requestId = self::requestId($response);
            Db::transaction(function () use ($consumptionId, $taskId, $images, $requestId, $response, $started) {
                $ctx = self::context($consumptionId, true); if ($ctx === null) return;
                $c = $ctx['consumption'];
                if (!in_array((string)$c['billing_status'], ['reserved', 'pending_usage'], true)) return;
                $status = $images === [] ? 'running' : 'success';
                $c->save(['run_status' => $status, 'upstream_task_id' => $taskId, 'upstream_request_id' => $requestId, 'response_summary' => ['image_count' => count($images)], 'update_time' => time()]);
                self::event((int)$c['id'], 'submit', 'success', ['upstream_task_id' => $taskId, 'image_count' => count($images)], (int)round((microtime(true) - $started) * 1000));
            });
            if ($images !== []) {
                self::settle($consumptionId, $images, $requestId, $taskId, $response);
                AiTaskJobService::enqueueProcessResult($consumptionId);
            }
            else AiTaskJobService::enqueueQueryResult($consumptionId);
            return ['status' => $images === [] ? 'running' : 'success', 'provider_task_id' => $taskId, 'provider_request_id' => $requestId, 'images' => $images];
        } catch (\Throwable $e) {
            self::fail($consumptionId, $e->getMessage(), 'submit_failed');
            throw $e instanceof Exception ? $e : new Exception('图片模型提交失败');
        }
    }

    /** @return array<string,mixed> */
    public static function refresh(int $consumptionId): array
    {
        $context = self::context($consumptionId, false);
        if ($context === null) throw new Exception('市场图片消耗记录不存在');
        $c = $context['consumption'];
        if (!in_array((string)$c['billing_status'], ['reserved', 'pending_usage'], true)) return self::responseFromConsumption($c->toArray());
        $taskId = trim((string)$c['upstream_task_id']);
        if ($taskId === '') {
            return self::responseFromConsumption($c->toArray());
        }
        try {
            $response = self::request('GET', self::origin() . str_replace('{task_id}', rawurlencode($taskId), self::TASK_PATH));
            $status = self::status($response); $images = self::images($response, (int)$c['tenant_id'], (int)$c['user_id']);
            if ($images !== []) { self::settle($consumptionId, $images, self::requestId($response), $taskId, $response); return ['status' => 'success', 'provider_task_id' => $taskId, 'images' => $images]; }
            if (in_array($status, ['failed', 'error', 'canceled', 'cancelled'], true)) { self::fail($consumptionId, self::error($response), 'upstream_failed'); return ['status' => 'failed', 'provider_task_id' => $taskId, 'images' => []]; }
            self::event($consumptionId, 'poll', 'running', ['upstream_task_id' => $taskId]);
            return ['status' => 'running', 'provider_task_id' => $taskId, 'images' => []];
        } catch (\Throwable $e) {
            self::recordRefreshError($consumptionId, $taskId, $e);
            return ['status' => 'running', 'provider_task_id' => $taskId, 'images' => []];
        }
    }

    public static function fail(int $consumptionId, string $message, string $code = 'failed'): void
    {
        Db::transaction(function () use ($consumptionId, $message, $code) {
            $ctx = self::context($consumptionId, true); if ($ctx === null) return;
            $c = $ctx['consumption']; $task = $ctx['app_task'];
            if (in_array((string)$c['billing_status'], ['settled', 'refunded'], true)) return;
            if ((float)$c['reserved_tenant_cost'] > 0 || (float)$c['reserved_user_price'] > 0) PointService::releaseReservedBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], (float)$c['reserved_tenant_cost'], (float)$c['reserved_user_price'], (string)$c['consume_no'] . '-release', '短剧图片模型失败退回', self::extra($task, $c, 'refunded'));
            $now = time(); $c->save(['run_status' => 'failed', 'billing_status' => 'refunded', 'error_code' => $code, 'error_message' => mb_substr($message, 0, 1000), 'finish_time' => $now, 'update_time' => $now]);
            $task->save(['status' => 'failed', 'progress' => 100, 'result_summary' => ['error' => mb_substr($message, 0, 500)], 'finish_time' => $now, 'update_time' => $now]);
            self::event((int)$c['id'], 'refund', 'success', ['reason' => $message]);
        });
    }

    /**
     * A submitted supplier task can still incur cost after a client disconnects,
     * so only a reservation that has not been submitted can be canceled.
     */
    public static function cancel(int $consumptionId): void
    {
        $context = self::context($consumptionId, false);
        if ($context === null) {
            throw new Exception('市场图片消耗记录不存在');
        }
        $consumption = $context['consumption'];
        if (!in_array((string)$consumption['billing_status'], ['reserved', 'pending_usage'], true)) {
            return;
        }
        if ((string)$consumption['run_status'] !== 'reserved') {
            throw new Exception('图片任务已提交至模型服务，无法取消');
        }
        Db::transaction(function () use ($consumptionId) {
            $ctx = self::context($consumptionId, true);
            if ($ctx === null) {
                return;
            }
            $consumption = $ctx['consumption'];
            $task = $ctx['app_task'];
            if (!in_array((string)$consumption['billing_status'], ['reserved', 'pending_usage'], true) || (string)$consumption['run_status'] !== 'reserved') {
                return;
            }
            if ((float)$consumption['reserved_tenant_cost'] > 0 || (float)$consumption['reserved_user_price'] > 0) PointService::releaseReservedBusinessAmountsInCurrentTransaction(
                (int)$consumption['tenant_id'],
                (int)$consumption['user_id'],
                (float)$consumption['reserved_tenant_cost'],
                (float)$consumption['reserved_user_price'],
                (string)$consumption['consume_no'] . '-release',
                '短剧图片模型取消退回',
                self::extra($task, $consumption, 'refunded')
            );
            $now = time();
            $consumption->save(['run_status' => 'canceled', 'billing_status' => 'refunded', 'error_code' => 'canceled', 'error_message' => '用户取消任务', 'finish_time' => $now, 'update_time' => $now]);
            $task->save(['status' => 'canceled', 'progress' => 100, 'result_summary' => ['error' => '用户取消任务'], 'finish_time' => $now, 'update_time' => $now]);
            self::event((int)$consumption['id'], 'cancel', 'success', []);
        });
    }

    public static function linkBusinessTask(int $appTaskId, int $businessId): void
    {
        if ($appTaskId > 0 && $businessId > 0) {
            AiAppTask::where('id', $appTaskId)->update([
                'business_id' => $businessId,
                'update_time' => time(),
            ]);
        }
    }

    private static function settle(int $consumptionId, array $images, string $requestId, string $taskId, array $response): void
    {
        Db::transaction(function () use ($consumptionId, $images, $requestId, $taskId, $response) {
            $ctx = self::context($consumptionId, true); if ($ctx === null) return;
            $c = $ctx['consumption']; $task = $ctx['app_task'];
            if (!in_array((string)$c['billing_status'], ['reserved', 'pending_usage'], true)) return;
            $quantity = max(1, count($images)); $snapshot = (array)$c['price_snapshot'];
            $deferredUsage = MarketUsageSettlementService::isActualUsageSku($snapshot);
            $actualUsage = MarketUsageSettlementService::tokenUsage($response);
            if ($deferredUsage && $actualUsage <= 0) {
                $now = time();
                $c->save(['run_status' => 'success', 'billing_status' => 'pending_usage', 'upstream_request_id' => $requestId ?: (string)$c['upstream_request_id'], 'upstream_task_id' => $taskId ?: (string)$c['upstream_task_id'], 'usage_snapshot' => ['settlement_basis' => 'awaiting_actual_usage'], 'response_summary' => ['image_count' => $quantity, 'images' => $images], 'finish_time' => $now, 'update_time' => $now]);
                $task->save(['status' => 'success', 'progress' => 100, 'finish_time' => $now, 'update_time' => $now]);
                self::event((int)$c['id'], 'await_usage', 'pending', ['image_count' => $quantity]);
                return;
            }
            $billedQuantity = $deferredUsage ? $actualUsage : $quantity;
            $tenant = $deferredUsage ? MarketUsageSettlementService::price((float)($snapshot['platform_price'] ?? 0), $billedQuantity, $snapshot) : self::points((float)($snapshot['platform_price'] ?? 0) * $quantity);
            $user = $deferredUsage ? MarketUsageSettlementService::price((float)($snapshot['tenant_price'] ?? 0), $billedQuantity, $snapshot) : self::points((float)($snapshot['tenant_price'] ?? 0) * $quantity);
            if ($deferredUsage) { PointService::assertCanConsumeAmounts((int)$c['tenant_id'], (int)$c['user_id'], $tenant, $user); PointService::consumeBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], $tenant, $user, (string)$c['consume_no'], '短剧图片模型实际用量结算', self::extra($task, $c, 'settled')); } else PointService::settleReservedBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], (float)$c['reserved_tenant_cost'], (float)$c['reserved_user_price'], $tenant, $user, (string)$c['consume_no'], '短剧图片模型结算', self::extra($task, $c, 'settled'));
            $now = time(); $c->save(['run_status' => 'success', 'billing_status' => 'settled', 'upstream_request_id' => $requestId ?: (string)$c['upstream_request_id'], 'upstream_task_id' => $taskId ?: (string)$c['upstream_task_id'], 'quantity' => $billedQuantity, 'usage_snapshot' => ['image_count' => $quantity, 'actual_token_usage' => $deferredUsage ? $actualUsage : 0], 'response_summary' => ['image_count' => $quantity, 'images' => $images], 'actual_tenant_cost' => $tenant, 'actual_user_price' => $user, 'tenant_point_sn' => $deferredUsage ? (string)$c['consume_no'] : (string)$c['consume_no'] . '-reserve', 'user_point_sn' => $deferredUsage ? (string)$c['consume_no'] : (string)$c['consume_no'] . '-reserve', 'finish_time' => $now, 'update_time' => $now]);
            $task->save(['status' => 'success', 'progress' => 100, 'actual_tenant_cost' => $tenant, 'actual_user_price' => $user, 'finish_time' => $now, 'update_time' => $now]);
            self::event((int)$c['id'], 'settle', 'success', ['image_count' => $quantity]);
        });
    }

    /** @return array{product:array<string,mixed>,sku:array<string,mixed>,tenant_price:float} */
    private static function resolve(int $tenantId, array $selection): array
    {
        $skuId = self::skuId($selection);
        if ($skuId > 0) {
            $sku = PowerMarketSku::where(['id' => $skuId, 'status' => 1, 'sale_status' => 1])->findOrEmpty();
            if ($sku->isEmpty()) throw new Exception('所选图片模型规格已下架');
            $product = PowerMarketProduct::where(['id' => (int)$sku['product_id'], 'resource_type' => 'model', 'model_type' => 'image', 'status' => 1])->findOrEmpty();
            if ($product->isEmpty()) throw new Exception('所选图片模型已下架');
            return self::marketRow($tenantId, $product->toArray(), $sku->toArray());
        }

        $productId = self::productId($selection);
        if ($productId <= 0) throw new Exception('请选择算力市场图片模型');
        $product = PowerMarketProduct::where(['id' => $productId, 'resource_type' => 'model', 'model_type' => 'image', 'status' => 1])->findOrEmpty();
        if ($product->isEmpty()) throw new Exception('所选图片模型已下架');
        $quality = self::selectionQuality($selection);
        $matches = [];
        foreach (self::availableSkus($tenantId, $productId) as $market) {
            $sku = (array)$market['sku'];
            $lockedQuality = self::firstValue(self::arrayValue($sku['locked_params'] ?? []), ['quality', 'resolution', 'image_size']);
            if ($quality === '' || $lockedQuality === $quality) {
                $matches[] = $market;
            }
        }
        if ($matches === []) {
            throw new Exception($quality === '' ? '所选图片模型暂无可用规格' : '所选图片模型不支持 ' . $quality . ' 分辨率');
        }
        return self::marketRow($tenantId, $product->toArray(), (array)$matches[0]['sku']);
    }

    /** @return array<int,array{sku:array<string,mixed>,tenant_price:float}> */
    private static function availableSkus(int $tenantId, int $productId): array
    {
        $rows = PowerMarketSku::where(['product_id' => $productId, 'status' => 1, 'sale_status' => 1])->select()->toArray(); $result = [];
        foreach ($rows as $sku) { $tenant = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => (int)$sku['id']])->findOrEmpty(); if (!$tenant->isEmpty() && (int)$tenant['sale_status'] !== 1) continue; $result[] = ['sku' => $sku, 'tenant_price' => $tenant->isEmpty() ? (float)$sku['sale_points'] : (float)$tenant['sale_points']]; }
        return $result;
    }

    private static function payload(array $snapshot, array $request, string $idempotencyKey): array
    {
        $locked = self::arrayValue($snapshot['locked_params'] ?? []); $params = array_merge($locked, (array)($request['provider_params'] ?? []));
        $payload = array_filter([
            'model' => (string)$snapshot['model_code'], 'channel' => (string)$snapshot['channel_code'], 'n' => max(1, (int)($request['quantity'] ?? 1)),
            'prompt' => (string)($request['prompt'] ?? ''), 'negative_prompt' => (string)($request['negative_prompt'] ?? ''),
            'image_urls' => array_values(array_filter((array)($request['reference_images'] ?? []))),
            'aspect_ratio' => (string)($request['ratio'] ?? $params['aspect_ratio'] ?? $params['ratio'] ?? ''),
            'resolution' => (string)($request['quality'] ?? $params['resolution'] ?? $params['quality'] ?? ''),
            'image_size' => (string)($params['image_size'] ?? $params['resolution'] ?? ''), 'idempotency_key' => $idempotencyKey,
        ], static fn($v) => $v !== '' && $v !== [] && $v !== null);
        return array_merge($payload, $params);
    }

    private static function origin(): string
    {
        $source = UpdateSourceClient::getSource(); $base = trim((string)($source['active_base_url'] ?? $source['base_url'] ?? '')); $parts = parse_url($base); $host = (string)($parts['host'] ?? '');
        if ($host === '') throw new Exception('模型 API 暂不可用'); return (string)($parts['scheme'] ?? 'https') . '://' . $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
    }

    private static function request(string $method, string $url, array $payload = []): array
    {
        $source = UpdateSourceClient::getSource(); $key = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? '')); if ($key === '') throw new Exception('模型 API 暂不可用');
        $ch = curl_init(); $headers = ['Authorization: Bearer ' . $key, 'Accept: application/json', 'Content-Type: application/json'];
        curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => UpdateSourceClient::sslVerify($source), CURLOPT_SSL_VERIFYHOST => UpdateSourceClient::sslVerify($source) ? 2 : 0]);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
        $body = curl_exec($ch); $errno = curl_errno($ch); $error = curl_error($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($errno) throw new Exception($error ?: '图片模型网络请求失败'); $data = json_decode((string)$body, true); if (!is_array($data)) throw new Exception('图片模型响应格式错误');
        if ($http >= 400 || isset($data['error']) || (isset($data['code']) && (int)$data['code'] !== 1)) throw new Exception(self::error($data));
        return is_array($data['data'] ?? null) ? $data['data'] : $data;
    }

    private static function recordRefreshError(int $consumptionId, string $taskId, \Throwable $e): void
    {
        $message = mb_substr($e->getMessage() ?: '图片任务查询失败', 0, 1000);
        try {
            AiConsumptionLog::where('id', $consumptionId)->update([
                'error_code' => 'refresh_retrying',
                'error_message' => $message,
                'refresh_requested_at' => time(),
                'update_time' => time(),
            ]);
            self::event($consumptionId, 'poll', 'retrying', [
                'upstream_task_id' => $taskId,
                'error' => mb_substr($message, 0, 300),
            ]);
        } catch (\Throwable) {
            // Diagnostics must not interrupt the next provider retry.
        }
    }

    /** @return array<int,array<string,mixed>> */
    private static function images(array $data, int $tenantId, int $userId): array
    {
        $urls = [];
        foreach ([
            $data['images'] ?? [],
            $data['data'] ?? [],
            $data['results'] ?? [],
            $data['result']['images'] ?? [],
            $data['result']['results'] ?? [],
            $data['output'] ?? [],
        ] as $rows) {
            foreach ((array)$rows as $item) {
                $url = is_string($item) ? $item : (string)($item['url'] ?? $item['image_url'] ?? $item['uri'] ?? '');
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }
        $result = [];
        foreach (array_values(array_unique($urls)) as $url) {
            $stored = AigcImageAssetService::persistGeneratedImage($url, $tenantId, $userId);
            $result[] = [
                'image_uri' => (string)$stored['uri'],
                'width' => (int)($stored['width'] ?? 0),
                'height' => (int)($stored['height'] ?? 0),
                'storage_scope' => (string)($stored['storage_scope'] ?? 'tenant'),
                'storage_engine' => (string)($stored['storage_engine'] ?? 'local'),
                'storage_domain' => (string)($stored['storage_domain'] ?? ''),
            ];
        }
        return $result;
    }

    private static function taskId(array $data): string { foreach ([$data['task_id'] ?? null, $data['id'] ?? null, $data['task']['id'] ?? null] as $v) if (is_scalar($v) && (string)$v !== '') return (string)$v; return ''; }
    private static function requestId(array $data): string { return (string)($data['request_id'] ?? $data['id'] ?? ''); }
    private static function status(array $data): string { return strtolower((string)($data['status'] ?? $data['state'] ?? $data['task_status'] ?? '')); }
    private static function error(array $data): string { return mb_substr((string)($data['message'] ?? $data['msg'] ?? $data['error']['message'] ?? '图片模型调用失败'), 0, 1000); }
    private static function marketRow(int $tenantId, array $product, array $sku): array { $tenant = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => (int)$sku['id']])->findOrEmpty(); if (!$tenant->isEmpty() && (int)$tenant['sale_status'] !== 1) throw new Exception('租户未上架该图片模型规格'); return ['product' => $product, 'sku' => $sku, 'tenant_price' => $tenant->isEmpty() ? (float)$sku['sale_points'] : (float)$tenant['sale_points'], 'reference_limit' => self::referenceLimit(self::metadata($product))]; }
    private static function skuId(array $selection): int { $value = $selection['market_sku_id'] ?? $selection['sku_id'] ?? $selection['model_id'] ?? $selection['channel'] ?? $selection['image_model_id'] ?? ''; if (is_string($value) && str_starts_with($value, 'market_image_model:')) return 0; return (int)(is_string($value) ? preg_replace('/^market_sku:/', '', $value) : $value); }
    private static function modelId(int $productId): string { return 'market_image_model:' . $productId; }
    private static function productId(array $selection): int { $value = $selection['market_product_id'] ?? ''; if ((int)$value > 0) return (int)$value; $nested = self::arrayValue($selection['params'] ?? []); foreach ([$selection['model_id'] ?? '', $selection['image_model_id'] ?? '', $selection['channel'] ?? '', $selection['channel_code'] ?? '', $nested['model_id'] ?? ''] as $candidate) { if (is_string($candidate) && preg_match('/^market_image_model:(\\d+)$/', $candidate, $match)) return (int)$match[1]; } return 0; }
    private static function selectionQuality(array $selection): string { $nested = self::arrayValue($selection['params'] ?? []); foreach (['quality', 'resolution', 'image_size'] as $key) { $value = trim((string)($selection[$key] ?? $nested[$key] ?? '')); if ($value !== '') return $value; } return ''; }
    private static function qualityLabel(string $quality): string { return preg_replace_callback('/(\\d+)k\\b/i', static fn(array $match): string => $match[1] . 'K', $quality) ?: $quality; }
    private static function metadata(array $product): array { $source = self::arrayValue($product['source_payload'] ?? []); return (array)($source['market_metadata'] ?? []); }
    private static function referenceLimit(array $meta): int { $capabilities = self::arrayValue($meta['capabilities'] ?? []); foreach (['max_reference_images','max_reference_image_count','reference_image_limit'] as $key) { if (isset($meta[$key])) return max(0, (int)$meta[$key]); if (isset($capabilities[$key])) return max(0, (int)$capabilities[$key]); } return !empty($meta['supports_reference_images']) || !empty($capabilities['supports_reference_images']) ? 1 : 0; }
    private static function firstValue(array $params, array $keys): string { foreach ($keys as $key) if (isset($params[$key]) && $params[$key] !== '') return (string)$params[$key]; return ''; }
    private static function qualityOptions(array $locked): array { $v = self::firstValue($locked, ['quality','resolution','image_size']); return $v === '' ? [] : [$v]; }
    private static function resolutionOptions(array $locked): array { $v = self::firstValue($locked, ['quality','resolution','image_size']); return $v === '' ? [] : [['value' => $v, 'label' => $v, 'ratio_options' => self::ratioOptions($locked, [])]]; }
    private static function ratioOptions(array $locked, array $meta): array { $v = self::firstValue($locked, ['ratio','aspect_ratio']); if ($v !== '') return [$v]; $schema = self::arrayValue($meta['params_schema'] ?? []); $values = $meta['supported_ratios'] ?? $meta['ratios'] ?? $schema['aspect_ratio']['options'] ?? []; if (is_string($values)) $values = preg_split('/\s*\/\s*/', $values) ?: []; return array_values(array_filter(array_map('strval', (array)$values))); }
    private static function snapshot(array $market): array { $product = $market['product']; $sku = $market['sku']; return ['product_id' => (int)$product['id'], 'sku_id' => (int)$sku['id'], 'sku_key' => (string)$sku['sku_key'], 'model_code' => (string)$product['upstream_model_code'], 'channel_code' => (string)$product['upstream_channel_code'], 'locked_params' => self::arrayValue($sku['locked_params'] ?? []), 'usage_unit' => (string)$sku['usage_unit'], 'usage_unit_size' => MarketUsageSettlementService::unitSize($sku), 'upstream_price' => (float)$sku['upstream_price'], 'platform_price' => (float)$sku['sale_points'], 'tenant_price' => (float)$market['tenant_price'], 'max_reference_images' => (int)($market['reference_limit'] ?? 0)]; }
    private static function requestSummary(array $request): array { return ['prompt_length' => mb_strlen((string)($request['prompt'] ?? '')), 'reference_image_count' => count((array)($request['reference_images'] ?? [])), 'ratio' => (string)($request['ratio'] ?? ''), 'quality' => (string)($request['quality'] ?? '')]; }
    private static function assertReferenceImagesAllowed(array $market, array $request): void { $count = count(array_filter((array)($request['reference_images'] ?? []))); $limit = (int)($market['reference_limit'] ?? 0); if ($count > $limit) throw new Exception($limit > 0 ? '所选图片模型最多支持 ' . $limit . ' 张参考图' : '所选图片模型不支持参考图'); }
    private static function context(int $consumptionId, bool $lock): ?array { $q = AiConsumptionLog::where('id', $consumptionId); if ($lock) $q->lock(true); $c = $q->findOrEmpty(); if ($c->isEmpty()) return null; $tq = AiAppTask::where('id', (int)$c['app_task_id']); if ($lock) $tq->lock(true); $t = $tq->findOrEmpty(); return $t->isEmpty() ? null : ['consumption' => $c, 'app_task' => $t]; }
    private static function responseFromConsumption(array $c): array { $summary = self::arrayValue($c['response_summary'] ?? []); return ['status' => (string)$c['run_status'], 'provider_task_id' => (string)$c['upstream_task_id'], 'provider_request_id' => (string)$c['upstream_request_id'], 'images' => (array)($summary['images'] ?? [])]; }
    private static function event(int $id, string $type, string $status, array $summary, int $elapsed = 0): void { AiConsumptionEvent::create(['consumption_id' => $id, 'event_type' => $type, 'event_status' => $status, 'attempt_no' => 1, 'payload_summary' => $summary, 'payload_ciphertext' => '', 'http_status' => 0, 'elapsed_ms' => $elapsed, 'create_time' => time()]); }
    private static function extra(AiAppTask $task, AiConsumptionLog $consumption, string $stage): array { return ['app_code' => self::APP_CODE, 'app_task_id' => (int)$task['id'], 'app_task_no' => (string)$task['task_no'], 'consumption_id' => (int)$consumption['id'], 'consume_no' => (string)$consumption['consume_no'], 'billing_stage' => $stage]; }
    private static function arrayValue($value): array { if (is_array($value)) return $value; if (is_string($value) && $value !== '') { $decoded = json_decode($value, true); return is_array($decoded) ? $decoded : []; } return []; }
    private static function points(float $value): float { return round(max(0, $value), 6); }
    private static function no(string $prefix): string { return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(5))); }
}
