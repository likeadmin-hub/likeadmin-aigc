<?php

declare(strict_types=1);

namespace app\common\service\app\aigc_video_enhance;

use app\common\model\ai\AiConsumptionEvent;
use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiTaskResultAsset;
use app\common\service\ai\AiTaskOperationService as Operations;
use app\common\service\FileService;
use app\common\service\workbench\PlatformSupplierCostReview;
use app\common\service\workbench\PlatformTaskCost;
use InvalidArgumentException;
use think\facade\Db;

final class VideoEnhanceAdminTasks
{
    private const TERMINAL = ['success', 'failed', 'canceled', 'cancelled'];
    private const PENDING = ['pending_confirmation', 'submitting', 'needs_reconciliation'];

    public static function lists(int $tenant, array $params): array
    {
        $scope = $tenant ?: max(0, (int)($params['tenant_id'] ?? 0));
        $query = self::query($scope);
        if ($user = (int)($params['user_id'] ?? 0)) $query->where('v.user_id', $user);
        if ($id = (int)($params['id'] ?? 0)) $query->where('v.id', $id);
        if ($keyword = trim((string)($params['task_no'] ?? ''))) $query->where('t.task_no|c.consume_no', $keyword);
        if ($start = (int)($params['start_time'] ?? 0)) $query->where('v.create_time', '>=', $start);
        if ($end = (int)($params['end_time'] ?? 0)) $query->where('v.create_time', '<=', $end);
        $status = (string)($params['status'] ?? '');
        if ($status === 'pending_confirmation') $query->whereIn('c.run_status', self::PENDING);
        elseif ($status === 'running') $query->whereIn('c.run_status', ['reserved', 'pending', 'running']);
        elseif ($status === 'canceled') $query->whereIn('c.run_status', ['canceled', 'cancelled']);
        elseif (in_array($status, ['success', 'failed'], true)) $query->where('c.run_status', $status);
        $billing = (string)($params['billing_status'] ?? '');
        if (in_array($billing, ['reserved', 'settled', 'refunded', 'none'], true)) $query->where('c.billing_status', $billing);

        $page = max(1, (int)($params['page_no'] ?? 1));
        $size = max(1, min(50, (int)($params['page_size'] ?? 15)));
        $count = (clone $query)->count();
        $rows = $query->field(self::fields())->order('v.id', 'desc')->page($page, $size)->select()->toArray();
        return ['lists' => array_map(self::present(...), $rows), 'count' => $count];
    }

    public static function detail(int $tenant, int $id): array
    {
        $row = self::record($tenant, $id);
        $data = self::present($row);
        if ((int)$row['app_task_id'] > 0) {
            $data['retry_task_no'] = self::retryTaskNo($row);
            $data['can_retry'] = $data['can_retry'] && $data['retry_task_no'] === '';
        }
        $id = (int)$row['consumption_id'];
        if ($id > 0) {
            $data['can_transfer'] = AiTaskResultAsset::where('consumption_id', $id)->whereIn('transfer_status', ['retrying', 'failed'])->count() > 0;
            $events = AiConsumptionEvent::where('consumption_id', $id)->whereIn('event_type', ['video_enhance_review', 'video_enhance_retry'])
                ->field('event_type,event_status,payload_summary,create_time')->order('id', 'desc')->limit(50)->select()->toArray();
            $operations = Operations::operationListsForTask((int)$row['app_task_id'], $id, $tenant);
            $data['operations'] = array_map(static fn(array $op): array => array_intersect_key($op, array_flip([
                'status', 'reason', 'result_message', 'admin_id', 'create_time', 'operation_no',
            ])), $operations);
            $data['reviews'] = $events;
        }
        if ($tenant === 0 && $row['billing_status'] === 'settled') {
            $data['supplier_points'] = PlatformSupplierCostReview::confirmedPoints([$id])[$id] ?? PlatformTaskCost::points($row);
        }
        return $data;
    }

    public static function media(int $tenant, int $id): array
    {
        $row = self::record($tenant, $id);
        $owner = (int)$row['tenant_id'];
        $source = $row['video_uri'] ? FileService::getFileUrlByStorage($row['video_uri'], $row['storage_scope'], $row['storage_engine'], $row['storage_domain'], $owner) : '';
        $summary = json_decode((string)$row['response_summary'], true) ?: [];
        $results = [];
        foreach (($summary['videos'] ?? []) as $asset) {
            if (empty($asset['video_uri'])) continue;
            $results[] = [
                'url' => FileService::getFileUrlByStorage((string)$asset['video_uri'], (string)($asset['storage_scope'] ?? ''), (string)($asset['storage_engine'] ?? ''), (string)($asset['storage_domain'] ?? ''), $owner),
                'width' => (int)($asset['width'] ?? 0), 'height' => (int)($asset['height'] ?? 0),
            ];
        }
        return ['source' => $source, 'results' => $results];
    }

    public static function diagnose(int $tenant, int $id): array
    {
        $row = self::record($tenant, $id);
        if (!$row['video_uri']) throw new InvalidArgumentException('此任务使用视频直链，请在创作页验证链接');
        return VideoEnhanceService::diagnoseUpload((int)$row['tenant_id'], (int)$row['user_id'], $row['video_uri']);
    }

    public static function operate(int $tenant, int $admin, int $id, string $action, string $reason): array
    {
        $row = self::record($tenant, $id);
        $reason = trim($reason);
        if (mb_strlen($reason) > 300) throw new InvalidArgumentException('处理备注最多 300 字');
        $consumption = (int)$row['consumption_id'];
        if (!$consumption) throw new InvalidArgumentException('该任务缺少可处理的消费记录');
        if ($action === 'review') {
            if ($reason === '' || in_array($row['run_status'], self::TERMINAL, true)) throw new InvalidArgumentException('请为待处理任务填写核对备注');
            AiConsumptionEvent::create(['consumption_id' => $consumption, 'event_type' => 'video_enhance_review',
                'event_status' => $tenant > 0 ? 'requested' : 'reviewed', 'payload_summary' => ['reason' => $reason, 'admin_id' => $admin], 'create_time' => time()]);
            return [];
        }
        if ($action === Operations::ACTION_QUERY && (empty($row['upstream_task_id']) || in_array($row['run_status'], self::TERMINAL, true))) {
            throw new InvalidArgumentException('当前任务没有可查询的上游任务号');
        }
        if (!in_array($action, [Operations::ACTION_QUERY, Operations::ACTION_RETRY_TRANSFER], true)) throw new InvalidArgumentException('不支持的任务操作');
        return Operations::submit(['consumption:'.$consumption], $action, $reason, $tenant, $admin, $tenant > 0 ? 'tenant' : 'platform');
    }

    public static function retryInput(int $tenant, int $id): array
    {
        if ($tenant < 1) throw new InvalidArgumentException('请在租户后台重新提交');
        $row = self::record($tenant, $id);
        if ($row['run_status'] !== 'failed' || $row['billing_status'] !== 'refunded' || (int)$row['app_task_id'] < 1) {
            throw new InvalidArgumentException('原任务需要明确失败并完成退款，才可重新提交');
        }
        return [(int)$row['user_id'], ($row['video_uri'] ? ['video_uri' => $row['video_uri']] : ['video_url' => $row['source_url']]) + ['bit_rate' => (int)$row['bit_rate']], (int)$row['app_task_id']];
    }

    private static function present(array $row): array
    {
        $run = (string)($row['run_status'] ?? '');
        $row['can_query'] = !empty($row['upstream_task_id']) && !in_array($run, self::TERMINAL, true);
        $row['can_review'] = !empty($row['consumption_id']) && !in_array($run, self::TERMINAL, true);
        $row['can_retry'] = $run === 'failed' && $row['billing_status'] === 'refunded' && (int)$row['app_task_id'] > 0;
        $row['status'] = in_array($run, self::PENDING, true) ? 'pending_confirmation' : ($run === 'cancelled' ? 'canceled' : ($run ?: 'unknown'));
        $row['error_message'] = $run === 'failed' ? match ($row['error_code'] ?? '') {
            'timeout' => '处理超时，详情见统一消费日志',
            'submit_failed' => '处理请求提交失败，详情见统一消费日志',
            default => '视频处理失败，请查看统一消费日志',
        } : '';
        unset($row['response_summary'], $row['price_snapshot'], $row['usage_snapshot'], $row['video_uri'], $row['source_url'],
            $row['storage_scope'], $row['storage_engine'], $row['storage_domain']);
        return $row;
    }

    private static function record(int $tenant, int $id): array
    {
        $row = self::query($tenant)->where('v.id', $id)->field(self::fields().',v.video_uri,v.source_url,v.storage_scope,v.storage_engine,v.storage_domain,c.response_summary,c.price_snapshot,c.usage_snapshot')->find();
        if (!$row) throw new InvalidArgumentException('任务不存在或无权查看');
        return $row;
    }

    private static function query(int $tenant): \think\db\Query
    {
        $query = Db::name('aigc_video_enhance_task')->alias('v')
            ->leftJoin('ai_consumption_log c', 'c.id=v.consumption_id AND c.tenant_id=v.tenant_id AND c.user_id=v.user_id AND c.app_code=\'aigc_video_enhance\'')
            ->leftJoin('ai_app_task t', 't.id=v.app_task_id AND t.tenant_id=v.tenant_id AND t.user_id=v.user_id')
            ->where('v.delete_time', 0);
        if ($tenant > 0) $query->where('v.tenant_id', $tenant);
        return $query;
    }

    private static function fields(): string
    {
        return 'v.id,v.tenant_id,v.user_id,v.app_task_id,v.duration,v.width,v.height,v.bit_rate,v.create_time,t.task_no,c.id consumption_id,c.consume_no,c.upstream_task_id,c.upstream_request_id,c.run_status,c.billing_status,c.error_code,c.quantity,c.usage_unit,c.reserved_tenant_cost,c.reserved_user_price,c.actual_tenant_cost,c.actual_user_price,c.finish_time';
    }

    private static function retryTaskNo(array $row): string
    {
        return (string)AiAppTask::where(['tenant_id' => $row['tenant_id'], 'app_code' => VideoEnhanceService::APP,
            'parent_task_id' => $row['app_task_id']])->order('id', 'desc')->value('task_no');
    }
}
