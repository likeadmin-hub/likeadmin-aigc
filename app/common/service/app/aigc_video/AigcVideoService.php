<?php

namespace app\common\service\app\aigc_video;

use app\common\model\app\aigc_video\AigcVideoConfig;
use app\common\model\app\aigc_video\AigcVideoBilling;
use app\common\model\app\aigc_video\AigcVideoQuota;
use app\common\model\app\aigc_video\AigcVideoResult;
use app\common\model\app\aigc_video\AigcVideoSensitiveWord;
use app\common\model\app\aigc_video\AigcVideoTask;
use app\common\service\app\AppCaseService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\FileService;
use app\common\service\point\PointService;
use app\common\service\storage\StorageConfigService;
use app\common\service\power\MarketVideoAppRuntimeService;
use app\common\service\power\MarketVideoModelRuntimeService;
use Exception;
use think\facade\Db;

class AigcVideoService
{
    public const APP_CODE = 'aigc_video';
    private const DUPLICATE_WINDOW_SECONDS = 6;

    public static function config(int $tenantId): array
    {
        $config = AigcVideoConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($config->isEmpty()) {
            return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, [
                'provider_mode' => 'platform',
                'provider' => 'mock',
                'model' => 'mock-video',
                'status' => 1,
                'config_json' => [],
                'option_config' => self::marketOptionConfig($tenantId),
            ]);
        }
        $data = $config->toArray();
        $data['option_config'] = self::marketOptionConfig($tenantId);
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, $data);
    }

    public static function estimate(int $tenantId, array $params): array
    {
        return self::marketQuote($tenantId, $params);
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        $row = AigcVideoConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $current = $row->isEmpty() ? [] : $row->toArray();
        $data = [
            'tenant_id' => $tenantId,
            'provider_mode' => array_key_exists('provider_mode', $params) ? $params['provider_mode'] : ($current['provider_mode'] ?? 'platform'),
            'provider' => array_key_exists('provider', $params) ? $params['provider'] : ($current['provider'] ?? 'mock'),
            'model' => array_key_exists('model', $params) ? $params['model'] : ($current['model'] ?? 'mock-video'),
            'config_json' => array_key_exists('config_json', $params) && is_array($params['config_json']) ? $params['config_json'] : ($current['config_json'] ?? []),
            'status' => array_key_exists('status', $params) ? $params['status'] : ($current['status'] ?? 1),
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcVideoConfig::create($data);
            return;
        }
        $row->save($data);
    }

    public static function generate(int $tenantId, int $userId, array $params): array
    {
        return self::generateMarket($tenantId, $userId, $params);
    }

    public static function generateWithBillingOverride(int $tenantId, int $userId, array $params, array $billingOverride): array
    {
        return self::generateInternal($tenantId, $userId, $params, $billingOverride);
    }

    /**
     * All new standalone video tasks use the power-market runtime. The legacy
     * generateInternal path remains only for historical diagnostics and old
     * task completion, never for a new public submission.
     */
    public static function generateMarket(int $tenantId, int $userId, array $params): array
    {
        $params = self::sanitizeUtf8Payload($params);
        $prompt = trim((string)($params['prompt'] ?? ''));
        if ($prompt === '') {
            throw new Exception('请输入提示词');
        }
        self::checkSensitiveWords($tenantId, $prompt);
        $referenceAssets = AigcVideoReferenceAssetService::normalize($params);
        $params['reference_assets'] = $referenceAssets;
        $params['reference_images'] = AigcVideoReferenceAssetService::images($referenceAssets);
        $selection = self::marketSelection($params);
        $quote = self::marketQuote($tenantId, $selection + $params);
        $duplicate = self::findRecentMarketDuplicateTask($tenantId, $userId, $prompt, $selection, $params);
        if ($duplicate !== null) {
            return self::marketDuplicateResponse($duplicate, $tenantId, $userId);
        }
        self::checkQuota($tenantId, $userId, 1);
        $now = time();
        $task = AigcVideoTask::create([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'prompt' => $prompt,
            'negative_prompt' => (string)($params['negative_prompt'] ?? ''), 'reference_images' => $params['reference_images'], 'reference_assets' => $referenceAssets,
            'style' => (string)($params['style'] ?? 'general'),
            'channel' => (string)($selection['model_id'] ?? $selection['channel'] ?? ''),
            'quality' => (string)($selection['resolution'] ?? $selection['quality'] ?? ''), 'ratio' => (string)($params['ratio'] ?? ''),
            'duration' => max(1, (int)($params['duration'] ?? 0)), 'quantity' => 1,
            'tenant_cost_points' => (float)$quote['tenant_cost_points'], 'user_charge_points' => (float)$quote['user_charge_points'],
            'provider' => 'power_market', 'model' => (string)($selection['model_id'] ?? ''), 'provider_task_id' => '',
            'status' => 'running', 'error' => '', 'app_task_id' => 0, 'consumption_id' => 0,
            'market_product_id' => (int)$quote['market_product_id'], 'market_sku_id' => (int)$quote['market_sku_id'],
            'model_json' => $selection, 'pricing_snapshot' => (array)$quote['market_snapshot'],
            'create_time' => $now, 'update_time' => $now, 'finish_time' => 0, 'delete_time' => 0,
        ]);
        try {
            $runtime = self::marketRuntime($selection);
            $reserve = $runtime::reserve($tenantId, $userId, self::APP_CODE, 'video_generate', 'aigc_video_task', (string)$task['id'], $selection, $params);
            $task->save([
                'app_task_id' => (int)$reserve['app_task_id'], 'consumption_id' => (int)$reserve['consumption_id'],
                'market_product_id' => (int)($reserve['market_snapshot']['product_id'] ?? 0), 'market_sku_id' => (int)($reserve['market_snapshot']['sku_id'] ?? 0),
                'model_json' => $selection, 'pricing_snapshot' => (array)$reserve['market_snapshot'], 'update_time' => time(),
            ]);
            $runtime::linkBusinessTask((int)$reserve['app_task_id'], (int)$task['id']);
            $result = $runtime::submit((int)$reserve['consumption_id'], $params);
            return self::applyMarketVideoResult($task, $result, $selection);
        } catch (\Throwable $e) {
            $current = AigcVideoTask::findOrEmpty((int)$task['id']);
            if (!$current->isEmpty() && (int)$current['consumption_id'] > 0) {
                try { self::marketRuntime($selection)::fail((int)$current['consumption_id'], $e->getMessage(), 'submit_failed'); } catch (\Throwable) {}
            }
            $task->save(['status' => 'failed', 'error' => self::friendlyRefreshError($e->getMessage()), 'finish_time' => time(), 'update_time' => time()]);
            throw $e instanceof Exception ? $e : new Exception('视频生成失败');
        }
    }

    /** Explicit worker/manual refresh hook. It is intentionally not called by list/detail reads. */
    public static function refreshMarketTask(int $tenantId, int $taskId, int $userId = 0): array
    {
        $query = AigcVideoTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
        if ($userId > 0) $query->where('user_id', $userId);
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) throw new Exception('任务不存在');
        if ((int)$task['consumption_id'] <= 0 || (string)$task['provider'] !== 'power_market') return self::taskDetail($tenantId, $taskId, $userId);
        $result = self::marketRuntime((array)($task['model_json'] ?: []))::refresh((int)$task['consumption_id']);
        self::applyMarketVideoResult($task, $result, (array)($task['model_json'] ?: []));
        return self::taskDetail($tenantId, $taskId, $userId);
    }

    private static function applyMarketVideoResult(AigcVideoTask $task, array $result, array $selection): array
    {
        $status = (string)($result['status'] ?? 'running');
        $task->save(['provider_task_id' => (string)($result['provider_task_id'] ?? $task['provider_task_id']), 'update_time' => time()]);
        if ($status === 'failed') {
            $task->save(['status' => 'failed', 'error' => (string)($result['error'] ?? '视频生成失败'), 'finish_time' => time(), 'update_time' => time()]);
            return ['task_id' => (int)$task['id'], 'status' => 'failed', 'results' => []];
        }
        $videos = (array)($result['videos'] ?? []);
        if ($videos === []) return ['task_id' => (int)$task['id'], 'status' => 'running', 'results' => []];
        $rows = self::finishMarketTaskWithVideos($task, $videos, $selection);
        return ['task_id' => (int)$task['id'], 'status' => 'success', 'results' => $rows];
    }

    private static function finishMarketTaskWithVideos(AigcVideoTask $task, array $videos, array $selection): array
    {
        $rows = [];
        Db::transaction(function () use ($task, $videos, &$rows) {
            $locked = AigcVideoTask::where('id', (int)$task['id'])->lock(true)->findOrEmpty();
            if ($locked->isEmpty()) throw new Exception('视频任务不存在');
            $existing = self::existingResultRows((int)$locked['tenant_id'], (int)$locked['user_id'], (int)$locked['id']);
            if ($existing !== []) { $rows = $existing; return; }
            $storage = StorageConfigService::getEffectiveConfig((int)$locked['tenant_id']);
            foreach ($videos as $video) {
                if (!is_array($video) || empty($video['video_uri'])) continue;
                $row = AigcVideoResult::create([
                    'tenant_id' => (int)$locked['tenant_id'], 'task_id' => (int)$locked['id'], 'user_id' => (int)$locked['user_id'],
                    'channel' => (string)$locked['channel'], 'quality' => (string)$locked['quality'], 'ratio' => (string)$locked['ratio'],
                    'video_uri' => (string)$video['video_uri'], 'storage_scope' => (string)($video['storage_scope'] ?? $storage['scope']), 'storage_engine' => (string)($video['storage_engine'] ?? $storage['default']), 'storage_domain' => (string)($video['storage_domain'] ?? StorageConfigService::getEffectiveDomain((int)$locked['tenant_id'])),
                    'width' => (int)($video['width'] ?? 0), 'height' => (int)($video['height'] ?? 0),
                    'tenant_cost_points' => (float)$locked['tenant_cost_points'], 'user_charge_points' => (float)$locked['user_charge_points'], 'provider_task_id' => (string)$locked['provider_task_id'], 'create_time' => time(), 'delete_time' => 0,
                ]);
                $item = $row->toArray(); $item['video_url'] = FileService::getFileUrlByStorage($item['video_uri'], $item['storage_scope'], $item['storage_engine'], $item['storage_domain']); $rows[] = $item;
            }
            if ($rows === []) throw new Exception('上游未返回可保存的视频');
            $locked->save(['status' => 'success', 'error' => '', 'finish_time' => time(), 'update_time' => time()]);
            self::consumeQuota((int)$locked['tenant_id'], (int)$locked['user_id'], count($rows));
        });
        return $rows;
    }

    private static function marketOptionConfig(int $tenantId): array
    {
        $models = MarketVideoModelRuntimeService::options($tenantId);
        $apps = MarketVideoAppRuntimeService::options($tenantId);
        return ['market_mode' => true, 'models' => $models, 'applications' => $apps, 'channels' => array_merge($models, $apps), 'defaults' => ['channel' => (string)(($models[0] ?? $apps[0] ?? [])['id'] ?? '')], 'quantity_options' => [1]];
    }

    private static function marketQuote(int $tenantId, array $params): array
    {
        $runtime = self::marketRuntime(self::marketSelection($params));
        return $runtime::quote($tenantId, self::marketSelection($params));
    }

    private static function marketSelection(array $params): array
    {
        $nested = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $selection = array_merge($nested, $params);
        if (empty($selection['model_id']) && !empty($selection['channel'])) $selection['model_id'] = $selection['channel'];
        if (empty($selection['resolution']) && !empty($selection['quality'])) $selection['resolution'] = $selection['quality'];
        $value = (string)($selection['model_id'] ?? $selection['video_model_id'] ?? $selection['channel'] ?? '');
        if (!str_starts_with($value, 'market_video_model:') && !str_starts_with($value, 'market_video_app:') && empty($selection['market_sku_id']) && empty($selection['market_product_id'])) {
            throw new Exception('请选择算力市场视频模型');
        }
        return $selection;
    }

    private static function marketRuntime(array $selection): string
    {
        $value = implode('|', array_map('strval', [$selection['resource_type'] ?? '', $selection['model_id'] ?? '', $selection['channel'] ?? '']));
        return str_contains($value, 'app_api') || str_contains($value, 'market_video_app:') ? MarketVideoAppRuntimeService::class : MarketVideoModelRuntimeService::class;
    }

    private static function findRecentMarketDuplicateTask(int $tenantId, int $userId, string $prompt, array $selection, array $params): ?AigcVideoTask
    {
        $skuId = (int)($selection['market_sku_id'] ?? 0);
        if ($skuId <= 0) {
            return null;
        }
        $rows = AigcVideoTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'prompt' => $prompt,
            'market_sku_id' => $skuId,
            'delete_time' => 0,
        ])->where('create_time', '>=', time() - self::DUPLICATE_WINDOW_SECONDS)
            ->order('id', 'desc')->limit(3)->select();
        $signature = self::referenceAssetSignature((array)$params['reference_assets'], (array)$params['reference_images']);
        foreach ($rows as $row) {
            if (in_array((string)$row['status'], ['failed', 'canceled'], true)) {
                continue;
            }
            if ((string)$row['ratio'] !== (string)($params['ratio'] ?? '') || (int)$row['duration'] !== (int)($params['duration'] ?? 0)) {
                continue;
            }
            if (self::referenceAssetSignature((array)($row['reference_assets'] ?: []), (array)($row['reference_images'] ?: [])) === $signature) {
                return $row;
            }
        }
        return null;
    }

    private static function marketDuplicateResponse(AigcVideoTask $task, int $tenantId, int $userId): array
    {
        $status = (string)($task['status'] ?: 'running');
        $response = ['task_id' => (int)$task['id'], 'status' => $status, 'results' => []];
        if ($status === 'success') {
            $response['results'] = self::existingResultRows($tenantId, $userId, (int)$task['id']);
        }
        if ($status === 'failed') {
            $response['error'] = (string)$task['error'];
        }
        return $response;
    }

    private static function generateInternal(int $tenantId, int $userId, array $params, array $billingOverride = []): array
    {
        $params = self::sanitizeUtf8Payload($params);
        $prompt = trim((string)($params['prompt'] ?? ''));
        if ($prompt === '') {
            throw new Exception('请输入提示词');
        }
        $selection = AigcVideoChannelService::resolveSelection($tenantId, $params);
        $quantity = AigcVideoChannelService::normalizeQuantity($params['quantity'] ?? 1);
        AigcVideoChannelService::assertChannelQuantity($selection['channel'], $quantity);
        $referenceAssets = AigcVideoReferenceAssetService::normalize($params);
        $generationMethod = self::normalizeGenerationMethod(
            $selection['channel'],
            $params['generation_method'] ?? $params['generationMethod'] ?? null
        );
        if ($generationMethod !== '') {
            $referenceAssets = array_map(static function (array $asset) use ($generationMethod): array {
                $asset['generation_method'] = $generationMethod;
                return $asset;
            }, $referenceAssets);
        }
        $referenceImages = AigcVideoReferenceAssetService::images($referenceAssets);
        self::assertGenerationMethodAssets($generationMethod, $referenceAssets);
        self::assertReferenceAssetsSupported($selection['channel'], $referenceAssets);
        $duration = AigcVideoChannelService::normalizeGenerateDuration($selection['channel'], $referenceAssets, $params['duration'] ?? null);
        $mode = self::normalizeVideoMode($selection['channel'], $params['mode'] ?? null);
        $selectedRatio = (string)($params['ratio'] ?? $selection['spec']['ratio']);
        self::checkSensitiveWords($tenantId, $prompt);
        $duplicateTask = self::findRecentDuplicateTask($tenantId, $userId, [
            'prompt' => $prompt,
            'negative_prompt' => (string)($params['negative_prompt'] ?? ''),
            'style' => (string)($params['style'] ?? 'general'),
            'channel' => (string)$selection['channel']['code'],
            'quality' => (string)$selection['spec']['quality'],
            'ratio' => $selectedRatio,
            'duration' => $duration,
            'mode' => $mode,
            'quantity' => $quantity,
            'generation_method' => $generationMethod,
            'reference_assets' => $referenceAssets,
        ]);
        if ($duplicateTask) {
            return self::buildDuplicateGenerateResponse($duplicateTask, $tenantId, $userId);
        }
        $estimate = AigcVideoChannelService::estimate($tenantId, array_merge($params, [
            'channel' => $selection['channel']['code'],
            'quality' => $selection['spec']['quality'],
            'ratio' => $selectedRatio,
            'duration' => $duration,
            'quantity' => $quantity,
        ]));
        $estimate = self::applyBillingOverride($estimate, $quantity, $billingOverride);
        self::checkQuota($tenantId, $userId, $quantity);
        PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);

        $taskData = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'prompt' => $prompt,
            'negative_prompt' => $params['negative_prompt'] ?? '',
            'reference_images' => $referenceImages,
            'reference_assets' => $referenceAssets,
            'style' => $params['style'] ?? 'general',
            'channel' => $selection['channel']['code'],
            'quality' => $selection['spec']['quality'],
            'ratio' => $selectedRatio,
            'quantity' => $quantity,
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
            'provider' => $selection['channel']['provider'],
            'model' => self::taskModelName($selection, $referenceAssets),
            'status' => 'running',
            'error' => '',
            'delete_time' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ];
        if (AigcVideoTask::hasDurationColumn()) {
            $taskData['duration'] = $duration;
        }
        $task = AigcVideoTask::create($taskData);

        $providerName = (string)$selection['channel']['provider'];
        $provider = self::providerFor($providerName);
        $channelConfig = array_merge($selection['channel']['config_json'] ?? [], [
            'model' => $selection['channel']['model'],
            'tenant_id' => $tenantId,
            'user_id' => $userId,
        ]);
        if (($selection['channel']['code'] ?? '') === 'seedance' && empty(($selection['spec']['provider_params_json'] ?? [])['model'])) {
            unset($channelConfig['model']);
        }
        if (self::isAsyncProvider($providerName)) {
            $channelConfig['poll_attempts'] = 0;
        }
        $result = $provider->generate(new AigcVideoGenerateRequest(
            $prompt,
            (string)($params['negative_prompt'] ?? ''),
            (string)($params['style'] ?? 'general'),
            $selection['channel']['code'],
            $selection['spec']['quality'],
            $selectedRatio,
            $quantity,
            $referenceImages,
            $referenceAssets,
            $selection['spec'],
            self::providerParamsForSelection($selection, [
                'duration' => $duration,
                'mode' => $mode,
                'generation_method' => $generationMethod,
                'first_frame_image' => self::referenceAssetUrlByRole($referenceAssets, 'first_frame_image'),
                'last_frame_image' => self::referenceAssetUrlByRole($referenceAssets, 'last_frame_image'),
                'aspect_ratio' => $selectedRatio,
                'callback_url' => $params['callback_url'] ?? null,
            ]),
            $channelConfig
        ));

        $task->provider_task_id = $result->providerTaskId;
        $task->update_time = time();
        $task->save();

        if (!$result->success) {
            $task->status = 'failed';
            $task->error = $result->error;
            $task->finish_time = time();
            $task->save();
            return ['task_id' => $task['id'], 'results' => [], 'status' => 'failed', 'error' => $task->error];
        }

        if (empty($result->videos) && $result->providerTaskId !== '') {
            return ['task_id' => $task['id'], 'results' => [], 'status' => 'running'];
        }

        $rows = self::finishTaskWithVideos($task, $selection, $estimate, $result->videos);

        return ['task_id' => $task['id'], 'results' => $rows];
    }

    private static function referenceImageUrls(array $referenceImages, array $referenceAssets): array
    {
        $urls = [];
        $append = static function (string $value) use (&$urls): void {
            $value = trim($value);
            if ($value === '') {
                return;
            }
            if (!str_starts_with($value, 'http://') && !str_starts_with($value, 'https://') && !str_starts_with($value, 'data:')) {
                $value = FileService::getFileUrl($value);
            }
            if ($value !== '' && !in_array($value, $urls, true)) {
                $urls[] = $value;
            }
        };

        foreach ($referenceAssets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            if (($asset['type'] ?? 'image') !== 'image') {
                continue;
            }
            $append(AigcVideoReferenceAssetService::publicUrl($asset));
        }
        foreach ($referenceImages as $image) {
            $append((string)$image);
        }
        return $urls;
    }

    private static function applyBillingOverride(array $estimate, int $quantity, array $billingOverride = []): array
    {
        if (!$billingOverride) {
            return $estimate;
        }
        $quantity = max(1, $quantity);
        if (array_key_exists('tenant_cost_points', $billingOverride)) {
            $tenantTotal = max(0, round((float)$billingOverride['tenant_cost_points'], 2));
            $estimate['tenant_cost_points'] = $tenantTotal;
            $estimate['platform_unit_cost'] = round($tenantTotal / $quantity, 2);
        }
        if (array_key_exists('user_charge_points', $billingOverride)) {
            $userTotal = max(0, round((float)$billingOverride['user_charge_points'], 2));
            $estimate['user_charge_points'] = $userTotal;
            $estimate['tenant_unit_price'] = round($userTotal / $quantity, 2);
        }
        return $estimate;
    }

    public static function taskLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        $query = AigcVideoTask::alias('t')
            ->leftJoin('user u', 'u.id = t.user_id AND u.tenant_id = t.tenant_id')
            ->field('t.*,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
            ->where('t.tenant_id', $tenantId)
            ->where('t.delete_time', 0)
            ->order('t.id', 'desc');
        if ($userId > 0) {
            $query->where('t.user_id', $userId);
        }
        $taskId = (int)($params['task_id'] ?? $params['id'] ?? 0);
        if ($taskId > 0) {
            $query->where('t.id', $taskId);
        }
        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $query->where('t.status', $status);
        }
        $userKeyword = trim((string)($params['user_keyword'] ?? ''));
        if ($userKeyword !== '') {
            $query->where(function ($query) use ($userKeyword) {
                $query->whereLike('u.nickname', '%' . $userKeyword . '%')
                    ->whereOrLike('u.account', '%' . $userKeyword . '%')
                    ->whereOrLike('u.mobile', '%' . $userKeyword . '%');
                if (ctype_digit($userKeyword)) {
                    $query->whereOr('t.user_id', (int)$userKeyword);
                }
            });
        }
        $usePage = isset($params['page_no']) || isset($params['page_size']);
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['page_size'] ?? 15)));
        $count = $usePage ? (int)(clone $query)->count() : 0;
        if ($usePage) {
            $query->limit(($pageNo - 1) * $pageSize, $pageSize);
        } else {
            $query->limit(100);
        }
        $rows = $query->select()->toArray();
        $taskIds = array_values(array_unique(array_filter(array_column($rows, 'id'))));
        $resultMap = [];
        if (!empty($taskIds)) {
            $resultRows = AigcVideoResult::where('tenant_id', $tenantId)
                ->where('delete_time', 0)
                ->whereIn('task_id', $taskIds)
                ->order('id', 'asc')
                ->select()
                ->toArray();
            foreach ($resultRows as $result) {
                $result['video_url'] = FileService::getFileUrlByStorage(
                    $result['video_uri'],
                    $result['storage_scope'] ?? '',
                    $result['storage_engine'] ?? '',
                    $result['storage_domain'] ?? ''
                );
                $resultMap[(int)$result['task_id']][] = $result;
            }
        }
        foreach ($rows as &$row) {
            $row['task_id'] = (int)$row['id'];
            $results = $resultMap[(int)$row['id']] ?? [];
            $first = $results[0] ?? [];
            $row['results'] = $results;
            $row['result_count'] = count($results);
            $row['result_id'] = (int)($first['id'] ?? 0);
            $row['video_uri'] = (string)($first['video_uri'] ?? '');
            $row['video_url'] = (string)($first['video_url'] ?? '');
            $row['width'] = (int)($first['width'] ?? 0);
            $row['height'] = (int)($first['height'] ?? 0);
            $row['reference_image_urls'] = self::referenceImageUrls(
                (array)($row['reference_images'] ?: []),
                (array)($row['reference_assets'] ?: [])
            );
        }
        if ($usePage) {
            return [
                'lists' => $rows,
                'count' => $count,
                'page_no' => $pageNo,
                'page_size' => $pageSize,
            ];
        }
        return $rows;
    }

    public static function taskDetail(int $tenantId, int $taskId, int $userId = 0): array
    {
        $query = AigcVideoTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        $data = $task->toArray();
        $data['results'] = self::existingResultRows($tenantId, $userId, $taskId);
        $data['reference_image_urls'] = self::referenceImageUrls(
            (array)($data['reference_images'] ?: []),
            (array)($data['reference_assets'] ?: [])
        );
        return $data;
    }

    public static function retryTask(int $tenantId, int $taskId): array
    {
        $task = AigcVideoTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return self::generate($tenantId, (int)$task['user_id'], [
            'prompt' => $task['prompt'],
            'negative_prompt' => $task['negative_prompt'],
            'reference_images' => $task['reference_images'] ?: [],
            'reference_assets' => $task['reference_assets'] ?: [],
            'style' => $task['style'],
            'model_id' => (string)($task['model_json']['model_id'] ?? $task['channel']),
            'market_product_id' => (int)($task['market_product_id'] ?? 0),
            'market_sku_id' => (int)($task['market_sku_id'] ?? 0),
            'resource_type' => (string)($task['model_json']['resource_type'] ?? ''),
            'quality' => $task['quality'],
            'ratio' => $task['ratio'],
            'duration' => (int)($task['duration'] ?? 0),
            'quantity' => $task['quantity'],
        ]);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = AigcVideoTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        $task->delete_time = time();
        $task->update_time = time();
        $task->save();
        AigcVideoResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update([
            'delete_time' => time(),
        ]);
    }

    public static function resultLists(int $tenantId, int $userId = 0, int $taskId = 0, string $status = ''): array
    {
        $query = AigcVideoTask::where('tenant_id', $tenantId)->where('delete_time', 0)->order('id', 'desc');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($taskId > 0) {
            $query->where('id', $taskId);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        $tasks = $query->limit(50)->select()->toArray();
        $taskIds = array_values(array_unique(array_filter(array_column($tasks, 'id'))));
        $resultMap = [];
        if (!empty($taskIds)) {
            $resultRows = AigcVideoResult::where('tenant_id', $tenantId)
                ->where('delete_time', 0)
                ->whereIn('task_id', $taskIds)
                ->order('id', 'asc')
                ->select()
                ->toArray();
            foreach ($resultRows as $row) {
                $row['video_url'] = FileService::getFileUrlByStorage(
                    $row['video_uri'],
                    $row['storage_scope'] ?? '',
                    $row['storage_engine'] ?? '',
                    $row['storage_domain'] ?? ''
                );
                $resultMap[(int)$row['task_id']][] = $row;
            }
        }
        foreach ($tasks as &$task) {
            $results = $resultMap[(int)$task['id']] ?? [];
            $task['task_id'] = (int)$task['id'];
            $task['results'] = $results;
            $task['result_count'] = count($results);
            $first = $results[0] ?? [];
            $task['result_id'] = (int)($first['id'] ?? 0);
            $task['video_uri'] = (string)($first['video_uri'] ?? '');
            $task['video_url'] = (string)($first['video_url'] ?? '');
            $task['width'] = (int)($first['width'] ?? 0);
            $task['height'] = (int)($first['height'] ?? 0);
            $task['reference_image_urls'] = self::referenceImageUrls(
                (array)($task['reference_images'] ?: []),
                (array)($task['reference_assets'] ?: [])
            );
        }
        return $tasks;
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = AigcVideoResult::where(['tenant_id' => $tenantId, 'id' => $resultId]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $result = $query->findOrEmpty();
        if ($result->isEmpty()) {
            throw new Exception('作品不存在');
        }
        $result->delete_time = time();
        $result->save();
    }

    public static function saveCaseFromTask(int $tenantId, int $taskId, array $params = []): array
    {
        $task = self::taskDetail($tenantId, $taskId);
        if (($task['status'] ?? '') !== 'success') {
            throw new Exception('只有已完成任务可以设为案例');
        }
        $results = $task['results'] ?? [];
        $first = $results[0] ?? [];
        $videoUri = (string)($first['video_uri'] ?? $first['video_url'] ?? '');
        if ($videoUri === '') {
            throw new Exception('任务暂无可用作品');
        }

        $title = trim((string)($params['title'] ?? ''));
        if ($title === '') {
            $title = mb_substr((string)$task['prompt'], 0, 20) ?: '视频案例';
        }

        return AppCaseService::save($tenantId, self::APP_CODE, [
            'title' => $title,
            'prompt' => $task['prompt'] ?? '',
            'media_type' => 'video',
            'cover_uri' => (string)($params['cover_uri'] ?? $params['cover_url'] ?? ''),
            'media_uri' => $videoUri,
            'reference_images' => $task['reference_images'] ?: [],
            'config_json' => [
                'channel' => $task['channel'] ?? '',
                'model' => $task['model'] ?? '',
                'quantity' => $task['quantity'] ?? 1,
                'ratio' => $task['ratio'] ?? '',
                'quality' => $task['quality'] ?? '',
            ],
            'source_task_id' => (int)$task['id'],
            'source_result_id' => (int)($first['id'] ?? 0),
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? 0),
        ]);
    }

    public static function quotaLists(int $tenantId, array $params = []): array
    {
        return self::paginateRows(AigcVideoQuota::where('tenant_id', $tenantId)->order('id', 'desc'), $params, 100);
    }

    public static function saveQuota(int $tenantId, array $params): void
    {
        $userId = (int)($params['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new Exception('请选择用户');
        }
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'total_quota' => max(0, (int)($params['total_quota'] ?? 0)),
            'used_quota' => max(0, (int)($params['used_quota'] ?? 0)),
            'expire_time' => (int)($params['expire_time'] ?? 0),
            'update_time' => time(),
        ];
        $row = AigcVideoQuota::where(['tenant_id' => $tenantId, 'user_id' => $userId])->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcVideoQuota::create($data);
            return;
        }
        $row->save($data);
    }

    public static function sensitiveWordLists(int $tenantId, array $params = []): array
    {
        return self::paginateRows(AigcVideoSensitiveWord::where('tenant_id', $tenantId)->order('id', 'desc'), $params, 200);
    }

    private static function paginateRows($query, array $params, int $defaultLimit = 100): array
    {
        $usePage = isset($params['page_no']) || isset($params['page_size']);
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['page_size'] ?? 15)));
        if ($usePage) {
            $count = (int)(clone $query)->count();
            return [
                'lists' => $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray(),
                'count' => $count,
                'page_no' => $pageNo,
                'page_size' => $pageSize,
            ];
        }
        return $query->limit($defaultLimit)->select()->toArray();
    }

    public static function saveSensitiveWord(int $tenantId, array $params): void
    {
        $word = trim((string)($params['word'] ?? ''));
        if ($word === '') {
            throw new Exception('请输入敏感词');
        }
        $id = (int)($params['id'] ?? 0);
        $data = [
            'tenant_id' => $tenantId,
            'word' => $word,
            'status' => (int)($params['status'] ?? 1),
            'update_time' => time(),
        ];
        if ($id > 0) {
            $row = AigcVideoSensitiveWord::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
            if ($row->isEmpty()) {
                throw new Exception('敏感词不存在');
            }
            $row->save($data);
            return;
        }
        $data['create_time'] = time();
        AigcVideoSensitiveWord::create($data);
    }

    public static function stat(int $tenantId = 0): array
    {
        $task = AigcVideoTask::where([])->where('delete_time', 0);
        $result = AigcVideoResult::where([])->where('delete_time', 0);
        if ($tenantId > 0) {
            $task->where('tenant_id', $tenantId);
            $result->where('tenant_id', $tenantId);
        }
        $taskTotal = (clone $task)->count();
        $resultTotal = (clone $result)->count();
        return [
            'task_total' => $taskTotal,
            'task_success' => (clone $task)->where('status', 'success')->count(),
            'task_failed' => (clone $task)->where('status', 'failed')->count(),
            'result_total' => $resultTotal,
            'quota_total' => $tenantId > 0 ? AigcVideoQuota::where('tenant_id', $tenantId)->sum('total_quota') : AigcVideoQuota::where([])->sum('total_quota'),
            'quota_used' => $tenantId > 0 ? AigcVideoQuota::where('tenant_id', $tenantId)->sum('used_quota') : AigcVideoQuota::where([])->sum('used_quota'),
            'tenant_cost_points' => $tenantId > 0 ? AigcVideoBilling::where('tenant_id', $tenantId)->sum('tenant_cost_points') : AigcVideoBilling::where([])->sum('tenant_cost_points'),
            'user_charge_points' => $tenantId > 0 ? AigcVideoBilling::where('tenant_id', $tenantId)->sum('user_charge_points') : AigcVideoBilling::where([])->sum('user_charge_points'),
        ];
    }

    private static function checkSensitiveWords(int $tenantId, string $prompt): void
    {
        $words = AigcVideoSensitiveWord::where(['tenant_id' => $tenantId, 'status' => 1])->column('word');
        foreach ($words as $word) {
            if ($word !== '' && str_contains($prompt, $word)) {
                throw new Exception('提示词包含敏感词');
            }
        }
    }

    private static function checkQuota(int $tenantId, int $userId, int $quantity): void
    {
        $quota = AigcVideoQuota::where(['tenant_id' => $tenantId, 'user_id' => $userId])->findOrEmpty();
        if (!$quota->isEmpty() && !empty($quota['expire_time']) && (int)$quota['expire_time'] < time()) {
            throw new Exception('视频额度已过期');
        }
        if (!$quota->isEmpty() && (int)$quota['total_quota'] > 0 && ((int)$quota['used_quota'] + $quantity) > (int)$quota['total_quota']) {
            throw new Exception('视频额度不足');
        }
    }

    private static function consumeQuota(int $tenantId, int $userId, int $quantity): void
    {
        $quota = AigcVideoQuota::where(['tenant_id' => $tenantId, 'user_id' => $userId])->findOrEmpty();
        if ($quota->isEmpty()) {
            return;
        }
        $quota->used_quota = (int)$quota['used_quota'] + $quantity;
        $quota->save();
    }

    private static function refreshRunningTasks(int $tenantId, int $userId = 0, int $taskId = 0, bool $swallowErrors = false): void
    {
        $query = AigcVideoTask::where('tenant_id', $tenantId)
            ->where('status', 'running')
            ->where('delete_time', 0)
            ->where('provider_task_id', '<>', '');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($taskId > 0) {
            $query->where('id', $taskId);
        }
        $tasks = $query->limit(10)->select();
        foreach ($tasks as $task) {
            if (!self::isAsyncProvider((string)$task['provider'])) {
                continue;
            }
            try {
                $selection = AigcVideoChannelService::resolveSelection($tenantId, [
                    'channel' => $task['channel'],
                    'quality' => $task['quality'],
                    'ratio' => $task['ratio'],
                    'duration' => (int)($task['duration'] ?? 0),
                    'quantity' => $task['quantity'],
                ]);
                $provider = self::providerFor((string)$task['provider']);
                if (!method_exists($provider, 'fetchResult')) {
                    continue;
                }
                $result = $provider->fetchResult((string)$task['provider_task_id'], self::buildRequestFromTask($task, $selection));
                if (!$result->success) {
                    if (self::isTransientRefreshError((string)$result->error)) {
                        $task->error = self::friendlyRefreshError((string)$result->error);
                        $task->update_time = time();
                        $task->save();
                        continue;
                    }
                    $task->status = 'failed';
                    $task->error = $result->error ?: '生成失败';
                    $task->finish_time = time();
                    $task->update_time = time();
                    $task->save();
                    continue;
                }
                if (empty($result->videos)) {
                    continue;
                }
                $estimate = [
                    'platform_unit_cost' => (float)$task['tenant_cost_points'] / max(1, (int)$task['quantity']),
                    'tenant_unit_price' => (float)$task['user_charge_points'] / max(1, (int)$task['quantity']),
                ];
                self::finishTaskWithVideos($task, $selection, $estimate, $result->videos);
            } catch (\Throwable $e) {
                if (!$swallowErrors) {
                    throw $e;
                }
                if (self::isPermanentRefreshError($e->getMessage())) {
                    self::markRefreshFailed($task, $e->getMessage() ?: '任务刷新失败');
                }
            }
        }
    }

    private static function safeRefreshRunningTasks(int $tenantId, int $userId = 0, int $taskId = 0): void
    {
        try {
            self::refreshRunningTasks($tenantId, $userId, $taskId, true);
        } catch (\Throwable) {
            // Listing/detail pages must remain readable even if async task polling fails.
        }
    }

    private static function markRefreshFailed(AigcVideoTask $task, string $message): void
    {
        try {
            $task->status = 'failed';
            $task->error = $message;
            $task->finish_time = time();
            $task->update_time = time();
            $task->save();
        } catch (\Throwable) {
            // Never let failure-state persistence break read-only task list APIs.
        }
    }

    private static function isPermanentRefreshError(string $message): bool
    {
        foreach (['暂无可用视频通道', '通道不可用', '不支持所选', '当前时长不支持', '规格'] as $needle) {
            if ($needle !== '' && str_contains($message, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function isTransientRefreshError(string $message): bool
    {
        $message = strtolower(trim($message));
        if ($message === '') {
            return false;
        }
        foreach ([
            'ssl_error_syscall',
            'operation timed out',
            'connection timed out',
            'connection reset',
            'failed to connect',
            'could not resolve host',
            'network is unreachable',
            'temporarily unavailable',
            '供应商网络请求失败',
            '接口请求超时',
            '网络请求失败',
            '连接超时',
            '连接失败',
        ] as $needle) {
            if ($needle !== '' && str_contains($message, strtolower($needle))) {
                return true;
            }
        }
        return false;
    }

    private static function friendlyRefreshError(string $message): string
    {
        return self::isTransientRefreshError($message) ? '上游任务查询暂时失败，稍后自动重试' : $message;
    }

    private static function buildRequestFromTask(AigcVideoTask $task, array $selection): AigcVideoGenerateRequest
    {
        $referenceAssets = (array)($task['reference_assets'] ?: []);
        $generationMethod = self::generationMethodFromAssets($referenceAssets);
        $channelConfig = array_merge($selection['channel']['config_json'] ?? [], [
            'model' => $selection['channel']['model'],
            'tenant_id' => (int)$task['tenant_id'],
            'user_id' => (int)$task['user_id'],
        ]);
        if (($selection['channel']['code'] ?? '') === 'seedance' && empty(($selection['spec']['provider_params_json'] ?? [])['model'])) {
            unset($channelConfig['model']);
        }
        return new AigcVideoGenerateRequest(
            (string)$task['prompt'],
            (string)$task['negative_prompt'],
            (string)$task['style'],
            (string)$task['channel'],
            (string)$task['quality'],
            (string)$task['ratio'],
            (int)$task['quantity'],
            (array)($task['reference_images'] ?: []),
            $referenceAssets,
            $selection['spec'],
            self::providerParamsForSelection($selection, [
                'duration' => AigcVideoChannelService::normalizeGenerateDuration(
                    $selection['channel'],
                    $referenceAssets,
                    (int)($task['duration'] ?? 0) ?: null
                ),
                'generation_method' => $generationMethod,
                'first_frame_image' => self::referenceAssetUrlByRole($referenceAssets, 'first_frame_image'),
                'last_frame_image' => self::referenceAssetUrlByRole($referenceAssets, 'last_frame_image'),
                'aspect_ratio' => (string)($task['ratio'] ?? $selection['spec']['ratio']),
            ]),
            $channelConfig
        );
    }

    private static function findRecentDuplicateTask(int $tenantId, int $userId, array $criteria): ?AigcVideoTask
    {
        if (($criteria['channel'] ?? '') === 'seedance2_pro' || ($criteria['generation_method'] ?? '') !== '') {
            return null;
        }
        $rows = AigcVideoTask::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('delete_time', 0)
            ->where('prompt', $criteria['prompt'])
            ->where('negative_prompt', $criteria['negative_prompt'])
            ->where('style', $criteria['style'])
            ->where('channel', $criteria['channel'])
            ->where('quality', $criteria['quality'])
            ->where('ratio', $criteria['ratio'])
            ->where('quantity', (int)$criteria['quantity'])
            ->where('create_time', '>=', time() - self::DUPLICATE_WINDOW_SECONDS)
            ->order('id', 'desc')
            ->limit(5)
            ->select();
        $referenceSignature = self::referenceAssetSignature((array)($criteria['reference_assets'] ?? []), (array)($criteria['reference_images'] ?? []));
        foreach ($rows as $row) {
            if (in_array((string)$row['status'], ['failed', 'canceled'], true)) {
                continue;
            }
            if (AigcVideoTask::hasDurationColumn() && (int)($row['duration'] ?? 0) !== (int)($criteria['duration'] ?? 0)) {
                continue;
            }
            if (self::referenceAssetSignature((array)($row['reference_assets'] ?: []), (array)($row['reference_images'] ?: [])) !== $referenceSignature) {
                continue;
            }
            return $row;
        }
        return null;
    }

    private static function buildDuplicateGenerateResponse(AigcVideoTask $task, int $tenantId, int $userId): array
    {
        if ((string)$task['status'] === 'running') {
            self::refreshRunningTasks($tenantId, $userId, (int)$task['id']);
        }
        $latest = AigcVideoTask::where(['tenant_id' => $tenantId, 'id' => (int)$task['id']])->findOrEmpty();
        if ($latest->isEmpty()) {
            $latest = $task;
        }
        $status = (string)($latest['status'] ?: 'running');
        $response = [
            'task_id' => (int)$latest['id'],
            'status' => $status,
            'results' => [],
        ];
        if ($status === 'success') {
            $response['results'] = self::existingResultRows($tenantId, $userId, (int)$latest['id']);
        } elseif ($status === 'failed') {
            $response['error'] = (string)$latest['error'];
        }
        return $response;
    }

    private static function referenceImageSignature(array $images): string
    {
        $normalized = [];
        foreach ($images as $image) {
            $image = trim((string)$image);
            if ($image !== '' && !in_array($image, $normalized, true)) {
                $normalized[] = $image;
            }
        }
        sort($normalized);
        return json_encode($normalized, JSON_UNESCAPED_UNICODE);
    }

    private static function assertReferenceAssetsSupported(array $channel, array $assets): void
    {
        if ((string)($channel['code'] ?? '') === 'seedance2_pro') {
            AigcVideoReferenceAssetService::assertSeedance2ProSupported($assets);
        }
        $supported = $channel['supported_asset_types'] ?? ['image'];
        if (!is_array($supported) || empty($supported)) {
            $supported = ['image'];
        }
        $supported = array_map('strval', $supported);
        $counts = ['image' => 0, 'video' => 0, 'audio' => 0];
        foreach ($assets as $asset) {
            $type = (string)($asset['type'] ?? 'image');
            if (!in_array($type, $supported, true)) {
                throw new Exception('当前通道不支持' . self::referenceAssetTypeLabel($type) . '参考素材');
            }
            if (isset($counts[$type])) {
                $counts[$type]++;
            }
        }
        if ($counts['image'] > (int)$channel['max_reference_images']) {
            throw new Exception('参考图数量超出限制');
        }
        if ($counts['video'] > (int)($channel['max_reference_videos'] ?? 0)) {
            throw new Exception('参考视频数量超出限制');
        }
        if ($counts['audio'] > (int)($channel['max_reference_audios'] ?? 0)) {
            throw new Exception('参考音频数量超出限制');
        }
        if ((string)($channel['code'] ?? '') === 'seedance') {
            AigcVideoReferenceAssetService::assertSeedanceSupported($assets);
        }
    }

    private static function referenceAssetTypeLabel(string $type): string
    {
        return match ($type) {
            'video' => '视频',
            'audio' => '音频',
            default => '图片',
        };
    }

    private static function taskModelName(array $selection, array $referenceAssets): string
    {
        $specModel = (string)(($selection['spec']['provider_params_json'] ?? [])['model'] ?? '');
        if ($specModel !== '') {
            return $specModel;
        }
        if (($selection['channel']['code'] ?? '') === 'seedance') {
            foreach ($referenceAssets as $asset) {
                if (($asset['type'] ?? '') === 'video') {
                    return 'seedance-2-video-2-video';
                }
            }
            return 'seedance-2-text-2-video';
        }
        if (($selection['channel']['code'] ?? '') === 'wan') {
            foreach ($referenceAssets as $asset) {
                if (($asset['type'] ?? '') === 'video') {
                    return 'wan2.7-videoedit';
                }
            }
            foreach ($referenceAssets as $asset) {
                if (($asset['type'] ?? '') === 'image') {
                    return 'wan2.7-r2v';
                }
            }
        }
        return (string)($selection['channel']['model'] ?? '');
    }

    private static function referenceAssetSignature(array $assets, array $legacyImages = []): string
    {
        if (empty($assets) && !empty($legacyImages)) {
            $assets = array_map(static fn($image) => [
                'type' => 'image',
                'uri' => (string)$image,
            ], $legacyImages);
        }
        $normalized = [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $type = trim((string)($asset['type'] ?? 'image'));
            $role = trim((string)($asset['role'] ?? ''));
            $generationMethod = trim((string)($asset['generation_method'] ?? ''));
            $uri = trim((string)($asset['uri'] ?? $asset['url'] ?? ''));
            if ($uri !== '') {
                $normalized[] = $type . ':' . $role . ':' . $generationMethod . ':' . $uri;
            }
        }
        return json_encode($normalized, JSON_UNESCAPED_UNICODE);
    }

    private static function providerParamsForSelection(array $selection, array $overrides = []): array
    {
        $params = array_merge(
            $selection['spec']['provider_params_json'] ?? [],
            array_filter($overrides, static fn($value) => $value !== null && $value !== '')
        );
        if (($selection['channel']['code'] ?? '') === 'seedance2_pro') {
            $params['mode'] = self::normalizeVideoMode($selection['channel'], $params['mode'] ?? null);
        }
        return $params;
    }

    private static function normalizeVideoMode(array $channel, $mode): string
    {
        $mode = strtolower(trim((string)$mode));
        if (($channel['code'] ?? '') !== 'seedance2_pro') {
            return $mode;
        }
        return in_array($mode, ['pro', 'fast'], true) ? $mode : 'pro';
    }

    private static function normalizeGenerationMethod(array $channel, $method): string
    {
        $method = strtolower(trim((string)$method));
        if ($method === '') {
            return '';
        }
        if (!in_array($method, ['omni_reference', 'start_end', 'multi_frame'], true)) {
            throw new Exception('不支持的视频生成模式');
        }
        $supported = array_values(array_filter(array_map(
            'strval',
            (array)($channel['generation_modes'] ?? [])
        )));
        if (!in_array($method, $supported, true)) {
            throw new Exception('当前模型不支持所选视频生成模式');
        }
        return $method;
    }

    private static function assertGenerationMethodAssets(string $method, array $assets): void
    {
        if ($method === '') {
            return;
        }
        $images = array_values(array_filter(
            $assets,
            static fn(array $asset): bool => ($asset['type'] ?? '') === 'image'
        ));
        $nonImages = array_values(array_filter(
            $assets,
            static fn(array $asset): bool => ($asset['type'] ?? '') !== 'image'
        ));
        if ($method === 'start_end') {
            $roles = array_column($images, 'role');
            if (
                count($assets) !== 2
                || count($images) !== 2
                || !in_array('first_frame_image', $roles, true)
                || !in_array('last_frame_image', $roles, true)
            ) {
                throw new Exception('首尾帧模式需要分别上传一张首帧和尾帧图片');
            }
        }
        if ($method === 'multi_frame' && (count($images) < 2 || !empty($nonImages))) {
            throw new Exception('智能多帧模式至少需要两张参考图片');
        }
        if ($method === 'omni_reference' && !empty($assets) && empty($images)) {
            $hasVideo = !empty(array_filter(
                $assets,
                static fn(array $asset): bool => ($asset['type'] ?? '') === 'video'
            ));
            if (!$hasVideo) {
                throw new Exception('全能参考不能只使用音频，请同时添加图片或视频');
            }
        }
    }

    private static function generationMethodFromAssets(array $assets): string
    {
        foreach ($assets as $asset) {
            $method = strtolower(trim((string)($asset['generation_method'] ?? '')));
            if (in_array($method, ['omni_reference', 'start_end', 'multi_frame'], true)) {
                return $method;
            }
        }
        return '';
    }

    private static function referenceAssetUrlByRole(array $assets, string $role): string
    {
        foreach ($assets as $asset) {
            if (($asset['role'] ?? '') === $role) {
                return trim((string)($asset['url'] ?? $asset['uri'] ?? ''));
            }
        }
        return '';
    }

    private static function finishTaskWithVideos(AigcVideoTask $task, array $selection, array $estimate, array $videos): array
    {
        $rows = [];
        Db::startTrans();
        try {
            $tenantId = (int)$task['tenant_id'];
            $userId = (int)$task['user_id'];
            $task = AigcVideoTask::where('tenant_id', $tenantId)
                ->where('id', (int)$task['id'])
                ->lock(true)
                ->findOrEmpty();
            if ($task->isEmpty()) {
                throw new Exception('任务不存在');
            }
            $existingRows = self::existingResultRows($tenantId, $userId, (int)$task['id']);
            if ((string)$task['status'] === 'success' || !empty($existingRows)) {
                if ((string)$task['status'] !== 'success' || (string)$task['error'] !== '') {
                    $task->status = 'success';
                    $task->error = '';
                    $task->finish_time = $task['finish_time'] ?: time();
                    $task->update_time = time();
                    $task->save();
                }
                Db::commit();
                return $existingRows;
            }
            $videos = self::uniqueVideos($videos, max(1, (int)$task['quantity']));
            $storage = StorageConfigService::getEffectiveConfig($tenantId);
            foreach ($videos as $index => $video) {
                $row = AigcVideoResult::create([
                    'tenant_id' => $tenantId,
                    'task_id' => (int)$task['id'],
                    'user_id' => $userId,
                    'channel' => $selection['channel']['code'],
                    'quality' => $selection['spec']['quality'],
                    'ratio' => $selection['spec']['ratio'],
                    'video_uri' => $video['uri'],
                    'storage_scope' => $storage['scope'],
                    'storage_engine' => $storage['default'],
                    'storage_domain' => StorageConfigService::getEffectiveDomain($tenantId),
                    'width' => $video['width'] ?? 0,
                    'height' => $video['height'] ?? 0,
                    'tenant_cost_points' => $estimate['platform_unit_cost'],
                    'user_charge_points' => $estimate['tenant_unit_price'],
                    'provider_task_id' => $video['provider_task_id'] ?? $task['provider_task_id'],
                    'delete_time' => 0,
                    'create_time' => time(),
                ]);
                $sourceSn = (string)$task['id'] . '-' . ((int)$index + 1);
                PointService::consumeBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$estimate['platform_unit_cost'], (float)$estimate['tenant_unit_price'], $sourceSn, 'AIGC视频消费', [
                    'app_code' => self::APP_CODE,
                    'task_id' => (int)$task['id'],
                    'result_id' => (int)$row['id'],
                    'channel' => $selection['channel']['code'],
                    'quality' => $selection['spec']['quality'],
                    'ratio' => $selection['spec']['ratio'],
                ]);
                AigcVideoBilling::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'task_id' => (int)$task['id'],
                    'result_id' => (int)$row['id'],
                    'channel' => $selection['channel']['code'],
                    'quality' => $selection['spec']['quality'],
                    'ratio' => $selection['spec']['ratio'],
                    'quantity' => 1,
                    'platform_unit_cost' => $estimate['platform_unit_cost'],
                    'tenant_unit_price' => $estimate['tenant_unit_price'],
                    'tenant_cost_points' => $estimate['platform_unit_cost'],
                    'user_charge_points' => $estimate['tenant_unit_price'],
                    'billing_status' => 'deducted',
                    'tenant_point_sn' => $sourceSn,
                    'user_point_sn' => $sourceSn,
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
                $item = $row->toArray();
                $item['video_url'] = FileService::getFileUrlByStorage(
                    $item['video_uri'],
                    $item['storage_scope'] ?? '',
                    $item['storage_engine'] ?? '',
                    $item['storage_domain'] ?? ''
                );
                $rows[] = $item;
            }

            $costPoints = count($rows);
            self::consumeQuota($tenantId, $userId, $costPoints);
            $task->status = 'success';
            $task->error = '';
            $task->tenant_cost_points = number_format((float)$estimate['platform_unit_cost'] * $costPoints, 2, '.', '');
            $task->user_charge_points = number_format((float)$estimate['tenant_unit_price'] * $costPoints, 2, '.', '');
            $task->provider_task_id = (string)($videos[0]['provider_task_id'] ?? $task['provider_task_id']);
            $task->finish_time = time();
            $task->update_time = time();
            $task->save();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $task->status = 'failed';
            $task->error = $e->getMessage();
            $task->finish_time = time();
            $task->update_time = time();
            $task->save();
            throw $e;
        }
        return $rows;
    }

    private static function uniqueVideos(array $videos, int $limit = 1): array
    {
        $unique = [];
        $seen = [];
        foreach ($videos as $video) {
            if (!is_array($video)) {
                continue;
            }
            $uri = trim((string)($video['uri'] ?? ''));
            if ($uri === '') {
                continue;
            }
            $signature = $uri . '|' . trim((string)($video['provider_task_id'] ?? ''));
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $unique[] = $video;
            if (count($unique) >= $limit) {
                break;
            }
        }
        return $unique;
    }

    private static function existingResultRows(int $tenantId, int $userId, int $taskId): array
    {
        $query = AigcVideoResult::where('tenant_id', $tenantId)
            ->where('task_id', $taskId)
            ->where('delete_time', 0)
            ->order('id', 'asc');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $rows = $query->select()->toArray();
        foreach ($rows as &$row) {
            $row['video_url'] = FileService::getFileUrlByStorage(
                $row['video_uri'],
                $row['storage_scope'] ?? '',
                $row['storage_engine'] ?? '',
                $row['storage_domain'] ?? ''
            );
        }
        return $rows;
    }

    private static function isAsyncProvider(string $provider): bool
    {
        return in_array(strtolower($provider), ['xhadmin', 'xhadmin_grok_video', 'grok_video_xaiq', 'wan', 'seedance', 'seedance2_pro', 'omni_flash_ext', 'happyhorse', 'happy_horse'], true);
    }

    private static function providerFor(string $provider): AigcVideoProviderInterface
    {
        return match (strtolower($provider)) {
            'happyhorse', 'happy_horse' => new HappyHorseAigcVideoProvider(),
            'xhadmin', 'xhadmin_grok_video', 'grok_video_xaiq', 'wan', 'seedance', 'seedance2_pro', 'omni_flash_ext' => new XhadminAigcVideoProvider(),
            default => new MockAigcVideoProvider(),
        };
    }

    private static function sanitizeUtf8Payload(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sanitizeUtf8Payload($value);
                continue;
            }
            if (is_string($value)) {
                $data[$key] = self::sanitizeUtf8String($value);
            }
        }
        return $data;
    }

    private static function sanitizeUtf8String(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? $value;
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($encoded)) {
            return '';
        }
        $decoded = json_decode($encoded, true);
        if (!is_string($decoded)) {
            return '';
        }
        $decoded = str_replace("\xEF\xBF\xBD", '', $decoded);
        return preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $decoded) ?? $decoded;
    }
}
