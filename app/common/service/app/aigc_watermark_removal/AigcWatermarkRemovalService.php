<?php

namespace app\common\service\app\aigc_watermark_removal;

use app\common\model\app\aigc_watermark_removal\AigcWatermarkRemovalConfig;
use app\common\model\app\aigc_watermark_removal\AigcWatermarkRemovalResult;
use app\common\model\app\aigc_watermark_removal\AigcWatermarkRemovalTask;
use app\common\model\ai\AiConsumptionLog;
use app\common\service\FileService;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\power\MarketApplicationApiRuntimeService;
use app\common\service\power\MarketGenericImageAppRuntimeService;
use Exception;

class AigcWatermarkRemovalService
{
    public const APP_CODE = 'aigc_watermark_removal';
    public const UPSTREAM_APP_CODE = 'watermark_removal';
    public const UPSTREAM_API_CODE = 'remove';

    public static function config(int $tenantId): array
    {
        $row = AigcWatermarkRemovalConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $data = $row->isEmpty() ? self::defaults() : array_merge(self::defaults(), $row->toArray());
        $data['status'] = (int)($data['status'] ?? 1) === 1 ? 1 : 0;
        $data['config_json'] = is_array($data['config_json'] ?? null) ? $data['config_json'] : [];
        $data['options'] = self::marketOptions($tenantId);
        $data['input_type'] = 'video_url';
        $data['input_label'] = '短视频分享链接';
        $data['max_videos'] = 1;
        $data['api_path'] = '/api/v1/apps/' . self::UPSTREAM_APP_CODE . '/' . self::UPSTREAM_API_CODE;
        $data['dependencies'] = self::dependencies($data['options']);
        if ($row->isEmpty()) {
            AigcWatermarkRemovalConfig::create(['tenant_id' => $tenantId, 'status' => $data['status'], 'config_json' => $data['config_json'], 'create_time' => time(), 'update_time' => time()]);
        }
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, $data);
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        $row = AigcWatermarkRemovalConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $payload = ['tenant_id' => $tenantId, 'status' => array_key_exists('status', $params) ? ((int)$params['status'] === 1 ? 1 : 0) : 1, 'config_json' => is_array($params['config_json'] ?? null) ? $params['config_json'] : [], 'update_time' => time()];
        if ($row->isEmpty()) { $payload['create_time'] = time(); AigcWatermarkRemovalConfig::create($payload); } else $row->save($payload);
    }

    public static function estimate(int $tenantId, array $params): array
    {
        self::assertAvailable($tenantId);
        $prepared = self::prepare($tenantId, $params, false);
        $quote = MarketApplicationApiRuntimeService::quote($tenantId, $prepared['selection'], 1);
        return array_merge($quote, ['quantity' => 1, 'video_count' => 1, 'url' => $prepared['url'], 'display_points' => (float)$quote['user_charge_points']]);
    }

    public static function generate(int $tenantId, int $userId, array $params): array
    {
        self::assertAvailable($tenantId);
        $prepared = self::prepare($tenantId, $params, true);
        $quote = MarketApplicationApiRuntimeService::quote($tenantId, $prepared['selection'], 1);
        $task = AigcWatermarkRemovalTask::create(['tenant_id' => $tenantId, 'user_id' => $userId, 'app_task_id' => 0, 'consumption_id' => 0, 'provider_task_id' => '', 'market_product_id' => (int)$quote['market_product_id'], 'market_sku_id' => (int)$quote['market_sku_id'], 'pricing_snapshot' => $quote['market_snapshot'], 'source_url' => $prepared['url'], 'request_snapshot' => $prepared['request'], 'status' => 'pending', 'error' => '', 'tenant_cost_points' => (float)$quote['tenant_cost_points'], 'user_charge_points' => (float)$quote['user_charge_points'], 'create_time' => time(), 'update_time' => time(), 'finish_time' => 0, 'delete_time' => 0]);
        try {
            $reserve = MarketGenericImageAppRuntimeService::reserve($tenantId, $userId, self::UPSTREAM_API_CODE, (string)$task['id'], $prepared['selection'], $prepared['request'], 1, self::APP_CODE, 'aigc_watermark_removal_task');
            $task->save(['app_task_id' => (int)$reserve['app_task_id'], 'consumption_id' => (int)$reserve['consumption_id'], 'market_product_id' => (int)($reserve['market_snapshot']['product_id'] ?? $quote['market_product_id']), 'market_sku_id' => (int)($reserve['market_snapshot']['sku_id'] ?? $quote['market_sku_id']), 'pricing_snapshot' => $reserve['market_snapshot'], 'status' => 'running', 'update_time' => time()]);
            MarketGenericImageAppRuntimeService::linkBusinessTask((int)$reserve['app_task_id'], (int)$task['id']);
            $result = MarketGenericImageAppRuntimeService::submit((int)$reserve['consumption_id'], $prepared['request']);
            $task->save(['provider_task_id' => (string)($result['provider_task_id'] ?? ''), 'status' => (string)($result['status'] ?? 'running'), 'update_time' => time()]);
            self::syncConsumption((int)$task['id']);
            return self::response($task, $quote);
        } catch (\Throwable $e) {
            if ((int)$task['consumption_id'] > 0) { try { MarketGenericImageAppRuntimeService::fail((int)$task['consumption_id'], $e->getMessage(), 'submit_failed'); } catch (\Throwable) {} }
            $task->save(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'finish_time' => time(), 'update_time' => time()]);
            throw $e instanceof Exception ? $e : new Exception('短视频去水印任务提交失败');
        }
    }

    public static function taskLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        $query = AigcWatermarkRemovalTask::where(['tenant_id' => $tenantId, 'delete_time' => 0])->order('id', 'desc');
        if ($userId > 0) $query->where('user_id', $userId);
        $status = trim((string)($params['status'] ?? '')); if ($status !== '') $query->where('status', $status);
        $pageNo = max(1, (int)($params['page_no'] ?? 1)); $pageSize = max(1, min(100, (int)($params['page_size'] ?? 15))); $count = (int)(clone $query)->count();
        $rows = $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();
        foreach ($rows as &$row) { self::refreshRunningTask($row); self::syncConsumption((int)$row['id'], $userId); $fresh = AigcWatermarkRemovalTask::find((int)$row['id']); $row = self::formatTask($fresh?->toArray() ?: $row, $tenantId); } unset($row);
        return ['lists' => $rows, 'count' => $count, 'page_no' => $pageNo, 'page_size' => $pageSize];
    }

    public static function taskDetail(int $tenantId, int $taskId, int $userId = 0): array
    {
        $query = AigcWatermarkRemovalTask::where(['tenant_id' => $tenantId, 'id' => $taskId, 'delete_time' => 0]); if ($userId > 0) $query->where('user_id', $userId); $task = $query->findOrEmpty(); if ($task->isEmpty()) throw new Exception('任务不存在');
        self::refreshRunningTask($task->toArray()); self::syncConsumption($taskId, $userId); $fresh = AigcWatermarkRemovalTask::find($taskId); return self::formatTask($fresh?->toArray() ?: $task->toArray(), $tenantId);
    }

    public static function resultLists(int $tenantId, int $userId, array $params = []): array { $params['status'] = 'success'; return self::taskLists($tenantId, $userId, $params); }
    public static function retryTask(int $tenantId, int $taskId): array { $task = AigcWatermarkRemovalTask::where(['tenant_id' => $tenantId, 'id' => $taskId, 'delete_time' => 0])->findOrEmpty(); if ($task->isEmpty()) throw new Exception('任务不存在'); return self::generate($tenantId, (int)$task['user_id'], ['url' => (string)$task['source_url'], 'market_product_id' => (int)$task['market_product_id'], 'market_sku_id' => (int)$task['market_sku_id']]); }
    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void { $query = AigcWatermarkRemovalTask::where(['tenant_id' => $tenantId, 'id' => $taskId, 'delete_time' => 0]); if ($userId > 0) $query->where('user_id', $userId); $task = $query->findOrEmpty(); if ($task->isEmpty()) throw new Exception('任务不存在'); if ((int)$task['consumption_id'] > 0 && in_array((string)$task['status'], ['pending', 'running'], true)) { try { MarketGenericImageAppRuntimeService::cancel((int)$task['consumption_id']); } catch (\Throwable) {} } $task->save(['delete_time' => time(), 'update_time' => time()]); AigcWatermarkRemovalResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time()]); }
    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void { $query = AigcWatermarkRemovalResult::where(['tenant_id' => $tenantId, 'id' => $resultId, 'delete_time' => 0]); if ($userId > 0) $query->where('user_id', $userId); $result = $query->findOrEmpty(); if ($result->isEmpty()) throw new Exception('作品不存在'); $result->save(['delete_time' => time()]); }

    public static function syncConsumption(int $taskId, int $userId = 0): void
    {
        $query = AigcWatermarkRemovalTask::where(['id' => $taskId, 'delete_time' => 0]); if ($userId > 0) $query->where('user_id', $userId); $task = $query->findOrEmpty(); if ($task->isEmpty() || (int)$task['consumption_id'] <= 0) return;
        $consumption = AiConsumptionLog::findOrEmpty((int)$task['consumption_id']); if ($consumption->isEmpty()) return;
        $terminal = in_array((string)$consumption['run_status'], ['success', 'failed', 'canceled', 'cancelled'], true) || in_array((string)$consumption['billing_status'], ['settled', 'refunded'], true); if (!$terminal) return;
        $summary = $consumption['response_summary'] ?? []; if (is_string($summary)) $summary = json_decode($summary, true) ?: []; $videos = is_array($summary) ? (array)($summary['videos'] ?? []) : [];
        if ((string)$consumption['run_status'] === 'success' && $videos !== []) {
            foreach ($videos as $video) { if (!is_array($video) || trim((string)($video['video_uri'] ?? '')) === '') continue; $exists = AigcWatermarkRemovalResult::where(['tenant_id' => (int)$task['tenant_id'], 'task_id' => $taskId, 'video_uri' => (string)$video['video_uri'], 'delete_time' => 0])->findOrEmpty(); if ($exists->isEmpty()) AigcWatermarkRemovalResult::create(['tenant_id' => (int)$task['tenant_id'], 'task_id' => $taskId, 'user_id' => (int)$task['user_id'], 'video_uri' => (string)$video['video_uri'], 'storage_scope' => (string)($video['storage_scope'] ?? 'tenant'), 'storage_engine' => (string)($video['storage_engine'] ?? 'local'), 'storage_domain' => (string)($video['storage_domain'] ?? ''), 'width' => (int)($video['width'] ?? 0), 'height' => (int)($video['height'] ?? 0), 'create_time' => time(), 'update_time' => time(), 'delete_time' => 0]); }
            $task->save(['status' => 'success', 'error' => '', 'finish_time' => self::timestampValue($consumption['finish_time'] ?? 0) ?: time(), 'update_time' => time()]);
        } elseif (in_array((string)$consumption['run_status'], ['failed', 'canceled', 'cancelled'], true) || (string)$consumption['billing_status'] === 'refunded') $task->save(['status' => (string)$consumption['run_status'] === 'canceled' ? 'canceled' : 'failed', 'error' => (string)($consumption['error_message'] ?? '短视频去水印任务失败'), 'finish_time' => self::timestampValue($consumption['finish_time'] ?? 0) ?: time(), 'update_time' => time()]);
    }

    private static function prepare(int $tenantId, array $params, bool $required): array
    {
        $url = trim((string)($params['url'] ?? $params['video_url'] ?? $params['share_url'] ?? '')); if ($required && $url === '') throw new Exception('请输入短视频分享链接'); if ($url !== '' && !self::validUrl($url)) throw new Exception('短视频分享链接格式不正确');
        $options = self::marketOptions($tenantId); $selection = ['runtime_adapter' => 'watermark_removal', 'upstream_app_code' => self::UPSTREAM_APP_CODE, 'market_product_id' => (int)($params['market_product_id'] ?? 0), 'market_sku_id' => (int)($params['market_sku_id'] ?? $params['sku_id'] ?? 0), 'id' => (string)($params['channel'] ?? $params['channel_code'] ?? '')];
        if ((int)$selection['market_product_id'] <= 0 || (int)$selection['market_sku_id'] <= 0) { $first = $options[0] ?? []; $selection['market_product_id'] = (int)($first['market_product_id'] ?? 0); $selection['market_sku_id'] = (int)($first['market_sku_id'] ?? 0); $selection['id'] = (string)($first['id'] ?? ''); }
        $selected = array_filter($options, static fn(array $item): bool => (int)($item['market_product_id'] ?? 0) === (int)$selection['market_product_id'] && (int)($item['market_sku_id'] ?? 0) === (int)$selection['market_sku_id']); if ($selected === []) throw new Exception('暂无可用的短视频去水印应用 API 规格');
        return ['url' => $url, 'selection' => $selection, 'request' => ['url' => $url]];
    }

    private static function marketOptions(int $tenantId): array { return MarketGenericImageAppRuntimeService::options($tenantId, self::UPSTREAM_APP_CODE); }
    private static function refreshRunningTask(array $task): void
    {
        if (!in_array((string)($task['status'] ?? ''), ['pending', 'running'], true) || (int)($task['consumption_id'] ?? 0) <= 0) return;
        try { MarketApplicationApiRuntimeService::refresh((int)$task['consumption_id']); } catch (\Throwable) { }
    }
    private static function assertAvailable(int $tenantId): void { if (AppAccessService::assertTenantCanUse($tenantId, self::APP_CODE) !== null) throw new Exception('短视频去水印应用未开通或未上架'); $config = self::config($tenantId); if ((int)$config['status'] !== 1) throw new Exception('短视频去水印应用已停用'); if (empty($config['options'])) throw new Exception('暂无上架的短视频去水印应用 API'); }
    private static function dependencies(array $options): array { return ['items' => [['app_code' => 'power_market', 'name' => '短视频去水印应用 API', 'required_for' => '短视频去水印', 'installed' => true, 'tenant_enabled' => true, 'channel_ready' => $options !== [], 'ready' => $options !== [], 'message' => $options !== [] ? '可用' : '暂无上架的短视频去水印应用 API']], 'ready' => $options !== []]; }

    private static function formatTask(array $row, int $tenantId): array { $results = AigcWatermarkRemovalResult::where(['tenant_id' => $tenantId, 'task_id' => (int)$row['id'], 'delete_time' => 0])->order('id', 'asc')->select()->toArray(); foreach ($results as &$result) { $result['video_url'] = FileService::getFileUrlByStorage($result['video_uri'], $result['storage_scope'] ?? '', $result['storage_engine'] ?? '', $result['storage_domain'] ?? ''); $result['download_url'] = $result['video_url']; } unset($result); $row['task_id'] = (int)$row['id']; $row['results'] = $results; $row['video_url'] = (string)($results[0]['video_url'] ?? ''); $row['status_label'] = match ((string)($row['status'] ?? '')) { 'success' => '已完成', 'failed' => '失败', 'canceled' => '已取消', 'pending' => '排队中', default => '处理中' }; return $row; }
    private static function response(AigcWatermarkRemovalTask $task, array $quote): array { return ['task_id' => (int)$task['id'], 'status' => (string)$task['status'], 'error' => (string)($task['error'] ?? ''), 'results' => self::formatTask($task->toArray(), (int)$task['tenant_id'])['results'], 'estimate' => array_merge($quote, ['quantity' => 1, 'video_count' => 1, 'url' => (string)$task['source_url'], 'display_points' => (float)$quote['user_charge_points']])]; }
    private static function defaults(): array { return ['status' => 1, 'config_json' => []]; }
    private static function validUrl(string $url): bool { $parts = parse_url($url); return is_array($parts) && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) && trim((string)($parts['host'] ?? '')) !== ''; }
    private static function timestampValue(mixed $value): int { if (is_numeric($value)) return (int)$value; $timestamp = strtotime((string)$value); return $timestamp === false ? 0 : $timestamp; }
}
