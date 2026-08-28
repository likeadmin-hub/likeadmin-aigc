<?php

namespace app\common\service\app\aigc_music_cover;

use app\common\model\ai\AiConsumptionLog;
use app\common\model\app\aigc_music\AigcMusicAsset;
use app\common\model\app\aigc_music_cover\AigcMusicCoverConfig;
use app\common\model\app\aigc_music_cover\AigcMusicCoverResult;
use app\common\model\app\aigc_music_cover\AigcMusicCoverTask;
use app\common\service\FileService;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\aigc_music\AigcMusicAssetService;
use app\common\service\power\MarketApplicationApiRuntimeService;
use Exception;

class AigcMusicCoverService
{
    public const APP_CODE = 'aigc_music_cover';
    public const UPSTREAM_APP_CODE = 'seedsvc';
    public const UPSTREAM_API_CODE = 'submit';

    public static function config(int $tenantId): array
    {
        $row = AigcMusicCoverConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $data = $row->isEmpty() ? self::defaults() : array_merge(self::defaults(), $row->toArray());
        $data['status'] = (int)($data['status'] ?? 1) === 1 ? 1 : 0;
        $data['config_json'] = is_array($data['config_json'] ?? null) ? $data['config_json'] : [];
        $data['options'] = self::marketOptions($tenantId);
        $data['input_type'] = 'audio_pair';
        $data['input_label'] = '原始歌曲 + 目标音色参考音频';
        $data['upstream_app_code'] = self::UPSTREAM_APP_CODE;
        $data['upstream_api_code'] = self::UPSTREAM_API_CODE;
        $data['api_path'] = '/api/v1/apps/' . self::UPSTREAM_APP_CODE . '/' . self::UPSTREAM_API_CODE;
        $data['dependencies'] = self::dependencies($data['options']);
        if ($row->isEmpty()) {
            AigcMusicCoverConfig::create([
                'tenant_id' => $tenantId,
                'status' => $data['status'],
                'config_json' => $data['config_json'],
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, $data);
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        $row = AigcMusicCoverConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $payload = [
            'tenant_id' => $tenantId,
            'status' => array_key_exists('status', $params) ? ((int)$params['status'] === 1 ? 1 : 0) : 1,
            'config_json' => is_array($params['config_json'] ?? null) ? $params['config_json'] : [],
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $payload['create_time'] = time();
            AigcMusicCoverConfig::create($payload);
        } else {
            $row->save($payload);
        }
    }

    public static function uploadAudio(int $tenantId, int $userId, array $params): array
    {
        $assetType = trim((string)($params['asset_type'] ?? $params['audio_type'] ?? 'cover_source'));
        return AigcMusicAssetService::uploadAudio($tenantId, $userId, $params + ['asset_type' => $assetType]);
    }

    public static function estimate(int $tenantId, array $params): array
    {
        self::assertAvailable($tenantId);
        $prepared = self::prepare($tenantId, $params, false);
        $quote = MarketApplicationApiRuntimeService::quote($tenantId, $prepared['selection'], 1);
        return array_merge($quote, [
            'quantity' => 1,
            'ref_audio' => $prepared['request']['ref_audio'],
            'source_audio' => $prepared['request']['source_audio'],
            'display_points' => (float)$quote['user_charge_points'],
        ]);
    }

    public static function generate(int $tenantId, int $userId, array $params): array
    {
        self::assertAvailable($tenantId);
        $prepared = self::prepare($tenantId, $params, true);
        $quote = MarketApplicationApiRuntimeService::quote($tenantId, $prepared['selection'], 1);
        $task = AigcMusicCoverTask::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'app_task_id' => 0,
            'consumption_id' => 0,
            'provider_task_id' => '',
            'market_product_id' => (int)$quote['market_product_id'],
            'market_sku_id' => (int)$quote['market_sku_id'],
            'pricing_snapshot' => $quote['market_snapshot'],
            'source_asset_id' => (int)$prepared['source_asset_id'],
            'source_uri' => $prepared['source_uri'],
            'source_url' => $prepared['source_url'],
            'reference_asset_id' => (int)$prepared['reference_asset_id'],
            'reference_uri' => $prepared['reference_uri'],
            'reference_url' => $prepared['reference_url'],
            'request_snapshot' => $prepared['request'],
            'title' => $prepared['request']['title'],
            'status' => 'pending',
            'error' => '',
            'tenant_cost_points' => (float)$quote['tenant_cost_points'],
            'user_charge_points' => (float)$quote['user_charge_points'],
            'actual_tenant_cost' => 0,
            'actual_user_price' => 0,
            'create_time' => time(),
            'update_time' => time(),
            'finish_time' => 0,
            'delete_time' => 0,
        ]);
        try {
            $reserve = MarketApplicationApiRuntimeService::reserve(
                $tenantId,
                $userId,
                'music_cover',
                (string)$task['id'],
                $prepared['selection'],
                $prepared['request'],
                1,
                self::APP_CODE,
                'aigc_music_cover_task'
            );
            $task->save([
                'app_task_id' => (int)$reserve['app_task_id'],
                'consumption_id' => (int)$reserve['consumption_id'],
                'market_product_id' => (int)($reserve['market_snapshot']['product_id'] ?? $quote['market_product_id']),
                'market_sku_id' => (int)($reserve['market_snapshot']['sku_id'] ?? $quote['market_sku_id']),
                'pricing_snapshot' => $reserve['market_snapshot'],
                'status' => 'running',
                'update_time' => time(),
            ]);
            \app\common\service\power\MarketSeedSvcAppRuntimeService::linkBusinessTask((int)$reserve['app_task_id'], (int)$task['id']);
            $result = MarketApplicationApiRuntimeService::submit((int)$reserve['consumption_id'], $prepared['request']);
            $task->save([
                'provider_task_id' => (string)($result['provider_task_id'] ?? ''),
                'status' => (string)($result['status'] ?? 'running'),
                'update_time' => time(),
            ]);
            self::syncConsumption((int)$task['id']);
            return self::response($task, $quote);
        } catch (\Throwable $e) {
            if ((int)$task['consumption_id'] > 0) {
                try {
                    MarketApplicationApiRuntimeService::fail((int)$task['consumption_id'], $e->getMessage(), 'submit_failed');
                } catch (\Throwable) {
                }
            }
            $task->save([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'finish_time' => time(),
                'update_time' => time(),
            ]);
            throw $e instanceof Exception ? $e : new Exception('音乐翻唱任务提交失败');
        }
    }

    public static function taskLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        $query = AigcMusicCoverTask::where(['tenant_id' => $tenantId, 'delete_time' => 0])->order('id', 'desc');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['page_size'] ?? 15)));
        $count = (int)(clone $query)->count();
        $rows = $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();
        foreach ($rows as &$row) {
            self::refreshRunningTask($row);
            self::syncConsumption((int)$row['id'], $userId);
            $fresh = AigcMusicCoverTask::find((int)$row['id']);
            $row = self::formatTask($fresh?->toArray() ?: $row, $tenantId);
        }
        unset($row);
        return ['lists' => $rows, 'count' => $count, 'page_no' => $pageNo, 'page_size' => $pageSize];
    }

    public static function taskDetail(int $tenantId, int $taskId, int $userId = 0): array
    {
        $query = AigcMusicCoverTask::where(['tenant_id' => $tenantId, 'id' => $taskId, 'delete_time' => 0]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        self::refreshRunningTask($task->toArray());
        self::syncConsumption($taskId, $userId);
        $fresh = AigcMusicCoverTask::find($taskId);
        return self::formatTask($fresh?->toArray() ?: $task->toArray(), $tenantId);
    }

    public static function resultLists(int $tenantId, int $userId, array $params = []): array
    {
        $params['status'] = 'success';
        return self::taskLists($tenantId, $userId, $params);
    }

    public static function retryTask(int $tenantId, int $taskId, int $userId = 0): array
    {
        $query = AigcMusicCoverTask::where(['tenant_id' => $tenantId, 'id' => $taskId, 'delete_time' => 0]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return self::generate($tenantId, (int)$task['user_id'], [
            'source_asset_id' => (int)$task['source_asset_id'],
            'source_uri' => (string)$task['source_uri'],
            'source_url' => (string)$task['source_url'],
            'reference_asset_id' => (int)$task['reference_asset_id'],
            'reference_uri' => (string)$task['reference_uri'],
            'reference_url' => (string)$task['reference_url'],
            'title' => (string)$task['title'],
            'market_product_id' => (int)$task['market_product_id'],
            'market_sku_id' => (int)$task['market_sku_id'],
        ]);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = AigcMusicCoverTask::where(['tenant_id' => $tenantId, 'id' => $taskId, 'delete_time' => 0]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        if ((int)$task['consumption_id'] > 0 && in_array((string)$task['status'], ['pending', 'running'], true)) {
            try {
                MarketApplicationApiRuntimeService::cancel((int)$task['consumption_id']);
            } catch (\Throwable) {
            }
        }
        $task->save(['delete_time' => time(), 'update_time' => time()]);
        AigcMusicCoverResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time()]);
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = AigcMusicCoverResult::where(['tenant_id' => $tenantId, 'id' => $resultId, 'delete_time' => 0]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $result = $query->findOrEmpty();
        if ($result->isEmpty()) {
            throw new Exception('作品不存在');
        }
        $result->save(['delete_time' => time()]);
    }

    public static function syncConsumption(int $taskId, int $userId = 0): void
    {
        $query = AigcMusicCoverTask::where(['id' => $taskId, 'delete_time' => 0]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty() || (int)$task['consumption_id'] <= 0) {
            return;
        }
        $consumption = AiConsumptionLog::findOrEmpty((int)$task['consumption_id']);
        if ($consumption->isEmpty()) {
            return;
        }
        $terminal = in_array((string)$consumption['run_status'], ['success', 'failed', 'canceled', 'cancelled'], true)
            || in_array((string)$consumption['billing_status'], ['settled', 'refunded'], true);
        if (!$terminal) {
            return;
        }
        $summary = $consumption['response_summary'] ?? [];
        if (is_string($summary)) {
            $summary = json_decode($summary, true) ?: [];
        }
        $items = is_array($summary) ? (array)($summary['items'] ?? []) : [];
        if ((string)$consumption['run_status'] === 'success' && $items !== []) {
            foreach ($items as $item) {
                if (!is_array($item) || trim((string)($item['audio_uri'] ?? '')) === '') {
                    continue;
                }
                $exists = AigcMusicCoverResult::where([
                    'tenant_id' => (int)$task['tenant_id'],
                    'task_id' => $taskId,
                    'audio_uri' => (string)$item['audio_uri'],
                    'delete_time' => 0,
                ])->findOrEmpty();
                if (!$exists->isEmpty()) {
                    continue;
                }
                AigcMusicCoverResult::create([
                    'tenant_id' => (int)$task['tenant_id'],
                    'task_id' => $taskId,
                    'user_id' => (int)$task['user_id'],
                    'audio_uri' => (string)$item['audio_uri'],
                    'audio_url' => (string)($item['audio_url'] ?? ''),
                    'storage_scope' => (string)($item['storage_scope'] ?? 'tenant'),
                    'storage_engine' => (string)($item['storage_engine'] ?? 'local'),
                    'storage_domain' => (string)($item['storage_domain'] ?? ''),
                    'mime_type' => (string)($item['mime_type'] ?? ''),
                    'file_size' => (int)($item['file_size'] ?? 0),
                    'duration' => (float)($item['duration'] ?? 0),
                    'create_time' => time(),
                    'update_time' => time(),
                    'delete_time' => 0,
                ]);
            }
            $task->save([
                'status' => 'success',
                'error' => '',
                'finish_time' => self::timestampValue($consumption['finish_time'] ?? 0) ?: time(),
                'update_time' => time(),
            ]);
        } elseif (in_array((string)$consumption['run_status'], ['failed', 'canceled', 'cancelled'], true)
            || (string)$consumption['billing_status'] === 'refunded') {
            $task->save([
                'status' => (string)$consumption['run_status'] === 'canceled' ? 'canceled' : 'failed',
                'error' => (string)($consumption['error_message'] ?? '音乐翻唱任务失败'),
                'finish_time' => self::timestampValue($consumption['finish_time'] ?? 0) ?: time(),
                'update_time' => time(),
            ]);
        }
    }

    private static function prepare(int $tenantId, array $params, bool $required): array
    {
        $sourceAssetId = (int)($params['source_asset_id'] ?? $params['source_id'] ?? $params['asset_id'] ?? 0);
        $referenceAssetId = (int)($params['reference_asset_id'] ?? $params['ref_asset_id'] ?? $params['voice_asset_id'] ?? 0);
        $sourceUri = trim((string)($params['source_uri'] ?? $params['source_audio_uri'] ?? ''));
        $sourceUrl = trim((string)($params['source_url'] ?? $params['source_audio'] ?? $params['audio_url'] ?? ''));
        $referenceUri = trim((string)($params['reference_uri'] ?? $params['ref_audio_uri'] ?? ''));
        $referenceUrl = trim((string)($params['reference_url'] ?? $params['ref_audio'] ?? $params['voice_audio_url'] ?? ''));
        if ($sourceAssetId > 0) {
            [$sourceUri, $sourceUrl] = self::assetInput($tenantId, $sourceAssetId, '原始歌曲');
        }
        if ($referenceAssetId > 0) {
            [$referenceUri, $referenceUrl] = self::assetInput($tenantId, $referenceAssetId, '目标音色参考音频');
        }
        if ($sourceUrl !== '' && !self::validUrl($sourceUrl)) {
            throw new Exception('原始歌曲地址格式不正确');
        }
        if ($referenceUrl !== '' && !self::validUrl($referenceUrl)) {
            throw new Exception('目标音色参考音频地址格式不正确');
        }
        if ($required && ($sourceUrl === '' || $referenceUrl === '')) {
            throw new Exception('请同时提供原始歌曲和目标音色参考音频');
        }
        $options = self::marketOptions($tenantId);
        $selection = [
            'runtime_adapter' => 'seedsvc',
            'upstream_app_code' => self::UPSTREAM_APP_CODE,
            'market_product_id' => (int)($params['market_product_id'] ?? 0),
            'market_sku_id' => (int)($params['market_sku_id'] ?? $params['sku_id'] ?? 0),
            'id' => (string)($params['channel'] ?? $params['channel_code'] ?? ''),
        ];
        $hasRequestedSelection = (int)$selection['market_product_id'] > 0 || (int)$selection['market_sku_id'] > 0;
        if (!$hasRequestedSelection) {
            $first = $options[0] ?? [];
            $selection['market_product_id'] = (int)($first['market_product_id'] ?? 0);
            $selection['market_sku_id'] = (int)($first['market_sku_id'] ?? 0);
            $selection['id'] = (string)($first['id'] ?? '');
        }
        $selected = array_filter($options, static fn(array $item): bool =>
            ((int)$selection['market_product_id'] <= 0 || (int)($item['market_product_id'] ?? 0) === (int)$selection['market_product_id'])
            && ((int)$selection['market_sku_id'] <= 0 || (int)($item['market_sku_id'] ?? 0) === (int)$selection['market_sku_id']));
        if ($selected === []) {
            throw new Exception('暂无可用的音乐翻唱应用 API 规格');
        }
        if ($hasRequestedSelection) {
            $selected = array_values($selected);
            $selection['market_product_id'] = (int)$selected[0]['market_product_id'];
            $selection['market_sku_id'] = (int)$selected[0]['market_sku_id'];
            $selection['id'] = (string)($selected[0]['id'] ?? '');
        }
        $mode = trim((string)($params['mode'] ?? 'async_query')) ?: 'async_query';
        $request = [
            'title' => trim((string)($params['title'] ?? '音乐翻唱')) ?: '音乐翻唱',
            'mode' => $mode,
            'ref_audio' => $referenceUrl,
            'source_audio' => $sourceUrl,
        ];
        return [
            'source_asset_id' => $sourceAssetId,
            'source_uri' => $sourceUri,
            'source_url' => $sourceUrl,
            'reference_asset_id' => $referenceAssetId,
            'reference_uri' => $referenceUri,
            'reference_url' => $referenceUrl,
            'selection' => $selection,
            'request' => $request,
        ];
    }

    private static function marketOptions(int $tenantId): array
    {
        return \app\common\service\power\MarketSeedSvcAppRuntimeService::options($tenantId);
    }

    private static function refreshRunningTask(array $task): void
    {
        if (!in_array((string)($task['status'] ?? ''), ['pending', 'running'], true) || (int)($task['consumption_id'] ?? 0) <= 0) {
            return;
        }
        try {
            MarketApplicationApiRuntimeService::refresh((int)$task['consumption_id']);
        } catch (\Throwable) {
        }
    }

    private static function assertAvailable(int $tenantId): void
    {
        if (AppAccessService::assertTenantCanUse($tenantId, self::APP_CODE) !== null) {
            throw new Exception('音乐翻唱应用未开通或未上架');
        }
        $config = self::config($tenantId);
        if ((int)$config['status'] !== 1) {
            throw new Exception('音乐翻唱应用已停用');
        }
        if (empty($config['options'])) {
            throw new Exception('暂无上架的音乐翻唱应用 API');
        }
    }

    private static function dependencies(array $options): array
    {
        return [
            'items' => [[
                'app_code' => 'power_market',
                'name' => '音乐翻唱应用 API',
                'required_for' => '音乐翻唱',
                'installed' => true,
                'tenant_enabled' => true,
                'channel_ready' => $options !== [],
                'ready' => $options !== [],
                'message' => $options !== [] ? '可用' : '暂无上架的音乐翻唱应用 API',
            ]],
            'ready' => $options !== [],
        ];
    }

    private static function formatTask(array $row, int $tenantId): array
    {
        $results = AigcMusicCoverResult::where(['tenant_id' => $tenantId, 'task_id' => (int)$row['id'], 'delete_time' => 0])->order('id', 'asc')->select()->toArray();
        foreach ($results as &$result) {
            $result['audio_url'] = (string)($result['audio_url'] ?? '') ?: FileService::getFileUrlByStorage($result['audio_uri'], $result['storage_scope'] ?? '', $result['storage_engine'] ?? '', $result['storage_domain'] ?? '');
            $result['download_url'] = $result['audio_url'];
        }
        unset($result);
        $row['task_id'] = (int)$row['id'];
        $row['results'] = $results;
        $row['status_label'] = match ((string)($row['status'] ?? '')) {
            'success' => '已完成',
            'failed' => '失败',
            'canceled' => '已取消',
            'pending' => '排队中',
            default => '处理中',
        };
        return $row;
    }

    private static function response(AigcMusicCoverTask $task, array $quote): array
    {
        $formatted = self::formatTask($task->toArray(), (int)$task['tenant_id']);
        return [
            'task_id' => (int)$task['id'],
            'status' => (string)$task['status'],
            'provider_task_id' => (string)($task['provider_task_id'] ?? ''),
            'error' => (string)($task['error'] ?? ''),
            'results' => $formatted['results'],
            'estimate' => array_merge($quote, [
                'quantity' => 1,
                'ref_audio' => (string)($task['reference_url'] ?? ''),
                'source_audio' => (string)($task['source_url'] ?? ''),
                'display_points' => (float)$quote['user_charge_points'],
            ]),
        ];
    }

    private static function defaults(): array
    {
        return ['status' => 1, 'config_json' => []];
    }

    private static function validUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            && trim((string)($parts['host'] ?? '')) !== '';
    }

    /** @return array{0:string,1:string} */
    private static function assetInput(int $tenantId, int $assetId, string $label): array
    {
        $asset = AigcMusicAsset::where([
            'tenant_id' => $tenantId,
            'id' => $assetId,
            'status' => 1,
            'delete_time' => 0,
        ])->findOrEmpty();
        if ($asset->isEmpty()) {
            throw new Exception($label . '不存在或已删除');
        }
        $uri = (string)$asset['uri'];
        $url = (string)($asset['url'] ?: AigcMusicAssetService::assetUrl($asset->toArray()));
        return [$uri, $url];
    }

    private static function timestampValue(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int)$value;
        }
        $timestamp = strtotime((string)$value);
        return $timestamp === false ? 0 : $timestamp;
    }
}
