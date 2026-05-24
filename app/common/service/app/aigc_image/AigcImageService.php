<?php

namespace app\common\service\app\aigc_image;

use app\common\model\app\aigc_image\AigcImageConfig;
use app\common\model\app\aigc_image\AigcImageBilling;
use app\common\model\app\aigc_image\AigcImageQuota;
use app\common\model\app\aigc_image\AigcImageResult;
use app\common\model\app\aigc_image\AigcImageSensitiveWord;
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\service\app\AppCaseService;
use app\common\service\FileService;
use app\common\service\point\PointService;
use app\common\service\storage\StorageConfigService;
use Exception;
use think\facade\Db;

class AigcImageService
{
    public const APP_CODE = 'aigc_image';
    private const DUPLICATE_WINDOW_SECONDS = 6;

    public static function config(int $tenantId): array
    {
        $config = AigcImageConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($config->isEmpty()) {
            return [
                'provider_mode' => 'platform',
                'provider' => 'mock',
                'model' => 'mock-image',
                'status' => 1,
                'config_json' => [],
                'option_config' => AigcImageChannelService::userConfig($tenantId),
            ];
        }
        $data = $config->toArray();
        $data['option_config'] = AigcImageChannelService::userConfig($tenantId);
        return $data;
    }

    public static function estimate(int $tenantId, array $params): array
    {
        return AigcImageChannelService::estimate($tenantId, $params);
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        $data = [
            'tenant_id' => $tenantId,
            'provider_mode' => $params['provider_mode'] ?? 'platform',
            'provider' => $params['provider'] ?? 'mock',
            'model' => $params['model'] ?? 'mock-image',
            'config_json' => $params['config_json'] ?? [],
            'status' => $params['status'] ?? 1,
            'update_time' => time(),
        ];
        $row = AigcImageConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcImageConfig::create($data);
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
        $selection = AigcImageChannelService::resolveSelection($tenantId, $params);
        $quantity = AigcImageChannelService::normalizeQuantity($params['quantity'] ?? 1);
        AigcImageChannelService::assertChannelQuantity($selection['channel'], $quantity);
        $referenceImages = self::normalizeReferenceImages((array)($params['reference_images'] ?? []), $tenantId, $userId);
        if (count($referenceImages) > (int)$selection['channel']['max_reference_images']) {
            throw new Exception('参考图数量超出限制');
        }
        self::checkSensitiveWords($tenantId, $prompt);
        $duplicateTask = self::findRecentDuplicateTask($tenantId, $userId, [
            'prompt' => $prompt,
            'negative_prompt' => (string)($params['negative_prompt'] ?? ''),
            'style' => (string)($params['style'] ?? 'general'),
            'channel' => (string)$selection['channel']['code'],
            'quality' => (string)$selection['spec']['quality'],
            'ratio' => (string)$selection['spec']['ratio'],
            'quantity' => $quantity,
            'reference_images' => $referenceImages,
        ]);
        if ($duplicateTask) {
            return self::buildDuplicateGenerateResponse($duplicateTask, $tenantId, $userId);
        }
        $estimate = AigcImageChannelService::estimate($tenantId, array_merge($params, [
            'channel' => $selection['channel']['code'],
            'quality' => $selection['spec']['quality'],
            'ratio' => $selection['spec']['ratio'],
            'quantity' => $quantity,
        ]));
        self::checkQuota($tenantId, $userId, $quantity);
        PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);

        $task = AigcImageTask::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'prompt' => $prompt,
            'negative_prompt' => $params['negative_prompt'] ?? '',
            'reference_images' => $referenceImages,
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
            $selection['spec']['provider_params_json'] ?? [],
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

        if (empty($result->images) && $result->providerTaskId !== '') {
            return ['task_id' => $task['id'], 'results' => [], 'status' => 'running'];
        }

        $rows = self::finishTaskWithImages($task, $selection, $estimate, $result->images);

        return ['task_id' => $task['id'], 'results' => $rows];
    }

    public static function taskLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        self::refreshRunningTasks($tenantId, $userId);
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
        self::refreshRunningTasks($tenantId, $userId, $taskId);
        $query = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        $data = $task->toArray();
        $data['results'] = self::resultLists($tenantId, $userId, $taskId);
        return $data;
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
        $task->delete_time = time();
        $task->update_time = time();
        $task->save();
        AigcImageResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update([
            'delete_time' => time(),
        ]);
    }

    public static function resultLists(int $tenantId, int $userId = 0, int $taskId = 0, string $status = ''): array
    {
        self::refreshRunningTasks($tenantId, $userId, $taskId);
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

    private static function refreshRunningTasks(int $tenantId, int $userId = 0, int $taskId = 0): void
    {
        $query = AigcImageTask::where('tenant_id', $tenantId)
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
            $selection = AigcImageChannelService::resolveSelection($tenantId, [
                'channel' => $task['channel'],
                'quality' => $task['quality'],
                'ratio' => $task['ratio'],
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
            if (empty($result->images)) {
                continue;
            }
            $estimate = [
                'platform_unit_cost' => (float)$task['tenant_cost_points'] / max(1, (int)$task['quantity']),
                'tenant_unit_price' => (float)$task['user_charge_points'] / max(1, (int)$task['quantity']),
            ];
            self::finishTaskWithImages($task, $selection, $estimate, $result->images);
        }
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
            $selection['spec']['provider_params_json'] ?? [],
            array_merge($selection['channel']['config_json'] ?? [], [
                'model' => $selection['channel']['model'],
                'tenant_id' => (int)$task['tenant_id'],
                'user_id' => (int)$task['user_id'],
            ])
        );
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
        foreach ($rows as $row) {
            if (in_array((string)$row['status'], ['failed', 'canceled'], true)) {
                continue;
            }
            if (self::referenceImageSignature((array)($row['reference_images'] ?: [])) !== $referenceSignature) {
                continue;
            }
            return $row;
        }
        return null;
    }

    private static function buildDuplicateGenerateResponse(AigcImageTask $task, int $tenantId, int $userId): array
    {
        if ((string)$task['status'] === 'running') {
            self::refreshRunningTasks($tenantId, $userId, (int)$task['id']);
        }
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

    private static function finishTaskWithImages(AigcImageTask $task, array $selection, array $estimate, array $images): array
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
                    $task->finish_time = $task['finish_time'] ?: time();
                    $task->update_time = time();
                    $task->save();
                }
                Db::commit();
                return $existingRows;
            }
            $images = self::uniqueImages($images, max(1, (int)$task['quantity']));
            $storage = StorageConfigService::getEffectiveConfig($tenantId);
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
                $sourceSn = (string)$task['id'] . '-' . ((int)$index + 1);
                PointService::consumeBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$estimate['platform_unit_cost'], (float)$estimate['tenant_unit_price'], $sourceSn, 'AIGC生图消费', [
                    'app_code' => self::APP_CODE,
                    'task_id' => (int)$task['id'],
                    'result_id' => (int)$row['id'],
                    'channel' => $selection['channel']['code'],
                    'quality' => $selection['spec']['quality'],
                    'ratio' => $selection['spec']['ratio'],
                ]);
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
                    'billing_status' => 'deducted',
                    'tenant_point_sn' => $sourceSn,
                    'user_point_sn' => $sourceSn,
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
            self::consumeQuota($tenantId, $userId, $costPoints);
            $task->status = 'success';
            $task->tenant_cost_points = number_format((float)$estimate['platform_unit_cost'] * $costPoints, 2, '.', '');
            $task->user_charge_points = number_format((float)$estimate['tenant_unit_price'] * $costPoints, 2, '.', '');
            $task->provider_task_id = (string)($images[0]['provider_task_id'] ?? $task['provider_task_id']);
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
        return in_array(strtolower($provider), ['xhadmin', 'xhadmin_gpt_image_2', 'gpt_image_2_openaim'], true);
    }

    private static function providerFor(string $provider): AigcImageProviderInterface
    {
        return match (strtolower($provider)) {
            'xhadmin', 'xhadmin_gpt_image_2', 'gpt_image_2_openaim' => new XhadminAigcImageProvider(),
            default => new MockAigcImageProvider(),
        };
    }
}
