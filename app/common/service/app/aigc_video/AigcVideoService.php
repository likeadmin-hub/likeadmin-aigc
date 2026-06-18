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
                'option_config' => AigcVideoChannelService::userConfig($tenantId),
            ]);
        }
        $data = $config->toArray();
        $data['option_config'] = AigcVideoChannelService::userConfig($tenantId);
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, $data);
    }

    public static function estimate(int $tenantId, array $params): array
    {
        return AigcVideoChannelService::estimate($tenantId, $params);
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
        $prompt = trim((string)($params['prompt'] ?? ''));
        if ($prompt === '') {
            throw new Exception('请输入提示词');
        }
        $selection = AigcVideoChannelService::resolveSelection($tenantId, $params);
        $quantity = AigcVideoChannelService::normalizeQuantity($params['quantity'] ?? 1);
        AigcVideoChannelService::assertChannelQuantity($selection['channel'], $quantity);
        $referenceAssets = AigcVideoReferenceAssetService::normalize($params);
        $referenceImages = AigcVideoReferenceAssetService::images($referenceAssets);
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

    public static function taskLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        self::safeRefreshRunningTasks($tenantId, $userId);
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
        self::safeRefreshRunningTasks($tenantId, $userId, $taskId);
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
            'channel' => $task['channel'],
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
        self::safeRefreshRunningTasks($tenantId, $userId, $taskId);
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

    private static function buildRequestFromTask(AigcVideoTask $task, array $selection): AigcVideoGenerateRequest
    {
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
            (array)($task['reference_assets'] ?: []),
            $selection['spec'],
            self::providerParamsForSelection($selection, [
                'duration' => AigcVideoChannelService::normalizeGenerateDuration(
                    $selection['channel'],
                    (array)($task['reference_assets'] ?: []),
                    (int)($task['duration'] ?? 0) ?: null
                ),
                'aspect_ratio' => (string)($task['ratio'] ?? $selection['spec']['ratio']),
            ]),
            $channelConfig
        );
    }

    private static function findRecentDuplicateTask(int $tenantId, int $userId, array $criteria): ?AigcVideoTask
    {
        if (($criteria['channel'] ?? '') === 'seedance2_pro') {
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
            $uri = trim((string)($asset['uri'] ?? $asset['url'] ?? ''));
            if ($uri !== '') {
                $normalized[] = $type . ':' . $uri;
            }
        }
        sort($normalized);
        return json_encode(array_values(array_unique($normalized)), JSON_UNESCAPED_UNICODE);
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
                if ((string)$task['status'] !== 'success') {
                    $task->status = 'success';
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
}
