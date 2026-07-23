<?php

namespace app\common\service\power;

use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionEvent;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\model\power\TenantPowerMarketSkuPrice;
use app\common\service\ai\AiTaskJobService;
use app\common\service\ai\AiTaskResultStorageService;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_video\AigcVideoAssetService;
use app\common\service\app\aigc_video\AigcVideoReferenceAssetService;
use app\common\service\point\PointService;
use app\common\service\update\UpdateSourceClient;
use Exception;
use think\facade\Db;
use think\facade\Log;

/**
 * Internal execution core for video products sold by the power market.
 * Legacy video-channel data is intentionally never consulted here: a new
 * submission is valid only while its product and SKU are market-sellable.
 */
class MarketVideoRuntimeService
{
    private const MODEL_TASK_PATH = '/api/v1/tasks';
    private const MODEL_QUERY_PATH = '/api/v1/tasks/{task_id}';
    private const APP_CODES = ['wan', 'seedance', 'happy_horse', 'grok_video'];
    private const APP_DURATION_RANGES = [
        'happy_horse' => [3, 15],
        'seedance' => [4, 15],
    ];

    public static function options(int $tenantId, string $resourceType): array
    {
        $where = ['resource_type' => $resourceType, 'status' => 1];
        if ($resourceType === PowerMarketService::TYPE_MODEL) {
            $where['model_type'] = 'video';
        }
        $products = PowerMarketProduct::where($where)->order(['update_time' => 'desc', 'id' => 'desc'])->select()->toArray();
        $options = [];
        foreach ($products as $product) {
            if ($resourceType === PowerMarketService::TYPE_APP_API && !self::isSupportedAppProduct($product)) {
                continue;
            }
            $skus = self::availableSkus($tenantId, (int)$product['id']);
            if ($skus === []) {
                continue;
            }
            $validSkus = [];
            foreach ($skus as $market) {
                $sku = self::formatSku($market, $product);
                if (!self::isSupportedAppDuration($product, (int)$sku['duration'], (array)$sku['locked_params'])) {
                    continue;
                }
                $validSkus[] = $sku;
            }
            if ($validSkus === []) {
                continue;
            }
            $metadata = self::metadata($product);
            $id = self::optionId($resourceType, $product);
            $resolutions = array_values(array_unique(array_filter(array_map(static fn(array $row): string => (string)$row['resolution'], $validSkus))));
            $durations = array_values(array_unique(array_filter(array_map(static fn(array $row): int => (int)$row['duration'], $validSkus))));
            $metadataDurations = self::durationOptions($metadata);
            if (self::hasConfigurableDurationSku($validSkus)) {
                $durations = array_values(array_unique(array_merge($durations, $metadataDurations, self::defaultDurations($product))));
            } elseif ($durations === []) {
                $durations = array_values(array_unique(array_merge($metadataDurations, self::defaultDurations($product))));
            }
            $durations = self::filterSupportedAppDurations($product, $durations);
            sort($durations);
            $ratios = array_values(array_unique(array_filter(array_map(static fn(array $row): string => (string)($row['locked_params']['ratio'] ?? $row['locked_params']['aspect_ratio'] ?? ''), $validSkus))));
            if ($ratios === [] && strtolower((string)($product['upstream_app_code'] ?? '')) === 'grok_video') {
                $ratios = ['1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3'];
            }
            if ($ratios === []) $ratios = self::ratioOptions($metadata);
            if ($ratios === []) $ratios = ['16:9', '9:16'];
            $qualities = array_map(static function (string $resolution) use ($validSkus, $ratios): array {
                $matched = array_values(array_filter($validSkus, static fn(array $sku): bool => (string)$sku['resolution'] === $resolution));
                $firstSku = $matched[0] ?? $validSkus[0] ?? [];
                return [
                    'value' => $resolution,
                    'label' => strtoupper($resolution),
                    'resolution' => $resolution,
                    'duration' => '',
                    'ratios' => array_map(static fn(string $ratio): array => [
                        'value' => $ratio,
                        'label' => $ratio,
                        'tenant_unit_price' => (float)($firstSku['tenant_unit_price'] ?? 0),
                        'usage_unit' => (string)($firstSku['usage_unit'] ?? ''),
                    ], $ratios),
                ];
            }, $resolutions);
            $first = $validSkus[0];
            $options[] = [
                'id' => $id,
                'value' => $id,
                'resource_type' => $resourceType,
                'resource_type_label' => $resourceType === PowerMarketService::TYPE_MODEL ? '模型 API' : '应用 API',
                'market_product_id' => (int)$product['id'],
                'name' => self::displayName($product),
                'description' => (string)($product['description'] ?? ''),
                'model_code' => (string)$product['upstream_model_code'],
                'channel_code' => (string)$product['upstream_channel_code'],
                'app_code' => (string)$product['upstream_app_code'],
                'api_code' => (string)$product['upstream_api_code'],
                'resolutions' => array_map(static fn(string $value): array => ['value' => $value, 'label' => strtoupper($value)], $resolutions),
                'resolution_options' => array_map(static fn(string $value): array => ['value' => $value, 'label' => strtoupper($value)], $resolutions),
                'qualities' => $qualities,
                'ratio_options' => $ratios,
                'specs' => array_map(static fn(array $row): array => ['market_sku_id' => (int)$row['market_sku_id'], 'resolution' => (string)$row['resolution'], 'quality' => (string)$row['resolution'], 'duration' => (int)$row['duration'], 'ratio' => (string)($row['locked_params']['ratio'] ?? $row['locked_params']['aspect_ratio'] ?? ''), 'model' => (string)$row['model'], 'tenant_unit_price' => (float)$row['tenant_unit_price'], 'platform_unit_cost' => (float)$row['platform_unit_cost'], 'usage_unit' => (string)$row['usage_unit'], 'input_mode' => (string)($row['input_mode'] ?? 'text_to_video'), 'provider_params_json' => $row['locked_params']], $validSkus),
                'durations' => $durations,
                'duration_options' => $durations,
                'mode_options' => self::modeOptions($product, $validSkus),
                'input_modes' => self::inputModes($resourceType, $product, $metadata),
                'supported_asset_types' => self::supportedAssetTypes($product, $metadata),
                'max_reference_images' => self::referenceLimit($product, $metadata, 'image'),
                'max_reference_videos' => self::referenceLimit($product, $metadata, 'video'),
                'max_reference_audios' => self::referenceLimit($product, $metadata, 'audio'),
                'supports_first_last_frame' => self::supportsFirstLastFrame($metadata),
                'skus' => $validSkus,
                'default_resolution' => (string)($resolutions[0] ?? ''),
                'default_duration' => (int)($durations[0] ?? 0),
                'billing_unit' => (string)$first['usage_unit'],
                'platform_unit_cost' => min(array_column($validSkus, 'platform_unit_cost')),
                'tenant_unit_price' => min(array_column($validSkus, 'tenant_unit_price')),
                'enabled' => true,
                'sort' => (int)$product['id'],
            ];
        }
        return $options;
    }

    public static function quote(int $tenantId, array $selection): array
    {
        $market = self::resolve($tenantId, $selection);
        $quantity = self::quantity($market, $selection);
        return self::quoteMarket($market, $quantity);
    }

    /**
     * A duration locked by a market SKU is part of the supplier contract.
     * The short-drama shot duration is only a fallback for SKUs that leave
     * duration configurable.
     */
    public static function effectiveDuration(int $tenantId, array $selection, int $fallback = 0): int
    {
        // A stale shot recommendation must not make a fixed-duration SKU fail
        // selection before we have a chance to apply its locked value.
        $market = self::resolve($tenantId, $selection);
        $lockedDuration = self::duration(self::arrayValue($market['sku']['locked_params'] ?? []));
        if ($lockedDuration > 0) {
            return $lockedDuration;
        }
        $metadata = self::metadata($market['product']);
        $schema = self::arrayValue($metadata['params_schema'] ?? []);
        $durationSchema = self::arrayValue($schema['duration'] ?? $schema['seconds'] ?? $schema['video_duration'] ?? []);
        foreach (['default', 'value'] as $key) {
            if (isset($durationSchema[$key]) && is_numeric($durationSchema[$key]) && (int)$durationSchema[$key] > 0) {
                return self::preferredSupportedDuration($market['product'], (int)$durationSchema[$key]);
            }
        }
        return self::preferredSupportedDuration($market['product'], $fallback);
    }

    /** @return array{app_task_id:int,consumption_id:int,consume_no:string,market_snapshot:array<string,mixed>} */
    public static function reserve(int $tenantId, int $userId, string $appCode, string $action, string $businessTable, string $businessTaskId, array $selection, array $request): array
    {
        $market = self::resolve($tenantId, $selection);
        self::assertAssets($market, $request);
        $quantity = self::quantity($market, $selection + $request);
        $quote = self::quoteMarket($market, $quantity);
        $deferredUsage = self::isDeferredUsageSku($market['sku']);
        if (!$deferredUsage) {
            PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$quote['tenant_cost_points'], (float)$quote['user_charge_points']);
        }
        return Db::transaction(function () use ($tenantId, $userId, $appCode, $action, $businessTable, $businessTaskId, $request, $market, $quantity, $quote, $deferredUsage) {
            $now = time();
            $appTask = AiAppTask::create([
                'task_no' => self::no('AT'), 'tenant_id' => $tenantId, 'user_id' => $userId,
                'app_code' => $appCode, 'action_code' => $action, 'business_table' => $businessTable, 'business_id' => 0, 'parent_task_id' => 0,
                'status' => 'running', 'progress' => 10, 'request_summary' => self::requestSummary($request), 'result_summary' => [],
                'estimated_tenant_cost' => $quote['tenant_cost_points'], 'estimated_user_price' => $quote['user_charge_points'],
                'actual_tenant_cost' => 0, 'actual_user_price' => 0,
                'idempotency_key' => sha1($tenantId . '|' . $businessTaskId . '|' . self::selectionKey($market) . '|' . microtime(true)),
                'create_time' => $now, 'update_time' => $now, 'finish_time' => 0,
            ]);
            $consumeNo = self::no('C');
            $snapshot = self::snapshot($market, $quantity);
            $consumption = AiConsumptionLog::create([
                'consume_no' => $consumeNo, 'app_task_id' => (int)$appTask['id'], 'tenant_id' => $tenantId, 'user_id' => $userId,
                'app_code' => $appCode, 'action_code' => $action, 'resource_type' => (string)$market['product']['resource_type'],
                'product_id' => (int)$market['product']['id'], 'sku_id' => (int)$market['sku']['id'],
                'model_code' => (string)$market['product']['upstream_model_code'], 'api_code' => (string)($market['product']['upstream_api_code'] ?: $market['product']['upstream_channel_code']),
                'protocol' => (string)$snapshot['protocol'], 'provider' => 'power_market', 'upstream_request_id' => '', 'upstream_task_id' => '',
                'quantity' => $deferredUsage ? 0 : $quantity, 'usage_unit' => (string)$market['sku']['usage_unit'], 'usage_snapshot' => ['settlement_basis' => $deferredUsage ? 'awaiting_actual_usage' : 'submit_snapshot', 'quantity' => $deferredUsage ? 0 : $quantity],
                'price_snapshot' => $snapshot, 'request_summary' => self::requestSummary($request), 'response_summary' => [],
                'run_status' => 'reserved', 'billing_status' => $deferredUsage ? 'pending_usage' : 'reserved',
                'reserved_tenant_cost' => $quote['tenant_cost_points'], 'reserved_user_price' => $quote['user_charge_points'], 'actual_tenant_cost' => 0, 'actual_user_price' => 0,
                'tenant_point_sn' => $consumeNo . '-reserve', 'user_point_sn' => $consumeNo . '-reserve',
                'error_code' => '', 'error_message' => '', 'refresh_requested_at' => 0, 'create_time' => $now, 'update_time' => $now, 'finish_time' => 0,
            ]);
            if (!$deferredUsage) {
                PointService::reserveBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$quote['tenant_cost_points'], (float)$quote['user_charge_points'], $consumeNo . '-reserve', '算力市场视频预占', self::extra($appTask, $consumption, 'reserved'));
            }
            self::event((int)$consumption['id'], 'reserve', 'success', ['quantity' => $quantity, 'settlement_mode' => $deferredUsage ? 'actual_usage' : 'reserved']);
            return ['app_task_id' => (int)$appTask['id'], 'consumption_id' => (int)$consumption['id'], 'consume_no' => $consumeNo, 'market_snapshot' => $snapshot];
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
        if ($context === null) throw new Exception('市场视频消耗记录不存在');
        if ((string)$context['consumption']['run_status'] !== 'reserved') return self::response($context['consumption']->toArray());
        $snapshot = self::arrayValue($context['consumption']['price_snapshot'] ?? []);
        try {
            $response = self::submitRequest($snapshot, $request, (string)$context['consumption']['consume_no'], $consumptionId);
            $taskId = self::taskId($response);
            $videos = self::videos(
                $response,
                (int)$context['consumption']['tenant_id'],
                (int)$context['consumption']['user_id'],
                self::shortDramaTransferStorageConfig((string)$context['app_task']['app_code'], (int)$context['consumption']['tenant_id'])
            );
            $requestId = self::requestId($response);
            if ($videos === [] && $taskId === '') {
                throw new Exception('上游未返回视频任务号');
            }
            Db::transaction(function () use ($consumptionId, $taskId, $videos, $requestId) {
                $ctx = self::context($consumptionId, true); if ($ctx === null) return;
                $c = $ctx['consumption']; if (!in_array((string)$c['billing_status'], ['reserved', 'pending_usage'], true)) return;
                $c->save(['run_status' => $videos === [] ? 'running' : 'success', 'upstream_task_id' => $taskId, 'upstream_request_id' => $requestId, 'response_summary' => ['video_count' => count($videos), 'videos' => $videos], 'update_time' => time()]);
                self::event((int)$c['id'], 'submit', 'success', ['upstream_task_id' => $taskId, 'video_count' => count($videos)]);
            });
            if ($videos !== []) {
                self::settle($consumptionId, $videos, $requestId, $taskId, $response);
                AiTaskJobService::enqueueProcessResult($consumptionId);
            }
            else AiTaskJobService::enqueueQueryResult($consumptionId);
            return ['status' => $videos === [] ? 'running' : 'success', 'provider_task_id' => $taskId, 'provider_request_id' => $requestId, 'videos' => $videos];
        } catch (\Throwable $e) {
            self::fail($consumptionId, $e->getMessage(), 'submit_failed');
            throw $e instanceof Exception ? $e : new Exception('视频任务提交失败');
        }
    }

    public static function refresh(int $consumptionId): array
    {
        $context = self::context($consumptionId, false);
        if ($context === null) throw new Exception('市场视频消耗记录不存在');
        $c = $context['consumption'];
        if (!in_array((string)$c['billing_status'], ['reserved', 'pending_usage'], true)) return self::response($c->toArray());
        $taskId = trim((string)$c['upstream_task_id']);
        if ($taskId === '') {
            self::recordRefreshError($consumptionId, '', new Exception('视频任务尚未返回上游任务号'));
            return self::response($c->toArray());
        }
        try {
            $snapshot = self::arrayValue($c['price_snapshot'] ?? []);
            $response = self::queryRequest($snapshot, $taskId);
            $videos = self::videos(
                $response,
                (int)$c['tenant_id'],
                (int)$c['user_id'],
                self::shortDramaTransferStorageConfig((string)$context['app_task']['app_code'], (int)$c['tenant_id'])
            );
            $upstreamStatus = self::status($response);
            if (in_array($upstreamStatus, ['failed', 'error', 'canceled', 'cancelled', 'rejected'], true)) {
                $message = self::error($response);
                self::fail($consumptionId, $message, 'upstream_failed');
                return ['status' => 'failed', 'provider_task_id' => $taskId, 'videos' => [], 'error_msg' => $message];
            }
            if ($videos !== []) {
                self::settle($consumptionId, $videos, self::requestId($response), $taskId, $response);
                return ['status' => 'success', 'provider_task_id' => $taskId, 'videos' => $videos];
            }
            if ((string)$c['billing_status'] === 'pending_usage' && self::isTokenSnapshot($snapshot)) {
                $usage = self::usage($response, 0);
                $usage['actual_quantity'] = MarketUsageSettlementService::tokenUsage($response);
                if ((float)($usage['actual_quantity'] ?? 0) > 0) {
                    $stored = self::arrayValue($c['response_summary'] ?? []);
                    $settleVideos = $videos !== [] ? $videos : (array)($stored['videos'] ?? []);
                    self::settle($consumptionId, $settleVideos, self::requestId($response), $taskId, $response);
                    return ['status' => 'success', 'provider_task_id' => $taskId, 'videos' => $settleVideos];
                }
                $stored = self::arrayValue($c['response_summary'] ?? []);
                $storedVideos = (array)($stored['videos'] ?? []);
                if ($storedVideos !== []) {
                    return ['status' => 'success', 'provider_task_id' => $taskId, 'videos' => $storedVideos];
                }
            }
            self::event($consumptionId, 'poll', 'running', ['upstream_task_id' => $taskId]);
            return ['status' => 'running', 'provider_task_id' => $taskId, 'videos' => []];
        } catch (\Throwable $e) {
            // A query or result-download failure is never evidence that the
            // upstream task failed. Keep it active until the supplier returns
            // an explicit terminal status.
            self::recordRefreshError($consumptionId, $taskId, $e);
            return ['status' => 'running', 'provider_task_id' => $taskId, 'videos' => []];
        }
    }

    public static function cancel(int $consumptionId): void
    {
        $context = self::context($consumptionId, false); if ($context === null) throw new Exception('市场视频消耗记录不存在');
        $c = $context['consumption'];
        if (!in_array((string)$c['billing_status'], ['reserved', 'pending_usage'], true)) return;
        if ((string)$c['run_status'] !== 'reserved') throw new Exception('video task already submitted');
        self::fail($consumptionId, '用户取消任务', 'canceled');
    }

    public static function fail(int $consumptionId, string $message, string $code = 'failed'): void
    {
        Db::transaction(function () use ($consumptionId, $message, $code) {
            $ctx = self::context($consumptionId, true); if ($ctx === null) return;
            $c = $ctx['consumption']; $task = $ctx['app_task'];
            if (in_array((string)$c['billing_status'], ['settled', 'refunded'], true)) return;
            if ((float)$c['reserved_tenant_cost'] > 0 || (float)$c['reserved_user_price'] > 0) {
                PointService::releaseReservedBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], (float)$c['reserved_tenant_cost'], (float)$c['reserved_user_price'], (string)$c['consume_no'] . '-release', 'power market video failed refund', self::extra($task, $c, 'refunded'));
            }
            $now = time(); $status = $code === 'canceled' ? 'canceled' : 'failed';
            $c->save(['run_status' => $status, 'billing_status' => 'refunded', 'error_code' => $code, 'error_message' => mb_substr($message, 0, 1000), 'finish_time' => $now, 'update_time' => $now]);
            $task->save(['status' => $status, 'progress' => 100, 'result_summary' => ['error' => mb_substr($message, 0, 500)], 'finish_time' => $now, 'update_time' => $now]);
            self::event((int)$c['id'], $code === 'canceled' ? 'cancel' : 'refund', 'success', ['reason' => mb_substr($message, 0, 300)]);
        });
    }

    private static function settle(int $consumptionId, array $videos, string $requestId, string $taskId, array $response): void
    {
        Db::transaction(function () use ($consumptionId, $videos, $requestId, $taskId, $response) {
            $ctx = self::context($consumptionId, true); if ($ctx === null) return;
            $c = $ctx['consumption']; $task = $ctx['app_task']; if (!in_array((string)$c['billing_status'], ['reserved', 'pending_usage'], true)) return;
            $snapshot = self::arrayValue($c['price_snapshot'] ?? []);
            $submittedQuantity = max(1, (float)($snapshot['quantity'] ?? $c['quantity']));
            $usage = self::usage($response, $submittedQuantity);
            if (self::isTokenSnapshot($snapshot)) {
                $usage['actual_quantity'] = MarketUsageSettlementService::tokenUsage($response);
            }
            if (self::isTokenSnapshot($snapshot) && (float)($usage['actual_quantity'] ?? 0) <= 0) {
                $now = time();
                $usage['settlement_basis'] = 'awaiting_actual_usage';
                $c->save([
                    'run_status' => 'success', 'billing_status' => 'pending_usage',
                    'upstream_request_id' => $requestId ?: (string)$c['upstream_request_id'],
                    'upstream_task_id' => $taskId ?: (string)$c['upstream_task_id'],
                    'usage_snapshot' => $usage,
                    'response_summary' => ['video_count' => count($videos), 'videos' => $videos],
                    'update_time' => $now,
                ]);
                $task->save(['status' => 'success', 'progress' => 100, 'finish_time' => $now, 'update_time' => $now]);
                self::event((int)$c['id'], 'await_usage', 'pending', ['video_count' => count($videos)]);
                return;
            }
            $quantity = self::settlementQuantity($snapshot, $submittedQuantity, $usage);
            $tenant = self::priceForQuantity((float)($snapshot['platform_price'] ?? 0), $quantity, $snapshot); $user = self::priceForQuantity((float)($snapshot['tenant_price'] ?? 0), $quantity, $snapshot);
            if ((string)$c['billing_status'] === 'pending_usage') {
                PointService::assertCanConsumeAmounts((int)$c['tenant_id'], (int)$c['user_id'], $tenant, $user);
                PointService::consumeBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], $tenant, $user, (string)$c['consume_no'], '算力市场视频实际用量结算', self::extra($task, $c, 'settled'));
            } else {
                PointService::settleReservedBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], (float)$c['reserved_tenant_cost'], (float)$c['reserved_user_price'], $tenant, $user, (string)$c['consume_no'], '算力市场视频结算', self::extra($task, $c, 'settled'));
            }
            $now = time();
            $usage['settled_quantity'] = $quantity;
            $usage['settlement_basis'] = $quantity !== $submittedQuantity ? 'actual_usage' : 'submit_snapshot';
            $c->save(['run_status' => 'success', 'billing_status' => 'settled', 'upstream_request_id' => $requestId ?: (string)$c['upstream_request_id'], 'upstream_task_id' => $taskId ?: (string)$c['upstream_task_id'], 'quantity' => $quantity, 'usage_snapshot' => $usage, 'response_summary' => ['video_count' => count($videos), 'videos' => $videos], 'actual_tenant_cost' => $tenant, 'actual_user_price' => $user, 'finish_time' => $now, 'update_time' => $now]);
            $task->save(['status' => 'success', 'progress' => 100, 'actual_tenant_cost' => $tenant, 'actual_user_price' => $user, 'finish_time' => $now, 'update_time' => $now]);
            self::event((int)$c['id'], 'settle', 'success', ['video_count' => count($videos), 'quantity' => $quantity]);
        });
    }

    private static function resolve(int $tenantId, array $selection): array
    {
        $skuId = self::intSelection($selection, ['market_sku_id', 'sku_id']);
        $resourceType = self::resourceType($selection);
        if ($skuId > 0) {
            $sku = PowerMarketSku::where(['id' => $skuId, 'status' => 1, 'sale_status' => 1])->findOrEmpty();
            if ($sku->isEmpty()) throw new Exception('所选视频规格已下架');
            $product = PowerMarketProduct::where(['id' => (int)$sku['product_id'], 'status' => 1])->findOrEmpty();
            if ($product->isEmpty() || !self::productAllowed($product->toArray(), $resourceType)) throw new Exception('所选视频商品已下架');
            $market = self::marketRow($tenantId, $product->toArray(), $sku->toArray());
            try {
                self::assertSkuMatchesSelection($market, $selection);
            } catch (Exception $e) {
                // The UI can keep a stale SKU while resolution, duration, or
                // reference input mode changes. Resolve inside the same product
                // so price and provider payload follow the current selection.
                if (!in_array($e->getMessage(), [
                    '所选市场 SKU 不支持当前分辨率',
                    '所选市场 SKU 不支持当前输入模式',
                    '所选市场 SKU 不支持当前生成模式',
                    'market sku does not support current input mode',
                ], true)) {
                    throw $e;
                }
                $fallback = self::resolveProduct($tenantId, $product->toArray(), self::withoutSku($selection));
                self::assertSkuMatchesSelection($fallback, $selection);
                return $fallback;
            }
            return $market;
        }
        $productId = self::productId($selection); if ($productId <= 0) throw new Exception('请选择算力市场视频模型');
        $product = PowerMarketProduct::where(['id' => $productId, 'status' => 1])->findOrEmpty();
        if ($product->isEmpty() || !self::productAllowed($product->toArray(), $resourceType)) throw new Exception('所选视频商品已下架');
        return self::resolveProduct($tenantId, $product->toArray(), $selection);
    }

    private static function resolveProduct(int $tenantId, array $productData, array $selection): array
    {
        $productId = (int)$productData['id'];
        if (strtolower((string)($productData['upstream_app_code'] ?? '')) === 'grok_video'
            && self::videoMode($selection) === 'standard'
            && self::assets($selection)['image'] === []) {
            throw new Exception('Grok 标准生成需要至少上传一张参考图');
        }
        $matches = self::availableSkus($tenantId, $productId); $quality = self::value($selection, ['resolution', 'quality']); $duration = (int)self::value($selection, ['duration']); $mode = self::inputMode($selection);
        $matches = array_values(array_filter($matches, static function (array $row) use ($quality, $duration, $mode, $productData, $selection): bool {
            $locked = self::arrayValue($row['sku']['locked_params'] ?? []); $resolution = self::resolution($locked); $lockedDuration = self::duration($locked); $skuMode = self::skuInputMode($locked);
            if ($quality !== '' && $resolution !== '' && strtolower($quality) !== strtolower($resolution)) return false;
            if (!self::isSupportedAppDuration($productData, $lockedDuration, $locked)) return false;
            if (!self::isSupportedAppDuration($productData, $duration, $locked)) return false;
            if (!self::skuMatchesMode($productData, $locked, $selection)) return false;
            if (!self::skuSupportsInputMode($skuMode, $mode, $productData, $locked)) return false;
            return true;
        }));
        if ($matches === []) throw new Exception('当前模型没有可用的市场计费 SKU');
        if ($duration > 0) {
            usort($matches, static function (array $a, array $b) use ($duration): int {
                $durationA = self::duration(self::arrayValue($a['sku']['locked_params'] ?? []));
                $durationB = self::duration(self::arrayValue($b['sku']['locked_params'] ?? []));
                if ($durationA <= 0 && $durationB <= 0) return 0;
                if ($durationA <= 0) return 1;
                if ($durationB <= 0) return -1;
                $diffA = abs($durationA - $duration);
                $diffB = abs($durationB - $duration);
                if ($diffA !== $diffB) return $diffA <=> $diffB;
                return $durationB <=> $durationA;
            });
        }
        return self::marketRow($tenantId, $productData, (array)$matches[0]['sku']);
    }

    private static function productAllowed(array $product, string $wanted = ''): bool
    {
        $type = (string)$product['resource_type'];
        if ($wanted !== '' && $type !== $wanted) return false;
        if ($type === PowerMarketService::TYPE_MODEL) return (string)$product['model_type'] === 'video';
        return $type === PowerMarketService::TYPE_APP_API && self::isSupportedAppProduct($product);
    }

    private static function isSupportedAppProduct(array $product): bool
    {
        $app = strtolower((string)($product['upstream_app_code'] ?? '')); $api = strtolower((string)($product['upstream_api_code'] ?? ''));
        if (!(($app === 'wan' && $api === 'create') || ($app === 'seedance' && $api === 'create') || ($app === 'happy_horse' && $api === 'submit') || ($app === 'grok_video' && $api === 'submit'))) return false;
        // Grok's query API is deliberately non-billable, so upstream market
        // synchronization has no query SKU to sell. Its submit SKU is the
        // sellable product; querying remains part of the task lifecycle.
        if ($app === 'grok_video') return true;
        // query is an internal task-lifecycle contract, not a separately sold
        // SKU. Its product only needs to remain published by the platform.
        $query = PowerMarketProduct::where(['resource_type' => PowerMarketService::TYPE_APP_API, 'upstream_app_code' => $app, 'upstream_api_code' => 'query', 'status' => 1])->findOrEmpty();
        return !$query->isEmpty();
    }

    private static function marketRow(int $tenantId, array $product, array $sku): array
    {
        $tenant = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => (int)$sku['id']])->findOrEmpty();
        if (!$tenant->isEmpty() && (int)$tenant['sale_status'] !== 1) throw new Exception('租户未上架该视频规格');
        return ['product' => $product, 'sku' => $sku, 'tenant_price' => $tenant->isEmpty() ? (float)$sku['sale_points'] : (float)$tenant['sale_points']];
    }

    private static function availableSkus(int $tenantId, int $productId): array
    {
        $rows = PowerMarketSku::where(['product_id' => $productId, 'status' => 1, 'sale_status' => 1])->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray(); $result = [];
        foreach ($rows as $sku) { $tenant = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => (int)$sku['id']])->findOrEmpty(); if (!$tenant->isEmpty() && (int)$tenant['sale_status'] !== 1) continue; $result[] = ['sku' => $sku, 'tenant_price' => $tenant->isEmpty() ? (float)$sku['sale_points'] : (float)$tenant['sale_points']]; }
        return $result;
    }

    private static function quoteMarket(array $market, float $quantity): array
    {
        $quantity = max(1, $quantity); $sku = $market['sku']; $snapshot = self::snapshot($market, $quantity); $deferred = self::isTokenSnapshot($snapshot);
        return [
            'billing_unit' => (string)$sku['usage_unit'], 'billing_unit_size' => (float)($snapshot['usage_unit_size'] ?? 1),
            'quantity' => $deferred ? 0 : $quantity,
            'tenant_unit_points' => self::points((float)$sku['sale_points']), 'user_unit_points' => self::points((float)$market['tenant_price']),
            'tenant_cost_points' => $deferred ? 0 : self::priceForQuantity((float)$sku['sale_points'], $quantity, $snapshot),
            'user_charge_points' => $deferred ? 0 : self::priceForQuantity((float)$market['tenant_price'], $quantity, $snapshot),
            'settlement_mode' => $deferred ? 'actual_usage' : 'reserved', 'price_source' => 'power_market_video',
            'market_product_id' => (int)$market['product']['id'], 'market_sku_id' => (int)$sku['id'], 'market_snapshot' => $snapshot,
        ];
    }

    private static function snapshot(array $market, float $quantity): array
    {
        $product = $market['product']; $sku = $market['sku']; $meta = self::metadata($product);
        return ['product_id' => (int)$product['id'], 'sku_id' => (int)$sku['id'], 'sku_key' => (string)$sku['sku_key'], 'resource_type' => (string)$product['resource_type'], 'model_code' => (string)$product['upstream_model_code'], 'channel_code' => (string)$product['upstream_channel_code'], 'app_code' => (string)$product['upstream_app_code'], 'api_code' => (string)$product['upstream_api_code'], 'locked_params' => self::arrayValue($sku['locked_params'] ?? []), 'usage_unit' => (string)$sku['usage_unit'], 'usage_unit_size' => max(1, (float)($sku['usage_unit_size'] ?? 1)), 'upstream_price' => (float)$sku['upstream_price'], 'platform_price' => (float)$sku['sale_points'], 'tenant_price' => (float)$market['tenant_price'], 'quantity' => $quantity, 'protocol' => (string)($meta['protocol'] ?? 'video_generate')];
    }

    private static function submitRequest(array $snapshot, array $request, string $idempotency, int $consumptionId): array
    {
        if (($snapshot['resource_type'] ?? '') === PowerMarketService::TYPE_MODEL) return self::request('POST', self::origin() . self::MODEL_TASK_PATH, self::modelPayload($snapshot, $request, $idempotency));
        $app = (string)$snapshot['app_code'];
        if ($app === 'seedance') self::seedanceAssets($request, $consumptionId);
        return self::request('POST', self::endpoint($app, (string)$snapshot['api_code']), self::appPayload($snapshot, $request, $idempotency));
    }

    private static function queryRequest(array $snapshot, string $taskId): array
    {
        $taskUrl = self::origin() . str_replace('{task_id}', rawurlencode($taskId), self::MODEL_QUERY_PATH);
        if (($snapshot['resource_type'] ?? '') === PowerMarketService::TYPE_MODEL) {
            return self::request('GET', $taskUrl, [], true);
        }

        $app = (string)$snapshot['app_code'];
        $url = self::endpoint($app, 'query');
        try {
            return $app === 'happy_horse'
                ? self::request('POST', $url, ['task_id' => $taskId], true)
                : self::request('GET', $url . '?task_id=' . rawurlencode($taskId), [], true);
        } catch (\Throwable $appQueryError) {
            // Application query endpoints sometimes collapse a completed
            // supplier failure into a generic error. The unified task API
            // retains the terminal state, so use it only as a query fallback.
            // If it is also unavailable, preserve the original retry path.
            try {
                $task = self::request('GET', $taskUrl, [], true);
                if (self::taskId($task) !== '' || self::status($task) !== '') {
                    return $task;
                }
            } catch (\Throwable) {
            }
            throw $appQueryError;
        }
    }

    private static function modelPayload(array $snapshot, array $request, string $idempotency): array
    {
        $locked = self::arrayValue($snapshot['locked_params'] ?? []);
        $assets = self::assets($request);
        $duration = self::duration($locked) ?: (int)($request['duration'] ?? 0);
        $quality = self::value($request, ['quality', 'resolution']) ?: self::resolution($locked);
        // Model API video calls share the existing AIGC video provider contract:
        // `quality` and `n` are required by multiple async channels. The
        // market stores `resolution` as UI metadata only, so do not replace the
        // supplier's quality parameter with it.
        return array_filter(array_merge($locked, [
            'model' => (string)$snapshot['model_code'],
            'n' => max(1, (int)($request['quantity'] ?? 1)),
            'prompt' => trim((string)($request['prompt'] ?? '')),
            'channel' => (string)$snapshot['channel_code'],
            'image_urls' => $assets['image'],
            'quality' => $quality,
            'aspect_ratio' => (string)($request['ratio'] ?? ''),
            'duration' => $duration,
            'negative_prompt' => trim((string)($request['negative_prompt'] ?? '')),
            'video_urls' => $assets['video'],
            'audio_urls' => $assets['audio'],
            'idempotency_key' => $idempotency,
        ]), static fn($value) => $value !== '' && $value !== null && $value !== []);
    }

    private static function appPayload(array $snapshot, array $request, string $idempotency): array
    {
        $app = (string)$snapshot['app_code']; $locked = self::arrayValue($snapshot['locked_params'] ?? []); $assets = self::assets($request); $duration = max(1, self::duration($locked) ?: (int)($request['duration'] ?? 5)); $resolution = self::value($request, ['resolution', 'quality']) ?: self::resolution($locked);
        self::assertSupportedAppDuration(['upstream_app_code' => $app], $duration);
        if ($app === 'happy_horse') {
            $model = $assets['image'] === [] ? 'happyhorse-1.0-t2v' : (count($assets['image']) === 1 ? 'happyhorse-1.0-i2v' : 'happyhorse-1.0-r2v');
            return array_filter(array_merge($locked, ['model' => $model, 'prompt' => trim((string)($request['prompt'] ?? '')), 'resolution' => strtoupper($resolution ?: '720P'), 'duration' => $duration, 'ratio' => (string)($request['ratio'] ?? ''), 'media' => array_map(static fn(string $url): array => ['url' => $url, 'type' => 'image'], $assets['image']), 'idempotency_key' => $idempotency]), static fn($value) => $value !== '' && $value !== [] && $value !== null);
        }
        if ($app === 'seedance') {
            return array_filter(array_merge($locked, ['model' => (string)($locked['model'] ?? ($assets['video'] === [] ? 'seedance-2-text-2-video' : 'seedance-2-video-2-video')), 'content' => [['type' => 'text', 'text' => trim((string)($request['prompt'] ?? ''))]], 'ratio' => (string)($request['ratio'] ?? ''), 'resolution' => $resolution, 'duration' => $duration, 'image_urls' => $assets['image'], 'video_urls' => $assets['video'], 'audio_urls' => $assets['audio'], 'idempotency_key' => $idempotency]), static fn($value) => $value !== '' && $value !== [] && $value !== null);
        }
        if ($app === 'grok_video') {
            self::assertSupportedAppDuration(['upstream_app_code' => $app, 'name' => 'Grok 视频'], $duration, $locked);
            return array_filter(array_merge($locked, [
                'model' => (string)($locked['model'] ?? 'grok-imagine-video-1.5-fast'),
                'prompt' => trim((string)($request['prompt'] ?? '')),
                'duration' => $duration,
                'resolution' => $resolution,
                'aspect_ratio' => (string)($request['ratio'] ?? ''),
                'image_urls' => $assets['image'],
                'idempotency_key' => $idempotency,
            ]), static fn($value) => $value !== '' && $value !== [] && $value !== null);
        }
        $model = (string)($locked['model'] ?? 'wan2.7'); if ($assets['video'] !== []) $model = 'wan2.7-videoedit'; elseif ($assets['image'] !== []) $model = 'wan2.7-r2v';
        return array_filter(array_merge($locked, ['model' => $model, 'prompt' => trim((string)($request['prompt'] ?? '')), 'resolution' => $resolution, 'duration' => $duration, 'size' => (string)($request['ratio'] ?? ''), 'image_urls' => $assets['image'], 'video_urls' => $assets['video'], 'audio_urls' => $assets['audio'], 'idempotency_key' => $idempotency]), static fn($value) => $value !== '' && $value !== [] && $value !== null);
    }

    private static function seedanceAssets(array &$request, int $consumptionId): void
    {
        $assets = self::assets($request); if ($assets['image'] === [] && $assets['video'] === [] && $assets['audio'] === []) return;
        $group = self::request('POST', self::endpoint('seedance', 'createGroup'), ['Name' => 'aigc-video-' . $consumptionId, 'GroupType' => 'AIGC', 'ProjectName' => 'default']);
        // App API responses are not uniform: both `data.result.id` and
        // `data.data.GroupId` occur in deployed Seedance gateways.
        $groupId = self::responseIdentifier($group, ['group_id', 'groupid', 'id']);
        if ($groupId === '') throw new Exception('Seedance素材组创建失败：未返回GroupId');
        $uploaded = ['image' => [], 'video' => [], 'audio' => []];
        foreach ($assets as $type => $urls) foreach ($urls as $index => $url) { $created = self::request('POST', self::endpoint('seedance', 'createAsset'), ['URL' => $url, 'Name' => $type . '-' . $index, 'GroupId' => $groupId, 'AssetType' => ucfirst($type), 'ProjectName' => 'default']); $id = self::responseIdentifier($created, ['asset_id', 'assetid', 'id']); if ($id === '') throw new Exception('Seedance素材上传失败：未返回AssetId'); $uploaded[$type][] = 'asset://' . $id; }
        $request['reference_assets'] = array_merge(...array_map(static fn(string $type, array $urls): array => array_map(static fn(string $url): array => ['type' => $type, 'url' => $url], $urls), array_keys($uploaded), $uploaded));
        self::event($consumptionId, 'asset_upload', 'success', ['group_id' => $groupId, 'asset_count' => count($uploaded['image']) + count($uploaded['video']) + count($uploaded['audio'])]);
    }

    private static function assets(array $request): array
    {
        $assets = AigcVideoReferenceAssetService::normalize($request); $result = ['image' => [], 'video' => [], 'audio' => []];
        foreach ($assets as $asset) { $type = (string)($asset['type'] ?? ''); if (!isset($result[$type])) continue; $raw = trim((string)($asset['url'] ?? $asset['uri'] ?? '')); $url = str_starts_with($raw, 'asset://') ? $raw : AigcVideoReferenceAssetService::publicUrl($asset); if ($url !== '' && !in_array($url, $result[$type], true)) $result[$type][] = $url; }
        return $result;
    }

    private static function assertAssets(array $market, array $request): void
    {
        $product = $market['product']; $metadata = self::metadata($product); $assets = self::assets($request);
        $locked = self::arrayValue($market['sku']['locked_params'] ?? []);
        if (strtolower((string)($product['upstream_app_code'] ?? '')) === 'grok_video'
            && (string)($locked['model'] ?? '') === 'grok-imagine-video-1.5'
            && $assets['image'] === []) {
            throw new Exception('Grok 标准生成需要至少上传一张参考图');
        }
        foreach ($assets as $type => $items) {
            $count = count($items); if ($count === 0) continue;
            $limit = self::referenceLimit($product, $metadata, $type); $supported = self::supportedAssetTypes($product, $metadata);
            if (!in_array($type, $supported, true) || $limit <= 0) throw new Exception('selected video model does not support reference ' . self::assetLabel($type));
            if ($count > $limit) throw new Exception('selected video model supports at most ' . $limit . ' reference ' . self::assetLabel($type));
        }
    }

    private static function assertSkuMatchesSelection(array $market, array $selection): void
    {
        $locked = self::arrayValue($market['sku']['locked_params'] ?? []);
        $requestedResolution = self::value($selection, ['resolution', 'quality']);
        $lockedResolution = self::resolution($locked);
        if ($requestedResolution !== '' && $lockedResolution !== '' && strtolower($requestedResolution) !== strtolower($lockedResolution)) {
            throw new Exception('所选市场 SKU 不支持当前分辨率');
        }
        $requestedDuration = (int)self::value($selection, ['duration']);
        $lockedDuration = self::duration($locked);
        self::assertSupportedAppDuration($market['product'], $requestedDuration ?: $lockedDuration, $locked);
        if (!self::skuMatchesMode($market['product'], $locked, $selection)) {
            throw new Exception('所选市场 SKU 不支持当前生成模式');
        }
        $mode = self::inputMode($selection); $app = strtolower((string)($market['product']['upstream_app_code'] ?? ''));
        if ($app === 'grok_video' && (string)($locked['model'] ?? '') === 'grok-imagine-video-1.5' && self::assets($selection)['image'] === []) {
            throw new Exception('Grok 标准生成需要至少上传一张参考图');
        }
        if (!self::skuSupportsInputMode(self::skuInputMode($locked), $mode, $market['product'], $locked)) {
            throw new Exception('所选市场 SKU 不支持当前输入模式');
        }
    }

    private static function formatSku(array $market, array $product): array
    {
        $sku = $market['sku']; $locked = self::arrayValue($sku['locked_params'] ?? []);
        $app = strtolower((string)($product['upstream_app_code'] ?? ''));
        $inputMode = $app === 'seedance'
            && str_contains(strtolower((string)($locked['_pricing_variant'] ?? $locked['pricing_variant'] ?? '')), 'withvideo')
            ? 'video_edit'
            : self::skuInputMode($locked);
        if ($app === 'grok_video' && (string)($locked['model'] ?? '') === 'grok-imagine-video-1.5') $inputMode = 'image_reference';
        return ['market_sku_id' => (int)$sku['id'], 'sku_key' => (string)$sku['sku_key'], 'title' => (string)$sku['title'], 'resolution' => self::resolution($locked), 'duration' => self::duration($locked), 'model' => (string)($locked['model'] ?? $product['upstream_model_code'] ?? ''), 'input_mode' => $inputMode, 'locked_params' => $locked, 'usage_unit' => (string)$sku['usage_unit'], 'usage_unit_size' => max(1, (float)($sku['usage_unit_size'] ?? 1)), 'settlement_mode' => self::isTokenSku($sku) ? 'actual_usage' : 'reserved', 'platform_unit_cost' => self::points((float)$sku['sale_points']), 'tenant_unit_price' => self::points((float)$market['tenant_price'])];
    }

    private static function inputModes(string $resourceType, array $product, array $meta): array
    {
        $modes = [['value' => 'text_to_video', 'label' => 'Text to video']]; $types = self::supportedAssetTypes($product, $meta); if (in_array('image', $types, true)) $modes[] = ['value' => 'image_reference', 'label' => 'Image reference']; if (in_array('video', $types, true)) $modes[] = ['value' => 'video_edit', 'label' => 'Video edit']; return $modes;
    }
    private static function modeOptions(array $product, array $skus): array
    {
        if (strtolower((string)($product['upstream_app_code'] ?? '')) !== 'grok_video') return [];
        $models = array_values(array_unique(array_filter(array_map(static fn(array $sku): string => (string)($sku['model'] ?? ''), $skus))));
        $options = [];
        if (in_array('grok-imagine-video-1.5-fast', $models, true)) $options[] = 'fast';
        if (in_array('grok-imagine-video-1.5', $models, true)) $options[] = 'standard';
        return $options;
    }
    private static function supportedAssetTypes(array $product, array $meta): array
    {
        if (strtolower((string)($product['upstream_app_code'] ?? '')) === 'happy_horse') return ['image'];
        if (strtolower((string)($product['upstream_app_code'] ?? '')) === 'grok_video') return ['image'];
        $types = self::assetTypes($meta);
        return $types;
    }
    private static function referenceLimit(array $product, array $meta, string $type): int
    {
        if (strtolower((string)($product['upstream_app_code'] ?? '')) === 'happy_horse' && $type === 'image') return max(2, self::capabilityLimit($meta, $type));
        if (strtolower((string)($product['upstream_app_code'] ?? '')) === 'grok_video' && $type === 'image') return max(7, self::capabilityLimit($meta, $type));
        return self::capabilityLimit($meta, $type);
    }
    private static function assetTypes(array $meta): array { $cap = self::arrayValue($meta['capabilities'] ?? []); $values = $meta['supported_asset_types'] ?? $cap['supported_asset_types'] ?? []; if ($values === []) { foreach (['image','video','audio'] as $type) if (self::capabilityLimit($meta, $type) > 0) $values[] = $type; } return array_values(array_intersect(['image','video','audio'], array_map(static fn($v) => strtolower((string)$v), (array)$values))); }
    private static function capabilityLimit(array $meta, string $type): int { $cap = self::arrayValue($meta['capabilities'] ?? []); $keys = ['image' => ['max_reference_images','max_reference_image_count','reference_image_limit'], 'video' => ['max_reference_videos','max_reference_video_count','reference_video_limit'], 'audio' => ['max_reference_audios','max_reference_audio_count','reference_audio_limit']]; foreach ($keys[$type] as $key) foreach ([$meta, $cap] as $source) if (isset($source[$key])) return max(0, (int)$source[$key]); return $type === 'image' && (!empty($meta['supports_reference_images']) || !empty($cap['supports_reference_images'])) ? 1 : 0; }
    private static function supportsFirstLastFrame(array $meta): bool { $cap = self::arrayValue($meta['capabilities'] ?? []); return !empty($meta['supports_first_last_frame']) || !empty($cap['supports_first_last_frame']) || in_array('start_end', (array)($meta['generation_modes'] ?? $cap['generation_modes'] ?? []), true); }
    private static function ratioOptions(array $meta): array { $cap = self::arrayValue($meta['capabilities'] ?? []); $schema = self::arrayValue($meta['params_schema'] ?? []); $values = $meta['supported_ratios'] ?? $meta['ratios'] ?? $cap['supported_ratios'] ?? $schema['aspect_ratio']['options'] ?? []; if (is_string($values)) $values = preg_split('~\s*[,|/]\s*~', $values) ?: []; return array_values(array_filter(array_map('strval', (array)$values))); }
    private static function durationOptions(array $meta): array
    {
        $cap = self::arrayValue($meta['capabilities'] ?? []);
        $schema = self::arrayValue($meta['params_schema'] ?? []);
        $durationSchema = self::arrayValue($schema['duration'] ?? $schema['seconds'] ?? $schema['video_duration'] ?? []);
        $values = $meta['supported_durations']
            ?? $meta['durations']
            ?? $cap['supported_durations']
            ?? $cap['durations']
            ?? $durationSchema['options']
            ?? $durationSchema['enum']
            ?? $durationSchema['values']
            ?? $durationSchema['allowed_values']
            ?? $durationSchema['default']
            ?? $durationSchema['value']
            ?? [];
        $items = is_array($values) ? $values : [$values];
        $durations = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $item = $item['value'] ?? $item['duration'] ?? $item['seconds'] ?? '';
            }
            $value = trim((string)$item);
            if ($value === '') continue;
            if (preg_match('/^(\d+)\s*(?:-|~|至)\s*(\d+)$/u', $value, $match)) {
                $start = (int)$match[1];
                $end = (int)$match[2];
                if ($start > 0 && $end >= $start && $end - $start <= 60) {
                    $durations = array_merge($durations, range($start, $end));
                }
                continue;
            }
            if (preg_match_all('/\d+/', $value, $matches)) {
                foreach ($matches[0] as $duration) if ((int)$duration > 0) $durations[] = (int)$duration;
            }
        }
        $durations = array_values(array_unique($durations));
        sort($durations);
        return $durations;
    }
    private static function quantity(array $market, array $selection): float
    {
        $locked = self::arrayValue($market['sku']['locked_params'] ?? []);
        $unit = strtolower((string)$market['sku']['usage_unit']);
        if (self::isTokenSku($market['sku'])) return max(0, self::lockedQuantity($locked));
        $lockedDuration = self::duration($locked);
        if (str_contains($unit, 'second') || str_contains($unit, 'sec')) return max(1, (int)($lockedDuration ?: ($selection['duration'] ?? 1)));
        // Per-call SKU pricing is one submitted request regardless of a model
        // parameter such as duration or n in its locked request contract.
        return 1;
    }
    private static function isTokenSku(array $sku): bool { return str_contains(strtolower((string)($sku['usage_unit'] ?? '')), 'token'); }
    private static function isDeferredUsageSku(array $sku): bool { return self::isTokenSku($sku); }
    private static function isTokenSnapshot(array $snapshot): bool { return str_contains(strtolower((string)($snapshot['usage_unit'] ?? '')), 'token'); }
    private static function priceForQuantity(float $unitPrice, float $quantity, array $snapshot): float
    {
        $unitSize = max(1, (float)($snapshot['usage_unit_size'] ?? 1));
        return self::points($unitPrice * $quantity / $unitSize);
    }
    private static function lockedQuantity(array $locked): float { foreach (['quantity','billing_quantity','usage_quantity','tokens','duration'] as $key) if (isset($locked[$key]) && is_numeric($locked[$key]) && (float)$locked[$key] > 0) return (float)$locked[$key]; return 0; }
    private static function resolution(array $locked): string { return (string)($locked['resolution'] ?? $locked['quality'] ?? $locked['size'] ?? ''); }
    private static function duration(array $locked): int { foreach (['duration','seconds','video_duration'] as $key) if (isset($locked[$key]) && is_numeric($locked[$key])) return max(0, (int)$locked[$key]); return 0; }
    private static function defaultDurations(array $product): array
    {
        $app = strtolower((string)($product['upstream_app_code'] ?? ''));
        if ($app === 'wan') return range(2, 15);
        if ($app === 'grok_video') return range(1, 30);
        $range = self::APP_DURATION_RANGES[$app] ?? [];
        return count($range) === 2 ? range($range[0], $range[1]) : [];
    }
    private static function hasConfigurableDurationSku(array $skus): bool { foreach ($skus as $sku) if ((int)($sku['duration'] ?? 0) === 0) return true; return false; }
    private static function isSupportedAppDuration(array $product, int $duration, array $locked = []): bool
    {
        if ($duration <= 0) return true;
        if (strtolower((string)($product['upstream_app_code'] ?? '')) === 'grok_video') {
            $max = (string)($locked['model'] ?? '') === 'grok-imagine-video-1.5' ? 15 : 30;
            return $duration >= 1 && $duration <= $max;
        }
        $range = self::APP_DURATION_RANGES[strtolower((string)($product['upstream_app_code'] ?? ''))] ?? [];
        return count($range) !== 2 || ($duration >= $range[0] && $duration <= $range[1]);
    }
    private static function preferredSupportedDuration(array $product, int $duration): int
    {
        if ($duration > 0 && self::isSupportedAppDuration($product, $duration)) return $duration;
        $defaults = self::defaultDurations($product);
        return (int)($defaults[0] ?? max(0, $duration));
    }
    private static function filterSupportedAppDurations(array $product, array $durations): array { return array_values(array_filter($durations, static fn($duration): bool => self::isSupportedAppDuration($product, (int)$duration))); }
    private static function assertSupportedAppDuration(array $product, int $duration, array $locked = []): void
    {
        if (self::isSupportedAppDuration($product, $duration, $locked)) return;
        if (strtolower((string)($product['upstream_app_code'] ?? '')) === 'grok_video') {
            $max = (string)($locked['model'] ?? '') === 'grok-imagine-video-1.5' ? 15 : 30;
            throw new Exception((string)($product['name'] ?? 'Grok 视频') . ' 当前模式仅支持 1-' . $max . ' 秒视频');
        }
        $range = self::APP_DURATION_RANGES[strtolower((string)($product['upstream_app_code'] ?? ''))] ?? [];
        throw new Exception((string)($product['name'] ?? '当前视频模型') . ' 仅支持 ' . $range[0] . '-' . $range[1] . ' 秒视频');
    }
    private static function displayName(array $product): string
    {
        if ((string)($product['resource_type'] ?? '') !== PowerMarketService::TYPE_APP_API) {
            return (string)($product['name'] ?? '视频模型');
        }
        return match (strtolower((string)($product['upstream_app_code'] ?? ''))) {
            'seedance' => 'Seedance 2.0',
            'wan' => 'Wan 视频生成',
            'happy_horse' => 'Happy Horse',
            'grok_video' => 'Grok 视频生成',
            default => preg_replace('/\s*\/\s*(?:创建|提交).*/u', '', (string)($product['name'] ?? '视频生成')) ?: '视频生成',
        };
    }
    private static function resourceType(array $selection): string { $value = (string)($selection['resource_type'] ?? ''); if (in_array($value, [PowerMarketService::TYPE_MODEL, PowerMarketService::TYPE_APP_API], true)) return $value; $model = implode('|', array_map('strval', [$selection['model_id'] ?? '', $selection['channel'] ?? ''])); return str_contains($model, 'market_video_app:') ? PowerMarketService::TYPE_APP_API : PowerMarketService::TYPE_MODEL; }
    private static function optionId(string $type, array $product): string { return $type === PowerMarketService::TYPE_MODEL ? 'market_video_model:' . (int)$product['id'] : 'market_video_app:' . (string)$product['upstream_app_code'] . ':' . (int)$product['id']; }
    private static function productId(array $selection): int { $value = self::intSelection($selection, ['market_product_id']); if ($value > 0) return $value; foreach ([$selection['model_id'] ?? '', $selection['video_model_id'] ?? '', $selection['channel'] ?? '', self::arrayValue($selection['params'] ?? [])['model_id'] ?? ''] as $value) if (preg_match('/^market_video_(?:model|app):(?:[a-z_]+:)?(\d+)$/', (string)$value, $m)) return (int)$m[1]; return 0; }
    private static function intSelection(array $selection, array $keys): int { $nested = self::arrayValue($selection['params'] ?? []); foreach ($keys as $key) { $value = $selection[$key] ?? $nested[$key] ?? 0; if (is_string($value)) $value = preg_replace('/^market_sku:/', '', $value); if ((int)$value > 0) return (int)$value; } return 0; }
    private static function value(array $source, array $keys): string { $nested = self::arrayValue($source['params'] ?? []); foreach ($keys as $key) { $value = trim((string)($source[$key] ?? $nested[$key] ?? '')); if ($value !== '') return $value; } return ''; }
    private static function withoutDuration(array $selection): array
    {
        unset($selection['duration'], $selection['seconds'], $selection['video_duration']);
        if (isset($selection['params'])) {
            $selection['params'] = self::arrayValue($selection['params']);
            unset($selection['params']['duration'], $selection['params']['seconds'], $selection['params']['video_duration']);
        }
        return $selection;
    }
    private static function withoutSku(array $selection): array
    {
        unset($selection['market_sku_id'], $selection['sku_id']);
        if (isset($selection['params'])) {
            $selection['params'] = self::arrayValue($selection['params']);
            unset($selection['params']['market_sku_id'], $selection['params']['sku_id']);
        }
        return $selection;
    }
    private static function inputMode(array $selection): string { $assets = self::assets($selection); if ($assets['video'] !== []) return 'video_edit'; if ($assets['image'] !== []) return 'image_reference'; return 'text_to_video'; }
    private static function skuInputMode(array $locked): string
    {
        $model = strtolower((string)($locked['model'] ?? ''));
        if (str_contains($model, 'videoedit') || str_contains($model, 'video-2-video')) return 'video_edit';
        if (str_contains($model, 'r2v') || str_contains($model, 'i2v') || str_contains($model, 'image-2-video') || str_contains($model, 'reference')) return 'image_reference';
        return 'text_to_video';
    }
    private static function skuSupportsInputMode(string $skuMode, string $requestedMode, array $product, array $locked): bool
    {
        $app = strtolower((string)($product['upstream_app_code'] ?? ''));
        if ($app === 'happy_horse') return in_array($requestedMode, ['text_to_video', 'image_reference'], true);
        if ($app === 'grok_video') {
            return (string)($locked['model'] ?? '') === 'grok-imagine-video-1.5'
                ? $requestedMode === 'image_reference'
                : in_array($requestedMode, ['text_to_video', 'image_reference'], true);
        }
        if ($app === 'seedance') {
            $variant = strtolower((string)($locked['_pricing_variant'] ?? $locked['pricing_variant'] ?? ''));
            return str_contains($variant, 'withvideo')
                ? $requestedMode === 'video_edit'
                : in_array($requestedMode, ['text_to_video', 'image_reference'], true);
        }
        // Model API video SKUs without a mode-lock use one price for text and
        // image-reference calls. App API SKU rows must declare an explicit
        // model variant (for example wan2.7-r2v) before image input is allowed.
        if ($requestedMode === 'image_reference' && $skuMode === 'text_to_video'
            && (string)($product['resource_type'] ?? '') === PowerMarketService::TYPE_MODEL
            && trim((string)($locked['model'] ?? '')) === '') return true;
        return $skuMode === $requestedMode;
    }
    private static function skuMatchesMode(array $product, array $locked, array $selection): bool
    {
        if (strtolower((string)($product['upstream_app_code'] ?? '')) !== 'grok_video') return true;
        $mode = self::videoMode($selection);
        if ($mode === '') return true;
        $model = (string)($locked['model'] ?? '');
        return ($mode === 'fast' && $model === 'grok-imagine-video-1.5-fast')
            || ($mode === 'standard' && $model === 'grok-imagine-video-1.5');
    }
    private static function videoMode(array $selection): string { return strtolower(self::value($selection, ['video_mode', 'mode'])); }
    private static function selectionKey(array $market): string { return (string)$market['product']['id'] . ':' . (string)$market['sku']['id']; }
    private static function metadata(array $product): array
    {
        $source = self::arrayValue($product['source_payload'] ?? []); $raw = self::arrayValue($source['raw'] ?? []);
        $metadata = self::arrayValue($source['market_metadata'] ?? []);
        foreach ([self::arrayValue($source['resource'] ?? []), self::arrayValue($raw['resource'] ?? [])] as $resource) {
            $metadata = array_merge($resource, $metadata);
            $metadata['capabilities'] = array_merge(self::arrayValue($resource['capabilities'] ?? []), self::arrayValue($metadata['capabilities'] ?? []));
        }
        return $metadata;
    }
    private static function requestSummary(array $request): array { $assets = self::assets($request); return ['prompt_length' => mb_strlen((string)($request['prompt'] ?? '')), 'duration' => (int)($request['duration'] ?? 0), 'resolution' => self::value($request, ['resolution','quality']), 'reference_images' => count($assets['image']), 'reference_videos' => count($assets['video']), 'reference_audios' => count($assets['audio'])]; }
    private static function usage(array $response, float $quantity): array
    {
        $root = self::root($response);
        $result = self::arrayValue($root['result'] ?? $root['Result'] ?? []);
        $usage = self::arrayValue($root['usage'] ?? $response['usage'] ?? $result['usage'] ?? $result['Usage'] ?? []);
        $actual = 0.0;
        foreach ([
            $usage['total_tokens'] ?? null,
            $usage['totalTokens'] ?? null,
            $usage['token_count'] ?? null,
            $usage['tokenCount'] ?? null,
            $usage['usage_tokens'] ?? null,
            $root['total_tokens'] ?? null,
            $root['totalTokens'] ?? null,
            $result['total_tokens'] ?? null,
            $result['totalTokens'] ?? null,
            $usage['quantity'] ?? null,
            $usage['duration'] ?? null,
            $usage['seconds'] ?? null,
            $usage['output_seconds'] ?? null,
            $root['duration'] ?? null,
            $root['seconds'] ?? null,
            $response['duration'] ?? null,
        ] as $value) {
            if (is_numeric($value) && (float)$value > 0) {
                $actual = (float)$value;
                break;
            }
        }
        return ['actual_quantity' => $actual, 'submitted_quantity' => $quantity, 'upstream_usage' => $usage];
    }
    private static function settlementQuantity(array $snapshot, float $submitted, array $usage): float
    {
        $unit = strtolower((string)($snapshot['usage_unit'] ?? ''));
        $actual = (float)($usage['actual_quantity'] ?? 0);
        // Fixed-call SKUs are always settled as one submitted call. Only
        // measured second/token SKUs can change the reserved quantity.
        if ($actual > 0 && (str_contains($unit, 'second') || str_contains($unit, 'sec') || str_contains($unit, 'token'))) {
            return max(1, $actual);
        }
        return $submitted;
    }
    private static function root(array $data): array { return self::arrayValue($data['data'] ?? $data); }
    private static function status(array $data): string { $root = self::root($data); return strtolower((string)($data['status'] ?? $data['state'] ?? $root['status'] ?? $root['state'] ?? $root['task_status'] ?? '')); }
    private static function taskId(array $data): string
    {
        $root = self::root($data);
        $result = self::arrayValue($root['result'] ?? $root['Result'] ?? []);
        foreach ([
            $data['task_id'] ?? null, $data['TaskId'] ?? null, $data['upstream_task_id'] ?? null, $data['upstreamTaskId'] ?? null, $data['id'] ?? null,
            $root['task_id'] ?? null, $root['TaskId'] ?? null, $root['upstream_task_id'] ?? null, $root['upstreamTaskId'] ?? null, $root['id'] ?? null,
            $root['task']['id'] ?? null, $root['task']['task_id'] ?? null,
            $result['task_id'] ?? null, $result['TaskId'] ?? null, $result['id'] ?? null,
        ] as $value) if (is_scalar($value) && (string)$value !== '') return (string)$value;
        return '';
    }
    private static function requestId(array $data): string
    {
        $root = self::root($data);
        foreach ([$data['request_id'] ?? null, $data['requestId'] ?? null, $root['request_id'] ?? null, $root['requestId'] ?? null, $root['RequestId'] ?? null] as $value) {
            if (is_scalar($value) && (string)$value !== '') return (string)$value;
        }
        return '';
    }
    private static function responseIdentifier(array $data, array $keys): string
    {
        $wanted = array_flip(array_map(static fn(string $key): string => strtolower(str_replace('_', '', $key)), $keys));
        $queue = [$data];
        while ($queue !== []) {
            $node = array_shift($queue);
            if (!is_array($node)) continue;
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $queue[] = $value;
                    continue;
                }
                $normalized = strtolower(str_replace('_', '', (string)$key));
                if (isset($wanted[$normalized]) && is_scalar($value) && trim((string)$value) !== '') {
                    return trim((string)$value);
                }
            }
        }
        return '';
    }
    private static function error(array $data): string
    {
        $root = self::root($data);
        foreach ([
            $data['message'] ?? null,
            $data['msg'] ?? null,
            $root['message'] ?? null,
            $root['msg'] ?? null,
            self::arrayValue($data['error'] ?? [])['message'] ?? null,
            self::arrayValue($data['error'] ?? [])['msg'] ?? null,
            self::arrayValue($root['error'] ?? [])['message'] ?? null,
            self::arrayValue($root['error'] ?? [])['msg'] ?? null,
            self::arrayValue(self::arrayValue($data['data'] ?? [])['error'] ?? [])['message'] ?? null,
            self::arrayValue(self::arrayValue($data['data'] ?? [])['error'] ?? [])['msg'] ?? null,
        ] as $value) {
            if (is_scalar($value) && trim((string)$value) !== '' && strtolower(trim((string)$value)) !== 'success') {
                return mb_substr(trim((string)$value), 0, 1000);
            }
        }
        foreach ([$data['error'] ?? null, $root['error'] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') return mb_substr(trim($value), 0, 1000);
        }
        return '视频模型调用失败';
    }
    private static function videos(array $data, int $tenantId, int $userId, ?array $forcedStorageConfig = null): array
    {
        $urls = self::videoUrls($data);
        if ($urls === []) {
            return [];
        }
        $rows = [];
        $errors = [];
        foreach ($urls as $url) {
            try {
                if ($forcedStorageConfig === null && !AiTaskResultStorageService::transferEnabled($tenantId)) {
                    $rows[] = [
                        'video_uri' => $url,
                        'width' => 0,
                        'height' => 0,
                        'storage_scope' => '',
                        'storage_engine' => '',
                        'storage_domain' => '',
                    ];
                    continue;
                }
                $stored = AigcVideoAssetService::persistGeneratedVideo($url, $tenantId, $userId, $forcedStorageConfig);
                $rows[] = [
                    'video_uri' => (string)$stored['uri'],
                    'width' => (int)($stored['width'] ?? 0),
                    'height' => (int)($stored['height'] ?? 0),
                    'storage_scope' => (string)($stored['storage_scope'] ?? 'tenant'),
                    'storage_engine' => (string)($stored['storage_engine'] ?? ''),
                    'storage_domain' => (string)($stored['storage_domain'] ?? ''),
                ];
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($rows === [] && $errors !== []) {
            throw new Exception('video result save failed: ' . mb_substr((string)$errors[0], 0, 500));
        }
        return $rows;
    }

    private static function shortDramaTransferStorageConfig(string $appCode, int $tenantId): ?array
    {
        if ($appCode !== AigcShortDramaService::APP_CODE) {
            return null;
        }
        return AigcShortDramaService::resultTransferStorageConfig($tenantId);
    }

    /** Match the established Happy Horse result contract, including signed URLs without a file extension. */
    private static function videoUrls(array $data): array
    {
        $candidates = [
            $data['video_url'] ?? null, $data['videoUrl'] ?? null, $data['url'] ?? null,
            $data['data']['video_url'] ?? null, $data['data']['videoUrl'] ?? null, $data['data']['url'] ?? null,
            $data['result']['video_url'] ?? null, $data['result']['videoUrl'] ?? null,
            $data['data']['result']['video_url'] ?? null, $data['data']['result']['videoUrl'] ?? null,
            $data['result']['videos'] ?? null, $data['data']['result']['videos'] ?? null,
            $data['videos'] ?? null, $data['data']['videos'] ?? null,
            $data['results'] ?? null, $data['data']['results'] ?? null,
            $data['outputs'] ?? null, $data['data']['outputs'] ?? null,
            $data['output'] ?? null, $data['data']['output'] ?? null,
            $data['content'] ?? null, $data['data']['content'] ?? null,
            $data['display'] ?? null, $data['data']['display'] ?? null,
            $data['result'] ?? null, $data['data']['result'] ?? null,
        ];
        $urls = [];
        foreach ($candidates as $candidate) {
            self::collectVideoUrls($candidate, $urls);
            if ($urls !== []) {
                break;
            }
        }
        return array_values(array_unique($urls));
    }

    private static function collectVideoUrls($value, array &$urls, int $depth = 0): void
    {
        if ($depth > 8) return;
        if (is_string($value)) {
            if (self::isVideoUrlCandidate($value)) $urls[] = trim($value);
            return;
        }
        if (!is_array($value)) return;
        foreach (['url', 'video_url', 'videoUrl', 'video', 'uri', 'src', 'origin_url', 'download_url', 'file_url', 'output_url'] as $key) {
            if (!empty($value[$key]) && is_string($value[$key]) && self::isVideoUrlCandidate($value[$key])) {
                $urls[] = trim($value[$key]);
            }
        }
        foreach (['outputs', 'output', 'videos', 'results', 'result', 'content', 'display', 'data'] as $key) {
            if (array_key_exists($key, $value)) self::collectVideoUrls($value[$key], $urls, $depth + 1);
        }
        foreach ($value as $item) {
            if (is_string($item) && self::isVideoUrlCandidate($item)) {
                $urls[] = trim($item);
            } elseif (is_array($item)) {
                self::collectVideoUrls($item, $urls, $depth + 1);
            }
        }
    }

    private static function isVideoUrlCandidate(string $value): bool
    {
        $value = trim($value);
        if ($value === '') return false;
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, 'data:video/')) return true;
        $path = ltrim((string)(parse_url($value, PHP_URL_PATH) ?: $value), '/');
        return (str_starts_with($path, 'uploads/') || str_starts_with($path, 'resource/'))
            && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['mp4', 'webm', 'mov', 'm4v'], true);
    }
    private static function origin(): string { $source = UpdateSourceClient::getSource(); $parts = parse_url(trim((string)($source['active_base_url'] ?? $source['base_url'] ?? ''))); if (empty($parts['host'])) throw new Exception('视频 API 暂不可用'); return (string)($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . (int)$parts['port'] : ''); }
    private static function endpoint(string $app, string $api): string { return self::origin() . '/api/v1/apps/' . rawurlencode($app) . '/' . rawurlencode($api); }
    private static function request(string $method, string $url, array $payload = [], bool $allowApplicationError = false): array { $source = UpdateSourceClient::getSource(); $key = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? '')); if ($key === '') throw new Exception('视频 API 暂不可用'); $ch = curl_init(); curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Accept: application/json', 'Content-Type: application/json'], CURLOPT_SSL_VERIFYPEER => UpdateSourceClient::sslVerify($source), CURLOPT_SSL_VERIFYHOST => UpdateSourceClient::sslVerify($source) ? 2 : 0]); if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); } $body = curl_exec($ch); $errno = curl_errno($ch); $error = curl_error($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); if ($errno) throw new Exception($error ?: '视频 API 网络请求失败'); $data = json_decode((string)$body, true); if (!is_array($data)) throw new Exception('视频 API 响应格式错误'); $hasApplicationError = isset($data['error']) || (isset($data['code']) && is_numeric($data['code']) && (int)$data['code'] !== 1); if ($http >= 400 || $hasApplicationError) throw new Exception(self::error($data)); return $data; }
    private static function recordRefreshError(int $consumptionId, string $taskId, \Throwable $e): void
    {
        $message = mb_substr($e->getMessage() ?: '视频任务查询失败', 0, 1000);
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
        Log::warning('Market video refresh retrying: consumption=' . $consumptionId . ' task=' . $taskId . ' error=' . $message);
    }
    private static function context(int $id, bool $lock): ?array { $query = AiConsumptionLog::where('id', $id); if ($lock) $query->lock(true); $c = $query->findOrEmpty(); if ($c->isEmpty()) return null; $taskQuery = AiAppTask::where('id', (int)$c['app_task_id']); if ($lock) $taskQuery->lock(true); $task = $taskQuery->findOrEmpty(); return $task->isEmpty() ? null : ['consumption' => $c, 'app_task' => $task]; }
    private static function response(array $c): array { $summary = self::arrayValue($c['response_summary'] ?? []); return ['status' => (string)$c['run_status'], 'provider_task_id' => (string)$c['upstream_task_id'], 'provider_request_id' => (string)$c['upstream_request_id'], 'videos' => (array)($summary['videos'] ?? [])]; }
    private static function event(int $id, string $type, string $status, array $summary): void { AiConsumptionEvent::create(['consumption_id' => $id, 'event_type' => $type, 'event_status' => $status, 'attempt_no' => 1, 'payload_summary' => $summary, 'payload_ciphertext' => '', 'http_status' => 0, 'elapsed_ms' => 0, 'create_time' => time()]); }
    private static function extra(AiAppTask $task, AiConsumptionLog $c, string $stage): array { return ['app_code' => (string)$task['app_code'], 'app_task_id' => (int)$task['id'], 'app_task_no' => (string)$task['task_no'], 'consumption_id' => (int)$c['id'], 'consume_no' => (string)$c['consume_no'], 'billing_stage' => $stage]; }
    private static function assetLabel(string $type): string { return ['image' => 'image', 'video' => 'video', 'audio' => 'audio'][$type] ?? 'asset'; }
    private static function arrayValue($value): array { if (is_array($value)) return $value; if (is_string($value) && $value !== '') { $data = json_decode($value, true); return is_array($data) ? $data : []; } return []; }
    private static function points(float $value): float { return round(max(0, $value), 6); }
    private static function timestamp($value): int
    {
        if (is_numeric($value)) return (int)$value;
        $time = strtotime(trim((string)$value));
        return $time === false ? 0 : $time;
    }
    private static function no(string $prefix): string { return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(5))); }
}
