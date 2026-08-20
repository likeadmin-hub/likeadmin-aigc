<?php

namespace app\common\service\ai;

use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionEvent;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\app\App;
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\model\power\TenantPowerMarketProduct;
use app\common\model\power\TenantPowerMarketSkuPrice;
use app\common\service\PointUnitService;
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

    public static function requestRefresh(int $appTaskId, int $tenantId): void
    {
        AiConsumptionLog::where(['app_task_id' => $appTaskId, 'tenant_id' => $tenantId])
            ->whereIn('run_status', ['submitting', 'submitted', 'running'])
            ->update(['refresh_requested_at' => time(), 'update_time' => time()]);
    }

    public static function appTaskLists(array $params, int $tenantId = 0): array
    {
        $query = AiAppTask::alias('t')->leftJoin('user u', 'u.id=t.user_id AND u.tenant_id=t.tenant_id')
            ->field('t.*,u.nickname user_nickname,u.account user_account,u.mobile user_mobile');
        if ($tenantId > 0) {
            $query->where('t.tenant_id', $tenantId);
        } elseif ($filterTenantId = (int)($params['tenant_id'] ?? 0)) {
            $query->where('t.tenant_id', $filterTenantId);
        }
        if (array_key_exists('app_codes', $params)) {
            $appCodes = array_values(array_unique(array_filter(array_map(
                static fn($value): string => trim((string)$value),
                (array)$params['app_codes']
            ))));
            if ($appCodes === []) {
                $query->whereRaw('1=0');
            } else {
                $query->whereIn('t.app_code', $appCodes);
            }
        } elseif ($appCode = trim((string)($params['app_code'] ?? ''))) {
            $query->where('t.app_code', $appCode);
        }
        self::applyAppTaskTypeFilter($query, (string)($params['task_type'] ?? ''));
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
        self::applyConsumptionFilters($query, $params, $tenantId);
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
        self::attachConsumptionContext($rows, $params, $tenantId);
        return compact('rows', 'count', 'pageNo', 'pageSize');
    }

    private static function applyAppTaskTypeFilter($query, string $taskType): void
    {
        $taskType = match (strtolower(trim($taskType))) {
            'image', 'image_generate', '生图', '图片' => 'image',
            'video', 'video_generate', '视频' => 'video',
            'text', 'text_generate', '文本' => 'text',
            'short_drama', 'short_drama_generate', '短剧' => 'short_drama',
            default => '',
        };
        if ($taskType === '') {
            return;
        }

        $appCodes = match ($taskType) {
            'image' => [
                'aigc_image', 'aigc_product_image', 'aigc_style_transfer', 'aigc_photo_restore',
                'aigc_model_wear', 'aigc_background_removal', 'aigc_image_translate',
                'aigc_one_click_cleanup', 'aigc_product_suite', 'aigc_product_multi_angle',
                'aigc_fashion_lookbook', 'aigc_outpaint', 'aigc_local_redraw', 'aigc_fitting',
                'aigc_hairstyle',
            ],
            'video' => [
                'aigc_video', 'aigc_digital_human', 'image_human', 'smart_clip',
                'aigc_product_promo_video', 'aigc_action_transfer', 'aigc_person_replacement',
            ],
            'text' => ['aigc_llm'],
            'short_drama' => ['aigc_short_drama'],
        };
        if ($taskType === 'short_drama') {
            $query->whereIn('t.app_code', $appCodes);
            return;
        }

        $protocolQuery = Db::name('ai_consumption_log')->alias('tc')
            ->fieldRaw('1')
            ->whereColumn('tc.app_task_id', '=', 't.id')
            ->where('t.app_code', '<>', 'aigc_short_drama')
            ->whereLike('tc.protocol', '%' . $taskType . '%');
        $query->where(function ($subQuery) use ($appCodes, $protocolQuery) {
            $subQuery->whereIn('t.app_code', $appCodes)
                ->whereExists($protocolQuery, 'OR');
        });
    }

    private static function applyConsumptionFilters($query, array $params, int $tenantId): void
    {
        $model = trim((string)($params['model'] ?? ''));
        $channel = trim((string)($params['channel'] ?? ''));
        if ($model === '' && $channel === '') {
            return;
        }

        $consumptionQuery = Db::name('ai_consumption_log')->alias('c')
            ->fieldRaw('1')
            ->whereColumn('c.app_task_id', '=', 't.id');
        if ($tenantId > 0) {
            $consumptionQuery->where('c.tenant_id', $tenantId);
        }
        if ($model !== '') {
            $productIds = self::matchingConsumptionProductIds($model, $tenantId);
            $consumptionQuery->where(function ($subQuery) use ($model, $productIds) {
                $subQuery->whereLike('c.model_code|c.api_code|c.price_snapshot', '%' . $model . '%');
                if ($productIds !== []) {
                    $subQuery->whereIn('c.product_id', $productIds, 'OR');
                }
            });
        }
        if ($channel !== '') {
            $consumptionQuery->whereLike(
                'c.provider|c.api_code|c.protocol|c.price_snapshot',
                '%' . $channel . '%'
            );
        }
        $query->whereExists($consumptionQuery);
    }

    /** @return array<int, int> */
    private static function matchingConsumptionProductIds(string $keyword, int $tenantId): array
    {
        try {
            $ids = PowerMarketProduct::whereLike(
                'name|product_code|upstream_model_code|upstream_api_code',
                '%' . $keyword . '%'
            )->column('id');
            if ($tenantId > 0) {
                $ids = array_merge($ids, TenantPowerMarketProduct::where('tenant_id', $tenantId)
                    ->whereLike('name', '%' . $keyword . '%')->column('product_id'));
            }
            return array_values(array_unique(array_filter(array_map('intval', $ids))));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function attachConsumptionContext(array &$rows, array $params, int $tenantId): void
    {
        $taskIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            $rows
        ))));
        if ($taskIds === []) {
            return;
        }

        $consumptions = AiConsumptionLog::whereIn('app_task_id', $taskIds)
            ->field('app_task_id,product_id,model_code,api_code,provider,protocol,resource_type,price_snapshot')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $model = trim((string)($params['model'] ?? ''));
        $channel = trim((string)($params['channel'] ?? ''));
        $matchingProductIds = $model === '' ? [] : self::matchingConsumptionProductIds($model, $tenantId);
        $byTask = [];
        $matchedTasks = [];
        foreach ($consumptions as $consumption) {
            $taskId = (int)($consumption['app_task_id'] ?? 0);
            if ($taskId > 0 && !isset($byTask[$taskId])) {
                $byTask[$taskId] = $consumption;
            }
            if ($taskId > 0
                && !isset($matchedTasks[$taskId])
                && self::consumptionMatchesDisplayFilters($consumption, $model, $channel, $matchingProductIds)) {
                $byTask[$taskId] = $consumption;
                $matchedTasks[$taskId] = true;
            }
        }
        foreach ($rows as &$row) {
            $context = $byTask[(int)($row['id'] ?? 0)] ?? [];
            $row['market_product_id'] = (int)($context['product_id'] ?? 0);
            $row['model_code'] = (string)($context['model_code'] ?? '');
            $row['model'] = $row['model_code'];
            $row['api_code'] = (string)($context['api_code'] ?? '');
            $row['provider'] = (string)($context['provider'] ?? '');
            $row['channel'] = $row['provider'] !== '' ? $row['provider'] : $row['api_code'];
            $row['protocol'] = (string)($context['protocol'] ?? '');
            $row['resource_type'] = (string)($context['resource_type'] ?? '');
        }
        unset($row);
    }

    /** @param array<int, int> $matchingProductIds */
    private static function consumptionMatchesDisplayFilters(
        array $consumption,
        string $model,
        string $channel,
        array $matchingProductIds
    ): bool {
        if ($model !== '') {
            $modelText = implode(' ', [
                (string)($consumption['model_code'] ?? ''),
                (string)($consumption['api_code'] ?? ''),
                self::filterText($consumption['price_snapshot'] ?? ''),
            ]);
            if (stripos($modelText, $model) === false
                && !in_array((int)($consumption['product_id'] ?? 0), $matchingProductIds, true)) {
                return false;
            }
        }
        if ($channel !== '') {
            $channelText = implode(' ', [
                (string)($consumption['provider'] ?? ''),
                (string)($consumption['api_code'] ?? ''),
                (string)($consumption['protocol'] ?? ''),
                self::filterText($consumption['price_snapshot'] ?? ''),
            ]);
            if (stripos($channelText, $channel) === false) {
                return false;
            }
        }
        return true;
    }

    private static function filterText($value): string
    {
        if (is_array($value)) {
            return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return is_scalar($value) ? (string)$value : '';
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
        $data['consumptions'] = self::formatConsumptions($data['consumptions']);
        $firstConsumption = $data['consumptions'][0] ?? [];
        $data['source_app_name'] = (string)$data['app_code'];
        $data['base_app_name'] = '统一应用任务';
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
            'app_name' => (string)$item['app_code'],
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
            ->field('c.*,t.task_no,t.status app_task_status,t.action_code,u.nickname user_nickname,u.account user_account,u.mobile user_mobile');
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
        $rows = self::formatConsumptions($rows);
        return compact('rows', 'count', 'pageNo', 'pageSize');
    }

    public static function consumptionDetail(int $id, int $tenantId = 0, bool $includePayload = false): array
    {
        $query = AiConsumptionLog::alias('c')
            ->leftJoin('ai_app_task t', 't.id=c.app_task_id')
            ->leftJoin('user u', 'u.id=c.user_id AND u.tenant_id=c.tenant_id')
            ->field('c.*,t.task_no,t.status app_task_status,t.action_code,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
            ->where('c.id', $id);
        if ($tenantId > 0) {
            $query->where('c.tenant_id', $tenantId);
        }
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            return [];
        }
        $data = self::formatConsumptions([$row->toArray()])[0] ?? [];
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
        $model = trim((string)($selection['channel']['model'] ?? ''));
        if ($model === '') {
            return [];
        }
        $skuId = (int)($selection['market_sku_id'] ?? $selection['sku_id'] ?? 0);
        if ($skuId > 0) {
            $sku = PowerMarketSku::where(['id' => $skuId, 'status' => 1, 'sale_status' => 1])->findOrEmpty();
            if ($sku->isEmpty()) {
                return [];
            }
            $product = PowerMarketProduct::where(['id' => (int)$sku['product_id'], 'resource_type' => 'model', 'model_type' => 'image', 'status' => 1])
                ->where('upstream_model_code', $model)
                ->findOrEmpty();
            if ($product->isEmpty()) {
                return [];
            }
            if (!self::matchesSku($sku->toArray(), $selection['spec'] ?? [])) {
                return [];
            }
            $tenantPrice = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => $skuId])->findOrEmpty();
            if (!$tenantPrice->isEmpty() && (int)$tenantPrice['sale_status'] !== 1) {
                return [];
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

    private static function formatAppTask(array $row): array
    {
        $row['record_key'] = 'app_task:' . (int)$row['id'];
        $row['base_app_code'] = 'ai_app_task';
        $row['task_sn'] = (string)$row['task_no'];
        $row['source_app_code'] = (string)$row['app_code'];
        $row['source_task_id'] = (int)$row['business_id'];
        $row['user_charge_points'] = (float)($row['actual_user_price'] ?: $row['estimated_user_price']);
        $row['tenant_cost_points'] = (float)($row['actual_tenant_cost'] ?: $row['estimated_tenant_cost']);
        $row['create_time_text'] = self::timeText($row['create_time'] ?? 0);
        $row['finish_time_text'] = self::timeText($row['finish_time'] ?? 0);
        return $row;
    }

    private static function formatConsumptions(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $skuIds = [];
        $productIds = [];
        $appCodes = [];
        foreach ($rows as $row) {
            $snapshot = self::arrayValue($row['price_snapshot'] ?? []);
            $skuIds[] = (int)($row['sku_id'] ?? 0);
            $productIds[] = (int)($row['product_id'] ?? $snapshot['product_id'] ?? 0);
            $appCodes[] = trim((string)($row['app_code'] ?? ''));
            foreach (['billing_sku', 'input', 'output'] as $key) {
                if (is_array($snapshot[$key] ?? null)) {
                    $skuIds[] = (int)($snapshot[$key]['sku_id'] ?? 0);
                }
            }
        }

        $skuIds = array_values(array_unique(array_filter($skuIds)));
        $productIds = array_values(array_unique(array_filter($productIds)));
        $appCodes = array_values(array_unique(array_filter($appCodes)));
        $skuMap = [];
        if ($skuIds !== []) {
            foreach (PowerMarketSku::whereIn('id', $skuIds)
                         ->field('id,product_id,sku_key,title,usage_unit,usage_unit_size')
                         ->select()->toArray() as $sku) {
                $skuMap[(int)$sku['id']] = $sku;
            }
        }
        $productMap = $productIds === []
            ? []
            : PowerMarketProduct::whereIn('id', $productIds)->column('name', 'id');
        $appNames = $appCodes === [] ? [] : App::whereIn('code', $appCodes)->column('name', 'code');
        $pointUnit = PointUnitService::unit();

        foreach ($rows as &$row) {
            $row = self::formatConsumption($row, $skuMap, $productMap, $appNames, $pointUnit);
        }
        unset($row);
        return $rows;
    }

    private static function formatConsumption(
        array $row,
        array $skuMap = [],
        array $productMap = [],
        array $appNames = [],
        string $pointUnit = ''
    ): array {
        $pointUnit = $pointUnit !== '' ? $pointUnit : PointUnitService::unit();
        $snapshot = self::arrayValue($row['price_snapshot'] ?? []);
        $usage = self::arrayValue($row['usage_snapshot'] ?? []);
        $billingStatus = (string)($row['billing_status'] ?? 'none');
        $productId = (int)($row['product_id'] ?? $snapshot['product_id'] ?? 0);
        $appCode = trim((string)($row['app_code'] ?? ''));
        $userCharge = self::chargeAmount($row, 'user', $billingStatus);
        $tenantCost = self::chargeAmount($row, 'tenant', $billingStatus);

        $row['record_key'] = 'consumption:' . (int)($row['id'] ?? 0);
        $row['app_name'] = (string)($row['app_name'] ?? $appNames[$appCode] ?? $appCode);
        $row['product_name'] = (string)($row['product_name'] ?? $productMap[$productId] ?? '');
        $row['sku_display'] = self::skuDisplay($row, $snapshot, $skuMap);
        $row['usage_display'] = self::usageDisplay($row, $snapshot, $usage);
        $row['run_status_text'] = self::runStatusText((string)($row['run_status'] ?? ''));
        $row['billing_status_text'] = self::billingStatusText($billingStatus);
        $row['user_charge_points'] = $userCharge;
        $row['tenant_cost_points'] = $tenantCost;
        $row['user_charge_text'] = self::chargeText($userCharge, $row, 'user', $billingStatus, $pointUnit);
        $row['tenant_cost_text'] = self::chargeText($tenantCost, $row, 'tenant', $billingStatus, $pointUnit);
        $row['create_time_text'] = self::timeText($row['create_time'] ?? 0);
        $row['finish_time_text'] = self::timeText($row['finish_time'] ?? 0);
        return $row;
    }

    private static function skuDisplay(array $row, array $snapshot, array $skuMap): string
    {
        if (is_array($snapshot['input'] ?? null) || is_array($snapshot['output'] ?? null)) {
            $parts = [];
            foreach (['input' => '输入', 'output' => '输出'] as $key => $prefix) {
                if (!is_array($snapshot[$key] ?? null)) {
                    continue;
                }
                $name = self::skuName($snapshot[$key], $skuMap);
                if ($name !== '') {
                    $parts[] = str_starts_with($name, $prefix) ? $name : $prefix . ' ' . $name;
                }
            }
            if ($parts !== []) {
                return implode(' / ', array_values(array_unique($parts)));
            }
        }

        foreach (['billing_sku'] as $key) {
            if (is_array($snapshot[$key] ?? null)) {
                $name = self::skuName($snapshot[$key], $skuMap);
                if ($name !== '') {
                    return $name;
                }
            }
        }
        $name = self::skuName([
            'sku_id' => (int)($row['sku_id'] ?? $snapshot['sku_id'] ?? 0),
            'sku_key' => (string)($snapshot['sku_key'] ?? ''),
            'title' => (string)($snapshot['sku_title'] ?? ''),
        ], $skuMap);
        return $name !== '' ? $name : '未记录规格';
    }

    private static function skuName(array $snapshot, array $skuMap): string
    {
        $skuId = (int)($snapshot['sku_id'] ?? 0);
        $sku = $skuMap[$skuId] ?? [];
        foreach ([$snapshot['title'] ?? '', $snapshot['sku_title'] ?? '', $sku['title'] ?? '', $snapshot['sku_key'] ?? '', $sku['sku_key'] ?? ''] as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private static function usageDisplay(array $row, array $snapshot, array $usage): string
    {
        $unit = strtolower(trim((string)($row['usage_unit'] ?? $snapshot['usage_unit'] ?? '')));
        if (str_contains($unit, 'token')) {
            $input = (float)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
            $output = (float)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
            if ($input > 0 || $output > 0) {
                return '输入 ' . self::quantityText($input) . ' / 输出 ' . self::quantityText($output) . ' Token';
            }
        }
        $quantity = self::firstUsageQuantity($row, $usage);
        return self::quantityText($quantity) . ' ' . self::usageUnitText($unit);
    }

    private static function firstUsageQuantity(array $row, array $usage): float
    {
        foreach (['settled_quantity', 'actual_quantity', 'actual_token_usage', 'total_tokens', 'image_count', 'video_count', 'audio_count'] as $key) {
            if (isset($usage[$key]) && is_numeric($usage[$key]) && (float)$usage[$key] > 0) {
                return (float)$usage[$key];
            }
        }
        return max(0, (float)($row['quantity'] ?? 0));
    }

    private static function usageUnitText(string $unit): string
    {
        return match ($unit) {
            'per_call', 'call', 'calls', 'request', 'requests' => '次',
            'image', 'images', 'per_image' => '张',
            'video', 'videos', 'per_video' => '个视频',
            'audio', 'audios', 'per_audio' => '段音频',
            'output_second', 'second', 'seconds', 'per_second', 'video_second', 'audio_second' => '秒',
            'minute', 'minutes', 'per_minute' => '分钟',
            'character', 'characters' => '字符',
            'token', 'tokens', 'input_token', 'output_token', 'total_token' => 'Token',
            default => $unit !== '' ? $unit : '单位',
        };
    }

    private static function quantityText(float $quantity): string
    {
        $text = number_format(max(0, $quantity), 6, '.', '');
        return rtrim(rtrim($text, '0'), '.') ?: '0';
    }

    private static function chargeAmount(array $row, string $side, string $billingStatus): float
    {
        $actualKey = $side === 'user' ? 'actual_user_price' : 'actual_tenant_cost';
        $reservedKey = $side === 'user' ? 'reserved_user_price' : 'reserved_tenant_cost';
        $actual = self::points($row[$actualKey] ?? 0);
        $reserved = self::points($row[$reservedKey] ?? 0);
        return match ($billingStatus) {
            'refunded', 'none' => 0.0,
            'reserved' => $reserved,
            'settled', 'deducted' => $actual,
            default => $actual > 0 ? $actual : $reserved,
        };
    }

    private static function chargeText(float $amount, array $row, string $side, string $billingStatus, string $pointUnit): string
    {
        $reservedKey = $side === 'user' ? 'reserved_user_price' : 'reserved_tenant_cost';
        $reserved = self::points($row[$reservedKey] ?? 0);
        if ($billingStatus === 'refunded') {
            return $reserved > 0
                ? '已退回 ' . self::amountText($reserved) . ' ' . $pointUnit
                : '已退回';
        }
        if ($billingStatus === 'pending_usage' && $amount <= 0) {
            return '待结算';
        }
        $prefix = $amount > 0 ? '-' : '';
        $suffix = $billingStatus === 'reserved' ? '（预扣）' : '';
        return $prefix . self::amountText($amount) . ' ' . $pointUnit . $suffix;
    }

    private static function amountText(float $amount): string
    {
        return number_format(max(0, $amount), 2, '.', '');
    }

    private static function runStatusText(string $status): string
    {
        return match ($status) {
            'pending' => '待处理',
            'reserved' => '待提交',
            'submitting' => '提交中',
            'submitted' => '已提交',
            'running', 'processing' => '生成中',
            'success' => '成功',
            'partial_success' => '部分成功',
            'failed' => '失败',
            'canceled', 'cancelled' => '已取消',
            default => $status !== '' ? $status : '未知',
        };
    }

    private static function billingStatusText(string $status): string
    {
        return match ($status) {
            'none' => '无扣费',
            'reserved' => '已预扣',
            'pending_usage' => '待结算',
            'settled' => '已结算',
            'deducted' => '已扣费',
            'refunded' => '已退回',
            default => $status !== '' ? $status : '未知',
        };
    }

    private static function arrayValue($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
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
