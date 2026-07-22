<?php

namespace app\common\service\ai;

use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionEvent;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\model\power\TenantPowerMarketSkuPrice;
use app\common\service\point\PointService;
use RuntimeException;
use think\facade\Db;

class AiUsageService
{
    public const APP_TASK_RUNNING = 'running';
    public const APP_TASK_SUCCESS = 'success';
    public const APP_TASK_PARTIAL = 'partial_success';
    public const APP_TASK_FAILED = 'failed';

    public static function resolveImageMarketEstimate(int $tenantId, array $selection, array $estimate, int $quantity): array
    {
        $market = self::resolveImageMarket($tenantId, $selection);
        if ($market === []) {
            return $estimate;
        }
        $quantity = max(1, $quantity);
        $estimate['platform_unit_cost'] = self::points($market['platform_price'] ?? 0);
        $estimate['tenant_unit_price'] = self::points($market['tenant_price'] ?? 0);
        $estimate['tenant_cost_points'] = self::points((float)$estimate['platform_unit_cost'] * $quantity);
        $estimate['user_charge_points'] = self::points((float)$estimate['tenant_unit_price'] * $quantity);
        return $estimate;
    }

    /** @return array{app_task: AiAppTask, consumption: AiConsumptionLog} */
    public static function createImageSubmission(
        int $tenantId,
        int $userId,
        array $request,
        array $selection,
        array $estimate
    ): array {
        $now = time();
        $market = self::resolveImageMarket($tenantId, $selection);
        $appTask = AiAppTask::create([
            'task_no' => self::newNo('AT'),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'app_code' => 'aigc_image',
            'action_code' => 'generate',
            'business_table' => 'aigc_image_task',
            'business_id' => 0,
            'parent_task_id' => 0,
            'status' => self::APP_TASK_RUNNING,
            'progress' => 5,
            'request_summary' => self::imageRequestSummary($request),
            'result_summary' => [],
            'estimated_tenant_cost' => self::points($estimate['tenant_cost_points'] ?? 0),
            'estimated_user_price' => self::points($estimate['user_charge_points'] ?? 0),
            'actual_tenant_cost' => 0,
            'actual_user_price' => 0,
            'idempotency_key' => self::newNo('IK'),
            'create_time' => $now,
            'update_time' => $now,
            'finish_time' => 0,
        ]);

        $consumeNo = self::newNo('C');
        $quantity = max(1, (int)($request['quantity'] ?? 1));
        $tenantCost = self::points($estimate['tenant_cost_points'] ?? 0);
        $userPrice = self::points($estimate['user_charge_points'] ?? 0);
        $consumption = AiConsumptionLog::create([
            'consume_no' => $consumeNo,
            'app_task_id' => (int)$appTask['id'],
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'app_code' => 'aigc_image',
            'action_code' => 'generate',
            'resource_type' => 'model',
            'product_id' => (int)($market['product_id'] ?? 0),
            'sku_id' => (int)($market['sku_id'] ?? 0),
            'model_code' => (string)($selection['channel']['model'] ?? ''),
            'api_code' => '',
            'protocol' => 'image_generate',
            'provider' => (string)($selection['channel']['provider'] ?? ''),
            'upstream_request_id' => '',
            'upstream_task_id' => '',
            'quantity' => $quantity,
            'usage_unit' => (string)($market['usage_unit'] ?? 'image'),
            'usage_snapshot' => ['requested_quantity' => $quantity],
            'price_snapshot' => self::priceSnapshot($market, $selection, $estimate, $quantity),
            'request_summary' => self::imageRequestSummary($request),
            'response_summary' => [],
            'run_status' => 'submitting',
            'billing_status' => 'reserved',
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

        PointService::reserveBusinessAmountsInCurrentTransaction(
            $tenantId,
            $userId,
            $tenantCost,
            $userPrice,
            $consumeNo . '-reserve',
            'AIGC生图预占',
            self::pointExtra($appTask, $consumption, 'reserved')
        );
        self::event((int)$consumption['id'], 'reserve', 'success', [
            'tenant_cost' => $tenantCost,
            'user_price' => $userPrice,
        ]);
        return ['app_task' => $appTask, 'consumption' => $consumption];
    }

    public static function attachImageTask(int $appTaskId, int $imageTaskId): void
    {
        AiAppTask::where('id', $appTaskId)->update([
            'business_id' => $imageTaskId,
            'update_time' => time(),
        ]);
    }

    public static function markImageSubmitted(int $imageTaskId, string $upstreamTaskId, array $summary = []): void
    {
        $context = self::imageContext($imageTaskId, false);
        if (!$context) {
            return;
        }
        /** @var AiConsumptionLog $consumption */
        $consumption = $context['consumption'];
        $consumption->save([
            'upstream_request_id' => $upstreamTaskId,
            'upstream_task_id' => $upstreamTaskId,
            'response_summary' => self::summary($summary),
            'run_status' => $upstreamTaskId === '' ? 'running' : 'submitted',
            'update_time' => time(),
        ]);
        self::event((int)$consumption['id'], 'submit', 'success', $summary);
        if ($upstreamTaskId !== '' && (int)($summary['image_count'] ?? 0) === 0) {
            AiTaskJobService::enqueueQueryResult((int)$consumption['id']);
        }
    }

    public static function recordImageEvent(int $imageTaskId, string $type, string $status, array $summary = []): void
    {
        $context = self::imageContext($imageTaskId, false);
        if (!$context) {
            return;
        }
        self::event((int)$context['consumption']['id'], $type, $status, $summary);
    }

    public static function failImageTask(int $imageTaskId, string $message, string $code = ''): void
    {
        Db::transaction(function () use ($imageTaskId, $message, $code): void {
            self::failImageTaskInCurrentTransaction($imageTaskId, $message, $code);
        });
    }

    private static function failImageTaskInCurrentTransaction(int $imageTaskId, string $message, string $code = ''): void
    {
        $context = self::imageContext($imageTaskId, true);
        if (!$context) {
            return;
        }
        /** @var AiConsumptionLog $consumption */
        $consumption = $context['consumption'];
        /** @var AiAppTask $appTask */
        $appTask = $context['app_task'];
        if (in_array((string)$consumption['billing_status'], ['refunded', 'settled'], true)) {
            return;
        }
        $now = time();
        PointService::releaseReservedBusinessAmountsInCurrentTransaction(
            (int)$consumption['tenant_id'],
            (int)$consumption['user_id'],
            (float)$consumption['reserved_tenant_cost'],
            (float)$consumption['reserved_user_price'],
            (string)$consumption['consume_no'] . '-release',
            'AIGC生图失败退回',
            self::pointExtra($appTask, $consumption, 'refunded')
        );
        $consumption->save([
            'run_status' => 'failed',
            'billing_status' => 'refunded',
            'error_code' => mb_substr($code, 0, 64),
            'error_message' => mb_substr($message, 0, 1000),
            'finish_time' => $now,
            'update_time' => $now,
        ]);
        $appTask->save([
            'status' => $code === 'canceled' ? 'canceled' : self::APP_TASK_FAILED,
            'progress' => 100,
            'result_summary' => ['error' => mb_substr($message, 0, 500)],
            'finish_time' => $now,
            'update_time' => $now,
        ]);
        self::event((int)$consumption['id'], 'refund', 'success', ['reason' => $message]);
    }

    /** @return array{consumption_id: int, actual_tenant_cost: float, actual_user_price: float, status: string}|null */
    public static function settleImageTaskInCurrentTransaction(int $imageTaskId, int $actualQuantity, array $resultSummary = []): ?array
    {
        $context = self::imageContext($imageTaskId, true);
        if (!$context) {
            return null;
        }
        /** @var AiConsumptionLog $consumption */
        $consumption = $context['consumption'];
        /** @var AiAppTask $appTask */
        $appTask = $context['app_task'];
        if ((string)$consumption['billing_status'] === 'settled') {
            return [
                'consumption_id' => (int)$consumption['id'],
                'actual_tenant_cost' => (float)$consumption['actual_tenant_cost'],
                'actual_user_price' => (float)$consumption['actual_user_price'],
                'status' => (string)$appTask['status'],
            ];
        }
        if ((string)$consumption['billing_status'] === 'refunded') {
            throw new RuntimeException('已退款的消耗记录不能结算');
        }
        $requested = max(1, (int)$consumption['quantity']);
        $actualQuantity = max(0, min($requested, $actualQuantity));
        $tenantUnit = (float)$consumption['reserved_tenant_cost'] / $requested;
        $userUnit = (float)$consumption['reserved_user_price'] / $requested;
        $actualTenant = self::points($tenantUnit * $actualQuantity);
        $actualUser = self::points($userUnit * $actualQuantity);
        PointService::settleReservedBusinessAmountsInCurrentTransaction(
            (int)$consumption['tenant_id'],
            (int)$consumption['user_id'],
            (float)$consumption['reserved_tenant_cost'],
            (float)$consumption['reserved_user_price'],
            $actualTenant,
            $actualUser,
            (string)$consumption['consume_no'],
            'AIGC生图结算',
            self::pointExtra($appTask, $consumption, 'settled')
        );
        $now = time();
        $status = $actualQuantity === $requested ? self::APP_TASK_SUCCESS : self::APP_TASK_PARTIAL;
        $consumption->save([
            'run_status' => 'success',
            'billing_status' => 'settled',
            'usage_snapshot' => ['requested_quantity' => $requested, 'actual_quantity' => $actualQuantity],
            'response_summary' => self::summary($resultSummary),
            'actual_tenant_cost' => $actualTenant,
            'actual_user_price' => $actualUser,
            'finish_time' => $now,
            'update_time' => $now,
        ]);
        $appTask->save([
            'status' => $status,
            'progress' => 100,
            'result_summary' => self::summary($resultSummary),
            'actual_tenant_cost' => $actualTenant,
            'actual_user_price' => $actualUser,
            'finish_time' => $now,
            'update_time' => $now,
        ]);
        self::event((int)$consumption['id'], 'settle', 'success', [
            'actual_quantity' => $actualQuantity,
            'tenant_cost' => $actualTenant,
            'user_price' => $actualUser,
        ]);
        return [
            'consumption_id' => (int)$consumption['id'],
            'actual_tenant_cost' => $actualTenant,
            'actual_user_price' => $actualUser,
            'status' => $status,
        ];
    }

    /**
     * Settle an upstream-completed image task after an earlier response parsing
     * defect refunded its reservation. This is intentionally operator-triggered
     * and uses a new source number instead of reusing the refunded reservation.
     *
     * @return array{consumption_id: int, actual_tenant_cost: float, actual_user_price: float, status: string}|null
     */
    public static function settleRecoveredImageTaskInCurrentTransaction(int $imageTaskId, int $actualQuantity, array $resultSummary = []): ?array
    {
        $context = self::imageContext($imageTaskId, true);
        if (!$context) {
            return null;
        }
        /** @var AiConsumptionLog $consumption */
        $consumption = $context['consumption'];
        /** @var AiAppTask $appTask */
        $appTask = $context['app_task'];
        if ((string)$consumption['billing_status'] === 'settled') {
            return [
                'consumption_id' => (int)$consumption['id'],
                'actual_tenant_cost' => (float)$consumption['actual_tenant_cost'],
                'actual_user_price' => (float)$consumption['actual_user_price'],
                'status' => (string)$appTask['status'],
            ];
        }
        if ((string)$consumption['billing_status'] !== 'refunded') {
            return self::settleImageTaskInCurrentTransaction($imageTaskId, $actualQuantity, $resultSummary);
        }

        $requested = max(1, (int)$consumption['quantity']);
        $actualQuantity = max(0, min($requested, $actualQuantity));
        if ($actualQuantity <= 0) {
            throw new RuntimeException('恢复结算必须包含至少一张图片');
        }
        $tenantUnit = (float)$consumption['reserved_tenant_cost'] / $requested;
        $userUnit = (float)$consumption['reserved_user_price'] / $requested;
        $actualTenant = self::points($tenantUnit * $actualQuantity);
        $actualUser = self::points($userUnit * $actualQuantity);
        $recoverySourceSn = (string)$consumption['consume_no'] . '-recovery';
        PointService::consumeBusinessAmountsInCurrentTransaction(
            (int)$consumption['tenant_id'],
            (int)$consumption['user_id'],
            $actualTenant,
            $actualUser,
            $recoverySourceSn,
            'AIGC生图结果补偿结算',
            self::pointExtra($appTask, $consumption, 'recovered_settled')
        );

        $now = time();
        $status = $actualQuantity === $requested ? self::APP_TASK_SUCCESS : self::APP_TASK_PARTIAL;
        $consumption->save([
            'run_status' => 'success',
            'billing_status' => 'settled',
            'usage_snapshot' => ['requested_quantity' => $requested, 'actual_quantity' => $actualQuantity],
            'response_summary' => self::summary($resultSummary),
            'actual_tenant_cost' => $actualTenant,
            'actual_user_price' => $actualUser,
            'tenant_point_sn' => $recoverySourceSn,
            'user_point_sn' => $recoverySourceSn,
            'error_code' => '',
            'error_message' => '',
            'finish_time' => $now,
            'update_time' => $now,
        ]);
        $appTask->save([
            'status' => $status,
            'progress' => 100,
            'result_summary' => self::summary($resultSummary),
            'actual_tenant_cost' => $actualTenant,
            'actual_user_price' => $actualUser,
            'finish_time' => $now,
            'update_time' => $now,
        ]);
        self::event((int)$consumption['id'], 'recover_settle', 'success', [
            'actual_quantity' => $actualQuantity,
            'tenant_cost' => $actualTenant,
            'user_price' => $actualUser,
        ]);
        return [
            'consumption_id' => (int)$consumption['id'],
            'actual_tenant_cost' => $actualTenant,
            'actual_user_price' => $actualUser,
            'status' => $status,
        ];
    }

    public static function requestRefresh(int $appTaskId, int $tenantId): void
    {
        AiConsumptionLog::where(['app_task_id' => $appTaskId, 'tenant_id' => $tenantId])
            ->whereIn('run_status', ['submitting', 'submitted', 'running'])
            ->update(['refresh_requested_at' => time(), 'update_time' => time()]);
    }

    public static function appTaskLists(array $params, int $tenantId = 0): array
    {
        $query = AiAppTask::alias('t')->leftJoin('user u', 'u.id=t.user_id AND u.tenant_id=t.tenant_id')
            ->leftJoin('tenant te', 'te.id=t.tenant_id')
            ->field('t.*,u.nickname user_nickname,u.account user_account,u.mobile user_mobile,te.name tenant_name,te.sn tenant_sn');
        if ($tenantId > 0) {
            $query->where('t.tenant_id', $tenantId);
        } elseif ($filterTenantId = (int)($params['tenant_id'] ?? 0)) {
            $query->where('t.tenant_id', $filterTenantId);
        }
        if ($appCode = trim((string)($params['app_code'] ?? ''))) {
            $query->where('t.app_code', $appCode);
        }
        if ($userId = (int)($params['user_id'] ?? 0)) {
            $query->where('t.user_id', $userId);
        }
        if ($status = trim((string)($params['status'] ?? ''))) {
            $query->where('t.status', $status);
        }
        if ($keyword = trim((string)($params['keyword'] ?? ''))) {
            $query->where(function ($query) use ($keyword) {
                $query->whereLike('t.task_no|t.app_code|t.action_code|u.nickname|u.account|u.mobile', '%' . $keyword . '%');
            });
        }
        if ($start = strtotime((string)($params['create_time_start'] ?? ''))) {
            $query->where('t.create_time', '>=', $start);
        }
        if ($end = strtotime((string)($params['create_time_end'] ?? ''))) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$params['create_time_end'])) {
                $end += 86399;
            }
            $query->where('t.create_time', '<=', $end);
        }
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 15)));
        $count = (int)(clone $query)->count();
        $rows = $query->order(['t.create_time' => 'desc', 't.id' => 'desc'])
            ->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();
        foreach ($rows as &$row) {
            $row = self::formatAppTask($row);
        }
        unset($row);
        return compact('rows', 'count', 'pageNo', 'pageSize');
    }

    public static function appTaskDetail(int $id, int $tenantId = 0): array
    {
        $query = AiAppTask::where('id', $id);
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            return [];
        }
        $data = self::formatAppTask($task->toArray());
        $data['consumptions'] = AiConsumptionLog::where('app_task_id', (int)$task['id'])
            ->order(['id' => 'asc'])->select()->toArray();
        foreach ($data['consumptions'] as &$item) {
            $item = self::formatConsumption($item);
        }
        unset($item);
        $firstConsumption = $data['consumptions'][0] ?? [];
        $data['source_app_name'] = self::appDisplayName((string)$data['app_code']);
        $data['base_app_name'] = $data['source_app_name'];
        $data['prompt'] = '';
        $data['error'] = (string)($firstConsumption['error_message'] ?? '');
        $data['provider_task_id'] = (string)($firstConsumption['upstream_task_id'] ?? $firstConsumption['upstream_request_id'] ?? '');
        $data['request_params'] = (array)($data['request_summary'] ?? []);
        $data['response_info'] = [
            'status' => (string)$data['status'],
            'provider_task_id' => $data['provider_task_id'],
            'consumption_count' => count($data['consumptions']),
        ];
        $data['media_results'] = [];
        $data['result_count'] = (int)(((array)($data['result_summary'] ?? []))['result_count'] ?? 0);
        $data['user_info'] = [
            'user_id' => (int)$data['user_id'],
            'nickname' => '',
            'account' => '',
            'mobile' => '',
            'display_name' => '用户#' . (int)$data['user_id'],
        ];
        $data['upstream_tasks'] = array_map(static fn (array $item): array => [
            'relation' => '消耗调用',
            'app_code' => (string)$item['app_code'],
            'app_name' => self::appDisplayName((string)$item['app_code']),
            'task_id' => (int)$item['id'],
            'task_sn' => (string)$item['consume_no'],
        ], $data['consumptions']);
        return $data;
    }

    public static function consumptionLists(array $params, int $tenantId = 0): array
    {
        $query = AiConsumptionLog::alias('c')
            ->leftJoin('ai_app_task t', 't.id=c.app_task_id')
            ->leftJoin('user u', 'u.id=c.user_id AND u.tenant_id=c.tenant_id')
            ->leftJoin('tenant te', 'te.id=c.tenant_id')
            ->field('c.*,t.task_no,t.status app_task_status,t.action_code,u.nickname user_nickname,u.account user_account,u.mobile user_mobile,te.name tenant_name,te.sn tenant_sn');
        if ($tenantId > 0) {
            $query->where('c.tenant_id', $tenantId);
        } elseif ($filterTenantId = (int)($params['tenant_id'] ?? 0)) {
            $query->where('c.tenant_id', $filterTenantId);
        }
        foreach (['app_code' => 'c.app_code', 'run_status' => 'c.run_status', 'billing_status' => 'c.billing_status', 'model_code' => 'c.model_code', 'api_code' => 'c.api_code'] as $key => $field) {
            if ($value = trim((string)($params[$key] ?? ''))) {
                $query->where($field, $value);
            }
        }
        if ($productId = (int)($params['product_id'] ?? 0)) {
            $query->where('c.product_id', $productId);
        }
        if ($skuId = (int)($params['sku_id'] ?? 0)) {
            $query->where('c.sku_id', $skuId);
        }
        if ($userId = (int)($params['user_id'] ?? 0)) {
            $query->where('c.user_id', $userId);
        }
        if ($keyword = trim((string)($params['keyword'] ?? ''))) {
            $query->where(function ($query) use ($keyword) {
                $query->whereLike('c.consume_no|c.upstream_request_id|c.upstream_task_id|t.task_no|u.nickname|u.account|u.mobile', '%' . $keyword . '%');
            });
        }
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 15)));
        $count = (int)(clone $query)->count();
        $rows = $query->order(['c.create_time' => 'desc', 'c.id' => 'desc'])
            ->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();
        foreach ($rows as &$row) {
            $row = self::formatConsumption($row);
        }
        unset($row);
        return compact('rows', 'count', 'pageNo', 'pageSize');
    }

    public static function consumptionDetail(int $id, int $tenantId = 0, bool $includePayload = false): array
    {
        $query = AiConsumptionLog::where('id', $id);
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            return [];
        }
        $data = self::formatConsumption($row->toArray());
        $events = AiConsumptionEvent::where('consumption_id', $id)->order('id', 'asc')->select()->toArray();
        foreach ($events as &$event) {
            $event['create_time_text'] = self::timeText($event['create_time'] ?? 0);
            if (!$includePayload) {
                unset($event['payload_ciphertext']);
            }
        }
        unset($event);
        $data['events'] = $events;
        return $data;
    }

    public static function purgeExpiredPayloads(int $days = 30): int
    {
        return AiConsumptionEvent::where('create_time', '<', time() - max(1, $days) * 86400)
            ->where('payload_ciphertext', '<>', '')
            ->update(['payload_ciphertext' => '']);
    }

    private static function imageContext(int $imageTaskId, bool $lock): ?array
    {
        $taskQuery = AigcImageTask::where('id', $imageTaskId);
        if ($lock) {
            $taskQuery->lock(true);
        }
        $imageTask = $taskQuery->findOrEmpty();
        if ($imageTask->isEmpty() || (int)$imageTask['app_task_id'] <= 0) {
            return null;
        }
        $appQuery = AiAppTask::where('id', (int)$imageTask['app_task_id']);
        $logQuery = AiConsumptionLog::where('app_task_id', (int)$imageTask['app_task_id'])->where('action_code', 'generate');
        if ($lock) {
            $appQuery->lock(true);
            $logQuery->lock(true);
        }
        $appTask = $appQuery->findOrEmpty();
        $consumption = $logQuery->findOrEmpty();
        if ($appTask->isEmpty() || $consumption->isEmpty()) {
            return null;
        }
        return [
            'image_task' => $imageTask,
            'app_task' => $appTask,
            'consumption' => $consumption,
        ];
    }

    private static function resolveImageMarket(int $tenantId, array $selection): array
    {
        $selectedSkuId = (int)($selection['spec']['market_sku_id'] ?? 0);
        if ($selectedSkuId > 0) {
            $sku = PowerMarketSku::where(['id' => $selectedSkuId, 'status' => 1, 'sale_status' => 1])->findOrEmpty();
            if ($sku->isEmpty()) {
                return [];
            }
            $product = PowerMarketProduct::where([
                'id' => (int)$sku['product_id'],
                'resource_type' => 'model',
                'model_type' => 'image',
                'status' => 1,
            ])->findOrEmpty();
            if ($product->isEmpty()) {
                return [];
            }
            $tenantPrice = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => $selectedSkuId])->findOrEmpty();
            if (!$tenantPrice->isEmpty() && (int)$tenantPrice['sale_status'] !== 1) {
                return [];
            }
            return [
                'product_id' => (int)$product['id'],
                'sku_id' => $selectedSkuId,
                'sku_key' => (string)$sku['sku_key'],
                'usage_unit' => (string)$sku['usage_unit'],
                'upstream_price' => (float)$sku['upstream_price'],
                'platform_price' => (float)$sku['sale_points'],
                'tenant_price' => $tenantPrice->isEmpty() ? (float)$sku['sale_points'] : (float)$tenantPrice['sale_points'],
            ];
        }
        $model = trim((string)($selection['channel']['model'] ?? ''));
        if ($model === '') {
            return [];
        }
        $product = PowerMarketProduct::where(['resource_type' => 'model', 'model_type' => 'image', 'status' => 1])
            ->where('upstream_model_code', $model)->order('id', 'desc')->findOrEmpty();
        if ($product->isEmpty()) {
            return [];
        }
        $skus = PowerMarketSku::where(['product_id' => (int)$product['id'], 'status' => 1, 'sale_status' => 1])->select()->toArray();
        foreach ($skus as $sku) {
            if (!self::matchesSku($sku, $selection['spec'] ?? [])) {
                continue;
            }
            $tenantPrice = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => (int)$sku['id']])->findOrEmpty();
            if (!$tenantPrice->isEmpty() && (int)$tenantPrice['sale_status'] !== 1) {
                continue;
            }
            return [
                'product_id' => (int)$product['id'],
                'sku_id' => (int)$sku['id'],
                'sku_key' => (string)$sku['sku_key'],
                'usage_unit' => (string)$sku['usage_unit'],
                'upstream_price' => (float)$sku['upstream_price'],
                'platform_price' => (float)$sku['sale_points'],
                'tenant_price' => $tenantPrice->isEmpty() ? (float)$sku['sale_points'] : (float)$tenantPrice['sale_points'],
            ];
        }
        return [];
    }

    private static function matchesSku(array $sku, array $spec): bool
    {
        $locked = is_array($sku['locked_params'] ?? null) ? $sku['locked_params'] : [];
        $values = [
            'quality' => (string)($spec['quality'] ?? ''),
            'ratio' => (string)($spec['ratio'] ?? ''),
            'width' => (string)($spec['width'] ?? ''),
            'height' => (string)($spec['height'] ?? ''),
        ];
        foreach ($locked as $key => $value) {
            if (!array_key_exists((string)$key, $values) || $values[(string)$key] === '') {
                continue;
            }
            if ((string)$value !== $values[(string)$key]) {
                return false;
            }
        }
        return true;
    }

    private static function priceSnapshot(array $market, array $selection, array $estimate, int $quantity): array
    {
        return [
            'pricing_source' => empty($market) ? 'image_channel' : 'power_market',
            'product_id' => (int)($market['product_id'] ?? 0),
            'sku_id' => (int)($market['sku_id'] ?? 0),
            'sku_key' => (string)($market['sku_key'] ?? ''),
            'upstream_unit_cost' => self::points($market['upstream_price'] ?? $estimate['platform_unit_cost'] ?? 0),
            'platform_unit_cost' => self::points($estimate['platform_unit_cost'] ?? 0),
            'tenant_unit_price' => self::points($estimate['tenant_unit_price'] ?? 0),
            'quantity' => $quantity,
            'channel' => (string)($selection['channel']['code'] ?? ''),
            'model' => (string)($selection['channel']['model'] ?? ''),
            'quality' => (string)($selection['spec']['quality'] ?? ''),
            'ratio' => (string)($selection['spec']['ratio'] ?? ''),
        ];
    }

    private static function imageRequestSummary(array $request): array
    {
        return [
            'prompt_length' => mb_strlen((string)($request['prompt'] ?? '')),
            'negative_prompt_length' => mb_strlen((string)($request['negative_prompt'] ?? '')),
            'channel' => (string)($request['channel'] ?? ''),
            'quality' => (string)($request['quality'] ?? ''),
            'ratio' => (string)($request['ratio'] ?? ''),
            'quantity' => max(1, (int)($request['quantity'] ?? 1)),
            'reference_image_count' => count((array)($request['reference_images'] ?? [])),
        ];
    }

    private static function pointExtra(AiAppTask $appTask, AiConsumptionLog $consumption, string $stage): array
    {
        return [
            'app_code' => (string)$appTask['app_code'],
            'task_id' => (int)$appTask['id'],
            'app_task_no' => (string)$appTask['task_no'],
            'consumption_id' => (int)$consumption['id'],
            'consume_no' => (string)$consumption['consume_no'],
            'billing_stage' => $stage,
        ];
    }

    public static function appDisplayName(string $appCode): string
    {
        return [
            'aigc_image' => 'AIGC生图', 'aigc_video' => 'AIGC视频', 'aigc_llm' => 'AIGC文本',
            'aigc_short_drama' => 'AI短剧', 'aigc_digital_human' => '数字人视频', 'image_human' => '全驱数字人',
            'smart_clip' => 'AI视频剪辑', 'aigc_product_image' => 'AI商品图', 'aigc_action_transfer' => '动作迁移',
            'aigc_person_replacement' => '动作替换',
        ][$appCode] ?? ($appCode !== '' ? $appCode : '--');
    }

    private static function formatAppTask(array $row): array
    {
        $row['record_key'] = 'app_task:' . (int)$row['id'];
        $row['base_app_code'] = 'ai_app_task';
        $row['task_sn'] = (string)$row['task_no'];
        $row['source_app_code'] = (string)$row['app_code'];
        $row['app_name'] = self::appDisplayName((string)$row['app_code']);
        $row['source_app_name'] = $row['app_name'];
        $row['source_task_id'] = (int)$row['business_id'];
        $row['user_charge_points'] = (float)($row['actual_user_price'] ?: $row['estimated_user_price']);
        $row['tenant_cost_points'] = (float)($row['actual_tenant_cost'] ?: $row['estimated_tenant_cost']);
        $row['create_time_text'] = self::timeText($row['create_time'] ?? 0);
        $row['finish_time_text'] = self::timeText($row['finish_time'] ?? 0);
        return $row;
    }

    private static function formatConsumption(array $row): array
    {
        $row['create_time_text'] = self::timeText($row['create_time'] ?? 0);
        $row['finish_time_text'] = self::timeText($row['finish_time'] ?? 0);
        $row['record_key'] = 'consumption:' . (int)$row['id'];
        $row['app_name'] = self::appDisplayName((string)($row['app_code'] ?? ''));
        return $row;
    }

    private static function timeText(mixed $value): string
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return '';
        }
        if (is_string($value)) {
            $text = trim($value);
            if ($text === '') {
                return '';
            }
            if (!ctype_digit($text)) {
                $timestamp = strtotime($text);
                return $timestamp ? date('Y-m-d H:i:s', $timestamp) : $text;
            }
            $value = (int)$text;
        }
        $timestamp = (int)$value;
        return $timestamp > 100000000 ? date('Y-m-d H:i:s', $timestamp) : '';
    }

    private static function event(int $consumptionId, string $type, string $status, array $payload = []): void
    {
        AiConsumptionEvent::create([
            'consumption_id' => $consumptionId,
            'event_type' => $type,
            'event_status' => $status,
            'attempt_no' => 1,
            'payload_summary' => self::summary($payload),
            'payload_ciphertext' => self::ciphertext($payload),
            'http_status' => 0,
            'elapsed_ms' => 0,
            'create_time' => time(),
        ]);
    }

    private static function ciphertext(array $payload): string
    {
        $key = trim((string)(getenv('AI_CONSUMPTION_LOG_CIPHER_KEY') ?: ''));
        if ($key === '' || !function_exists('openssl_encrypt')) {
            return '';
        }
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt(
            json_encode(self::withoutSecrets($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'AES-256-CBC',
            hash('sha256', $key, true),
            OPENSSL_RAW_DATA,
            $iv
        );
        return $encrypted === false ? '' : base64_encode($iv . $encrypted);
    }

    private static function summary(array $value): array
    {
        return self::withoutSecrets($value);
    }

    private static function withoutSecrets(array $value): array
    {
        $hidden = ['authorization', 'api_key', 'apikey', 'secret', 'signature', 'cookie', 'token', 'password', 'prompt', 'negative_prompt', 'reference_images'];
        $result = [];
        foreach ($value as $key => $item) {
            $lower = strtolower((string)$key);
            if (in_array($lower, $hidden, true)) {
                continue;
            }
            if (is_array($item)) {
                $result[$key] = self::withoutSecrets($item);
            } elseif (is_scalar($item) || $item === null) {
                $result[$key] = is_string($item) ? mb_substr($item, 0, 500) : $item;
            }
        }
        return $result;
    }

    private static function newNo(string $prefix): string
    {
        return $prefix . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
    }

    private static function points($value): float
    {
        return round(max(0, (float)$value), 6);
    }
}
