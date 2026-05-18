<?php

namespace app\common\service\app\image_human;

use app\common\model\app\image_human\ImageHumanBilling;
use app\common\model\app\image_human\ImageHumanAvatar;
use app\common\model\app\image_human\ImageHumanConfig;
use app\common\model\app\image_human\ImageHumanResult;
use app\common\model\app\image_human\ImageHumanTask;
use app\common\model\app\image_human\ImageHumanVoice;
use app\common\service\FileService;
use app\common\service\point\PointService;
use app\common\service\storage\StorageConfigService;
use Exception;
use think\facade\Db;

class ImageHumanService
{
    public const APP_CODE = 'image_human';
    private const DUPLICATE_WINDOW_SECONDS = 6;

    public static function config(int $tenantId): array
    {
        $config = self::effectiveConfig($tenantId);
        return [
            'provider' => $config['provider'],
            'model' => $config['model'],
            'status' => $config['status'],
            'config_json' => $config['config_json'],
            'option_config' => [
                'modes' => [
                    ['value' => 'fast', 'label' => '快速模式', 'description' => '适合快速预览，生成速度优先'],
                    ['value' => 'standard', 'label' => '标准模式', 'description' => '适合正式成片，稳定性优先'],
                ],
                'defaults' => ['mode' => 'fast'],
                'pricing' => self::pricing($tenantId),
            ],
        ];
    }

    public static function estimate(int $tenantId, array $params, int $userId = 0): array
    {
        $duration = self::normalizeDuration($params['duration'] ?? $params['audio_duration'] ?? 0);
        if ($duration <= 0 && (int)($params['voice_id'] ?? 0) > 0) {
            $voice = self::findVoice($tenantId, $userId, (int)$params['voice_id'], false);
            $duration = self::normalizeDuration($voice['duration'] ?? 0);
        }
        $pricing = self::pricing($tenantId);
        return self::buildEstimate($duration, $pricing, self::normalizeMode((string)($params['mode'] ?? 'fast')));
    }

    public static function avatarLists(int $tenantId, int $userId, string $source = ''): array
    {
        $query = ImageHumanAvatar::where('tenant_id', $tenantId)
            ->where('delete_time', 0)
            ->whereRaw("(source = 'official' OR (source = 'mine' AND user_id = " . (int)$userId . '))')
            ->order(['source' => 'asc', 'sort' => 'desc', 'id' => 'desc']);
        if (in_array($source, ['official', 'mine'], true)) {
            $query->where('source', $source);
        }
        return array_map([self::class, 'formatAvatar'], $query->select()->toArray());
    }

    public static function saveAvatar(int $tenantId, int $userId, array $params): array
    {
        $name = self::normalizeAssetText((string)($params['name'] ?? '我的图片形象'), '我的图片形象', 80);
        $imageUri = self::normalizeAssetUri((string)($params['image_uri'] ?? $params['media_uri'] ?? $params['cover_uri'] ?? ''));
        $coverUri = self::normalizeAssetUri((string)($params['cover_uri'] ?? $imageUri));
        if ($imageUri === '') {
            throw new Exception('请上传人物图片');
        }
        $storage = StorageConfigService::getEffectiveConfig($tenantId);
        $row = ImageHumanAvatar::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'name' => $name,
            'source' => 'mine',
            'gender' => self::normalizeAssetText((string)($params['gender'] ?? ''), '', 20),
            'scene' => self::normalizeAssetText((string)($params['scene'] ?? ''), '', 50),
            'cover_uri' => $coverUri,
            'image_uri' => $imageUri,
            'media_uri' => $imageUri,
            'media_type' => 'image',
            'storage_scope' => $storage['scope'],
            'storage_engine' => $storage['default'],
            'storage_domain' => StorageConfigService::getEffectiveDomain($tenantId),
            'provider' => (string)($params['provider'] ?? 'xhadmin'),
            'provider_asset_id' => trim((string)($params['provider_asset_id'] ?? '')),
            'status' => 'ready',
            'sort' => 0,
            'create_time' => time(),
            'update_time' => time(),
            'delete_time' => 0,
        ]);
        return self::formatAvatar($row->toArray());
    }

    public static function deleteAvatar(int $tenantId, int $userId, int $id): void
    {
        if ($id <= 0) {
            throw new Exception('形象不存在');
        }
        $row = ImageHumanAvatar::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'id' => $id, 'source' => 'mine'])
            ->where('delete_time', 0)
            ->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('形象不存在');
        }
        $row->save(['delete_time' => time(), 'update_time' => time()]);
    }

    public static function voiceLists(int $tenantId, int $userId, string $source = ''): array
    {
        $query = ImageHumanVoice::where('tenant_id', $tenantId)
            ->where('delete_time', 0)
            ->whereRaw("(source = 'official' OR (source = 'mine' AND user_id = " . (int)$userId . '))')
            ->order(['source' => 'asc', 'sort' => 'desc', 'id' => 'desc']);
        if (in_array($source, ['official', 'mine'], true)) {
            $query->where('source', $source);
        }
        return array_map([self::class, 'formatVoice'], $query->select()->toArray());
    }

    public static function saveVoice(int $tenantId, int $userId, array $params): array
    {
        $name = self::normalizeAssetText((string)($params['name'] ?? '我的参考音频'), '我的参考音频', 80);
        $audioUri = self::normalizeAssetUri((string)($params['audio_uri'] ?? $params['ref_file_uri'] ?? ''));
        if ($audioUri === '') {
            throw new Exception('请上传参考音频');
        }
        $duration = self::normalizeDuration($params['duration'] ?? 0);
        if ($duration <= 0) {
            $duration = self::detectAudioDurationFromUri($audioUri, $tenantId);
        }
        $storage = StorageConfigService::getEffectiveConfig($tenantId);
        $row = ImageHumanVoice::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'name' => $name,
            'source' => 'mine',
            'gender' => self::normalizeAssetText((string)($params['gender'] ?? ''), '', 20),
            'age_group' => self::normalizeAssetText((string)($params['age_group'] ?? ''), '', 20),
            'cover_uri' => self::normalizeAssetUri((string)($params['cover_uri'] ?? '')),
            'audio_uri' => $audioUri,
            'storage_scope' => $storage['scope'],
            'storage_engine' => $storage['default'],
            'storage_domain' => StorageConfigService::getEffectiveDomain($tenantId),
            'duration' => $duration,
            'provider' => (string)($params['provider'] ?? 'xhadmin'),
            'provider_asset_id' => trim((string)($params['provider_asset_id'] ?? '')),
            'status' => 'ready',
            'sort' => 0,
            'create_time' => time(),
            'update_time' => time(),
            'delete_time' => 0,
        ]);
        return self::formatVoice($row->toArray());
    }

    public static function deleteVoice(int $tenantId, int $userId, int $id): void
    {
        if ($id <= 0) {
            throw new Exception('参考音频不存在');
        }
        $row = ImageHumanVoice::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'id' => $id, 'source' => 'mine'])
            ->where('delete_time', 0)
            ->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('参考音频不存在');
        }
        $row->save(['delete_time' => time(), 'update_time' => time()]);
    }

    public static function submit(int $tenantId, int $userId, array $params): array
    {
        $prompt = trim((string)($params['prompt'] ?? ''));
        $avatarId = (int)($params['avatar_id'] ?? 0);
        $voiceId = (int)($params['voice_id'] ?? 0);
        $avatar = $avatarId > 0 ? self::findAvatar($tenantId, $userId, $avatarId) : [];
        $voice = $voiceId > 0 ? self::findVoice($tenantId, $userId, $voiceId) : [];
        $imageUri = FileService::setFileUrl((string)($avatar['image_uri'] ?? $params['image_uri'] ?? $params['file_uri'] ?? $params['file_url'] ?? ''));
        $audioUri = FileService::setFileUrl((string)($voice['audio_uri'] ?? $params['audio_uri'] ?? $params['ref_file_uri'] ?? $params['ref_file_url'] ?? ''));
        $mode = self::normalizeMode((string)($params['mode'] ?? 'fast'));
        if ($imageUri === '') {
            throw new Exception('请上传人物图片');
        }
        if ($audioUri === '') {
            throw new Exception('请上传参考音频');
        }
        if ($prompt === '') {
            throw new Exception('请输入提示词');
        }
        $duration = self::normalizeDuration($params['duration'] ?? 0);
        if ($duration <= 0 && !empty($voice['duration'])) {
            $duration = self::normalizeDuration($voice['duration']);
        }
        if ($duration <= 0) {
            $duration = self::detectAudioDurationFromUri($audioUri, $tenantId);
        }
        if ($duration <= 0) {
            throw new Exception('请填写音频时长');
        }
        $config = self::effectiveConfig($tenantId);
        if ((int)$config['status'] !== 1) {
            throw new Exception('全驱动数字人应用未启用');
        }
        $estimate = self::buildEstimate($duration, self::pricing($tenantId), $mode);
        PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);
        $duplicate = self::findRecentDuplicateTask($tenantId, $userId, $imageUri, $audioUri, $prompt, $mode, $duration);
        if ($duplicate) {
            return self::duplicateResponse($duplicate, $tenantId, $userId);
        }

        $task = ImageHumanTask::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'avatar_id' => $avatarId,
            'voice_id' => $voiceId,
            'title' => trim((string)($params['title'] ?? '全驱动数字人')) ?: '全驱动数字人',
            'image_uri' => $imageUri,
            'audio_uri' => $audioUri,
            'prompt' => $prompt,
            'mode' => $mode,
            'duration' => $duration,
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
            'provider' => (string)$config['provider'],
            'model' => (string)$config['model'],
            'provider_task_id' => '',
            'provider_payload_json' => [],
            'status' => 'running',
            'progress' => 10,
            'error' => '',
            'create_time' => time(),
            'update_time' => time(),
            'finish_time' => 0,
            'delete_time' => 0,
        ]);

        $request = self::buildRequestFromTask($task, $config);
        $result = self::providerFor((string)$config['provider'])->submit($request);
        $task->provider_task_id = $result->providerTaskId;
        $task->provider_payload_json = self::mergePayload($task, $result->payload);
        $task->update_time = time();
        if (!$result->success) {
            $task->status = 'failed';
            $task->progress = 100;
            $task->error = $result->error ?: '提交失败';
            $task->finish_time = time();
            $task->save();
            return ['task_id' => (int)$task['id'], 'status' => 'failed', 'results' => [], 'error' => $task->error];
        }
        $task->progress = 30;
        $task->save();
        return ['task_id' => (int)$task['id'], 'status' => 'running', 'results' => []];
    }

    public static function taskLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        self::refreshRunningTasks($tenantId, $userId, (int)($params['id'] ?? $params['task_id'] ?? 0));
        $query = ImageHumanTask::where('tenant_id', $tenantId)->where('delete_time', 0)->order('id', 'desc');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $query->where('status', $status);
        }
        $taskId = (int)($params['id'] ?? $params['task_id'] ?? 0);
        if ($taskId > 0) {
            $query->where('id', $taskId);
        }
        $rows = $query->limit(100)->select()->toArray();
        return self::attachResults($tenantId, $rows);
    }

    public static function taskDetail(int $tenantId, int $taskId, int $userId = 0): array
    {
        self::refreshRunningTasks($tenantId, $userId, $taskId);
        $query = ImageHumanTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        $rows = self::attachResults($tenantId, [$task->toArray()]);
        return $rows[0] ?? [];
    }

    public static function resultLists(int $tenantId, int $userId = 0, int $taskId = 0, string $status = ''): array
    {
        self::refreshRunningTasks($tenantId, $userId, $taskId);
        $params = [];
        if ($taskId > 0) {
            $params['task_id'] = $taskId;
        }
        if ($status !== '') {
            $params['status'] = $status;
        }
        return self::taskLists($tenantId, $userId, $params);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = ImageHumanTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        $task->save(['delete_time' => time(), 'update_time' => time()]);
        ImageHumanResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time()]);
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = ImageHumanResult::where(['tenant_id' => $tenantId, 'id' => $resultId]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $result = $query->findOrEmpty();
        if ($result->isEmpty()) {
            throw new Exception('作品不存在');
        }
        $result->save(['delete_time' => time()]);
    }

    private static function refreshRunningTasks(int $tenantId, int $userId = 0, int $taskId = 0): void
    {
        $query = ImageHumanTask::where('tenant_id', $tenantId)
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
            $config = self::effectiveConfig((int)$task['tenant_id']);
            $request = self::buildRequestFromTask($task, $config);
            $result = self::providerFor((string)$task['provider'])->query((string)$task['provider_task_id'], $request);
            $task->provider_payload_json = self::mergePayload($task, $result->payload);
            $task->update_time = time();
            if ($result->pending) {
                $task->progress = max(50, (int)$task['progress']);
                $task->save();
                continue;
            }
            if (!$result->success) {
                $task->status = 'failed';
                $task->progress = 100;
                $task->error = $result->error ?: '生成失败';
                $task->finish_time = time();
                $task->save();
                continue;
            }
            if (!empty($result->videos)) {
                self::finishTaskWithVideos($task, $result->videos);
            }
        }
    }

    private static function finishTaskWithVideos(ImageHumanTask $task, array $videos): array
    {
        Db::startTrans();
        try {
            $tenantId = (int)$task['tenant_id'];
            $userId = (int)$task['user_id'];
            $locked = ImageHumanTask::where('tenant_id', $tenantId)
                ->where('id', (int)$task['id'])
                ->lock(true)
                ->findOrEmpty();
            if ($locked->isEmpty()) {
                throw new Exception('任务不存在');
            }
            $existing = self::existingResultRows($tenantId, $userId, (int)$locked['id']);
            if ((string)$locked['status'] === 'success' || !empty($existing)) {
                if ((string)$locked['status'] !== 'success') {
                    $locked->save(['status' => 'success', 'progress' => 100, 'finish_time' => $locked['finish_time'] ?: time(), 'update_time' => time()]);
                }
                Db::commit();
                return $existing;
            }
            $video = self::firstVideo($videos);
            if (empty($video)) {
                throw new Exception('供应商视频格式错误');
            }
            $storage = StorageConfigService::getEffectiveConfig($tenantId);
            $row = ImageHumanResult::create([
                'tenant_id' => $tenantId,
                'task_id' => (int)$locked['id'],
                'user_id' => $userId,
                'avatar_id' => (int)$locked['avatar_id'],
                'voice_id' => (int)$locked['voice_id'],
                'title' => (string)$locked['title'],
                'cover_uri' => (string)$locked['image_uri'],
                'video_uri' => (string)$video['uri'],
                'storage_scope' => $storage['scope'],
                'storage_engine' => $storage['default'],
                'storage_domain' => StorageConfigService::getEffectiveDomain($tenantId),
                'width' => (int)($video['width'] ?? 0),
                'height' => (int)($video['height'] ?? 0),
                'duration' => (float)($video['duration'] ?? $locked['duration']),
                'tenant_cost_points' => $locked['tenant_cost_points'],
                'user_charge_points' => $locked['user_charge_points'],
                'provider_task_id' => (string)($video['provider_task_id'] ?? $locked['provider_task_id']),
                'delete_time' => 0,
                'create_time' => time(),
            ]);
            $sourceSn = (string)$locked['id'] . '-1';
            PointService::consumeBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$locked['tenant_cost_points'], (float)$locked['user_charge_points'], $sourceSn, '全驱动数字人消费', [
                'app_code' => self::APP_CODE,
                'task_id' => (int)$locked['id'],
                'result_id' => (int)$row['id'],
                'mode' => (string)$locked['mode'],
                'duration' => (float)$locked['duration'],
            ]);
            ImageHumanBilling::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'task_id' => (int)$locked['id'],
                'result_id' => (int)$row['id'],
                'mode' => (string)$locked['mode'],
                'duration' => (float)$locked['duration'],
                'platform_unit_cost' => self::pricing($tenantId)['platform_unit_cost'],
                'tenant_unit_price' => self::pricing($tenantId)['tenant_unit_price'],
                'tenant_cost_points' => $locked['tenant_cost_points'],
                'user_charge_points' => $locked['user_charge_points'],
                'billing_status' => 'deducted',
                'tenant_point_sn' => $sourceSn,
                'user_point_sn' => $sourceSn,
                'create_time' => time(),
                'update_time' => time(),
            ]);
            $locked->save([
                'status' => 'success',
                'progress' => 100,
                'provider_task_id' => (string)($video['provider_task_id'] ?? $locked['provider_task_id']),
                'finish_time' => time(),
                'update_time' => time(),
            ]);
            Db::commit();
            return self::existingResultRows($tenantId, $userId, (int)$locked['id']);
        } catch (\Throwable $e) {
            Db::rollback();
            ImageHumanTask::where(['tenant_id' => (int)$task['tenant_id'], 'id' => (int)$task['id']])->update([
                'status' => 'failed',
                'progress' => 100,
                'error' => $e->getMessage(),
                'finish_time' => time(),
                'update_time' => time(),
            ]);
            return [];
        }
    }

    private static function effectiveConfig(int $tenantId): array
    {
        $platform = ImageHumanConfig::where('tenant_id', 0)->findOrEmpty();
        $tenant = $tenantId > 0 ? ImageHumanConfig::where('tenant_id', $tenantId)->findOrEmpty() : null;
        $base = $platform->isEmpty() ? [
            'provider' => 'xhadmin',
            'model' => 'image_human',
            'config_json' => [],
            'status' => 1,
        ] : $platform->toArray();
        if ($tenant && !$tenant->isEmpty()) {
            $tenantData = $tenant->toArray();
            $base = array_merge($base, array_filter($tenantData, static fn($value) => $value !== null && $value !== ''));
            $base['config_json'] = array_replace_recursive((array)($platform['config_json'] ?? []), (array)($tenantData['config_json'] ?? []));
        }
        $base['config_json'] = is_array($base['config_json'] ?? null) ? $base['config_json'] : [];
        return $base;
    }

    private static function pricing(int $tenantId): array
    {
        $config = self::effectiveConfig($tenantId);
        $pricing = (array)($config['config_json']['pricing'] ?? []);
        return [
            'platform_unit_cost' => max(0, (float)($pricing['platform_unit_cost'] ?? 1.666667)),
            'tenant_unit_price' => max(0, (float)($pricing['tenant_unit_price'] ?? 2.0)),
            'billing_unit' => (string)($pricing['billing_unit'] ?? 'second'),
        ];
    }

    private static function buildEstimate(float $duration, array $pricing, string $mode): array
    {
        $duration = max(1, $duration);
        return [
            'mode' => $mode,
            'duration' => $duration,
            'billing_unit' => $pricing['billing_unit'] ?? 'second',
            'billable_quantity' => $duration,
            'platform_unit_cost' => $pricing['platform_unit_cost'],
            'tenant_unit_price' => $pricing['tenant_unit_price'],
            'tenant_cost_points' => number_format($duration * (float)$pricing['platform_unit_cost'], 2, '.', ''),
            'user_charge_points' => number_format($duration * (float)$pricing['tenant_unit_price'], 2, '.', ''),
        ];
    }

    private static function buildRequestFromTask(ImageHumanTask $task, array $config): ImageHumanGenerateRequest
    {
        return new ImageHumanGenerateRequest(
            self::fileUrlForTenant((string)$task['image_uri'], (int)$task['tenant_id']),
            self::fileUrlForTenant((string)$task['audio_uri'], (int)$task['tenant_id']),
            (string)$task['prompt'],
            (float)$task['duration'],
            (string)$task['mode'],
            [],
            array_merge($config['config_json'] ?? [], [
                'model' => $config['model'] ?? 'image_human',
                'tenant_id' => (int)$task['tenant_id'],
                'user_id' => (int)$task['user_id'],
            ])
        );
    }

    private static function attachResults(int $tenantId, array $tasks): array
    {
        $taskIds = array_values(array_unique(array_filter(array_column($tasks, 'id'))));
        $resultMap = [];
        if (!empty($taskIds)) {
            $results = ImageHumanResult::where('tenant_id', $tenantId)
                ->where('delete_time', 0)
                ->whereIn('task_id', $taskIds)
                ->order('id', 'asc')
                ->select()
                ->toArray();
            foreach ($results as $result) {
                $result = self::formatResult($result);
                $resultMap[(int)$result['task_id']][] = $result;
            }
        }
        foreach ($tasks as &$task) {
            $task['task_id'] = (int)$task['id'];
            $task['image_url'] = self::fileUrlForTenant((string)($task['image_uri'] ?? ''), $tenantId);
            $task['audio_url'] = self::fileUrlForTenant((string)($task['audio_uri'] ?? ''), $tenantId);
            $task['script_text'] = (string)($task['prompt'] ?? '');
            $task['results'] = $resultMap[(int)$task['id']] ?? [];
            $task['result_count'] = count($task['results']);
            $first = $task['results'][0] ?? [];
            $task['result_id'] = (int)($first['id'] ?? 0);
            $task['video_uri'] = (string)($first['video_uri'] ?? '');
            $task['video_url'] = (string)($first['video_url'] ?? '');
        }
        return $tasks;
    }

    private static function existingResultRows(int $tenantId, int $userId, int $taskId): array
    {
        $query = ImageHumanResult::where('tenant_id', $tenantId)
            ->where('task_id', $taskId)
            ->where('delete_time', 0)
            ->order('id', 'asc');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        return array_map([self::class, 'formatResult'], $query->select()->toArray());
    }

    private static function formatResult(array $row): array
    {
        $row['cover_url'] = self::fileUrlForTenant((string)($row['cover_uri'] ?? ''), (int)($row['tenant_id'] ?? 0), $row);
        $row['video_url'] = FileService::getFileUrlByStorage(
            (string)($row['video_uri'] ?? ''),
            (string)($row['storage_scope'] ?? ''),
            (string)($row['storage_engine'] ?? ''),
            (string)($row['storage_domain'] ?? '')
        );
        return $row;
    }

    private static function formatAvatar(array $row): array
    {
        $tenantId = (int)($row['tenant_id'] ?? 0);
        $imageUri = (string)($row['image_uri'] ?? $row['media_uri'] ?? '');
        $row['image_uri'] = $imageUri;
        $row['media_uri'] = (string)($row['media_uri'] ?? $imageUri);
        $row['media_type'] = (string)($row['media_type'] ?? 'image') ?: 'image';
        $row['cover_url'] = self::fileUrlForTenant((string)($row['cover_uri'] ?? $imageUri), $tenantId, $row);
        $row['image_url'] = self::fileUrlForTenant($imageUri, $tenantId, $row);
        $row['media_url'] = self::fileUrlForTenant((string)$row['media_uri'], $tenantId, $row);
        return $row;
    }

    private static function formatVoice(array $row): array
    {
        $tenantId = (int)($row['tenant_id'] ?? 0);
        $row['cover_url'] = self::fileUrlForTenant((string)($row['cover_uri'] ?? ''), $tenantId, $row);
        $row['audio_url'] = self::fileUrlForTenant((string)($row['audio_uri'] ?? ''), $tenantId, $row);
        $row['preview_url'] = $row['audio_url'];
        return $row;
    }

    private static function findAvatar(int $tenantId, int $userId, int $id, bool $throw = true): array
    {
        $query = ImageHumanAvatar::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->where('delete_time', 0)
            ->whereRaw("(source = 'official' OR (source = 'mine' AND user_id = " . (int)$userId . '))');
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            if ($throw) {
                throw new Exception('形象不存在');
            }
            return [];
        }
        return $row->toArray();
    }

    private static function findVoice(int $tenantId, int $userId, int $id, bool $throw = true): array
    {
        $query = ImageHumanVoice::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->where('delete_time', 0)
            ->whereRaw("(source = 'official' OR (source = 'mine' AND user_id = " . (int)$userId . '))');
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            if ($throw) {
                throw new Exception('参考音频不存在');
            }
            return [];
        }
        return $row->toArray();
    }

    private static function normalizeAssetText(string $value, string $fallback, int $maxLength): string
    {
        $value = trim(strip_tags($value));
        if ($value === '') {
            $value = $fallback;
        }
        return mb_substr($value, 0, $maxLength);
    }

    private static function normalizeAssetUri(string $uri): string
    {
        return FileService::setFileUrl(trim($uri));
    }

    private static function fileUrlForTenant(string $uri, int $tenantId, array $storage = []): string
    {
        if ($uri === '' || str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://') || str_starts_with($uri, 'data:')) {
            return $uri;
        }
        if (!empty($storage['storage_engine']) || !empty($storage['storage_domain'])) {
            return FileService::getFileUrlByStorage(
                $uri,
                (string)($storage['storage_scope'] ?? ''),
                (string)($storage['storage_engine'] ?? ''),
                (string)($storage['storage_domain'] ?? '')
            );
        }
        return FileService::getFileUrl($uri);
    }

    private static function normalizeMode(string $mode): string
    {
        return in_array($mode, ['fast', 'standard'], true) ? $mode : 'fast';
    }

    private static function normalizeDuration(mixed $duration): float
    {
        return round(max(0, (float)$duration), 2);
    }

    private static function detectAudioDurationFromUri(string $audioUri, int $tenantId = 0): float
    {
        if ($audioUri === '') {
            return 0;
        }
        foreach (self::candidateLocalAudioPaths($audioUri) as $filePath) {
            if (is_file($filePath)) {
                $duration = self::estimateMp3Duration($filePath);
                if ($duration > 0) {
                    return $duration;
                }
            }
        }
        return 0;
    }

    private static function candidateLocalAudioPaths(string $audioUri): array
    {
        $path = ltrim((string)parse_url($audioUri, PHP_URL_PATH), '/');
        if ($path === '') {
            $path = ltrim($audioUri, '/');
        }
        $root = dirname(app()->getRootPath());
        return array_values(array_unique([
            public_path() . $path,
            public_path() . ltrim(str_replace('server/public/', '', $path), '/'),
            $root . '/server/public/' . $path,
            $root . '/' . $path,
        ]));
    }

    private static function estimateMp3Duration(string $filePath): float
    {
        $size = filesize($filePath);
        if (!$size || $size <= 0) {
            return 0;
        }
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return 0;
        }
        $header = fread($handle, 10);
        $offset = 0;
        if (strlen($header) === 10 && substr($header, 0, 3) === 'ID3') {
            $offset = ((ord($header[6]) & 0x7f) << 21) | ((ord($header[7]) & 0x7f) << 14) | ((ord($header[8]) & 0x7f) << 7) | (ord($header[9]) & 0x7f);
            $offset += 10;
        }
        fseek($handle, $offset);
        $frame = fread($handle, 4);
        fclose($handle);
        if (strlen($frame) < 4 || ord($frame[0]) !== 0xff) {
            return 0;
        }
        $bitrateIndex = (ord($frame[2]) >> 4) & 0x0f;
        $bitrates = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0];
        $bitrate = $bitrates[$bitrateIndex] ?? 0;
        if ($bitrate <= 0) {
            return 0;
        }
        return round(($size * 8) / ($bitrate * 1000), 2);
    }

    private static function findRecentDuplicateTask(int $tenantId, int $userId, string $imageUri, string $audioUri, string $prompt, string $mode, float $duration): ?ImageHumanTask
    {
        $rows = ImageHumanTask::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('delete_time', 0)
            ->where('image_uri', $imageUri)
            ->where('audio_uri', $audioUri)
            ->where('prompt', $prompt)
            ->where('mode', $mode)
            ->where('duration', $duration)
            ->where('create_time', '>=', time() - self::DUPLICATE_WINDOW_SECONDS)
            ->order('id', 'desc')
            ->limit(5)
            ->select();
        foreach ($rows as $row) {
            if (in_array((string)$row['status'], ['failed', 'canceled'], true)) {
                continue;
            }
            return $row;
        }
        return null;
    }

    private static function duplicateResponse(ImageHumanTask $task, int $tenantId, int $userId): array
    {
        if ((string)$task['status'] === 'running') {
            self::refreshRunningTasks($tenantId, $userId, (int)$task['id']);
        }
        $detail = self::taskDetail($tenantId, (int)$task['id'], $userId);
        return [
            'task_id' => (int)$detail['id'],
            'status' => (string)$detail['status'],
            'results' => $detail['results'] ?? [],
            'error' => (string)($detail['error'] ?? ''),
        ];
    }

    private static function mergePayload(ImageHumanTask $task, array $payload): array
    {
        $current = $task['provider_payload_json'] ?? [];
        if (!is_array($current)) {
            $current = [];
        }
        return array_merge($current, $payload);
    }

    private static function firstVideo(array $videos): array
    {
        foreach ($videos as $video) {
            if (is_array($video) && trim((string)($video['uri'] ?? '')) !== '') {
                return $video;
            }
        }
        return [];
    }

    private static function providerFor(string $provider): ImageHumanProviderInterface
    {
        return new XhadminImageHumanProvider();
    }
}
