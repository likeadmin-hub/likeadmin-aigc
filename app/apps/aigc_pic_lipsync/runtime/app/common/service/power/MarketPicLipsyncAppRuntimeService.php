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
use app\common\service\app\aigc_video\AigcVideoAssetService;
use app\common\service\point\PointService;
use app\common\service\update\UpdateSourceClient;
use Exception;
use think\facade\Db;

/** Executes the pic_lipsync picture lip-sync application API. */
class MarketPicLipsyncAppRuntimeService
{
    public const APP_CODE = 'aigc_pic_lipsync';
    public const UPSTREAM_APP_CODE = 'pic_lipsync';
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
                if (!in_array((string)(self::arrayValue($sku['locked_params'] ?? [])['quality'] ?? ''), ['fast', 'standard', 'max'], true)) continue;
                $tenantPrice = $tenant->isEmpty() ? (float)$sku['sale_points'] : (float)$tenant['sale_points'];
                $options[] = [
                    'id' => self::selectionId((int)$product['id'], (int)$sku['id']),
                    'value' => self::selectionId((int)$product['id'], (int)$sku['id']),
                    'label' => (string)($sku['title'] ?: ($product['name'] ?? '图片数字人、AI翻唱 API')),
                    'name' => (string)($sku['title'] ?: ($product['name'] ?? '图片数字人、AI翻唱 API')),
                    'description' => (string)($product['description'] ?? ''),
                    'display_icon' => (string)($product['display_icon'] ?? ''),
                    'resource_type' => PowerMarketService::TYPE_APP_API,
                    'resource_type_label' => '应用 API',
                    'category_code' => 'video',
                    'category_name' => '数字人视频',
                    'market_product_id' => (int)$product['id'],
                    'market_sku_id' => (int)$sku['id'],
                    'sku_id' => (int)$sku['id'],
                    'sku_key' => (string)$sku['sku_key'],
                    'app_code' => self::UPSTREAM_APP_CODE,
                    'api_code' => self::SUBMIT_API_CODE,
                    'quality' => (string)(self::arrayValue($sku['locked_params'] ?? [])['quality'] ?? ''),
                    'locked_params' => self::arrayValue($sku['locked_params'] ?? []),
                    'selectable_params' => self::arrayValue($sku['selectable_params'] ?? []),
                    'params_schema' => self::arrayValue($metadata['params_schema'] ?? []),
                    'default_params' => self::arrayValue($metadata['default_params'] ?? []),
                    'capabilities' => self::arrayValue($metadata['capabilities'] ?? []),
                    'query_api_code' => self::QUERY_API_CODE,
                    'platform_unit_cost' => self::points((float)$sku['sale_points']),
                    'tenant_unit_price' => self::points($tenantPrice),
                    'usage_unit' => (string)$sku['usage_unit'],
                    'usage_unit_size' => max(1, (float)($sku['usage_unit_size'] ?? 1)),
                    'settlement_mode' => 'input_audio_duration',
                    'enabled' => true,
                    'available' => true,
                    'sort' => (int)$sku['id'],
                ];
            }
        }
        return $options;
    }

    public static function quote(int $tenantId, array $selection, float $quantity = 0): array
    {
        $market = self::resolve($tenantId, $selection);
        $quantity = max(0, $quantity);
        $deferred = false;
        return [
            'billing_unit' => (string)$market['sku']['usage_unit'],
            'billing_unit_size' => max(1, (float)($market['sku']['usage_unit_size'] ?? 1)),
            'quantity' => $deferred ? 0 : $quantity,
            'tenant_unit_points' => self::points((float)$market['sku']['sale_points']),
            'user_unit_points' => self::points((float)$market['tenant_price']),
            'tenant_cost_points' => $deferred ? 0 : self::cost((float)$market['sku']['sale_points'], $quantity, $market['sku']),
            'user_charge_points' => $deferred ? 0 : self::cost((float)$market['tenant_price'], $quantity, $market['sku']),
            'settlement_mode' => 'input_audio_duration',
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
        float $quantity = 0,
        string $appCode = self::APP_CODE,
        string $businessTable = 'aigc_pic_lipsync_task'
    ): array {
        if (MarketAppGateService::requiresGate($appCode)) {
            MarketAppGateService::requireMarket($tenantId, $userId, $appCode, (string)($request['idempotency_key'] ?? $businessTaskId));
        }
        $market = self::resolve($tenantId, $selection);
        if ($quantity <= 0) throw new Exception('无法确认输入音频时长');
        self::payload(self::snapshot($market), $request, $businessTaskId);
        $deferred = false;
        $tenantCost = $deferred ? 0 : self::cost((float)$market['sku']['sale_points'], $quantity, $market['sku']);
        $userPrice = $deferred ? 0 : self::cost((float)$market['tenant_price'], $quantity, $market['sku']);
        $key = sha1($tenantId . '|' . $appCode . '|' . $action . '|' . $businessTaskId . '|pic_lipsync');
        $existing = self::existingReservation($tenantId, $key);
        if ($existing !== null) {
            return $existing;
        }
        if (!$deferred) {
            PointService::assertCanConsumeAmounts($tenantId, $userId, $tenantCost, $userPrice);
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
                'request_summary' => array_merge(self::requestSummary($request), ['input_audio_seconds' => $quantity]),
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
                'request_summary' => array_merge(self::requestSummary($request), ['input_audio_seconds' => $quantity]),
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
                    '图片数字人预占',
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
            throw new Exception('市场图片数字人消耗记录不存在');
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
                throw new Exception('图片数字人接口未返回任务 ID');
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
            return ['status' => 'running', 'provider_task_id' => $taskId, 'videos' => []];
        } catch (\Throwable $e) {
            self::fail($consumptionId, $e->getMessage(), 'submit_failed');
            throw $e instanceof Exception ? $e : new Exception('图片数字人接口提交失败');
        }
    }

    public static function refresh(int $consumptionId): array
    {
        $context = self::context($consumptionId, false);
        if ($context === null) {
            throw new Exception('市场图片数字人消耗记录不存在');
        }
        $consumption = $context['consumption'];
        if (in_array((string)$consumption['billing_status'], ['settled', 'refunded'], true)
            || in_array((string)$consumption['run_status'], ['success', 'failed', 'canceled', 'cancelled'], true)) {
            return self::response($consumption->toArray());
        }
        $taskId = trim((string)$consumption['upstream_task_id']);
        $created = self::timestamp($consumption['create_time'] ?? 0);
        $timedOut = $created > 0 && time() - $created >= self::MAX_RUNNING_SECONDS;
        if ($taskId === '') {
            if ($timedOut) {
                self::fail($consumptionId, '图片数字人任务未返回上游任务号', 'timeout');
                return self::failureResponse('', '图片数字人任务未返回上游任务号');
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
                return ['status' => 'success', 'provider_task_id' => $taskId, 'videos' => $items];
            }
            $status = self::status($body);
            if (in_array($status, ['failed', 'error', 'canceled', 'cancelled'], true)) {
                $message = self::error($body) ?: '图片数字人任务失败';
                self::fail($consumptionId, $message, 'upstream_failed');
                return self::failureResponse($taskId, $message);
            }
            if (AiTaskLifecycleEventService::isTerminalSuccess($status)) {
                $message = '图片数字人任务已完成，但未返回可用视频';
                self::fail($consumptionId, $message, 'upstream_result_missing');
                return self::failureResponse($taskId, $message);
            }
            if ($timedOut) {
                self::fail($consumptionId, '图片数字人任务处理超时', 'timeout');
                return self::failureResponse($taskId, '图片数字人任务处理超时');
            }
            return ['status' => 'running', 'provider_task_id' => $taskId, 'videos' => []];
        } catch (\Throwable $e) {
            if ((int)$e->getCode() === 422) {
                $message = $e->getMessage() ?: '图片数字人任务失败';
                self::fail($consumptionId, $message, 'upstream_failed');
                return self::failureResponse($taskId, $message);
            }
            if ($timedOut) {
                self::fail($consumptionId, $e->getMessage(), 'timeout');
                return self::failureResponse($taskId, $e->getMessage());
            }
            AiTaskLifecycleEventService::record($consumptionId, 'query_error', 'retrying', ['upstream_task_id' => $taskId, 'error' => $e->getMessage()]);
            return ['status' => 'running', 'provider_task_id' => $taskId, 'videos' => []];
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
                    '图片数字人失败退回',
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
            $quantity = self::inputSeconds($response) ?: (float)$consumption['quantity'];
            if ($quantity <= 0) throw new Exception('图片数字人任务缺少输入音频用量');
            $tenant = self::cost((float)($snapshot['platform_price'] ?? 0), $quantity, $snapshot);
            $user = self::cost((float)($snapshot['tenant_price'] ?? 0), $quantity, $snapshot);
            PointService::settleReservedBusinessAmountsInCurrentTransaction((int)$consumption['tenant_id'], (int)$consumption['user_id'], (float)$consumption['reserved_tenant_cost'], (float)$consumption['reserved_user_price'], $tenant, $user, (string)$consumption['consume_no'], '图片数字人结算', self::extra($task, $consumption, 'settled'));
            $now = time();
            $consumption->save([
                'run_status' => 'success',
                'billing_status' => 'settled',
                'upstream_request_id' => $requestId ?: (string)$consumption['upstream_request_id'],
                'upstream_task_id' => $taskId,
                'quantity' => $quantity,
                'usage_snapshot' => ['input_audio_seconds' => $quantity],
                'response_summary' => ['videos' => $items],
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
                'result_summary' => ['videos' => $items],
                'finish_time' => $now,
                'update_time' => $now,
            ]);
            self::event((int)$consumption['id'], 'settle', 'success', ['input_audio_seconds' => $quantity]);
        });
    }

    private static function resolve(int $tenantId, array $selection): array
    {
        $productId = (int)($selection['market_product_id'] ?? 0);
        $skuId = (int)($selection['market_sku_id'] ?? $selection['sku_id'] ?? 0);
        $id = (string)($selection['id'] ?? $selection['value'] ?? '');
        if (($productId <= 0 || $skuId <= 0) && preg_match('/^market_pic_lipsync_app:(\d+):(\d+)$/', $id, $matches)) {
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
            throw new Exception('所选图片数字人应用 API 已下架');
        }
        $sku = PowerMarketSku::where([
            'id' => $skuId,
            'product_id' => $productId,
            'status' => 1,
            'sale_status' => 1,
        ])->findOrEmpty();
        if ($sku->isEmpty()) {
            throw new Exception('所选图片数字人规格已下架');
        }
        $tenant = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => $skuId])->findOrEmpty();
        if (!$tenant->isEmpty() && (int)$tenant['sale_status'] !== 1) {
            throw new Exception('所选图片数字人规格暂不可用');
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
            'runtime_adapter' => 'pic_lipsync',
            'locked_params' => self::arrayValue($sku['locked_params'] ?? []),
            'params_schema' => self::arrayValue($metadata['params_schema'] ?? []),
            'query_api_code' => self::QUERY_API_CODE,
            'usage_unit' => (string)$sku['usage_unit'],
            'usage_unit_size' => max(1, (float)($sku['usage_unit_size'] ?? 1)),
            'upstream_price' => (float)$sku['upstream_price'],
            'platform_price' => (float)$sku['sale_points'],
            'tenant_price' => (float)$market['tenant_price'],
        ];
    }

    public static function buildPayload(array $snapshot, array $request): array
    {
        $locked = self::arrayValue($snapshot['locked_params'] ?? []);
        $quality = (string)($locked['quality'] ?? '');
        if (!in_array($quality, ['fast', 'standard', 'max'], true)) throw new Exception('所选图片数字人规格没有有效的质量模式');
        if (isset($request['quality']) && $request['quality'] !== $quality) throw new Exception('质量模式与所选市场规格不一致');
        $mode = (string)($request['mode'] ?? 'audio');
        if (!in_array($mode, ['audio', 'text'], true)) throw new Exception('驱动模式无效');
        $payload = ['model' => 'super-lipsync-pro', 'quality' => $quality, 'mode' => $mode];
        foreach (['image_url', 'audio_url'] as $field) {
            $value = trim((string)($request[$field] ?? ''));
            if (!self::validUrl($value)) throw new Exception($field === 'image_url' ? '请上传人物图片' : '请上传驱动音频或参考音色');
            $payload[$field] = $value;
        }
        if ($mode === 'text') {
            $payload['content'] = trim((string)($request['content'] ?? ''));
            if ($payload['content'] === '') throw new Exception('请输入口播文案');
            if (mb_strlen($payload['content']) > 10000) throw new Exception('口播文案最多10000字');
        }
        $prompt = trim((string)($request['prompt'] ?? ''));
        if (mb_strlen($prompt) > 2000) throw new Exception('动作提示词最多2000字');
        if ($prompt !== '') $payload['prompt'] = $prompt;
        return $payload;
    }

    private static function payload(array $snapshot, array $request, string $key): array
    {
        return self::buildPayload($snapshot, $request);
    }

    private static function items(array $data, int $tenantId, int $userId): array
    {
        $items = [];
        foreach (AiTaskResultUrlService::collect($data) as $url) {
            // A thumbnail or echoed input must never be mistaken for the output video.
            $path = strtolower((string)parse_url($url, PHP_URL_PATH));
            if (preg_match('/\.(png|jpg|jpeg|webp|gif|mp3|wav|m4a)$/', $path)) continue;
            $stored = AigcVideoAssetService::persistGeneratedVideo($url, $tenantId, $userId);
            if (empty($stored['uri'])) throw new Exception('图片数字人视频保存失败');
            $items[] = [
                'video_uri' => (string)$stored['uri'],
                'storage_scope' => (string)($stored['storage_scope'] ?? 'tenant'),
                'storage_engine' => (string)($stored['storage_engine'] ?? 'local'),
                'storage_domain' => (string)($stored['storage_domain'] ?? ''),
                'width' => (int)($stored['width'] ?? 0), 'height' => (int)($stored['height'] ?? 0),
            ];
        }
        return $items;
    }

    private static function cost(float $price, float $quantity, array $sku): float
    {
        return self::points($price * $quantity / max(1, (float)($sku['usage_unit_size'] ?? 1)));
    }

    public static function inputSeconds(array $response): float
    {
        // Deliberately exclude output duration: billing is for the input audio.
        foreach (['input_second', 'input_seconds', 'input_audio_seconds', 'audio_duration'] as $key) {
            if (isset($response[$key]) && is_numeric($response[$key]) && (float)$response[$key] > 0) return (float)$response[$key];
        }
        foreach (['usage', 'usage_snapshot', 'billing', 'data', 'result'] as $key) {
            if (is_array($response[$key] ?? null)) { $value = self::inputSeconds($response[$key]); if ($value > 0) return $value; }
        }
        return 0;
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
            throw new Exception('图片数字人应用 API 暂不可用');
        }
        return (string)($parts['scheme'] ?? 'https') . '://' . $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
    }

    private static function request(string $method, string $url, array $payload = []): array
    {
        $source = UpdateSourceClient::getSource();
        $key = trim((string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? ''));
        if ($key === '') {
            throw new Exception('图片数字人应用 API 暂不可用');
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
            throw new Exception($error ?: '图片数字人应用网络请求失败');
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            throw new Exception('图片数字人应用响应格式错误');
        }
        if ($http >= 400 || isset($data['error']) || (array_key_exists('code', $data) && (int)$data['code'] !== 1)) {
            throw new Exception(self::error($data), 422);
        }
        return $data;
    }

    private static function status(array $data): string
    {
        $candidates = [];
        self::collectStatusCandidates($data, $candidates);
        $normalized = [];
        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim((string)$candidate));
            if ($candidate !== '') {
                $normalized[] = $candidate;
            }
        }
        if (array_intersect($normalized, ['failed', 'fail', 'error', 'failure', 'rejected', '拒绝', '失败']) !== []) {
            return 'failed';
        }
        if (array_intersect($normalized, ['canceled', 'cancelled']) !== []) {
            return 'canceled';
        }
        if (array_intersect($normalized, ['done', 'completed', 'complete', 'success', 'succeeded', 'finished']) !== []) {
            return 'success';
        }
        return $normalized[0] ?? 'running';
    }

    /** Collect status fields from common Seedsvc response nesting shapes. */
    private static function collectStatusCandidates(mixed $value, array &$candidates, int $depth = 0): void
    {
        if ($depth > 5 || !is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            $key = strtolower((string)$key);
            if (in_array($key, ['status', 'state', 'task_status', 'task_state', 'result_status', 'run_status'], true)
                && (is_scalar($item) || $item === null)) {
                $candidates[] = (string)$item;
            }
            if (is_array($item)) {
                self::collectStatusCandidates($item, $candidates, $depth + 1);
            }
        }
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
        return UpstreamErrorMessageService::fromResponse($data) ?: '图片数字人应用 API 请求失败';
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
            'videos' => (array)($summary['videos'] ?? []),
        ];
        return $message === '' ? $response : array_merge($response, ['error' => $message, 'error_msg' => $message]);
    }

    private static function failureResponse(string $taskId, string $message): array
    {
        return ['status' => 'failed', 'provider_task_id' => $taskId, 'videos' => [], 'error' => $message, 'error_msg' => $message];
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
            throw new Exception('图片数字人任务缺少消耗记录');
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
        return ['mode' => (string)($request['mode'] ?? 'audio'), 'quality' => (string)($request['quality'] ?? ''),
            'content_length' => mb_strlen((string)($request['content'] ?? '')), 'has_image' => !empty($request['image_url']), 'has_audio' => !empty($request['audio_url'])];
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
        return 'market_pic_lipsync_app:' . $productId . ':' . $skuId;
    }

    private static function no(string $prefix): string
    {
        return $prefix . date('YmdHis') . strtoupper(bin2hex(random_bytes(5)));
    }
}
