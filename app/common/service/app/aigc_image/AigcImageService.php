<?php

namespace app\common\service\app\aigc_image;

use app\common\model\app\aigc_image\AigcImageConfig;
use app\common\model\app\aigc_image\AigcImageBilling;
use app\common\model\app\aigc_image\AigcImageQuota;
use app\common\model\app\aigc_image\AigcImageResult;
use app\common\model\app\aigc_image\AigcImageSensitiveWord;
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\service\ai\AiUsageService;
use app\common\service\app\AppCaseService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\FileService;
use app\common\service\point\PointService;
use app\common\service\power\MarketNanoBananaAppRuntimeService;
use app\common\service\storage\StorageConfigService;
use Exception;
use think\facade\Db;

class AigcImageService
{
    public const APP_CODE = 'aigc_image';
    private const DUPLICATE_WINDOW_SECONDS = 6;
    private const READ_REFRESH_INTERVAL_SECONDS = 8;

    public static function config(int $tenantId): array
    {
        $config = AigcImageConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($config->isEmpty()) {
            return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, [
                'provider_mode' => 'platform',
                'provider' => 'mock',
                'model' => 'mock-image',
                'status' => 1,
                'config_json' => [],
                'option_config' => AigcImageChannelService::userConfig($tenantId),
            ]);
        }
        $data = $config->toArray();
        $data['option_config'] = AigcImageChannelService::userConfig($tenantId);
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, $data);
    }

    public static function estimate(int $tenantId, array $params): array
    {
        $selection = AigcImageChannelService::resolveSelection($tenantId, $params);
        if (MarketNanoBananaAppRuntimeService::isSelection($params)) {
            $quote = MarketNanoBananaAppRuntimeService::quote($tenantId, array_merge($params, [
                'channel' => (string)$selection['channel']['code'],
                'quality' => (string)$selection['spec']['quality'],
                'ratio' => (string)$selection['spec']['ratio'],
            ]), (int)($params['quantity'] ?? 1));
            $quote['platform_unit_cost'] = (float)($quote['tenant_unit_points'] ?? 0);
            $quote['tenant_unit_price'] = (float)($quote['user_unit_points'] ?? 0);
            return $quote;
        }
        $estimate = AigcImageChannelService::estimate($tenantId, $params);
        return AiUsageService::resolveImageMarketEstimate($tenantId, $selection, $estimate, (int)($estimate['quantity'] ?? 1));
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        $row = AigcImageConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $current = $row->isEmpty() ? [] : $row->toArray();
        $data = [
            'tenant_id' => $tenantId,
            'provider_mode' => array_key_exists('provider_mode', $params) ? $params['provider_mode'] : ($current['provider_mode'] ?? 'platform'),
            'provider' => array_key_exists('provider', $params) ? $params['provider'] : ($current['provider'] ?? 'mock'),
            'model' => array_key_exists('model', $params) ? $params['model'] : ($current['model'] ?? 'mock-image'),
            'config_json' => array_key_exists('config_json', $params) && is_array($params['config_json']) ? $params['config_json'] : ($current['config_json'] ?? []),
            'status' => array_key_exists('status', $params) ? $params['status'] : ($current['status'] ?? 1),
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcImageConfig::create($data);
            return;
        }
        $row->save($data);
    }

    public static function generate(int $tenantId, int $userId, array $params): array
    {
        return self::generateInternal($tenantId, $userId, $params);
    }

    public static function generateWithBillingOverride(int $tenantId, int $userId, array $params, array $billingOverride): array
    {
        return self::generateInternal($tenantId, $userId, $params, $billingOverride);
    }

    private static function generateInternal(int $tenantId, int $userId, array $params, array $billingOverride = []): array
    {
        $params = self::sanitizeUtf8Payload($params);
        $prompt = trim((string)($params['prompt'] ?? ''));
        if ($prompt === '') {
            throw new Exception('请输入提示词');
        }
        $selection = AigcImageChannelService::resolveSelection($tenantId, $params);
        $quantity = AigcImageChannelService::normalizeQuantity($params['quantity'] ?? 1);
        AigcImageChannelService::assertChannelQuantity($selection['channel'], $quantity);
        $referenceImages = self::normalizeReferenceImages((array)($params['reference_images'] ?? []), $tenantId, $userId);
        if (count($referenceImages) > (int)$selection['channel']['max_reference_images']) {
            throw new Exception('参考图数量超出限制');
        }
        $providerParams = self::providerParamsForRequest($selection['spec']['provider_params_json'] ?? [], $params);
        $providerParams['channel_config'] = array_merge($selection['channel']['config_json'] ?? [], [
            'model' => $selection['channel']['model'],
        ]);
        if (MarketNanoBananaAppRuntimeService::isSelection($params)) {
            return self::generateMarketNanoBanana($tenantId, $userId, $params, $selection, $quantity, $referenceImages, $providerParams);
        }
        self::checkSensitiveWords($tenantId, $prompt);
        $duplicateCriteria = [
            'prompt' => $prompt,
            'negative_prompt' => (string)($params['negative_prompt'] ?? ''),
            'style' => (string)($params['style'] ?? 'general'),
            'channel' => (string)$selection['channel']['code'],
            'quality' => (string)$selection['spec']['quality'],
            'ratio' => (string)$selection['spec']['ratio'],
            'quantity' => $quantity,
            'reference_images' => $referenceImages,
            'provider_params_json' => $providerParams,
        ];
        $estimate = AigcImageChannelService::estimate($tenantId, array_merge($params, [
            'channel' => $selection['channel']['code'],
            'quality' => $selection['spec']['quality'],
            'ratio' => $selection['spec']['ratio'],
            'quantity' => $quantity,
        ]));
        $estimate = self::applyBillingOverride($estimate, $quantity, $billingOverride);
        if ($billingOverride === []) {
            $estimate = AiUsageService::resolveImageMarketEstimate($tenantId, $selection, $estimate, $quantity);
        }

        Db::startTrans();
        try {
            self::lockSubmitOwner($userId);
            $duplicateTask = self::findRecentDuplicateTask($tenantId, $userId, $duplicateCriteria);
            if ($duplicateTask) {
                Db::commit();
                return self::buildDuplicateGenerateResponse($duplicateTask, $tenantId, $userId);
            }
            self::checkQuota($tenantId, $userId, $quantity);
            PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);
            $usage = AiUsageService::createImageSubmission($tenantId, $userId, array_merge($params, [
                'channel' => $selection['channel']['code'],
                'quality' => $selection['spec']['quality'],
                'ratio' => $selection['spec']['ratio'],
                'quantity' => $quantity,
                'reference_images' => $referenceImages,
            ]), $selection, $estimate);
            $task = AigcImageTask::create([
                'app_task_id' => (int)$usage['app_task']['id'],
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'prompt' => $prompt,
                'negative_prompt' => $params['negative_prompt'] ?? '',
                'reference_images' => $referenceImages,
                'provider_params_json' => $providerParams,
                'style' => $params['style'] ?? 'general',
                'channel' => $selection['channel']['code'],
                'quality' => $selection['spec']['quality'],
                'ratio' => $selection['spec']['ratio'],
                'quantity' => $quantity,
                'tenant_cost_points' => $estimate['tenant_cost_points'],
                'user_charge_points' => $estimate['user_charge_points'],
                'provider' => $selection['channel']['provider'],
                'model' => $selection['channel']['model'],
                'status' => 'running',
                'error' => '',
                'delete_time' => 0,
                'create_time' => time(),
                'update_time' => time(),
            ]);
            AiUsageService::attachImageTask((int)$usage['app_task']['id'], (int)$task['id']);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        $providerName = (string)$selection['channel']['provider'];
        $provider = self::providerFor($providerName);
        $channelConfig = array_merge($selection['channel']['config_json'] ?? [], [
            'model' => $selection['channel']['model'],
            'tenant_id' => $tenantId,
            'user_id' => $userId,
        ]);
        if (self::isAsyncProvider($providerName)) {
            $channelConfig['poll_attempts'] = 0;
        }
        try {
            $result = $provider->generate(new AigcImageGenerateRequest(
                $prompt,
                (string)($params['negative_prompt'] ?? ''),
                (string)($params['style'] ?? 'general'),
                $selection['channel']['code'],
                $selection['spec']['quality'],
                $selection['spec']['ratio'],
                $quantity,
                $referenceImages,
                $selection['spec'],
                $providerParams,
                $channelConfig
            ));
        } catch (\Throwable $e) {
            $task->status = 'failed';
            $task->error = '生图提交失败';
            $task->finish_time = time();
            $task->update_time = time();
            $task->save();
            AiUsageService::failImageTask((int)$task['id'], $e->getMessage(), 'submit_exception');
            throw $e;
        }

        $task->provider_task_id = $result->providerTaskId;
        $task->update_time = time();
        $task->save();
        AiUsageService::markImageSubmitted((int)$task['id'], (string)$result->providerTaskId, [
            'provider_task_id' => (string)$result->providerTaskId,
            'image_count' => count($result->images),
        ]);

        if (!$result->success) {
            $task->status = 'failed';
            $task->error = $result->error;
            $task->finish_time = time();
            $task->save();
            AiUsageService::failImageTask((int)$task['id'], (string)$task->error, 'submit_failed');
            return ['task_id' => $task['id'], 'results' => [], 'status' => 'failed', 'error' => $task->error];
        }

        if (empty($result->images) && $result->providerTaskId !== '') {
            return ['task_id' => $task['id'], 'results' => [], 'status' => 'running'];
        }

        $rows = self::finishTaskWithImages($task, $selection, $estimate, $result->images);

        return ['task_id' => $task['id'], 'results' => $rows];
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

    /** Executes the Nano Banana application API without creating a second image-model billing chain. */
    private static function generateMarketNanoBanana(int $tenantId, int $userId, array $params, array $selection, int $quantity, array $referenceImages, array $providerParams): array
    {
        $prompt = trim((string)($params['prompt'] ?? ''));
        self::checkSensitiveWords($tenantId, $prompt);
        $request = array_merge($params, [
            'channel' => (string)$selection['channel']['code'],
            'quality' => (string)$selection['spec']['quality'],
            'ratio' => (string)$selection['spec']['ratio'],
            'quantity' => $quantity,
            'reference_images' => $referenceImages,
        ]);
        $quote = MarketNanoBananaAppRuntimeService::quote($tenantId, $request, $quantity);
        $duplicateCriteria = [
            'prompt' => $prompt,
            'negative_prompt' => (string)($params['negative_prompt'] ?? ''),
            'style' => (string)($params['style'] ?? 'general'),
            'channel' => (string)$selection['channel']['code'],
            'quality' => (string)$selection['spec']['quality'],
            'ratio' => (string)$selection['spec']['ratio'],
            'quantity' => $quantity,
            'reference_images' => $referenceImages,
            'provider_params_json' => $providerParams,
        ];

        $reserve = [];
        $task = null;
        Db::startTrans();
        try {
            self::lockSubmitOwner($userId);
            $duplicateTask = self::findRecentDuplicateTask($tenantId, $userId, $duplicateCriteria);
            if ($duplicateTask) {
                Db::commit();
                return self::buildDuplicateGenerateResponse($duplicateTask, $tenantId, $userId);
            }
            self::checkQuota($tenantId, $userId, $quantity);
            $reserve = MarketNanoBananaAppRuntimeService::reserve(
                $tenantId,
                $userId,
                'generate',
                'aigc-image-' . bin2hex(random_bytes(12)),
                $request,
                $request,
                $quantity,
                [
                    'app_code' => self::APP_CODE,
                    'business_table' => 'aigc_image_task',
                    'billing_label' => 'AIGC 生图 Nano Banana',
                ]
            );
            $task = AigcImageTask::create([
                'app_task_id' => (int)$reserve['app_task_id'],
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'prompt' => $prompt,
                'negative_prompt' => (string)($params['negative_prompt'] ?? ''),
                'reference_images' => $referenceImages,
                'provider_params_json' => $providerParams,
                'style' => (string)($params['style'] ?? 'general'),
                'channel' => (string)$selection['channel']['code'],
                'quality' => (string)$selection['spec']['quality'],
                'ratio' => (string)$selection['spec']['ratio'],
                'quantity' => $quantity,
                'tenant_cost_points' => (float)($quote['tenant_cost_points'] ?? 0),
                'user_charge_points' => (float)($quote['user_charge_points'] ?? 0),
                'provider' => 'power_market_app_api',
                'model' => (string)$selection['channel']['model'],
                'provider_task_id' => '',
                'status' => 'running',
                'error' => '',
                'delete_time' => 0,
                'create_time' => time(),
                'update_time' => time(),
            ]);
            MarketNanoBananaAppRuntimeService::linkBusinessTask((int)$reserve['app_task_id'], (int)$task['id']);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            if (!empty($reserve['consumption_id'])) {
                MarketNanoBananaAppRuntimeService::fail((int)$reserve['consumption_id'], $e->getMessage(), 'image_task_create_failed');
            }
            throw $e;
        }

        try {
            $result = MarketNanoBananaAppRuntimeService::submit((int)$reserve['consumption_id'], $request);
            $task->save([
                'provider_task_id' => (string)($result['provider_task_id'] ?? ''),
                'update_time' => time(),
            ]);
            $rows = self::syncMarketNanoBananaImageTask($task);
            $latest = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => (int)$task['id']])->findOrEmpty();
            return [
                'task_id' => (int)$task['id'],
                'results' => $rows,
                'status' => $latest->isEmpty() ? (string)($result['status'] ?? 'running') : (string)$latest['status'],
            ];
        } catch (\Throwable $e) {
            self::syncMarketNanoBananaImageTask($task, $e->getMessage());
            throw $e;
        }
    }

    /** Persists completed application-API results into the AIGC image business tables. */
    private static function syncMarketNanoBananaImageTask(AigcImageTask $task, string $fallbackError = ''): array
    {
        $consumption = \app\common\model\ai\AiConsumptionLog::where('app_task_id', (int)$task['app_task_id'])->findOrEmpty();
        if ($consumption->isEmpty()) {
            return [];
        }
        $providerTaskId = (string)$consumption['upstream_task_id'];
        if ($providerTaskId !== '' && $providerTaskId !== (string)$task['provider_task_id']) {
            $task->save(['provider_task_id' => $providerTaskId, 'update_time' => time()]);
        }
        $runStatus = (string)$consumption['run_status'];
        $billingStatus = (string)$consumption['billing_status'];
        if (in_array($runStatus, ['failed', 'canceled', 'cancelled'], true) || $billingStatus === 'refunded') {
            self::failMarketNanoBananaImageTask($task, (string)$consumption['error_message'] ?: $fallbackError ?: 'Nano Banana 生图失败', $runStatus);
            return [];
        }
        if ($runStatus !== 'success' && $billingStatus !== 'settled') {
            return [];
        }
        $summary = self::arrayValue($consumption['response_summary'] ?? []);
        $images = self::normalizeMarketNanoBananaImages((array)($summary['images'] ?? []), $providerTaskId);
        if ($images === []) {
            return [];
        }
        return self::finishMarketNanoBananaImageTask($task, $consumption->toArray(), $images);
    }

    private static function finishMarketNanoBananaImageTask(AigcImageTask $task, array $consumption, array $images): array
    {
        $rows = [];
        Db::startTrans();
        try {
            $tenantId = (int)$task['tenant_id'];
            $userId = (int)$task['user_id'];
            $task = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => (int)$task['id']])->lock(true)->findOrEmpty();
            if ($task->isEmpty()) {
                throw new Exception('任务不存在');
            }
            $existingRows = self::existingResultRows($tenantId, $userId, (int)$task['id']);
            if ($existingRows !== []) {
                if ((string)$task['status'] !== 'success') {
                    $task->save(['status' => 'success', 'error' => '', 'finish_time' => time(), 'update_time' => time()]);
                }
                Db::commit();
                return $existingRows;
            }
            $images = self::uniqueImages($images, max(1, (int)$task['quantity']));
            if ($images === []) {
                Db::commit();
                return [];
            }
            $count = count($images);
            $tenantTotal = (float)($consumption['actual_tenant_cost'] ?? $task['tenant_cost_points'] ?? 0);
            $userTotal = (float)($consumption['actual_user_price'] ?? $task['user_charge_points'] ?? 0);
            $tenantUnit = $tenantTotal / $count;
            $userUnit = $userTotal / $count;
            $billingStatus = (string)($consumption['billing_status'] ?? '') === 'settled' ? 'deducted' : 'pending_usage';
            $fallbackStorage = StorageConfigService::getEffectiveConfig($tenantId);
            foreach ($images as $image) {
                $row = AigcImageResult::create([
                    'tenant_id' => $tenantId,
                    'task_id' => (int)$task['id'],
                    'user_id' => $userId,
                    'channel' => (string)$task['channel'],
                    'quality' => (string)$task['quality'],
                    'ratio' => (string)$task['ratio'],
                    'image_uri' => (string)$image['uri'],
                    'storage_scope' => (string)($image['storage_scope'] ?? $fallbackStorage['scope']),
                    'storage_engine' => (string)($image['storage_engine'] ?? $fallbackStorage['default']),
                    'storage_domain' => (string)($image['storage_domain'] ?? StorageConfigService::getEffectiveDomain($tenantId)),
                    'width' => (int)($image['width'] ?? 0),
                    'height' => (int)($image['height'] ?? 0),
                    'tenant_cost_points' => $tenantUnit,
                    'user_charge_points' => $userUnit,
                    'provider_task_id' => (string)($image['provider_task_id'] ?? $task['provider_task_id']),
                    'delete_time' => 0,
                    'create_time' => time(),
                ]);
                AigcImageBilling::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'task_id' => (int)$task['id'],
                    'result_id' => (int)$row['id'],
                    'channel' => (string)$task['channel'],
                    'quality' => (string)$task['quality'],
                    'ratio' => (string)$task['ratio'],
                    'quantity' => 1,
                    'platform_unit_cost' => $tenantUnit,
                    'tenant_unit_price' => $userUnit,
                    'tenant_cost_points' => $tenantUnit,
                    'user_charge_points' => $userUnit,
                    'consumption_id' => (int)($consumption['id'] ?? 0),
                    'billing_status' => $billingStatus,
                    'tenant_point_sn' => (string)($consumption['tenant_point_sn'] ?? ''),
                    'user_point_sn' => (string)($consumption['user_point_sn'] ?? ''),
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
                $item = $row->toArray();
                $item['image_url'] = FileService::getFileUrlByStorage($item['image_uri'], $item['storage_scope'] ?? '', $item['storage_engine'] ?? '', $item['storage_domain'] ?? '');
                $rows[] = $item;
            }
            self::consumeQuota($tenantId, $userId, $count);
            $task->save([
                'status' => 'success',
                'tenant_cost_points' => $tenantTotal,
                'user_charge_points' => $userTotal,
                'error' => '',
                'finish_time' => time(),
                'update_time' => time(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return $rows;
    }

    private static function failMarketNanoBananaImageTask(AigcImageTask $task, string $message, string $runStatus = 'failed'): void
    {
        if ((string)$task['status'] === 'success') {
            return;
        }
        $task->save([
            'status' => in_array($runStatus, ['canceled', 'cancelled'], true) ? 'canceled' : 'failed',
            'error' => mb_substr($message, 0, 1000),
            'finish_time' => time(),
            'update_time' => time(),
        ]);
    }

    private static function normalizeMarketNanoBananaImages(array $images, string $providerTaskId): array
    {
        $normalized = [];
        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }
            $uri = trim((string)($image['uri'] ?? $image['image_uri'] ?? ''));
            if ($uri === '') {
                continue;
            }
            $normalized[] = [
                'uri' => $uri,
                'width' => (int)($image['width'] ?? 0),
                'height' => (int)($image['height'] ?? 0),
                'storage_scope' => (string)($image['storage_scope'] ?? ''),
                'storage_engine' => (string)($image['storage_engine'] ?? ''),
                'storage_domain' => (string)($image['storage_domain'] ?? ''),
                'provider_task_id' => $providerTaskId,
            ];
        }
        return $normalized;
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

    public static function taskLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        $query = AigcImageTask::alias('t')
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
        $style = trim((string)($params['style'] ?? ''));
        if ($style !== '') {
            $query->where('t.style', $style);
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
        $seenResultKeys = [];
        if ($taskIds) {
            $resultRows = AigcImageResult::where('tenant_id', $tenantId)
                ->where('delete_time', 0)
                ->whereIn('task_id', $taskIds)
                ->order('id', 'asc')
                ->select()
                ->toArray();
            foreach ($resultRows as $result) {
                $result['image_url'] = FileService::getFileUrlByStorage(
                    $result['image_uri'],
                    $result['storage_scope'] ?? '',
                    $result['storage_engine'] ?? '',
                    $result['storage_domain'] ?? ''
                );
                $signature = (string)($result['image_uri'] ?: $result['image_url']);
                if ($signature === '') {
                    $signature = (string)$result['id'];
                }
                $taskKey = (int)$result['task_id'];
                $dedupeKey = $taskKey . ':' . $signature;
                if (!isset($seenResultKeys[$dedupeKey])) {
                    $seenResultKeys[$dedupeKey] = true;
                    $resultMap[$taskKey][] = $result;
                }
            }
        }
        foreach ($rows as &$row) {
            $row['task_id'] = (int)$row['id'];
            $results = $resultMap[(int)$row['id']] ?? [];
            $first = $results[0] ?? [];
            $row['results'] = $results;
            $row['result_count'] = count($results);
            $row['result_id'] = (int)($first['id'] ?? 0);
            $row['image_uri'] = (string)($first['image_uri'] ?? '');
            $row['image_url'] = (string)($first['image_url'] ?? '');
            $row['width'] = (int)($first['width'] ?? 0);
            $row['height'] = (int)($first['height'] ?? 0);
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
        $query = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        $data = $task->toArray();
        $data['results'] = self::existingResultRows($tenantId, $userId, $taskId);
        return $data;
    }

    public static function refreshPendingTasks(int $limit = 20): int
    {
        $tasks = AigcImageTask::where('status', 'running')
            ->where('delete_time', 0)
            ->where('provider_task_id', '<>', '')
            ->order('update_time', 'asc')
            ->limit(max(1, min(100, $limit)))
            ->select();
        foreach ($tasks as $task) {
            self::refreshRunningTasks((int)$task['tenant_id'], (int)$task['user_id'], (int)$task['id'], true);
        }
        return count($tasks);
    }

    /** Provider-runtime result-worker entry point for one image task. */
    public static function refreshRuntimeTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if (!$task->isEmpty() && (string)$task['provider'] === 'power_market_app_api') {
            self::syncMarketNanoBananaImageTask($task);
            return;
        }
        self::refreshRunningTasks($tenantId, $userId, $taskId, false);
    }

    /**
     * Recover an already-completed upstream task that was refunded only because
     * older result normalization did not recognize its image list. It is not
     * part of routine polling because recovery may charge a refunded reservation.
     */
    public static function recoverCompletedTask(int $tenantId, int $taskId, int $userId = 0): array
    {
        $query = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        $existingRows = self::existingResultRows($tenantId, $userId, $taskId);
        if ((string)$task['status'] === 'success' && $existingRows) {
            if ((string)$task['error'] !== '') {
                $task->error = '';
                $task->update_time = time();
                $task->save();
            }
            return $existingRows;
        }
        if (!self::isRecoverableCompletedImageFailure($task)) {
            throw new Exception('该任务不符合结果补偿恢复条件');
        }
        if (!self::isAsyncProvider((string)$task['provider'])) {
            throw new Exception('该任务不支持上游结果恢复');
        }
        try {
            $selection = AigcImageChannelService::resolveSelection($tenantId, [
                'channel' => $task['channel'],
                'quality' => $task['quality'],
                'ratio' => $task['ratio'],
                'quantity' => $task['quantity'],
            ]);
        } catch (\Throwable $e) {
            $selection = self::selectionFromTask($task);
            if ($selection === []) {
                throw $e;
            }
        }
        $provider = self::providerFor((string)$task['provider']);
        if (!method_exists($provider, 'fetchResult')) {
            throw new Exception('当前生图通道不支持结果查询');
        }
        $result = $provider->fetchResult((string)$task['provider_task_id'], self::buildRequestFromTask($task, $selection));
        if (!$result->success) {
            throw new Exception($result->error ?: '上游任务尚未完成');
        }
        if (empty($result->images)) {
            throw new Exception('上游任务尚未返回图片');
        }
        $estimate = [
            'platform_unit_cost' => (float)$task['tenant_cost_points'] / max(1, (int)$task['quantity']),
            'tenant_unit_price' => (float)$task['user_charge_points'] / max(1, (int)$task['quantity']),
        ];
        return self::finishTaskWithImages($task, $selection, $estimate, $result->images, true);
    }

    /** @deprecated Use refreshRuntimeTask(). */
    public static function refreshMarketTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        self::refreshRuntimeTask($tenantId, $taskId, $userId);
    }

    public static function retryTask(int $tenantId, int $taskId): array
    {
        $task = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return self::generate($tenantId, (int)$task['user_id'], [
            'prompt' => $task['prompt'],
            'negative_prompt' => $task['negative_prompt'],
            'reference_images' => $task['reference_images'] ?: [],
            'style' => $task['style'],
            'channel' => $task['channel'],
            'quality' => $task['quality'],
            'ratio' => $task['ratio'],
            'quantity' => $task['quantity'],
        ]);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        if ((int)($task['app_task_id'] ?? 0) > 0 && in_array((string)$task['status'], ['pending', 'running'], true)) {
            AiUsageService::failImageTask((int)$task['id'], '用户取消任务', 'canceled');
        }
        $task->delete_time = time();
        $task->update_time = time();
        $task->save();
        AigcImageResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update([
            'delete_time' => time(),
        ]);
    }

    public static function resultLists(int $tenantId, int $userId = 0, int $taskId = 0, string $status = '', string $style = ''): array
    {
        $query = AigcImageTask::where('tenant_id', $tenantId)->where('delete_time', 0)->order('id', 'desc');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($taskId > 0) {
            $query->where('id', $taskId);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($style !== '') {
            $query->where('style', $style);
        }
        $tasks = $query->limit(50)->select()->toArray();
        $taskIds = array_values(array_unique(array_filter(array_column($tasks, 'id'))));
        $resultMap = [];
        if (!empty($taskIds)) {
            $resultRows = AigcImageResult::where('tenant_id', $tenantId)
                ->where('delete_time', 0)
                ->whereIn('task_id', $taskIds)
                ->order('id', 'asc')
                ->select()
                ->toArray();
            foreach ($resultRows as $row) {
                $row['image_url'] = FileService::getFileUrlByStorage(
                    $row['image_uri'],
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
            $task['image_uri'] = (string)($first['image_uri'] ?? '');
            $task['image_url'] = (string)($first['image_url'] ?? '');
            $task['width'] = (int)($first['width'] ?? 0);
            $task['height'] = (int)($first['height'] ?? 0);
        }
        return $tasks;
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = AigcImageResult::where(['tenant_id' => $tenantId, 'id' => $resultId]);
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
        $imageUri = (string)($first['image_uri'] ?? '');
        if ($imageUri === '') {
            throw new Exception('任务暂无可用作品');
        }

        $title = trim((string)($params['title'] ?? ''));
        if ($title === '') {
            $title = mb_substr((string)$task['prompt'], 0, 20) ?: '生图案例';
        }

        return AppCaseService::save($tenantId, self::APP_CODE, [
            'title' => $title,
            'prompt' => $task['prompt'] ?? '',
            'media_type' => 'image',
            'cover_uri' => $imageUri,
            'media_uri' => $imageUri,
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
        return self::paginateRows(AigcImageQuota::where('tenant_id', $tenantId)->order('id', 'desc'), $params, 100);
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
        $row = AigcImageQuota::where(['tenant_id' => $tenantId, 'user_id' => $userId])->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcImageQuota::create($data);
            return;
        }
        $row->save($data);
    }

    public static function sensitiveWordLists(int $tenantId, array $params = []): array
    {
        return self::paginateRows(AigcImageSensitiveWord::where('tenant_id', $tenantId)->order('id', 'desc'), $params, 200);
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
            $row = AigcImageSensitiveWord::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
            if ($row->isEmpty()) {
                throw new Exception('敏感词不存在');
            }
            $row->save($data);
            return;
        }
        $data['create_time'] = time();
        AigcImageSensitiveWord::create($data);
    }

    public static function stat(int $tenantId = 0): array
    {
        $task = AigcImageTask::where([])->where('delete_time', 0);
        $result = AigcImageResult::where([])->where('delete_time', 0);
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
            'quota_total' => $tenantId > 0 ? AigcImageQuota::where('tenant_id', $tenantId)->sum('total_quota') : AigcImageQuota::where([])->sum('total_quota'),
            'quota_used' => $tenantId > 0 ? AigcImageQuota::where('tenant_id', $tenantId)->sum('used_quota') : AigcImageQuota::where([])->sum('used_quota'),
            'tenant_cost_points' => $tenantId > 0 ? AigcImageBilling::where('tenant_id', $tenantId)->sum('tenant_cost_points') : AigcImageBilling::where([])->sum('tenant_cost_points'),
            'user_charge_points' => $tenantId > 0 ? AigcImageBilling::where('tenant_id', $tenantId)->sum('user_charge_points') : AigcImageBilling::where([])->sum('user_charge_points'),
        ];
    }

    private static function checkSensitiveWords(int $tenantId, string $prompt): void
    {
        $words = AigcImageSensitiveWord::where(['tenant_id' => $tenantId, 'status' => 1])->column('word');
        foreach ($words as $word) {
            if ($word !== '' && str_contains($prompt, $word)) {
                throw new Exception('提示词包含敏感词');
            }
        }
    }

    private static function checkQuota(int $tenantId, int $userId, int $quantity): void
    {
        $quota = AigcImageQuota::where(['tenant_id' => $tenantId, 'user_id' => $userId])->findOrEmpty();
        if (!$quota->isEmpty() && !empty($quota['expire_time']) && (int)$quota['expire_time'] < time()) {
            throw new Exception('生图额度已过期');
        }
        if (!$quota->isEmpty() && (int)$quota['total_quota'] > 0 && ((int)$quota['used_quota'] + $quantity) > (int)$quota['total_quota']) {
            throw new Exception('生图额度不足');
        }
    }

    private static function consumeQuota(int $tenantId, int $userId, int $quantity): void
    {
        $quota = AigcImageQuota::where(['tenant_id' => $tenantId, 'user_id' => $userId])->findOrEmpty();
        if ($quota->isEmpty()) {
            return;
        }
        $quota->used_quota = (int)$quota['used_quota'] + $quantity;
        $quota->save();
    }

    private static function refreshRunningTasks(int $tenantId, int $userId = 0, int $taskId = 0, bool $swallowErrors = false): void
    {
        $query = AigcImageTask::where('tenant_id', $tenantId)
            ->where('delete_time', 0)
            ->where('provider_task_id', '<>', '');
        if ($taskId > 0) {
            $query->where('id', $taskId);
        } else {
            $query->where('status', 'running');
        }
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $tasks = $query
            ->order('update_time', 'asc')
            ->order('id', 'asc')
            ->limit(10)
            ->select();
        foreach ($tasks as $task) {
            $status = (string)($task['status'] ?? '');
            if ($status !== 'running' && !self::isRecoverableAsyncFailure($task)) {
                continue;
            }
            if (!self::shouldRefreshTask($task, $taskId > 0)) {
                continue;
            }
            if ((string)$task['provider'] === 'power_market_app_api') {
                self::syncMarketNanoBananaImageTask($task);
                continue;
            }
            if ($status !== 'running') {
                $task->status = 'running';
                $task->finish_time = 0;
                $task->update_time = time();
                $task->save();
            }
            if (!self::isAsyncProvider((string)$task['provider'])) {
                continue;
            }
            try {
                try {
                    $selection = AigcImageChannelService::resolveSelection($tenantId, [
                        'channel' => $task['channel'],
                        'quality' => $task['quality'],
                        'ratio' => $task['ratio'],
                        'quantity' => $task['quantity'],
                    ]);
                } catch (\Throwable $e) {
                    $selection = self::selectionFromTask($task);
                    if ($selection === []) {
                        throw $e;
                    }
                }
                $provider = self::providerFor((string)$task['provider']);
                if (!method_exists($provider, 'fetchResult')) {
                    continue;
                }
                AiUsageService::recordImageEvent((int)$task['id'], 'poll', 'started', [
                    'provider_task_id' => (string)$task['provider_task_id'],
                ]);
                $result = $provider->fetchResult((string)$task['provider_task_id'], self::buildRequestFromTask($task, $selection));
                if (!$result->success) {
                    if (self::isTransientRefreshError((string)$result->error)) {
                        $task->error = self::friendlyRefreshError((string)$result->error);
                        $task->update_time = time();
                        $task->save();
                        AiUsageService::recordImageEvent((int)$task['id'], 'poll', 'retry', ['reason' => $task->error]);
                        continue;
                    }
                    $task->status = 'failed';
                    $task->error = $result->error ?: '生成失败';
                    $task->finish_time = time();
                    $task->update_time = time();
                    $task->save();
                    AiUsageService::failImageTask((int)$task['id'], (string)$task->error, 'upstream_failed');
                    continue;
                }
                AiUsageService::recordImageEvent((int)$task['id'], 'poll', 'success', ['image_count' => count($result->images)]);
                if (empty($result->images)) {
                    self::finishOverdueAsyncTask($task, $selection);
                    continue;
                }
                $estimate = [
                    'platform_unit_cost' => (float)$task['tenant_cost_points'] / max(1, (int)$task['quantity']),
                    'tenant_unit_price' => (float)$task['user_charge_points'] / max(1, (int)$task['quantity']),
                ];
                self::finishTaskWithImages($task, $selection, $estimate, $result->images);
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

    private static function shouldRefreshTask(AigcImageTask $task, bool $explicitTask = false): bool
    {
        if ($explicitTask) {
            return true;
        }
        $lastUpdate = self::timestampValue($task['update_time'] ?? 0);
        return $lastUpdate <= 0 || (time() - $lastUpdate) >= self::READ_REFRESH_INTERVAL_SECONDS;
    }

    private static function safeRefreshRunningTasks(int $tenantId, int $userId = 0, int $taskId = 0): void
    {
        try {
            self::refreshRunningTasks($tenantId, $userId, $taskId, true);
        } catch (\Throwable) {
            // Listing/detail pages must remain readable even if async task polling fails.
        }
    }

    private static function markRefreshFailed(AigcImageTask $task, string $message): void
    {
        try {
            $task->status = 'failed';
            $task->error = $message;
            $task->finish_time = time();
            $task->update_time = time();
            $task->save();
            AiUsageService::failImageTask((int)$task['id'], $message, 'refresh_failed');
        } catch (\Throwable) {
            // Never let failure-state persistence break read-only task list APIs.
        }
    }

    private static function isPermanentRefreshError(string $message): bool
    {
        foreach (['暂无可用生图通道', '生图通道不可用', '通道不可用', '不支持所选', '当前分辨率不支持', '规格'] as $needle) {
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

    private static function buildRequestFromTask(AigcImageTask $task, array $selection): AigcImageGenerateRequest
    {
        return new AigcImageGenerateRequest(
            (string)$task['prompt'],
            (string)$task['negative_prompt'],
            (string)$task['style'],
            (string)$task['channel'],
            (string)$task['quality'],
            (string)$task['ratio'],
            (int)$task['quantity'],
            (array)($task['reference_images'] ?: []),
            $selection['spec'],
            array_merge($selection['spec']['provider_params_json'] ?? [], is_array($task['provider_params_json'] ?? null) ? $task['provider_params_json'] : []),
            array_merge($selection['channel']['config_json'] ?? [], [
                'model' => $selection['channel']['model'],
                'tenant_id' => (int)$task['tenant_id'],
                'user_id' => (int)$task['user_id'],
            ])
        );
    }

    /**
     * A SKU may be unshelved after an upstream task has been accepted. Keep
     * polling it with the persisted submit-time runtime configuration.
     */
    private static function selectionFromTask(AigcImageTask $task): array
    {
        $providerParams = is_array($task['provider_params_json'] ?? null) ? $task['provider_params_json'] : [];
        $channelConfig = $providerParams['channel_config'] ?? [];
        if (!is_array($channelConfig)) {
            return [];
        }
        $model = trim((string)($channelConfig['model'] ?? $task['model'] ?? ''));
        if ($model === '') {
            return [];
        }
        return [
            'channel' => [
                'code' => (string)$task['channel'],
                'model' => $model,
                'config_json' => $channelConfig,
            ],
            'spec' => [
                'quality' => (string)$task['quality'],
                'ratio' => (string)$task['ratio'],
                'provider_params_json' => [],
            ],
        ];
    }

    private static function finishOverdueAsyncTask(AigcImageTask $task, array $selection): void
    {
        $timeout = self::asyncProcessingTimeoutSeconds($selection);
        if ($timeout <= 0) {
            return;
        }
        $createTime = self::timestampValue($task['create_time'] ?? 0);
        if ($createTime <= 0 || (time() - $createTime) < $timeout) {
            return;
        }
        $tenantId = (int)$task['tenant_id'];
        $userId = (int)$task['user_id'];
        $taskId = (int)$task['id'];
        if (!empty(self::existingResultRows($tenantId, $userId, $taskId))) {
            $latest = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->findOrEmpty();
            if (!$latest->isEmpty() && (string)$latest['status'] !== 'success') {
                $latest->status = 'success';
                $latest->finish_time = $latest['finish_time'] ?: time();
                $latest->update_time = time();
                $latest->save();
            }
            return;
        }
        $latest = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => $taskId])
            ->where('status', 'running')
            ->findOrEmpty();
        if ($latest->isEmpty()) {
            return;
        }
        $latest->status = 'running';
        $latest->error = '上游任务仍在生成中，系统会继续自动查询结果';
        $latest->finish_time = 0;
        $latest->update_time = time();
        $latest->save();
    }

    private static function isRecoverableAsyncFailure(AigcImageTask $task): bool
    {
        if ((string)($task['provider_task_id'] ?? '') === '') {
            return false;
        }
        $status = (string)($task['status'] ?? '');
        if ($status === 'running') {
            return true;
        }
        if ($status !== 'failed') {
            return false;
        }
        $error = (string)($task['error'] ?? '');
        foreach (['上游任务长时间未返回结果', '上游任务仍在生成中', '稍后自动重试', '任务查询暂时失败'] as $needle) {
            if ($needle !== '' && str_contains($error, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function isRecoverableCompletedImageFailure(AigcImageTask $task): bool
    {
        if ((string)($task['status'] ?? '') !== 'failed' || (string)($task['provider_task_id'] ?? '') === '') {
            return false;
        }
        return (string)\app\common\model\ai\AiConsumptionLog::where('app_task_id', (int)($task['app_task_id'] ?? 0))
            ->value('billing_status') === 'refunded';
    }

    private static function asyncProcessingTimeoutSeconds(array $selection): int
    {
        $config = is_array($selection['channel']['config_json'] ?? null) ? $selection['channel']['config_json'] : [];
        foreach (['max_processing_seconds', 'async_timeout_seconds', 'task_timeout_seconds'] as $key) {
            if (array_key_exists($key, $config)) {
                return max(0, (int)$config[$key]);
            }
        }
        $provider = strtolower((string)($selection['channel']['provider'] ?? ''));
        if (in_array($provider, ['gpt_image_2_pro', 'gpt_image_2_fast'], true)) {
            return 3600;
        }
        return 3600;
    }

    private static function timestampValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        $timestamp = strtotime((string)$value);
        return $timestamp === false ? 0 : $timestamp;
    }

    private static function providerParamsForRequest(array $specParams, array $params): array
    {
        $providerParams = $specParams;
        foreach (['output_format', 'transparent_background', 'background', 'response_format', 'mask_url'] as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }
            $providerParams[$key] = $params[$key];
        }
        return $providerParams;
    }

    private static function normalizeReferenceImages(array $images, int $tenantId, int $userId): array
    {
        $normalized = [];
        foreach ($images as $image) {
            $image = trim((string)$image);
            if ($image === '') {
                continue;
            }
            if (str_starts_with($image, 'data:image/')) {
                $stored = AigcImageAssetService::persistGeneratedImage($image, $tenantId, $userId);
                $image = (string)($stored['uri'] ?? '');
            }
            if ($image !== '' && !in_array($image, $normalized, true)) {
                $normalized[] = $image;
            }
        }
        return $normalized;
    }

    private static function findRecentDuplicateTask(int $tenantId, int $userId, array $criteria): ?AigcImageTask
    {
        $rows = AigcImageTask::where('tenant_id', $tenantId)
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
        $referenceSignature = self::referenceImageSignature((array)($criteria['reference_images'] ?? []));
        $providerSignature = self::providerParamSignature((array)($criteria['provider_params_json'] ?? []));
        foreach ($rows as $row) {
            if (in_array((string)$row['status'], ['failed', 'canceled'], true)) {
                continue;
            }
            if (self::referenceImageSignature((array)($row['reference_images'] ?: [])) !== $referenceSignature) {
                continue;
            }
            if (self::providerParamSignature((array)($row['provider_params_json'] ?: [])) !== $providerSignature) {
                continue;
            }
            return $row;
        }
        return null;
    }

    private static function lockSubmitOwner(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        Db::name('user')->where('id', $userId)->lock(true)->find();
    }

    private static function buildDuplicateGenerateResponse(AigcImageTask $task, int $tenantId, int $userId): array
    {
        $latest = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => (int)$task['id']])->findOrEmpty();
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
            $response['results'] = self::resultLists($tenantId, $userId, (int)$latest['id']);
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

    private static function providerParamSignature(array $params): string
    {
        ksort($params);
        return json_encode($params, JSON_UNESCAPED_UNICODE);
    }

    private static function finishTaskWithImages(AigcImageTask $task, array $selection, array $estimate, array $images, bool $recoverRefundedBilling = false): array
    {
        $rows = [];
        Db::startTrans();
        try {
            $tenantId = (int)$task['tenant_id'];
            $userId = (int)$task['user_id'];
            $task = AigcImageTask::where('tenant_id', $tenantId)
                ->where('id', (int)$task['id'])
                ->lock(true)
                ->findOrEmpty();
            if ($task->isEmpty()) {
                throw new Exception('任务不存在');
            }
            $existingRows = self::existingResultRows($tenantId, $userId, (int)$task['id']);
            if ((string)$task['status'] === 'success' || !empty($existingRows)) {
                if ((string)$task['status'] !== 'success') {
                    $task->status = 'success';
                    $task->error = '';
                    $task->finish_time = $task['finish_time'] ?: time();
                    $task->update_time = time();
                    $task->save();
                }
                Db::commit();
                return $existingRows;
            }
            $images = self::uniqueImages($images, max(1, (int)$task['quantity']));
            $storage = StorageConfigService::getEffectiveConfig($tenantId);
            $isUnifiedTask = (int)($task['app_task_id'] ?? 0) > 0;
            foreach ($images as $index => $image) {
                $row = AigcImageResult::create([
                    'tenant_id' => $tenantId,
                    'task_id' => (int)$task['id'],
                    'user_id' => $userId,
                    'channel' => $selection['channel']['code'],
                    'quality' => $selection['spec']['quality'],
                    'ratio' => $selection['spec']['ratio'],
                    'image_uri' => $image['uri'],
                    'storage_scope' => $storage['scope'],
                    'storage_engine' => $storage['default'],
                    'storage_domain' => StorageConfigService::getEffectiveDomain($tenantId),
                    'width' => $image['width'] ?? 0,
                    'height' => $image['height'] ?? 0,
                    'tenant_cost_points' => $estimate['platform_unit_cost'],
                    'user_charge_points' => $estimate['tenant_unit_price'],
                    'provider_task_id' => $image['provider_task_id'] ?? $task['provider_task_id'],
                    'delete_time' => 0,
                    'create_time' => time(),
                ]);
                $sourceSn = self::APP_CODE . '-' . (string)$task['id'] . '-' . ((int)$index + 1);
                if (!$isUnifiedTask) {
                    PointService::consumeBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$estimate['platform_unit_cost'], (float)$estimate['tenant_unit_price'], $sourceSn, 'AIGC生图消费', [
                        'app_code' => self::APP_CODE,
                        'task_id' => (int)$task['id'],
                        'result_id' => (int)$row['id'],
                        'channel' => $selection['channel']['code'],
                        'quality' => $selection['spec']['quality'],
                        'ratio' => $selection['spec']['ratio'],
                    ]);
                }
                AigcImageBilling::create([
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
                    'consumption_id' => 0,
                    'billing_status' => $isUnifiedTask ? 'reserved' : 'deducted',
                    'tenant_point_sn' => $isUnifiedTask ? '' : $sourceSn,
                    'user_point_sn' => $isUnifiedTask ? '' : $sourceSn,
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
                $item = $row->toArray();
                $item['image_url'] = FileService::getFileUrlByStorage(
                    $item['image_uri'],
                    $item['storage_scope'] ?? '',
                    $item['storage_engine'] ?? '',
                    $item['storage_domain'] ?? ''
                );
                $rows[] = $item;
            }

            $costPoints = count($rows);
            $settlementSummary = [
                'result_count' => $costPoints,
                'provider_task_id' => (string)($images[0]['provider_task_id'] ?? $task['provider_task_id']),
            ];
            $settlement = $isUnifiedTask
                ? ($recoverRefundedBilling
                    ? AiUsageService::settleRecoveredImageTaskInCurrentTransaction((int)$task['id'], $costPoints, $settlementSummary)
                    : AiUsageService::settleImageTaskInCurrentTransaction((int)$task['id'], $costPoints, $settlementSummary))
                : null;
            if ($settlement !== null) {
                $consumption = \app\common\model\ai\AiConsumptionLog::findOrEmpty((int)$settlement['consumption_id']);
                AigcImageBilling::where(['tenant_id' => $tenantId, 'task_id' => (int)$task['id']])->update([
                    'consumption_id' => (int)$settlement['consumption_id'],
                    'billing_status' => 'deducted',
                    'tenant_point_sn' => (string)($consumption['tenant_point_sn'] ?? ''),
                    'user_point_sn' => (string)($consumption['user_point_sn'] ?? ''),
                    'update_time' => time(),
                ]);
            }
            self::consumeQuota($tenantId, $userId, $costPoints);
            $task->status = $settlement['status'] ?? 'success';
            $task->tenant_cost_points = number_format((float)($settlement['actual_tenant_cost'] ?? ((float)$estimate['platform_unit_cost'] * $costPoints)), 2, '.', '');
            $task->user_charge_points = number_format((float)($settlement['actual_user_price'] ?? ((float)$estimate['tenant_unit_price'] * $costPoints)), 2, '.', '');
            $task->provider_task_id = (string)($images[0]['provider_task_id'] ?? $task['provider_task_id']);
            $task->error = '';
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
            AiUsageService::failImageTask((int)$task['id'], $e->getMessage(), 'result_persist_failed');
            throw $e;
        }
        return $rows;
    }

    private static function uniqueImages(array $images, int $limit = 1): array
    {
        $unique = [];
        $seen = [];
        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }
            $uri = trim((string)($image['uri'] ?? ''));
            if ($uri === '') {
                continue;
            }
            $signature = $uri . '|' . trim((string)($image['provider_task_id'] ?? ''));
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $unique[] = $image;
            if (count($unique) >= $limit) {
                break;
            }
        }
        return $unique;
    }

    private static function existingResultRows(int $tenantId, int $userId, int $taskId): array
    {
        $query = AigcImageResult::where('tenant_id', $tenantId)
            ->where('task_id', $taskId)
            ->where('delete_time', 0)
            ->order('id', 'asc');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $rows = $query->select()->toArray();
        foreach ($rows as &$row) {
            $row['image_url'] = FileService::getFileUrlByStorage(
                $row['image_uri'],
                $row['storage_scope'] ?? '',
                $row['storage_engine'] ?? '',
                $row['storage_domain'] ?? ''
            );
        }
        return $rows;
    }

    private static function isAsyncProvider(string $provider): bool
    {
        return in_array(strtolower($provider), ['xhadmin', 'xhadmin_gpt_image_2', 'gpt_image_2_openaim', 'gpt_image_2_pro', 'gpt_image_2_fast'], true);
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
        return preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $decoded) ?? $decoded;
    }

    private static function providerFor(string $provider): AigcImageProviderInterface
    {
        return match (strtolower($provider)) {
            'xhadmin', 'xhadmin_gpt_image_2', 'gpt_image_2_openaim', 'gpt_image_2_pro', 'gpt_image_2_fast' => new XhadminAigcImageProvider(),
            default => new MockAigcImageProvider(),
        };
    }
}
