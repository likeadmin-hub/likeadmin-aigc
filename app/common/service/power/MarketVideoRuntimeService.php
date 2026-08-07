<?php

namespace app\common\service\power;

use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionEvent;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\model\power\TenantPowerMarketSkuPrice;
use app\common\service\ai\AiTaskLifecycleEventService;
use app\common\service\ai\AiTaskJobService;
use app\common\service\ai\AiTaskResultUrlService;
use app\common\service\ai\AiTaskResultStorageService;
use app\common\service\ai\MarketAppGateService;
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

    public static function options(int $tenantId, string $resourceType): array
    {
        $where = ['resource_type' => $resourceType, 'status' => 1];
        if ($resourceType === PowerMarketService::TYPE_MODEL) {
            $where['model_type'] = 'video';
        }
        $products = PowerMarketProduct::where($where)->order(['update_time' => 'desc', 'id' => 'desc'])->select()->toArray();
        TenantPowerMarketService::applyProductDisplays($tenantId, $products);
        $options = [];
        foreach ($products as $product) {
            if ($resourceType === PowerMarketService::TYPE_APP_API
                && (!self::isVideoGenerationApi($product) || !self::isSupportedAppProduct($product))) {
                continue;
            }
            if ($resourceType === PowerMarketService::TYPE_APP_API && self::isShadowedAppApi($product, $products)) {
                continue;
            }
            $skus = self::availableSkus($tenantId, (int)$product['id']);
            if ($skus === []) {
                $options[] = self::unavailableOption($resourceType, $product, '暂无可售 SKU');
                continue;
            }
            $validSkus = [];
            foreach ($skus as $market) {
                $sku = self::formatSku($market, $product);
                $validSkus[] = $sku;
            }
            if ($validSkus === []) {
                $options[] = self::unavailableOption($resourceType, $product, '暂无可用规格');
                continue;
            }
            $metadata = self::metadata($product);
            $category = $resourceType === PowerMarketService::TYPE_APP_API
                ? PowerMarketService::appCategory($product)
                : ['id' => 0, 'code' => 'video', 'name' => '视频生成'];
            $categoryId = (int)($category['id'] ?? $category['category_id'] ?? ($category['category']['id'] ?? 0));
            $categoryCode = (string)($category['code'] ?? $category['category_code'] ?? ($category['category']['code'] ?? ''));
            $categoryName = (string)($category['name'] ?? $category['category_name'] ?? ($category['category']['name'] ?? ''));
            if ($resourceType === PowerMarketService::TYPE_APP_API && $categoryCode === '') {
                $categoryCode = 'video';
                $categoryName = $categoryName ?: '视频生成';
            }
            $id = self::optionId($resourceType, $product);
            $resolutions = array_values(array_unique(array_filter(array_map(static fn(array $row): string => (string)$row['resolution'], $validSkus))));
            $durations = array_values(array_unique(array_merge(...array_map(
                static fn(array $row): array => (array)($row['duration_options'] ?? []),
                $validSkus
            ))));
            $metadataDurations = self::durationOptions($metadata);
            if (self::hasConfigurableDurationSku($validSkus)) {
                // Only expose durations declared by the SKU or the synchronized
                // product schema. Provider-wide defaults are not a model contract.
                $durations = array_values(array_unique(array_merge($durations, $metadataDurations)));
            } elseif ($durations === []) {
                $durations = $metadataDurations;
            }
            sort($durations);
            $defaultDuration = self::defaultDurationOption($metadata, $durations);
            $requiresConcreteRatio = self::requiresConcreteTextToVideoRatio($product, $metadata);
            $restrictRatioOptions = $requiresConcreteRatio && !self::isFullVideoProduct($product) && !self::isH3Product($product);
            $ratios = self::ratiosForSkus($validSkus, $metadata);
            if ($restrictRatioOptions) {
                $ratios = self::concreteRatioOptions($ratios);
                if ($ratios === []) {
                    $ratios = [self::defaultConcreteTextToVideoRatio($validSkus, $metadata)];
                }
            }
            $qualities = array_map(static function (string $resolution) use ($validSkus, $ratios, $restrictRatioOptions): array {
                $matched = array_values(array_filter($validSkus, static fn(array $sku): bool => (string)$sku['resolution'] === $resolution));
                $firstSku = $matched[0] ?? $validSkus[0] ?? [];
                $qualityRatios = self::ratiosForSkus($matched, []);
                if ($restrictRatioOptions) {
                    $qualityRatios = self::concreteRatioOptions($qualityRatios);
                }
                if ($qualityRatios === []) {
                    $qualityRatios = $ratios;
                }
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
                        'usage_unit_size' => (float)($firstSku['usage_unit_size'] ?? 1),
                    ], $qualityRatios),
                ];
            }, $resolutions);
            $first = $validSkus[0];
            $options[] = [
                'id' => $id,
                'value' => $id,
                'resource_type' => $resourceType,
                'resource_type_label' => $resourceType === PowerMarketService::TYPE_MODEL ? '模型 API' : '应用 API',
                'model_type' => (string)($product['model_type'] ?? ''),
                'category_id' => $categoryId,
                'category_code' => $categoryCode,
                'category_name' => $categoryName,
                'market_product_id' => (int)$product['id'],
                'name' => self::displayName($product),
                'description' => (string)($product['description'] ?? ''),
                'display_icon' => (string)($product['display_icon'] ?? ''),
                'model_code' => (string)$product['upstream_model_code'],
                'channel_code' => (string)$product['upstream_channel_code'],
                'app_code' => (string)$product['upstream_app_code'],
                'api_code' => (string)$product['upstream_api_code'],
                'resolutions' => array_map(static fn(string $value): array => ['value' => $value, 'label' => strtoupper($value)], $resolutions),
                'resolution_options' => array_map(static fn(string $value): array => ['value' => $value, 'label' => strtoupper($value)], $resolutions),
                'qualities' => $qualities,
                'ratio_options' => $ratios,
                'specs' => array_map(static fn(array $row): array => ['market_sku_id' => (int)$row['market_sku_id'], 'resolution' => (string)$row['resolution'], 'quality' => (string)$row['resolution'], 'duration' => (int)$row['duration'], 'duration_options' => (array)($row['duration_options'] ?? []), 'ratio' => self::skuRatio((array)$row['locked_params']), 'ratio_options' => self::skuRatios($row), 'model' => (string)($row['model'] ?? ''), 'pricing_variant' => (string)($row['pricing_variant'] ?? ''), 'tenant_unit_price' => (float)$row['tenant_unit_price'], 'platform_unit_cost' => (float)$row['platform_unit_cost'], 'usage_unit' => (string)$row['usage_unit'], 'usage_unit_size' => (float)($row['usage_unit_size'] ?? 1), 'settlement_mode' => (string)($row['settlement_mode'] ?? 'reserved'), 'input_mode' => (string)($row['input_mode'] ?? 'text_to_video'), 'provider_params_json' => $row['locked_params']], $validSkus),
                'durations' => $durations,
                'duration_options' => $durations,
                'input_modes' => self::inputModes($resourceType, $product, $metadata),
                'params_schema' => self::arrayValue($metadata['params_schema'] ?? []),
                'default_params' => self::arrayValue($metadata['default_params'] ?? []),
                'content_schema' => self::arrayValue($metadata['content_schema'] ?? []),
                'capabilities' => self::arrayValue($metadata['capabilities'] ?? []),
                'developer_doc_slug' => (string)($metadata['developer_doc_slug'] ?? ''),
                'api_doc' => (string)($metadata['api_doc'] ?? ''),
                'supported_asset_types' => self::supportedAssetTypes($product, $metadata),
                'max_reference_images' => self::referenceLimit($product, $metadata, 'image'),
                'max_reference_videos' => self::referenceLimit($product, $metadata, 'video'),
                'max_reference_audios' => self::referenceLimit($product, $metadata, 'audio'),
                'max_reference_assets' => self::referenceAssetLimit($product, $metadata),
                'reference_audio_requires_visual' => self::referenceAudioRequiresVisual($product, $metadata),
                'frame_and_reference_mutually_exclusive' => self::frameAndReferenceMutuallyExclusive($product, $metadata),
                'generation_modes' => self::generationModes($product, $metadata),
                'supports_first_last_frame' => self::supportsFirstLastFrame($product, $metadata),
                'skus' => $validSkus,
                'default_resolution' => (string)($resolutions[0] ?? ''),
                'default_duration' => $defaultDuration,
                'billing_unit' => (string)$first['usage_unit'],
                'billing_unit_size' => (float)($first['usage_unit_size'] ?? 1),
                'platform_unit_cost' => min(array_column($validSkus, 'platform_unit_cost')),
                'tenant_unit_price' => min(array_column($validSkus, 'tenant_unit_price')),
                'status' => 1,
                'enabled' => true,
                'available' => true,
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
     * Returns the supplier capabilities that are safe to expose to business
     * callers. These values describe implemented request adapters, not merely
     * catalog metadata.
     */
    public static function capabilities(int $tenantId, array $selection): array
    {
        $market = self::resolve($tenantId, $selection);
        $product = (array)$market['product'];
        $metadata = self::metadata($product);
        return [
            'generation_modes' => self::generationModes($product, $metadata),
            'supports_first_last_frame' => self::supportsFirstLastFrame($product, $metadata),
            'supported_asset_types' => self::supportedAssetTypes($product, $metadata),
            'max_reference_images' => self::referenceLimit($product, $metadata, 'image'),
            'max_reference_videos' => self::referenceLimit($product, $metadata, 'video'),
            'max_reference_audios' => self::referenceLimit($product, $metadata, 'audio'),
            'max_reference_assets' => self::referenceAssetLimit($product, $metadata),
            'reference_audio_requires_visual' => self::referenceAudioRequiresVisual($product, $metadata),
            'frame_and_reference_mutually_exclusive' => self::frameAndReferenceMutuallyExclusive($product, $metadata),
        ];
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
        $durationSchema = self::durationSchema($metadata);
        foreach (['default', 'value'] as $key) {
            if (isset($durationSchema[$key]) && is_numeric($durationSchema[$key]) && (int)$durationSchema[$key] > 0) {
                return self::preferredSupportedDuration($market['product'], (int)$durationSchema[$key]);
            }
        }
        return self::preferredSupportedDuration($market['product'], $fallback);
    }

    /** @return array{app_task_id:int,consumption_id:int,consume_no:string,market_snapshot:array<string,mixed>} */
    public static function reserve(int $tenantId, int $userId, string $appCode, string $action, string $businessTable, string $businessTaskId, array $selection, array $request, array $billingOverride = []): array
    {
        if (MarketAppGateService::requiresGate($appCode)) {
            MarketAppGateService::requireMarket($tenantId, $userId, $appCode, (string)($request['idempotency_key'] ?? $businessTaskId));
        }
        $idempotencyKey = self::reservationKey($tenantId, $appCode, $action, $businessTable, $businessTaskId);
        $existing = self::existingReservation($tenantId, $idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }
        CanvasVideoPromptSubmissionGuard::assertPrepared($appCode, $selection);
        $market = self::resolve($tenantId, $selection);
        self::assertAssets($market, $request);
        self::assertTextToVideoRatio($market, $request);
        $quantity = self::quantity($market, $selection + $request);
        $quote = self::applyBillingOverride(self::quoteMarket($market, $quantity), $billingOverride);
        $deferredUsage = self::isDeferredUsageSku($market['sku']);
        if ($deferredUsage && $billingOverride !== []) {
            throw new Exception('按应用售价结算暂不支持按实际用量计费的视频 SKU');
        }
        if (!$deferredUsage) {
            PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$quote['tenant_cost_points'], (float)$quote['user_charge_points']);
        }
        return Db::transaction(function () use ($tenantId, $userId, $appCode, $action, $businessTable, $businessTaskId, $request, $market, $quantity, $quote, $deferredUsage, $billingOverride, $idempotencyKey) {
            $existing = self::existingReservation($tenantId, $idempotencyKey, true);
            if ($existing !== null) {
                return $existing;
            }
            $now = time();
            $appTask = AiAppTask::create([
                'task_no' => self::no('AT'), 'tenant_id' => $tenantId, 'user_id' => $userId,
                'app_code' => $appCode, 'action_code' => $action, 'business_table' => $businessTable, 'business_id' => 0, 'parent_task_id' => 0,
                'status' => 'running', 'progress' => 10, 'request_summary' => self::requestSummary($request), 'result_summary' => [],
                'estimated_tenant_cost' => $quote['tenant_cost_points'], 'estimated_user_price' => $quote['user_charge_points'],
                'actual_tenant_cost' => 0, 'actual_user_price' => 0,
                'idempotency_key' => $idempotencyKey,
                'create_time' => $now, 'update_time' => $now, 'finish_time' => 0,
            ]);
            $consumeNo = self::no('C');
            $snapshot = self::applyBillingOverrideToSnapshot(self::snapshot($market, $quantity), $quote, $billingOverride);
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

    private static function reservationKey(int $tenantId, string $appCode, string $action, string $businessTable, string $businessTaskId): string
    {
        return sha1($tenantId . '|' . $appCode . '|' . $action . '|' . $businessTable . '|' . $businessTaskId . '|market_video');
    }

    private static function existingReservation(int $tenantId, string $idempotencyKey, bool $lock = false): ?array
    {
        $taskQuery = AiAppTask::where(['tenant_id' => $tenantId, 'idempotency_key' => $idempotencyKey]);
        if ($lock) {
            $taskQuery->lock(true);
        }
        $task = $taskQuery->findOrEmpty();
        if ($task->isEmpty()) {
            return null;
        }
        $consumptionQuery = AiConsumptionLog::where('app_task_id', (int)$task['id'])->order('id', 'asc');
        if ($lock) {
            $consumptionQuery->lock(true);
        }
        $consumption = $consumptionQuery->findOrEmpty();
        if ($consumption->isEmpty()) {
            throw new Exception('Existing idempotent market task has no consumption record');
        }
        return [
            'app_task_id' => (int)$task['id'],
            'consumption_id' => (int)$consumption['id'],
            'consume_no' => (string)$consumption['consume_no'],
            'market_snapshot' => self::arrayValue($consumption['price_snapshot'] ?? []),
        ];
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
                self::requiresDurableResultStorage((string)$context['app_task']['app_code'])
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
        if (!in_array((string)$c['billing_status'], ['reserved', 'pending_usage'], true)) {
            self::clearTerminalRefreshDiagnostics((int)$c['id']);
            return self::response($c->toArray());
        }
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
                self::requiresDurableResultStorage((string)$context['app_task']['app_code'])
            );
            $upstreamStatus = self::status($response);
            $upstreamRejected = !empty($response['error'])
                || (isset($response['code']) && is_numeric($response['code']) && (int)$response['code'] !== 1);
            if ($upstreamRejected || in_array($upstreamStatus, ['failed', 'error', 'canceled', 'cancelled', 'rejected'], true)) {
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
            if (AiTaskLifecycleEventService::isTerminalSuccess($upstreamStatus)) {
                if (AiTaskLifecycleEventService::terminalResultMissing($consumptionId, $upstreamStatus, $taskId)) {
                    self::fail($consumptionId, '上游视频任务已完成，但未返回可用结果文件', 'upstream_result_missing');
                    return ['status' => 'failed', 'provider_task_id' => $taskId, 'videos' => []];
                }
                return ['status' => 'running', 'provider_task_id' => $taskId, 'videos' => []];
            }
            self::event($consumptionId, 'poll', 'running', ['upstream_task_id' => $taskId, 'upstream_status' => $upstreamStatus]);
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
                $c->save(self::clearRefreshDiagnostics([
                    'run_status' => 'success', 'billing_status' => 'pending_usage',
                    'upstream_request_id' => $requestId ?: (string)$c['upstream_request_id'],
                    'upstream_task_id' => $taskId ?: (string)$c['upstream_task_id'],
                    'usage_snapshot' => $usage,
                    'response_summary' => ['video_count' => count($videos), 'videos' => $videos],
                    'update_time' => $now,
                ]));
                $task->save(['status' => 'success', 'progress' => 100, 'finish_time' => $now, 'update_time' => $now]);
                self::event((int)$c['id'], 'await_usage', 'pending', ['video_count' => count($videos)]);
                return;
            }
            $quantity = self::settlementQuantity($snapshot, $submittedQuantity, $usage);
            $tenant = self::priceForQuantity((float)($snapshot['platform_price'] ?? 0), $quantity, $snapshot);
            $user = array_key_exists('fixed_user_price', $snapshot)
                ? self::points((float)$snapshot['fixed_user_price'])
                : self::priceForQuantity((float)($snapshot['tenant_price'] ?? 0), $quantity, $snapshot);
            if ((string)$c['billing_status'] === 'pending_usage') {
                PointService::assertCanConsumeAmounts((int)$c['tenant_id'], (int)$c['user_id'], $tenant, $user);
                PointService::consumeBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], $tenant, $user, (string)$c['consume_no'], '算力市场视频实际用量结算', self::extra($task, $c, 'settled'));
            } else {
                PointService::settleReservedBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], (float)$c['reserved_tenant_cost'], (float)$c['reserved_user_price'], $tenant, $user, (string)$c['consume_no'], '算力市场视频结算', self::extra($task, $c, 'settled'));
            }
            $now = time();
            $usage['settled_quantity'] = $quantity;
            $usage['settlement_basis'] = $quantity !== $submittedQuantity ? 'actual_usage' : 'submit_snapshot';
            $c->save(self::clearRefreshDiagnostics(['run_status' => 'success', 'billing_status' => 'settled', 'upstream_request_id' => $requestId ?: (string)$c['upstream_request_id'], 'upstream_task_id' => $taskId ?: (string)$c['upstream_task_id'], 'quantity' => $quantity, 'usage_snapshot' => $usage, 'response_summary' => ['video_count' => count($videos), 'videos' => $videos], 'actual_tenant_cost' => $tenant, 'actual_user_price' => $user, 'finish_time' => $now, 'update_time' => $now]));
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
                    'selected market SKU does not support current aspect ratio',
                    'market sku does not support current input mode',
                    '所选市场 SKU 不支持当前时长',
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
        $matches = self::availableSkus($tenantId, $productId); $quality = self::value($selection, ['resolution', 'quality']); $duration = (int)self::value($selection, ['duration']); $ratio = self::value($selection, ['ratio', 'aspect_ratio', 'size']); $mode = self::inputMode($selection);
        $matches = array_values(array_filter($matches, static function (array $row) use ($quality, $duration, $ratio, $mode, $productData): bool {
            $locked = self::arrayValue($row['sku']['locked_params'] ?? []); $resolution = self::resolution($locked); $lockedDuration = self::duration($locked); $skuMode = self::skuInputMode($locked);
            if ($quality !== '' && $resolution !== '' && strtolower($quality) !== strtolower($resolution)) return false;
            if ($duration > 0 && $lockedDuration > 0 && $lockedDuration !== $duration) return false;
            if (!self::skuSupportsRatio($locked, self::arrayValue($row['sku']['selectable_params'] ?? []), $ratio)) return false;
            if (!self::skuSupportsInputMode($skuMode, $mode, $productData, $locked)) return false;
            return true;
        }));
        if ($matches === []) throw new Exception('当前模型没有可用的市场计费 SKU');
        $market = self::marketRow($tenantId, $productData, (array)$matches[0]['sku']);
        self::assertSkuMatchesSelection($market, $selection);
        return $market;
    }

    private static function productAllowed(array $product, string $wanted = ''): bool
    {
        $type = (string)$product['resource_type'];
        if ($wanted !== '' && $type !== $wanted) return false;
        if ($type === PowerMarketService::TYPE_MODEL) return (string)$product['model_type'] === 'video';
        return $type === PowerMarketService::TYPE_APP_API && self::isSupportedAppProduct($product);
    }

    public static function isSupportedAppProduct(array $product): bool
    {
        // The market catalogue is the source of truth for an app API's
        // business category. Video-capable adapters such as lip-sync or
        // action-transfer must not be promoted to video-generation options.
        if (!self::isVideoAppCategory($product)) {
            return false;
        }
        if (!self::isVideoGenerationApi($product)) {
            return false;
        }
        if (self::hasNonVideoAppIdentity($product)) {
            return false;
        }
        $metadata = self::metadata($product);
        $generationModes = self::generationModes($product, $metadata);
        $assetTypes = self::supportedAssetTypes($product, $metadata);
        $videoModes = ['text_to_video', 'image_to_video', 'video_edit', 'omni_reference', 'start_end', 'multi_frame'];
        $hasVideoCapability = $generationModes !== [] && array_intersect($generationModes, $videoModes) !== [];
        if (!$hasVideoCapability && !in_array('video', $assetTypes, true) && !self::hasVideoAppIdentity($product)) {
            return false;
        }
        $app = strtolower((string)($product['upstream_app_code'] ?? ''));
        // query is an internal task-lifecycle contract, not a separately sold
        // SKU. Its product only needs to remain published by the platform.
        $query = PowerMarketProduct::where([
            'resource_type' => PowerMarketService::TYPE_APP_API,
            'upstream_app_code' => $app,
            'upstream_api_code' => 'query',
            'status' => 1,
        ])->findOrEmpty();
        return !$query->isEmpty();
    }

    private static function isVideoAppCategory(array $product): bool
    {
        return (string)(PowerMarketService::appCategory($product)['category_code'] ?? '') === 'video';
    }

    /**
     * Only task-generation APIs are selectable. Query and asset-management
     * endpoints remain internal dependencies of a submitted task.
     */
    private static function isVideoGenerationApi(array $product): bool
    {
        $api = strtolower(trim((string)($product['upstream_api_code'] ?? '')));
        if ($api === '') {
            return false;
        }
        return !preg_match('/(?:^|[_-])(query|status|detail|list|template|asset|group|upload|download|delete|remove|update|lyrics|vox|midi|wav|mp4|timing|voice|clone|stt|tts)(?:$|[_-])/i', $api)
            && !in_array($api, ['query', 'getasset', 'createasset', 'creategroup', 'updateasset', 'deleteasset'], true);
    }

    /**
     * Happy Horse publishes both `create` and `submit` for the same task
     * family. The submit contract is the superset (it also accepts video
     * references), so expose one stable application option and keep create as
     * a synchronized compatibility product only.
     */
    private static function isShadowedAppApi(array $product, array $products): bool
    {
        if (strtolower((string)($product['upstream_app_code'] ?? '')) !== 'happy_horse'
            || strtolower((string)($product['upstream_api_code'] ?? '')) !== 'create') {
            return false;
        }
        foreach ($products as $candidate) {
            if (strtolower((string)($candidate['upstream_app_code'] ?? '')) === 'happy_horse'
                && strtolower((string)($candidate['upstream_api_code'] ?? '')) === 'submit'
                && (int)($candidate['status'] ?? 0) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Some legacy application catalogues do not publish generation modes, but
     * their app identity is unambiguous (for example `full_video` or
     * `action_transfer`). Keep those adapters usable without making every
     * metadata-less API text-to-video by default.
     */
    private static function hasVideoAppIdentity(array $product): bool
    {
        $identity = strtolower(implode(' ', [
            (string)($product['upstream_app_code'] ?? ''),
            (string)($product['name'] ?? ''),
            (string)($product['description'] ?? ''),
        ]));
        if ($identity === '') {
            return false;
        }
        if (self::hasNonVideoAppIdentity($product)) {
            return false;
        }
        $asciiIdentity = preg_replace('/[^\x00-\x7F]/', '', $identity) ?: $identity;
        if (preg_match('/(?:video|wan|seedance|grok|happy[_-]?horse|full[_-]?video|image[_-]?human|lipsync|action[_-]?transfer|person[_-]?replacement)/i', $asciiIdentity) === 1) {
            return true;
        }
        return preg_match('/(?:video|wan|seedance|grok|happy[_-]?horse|full[_-]?video|image[_-]?human|lipsync|action[_-]?transfer|person[_-]?replacement|dressing[_-]?diffusion|视频|数字人|口型|动作迁移|人物替换|换装|全驱动)/iu', $identity) === 1;
    }

    private static function hasNonVideoAppIdentity(array $product): bool
    {
        $identity = strtolower(implode(' ', [
            (string)($product['upstream_app_code'] ?? ''),
            (string)($product['name'] ?? ''),
            (string)($product['description'] ?? ''),
        ]));
        $asciiIdentity = preg_replace('/[^\x00-\x7F]/', '', $identity) ?: $identity;
        if ($asciiIdentity !== '' && preg_match('/(?:music|audio|voice|tts|stt|watermark|nano[_-]?banana|smart[_-]?clip|dressing[_-]?diffusion|flashvsr|lyrics|midi|wav|mp4|timing)/i', $asciiIdentity) === 1) {
            return true;
        }
        return false;
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
        $requiresConcreteRatio = self::requiresConcreteTextToVideoRatio($product, $meta);
        return ['product_id' => (int)$product['id'], 'sku_id' => (int)$sku['id'], 'sku_key' => (string)$sku['sku_key'], 'resource_type' => (string)$product['resource_type'], 'model_code' => (string)$product['upstream_model_code'], 'channel_code' => (string)$product['upstream_channel_code'], 'app_code' => (string)$product['upstream_app_code'], 'api_code' => (string)$product['upstream_api_code'], 'params_schema' => self::arrayValue($meta['params_schema'] ?? []), 'locked_params' => self::arrayValue($sku['locked_params'] ?? []), 'requires_concrete_text_to_video_ratio' => $requiresConcreteRatio, 'default_text_to_video_ratio' => $requiresConcreteRatio ? self::defaultConcreteTextToVideoRatio([$market], $meta) : '', 'reference_audio_requires_visual' => self::referenceAudioRequiresVisual($product, $meta), 'frame_and_reference_mutually_exclusive' => self::frameAndReferenceMutuallyExclusive($product, $meta), 'usage_unit' => (string)$sku['usage_unit'], 'usage_unit_size' => max(1, (float)($sku['usage_unit_size'] ?? 1)), 'upstream_price' => (float)$sku['upstream_price'], 'platform_price' => (float)$sku['sale_points'], 'tenant_price' => (float)$market['tenant_price'], 'quantity' => $quantity, 'protocol' => (string)($meta['protocol'] ?? 'video_generate')];
    }

    private static function submitRequest(array $snapshot, array $request, string $idempotency, int $consumptionId): array
    {
        // Providers address references by their typed ordinal, while the UI
        // keeps the user's readable @asset-name prompt intact for auditing.
        $request['prompt'] = AigcVideoReferenceAssetService::promptWithReferenceAliases(
            (string)($request['prompt'] ?? ''),
            $request
        );
        if (($snapshot['resource_type'] ?? '') === PowerMarketService::TYPE_MODEL) return self::request('POST', self::origin() . self::MODEL_TASK_PATH, self::modelPayload($snapshot, $request, $idempotency));
        $app = (string)$snapshot['app_code'];
        if ($app === 'seedance') self::seedanceAssets($request, $consumptionId);
        return self::request('POST', self::endpoint($app, (string)$snapshot['api_code']), self::appPayload($snapshot, $request, $idempotency));
    }

    private static function queryRequest(array $snapshot, string $taskId): array
    {
        if (($snapshot['resource_type'] ?? '') === PowerMarketService::TYPE_MODEL) return self::request('GET', self::origin() . str_replace('{task_id}', rawurlencode($taskId), self::MODEL_QUERY_PATH), [], true);
        $app = (string)$snapshot['app_code']; $url = self::endpoint($app, 'query');
        // Application APIs declare their HTTP method independently. The
        // full_video query endpoint is POST-only; keep the legacy adapters'
        // existing contracts while sending its task ID in the request body.
        if ($app === 'happy_horse' || $app === 'full_video') {
            return self::request('POST', $url, ['task_id' => $taskId], true);
        }
        return self::request('GET', $url . '?task_id=' . rawurlencode($taskId), [], true);
    }

    private static function modelPayload(array $snapshot, array $request, string $idempotency): array
    {
        $locked = self::arrayValue($snapshot['locked_params'] ?? []);
        $assets = self::assets($request);
        $duration = self::duration($locked) ?: (int)($request['duration'] ?? 0);
        $quality = self::value($request, ['quality', 'resolution']) ?: self::resolution($locked);
        $prompt = trim((string)($request['prompt'] ?? ''));
        $ratio = self::payloadRatio($snapshot, $request, $assets);
        if (self::isH3Snapshot($snapshot)) {
            return self::h3Payload($snapshot, $request, $locked, $prompt, $ratio, $quality, $duration, $idempotency);
        }
        $promptKey = self::schemaParameterName($snapshot, ['content', 'prompt'], 'prompt');
        $ratioKey = self::schemaParameterName($snapshot, ['ratio', 'aspect_ratio', 'size'], 'aspect_ratio');
        // The model API accepts one contract-defined field for each value. Do
        // not retain aliases from a SKU when the upstream schema uses another
        // name; strict providers reject unknown or duplicate input fields.
        foreach (['prompt', 'content', 'aspect_ratio', 'ratio', 'size'] as $key) {
            unset($locked[$key]);
        }
        // Model API video calls share the existing AIGC video provider contract:
        // `quality` and `n` are required by multiple async channels. The
        // market stores `resolution` as UI metadata only, so do not replace the
        // supplier's quality parameter with it.
        $payload = array_merge($locked, self::marketContext($snapshot, 'power_market_video'), [
            'model' => (string)$snapshot['model_code'],
            'n' => max(1, (int)($request['quantity'] ?? 1)),
            'channel' => (string)$snapshot['channel_code'],
            'image_urls' => $assets['image'],
            'quality' => $quality,
            'duration' => $duration > 0 ? $duration : null,
            'negative_prompt' => trim((string)($request['negative_prompt'] ?? '')),
            'video_urls' => $assets['video'],
            'audio_urls' => $assets['audio'],
            'idempotency_key' => $idempotency,
        ]);
        $payload[$promptKey] = self::schemaParameterUsesArray($snapshot, $promptKey)
            ? self::schemaTextContent($snapshot, $promptKey, $prompt)
            : $prompt;
        $payload[$ratioKey] = $ratio;
        return array_filter($payload, static fn($value) => $value !== '' && $value !== null && $value !== []);
    }

    /**
     * A consuming app may define its tenant-facing sale price, but it can
     * never lower the platform cost sourced from the market SKU.
     */
    private static function applyBillingOverride(array $quote, array $billingOverride): array
    {
        if ($billingOverride === []) {
            return $quote;
        }
        if (array_key_exists('tenant_cost_points', $billingOverride)
            && abs((float)$billingOverride['tenant_cost_points'] - (float)$quote['tenant_cost_points']) > 0.000001) {
            throw new Exception('应用不能覆盖算力市场平台成本');
        }
        if (array_key_exists('user_charge_points', $billingOverride)) {
            $quote['user_charge_points'] = self::points((float)$billingOverride['user_charge_points']);
        }
        return $quote;
    }

    private static function applyBillingOverrideToSnapshot(array $snapshot, array $quote, array $billingOverride): array
    {
        if (!array_key_exists('user_charge_points', $billingOverride)) {
            return $snapshot;
        }
        $snapshot['fixed_user_price'] = self::points((float)$quote['user_charge_points']);
        $snapshot['app_sale_price_override'] = true;
        return $snapshot;
    }

    /** Terminal success must not retain a transient polling diagnostic. */
    private static function clearRefreshDiagnostics(array $state): array
    {
        $state['error_code'] = '';
        $state['error_message'] = '';
        $state['refresh_requested_at'] = 0;
        return $state;
    }

    private static function clearTerminalRefreshDiagnostics(int $consumptionId): void
    {
        AiConsumptionLog::where([
            'id' => $consumptionId,
            'run_status' => 'success',
            'billing_status' => 'settled',
        ])->where('error_code', '<>', '')->update([
            'error_code' => '',
            'error_message' => '',
            'refresh_requested_at' => 0,
            'update_time' => time(),
        ]);
    }

    private static function isH3Snapshot(array $snapshot): bool
    {
        return self::isH3Product([
            'upstream_model_code' => (string)($snapshot['model_code'] ?? ''),
            'upstream_channel_code' => (string)($snapshot['channel_code'] ?? ''),
        ]);
    }

    private static function h3Payload(array $snapshot, array $request, array $locked, string $prompt, string $ratio, string $resolution, int $duration, string $idempotency): array
    {
        if ($prompt === '') {
            throw new Exception('H3 video generation requires a text prompt.');
        }
        foreach (['prompt', 'content', 'aspect_ratio', 'ratio', 'size', 'quality', 'n', 'negative_prompt', 'image_urls', 'video_urls', 'audio_urls'] as $key) {
            unset($locked[$key]);
        }
        return array_filter(array_merge($locked, self::marketContext($snapshot, 'power_market_video'), [
            'model' => (string)$snapshot['model_code'],
            'channel' => (string)$snapshot['channel_code'],
            'ratio' => $ratio,
            'resolution' => $resolution,
            'duration' => $duration > 0 ? $duration : null,
            'content' => self::h3Content($request, $prompt),
            'idempotency_key' => $idempotency,
        ]), static fn($value) => $value !== '' && $value !== null && $value !== []);
    }

    private static function h3Content(array $request, string $prompt): array
    {
        $content = [['type' => 'text', 'text' => $prompt]];
        foreach (AigcVideoReferenceAssetService::normalize($request) as $asset) {
            $type = (string)($asset['type'] ?? '');
            $url = AigcVideoReferenceAssetService::publicUrl($asset);
            if ($url === '') {
                continue;
            }
            if ($type === 'image') {
                $role = match ((string)($asset['role'] ?? 'reference_image')) {
                    'first_frame_image' => 'first_frame',
                    'last_frame_image' => 'last_frame',
                    default => 'reference_image',
                };
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url], 'role' => $role];
                continue;
            }
            if ($type === 'video') {
                $content[] = ['type' => 'video_url', 'video_url' => ['url' => $url], 'role' => 'reference_video'];
                continue;
            }
            if ($type === 'audio') {
                $content[] = ['type' => 'audio_url', 'audio_url' => ['url' => $url], 'role' => 'reference_audio'];
            }
        }
        return $content;
    }

    private static function schemaParameterName(array $snapshot, array $candidates, string $fallback): string
    {
        $schema = self::arrayValue($snapshot['params_schema'] ?? []);
        foreach ($candidates as $candidate) {
            if (self::schemaDeclaresParameter($schema, $candidate)) {
                return $candidate;
            }
        }
        return $fallback;
    }

    private static function schemaDeclaresParameter(array $schema, string $parameter, int $depth = 0): bool
    {
        if ($depth > 6) {
            return false;
        }
        foreach ($schema as $key => $definition) {
            if (is_string($key) && strcasecmp($key, $parameter) === 0) {
                return true;
            }
            if (!is_array($definition)) {
                continue;
            }
            foreach (['name', 'key', 'field'] as $nameKey) {
                if (isset($definition[$nameKey]) && is_string($definition[$nameKey]) && strcasecmp($definition[$nameKey], $parameter) === 0) {
                    return true;
                }
            }
            if (self::schemaDeclaresParameter($definition, $parameter, $depth + 1)) {
                return true;
            }
        }
        return false;
    }

    private static function schemaParameterUsesArray(array $snapshot, string $parameter): bool
    {
        $definition = self::schemaParameterDefinition(self::arrayValue($snapshot['params_schema'] ?? []), $parameter);
        $type = strtolower(trim((string)($definition['type'] ?? $definition['data_type'] ?? $definition['value_type'] ?? '')));
        return in_array($type, ['array', 'list'], true);
    }

    private static function schemaTextContent(array $snapshot, string $parameter, string $prompt): array
    {
        $definition = self::schemaParameterDefinition(self::arrayValue($snapshot['params_schema'] ?? []), $parameter);
        $items = self::arrayValue($definition['items'] ?? $definition['item'] ?? []);
        $itemType = strtolower(trim((string)($items['type'] ?? $items['data_type'] ?? '')));
        if ($itemType === 'string') {
            return [$prompt];
        }
        return [['type' => 'text', 'text' => $prompt]];
    }

    private static function schemaParameterDefinition(array $schema, string $parameter, int $depth = 0): array
    {
        if ($depth > 6) {
            return [];
        }
        foreach ($schema as $key => $definition) {
            if (is_string($key) && strcasecmp($key, $parameter) === 0) {
                return is_array($definition) ? $definition : ['type' => (string)$definition];
            }
            if (!is_array($definition)) {
                continue;
            }
            foreach (['name', 'key', 'field'] as $nameKey) {
                if (isset($definition[$nameKey]) && is_string($definition[$nameKey]) && strcasecmp($definition[$nameKey], $parameter) === 0) {
                    return $definition;
                }
            }
            $nested = self::schemaParameterDefinition($definition, $parameter, $depth + 1);
            if ($nested !== []) {
                return $nested;
            }
        }
        return [];
    }

    private static function appPayload(array $snapshot, array $request, string $idempotency): array
    {
        $app = (string)$snapshot['app_code']; $locked = self::arrayValue($snapshot['locked_params'] ?? []); $assets = self::assets($request); $duration = self::duration($locked) ?: (int)($request['duration'] ?? 0); $resolution = self::value($request, ['resolution', 'quality']) ?: self::resolution($locked);
        if ($app === 'full_video') {
            $prompt = trim((string)($request['prompt'] ?? ''));
            if ($prompt === '') {
                throw new Exception('full_video generation requires a text prompt.');
            }
            foreach (['prompt', 'content', 'aspect_ratio', 'ratio', 'size', 'quality', 'n', 'negative_prompt', 'image_urls', 'video_urls', 'audio_urls'] as $key) {
                unset($locked[$key]);
            }
            $ratio = self::payloadRatio($snapshot, $request, $assets);
            if ($ratio === '' && ($assets['image'] !== [] || $assets['video'] !== [] || $assets['audio'] !== [])) {
                $ratio = 'adaptive';
            }
            return array_filter(array_merge($locked, self::marketContext($snapshot, 'power_market_app_api'), [
                'model' => trim((string)($locked['model'] ?? $snapshot['model_code'] ?? '')) ?: 'full-video',
                'content' => self::h3Content($request, $prompt),
                'resolution' => strtoupper($resolution ?: '480P'),
                'duration' => $duration > 0 ? $duration : null,
                'ratio' => $ratio,
                'aigc_watermark' => $request['aigc_watermark'] ?? $request['aigcWatermark'] ?? null,
                'callback_url' => $request['callback_url'] ?? $request['callbackUrl'] ?? null,
                'idempotency_key' => $idempotency,
            ]), static fn($value) => $value !== '' && $value !== [] && $value !== null);
        }
        if ($app === 'happy_horse') {
            $model = trim((string)($locked['model'] ?? ''));
            if ($model === '') {
                $model = $assets['image'] === [] ? 'happyhorse-1.0-t2v' : (count($assets['image']) === 1 ? 'happyhorse-1.0-i2v' : 'happyhorse-1.0-r2v');
            }
            return array_filter(array_merge($locked, self::marketContext($snapshot, 'power_market_app_api'), ['model' => $model, 'prompt' => trim((string)($request['prompt'] ?? '')), 'resolution' => strtoupper($resolution), 'duration' => $duration > 0 ? $duration : null, 'ratio' => (string)($request['ratio'] ?? ''), 'media' => array_map(static fn(string $url): array => ['url' => $url, 'type' => 'image'], $assets['image']), 'idempotency_key' => $idempotency]), static fn($value) => $value !== '' && $value !== [] && $value !== null);
        }
        if ($app === 'seedance') {
            $assetIds = self::seedanceAssetReferences($request);
            return array_filter(array_merge($locked, self::marketContext($snapshot, 'power_market_app_api'), ['model' => (string)($locked['model'] ?? ($assetIds['video'] === [] ? 'seedance-2-text-2-video' : 'seedance-2-video-2-video')), 'content' => [['type' => 'text', 'text' => trim((string)($request['prompt'] ?? ''))]], 'ratio' => (string)($request['ratio'] ?? ''), 'resolution' => $resolution, 'duration' => $duration > 0 ? $duration : null, 'image_urls' => $assetIds['image'], 'video_urls' => $assetIds['video'], 'audio_urls' => $assetIds['audio'], 'generate_audio' => $request['generate_audio'] ?? null, 'idempotency_key' => $idempotency]), static fn($value) => $value !== '' && $value !== [] && $value !== null);
        }
        if ($app === 'grok_video') {
            $model = trim((string)($locked['model'] ?? $snapshot['model_code'] ?? 'grok-video'));
            return array_filter(array_merge($locked, self::marketContext($snapshot, 'power_market_app_api'), ['model' => $model, 'prompt' => trim((string)($request['prompt'] ?? '')), 'resolution' => $resolution, 'duration' => $duration > 0 ? $duration : null, 'aspect_ratio' => (string)($request['aspect_ratio'] ?? $request['ratio'] ?? ''), 'image_urls' => $assets['image'], 'idempotency_key' => $idempotency]), static fn($value) => $value !== '' && $value !== [] && $value !== null);
        }
        $model = trim((string)($locked['model'] ?? ''));
        if ($model === '') {
            $model = trim((string)($snapshot['model_code'] ?? ''));
        }
        if ($model === '') {
            $model = $assets['video'] !== [] ? 'wan2.7-videoedit' : ($assets['image'] !== [] ? 'wan2.7-r2v' : 'wan2.7');
        }
        return array_filter(array_merge($locked, self::marketContext($snapshot, 'power_market_app_api'), ['model' => $model, 'prompt' => trim((string)($request['prompt'] ?? '')), 'resolution' => $resolution, 'duration' => $duration > 0 ? $duration : null, 'size' => (string)($request['ratio'] ?? ''), 'image_urls' => $assets['image'], 'video_urls' => $assets['video'], 'audio_urls' => $assets['audio'], 'idempotency_key' => $idempotency]), static fn($value) => $value !== '' && $value !== [] && $value !== null);
    }

    private static function seedanceAssets(array &$request, int $consumptionId): void
    {
        $assets = AigcVideoReferenceAssetService::normalize($request);
        if ($assets === []) return;
        $group = self::request('POST', self::endpoint('seedance', 'createGroup'), [
            'Name' => 'aigc-video-' . $consumptionId,
            'GroupType' => 'AIGC',
            'Description' => mb_substr(trim((string)($request['prompt'] ?? '')), 0, 120, 'UTF-8'),
            'ProjectName' => 'default',
        ]);
        // App API responses are not uniform: both `data.result.id` and
        // `data.data.GroupId` occur in deployed Seedance gateways.
        $groupId = self::seedanceResponseIdentifier($group, ['group_id', 'groupid', 'id']);
        if ($groupId === '') {
            self::event($consumptionId, 'asset_group', 'failed', ['response_keys' => self::responseKeys($group)]);
            throw new Exception('Seedance素材服务暂未返回可用素材组，请稍后重试');
        }
        $uploaded = ['image' => [], 'video' => [], 'audio' => []];
        $lockedAssets = [];
        foreach ($assets as $index => $asset) {
            $type = (string)($asset['type'] ?? '');
            if (!isset($uploaded[$type])) continue;
            $url = AigcVideoReferenceAssetService::publicUrl($asset);
            if ($url === '') continue;
            $created = self::request('POST', self::endpoint('seedance', 'createAsset'), [
                'URL' => $url,
                'Name' => $type . '-' . $index,
                'GroupId' => $groupId,
                'AssetType' => ucfirst($type),
                'ProjectName' => 'default',
            ]);
            $id = self::seedanceResponseIdentifier($created, ['asset_id', 'assetid', 'id']);
            if ($id === '') throw new Exception('Seedance素材上传失败：未返回AssetId');
            $reference = 'asset://' . $id;
            $uploaded[$type][] = $reference;
            $locked = ['type' => $type, 'uri' => $reference, 'url' => $reference];
            if (!empty($asset['role'])) $locked['role'] = (string)$asset['role'];
            $lockedAssets[] = $locked;
        }
        if ($lockedAssets === []) throw new Exception('Seedance素材上传失败：未生成可用素材ID');
        // Remove the original aliases so the create request cannot mix public
        // URLs with the provider-side assets that were just locked for it.
        $request['reference_assets'] = $lockedAssets;
        $request['seedance_asset_references'] = $uploaded;
        $request['seedance_asset_group_id'] = $groupId;
        unset($request['reference_images'], $request['image_urls'], $request['video_urls'], $request['audio_urls'], $request['image'], $request['first_frame_image'], $request['last_frame_image']);
        self::event($consumptionId, 'asset_upload', 'success', ['group_id' => $groupId, 'asset_count' => count($uploaded['image']) + count($uploaded['video']) + count($uploaded['audio'])]);
    }

    /** @return array{image: array<int, string>, video: array<int, string>, audio: array<int, string>} */
    private static function seedanceAssetReferences(array $request): array
    {
        $references = ['image' => [], 'video' => [], 'audio' => []];
        foreach (self::arrayValue($request['seedance_asset_references'] ?? []) as $type => $values) {
            if (!isset($references[$type])) continue;
            foreach ((array)$values as $value) {
                $reference = trim((string)$value);
                if ($reference !== '' && str_starts_with($reference, 'asset://') && !in_array($reference, $references[$type], true)) {
                    $references[$type][] = $reference;
                }
            }
        }
        if ($references['image'] !== [] || $references['video'] !== [] || $references['audio'] !== []) return $references;
        foreach (self::assets($request) as $type => $values) {
            $references[$type] = array_values(array_filter($values, static fn(string $value): bool => str_starts_with($value, 'asset://')));
        }
        return $references;
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
        $referenceAssets = AigcVideoReferenceAssetService::normalize($request);
        $generationMethod = self::canonicalGenerationMethod(
            $product,
            $metadata,
            strtolower(trim((string)($request['generation_method'] ?? $request['generationMethod'] ?? ''))),
            $assets
        );
        if ($generationMethod !== '' && !in_array($generationMethod, self::generationModes($product, $metadata), true)) {
            throw new Exception('selected video model does not support this generation method');
        }
        $assetCount = count($assets['image']) + count($assets['video']) + count($assets['audio']);
        if (self::isFullVideoProduct($product) && (string)($product['resource_type'] ?? '') === PowerMarketService::TYPE_APP_API) {
            self::assertFullVideoAssets($referenceAssets, $assets, $assetCount, $generationMethod);
            return;
        }
        if ($generationMethod === 'text_to_video' && $assetCount > 0) {
            throw new Exception('text-to-video generation does not accept reference assets');
        }
        if ($generationMethod === 'image_to_video') {
            $roles = array_column($referenceAssets, 'role');
            if (
                count($assets['image']) !== 1 ||
                $assets['video'] !== [] ||
                $assets['audio'] !== [] ||
                !in_array('first_frame_image', $roles, true)
            ) {
                throw new Exception('image-to-video generation requires exactly one first-frame image');
            }
        }
        if ($generationMethod === 'image_reference') {
            if (count($assets['image']) < 1 || $assets['video'] !== [] || $assets['audio'] !== []) {
                throw new Exception('image reference generation requires one or more reference images only');
            }
        }
        if ($generationMethod === 'video_edit' && $assets['video'] === []) {
            throw new Exception('video reference generation requires one or more reference videos');
        }
        if ($generationMethod === 'start_end') {
            $roles = array_column($referenceAssets, 'role');
            if (
                count($assets['image']) !== 2 ||
                $assets['video'] !== [] ||
                $assets['audio'] !== [] ||
                !in_array('first_frame_image', $roles, true) ||
                !in_array('last_frame_image', $roles, true)
            ) {
                throw new Exception('start/end frame generation requires both first and last frame images');
            }
        }
        if ($generationMethod === 'multi_frame' && (count($assets['image']) < 2 || $assets['video'] !== [] || $assets['audio'] !== [])) {
            throw new Exception('multi-frame generation requires at least two reference images');
        }
        if ($generationMethod === 'audio_reference') {
            if ($assets['audio'] === []) {
                throw new Exception('audio reference generation requires one or more reference audios');
            }
            if (self::referenceAudioRequiresVisual($product, $metadata)) {
                if ($assets['image'] === [] && $assets['video'] === []) {
                    throw new Exception('audio reference generation requires at least one reference image or video');
                }
            } elseif ($assets['image'] !== [] || $assets['video'] !== []) {
                throw new Exception('audio reference generation requires one or more reference audios only');
            }
        }
        if ($generationMethod === 'omni_reference') {
            if ($assetCount < 1) {
                throw new Exception('omni reference generation requires at least one reference asset');
            }
            if (self::referenceAudioRequiresVisual($product, $metadata) && $assets['audio'] !== [] && $assets['image'] === [] && $assets['video'] === []) {
                throw new Exception('omni reference generation requires at least one reference image or video when reference audio is used');
            }
        }
        if (self::frameAndReferenceMutuallyExclusive($product, $metadata)) {
            $hasFrame = false;
            $hasReference = $assets['video'] !== [] || $assets['audio'] !== [];
            foreach ($referenceAssets as $asset) {
                $role = (string)($asset['role'] ?? '');
                if (in_array($role, ['first_frame_image', 'last_frame_image'], true)) {
                    $hasFrame = true;
                } elseif (($asset['type'] ?? '') === 'image') {
                    $hasReference = true;
                }
            }
            if ($hasFrame && $hasReference) {
                throw new Exception('first/last frame mode cannot be mixed with reference media mode');
            }
        }
        foreach ($assets as $type => $items) {
            $count = count($items); if ($count === 0) continue;
            $limit = self::referenceLimit($product, $metadata, $type); $supported = self::supportedAssetTypes($product, $metadata);
            if (!in_array($type, $supported, true)) throw new Exception('selected video model does not support reference ' . self::assetLabel($type));
            if ($limit > 0 && $count > $limit) throw new Exception('selected video model supports at most ' . $limit . ' reference ' . self::assetLabel($type));
        }
        $totalLimit = self::referenceAssetLimit($product, $metadata);
        if ($totalLimit > 0 && $assetCount > $totalLimit) {
            throw new Exception('selected video model supports at most ' . $totalLimit . ' reference assets');
        }
    }

    private static function assertFullVideoAssets(array $referenceAssets, array $assets, int $assetCount, string $generationMethod): void
    {
        $firstFrames = 0;
        $lastFrames = 0;
        $referenceImages = 0;
        foreach ($referenceAssets as $asset) {
            if (($asset['type'] ?? '') !== 'image') {
                continue;
            }
            $role = (string)($asset['role'] ?? 'reference_image');
            if ($role === 'first_frame_image') {
                $firstFrames++;
            } elseif ($role === 'last_frame_image') {
                $lastFrames++;
            } else {
                $referenceImages++;
            }
        }
        $referenceVideos = count($assets['video']);
        $referenceAudios = count($assets['audio']);
        $hasFrame = $firstFrames > 0 || $lastFrames > 0;
        $hasReference = $referenceImages > 0 || $referenceVideos > 0 || $referenceAudios > 0;

        if ($generationMethod === 'text_to_video' && $assetCount > 0) {
            throw new Exception('text-to-video generation does not accept reference assets');
        }
        if ($firstFrames > 1) {
            throw new Exception('full_video supports at most one first-frame image');
        }
        if ($lastFrames > 1) {
            throw new Exception('full_video supports at most one last-frame image');
        }
        if ($hasFrame && $hasReference) {
            throw new Exception('full_video first/last frame mode cannot be mixed with reference media mode');
        }
        if ($generationMethod === 'image_to_video' && ($assetCount !== 1 || ($firstFrames + $lastFrames) !== 1)) {
            throw new Exception('full_video image-to-video generation requires exactly one first or last frame image');
        }
        if ($generationMethod === 'start_end' && ($firstFrames !== 1 || $lastFrames !== 1 || $assetCount !== 2)) {
            throw new Exception('full_video start/end generation requires one first-frame and one last-frame image');
        }
        if ($generationMethod === 'image_reference' && ($referenceImages < 1 || $referenceVideos > 0 || $referenceAudios > 0 || $hasFrame)) {
            throw new Exception('full_video image reference generation requires one or more reference images only');
        }
        if ($generationMethod === 'video_edit' && ($referenceVideos < 1 || $hasFrame)) {
            throw new Exception('full_video video reference generation requires one or more reference videos');
        }
        if ($generationMethod === 'omni_reference' && (!$hasReference || $hasFrame)) {
            throw new Exception('full_video omni reference generation requires reference media');
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
        if (!self::skuSupportsRatio($locked, self::arrayValue($market['sku']['selectable_params'] ?? []), self::value($selection, ['ratio', 'aspect_ratio', 'size']))) {
            throw new Exception('selected market SKU does not support current aspect ratio');
        }
        $requestedDuration = (int)self::value($selection, ['duration', 'seconds', 'video_duration']);
        $lockedDuration = self::duration($locked);
        if ($requestedDuration > 0 && $lockedDuration > 0 && $requestedDuration !== $lockedDuration) {
            throw new Exception('所选市场 SKU 不支持当前时长');
        }
        if ($requestedDuration > 0 && $lockedDuration === 0) {
            $allowedDurations = self::durationOptions(self::metadata($market['product']));
            if ($allowedDurations !== [] && !in_array($requestedDuration, $allowedDurations, true)) {
                throw new Exception('当前视频模型不支持所选时长');
            }
        }
        $mode = self::inputMode($selection); $app = strtolower((string)($market['product']['upstream_app_code'] ?? ''));
        if (!self::skuSupportsInputMode(self::skuInputMode($locked), $mode, $market['product'], $locked)) {
            throw new Exception('所选市场 SKU 不支持当前输入模式');
        }
    }

    private static function formatSku(array $market, array $product): array
    {
        $sku = $market['sku']; $locked = self::arrayValue($sku['locked_params'] ?? []);
        $selectable = self::arrayValue($sku['selectable_params'] ?? []);
        $lockedDuration = self::duration($locked);
        $durationOptions = $lockedDuration > 0
            ? [$lockedDuration]
            : self::durationOptionsFromParams($selectable);
        $inputMode = strtolower((string)($product['upstream_app_code'] ?? '')) === 'seedance'
            && self::seedanceVideoInputVariant($locked)
            ? 'video_edit'
            : self::skuInputMode($locked);
        return ['market_sku_id' => (int)$sku['id'], 'sku_key' => (string)$sku['sku_key'], 'title' => (string)$sku['title'], 'resolution' => self::resolution($locked), 'duration' => $lockedDuration, 'duration_options' => $durationOptions, 'model' => (string)($locked['model'] ?? $product['upstream_model_code'] ?? ''), 'pricing_variant' => (string)($locked['_pricing_variant'] ?? $locked['pricing_variant'] ?? ''), 'input_mode' => $inputMode, 'locked_params' => $locked, 'selectable_params' => $selectable, 'usage_unit' => (string)$sku['usage_unit'], 'usage_unit_size' => max(1, (float)($sku['usage_unit_size'] ?? 1)), 'settlement_mode' => self::isTokenSku($sku) ? 'actual_usage' : 'reserved', 'platform_unit_cost' => self::points((float)$sku['sale_points']), 'tenant_unit_price' => self::points((float)$market['tenant_price'])];
    }

    private static function inputModes(string $resourceType, array $product, array $meta): array
    {
        $capabilities = self::arrayValue($meta['capabilities'] ?? []);
        $configured = $meta['input_modes'] ?? $capabilities['input_modes'] ?? [];
        if (is_string($configured)) {
            $configured = preg_split('~\\s*[,|/]\\s*~', $configured) ?: [];
        }
        $values = [];
        foreach ((array)$configured as $mode) {
            $value = is_array($mode) ? ($mode['value'] ?? $mode['code'] ?? '') : $mode;
            $value = strtolower(trim((string)$value));
            $value = match ($value) {
                't2v', 'text', 'text2video', 'text-to-video' => 'text_to_video',
                'i2v', 'image', 'image2video', 'image-to-video', 'singleimage2video', 'single-image-to-video' => 'image_to_video',
                'frames2video', 'frames-to-video' => 'start_end',
                'mixed2video', 'mixed-to-video' => 'omni_reference',
                'reference_image', 'image-reference' => 'image_reference',
                'video_reference', 'video-reference', 'video_to_video', 'video-to-video', 'videoedit2video', 'video2video', 'video-edit-to-video' => 'video_edit',
                'audio2video', 'audio_to_video', 'audio-to-video', 'audio_reference', 'audio-reference' => 'audio_reference',
                default => $value,
            };
            if (in_array($value, ['text_to_video', 'image_to_video', 'image_reference', 'omni_reference', 'start_end', 'multi_frame', 'video_edit', 'audio_reference'], true) && !in_array($value, $values, true)) {
                $values[] = $value;
            }
        }
        if ($values === []) {
            $values = self::schemaVideoCapabilities($meta)['generation_modes'];
        }
        if ($values === []) {
            $values[] = 'text_to_video';
            $types = self::supportedAssetTypes($product, $meta);
            if (in_array('image', $types, true)) {
                $values[] = 'image_reference';
            }
            if (in_array('video', $types, true)) {
                $values[] = 'video_edit';
            }
            if (in_array('audio', $types, true)) {
                $values[] = 'audio_reference';
            }
        }
        $labels = [
            'text_to_video' => 'Text to video',
            'image_to_video' => 'Image to video',
            'image_reference' => 'Image reference',
            'omni_reference' => 'Omni reference',
            'start_end' => 'Start/end frame',
            'multi_frame' => 'Multi-frame',
            'video_edit' => '全能参考',
            'audio_reference' => '全能参考',
        ];
        return array_map(static fn(string $value): array => ['value' => $value, 'label' => $labels[$value]], $values);
    }
    private static function supportedAssetTypes(array $product, array $meta): array
    {
        return self::assetTypes($meta);
    }
    private static function referenceLimit(array $product, array $meta, string $type): int
    {
        return self::capabilityLimit($meta, $type);
    }
    private static function assetTypes(array $meta): array { $cap = self::arrayValue($meta['capabilities'] ?? []); $values = $meta['supported_asset_types'] ?? $cap['supported_asset_types'] ?? []; if ($values === []) $values = self::schemaVideoCapabilities($meta)['asset_types']; if ($values === []) { foreach (['image','video','audio'] as $type) if (self::capabilityLimit($meta, $type) > 0) $values[] = $type; } return array_values(array_intersect(['image','video','audio'], array_map(static fn($v) => strtolower((string)$v), (array)$values))); }
    private static function capabilityLimit(array $meta, string $type): int { $cap = self::arrayValue($meta['capabilities'] ?? []); $keys = ['image' => ['max_reference_images','max_reference_image_count','reference_image_limit'], 'video' => ['max_reference_videos','max_reference_video_count','reference_video_limit'], 'audio' => ['max_reference_audios','max_reference_audio_count','reference_audio_limit']]; foreach ($keys[$type] as $key) foreach ([$meta, $cap] as $source) if (isset($source[$key]) && (int)$source[$key] > 0) return max(0, (int)$source[$key]); return self::docReferenceLimit($meta, $type); }
    private static function schemaVideoCapabilities(array $meta): array
    {
        $schema = self::arrayValue($meta['params_schema'] ?? []);
        $rawTypes = [];
        $rawRoles = [];
        $hasPrompt = false;
        $contentSchema = self::arrayValue($meta['content_schema'] ?? []);
        if ($schema === [] && $contentSchema === [] && trim((string)($meta['api_doc'] ?? '') . (string)($meta['developer_doc_content'] ?? '')) === '') {
            return ['asset_types' => [], 'generation_modes' => []];
        }
        foreach (self::arrayValue($contentSchema['items'] ?? []) as $type => $definition) {
            $type = is_string($type) ? $type : (string)($definition['type'] ?? '');
            if ($type !== '') {
                $rawTypes[] = $type;
            }
            foreach ((array)($definition['roles'] ?? []) as $role) {
                $rawRoles[] = $role;
            }
            if (($definition['asset_type'] ?? '') === 'text' || $type === 'text') {
                $hasPrompt = true;
            }
        }
        self::appendVideoSchemaFieldSignals($schema, $rawTypes, $rawRoles, $hasPrompt);
        $queue = [$schema];
        while ($queue !== []) {
            $node = array_shift($queue);
            if (!is_array($node)) {
                continue;
            }
            foreach ($node as $key => $value) {
                $name = strtolower(str_replace(['_', '-', ' '], '', (string)$key));
                if (in_array($name, ['prompt', 'textprompt'], true)) {
                    $hasPrompt = true;
                }
                if (in_array($name, ['type', 'mediatype', 'assettype', 'filetype', 'inputtype'], true)) {
                    self::appendSchemaValues($rawTypes, $value);
                }
                if (in_array($name, ['role', 'assetrole', 'framerole'], true)) {
                    self::appendSchemaValues($rawRoles, $value);
                }
                if (is_array($value)) {
                    $queue[] = $value;
                }
            }
        }
        $assetTypes = [];
        foreach ($rawTypes as $type) {
            $type = strtolower(str_replace(['-', ' '], '_', trim((string)$type)));
            $mapped = match ($type) {
                'image', 'image_url', 'image_urls', 'reference_image', 'first_frame', 'last_frame' => 'image',
                'video', 'video_url', 'video_urls', 'reference_video' => 'video',
                'audio', 'audio_url', 'audio_urls', 'reference_audio' => 'audio',
                default => '',
            };
            if ($mapped !== '' && !in_array($mapped, $assetTypes, true)) {
                $assetTypes[] = $mapped;
            }
            if ($type === 'text') {
                $hasPrompt = true;
            }
        }
        $roles = array_values(array_unique(array_map(
            static fn($role): string => strtolower(str_replace(['-', ' '], '_', trim((string)$role))),
            $rawRoles
        )));
        $docText = self::videoCapabilityDocText($meta, $schema);
        if ($docText !== '' && (preg_match('/(?:^|[^a-z])text(?:[^a-z]|$)|文本生视频|文生视频/iu', $docText) === 1)) {
            $hasPrompt = true;
        }
        foreach ([
            'first_frame' => ['first_frame', '首帧'],
            'last_frame' => ['last_frame', '尾帧'],
            'reference_image' => ['reference_image', '参考图', '参考图片'],
            'reference_video' => ['reference_video', '参考视频'],
            'reference_audio' => ['reference_audio', '参考音频'],
        ] as $role => $needles) {
            foreach ($needles as $needle) {
                if ($docText !== '' && mb_stripos($docText, $needle, 0, 'UTF-8') !== false && !in_array($role, $roles, true)) {
                    $roles[] = $role;
                    break;
                }
            }
        }
        foreach ([
            'image' => ['image_url', 'reference_image', 'first_frame', 'last_frame', '参考图', '首帧', '尾帧'],
            'video' => ['video_url', 'reference_video', '参考视频', '视频参考'],
            'audio' => ['audio_url', 'reference_audio', '参考音频', '音频参考'],
        ] as $type => $needles) {
            foreach ($needles as $needle) {
                if ($docText !== '' && mb_stripos($docText, $needle, 0, 'UTF-8') !== false && !in_array($type, $assetTypes, true)) {
                    $assetTypes[] = $type;
                    break;
                }
            }
        }
        foreach ($roles as $role) {
            $mapped = match ($role) {
                'first_frame', 'first_frame_image', 'last_frame', 'last_frame_image', 'reference_image' => 'image',
                'reference_video' => 'video',
                'reference_audio' => 'audio',
                default => '',
            };
            if ($mapped !== '' && !in_array($mapped, $assetTypes, true)) {
                $assetTypes[] = $mapped;
            }
        }
        $modes = [];
        if ($hasPrompt) {
            $modes[] = 'text_to_video';
        }
        $hasFirstFrame = in_array('first_frame', $roles, true) || in_array('first_frame_image', $roles, true);
        $hasLastFrame = in_array('last_frame', $roles, true) || in_array('last_frame_image', $roles, true);
        if ($hasFirstFrame || in_array('image', $assetTypes, true)) {
            $modes[] = 'image_to_video';
        }
        if ($hasFirstFrame && $hasLastFrame) {
            $modes[] = 'start_end';
        }
        if (in_array('reference_image', $roles, true) || in_array('image', $assetTypes, true)) {
            $modes[] = 'image_reference';
        }
        if (in_array('reference_video', $roles, true) || in_array('video', $assetTypes, true)) {
            $modes[] = 'video_edit';
        }
        if (in_array('reference_audio', $roles, true) || in_array('audio', $assetTypes, true)) {
            $modes[] = 'audio_reference';
        }
        if (count(array_intersect(['image', 'video', 'audio'], $assetTypes)) >= 2) {
            $modes[] = 'omni_reference';
        }
        $order = ['text_to_video', 'omni_reference', 'image_to_video', 'start_end', 'image_reference', 'video_edit', 'multi_frame', 'audio_reference'];
        return [
            'asset_types' => array_values(array_filter(['image', 'video', 'audio'], static fn(string $type): bool => in_array($type, $assetTypes, true))),
            'generation_modes' => array_values(array_filter($order, static fn(string $mode): bool => in_array($mode, $modes, true))),
        ];
    }
    private static function videoCapabilityDocText(array $meta, array $schema = []): string
    {
        $parts = [
            (string)($meta['api_doc'] ?? ''),
            (string)($meta['developer_doc_content'] ?? ''),
        ];
        if ($schema !== []) {
            $parts[] = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }
        return trim(implode("\n", array_filter($parts)));
    }
    private static function appendVideoSchemaFieldSignals(array $schema, array &$rawTypes, array &$rawRoles, bool &$hasPrompt): void
    {
        $queue = [$schema];
        while ($queue !== []) {
            $node = array_shift($queue);
            if (!is_array($node)) {
                continue;
            }
            foreach ($node as $key => $value) {
                $name = strtolower(preg_replace('/[^a-z0-9]+/', '', (string)$key) ?: '');
                $text = $name;
                if (is_array($value)) {
                    $text .= ' ' . strtolower((string)($value['description'] ?? $value['label'] ?? $value['title'] ?? ''));
                    $queue[] = $value;
                } elseif (is_scalar($value)) {
                    $text .= ' ' . strtolower((string)$value);
                }
                if (preg_match('/(^|[^a-z])(prompt|text)([^a-z]|$)|文本|提示词|文生视频/u', $text) === 1) {
                    $hasPrompt = true;
                }
                if (str_contains($name, 'firstframe') || str_contains($text, '首帧')) {
                    $rawTypes[] = 'image_url';
                    $rawRoles[] = 'first_frame';
                }
                if (str_contains($name, 'lastframe') || str_contains($text, '尾帧')) {
                    $rawTypes[] = 'image_url';
                    $rawRoles[] = 'last_frame';
                }
                if (str_contains($name, 'referenceimage') || str_contains($name, 'imageurl') || str_contains($name, 'imageurls') || str_contains($name, 'images') || str_contains($text, '参考图') || str_contains($text, '参考图片')) {
                    $rawTypes[] = 'image_url';
                    if (!str_contains($name, 'firstframe') && !str_contains($name, 'lastframe')) {
                        $rawRoles[] = 'reference_image';
                    }
                }
                if (str_contains($name, 'referencevideo') || str_contains($name, 'videourl') || str_contains($name, 'videourls') || str_contains($name, 'videos') || str_contains($text, '参考视频')) {
                    $rawTypes[] = 'video_url';
                    $rawRoles[] = 'reference_video';
                }
                if (str_contains($name, 'referenceaudio') || str_contains($name, 'audiourl') || str_contains($name, 'audiourls') || str_contains($name, 'audios') || str_contains($text, '参考音频')) {
                    $rawTypes[] = 'audio_url';
                    $rawRoles[] = 'reference_audio';
                }
            }
        }
    }
    private static function docReferenceLimit(array $meta, string $type): int
    {
        $text = self::videoCapabilityDocText($meta, self::arrayValue($meta['params_schema'] ?? []));
        if ($text === '') {
            return 0;
        }
        $patterns = [
            'image' => ['reference_image', '参考图', '参考图片'],
            'video' => ['reference_video', '参考视频'],
            'audio' => ['reference_audio', '参考音频'],
        ][$type] ?? [];
        $contentLimits = self::arrayValue(self::arrayValue($meta['content_schema'] ?? [])['limits'] ?? []);
        $roleKey = ['image' => 'reference_image', 'video' => 'reference_video', 'audio' => 'reference_audio'][$type] ?? '';
        if ($roleKey !== '' && (int)($contentLimits[$roleKey] ?? 0) > 0) {
            return (int)$contentLimits[$roleKey];
        }
        foreach ($patterns as $pattern) {
            if (preg_match('/' . preg_quote($pattern, '/') . '[^。；;\\n]{0,80}最多\\s*(\\d+)/iu', $text, $match)) {
                return max(0, (int)$match[1]);
            }
        }
        return 0;
    }
    private static function appendSchemaValues(array &$values, $node): void
    {
        if (is_array($node)) {
            foreach (['enum', 'options', 'values', 'allowed_values', 'items'] as $key) {
                if (array_key_exists($key, $node)) {
                    self::appendSchemaValues($values, $node[$key]);
                }
            }
            foreach ($node as $key => $value) {
                if (is_int($key)) {
                    self::appendSchemaValues($values, $value);
                } elseif (in_array($key, ['value', 'code', 'type', 'role', 'default'], true) && is_scalar($value)) {
                    $values[] = (string)$value;
                }
            }
            return;
        }
        if (is_scalar($node) && trim((string)$node) !== '') {
            $values[] = (string)$node;
        }
    }
    private static function supportsFirstLastFrame(array $product, array $meta): bool
    {
        return in_array('start_end', self::generationModes($product, $meta), true);
    }
    private static function canonicalGenerationMethod(array $product, array $meta, string $method, array $assets = []): string
    {
        if ($method === '') {
            return '';
        }
        $modes = self::generationModes($product, $meta);
        if (in_array('omni_reference', $modes, true) && in_array($method, ['video_edit', 'audio_reference'], true)) {
            return 'omni_reference';
        }
        if ($method === 'omni_reference' && !in_array('omni_reference', $modes, true)) {
            if (($assets['video'] ?? []) !== [] && in_array('video_edit', $modes, true)) {
                return 'video_edit';
            }
            if (($assets['audio'] ?? []) !== [] && in_array('audio_reference', $modes, true)) {
                return 'audio_reference';
            }
            if (($assets['image'] ?? []) !== [] && in_array('image_reference', $modes, true)) {
                return 'image_reference';
            }
        }
        return $method;
    }
    private static function referenceAudioRequiresVisual(array $product, array $meta): bool
    {
        $cap = self::arrayValue($meta['capabilities'] ?? []);
        $rules = self::arrayValue(self::arrayValue($meta['content_schema'] ?? [])['rules'] ?? []);
        foreach ([$meta, $cap, $rules] as $source) {
            foreach (['reference_audio_requires_visual', 'audio_reference_requires_visual', 'audio_requires_visual_reference'] as $key) {
                $value = $source[$key] ?? null;
                if ($value === true || $value === 1 || in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes'], true)) {
                    return true;
                }
            }
        }
        $text = self::videoCapabilityDocText($meta, self::arrayValue($meta['params_schema'] ?? []));
        if ($text !== '' && (
            mb_stripos($text, '参考音频不能单独使用', 0, 'UTF-8') !== false ||
            mb_stripos($text, '不能单独使用音频', 0, 'UTF-8') !== false ||
            mb_stripos($text, '不能只传音频', 0, 'UTF-8') !== false ||
            mb_stripos($text, '同时有一个参考图片或参考视频', 0, 'UTF-8') !== false ||
            mb_stripos($text, '同时上传图片或视频参考', 0, 'UTF-8') !== false
        )) {
            return true;
        }
        return self::isH3Product($product);
    }
    private static function frameAndReferenceMutuallyExclusive(array $product, array $meta): bool
    {
        $cap = self::arrayValue($meta['capabilities'] ?? []);
        $rules = self::arrayValue(self::arrayValue($meta['content_schema'] ?? [])['rules'] ?? []);
        foreach ([$meta, $cap, $rules] as $source) {
            foreach (['frame_and_reference_mutually_exclusive', 'frames_and_references_mutually_exclusive'] as $key) {
                $value = $source[$key] ?? null;
                if ($value === true || $value === 1 || in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes'], true)) {
                    return true;
                }
            }
        }
        $text = self::videoCapabilityDocText($meta, self::arrayValue($meta['params_schema'] ?? []));
        if ($text !== '' && (
            mb_stripos($text, '首尾帧模式与多模态参考模式互斥', 0, 'UTF-8') !== false ||
            mb_stripos($text, '不能同时出现 `first_frame` 或 `last_frame`', 0, 'UTF-8') !== false ||
            mb_stripos($text, '不能同时出现 first_frame 或 last_frame', 0, 'UTF-8') !== false ||
            mb_stripos($text, 'frame mode cannot be mixed with reference media', 0, 'UTF-8') !== false
        )) {
            return true;
        }
        return self::isH3Product($product);
    }
    private static function referenceAssetLimit(array $product, array $meta): int
    {
        $cap = self::arrayValue($meta['capabilities'] ?? []);
        foreach ([$meta, $cap] as $source) {
            foreach (['max_reference_assets', 'max_reference_asset_count', 'reference_asset_limit'] as $key) {
                if (isset($source[$key])) return max(0, (int)$source[$key]);
            }
        }
        return self::referenceLimit($product, $meta, 'image')
            + self::referenceLimit($product, $meta, 'video')
            + self::referenceLimit($product, $meta, 'audio');
    }
    private static function generationModes(array $product, array $meta): array
    {
        $cap = self::arrayValue($meta['capabilities'] ?? []);
        $configured = $meta['generation_modes'] ?? $cap['generation_modes'] ?? [];
        if (is_string($configured)) $configured = preg_split('~\s*[,|/]\s*~', $configured) ?: [];
        $configured = array_values(array_filter(array_map(static function ($value): string {
            if (is_array($value)) $value = $value['value'] ?? $value['code'] ?? $value['mode'] ?? '';
            $value = strtolower(trim((string)$value));
            return match ($value) {
                't2v', 'text', 'text2video', 'text-to-video' => 'text_to_video',
                'i2v', 'image', 'image2video', 'image-to-video', 'singleimage2video', 'single-image-to-video' => 'image_to_video',
                'frames2video', 'frames-to-video' => 'start_end',
                'mixed2video', 'mixed-to-video' => 'omni_reference',
                'reference_image', 'image-reference' => 'image_reference',
                'video_reference', 'video-reference', 'video_to_video', 'video-to-video', 'videoedit2video', 'video2video', 'video-edit-to-video' => 'video_edit',
                'audio2video', 'audio_to_video', 'audio-to-video', 'audio_reference', 'audio-reference' => 'audio_reference',
                default => $value,
            };
        }, (array)$configured)));
        $order = ['text_to_video', 'omni_reference', 'image_to_video', 'start_end', 'image_reference', 'video_edit', 'multi_frame', 'audio_reference'];
        $declared = array_values(array_intersect($order, array_unique($configured)));
        if ($declared !== []) {
            return array_values(array_filter($order, static fn(string $mode): bool => in_array($mode, $declared, true)));
        }
        $schemaModes = self::schemaVideoCapabilities($meta)['generation_modes'];
        if ($schemaModes !== []) {
            return array_values(array_filter($order, static fn(string $mode): bool => in_array($mode, $schemaModes, true)));
        }
        $configuredInputModes = $meta['input_modes'] ?? $cap['input_modes'] ?? [];
        if (is_string($configuredInputModes)) $configuredInputModes = preg_split('~\s*[,|/]\s*~', $configuredInputModes) ?: [];
        $declaredInputModes = [];
        foreach ((array)$configuredInputModes as $mode) {
            $value = is_array($mode) ? ($mode['value'] ?? $mode['code'] ?? '') : $mode;
            $value = strtolower(trim((string)$value));
            $value = match ($value) {
                't2v', 'text', 'text2video', 'text-to-video' => 'text_to_video',
                'i2v', 'image', 'image2video', 'image-to-video', 'singleimage2video', 'single-image-to-video' => 'image_to_video',
                'frames2video', 'frames-to-video' => 'start_end',
                'mixed2video', 'mixed-to-video' => 'omni_reference',
                'reference_image', 'image-reference' => 'image_reference',
                'video_reference', 'video-reference', 'video_to_video', 'video-to-video', 'videoedit2video', 'video2video', 'video-edit-to-video' => 'video_edit',
                'audio2video', 'audio_to_video', 'audio-to-video', 'audio_reference', 'audio-reference' => 'audio_reference',
                default => $value,
            };
            if (in_array($value, $order, true)) $declaredInputModes[] = $value;
        }
        $declaredInputModes = array_values(array_intersect($order, array_unique($declaredInputModes)));
        if ($declaredInputModes !== []) {
            return array_values(array_filter($order, static fn(string $mode): bool => in_array($mode, $declaredInputModes, true)));
        }
        $resourceType = (string)($product['resource_type'] ?? PowerMarketService::TYPE_MODEL);
        $inputModes = array_column(self::inputModes($resourceType, $product, $meta), 'value');
        $supported = [];
        if (in_array('text_to_video', $inputModes, true)) {
            $supported[] = 'text_to_video';
        }
        if (in_array('image_to_video', $inputModes, true) || in_array('image_reference', $inputModes, true)) {
            $supported[] = 'image_to_video';
        }
        if (in_array('image_reference', $inputModes, true)) {
            $supported[] = 'image_reference';
        }
        if (in_array('video_edit', $inputModes, true)) {
            $supported[] = 'video_edit';
        }
        foreach (['omni_reference', 'start_end', 'multi_frame'] as $mode) {
            if (in_array($mode, $inputModes, true)) $supported[] = $mode;
        }
        $assetTypes = self::supportedAssetTypes($product, $meta);
        if (in_array('image', $assetTypes, true)) $supported[] = 'image_reference';
        if (in_array('video', $assetTypes, true)) $supported[] = 'video_edit';
        if (in_array('audio', $assetTypes, true)) $supported[] = 'audio_reference';
        foreach (['omni_reference', 'multi_frame'] as $mode) {
            if (in_array($mode, $configured, true)) {
                $supported[] = $mode;
            }
        }
        return array_values(array_filter($order, static fn(string $mode): bool => in_array($mode, $supported, true)));
    }
    private static function ratiosForSkus(array $skus, array $metadata): array
    {
        $sets = array_map(static fn(array $row): array => self::skuRatios($row), $skus);
        $ratios = $sets === [] ? [] : array_values(array_unique(array_merge(...$sets)));
        return $ratios !== [] ? $ratios : self::ratioOptions($metadata);
    }
    private static function skuRatios(array $sku): array
    {
        $lockedRatio = self::skuRatio(self::arrayValue($sku['locked_params'] ?? []));
        if ($lockedRatio !== '') return [$lockedRatio];
        return self::ratioValues(self::arrayValue($sku['selectable_params'] ?? []));
    }
    private static function skuRatio(array $locked): string { return self::ratioValues($locked)[0] ?? ''; }
    private static function skuSupportsRatio(array $locked, array $selectable, string $ratio): bool
    {
        if ($ratio === '') return true;
        $lockedRatio = self::skuRatio($locked);
        if ($lockedRatio !== '') return strtolower($lockedRatio) === strtolower($ratio);
        $allowed = self::ratioValues($selectable);
        return $allowed === [] || in_array(strtolower($ratio), array_map('strtolower', $allowed), true);
    }
    private static function ratioValues(array $params): array
    {
        $values = [];
        $queue = [$params];
        while ($queue !== []) {
            $node = array_shift($queue);
            if (!is_array($node)) continue;
            foreach ($node as $key => $value) {
                $name = strtolower(str_replace(['_', '-', ' '], '', (string)$key));
                if (in_array($name, ['ratio', 'aspectratio', 'aspectratiooptions', 'size', 'ratios', 'ratiooptions', 'supportedratios'], true)) {
                    self::appendRatioValues($values, $value);
                }
                if (is_array($value)) {
                    $queue[] = $value;
                }
            }
        }
        return array_values(array_unique($values));
    }
    private static function appendRatioValues(array &$values, $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) self::appendRatioValues($values, $item);
            return;
        }
        $ratio = trim((string)$value);
        if (preg_match('/^\d+\s*:\s*\d+$/', $ratio) || strtolower($ratio) === 'adaptive') {
            $values[] = str_replace(' ', '', $ratio);
            return;
        }
        if (preg_match_all('/\d+\s*:\s*\d+|adaptive/i', $ratio, $matches)) {
            foreach ($matches[0] as $match) {
                $values[] = strtolower($match) === 'adaptive' ? 'adaptive' : str_replace(' ', '', $match);
            }
        }
    }
    private static function concreteRatio(string $ratio): string
    {
        if (!preg_match('/^([1-9]\d{0,3})\s*:\s*([1-9]\d{0,3})$/', trim($ratio), $matches)) {
            return '';
        }
        return (int)$matches[1] . ':' . (int)$matches[2];
    }
    private static function concreteRatioOptions(array $ratios): array
    {
        $result = [];
        foreach ($ratios as $ratio) {
            $concrete = self::concreteRatio((string)$ratio);
            if ($concrete !== '' && !in_array($concrete, $result, true)) {
                $result[] = $concrete;
            }
        }
        return $result;
    }
    private static function requiresConcreteTextToVideoRatio(array $product, array $metadata): bool
    {
        foreach ([$metadata, self::arrayValue($metadata['capabilities'] ?? []), self::arrayValue($metadata['params_schema'] ?? [])] as $source) {
            foreach (['text_to_video_requires_concrete_ratio', 'ratio_required_for_text_to_video', 'require_concrete_ratio'] as $key) {
                $value = $source[$key] ?? null;
                if ($value === true || $value === 1 || in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes'], true)) {
                    return true;
                }
            }
        }
        return self::isH3Product($product) || self::isFullVideoProduct($product);
    }
    private static function isFullVideoProduct(array $product): bool
    {
        return strtolower(trim((string)($product['upstream_app_code'] ?? $product['app_code'] ?? ''))) === 'full_video';
    }
    private static function isH3Product(array $product): bool
    {
        foreach (['upstream_model_code', 'upstream_channel_code', 'name'] as $key) {
            $value = strtolower(trim((string)($product[$key] ?? '')));
            $compact = preg_replace('/[^a-z0-9]+/', '', $value) ?: '';
            if (preg_match('/(?:^|[-_\s])h3(?:[-_\s]|$)/', $value) === 1 || str_starts_with($compact, 'h3')) {
                return true;
            }
        }
        return false;
    }
    private static function defaultConcreteTextToVideoRatio(array $skus, array $metadata): string
    {
        foreach ($skus as $sku) {
            $locked = self::arrayValue($sku['locked_params'] ?? self::arrayValue($sku['sku'] ?? [])['locked_params'] ?? []);
            $selectable = self::arrayValue($sku['selectable_params'] ?? self::arrayValue($sku['sku'] ?? [])['selectable_params'] ?? []);
            $lockedRatios = self::concreteRatioOptions(self::ratioValues($locked));
            if ($lockedRatios !== []) {
                return $lockedRatios[0];
            }
            $selectableRatios = self::concreteRatioOptions(self::ratioValues($selectable));
            if ($selectableRatios !== []) {
                return in_array('16:9', $selectableRatios, true) ? '16:9' : $selectableRatios[0];
            }
        }
        $metadataRatios = self::concreteRatioOptions(self::ratioOptions($metadata));
        return in_array('16:9', $metadataRatios, true) ? '16:9' : ($metadataRatios[0] ?? '16:9');
    }
    private static function assertTextToVideoRatio(array $market, array $request): void
    {
        $metadata = self::metadata($market['product']);
        if (!self::requiresConcreteTextToVideoRatio($market['product'], $metadata)) {
            return;
        }
        $assets = self::assets($request);
        if ($assets['image'] !== [] || $assets['video'] !== [] || $assets['audio'] !== []) {
            return;
        }
        $ratio = self::concreteRatio(self::value($request, ['ratio', 'aspect_ratio', 'size']));
        if ($ratio !== '') {
            return;
        }
        $fallback = self::defaultConcreteTextToVideoRatio([$market], $metadata);
        if ($fallback === '') {
            throw new Exception('H3 text-to-video requires a concrete aspect ratio.');
        }
    }
    private static function payloadRatio(array $snapshot, array $request, array $assets): string
    {
        $locked = self::arrayValue($snapshot['locked_params'] ?? []);
        $ratio = self::value($request, ['ratio', 'aspect_ratio', 'size']) ?: self::value($locked, ['ratio', 'aspect_ratio', 'size']);
        $isTextToVideo = $assets['image'] === [] && $assets['video'] === [] && $assets['audio'] === [];
        if (empty($snapshot['requires_concrete_text_to_video_ratio']) || !$isTextToVideo) {
            return $ratio;
        }
        $concrete = self::concreteRatio($ratio);
        if ($concrete !== '') {
            return $concrete;
        }
        $fallback = self::concreteRatio((string)($snapshot['default_text_to_video_ratio'] ?? ''));
        if ($fallback !== '') {
            return $fallback;
        }
        throw new Exception('H3 text-to-video requires a concrete aspect ratio.');
    }
    private static function ratioOptions(array $meta): array
    {
        $ratios = self::ratioValues($meta);
        if ($ratios !== []) return $ratios;
        $cap = self::arrayValue($meta['capabilities'] ?? []); $schema = self::arrayValue($meta['params_schema'] ?? []); $values = $meta['supported_ratios'] ?? $meta['ratio_options'] ?? $meta['ratios'] ?? $cap['supported_ratios'] ?? $cap['ratio_options'] ?? $schema['aspect_ratio']['options'] ?? []; if (is_string($values)) $values = preg_split('~\s*[,|/]\s*~', $values) ?: []; return array_values(array_filter(array_map('strval', (array)$values)));
    }
    private static function durationOptions(array $meta): array
    {
        $cap = self::arrayValue($meta['capabilities'] ?? []);
        $durationSchema = self::durationSchema($meta);
        $minimum = (int)($durationSchema['minimum'] ?? $durationSchema['min'] ?? 0);
        $maximum = (int)($durationSchema['maximum'] ?? $durationSchema['max'] ?? 0);
        if ($minimum > 0 && $maximum >= $minimum && $maximum - $minimum <= 120) {
            return range($minimum, $maximum);
        }
        $durations = [];
        foreach ([
            $meta['supported_durations'] ?? null,
            $meta['duration_options'] ?? null,
            $meta['durations'] ?? null,
            $cap['supported_durations'] ?? null,
            $cap['duration_options'] ?? null,
            $cap['durations'] ?? null,
            $durationSchema['options'] ?? null,
            $durationSchema['enum'] ?? null,
            $durationSchema['values'] ?? null,
            $durationSchema['allowed_values'] ?? null,
            $durationSchema['description'] ?? null,
            $durationSchema['default'] ?? null,
            $durationSchema['value'] ?? null,
        ] as $values) {
            if ($values === null || $values === [] || $values === '') {
                continue;
            }
            foreach (is_array($values) ? $values : [$values] as $item) {
                self::appendDurationOptions($durations, $item);
            }
        }
        $durations = array_values(array_unique($durations));
        sort($durations);
        return $durations;
    }

    private static function durationSchema(array $meta): array
    {
        $schema = self::arrayValue($meta['params_schema'] ?? []);
        $properties = self::arrayValue($schema['properties'] ?? []);
        return self::arrayValue(
            $schema['duration']
                ?? $schema['seconds']
                ?? $schema['video_duration']
                ?? $properties['duration']
                ?? $properties['seconds']
                ?? $properties['video_duration']
                ?? []
        );
    }

    private static function defaultDurationOption(array $meta, array $durations): int
    {
        $schema = self::durationSchema($meta);
        foreach ([$meta['default_duration'] ?? null, $schema['default'] ?? null, $schema['value'] ?? null] as $value) {
            if (!is_numeric($value)) {
                continue;
            }
            $duration = (int)$value;
            if ($duration <= 0) {
                continue;
            }
            if ($durations === [] || in_array($duration, $durations, true)) {
                return $duration;
            }
        }
        return (int)($durations[0] ?? 0);
    }

    private static function durationOptionsFromParams(array $params): array
    {
        $durations = [];
        $queue = [$params];
        while ($queue !== []) {
            $node = array_shift($queue);
            if (!is_array($node)) continue;
            foreach ($node as $key => $value) {
                $name = strtolower(str_replace(['_', '-', ' '], '', (string)$key));
                if (in_array($name, ['duration', 'durations', 'durationoptions', 'seconds', 'videoduration', 'supporteddurations'], true)) {
                    self::appendDurationOptions($durations, $value);
                }
                if (is_array($value)) $queue[] = $value;
            }
        }
        $durations = array_values(array_unique($durations));
        sort($durations);
        return $durations;
    }
    private static function appendDurationOptions(array &$durations, $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::appendDurationOptions($durations, $item);
            }
            return;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return;
        }
        if (preg_match_all('/(?<![-\d])(\d{1,3})\s*(?:-|~|～|至|到)\s*(\d{1,3})(?!\d)/u', $value, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $start = (int)$match[1];
                $end = (int)$match[2];
                if ($start > 0 && $end >= $start && $end - $start <= 120) {
                    $durations = array_merge($durations, range($start, $end));
                }
            }
            return;
        }
        if (preg_match_all('/(?<![-\d])\d{1,3}(?!\d)/', $value, $matches)) {
            foreach ($matches[0] as $duration) {
                if ((int)$duration > 0) {
                    $durations[] = (int)$duration;
                }
            }
        }
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
    private static function hasConfigurableDurationSku(array $skus): bool { foreach ($skus as $sku) if ((int)($sku['duration'] ?? 0) === 0) return true; return false; }
    private static function preferredSupportedDuration(array $product, int $duration): int
    {
        return max(0, $duration);
    }
    private static function displayName(array $product): string
    {
        if ((string)($product['resource_type'] ?? '') !== PowerMarketService::TYPE_APP_API) {
            return (string)($product['name'] ?? '视频模型');
        }
        if (!empty($product['display_name_overridden'])) {
            return (string)($product['name'] ?? '视频生成');
        }
        return match (strtolower((string)($product['upstream_app_code'] ?? ''))) {
            'seedance' => 'Seedance 2.0',
            'seedance2_pro' => 'Seedance 2.0 Pro',
            'wan' => 'Wan 视频生成',
            'happy_horse' => 'Happy Horse',
            default => preg_replace('/\s*\/\s*(?:创建|提交).*/u', '', (string)($product['name'] ?? '视频生成')) ?: '视频生成',
        };
    }
    private static function resourceType(array $selection): string { $value = (string)($selection['resource_type'] ?? ''); if (in_array($value, [PowerMarketService::TYPE_MODEL, PowerMarketService::TYPE_APP_API], true)) return $value; $model = implode('|', array_map('strval', [$selection['model_id'] ?? '', $selection['channel'] ?? ''])); return str_contains($model, 'market_video_app:') ? PowerMarketService::TYPE_APP_API : PowerMarketService::TYPE_MODEL; }
    private static function optionId(string $type, array $product): string { return $type === PowerMarketService::TYPE_MODEL ? 'market_video_model:' . (int)$product['id'] : 'market_video_app:' . (string)$product['upstream_app_code'] . ':' . (int)$product['id']; }
    private static function unavailableOption(string $type, array $product, string $reason): array
    {
        $category = $type === PowerMarketService::TYPE_APP_API
            ? PowerMarketService::appCategory($product)
            : ['id' => 0, 'code' => 'video', 'name' => '视频生成'];
        $id = self::optionId($type, $product);
        return [
            'id' => $id,
            'value' => $id,
            'channel_code' => $id,
            'market_product_id' => (int)$product['id'],
            'resource_type' => $type,
            'resource_type_label' => $type === PowerMarketService::TYPE_APP_API ? '应用 API' : '模型 API',
            'category_id' => (int)($category['id'] ?? 0),
            'category_code' => (string)($category['code'] ?? 'video'),
            'category_name' => (string)($category['name'] ?? '视频生成'),
            'name' => (string)($product['name'] ?? ''),
            'description' => (string)($product['description'] ?? ''),
            'display_icon' => (string)($product['display_icon'] ?? ''),
            'app_code' => (string)($product['upstream_app_code'] ?? ''),
            'api_code' => (string)($product['upstream_api_code'] ?? ''),
            'model_code' => (string)($product['upstream_model_code'] ?? ''),
            'skus' => [],
            'qualities' => [],
            'status' => 0,
            'enabled' => false,
            'available' => false,
            'unavailable_reason' => trim($reason) ?: 'No sellable video runtime option',
            'sort' => (int)($product['id'] ?? 0),
        ];
    }
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
    private static function inputMode(array $selection): string
    {
        $assets = self::assets($selection);
        $method = strtolower(trim((string)($selection['generation_method'] ?? $selection['generationMethod'] ?? '')));
        if ($method === 'text_to_video') return 'text_to_video';
        if ($method === 'video_edit') return 'video_edit';
        if ($method === 'audio_reference') return 'audio_reference';
        if ($method === 'omni_reference') {
            if ($assets['video'] !== []) return 'video_edit';
            if ($assets['image'] !== []) return 'image_reference';
            return 'text_to_video';
        }
        if (in_array($method, ['image_to_video', 'image_reference', 'start_end', 'multi_frame'], true)) {
            return 'image_reference';
        }
        if ($assets['video'] !== []) return 'video_edit';
        if ($assets['audio'] !== []) return 'audio_reference';
        if ($assets['image'] !== []) return 'image_reference';
        return 'text_to_video';
    }
    private static function skuInputMode(array $locked): string
    {
        $model = strtolower((string)($locked['model'] ?? ''));
        if (str_contains($model, 'a2v') || str_contains($model, 'audio2video') || str_contains($model, 'audio-to-video')) return 'audio_reference';
        if (str_contains($model, 'videoedit') || str_contains($model, 'video-2-video')) return 'video_edit';
        if (str_contains($model, 'r2v') || str_contains($model, 'i2v') || str_contains($model, 'image-2-video') || str_contains($model, 'reference')) return 'image_reference';
        if ($model === 'seedance2_pro' || str_contains($model, 'seedance2_pro')) return 'image_reference';
        return 'text_to_video';
    }
    private static function seedanceVideoInputVariant(array $locked): bool
    {
        $variant = strtolower(trim((string)($locked['_pricing_variant'] ?? $locked['pricing_variant'] ?? '')));
        return str_contains(str_replace(['_', '-', ' '], '', $variant), 'withvideo');
    }
    private static function skuSupportsInputMode(string $skuMode, string $requestedMode, array $product, array $locked): bool
    {
        if (self::isH3Product($product)) {
            return in_array($requestedMode, self::generationModes($product, self::metadata($product)), true);
        }
        $app = strtolower((string)($product['upstream_app_code'] ?? ''));
        if (in_array($app, ['happy_horse', 'grok_video', 'full_video', 'seedance2_pro'], true)) {
            $modes = self::generationModes($product, self::metadata($product));
            return $modes !== [] ? in_array($requestedMode, $modes, true) : $skuMode === $requestedMode;
        }
        if ($app === 'seedance') {
            return self::seedanceVideoInputVariant($locked)
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
    private static function selectionKey(array $market): string { return (string)$market['product']['id'] . ':' . (string)$market['sku']['id']; }
    private static function metadata(array $product): array
    {
        $source = self::arrayValue($product['source_payload'] ?? []);
        $raw = self::arrayValue($source['raw'] ?? []);
        $metadata = self::arrayValue($source['market_metadata'] ?? []);
        foreach ([self::arrayValue($source['resource'] ?? []), self::arrayValue($raw['resource'] ?? [])] as $resource) {
            $metadata = array_merge($resource, $metadata);
            $metadata['capabilities'] = array_merge(self::arrayValue($resource['capabilities'] ?? []), self::arrayValue($metadata['capabilities'] ?? []));
        }
        $metadata = self::inheritReferenceModelMetadata($product, $metadata);
        foreach ([
            'supported_ratios', 'ratio_options', 'ratios', 'aspect_ratio',
            'supported_durations', 'duration_options', 'durations',
            'default_params',
            'supported_asset_types', 'input_modes', 'generation_modes',
            'supports_first_last_frame', 'supports_reference_images',
            'supports_vision', 'supports_reasoning',
            'max_reference_images', 'max_reference_audios', 'max_reference_videos',
            'max_reference_assets',
        ] as $field) {
            if (!array_key_exists($field, $metadata) && array_key_exists($field, $source)) {
                $metadata[$field] = $source[$field];
            }
            if (!array_key_exists($field, $metadata) && array_key_exists($field, $raw)) {
                $metadata[$field] = $raw[$field];
            }
        }

        // Application catalogues commonly publish duration/ratio choices in
        // pricing_v2.attributes rather than capability metadata. Preserve
        // those upstream-declared values so the runtime can build selectable
        // SKUs without inventing provider defaults.
        foreach ([self::arrayValue($metadata['pricing_v2'] ?? []), self::arrayValue($source['pricing_v2'] ?? []), self::arrayValue($raw['pricing_v2'] ?? [])] as $pricing) {
            foreach ((array)($pricing['attributes'] ?? []) as $attribute) {
                if (!is_array($attribute)) {
                    continue;
                }
                $code = strtolower(str_replace(['-', ' ', '_'], '', (string)($attribute['code'] ?? $attribute['name'] ?? '')));
                $options = $attribute['options'] ?? $attribute['enum'] ?? $attribute['values'] ?? [];
                if ($options === [] || $options === null) {
                    continue;
                }
                if (in_array($code, ['duration', 'durations', 'durationoptions', 'seconds', 'videoduration'], true)
                    && empty($metadata['supported_durations'])) {
                    $metadata['supported_durations'] = $options;
                }
                if (in_array($code, ['ratio', 'ratios', 'ratiooptions', 'aspectratio', 'aspectratiooptions', 'sizeratio'], true)
                    && empty($metadata['supported_ratios'])) {
                    $metadata['supported_ratios'] = $options;
                }
            }
        }
        return $metadata;
    }
    private static function inheritReferenceModelMetadata(array $product, array $metadata): array
    {
        if ((string)($product['resource_type'] ?? '') !== PowerMarketService::TYPE_APP_API) {
            return $metadata;
        }
        $app = strtolower(trim((string)($product['upstream_app_code'] ?? $product['app_code'] ?? '')));
        $referenceModel = match ($app) {
            'grok_video' => 'grok-video',
            default => '',
        };
        if ($referenceModel === '') {
            return $metadata;
        }
        $reference = self::referenceModelMetadata($referenceModel);
        if ($reference === []) {
            return $metadata;
        }
        foreach ([
            'params_schema',
            'default_params',
            'supported_ratios',
            'ratio_options',
            'ratios',
            'aspect_ratio',
            'supported_durations',
            'duration_options',
            'durations',
            'input_modes',
            'generation_modes',
            'supported_asset_types',
            'content_schema',
        ] as $field) {
            if (empty($metadata[$field]) && !empty($reference[$field])) {
                $metadata[$field] = $reference[$field];
            }
        }
        $metadata['capabilities'] = array_merge(
            self::arrayValue($reference['capabilities'] ?? []),
            self::arrayValue($metadata['capabilities'] ?? [])
        );
        return $metadata;
    }
    private static function referenceModelMetadata(string $modelCode): array
    {
        static $cache = [];
        $modelCode = trim($modelCode);
        if ($modelCode === '') {
            return [];
        }
        if (array_key_exists($modelCode, $cache)) {
            return $cache[$modelCode];
        }
        $product = PowerMarketProduct::where([
            'resource_type' => PowerMarketService::TYPE_MODEL,
            'model_type' => 'video',
            'upstream_model_code' => $modelCode,
            'status' => 1,
        ])->order(['id' => 'desc'])->find();
        if (empty($product)) {
            return $cache[$modelCode] = [];
        }
        return $cache[$modelCode] = self::metadata($product->toArray());
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
    /** Seedance gateways may serialize `data`/`result` once more before returning it. */
    private static function seedanceResponseIdentifier(array $data, array $keys): string
    {
        $identifier = self::responseIdentifier($data, $keys);
        if ($identifier !== '') {
            return $identifier;
        }
        foreach (['data', 'result', 'Result'] as $key) {
            $value = $data[$key] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value === '' || in_array(strtolower($value), ['success', 'ok', 'true', 'false', 'null'], true)) {
                continue;
            }
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $identifier = self::responseIdentifier($decoded, $keys);
                if ($identifier !== '') {
                    return $identifier;
                }
                continue;
            }
            return $value;
        }
        return '';
    }
    /** @return array<int, string> */
    private static function responseKeys(array $data): array
    {
        return array_slice(array_values(array_filter(array_map(static fn($key): string => is_string($key) ? $key : '', array_keys($data)))), 0, 20);
    }
    private static function marketContext(array $snapshot, string $priceSource): array { $skuKey = trim((string)($snapshot['sku_key'] ?? '')); $skuId = (int)($snapshot['sku_id'] ?? 0); return ['market_product_id' => (int)($snapshot['product_id'] ?? 0), 'market_sku_id' => $skuId, 'sku_id' => $skuId, 'market_sku_key' => $skuKey, 'sku_key' => $skuKey, 'pricing_sku_key' => $skuKey, 'price_source' => $priceSource]; }
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
    /**
     * These composed apps retain user-visible result histories. Their videos
     * must survive provider URL expiry regardless of the tenant's optional
     * transfer setting.
     */
    private static function requiresDurableResultStorage(string $appCode): bool
    {
        return in_array($appCode, ['aigc_short_drama', 'aigc_product_promo_video'], true);
    }

    private static function videos(array $data, int $tenantId, int $userId, bool $forceTransfer = false): array
    {
        $urls = self::videoUrls($data);
        if ($urls === []) {
            return [];
        }
        $rows = [];
        $errors = [];
        foreach ($urls as $url) {
            try {
                if (!AiTaskResultStorageService::transferEnabled($tenantId, $forceTransfer)) {
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
                $stored = AigcVideoAssetService::persistGeneratedVideo($url, $tenantId, $userId);
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
        return array_values(array_unique($urls !== [] ? $urls : AiTaskResultUrlService::collect($data)));
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
    private static function request(string $method, string $url, array $payload = [], bool $allowApplicationError = false): array { $source = UpdateSourceClient::getSource(); $key = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? '')); if ($key === '') throw new Exception('视频 API 暂不可用'); $ch = curl_init(); curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Accept: application/json', 'Content-Type: application/json'], CURLOPT_SSL_VERIFYPEER => UpdateSourceClient::sslVerify($source), CURLOPT_SSL_VERIFYHOST => UpdateSourceClient::sslVerify($source) ? 2 : 0]); if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); } $body = curl_exec($ch); $errno = curl_errno($ch); $error = curl_error($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); if ($errno) throw new Exception($error ?: '视频 API 网络请求失败'); $data = json_decode((string)$body, true); if (!is_array($data)) throw new Exception('视频 API 响应格式错误'); $hasApplicationError = isset($data['error']) || (isset($data['code']) && is_numeric($data['code']) && (int)$data['code'] !== 1); if (($http >= 400 || $hasApplicationError) && !$allowApplicationError) throw new Exception(self::error($data)); return $data; }
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
