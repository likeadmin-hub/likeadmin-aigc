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
    public const ENHANCE_APP = 'flashvsr';
    private const SUBMIT_APIS = [self::UPSTREAM_APP_CODE => self::UPSTREAM_API_CODE, self::ENHANCE_APP => 'submit'];
    private const MAX_RUNNING_SECONDS = 7200;

    public static function options(int $tenantId, string $upstreamAppCode = ''): array
    {
        return MarketCatalogReadScope::run(static fn(): array => self::readOptions($tenantId, $upstreamAppCode));
    }

    private static function readOptions(int $tenantId, string $upstreamAppCode = ''): array
    {
        $query = PowerMarketProduct::where(['resource_type' => PowerMarketService::TYPE_APP_API, 'status' => 1]);
        $app = $upstreamAppCode ?: self::UPSTREAM_APP_CODE;
        $api = self::SUBMIT_APIS[$app] ?? '';
        $query->where(['upstream_app_code' => $app, 'upstream_api_code' => $api]);
        $products = $query->order('id', 'asc')->select()->toArray();
        TenantPowerMarketService::applyProductDisplays($tenantId, $products);
        $catalogueSkus = MarketCatalogSkuReader::forProducts($tenantId, array_column($products, 'id'), ['id' => 'asc']);
        $options = [];
        foreach ($products as $product) {
            $metadata = self::metadata($product);
            foreach ($catalogueSkus[(int)$product['id']]['available'] ?? [] as $market) {
                $sku = $market['sku'];
                $locked = self::arrayValue($sku['locked_params'] ?? []);
                $options[] = [
                    ...MarketModelSource::product($product),
                    'id' => self::selectionId((int)$product['id'], (int)$sku['id'], $app),
                    'value' => self::selectionId((int)$product['id'], (int)$sku['id'], $app),
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
                    'tenant_unit_price' => self::points($market['tenant_price']),
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
        return MarketHealth::attachOptions(MarketModelSource::officialFirst($options));
    }

    public static function isSelection(array $selection): bool
    {
        $value = (string)($selection['id'] ?? $selection['value'] ?? $selection['channel'] ?? $selection['channel_code'] ?? '');
        return str_starts_with($value, 'market_watermark_app:') || ((int)($selection['market_product_id'] ?? 0) > 0 && (int)($selection['market_sku_id'] ?? $selection['sku_id'] ?? 0) > 0);
    }

    public static function quote(int $tenantId, array $selection, int|float $quantity = 1): array
    {
        $market = self::resolve($tenantId, $selection);
        MarketGenerationMeter::assertLegacyPricing($market['sku']);
        $quantity = self::quantity($market, $quantity);
        $deferred = MarketUsageSettlementService::isActualUsageSku($market['sku']);
        return [
            'billing_unit' => (string)$market['sku']['usage_unit'], 'billing_unit_size' => MarketUsageSettlementService::unitSize($market['sku']),
            'quantity' => $deferred ? 0 : $quantity, 'tenant_unit_points' => self::points((float)$market['sku']['sale_points']), 'user_unit_points' => self::points((float)$market['tenant_price']),
            'tenant_cost_points' => $deferred ? 0 : self::charge($market, (float)$market['sku']['sale_points'], $quantity), 'user_charge_points' => $deferred ? 0 : self::charge($market, (float)$market['tenant_price'], $quantity),
            'settlement_mode' => $deferred ? 'actual_usage' : 'reserved', 'price_source' => 'power_market_app_api', 'market_product_id' => (int)$market['product']['id'], 'market_sku_id' => (int)$market['sku']['id'], 'market_snapshot' => self::snapshot($market),
        ];
    }

    public static function reserve(int $tenantId, int $userId, string $action, string $businessTaskId, array $selection, array $request, int|float $quantity = 1, string $appCode = self::APP_CODE, string $businessTable = 'aigc_watermark_removal_task'): array
    {
        $market = self::resolve($tenantId, $selection);
        MarketGenerationMeter::assertLegacyPricing($market['sku']);
        $quantity = self::quantity($market, $quantity);
        $deferred = MarketUsageSettlementService::isActualUsageSku($market['sku']);
        $tenantCost = $deferred ? 0 : self::charge($market, (float)$market['sku']['sale_points'], $quantity);
        $userPrice = $deferred ? 0 : self::charge($market, (float)$market['tenant_price'], $quantity);
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
            if (!$deferred) PointService::reserveBusinessAmountsInCurrentTransaction($tenantId, $userId, $tenantCost, $userPrice, $consumeNo . '-reserve', self::label(self::snapshot($market)) . '预占', self::extra($appTask, $consumption, 'reserved'));
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
        $enhance = ($snapshot['app_code'] ?? '') === self::ENHANCE_APP;
        if ($enhance) {
            $endpoint = self::endpoint($snapshot, false);
            self::apiKey();
            if (!AiConsumptionLog::where(['id' => $consumptionId, 'run_status' => 'reserved'])->update([
                'run_status' => 'submitting', 'update_time' => time(),
            ])) return self::response(AiConsumptionLog::findOrEmpty($consumptionId)->toArray());
        }
        \app\common\service\open_platform\OpenSubmission::start($consumptionId);
        try {
            $body = self::request('POST', $endpoint ?? self::endpoint($snapshot, false), self::payload($snapshot, $request), false, $enhance);
            $taskId = self::taskId($body);
            $requestId = self::requestId($body);
            \app\common\service\open_platform\OpenSubmission::capture($consumptionId,$body,$taskId,$requestId);
            if ($enhance && $taskId === '') throw new Exception('视频处理任务回执待确认');
            $assets = $enhance ? [] : self::assets($body, (int)$c['tenant_id'], (int)$c['user_id'], [(string)($request['url'] ?? $request['source_url'] ?? '')]);
            Db::transaction(function () use ($consumptionId, $taskId, $requestId, $assets) { $ctx = self::context($consumptionId, true); if ($ctx === null) return; $c = $ctx['consumption']; $c->save(['run_status' => $assets === [] ? 'running' : 'success', 'upstream_task_id' => $taskId, 'upstream_request_id' => $requestId, 'update_time' => time()]); self::event((int)$c['id'], 'submit', 'success', ['upstream_task_id' => $taskId, 'video_count' => count($assets)]); });
            if ($assets !== []) { self::settle($consumptionId, $assets, $requestId, $taskId, $body); AiTaskJobService::enqueueProcessResult($consumptionId); return ['status' => 'success', 'provider_task_id' => $taskId, 'videos' => $assets]; }
            if ($taskId === '') throw new Exception('短视频去水印接口未返回任务 ID 或视频地址');
            AiTaskJobService::enqueueQueryResult($consumptionId);
            return ['status' => 'running', 'provider_task_id' => $taskId, 'videos' => []];
        } catch (\Throwable $e) {
            if ($enhance && !$e instanceof \InvalidArgumentException) {
                AiConsumptionLog::where(['id' => $consumptionId, 'run_status' => 'submitting'])->update([
                    'run_status' => 'pending_confirmation', 'error_code' => 'pending_confirmation',
                    'error_message' => '提交结果待确认，请在处理记录中核对', 'update_time' => time(),
                ]);
                return self::response(AiConsumptionLog::findOrEmpty($consumptionId)->toArray());
            }
            if (\app\common\service\open_platform\OpenSubmission::holdUnknown($consumptionId)) return self::response(AiConsumptionLog::findOrEmpty($consumptionId)->toArray());
            self::fail($consumptionId, $e->getMessage(), 'submit_failed'); throw $e instanceof Exception ? $e : new Exception('去水印任务提交失败'); }
    }

    public static function refresh(int $consumptionId): array
    {
        $ctx = self::context($consumptionId, false); if ($ctx === null) throw new Exception('短视频去水印消耗记录不存在');
        $c = $ctx['consumption'];
        if (\app\common\service\open_platform\OpenSubmission::pending($c->toArray())) return self::response($c->toArray()); if (in_array((string)$c['billing_status'], ['settled', 'refunded'], true)) return self::response($c->toArray());
        $taskId = trim((string)$c['upstream_task_id']); $snapshot = self::arrayValue($c['price_snapshot'] ?? []);
        $enhance = ($snapshot['app_code'] ?? '') === self::ENHANCE_APP;
        if ($enhance && $taskId === '' && in_array((string)$c['run_status'], ['submitting','pending_confirmation'], true)) {
            return self::response($c->toArray());
        }
        $label = self::label($snapshot);
        $created = self::timestamp($c['create_time'] ?? 0); $timedOut = $created > 0 && time() - $created >= self::MAX_RUNNING_SECONDS && !\app\common\service\open_platform\OpenSubmission::linked($c->toArray());
        if($taskId===''&&($receipt=\app\common\service\open_platform\OpenSubmission::receipt($c->toArray()))!==null){
            try{$summary=self::arrayValue($c['request_summary']??[]);$videos=self::assets($receipt,(int)$c['tenant_id'],(int)$c['user_id'],[(string)($summary['url']??'')]);if($videos!==[]){self::settle($consumptionId,$videos,self::requestId($receipt),'',$receipt);return ['status'=>'success','provider_task_id'=>'','videos'=>$videos];}}
            catch(\Throwable $e){AiTaskLifecycleEventService::record($consumptionId,'query_error','retrying',['error'=>'结果转存待重试']);}
        }
        if ($taskId === '') { if ($timedOut) { self::fail($consumptionId, $label . '任务超时', 'timeout'); return self::failureResponse('', $label . '任务超时'); } return self::response($c->toArray()); }
        try {
            $body = self::request((string)($snapshot['query_method'] ?? 'GET'), self::endpoint($snapshot, true, $taskId), (string)($snapshot['query_method'] ?? 'GET') === 'POST' ? ['task_id' => $taskId] : [], true, $enhance);
            $requestSummary = self::arrayValue($c['request_summary'] ?? []);
            $status = self::status($body, !$enhance);
            $images = $enhance && $status !== 'success' ? [] : self::assets($body, (int)$c['tenant_id'], (int)$c['user_id'], [(string)($requestSummary['url'] ?? '')]);
            if ($images !== []) { self::settle($consumptionId, $images, self::requestId($body), $taskId, $body); return ['status' => 'success', 'provider_task_id' => $taskId, 'videos' => $images]; }
            if (in_array($status, ['failed', 'error', 'canceled', 'cancelled'], true)) { $message = $enhance ? '视频处理失败，请检查素材后重试' : self::error($body); self::fail($consumptionId, $message, 'upstream_failed'); return self::failureResponse($taskId, $message); }
            if ($timedOut && !$enhance) { self::fail($consumptionId, $label . '任务处理超时', 'timeout'); return self::failureResponse($taskId, $label . '任务处理超时'); }
            return ['status' => 'running', 'provider_task_id' => $taskId, 'videos' => []];
        } catch (\Throwable $e) { if ($timedOut && !$enhance) { self::fail($consumptionId, $e->getMessage(), 'timeout'); return self::failureResponse($taskId, $e->getMessage()); } return ['status' => 'running', 'provider_task_id' => $taskId, 'videos' => []]; }
    }

    public static function cancel(int $consumptionId): void { self::fail($consumptionId, '用户取消任务', 'canceled'); }

    public static function fail(int $consumptionId, string $message, string $code = 'failed'): void
    {
        Db::transaction(function () use ($consumptionId, $message, $code) { $ctx = self::context($consumptionId, true); if ($ctx === null) return; $c = $ctx['consumption']; $task = $ctx['app_task']; if (in_array((string)$c['billing_status'], ['settled', 'refunded'], true)) return; if ((float)$c['reserved_tenant_cost'] > 0 || (float)$c['reserved_user_price'] > 0) PointService::releaseReservedBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], (float)$c['reserved_tenant_cost'], (float)$c['reserved_user_price'], (string)$c['consume_no'] . '-release', '去水印失败退回', self::extra($task, $c, 'refunded')); $now = time(); $c->save(['run_status' => $code === 'canceled' ? 'canceled' : 'failed', 'billing_status' => 'refunded', 'error_code' => $code, 'error_message' => mb_substr($message, 0, 1000), 'finish_time' => $now, 'update_time' => $now]); $task->save(['status' => $code === 'canceled' ? 'canceled' : 'failed', 'progress' => 100, 'result_summary' => ['error' => mb_substr($message, 0, 500)], 'finish_time' => $now, 'update_time' => $now]); self::event((int)$c['id'], $code === 'canceled' ? 'cancel' : 'refund', 'success', ['reason' => $message]); });
    }

    private static function settle(int $consumptionId, array $videos, string $requestId, string $taskId, array $response): void
    {
        Db::transaction(function () use ($consumptionId, $videos, $requestId, $taskId, $response) {
            $context = self::context($consumptionId, true);
            if ($context === null) return;
            $consumption = $context['consumption'];
            $task = $context['app_task'];
            if (in_array((string)$consumption['billing_status'], ['settled', 'refunded'], true)) return;

            $snapshot = self::arrayValue($consumption['price_snapshot']);
            $inputBilled = ($snapshot['app_code'] ?? '') === self::ENHANCE_APP;
            $quantity = $inputBilled ? (float)$consumption['quantity'] : count($videos);
            $deferred = MarketUsageSettlementService::isActualUsageSku($snapshot);
            $tenantCost = $deferred ? MarketUsageSettlementService::price((float)$snapshot['platform_price'], $quantity, $snapshot)
                : (float)$consumption['reserved_tenant_cost'];
            $userCost = $deferred ? MarketUsageSettlementService::price((float)$snapshot['tenant_price'], $quantity, $snapshot)
                : (float)$consumption['reserved_user_price'];
            $tenant = (int)$consumption['tenant_id'];
            $user = (int)$consumption['user_id'];
            $sn = (string)$consumption['consume_no'];
            $label = self::label($snapshot);
            $extra = self::extra($task, $consumption, 'settled');
            if ($deferred) {
                PointService::assertCanConsumeAmounts($tenant, $user, $tenantCost, $userCost);
                PointService::consumeBusinessAmountsInCurrentTransaction($tenant, $user, $tenantCost, $userCost, $sn, $label . '实际用量结算', $extra);
            } else {
                PointService::settleReservedBusinessAmountsInCurrentTransaction(
                    $tenant, $user, (float)$consumption['reserved_tenant_cost'], (float)$consumption['reserved_user_price'],
                    $tenantCost, $userCost, $sn, $label . '结算', $extra
                );
            }

            $now = time();
            $usage = self::arrayValue($consumption['usage_snapshot']);
            if ($inputBilled) $usage['input_seconds'] = $quantity;
            $consumption->save([
                'run_status' => 'success', 'billing_status' => 'settled',
                'upstream_request_id' => $requestId ?: (string)$consumption['upstream_request_id'],
                'upstream_task_id' => $taskId ?: (string)$consumption['upstream_task_id'],
                'quantity' => $quantity, 'usage_snapshot' => $usage,
                'response_summary' => ['videos' => $videos, 'status' => self::status($response)],
                'actual_tenant_cost' => $tenantCost, 'actual_user_price' => $userCost,
                'finish_time' => $now, 'update_time' => $now,
            ]);
            $task->save(['status' => 'success', 'progress' => 100, 'actual_tenant_cost' => $tenantCost,
                'actual_user_price' => $userCost, 'result_summary' => ['videos' => $videos], 'finish_time' => $now, 'update_time' => $now]);
            self::event((int)$consumption['id'], 'settle', 'success', ['video_count' => count($videos)]);
        });
    }

    private static function resolve(int $tenantId, array $selection): array
    {
        $app = (string)($selection['upstream_app_code'] ?? self::UPSTREAM_APP_CODE);
        $productId = (int)($selection['market_product_id'] ?? 0); $skuId = (int)($selection['market_sku_id'] ?? $selection['sku_id'] ?? 0); $id = (string)($selection['id'] ?? $selection['value'] ?? $selection['channel'] ?? $selection['channel_code'] ?? '');
        if (($productId <= 0 || $skuId <= 0) && preg_match('/^market_watermark_app:(\d+):(\d+)$/', $id, $m)) { $productId = (int)$m[1]; $skuId = (int)$m[2]; }
        $product = PowerMarketProduct::where(['id' => $productId, 'resource_type' => PowerMarketService::TYPE_APP_API, 'upstream_app_code' => $app, 'upstream_api_code' => self::SUBMIT_APIS[$app] ?? '', 'status' => 1])->findOrEmpty(); if ($product->isEmpty()) throw new Exception('所选视频处理通道已下架');
        $sku = PowerMarketSku::where(['id' => $skuId, 'product_id' => $productId, 'status' => 1, 'sale_status' => 1])->findOrEmpty(); if ($sku->isEmpty()) throw new Exception('所选视频处理规格已下架');
        $tenant = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => $skuId])->findOrEmpty(); if (!$tenant->isEmpty() && (int)$tenant['sale_status'] !== 1) throw new Exception('所选视频处理规格暂不可用');
        return ['product' => $product->toArray(), ...MarketCatalogSkuReader::requirePriced($tenantId, $sku->toArray(), $tenant->toArray())];
    }

    private static function snapshot(array $market): array { $p = $market['product']; $s = $market['sku']; $m = self::metadata($p); return ['product_id' => (int)$p['id'], 'sku_id' => (int)$s['id'], 'sku_key' => (string)$s['sku_key'], 'app_code' => (string)$p['upstream_app_code'], 'api_code' => (string)$p['upstream_api_code'], 'runtime_adapter' => (string)$p['upstream_app_code'], 'locked_params' => self::arrayValue($s['locked_params'] ?? []), 'params_schema' => self::arrayValue($m['params_schema'] ?? []), 'query_path' => $p['upstream_app_code'] === self::ENHANCE_APP ? '/api/v1/tasks/{task_id}' : (string)($m['query_path'] ?? $m['capabilities']['query_path'] ?? '/api/v1/tasks/{task_id}'), 'query_method' => 'GET', 'usage_unit' => (string)$s['usage_unit'], 'usage_unit_size' => MarketUsageSettlementService::unitSize($s), 'upstream_price' => (float)$s['upstream_price'], 'platform_price' => (float)$s['sale_points'], 'tenant_price' => (float)$market['tenant_price']]; }
    private static function payload(array $snapshot, array $request): array
    {
        $url = trim((string)($request['url'] ?? $request['source_url'] ?? ''));
        if (($snapshot['app_code'] ?? '') === self::ENHANCE_APP) {
            return ['input_url' => $url, 'bit_rate' => (int)$request['bit_rate'], 'duration' => (float)$request['duration']];
        }
        return ['url' => $url];
    }
    private static function endpoint(array $snapshot, bool $query, string $taskId = ''): string { $origin = self::origin(); if ($query) { $path = str_replace('{task_id}', rawurlencode($taskId), (string)($snapshot['query_path'] ?? '/api/v1/tasks/{task_id}')); return $origin . '/' . ltrim($path, '/'); } return $origin . '/api/v1/apps/' . rawurlencode((string)$snapshot['app_code']) . '/' . rawurlencode((string)$snapshot['api_code']); }
    private static function marketContext(array $s): array { return ['market_product_id' => (int)$s['product_id'], 'market_sku_id' => (int)$s['sku_id'], 'sku_id' => (int)$s['sku_id'], 'sku_key' => (string)$s['sku_key'], 'price_source' => 'power_market_app_api']; }
    private static function assets(array $data, int $tenantId, int $userId, array $excluded = []): array { $excluded = array_values(array_filter(array_map('trim', $excluded))); $payload = self::arrayValue($data['data'] ?? []); if (isset($payload['result']) && is_array($payload['result'])) $payload = $payload['result']; elseif (isset($data['result']) && is_array($data['result'])) $payload = $data['result']; $raw = is_string($data['data'] ?? null) ? trim($data['data']) : ''; $urls = preg_match('#^(?:https?://|data:video/)#i', $raw) === 1 ? [$raw] : AiTaskResultUrlService::collect($payload !== [] ? $payload : $data); $urls = array_values(array_diff($urls, $excluded)); $items = []; foreach (array_values(array_unique($urls)) as $url) { $stored = AigcVideoAssetService::persistGeneratedVideo($url, $tenantId, $userId); $items[] = ['video_uri' => (string)$stored['uri'], 'width' => (int)($stored['width'] ?? 0), 'height' => (int)($stored['height'] ?? 0), 'storage_scope' => (string)($stored['storage_scope'] ?? 'tenant'), 'storage_engine' => (string)($stored['storage_engine'] ?? 'local'), 'storage_domain' => (string)($stored['storage_domain'] ?? '')]; } return $items; }
    private static function apiKey(): string
    {
        $source = UpdateSourceClient::getSource();
        $key = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? ''));
        if ($key === '') throw new \InvalidArgumentException('视频处理服务尚未配置，请联系管理员');
        return $key;
    }

    private static function request(string $method, string $url, array $payload = [], bool $allowError = false, bool $enhance = false): array
    {
        $source = UpdateSourceClient::getSource();
        $verify = UpdateSourceClient::sslVerify($source);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::apiKey(), 'Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
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
        if ($errno) throw new Exception($enhance ? '视频处理接口连接超时或暂不可用' : ($error ?: '去水印应用网络请求失败'));
        $data = json_decode((string)$body, true);
        if ($method === 'POST' && is_array($data)) \app\common\service\open_platform\OpenCallContext::providerFailure($http, (string)$body, self::taskId($data) !== '');
        if ($enhance && in_array($http, [400, 401, 403, 404, 405, 413, 422, 429], true)) {
            throw new \InvalidArgumentException('视频处理请求未受理，请检查素材或稍后再试');
        }
        if (!is_array($data) || ($enhance && $http >= 400)) throw new Exception('视频处理接口响应暂不可用');
        if (!$allowError && ($http >= 400 || isset($data['error']) || (isset($data['code']) && (int)$data['code'] !== 1))) {
            if ($enhance) {
                $reason = (string)($data['error']['code'] ?? $data['data']['error']['code'] ?? '');
                if (in_array($reason, ['invalid_request','config_error','auth_failed','insufficient_points','permission_denied','task_failed'], true)) {
                    throw new \InvalidArgumentException('视频处理请求未受理，请检查素材或联系管理员');
                }
                throw new Exception('视频处理任务回执待确认');
            }
            throw new Exception(self::error($data));
        }
        return $data;
    }
    private static function response(array $c): array { $s = self::arrayValue($c['response_summary'] ?? []); $message = trim((string)($c['error_message'] ?? '')); $out = ['status' => (string)$c['run_status'], 'provider_task_id' => (string)$c['upstream_task_id'], 'videos' => (array)($s['videos'] ?? [])]; return $message === '' ? $out : array_merge($out, ['error' => $message, 'error_msg' => $message]); }
    private static function failureResponse(string $id, string $message): array { return ['status' => 'failed', 'provider_task_id' => $id, 'videos' => [], 'error' => $message, 'error_msg' => $message]; }
    private static function status(array $d, bool $inferFailure = true): string { $root = self::arrayValue($d['data'] ?? $d); $v = strtolower((string)($d['status'] ?? $root['status'] ?? $root['state'] ?? $root['task_status'] ?? $root['result']['status'] ?? '')); if ($inferFailure && $v === '' && array_key_exists('code', $d) && (int)$d['code'] === 0) return 'failed'; return match ($v) { 'done', 'completed', 'complete', 'success', 'succeeded' => 'success', 'failed', 'fail', 'error' => 'failed', 'canceled', 'cancelled' => 'canceled', default => $v ?: 'running' }; }
    private static function taskId(array $d): string { $root = self::arrayValue($d['data'] ?? $d); foreach ([$d['task_id'] ?? null, $d['id'] ?? null, $root['task_id'] ?? null, $root['id'] ?? null, $root['taskId'] ?? null] as $v) if (is_scalar($v) && (string)$v !== '') return (string)$v; return ''; }
    private static function requestId(array $d): string { $root = self::arrayValue($d['data'] ?? $d); return (string)($d['request_id'] ?? $root['request_id'] ?? ''); }
    private static function error(array $d): string { return UpstreamErrorMessageService::fromResponse($d) ?: '短视频去水印应用 API 请求失败'; }
    private static function metadata(array $p): array { $source = self::arrayValue($p['source_payload'] ?? []); return self::arrayValue($source['market_metadata'] ?? []); }
    private static function origin(): string { $s = UpdateSourceClient::getSource(); $parts = parse_url(trim((string)($s['active_base_url'] ?? $s['base_url'] ?? ''))); $host = (string)($parts['host'] ?? ''); if ($host === '') throw new Exception('去水印应用 API 暂不可用'); return (string)($parts['scheme'] ?? 'https') . '://' . $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : ''); }
    private static function existingReservation(int $tenantId, string $key, bool $lock = false): ?array { $q = AiAppTask::where(['tenant_id' => $tenantId, 'idempotency_key' => $key]); if ($lock) $q->lock(true); $task = $q->findOrEmpty(); if ($task->isEmpty()) return null; $q2 = AiConsumptionLog::where('app_task_id', (int)$task['id'])->order('id', 'asc'); if ($lock) $q2->lock(true); $c = $q2->findOrEmpty(); if ($c->isEmpty()) throw new Exception('短视频去水印任务缺少消耗记录'); return ['app_task_id' => (int)$task['id'], 'consumption_id' => (int)$c['id'], 'consume_no' => (string)$c['consume_no'], 'market_snapshot' => self::arrayValue($c['price_snapshot'] ?? [])]; }
    private static function context(int $id, bool $lock): ?array { $q = AiConsumptionLog::where('id', $id); if ($lock) $q->lock(true); $c = $q->findOrEmpty(); if ($c->isEmpty()) return null; $q2 = AiAppTask::where('id', (int)$c['app_task_id']); if ($lock) $q2->lock(true); $t = $q2->findOrEmpty(); return $t->isEmpty() ? null : ['consumption' => $c, 'app_task' => $t]; }
    private static function event(int $id, string $type, string $status, array $summary): void { AiConsumptionEvent::create(['consumption_id' => $id, 'event_type' => $type, 'event_status' => $status, 'attempt_no' => 1, 'payload_summary' => $summary, 'payload_ciphertext' => '', 'http_status' => 0, 'elapsed_ms' => 0, 'create_time' => time()]); }
    private static function extra(AiAppTask $t, AiConsumptionLog $c, string $stage): array { return ['app_code' => (string)$t['app_code'], 'app_task_id' => (int)$t['id'], 'app_task_no' => (string)$t['task_no'], 'consumption_id' => (int)$c['id'], 'consume_no' => (string)$c['consume_no'], 'billing_stage' => $stage]; }
    private static function requestSummary(array $request): array
    {
        $summary = ['url' => trim((string)($request['url'] ?? $request['source_url'] ?? ''))];
        if (isset($request['duration'], $request['bit_rate'])) {
            $summary += ['duration' => (float)$request['duration'], 'bit_rate' => (int)$request['bit_rate']];
        }
        return $summary;
    }
    private static function arrayValue(mixed $v): array { if (is_array($v)) return $v; if (is_string($v) && $v !== '') { $d = json_decode($v, true); return is_array($d) ? $d : []; } return []; }
    private static function timestamp(mixed $v): int { if (is_numeric($v)) return (int)$v; $t = strtotime((string)$v); return $t === false ? 0 : $t; }
    private static function points(float $v): float { return round(max(0, $v), 6); }
    private static function selectionId(int $p, int $s, string $app): string
    {
        return ($app === self::ENHANCE_APP ? 'market_flashvsr_app:' : 'market_watermark_app:') . $p . ':' . $s;
    }
    private static function label(array $snapshot): string
    {
        return ($snapshot['app_code'] ?? '') === self::ENHANCE_APP ? '视频变清晰' : '短视频去水印';
    }
    private static function quantity(array $market, int|float $quantity): int|float
    {
        if ($market['product']['upstream_app_code'] !== self::ENHANCE_APP) return max(1, (int)$quantity);
        if (($market['sku']['usage_unit'] ?? '') !== 'input_second' || !is_finite((float)$quantity) || $quantity <= 0 || $quantity > 30) {
            throw new \InvalidArgumentException('视频时长或处理计费规格不可用');
        }
        return $quantity;
    }
    private static function charge(array $market, float $rate, int|float $quantity): float
    {
        return $market['product']['upstream_app_code'] === self::ENHANCE_APP
            ? MarketUsageSettlementService::price($rate, $quantity, $market['sku'])
            : self::points($rate * $quantity);
    }
    private static function no(string $p): string { return $p . date('YmdHis') . strtoupper(bin2hex(random_bytes(5))); }
}
