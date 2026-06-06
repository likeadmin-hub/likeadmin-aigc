<?php

namespace app\common\service\app\smart_clip;

use app\common\model\app\smart_clip\SmartClipBilling;
use app\common\model\app\smart_clip\SmartClipConfig;
use app\common\model\app\smart_clip\SmartClipResult;
use app\common\model\app\smart_clip\SmartClipSensitiveWord;
use app\common\model\app\smart_clip\SmartClipTask;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\FileService;
use app\common\service\point\PointService;
use app\common\service\storage\StorageConfigService;
use Exception;
use think\facade\Db;

class SmartClipService
{
    public const APP_CODE = 'smart_clip';
    public const API_REALMAN = 'realman_broadcast';
    public const API_MATERIAL = 'broadcast_mixcut';
    public const API_NEWS = 'news_mixcut';

    private const DUPLICATE_WINDOW_SECONDS = 6;
    private const MAX_DURATION = 300;

    public static function clipTypeOptions(): array
    {
        return [
            ['value' => self::API_REALMAN, 'label' => '真人口播混剪', 'scene' => 'realMan'],
            ['value' => self::API_MATERIAL, 'label' => '素材混剪', 'scene' => 'oralMixCutting'],
            ['value' => self::API_NEWS, 'label' => '新闻体视频', 'scene' => 'newsMixCutting'],
        ];
    }

    public static function config(int $tenantId): array
    {
        $config = SmartClipConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $data = $config->isEmpty() ? [
            'provider_mode' => 'platform',
            'provider' => 'xhadmin',
            'model' => 'smart_clip',
            'status' => 1,
            'config_json' => [],
        ] : $config->toArray();
        $data['option_config'] = SmartClipChannelService::userConfig($tenantId);
        $data['clip_types'] = self::clipTypeOptions();
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, $data);
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        $data = [
            'tenant_id' => $tenantId,
            'provider_mode' => $params['provider_mode'] ?? 'platform',
            'provider' => $params['provider'] ?? 'xhadmin',
            'model' => $params['model'] ?? 'smart_clip',
            'config_json' => $params['config_json'] ?? [],
            'status' => $params['status'] ?? 1,
            'update_time' => time(),
        ];
        $row = SmartClipConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            SmartClipConfig::create($data);
            return;
        }
        $row->save($data);
    }

    public static function templateLists(int $tenantId, array $params): array
    {
        $scene = trim((string)($params['scene'] ?? ''));
        if ($scene === '') {
            $scene = self::sceneForApi((string)($params['api'] ?? self::API_REALMAN));
        }
        return self::providerFor('xhadmin')->templateLists($scene, $params);
    }

    public static function templateDetail(int $tenantId, string $id): array
    {
        return self::providerFor('xhadmin')->templateDetail($id);
    }

    public static function estimate(int $tenantId, array $params): array
    {
        self::normalizeSubmit($params, false);
        return SmartClipChannelService::estimate($tenantId, $params);
    }

    public static function generate(int $tenantId, int $userId, array $params): array
    {
        $payload = self::normalizeSubmit($params, true);
        self::checkSensitiveWords($tenantId, implode(' ', array_filter([
            $payload['title'],
            $payload['introduceCard']['name'] ?? '',
            $payload['introduceCard']['description'] ?? '',
        ])));
        $selection = SmartClipChannelService::resolveSelection($tenantId, $params);
        $estimate = SmartClipChannelService::estimate($tenantId, $params);
        PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);

        $duplicate = self::findRecentDuplicateTask($tenantId, $userId, $payload);
        if ($duplicate) {
            return self::duplicateResponse($duplicate, $tenantId, $userId);
        }

        $task = SmartClipTask::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'clip_type' => $payload['api'],
            'scene' => self::sceneForApi($payload['api']),
            'style_id' => $payload['styleId'],
            'title' => $payload['title'],
            'video_url' => $payload['videoUrl'],
            'audio_url' => $payload['audioUrl'],
            'materials' => $payload['materials'],
            'introduce_card' => $payload['introduceCard'],
            'pack_rules' => $payload['packRules'],
            'process_rules' => $payload['processRules'],
            'struct_layers' => $payload['structLayers'],
            'subtitle' => $payload['subtitle'],
            'source_app' => $payload['source_app'],
            'source_result_id' => $payload['source_result_id'],
            'duration' => (int)$estimate['duration'],
            'quantity' => (int)$estimate['quantity'],
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
            'provider' => $selection['channel']['provider'],
            'model' => $selection['channel']['model'],
            'channel' => $selection['channel']['code'],
            'quality' => $selection['spec']['quality'],
            'ratio' => $selection['spec']['ratio'],
            'status' => 'running',
            'error' => '',
            'delete_time' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $request = self::buildRequest($task, $selection, $payload);
        $result = self::providerFor((string)$selection['channel']['provider'])->generate($request);
        $task->provider_task_id = $result->providerTaskId;
        $task->provider_payload = ['submit' => $result->raw];
        $task->update_time = time();
        if (!$result->success) {
            $task->status = 'failed';
            $task->error = $result->error;
            $task->finish_time = time();
        }
        $task->save();

        $results = [];
        if ($result->success && !empty($result->videos)) {
            $results = self::finishTaskWithVideos($task, $selection, $result->videos);
        }

        return [
            'task_id' => (int)$task['id'],
            'provider_task_id' => $result->providerTaskId,
            'status' => !empty($results) ? 'success' : ($result->success ? 'running' : 'failed'),
            'app' => self::APP_CODE,
            'api' => $payload['api'],
            'error' => $result->error,
            'results' => $results,
        ];
    }

    public static function taskLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        self::refreshRunningTasks($tenantId, $userId, 0, true);
        $query = SmartClipTask::alias('t')
            ->leftJoin('user u', 'u.id = t.user_id AND u.tenant_id = t.tenant_id')
            ->field('t.*,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
            ->where('t.tenant_id', $tenantId)
            ->where('t.delete_time', 0)
            ->order('t.id', 'desc');
        if ($userId > 0) {
            $query->where('t.user_id', $userId);
        }
        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $query->where('t.status', $status);
        }
        $taskId = (int)($params['task_id'] ?? $params['id'] ?? 0);
        if ($taskId > 0) {
            $query->where('t.id', $taskId);
        }
        $keyword = trim((string)($params['user_keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($query) use ($keyword) {
                $query->whereLike('u.nickname', '%' . $keyword . '%')
                    ->whereOrLike('u.account', '%' . $keyword . '%')
                    ->whereOrLike('u.mobile', '%' . $keyword . '%');
                if (ctype_digit($keyword)) {
                    $query->whereOr('t.user_id', (int)$keyword);
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
        $rows = self::attachTaskResults($tenantId, $query->select()->toArray());
        return $usePage ? ['lists' => $rows, 'count' => $count, 'page_no' => $pageNo, 'page_size' => $pageSize] : $rows;
    }

    public static function taskDetail(int $tenantId, int $taskId, int $userId = 0): array
    {
        self::refreshRunningTasks($tenantId, $userId, $taskId, true);
        $query = SmartClipTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
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

    public static function platformTaskLists(array $params = []): array
    {
        $tenantId = (int)($params['tenant_id'] ?? 0);
        if ($tenantId > 0) {
            return self::taskLists($tenantId, 0, $params);
        }
        $query = SmartClipTask::alias('t')
            ->leftJoin('user u', 'u.id = t.user_id AND u.tenant_id = t.tenant_id')
            ->field('t.*,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
            ->where('t.delete_time', 0)
            ->order('t.id', 'desc');
        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $query->where('t.status', $status);
        }
        $taskId = (int)($params['task_id'] ?? $params['id'] ?? 0);
        if ($taskId > 0) {
            $query->where('t.id', $taskId);
        }
        $keyword = trim((string)($params['user_keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($query) use ($keyword) {
                $query->whereLike('u.nickname', '%' . $keyword . '%')
                    ->whereOrLike('u.account', '%' . $keyword . '%')
                    ->whereOrLike('u.mobile', '%' . $keyword . '%');
                if (ctype_digit($keyword)) {
                    $query->whereOr('t.user_id', (int)$keyword);
                }
            });
        }
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['page_size'] ?? 15)));
        $count = (int)(clone $query)->count();
        $rows = $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();
        return [
            'lists' => self::attachPlatformTaskResults($rows),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ];
    }

    public static function platformTaskDetail(int $taskId): array
    {
        $task = SmartClipTask::where('id', $taskId)->where('delete_time', 0)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return self::taskDetail((int)$task['tenant_id'], $taskId);
    }

    public static function retryTask(int $tenantId, int $taskId): array
    {
        $task = self::taskDetail($tenantId, $taskId);
        return self::generate($tenantId, (int)$task['user_id'], [
            'api' => $task['clip_type'],
            'styleId' => $task['style_id'],
            'title' => $task['title'],
            'videoUrl' => $task['video_url'],
            'audioUrl' => $task['audio_url'],
            'materials' => $task['materials'] ?: [],
            'introduceCard' => $task['introduce_card'] ?: [],
            'packRules' => $task['pack_rules'] ?: [],
            'processRules' => $task['process_rules'] ?: [],
            'structLayers' => $task['struct_layers'] ?: [],
            'subtitle' => $task['subtitle'] ?: [],
            'duration' => (int)$task['duration'],
            'source_app' => $task['source_app'],
            'source_result_id' => $task['source_result_id'],
        ]);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = SmartClipTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
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
        SmartClipResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time()]);
    }

    public static function resultLists(int $tenantId, int $userId = 0, int $taskId = 0, string $status = ''): array
    {
        self::refreshRunningTasks($tenantId, $userId, $taskId, true);
        $query = SmartClipTask::where('tenant_id', $tenantId)->where('delete_time', 0)->order('id', 'desc');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($taskId > 0) {
            $query->where('id', $taskId);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        return self::attachTaskResults($tenantId, $query->limit(50)->select()->toArray());
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = SmartClipResult::where(['tenant_id' => $tenantId, 'id' => $resultId]);
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

    public static function sensitiveWordLists(int $tenantId, array $params = []): array
    {
        return self::paginateRows(SmartClipSensitiveWord::where('tenant_id', $tenantId)->order('id', 'desc'), $params, 200);
    }

    public static function saveSensitiveWord(int $tenantId, array $params): void
    {
        $word = trim((string)($params['word'] ?? ''));
        if ($word === '') {
            throw new Exception('请输入敏感词');
        }
        $id = (int)($params['id'] ?? 0);
        $data = ['tenant_id' => $tenantId, 'word' => $word, 'status' => (int)($params['status'] ?? 1), 'update_time' => time()];
        if ($id > 0) {
            $row = SmartClipSensitiveWord::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
            if ($row->isEmpty()) {
                throw new Exception('敏感词不存在');
            }
            $row->save($data);
            return;
        }
        $data['create_time'] = time();
        SmartClipSensitiveWord::create($data);
    }

    public static function stat(int $tenantId = 0): array
    {
        $task = SmartClipTask::where([])->where('delete_time', 0);
        $result = SmartClipResult::where([])->where('delete_time', 0);
        if ($tenantId > 0) {
            $task->where('tenant_id', $tenantId);
            $result->where('tenant_id', $tenantId);
        }
        return [
            'task_total' => (clone $task)->count(),
            'task_success' => (clone $task)->where('status', 'success')->count(),
            'task_failed' => (clone $task)->where('status', 'failed')->count(),
            'result_total' => (clone $result)->count(),
            'quota_total' => 0,
            'quota_used' => 0,
            'tenant_cost_points' => $tenantId > 0 ? SmartClipBilling::where('tenant_id', $tenantId)->sum('tenant_cost_points') : SmartClipBilling::where([])->sum('tenant_cost_points'),
            'user_charge_points' => $tenantId > 0 ? SmartClipBilling::where('tenant_id', $tenantId)->sum('user_charge_points') : SmartClipBilling::where([])->sum('user_charge_points'),
        ];
    }

    public static function estimateInputDuration(array $params): int
    {
        $api = (string)($params['api'] ?? self::API_REALMAN);
        $processRules = self::arrayValue($params['processRules'] ?? $params['process_rules'] ?? []);
        if ($api === self::API_NEWS && (int)($processRules['videoDuration'] ?? $processRules['video_duration'] ?? 0) > 0) {
            return self::assertDuration((int)($processRules['videoDuration'] ?? $processRules['video_duration']));
        }
        $duration = (float)($params['duration'] ?? $params['media_duration'] ?? 0);
        if ($duration <= 0 && $api === self::API_REALMAN) {
            $duration = (float)($params['video_duration'] ?? 0);
        }
        if ($duration <= 0 && $api === self::API_MATERIAL) {
            $duration = (float)($params['audio_duration'] ?? 0);
        }
        if ($duration <= 0) {
            foreach (self::normalizeMaterials((array)($params['materials'] ?? [])) as $material) {
                if ((float)($material['duration'] ?? 0) > 0) {
                    $duration += (float)$material['duration'];
                } elseif (($material['type'] ?? '') === 'image') {
                    $duration += 2;
                }
            }
        }
        if ($duration <= 0) {
            throw new Exception('无法解析有效输入媒体时长，请上传或选择带时长的视频/音频素材');
        }
        return self::assertDuration((int)ceil($duration));
    }

    private static function normalizeSubmit(array $params, bool $strict): array
    {
        $api = (string)($params['api'] ?? $params['clip_type'] ?? self::API_REALMAN);
        if (!in_array($api, [self::API_REALMAN, self::API_MATERIAL, self::API_NEWS], true)) {
            throw new Exception('剪辑类型不支持');
        }
        $styleId = trim((string)($params['styleId'] ?? $params['style_id'] ?? ''));
        if ($styleId === '') {
            throw new Exception('请选择剪辑模板');
        }
        $materials = self::normalizeMaterials((array)($params['materials'] ?? []));
        $videoUrl = trim((string)($params['videoUrl'] ?? $params['video_url'] ?? ''));
        $audioUrl = trim((string)($params['audioUrl'] ?? $params['audio_url'] ?? ''));
        $title = trim((string)($params['title'] ?? ''));
        if ($strict && $api === self::API_REALMAN && $videoUrl === '') {
            throw new Exception('请上传或选择真人口播视频');
        }
        if ($strict && $api !== self::API_REALMAN && empty($materials)) {
            throw new Exception('请添加剪辑素材');
        }
        if ($strict && $api === self::API_MATERIAL && $audioUrl === '') {
            throw new Exception('请上传驱动音频后再提交素材混剪');
        }
        if ($strict && $api === self::API_NEWS && $title === '') {
            throw new Exception('请输入新闻标题');
        }
        self::estimateInputDuration(array_merge($params, ['materials' => $materials, 'api' => $api]));
        return [
            'api' => $api,
            'styleId' => $styleId,
            'title' => $title,
            'videoUrl' => $videoUrl,
            'audioUrl' => $audioUrl,
            'language' => trim((string)($params['language'] ?? '')),
            'materials' => $materials,
            'introduceCard' => self::arrayValue($params['introduceCard'] ?? $params['introduce_card'] ?? []),
            'packRules' => self::normalizePackRules($params['packRules'] ?? $params['pack_rules'] ?? []),
            'processRules' => self::arrayValue($params['processRules'] ?? $params['process_rules'] ?? []),
            'structLayers' => (array)($params['structLayers'] ?? $params['struct_layers'] ?? []),
            'subtitle' => (array)($params['subtitle'] ?? $params['subtitles'] ?? []),
            'callbackUrl' => trim((string)($params['callbackUrl'] ?? $params['callback_url'] ?? '')),
            'source_app' => trim((string)($params['source_app'] ?? '')),
            'source_result_id' => (int)($params['source_result_id'] ?? 0),
        ];
    }

    private static function buildRequest(SmartClipTask $task, array $selection, array $payload): SmartClipGenerateRequest
    {
        $channelConfig = array_merge($selection['channel']['config_json'] ?? [], [
            'tenant_id' => (int)$task['tenant_id'],
            'user_id' => (int)$task['user_id'],
        ]);
        return new SmartClipGenerateRequest(
            $payload['api'],
            self::sceneForApi($payload['api']),
            $payload['styleId'],
            $payload['title'],
            $payload['videoUrl'],
            $payload['audioUrl'],
            $payload['language'],
            $payload['materials'],
            $payload['introduceCard'],
            $payload['packRules'],
            $payload['processRules'],
            $payload['structLayers'],
            $payload['subtitle'],
            $payload['callbackUrl'],
            ['app' => $payload['source_app'], 'result_id' => $payload['source_result_id']],
            $payload,
            $selection['spec'],
            $selection['spec']['provider_params_json'] ?? [],
            $channelConfig
        );
    }

    private static function refreshRunningTasks(int $tenantId, int $userId = 0, int $taskId = 0, bool $swallowErrors = false): void
    {
        $query = SmartClipTask::where('tenant_id', $tenantId)
            ->where('status', 'running')
            ->where('delete_time', 0)
            ->where('provider_task_id', '<>', '');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($taskId > 0) {
            $query->where('id', $taskId);
        }
        foreach ($query->limit(10)->select() as $task) {
            try {
                $selection = SmartClipChannelService::resolveSelection($tenantId, ['channel' => $task['channel']]);
                $payload = self::normalizeSubmit($task->toArray() + [
                    'api' => $task['clip_type'],
                    'styleId' => $task['style_id'],
                    'duration' => (int)$task['duration'],
                ], false);
                $provider = self::providerFor((string)$task['provider']);
                if (!method_exists($provider, 'fetchResult')) {
                    continue;
                }
                $result = $provider->fetchResult((string)$task['provider_task_id'], self::buildRequest($task, $selection, $payload));
                $payloadHistory = (array)($task['provider_payload'] ?: []);
                $payloadHistory['query'] = $result->raw;
                $task->provider_payload = $payloadHistory;
                if (!$result->success) {
                    $task->status = 'failed';
                    $task->error = $result->error ?: '剪辑失败';
                    $task->finish_time = time();
                    $task->update_time = time();
                    $task->save();
                    continue;
                }
                if (empty($result->videos)) {
                    $task->update_time = time();
                    $task->save();
                    continue;
                }
                self::finishTaskWithVideos($task, $selection, $result->videos);
            } catch (\Throwable $e) {
                if (!$swallowErrors) {
                    throw $e;
                }
            }
        }
    }

    private static function finishTaskWithVideos(SmartClipTask $task, array $selection, array $videos): array
    {
        Db::startTrans();
        try {
            $tenantId = (int)$task['tenant_id'];
            $userId = (int)$task['user_id'];
            $task = SmartClipTask::where('tenant_id', $tenantId)->where('id', (int)$task['id'])->lock(true)->findOrEmpty();
            if ($task->isEmpty()) {
                throw new Exception('任务不存在');
            }
            $existing = self::existingResultRows($tenantId, $userId, (int)$task['id']);
            if ((string)$task['status'] === 'success' || !empty($existing)) {
                Db::commit();
                return $existing;
            }
            $storage = StorageConfigService::getEffectiveConfig($tenantId);
            $domain = StorageConfigService::getEffectiveDomain($tenantId);
            $rows = [];
            foreach (array_slice($videos, 0, 1) as $index => $video) {
                $row = SmartClipResult::create([
                    'tenant_id' => $tenantId,
                    'task_id' => (int)$task['id'],
                    'user_id' => $userId,
                    'clip_type' => $task['clip_type'],
                    'style_id' => $task['style_id'],
                    'title' => $task['title'],
                    'video_uri' => $video['uri'],
                    'cover_uri' => (string)($video['cover_url'] ?? ''),
                    'storage_scope' => $storage['scope'],
                    'storage_engine' => $storage['default'],
                    'storage_domain' => $domain,
                    'duration' => (float)($video['duration'] ?? $task['duration']),
                    'tenant_cost_points' => $task['tenant_cost_points'],
                    'user_charge_points' => $task['user_charge_points'],
                    'provider_task_id' => $video['provider_task_id'] ?? $task['provider_task_id'],
                    'result_json' => $video['raw'] ?? [],
                    'delete_time' => 0,
                    'create_time' => time(),
                ]);
                $sourceSn = self::APP_CODE . '-' . (int)$task['id'] . '-' . ((int)$index + 1);
                PointService::consumeBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$task['tenant_cost_points'], (float)$task['user_charge_points'], $sourceSn, 'AI视频剪辑消费', [
                    'app_code' => self::APP_CODE,
                    'task_id' => (int)$task['id'],
                    'result_id' => (int)$row['id'],
                    'clip_type' => $task['clip_type'],
                    'duration' => (int)$task['duration'],
                ]);
                SmartClipBilling::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'task_id' => (int)$task['id'],
                    'result_id' => (int)$row['id'],
                    'channel' => $selection['channel']['code'],
                    'quality' => $selection['spec']['quality'],
                    'ratio' => $selection['spec']['ratio'],
                    'quantity' => (int)$task['quantity'],
                    'platform_unit_cost' => $selection['spec']['platform_unit_cost'],
                    'tenant_unit_price' => $selection['spec']['tenant_unit_price'],
                    'tenant_cost_points' => $task['tenant_cost_points'],
                    'user_charge_points' => $task['user_charge_points'],
                    'billing_status' => 'deducted',
                    'tenant_point_sn' => $sourceSn,
                    'user_point_sn' => $sourceSn,
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
                $item = $row->toArray();
                $item['video_url'] = self::fileUrl($item['video_uri'], $item);
                $rows[] = $item;
            }
            $task->status = 'success';
            $task->finish_time = time();
            $task->update_time = time();
            $task->save();
            Db::commit();
            return $rows;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    private static function attachTaskResults(int $tenantId, array $tasks): array
    {
        $ids = array_values(array_unique(array_filter(array_column($tasks, 'id'))));
        $map = [];
        if ($ids) {
            foreach (SmartClipResult::where('tenant_id', $tenantId)->where('delete_time', 0)->whereIn('task_id', $ids)->order('id', 'asc')->select()->toArray() as $row) {
                $row['video_url'] = self::fileUrl($row['video_uri'], $row);
                $row['cover_url'] = self::fileUrl($row['cover_uri'] ?? '', $row);
                $map[(int)$row['task_id']][] = $row;
            }
        }
        foreach ($tasks as &$task) {
            $results = $map[(int)$task['id']] ?? [];
            $first = $results[0] ?? [];
            $task['task_id'] = (int)$task['id'];
            $task['results'] = $results;
            $task['result_count'] = count($results);
            $task['result_id'] = (int)($first['id'] ?? 0);
            $task['video_url'] = (string)($first['video_url'] ?? '');
            $task['cover_url'] = (string)($first['cover_url'] ?? '');
        }
        return $tasks;
    }

    private static function attachPlatformTaskResults(array $tasks): array
    {
        if (empty($tasks)) {
            return [];
        }
        $taskIds = array_values(array_unique(array_filter(array_column($tasks, 'id'))));
        $map = [];
        foreach (SmartClipResult::where('delete_time', 0)->whereIn('task_id', $taskIds)->order('id', 'asc')->select()->toArray() as $row) {
            $row['video_url'] = self::fileUrl($row['video_uri'], $row);
            $row['cover_url'] = self::fileUrl($row['cover_uri'] ?? '', $row);
            $map[(int)$row['task_id']][] = $row;
        }
        foreach ($tasks as &$task) {
            $results = $map[(int)$task['id']] ?? [];
            $first = $results[0] ?? [];
            $task['task_id'] = (int)$task['id'];
            $task['results'] = $results;
            $task['result_count'] = count($results);
            $task['result_id'] = (int)($first['id'] ?? 0);
            $task['video_url'] = (string)($first['video_url'] ?? '');
            $task['cover_url'] = (string)($first['cover_url'] ?? '');
        }
        return $tasks;
    }

    private static function existingResultRows(int $tenantId, int $userId, int $taskId): array
    {
        $query = SmartClipResult::where('tenant_id', $tenantId)->where('task_id', $taskId)->where('delete_time', 0)->order('id', 'asc');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $rows = $query->select()->toArray();
        foreach ($rows as &$row) {
            $row['video_url'] = self::fileUrl($row['video_uri'], $row);
            $row['cover_url'] = self::fileUrl($row['cover_uri'] ?? '', $row);
        }
        return $rows;
    }

    private static function fileUrl(string $uri, array $row): string
    {
        if ($uri === '') {
            return '';
        }
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }
        return FileService::getFileUrlByStorage($uri, $row['storage_scope'] ?? '', $row['storage_engine'] ?? '', $row['storage_domain'] ?? '');
    }

    private static function normalizeMaterials(array $materials): array
    {
        $rows = [];
        foreach ($materials as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = strtolower((string)($item['type'] ?? ''));
            if (!in_array($type, ['image', 'video'], true)) {
                continue;
            }
            $url = trim((string)($item['fileUrl'] ?? $item['file_url'] ?? $item['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $rows[] = [
                'type' => $type,
                'fileUrl' => $url,
                'soundSwitch' => (bool)($item['soundSwitch'] ?? $item['sound_switch'] ?? false),
                'duration' => (float)($item['duration'] ?? 0),
                'name' => trim((string)($item['name'] ?? '')),
            ];
        }
        return array_slice($rows, 0, 50);
    }

    private static function normalizePackRules($value): array
    {
        $rules = self::arrayValue($value);
        if (empty($rules)) {
            return [];
        }

        $normalized = [];
        $switchMap = [
            'headerSwitch' => ['headerSwitch', 'header_switch', 'title'],
            'materialSwitch' => ['materialSwitch', 'material_switch', 'material'],
            'subtitleSwitch' => ['subtitleSwitch', 'subtitle_switch', 'subtitle'],
            'keywordSwitch' => ['keywordSwitch', 'keyword_switch', 'keyword'],
        ];
        foreach ($switchMap as $target => $aliases) {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $rules)) {
                    $normalized[$target] = self::boolValue($rules[$alias]);
                    break;
                }
            }
        }

        $backgroundMusic = self::arrayValue($rules['backgroundMusic'] ?? $rules['background_music'] ?? []);
        $hasBgmAlias = array_key_exists('bgm', $rules);
        if (!empty($backgroundMusic) || $hasBgmAlias) {
            $music = [];
            if (array_key_exists('audioSwitch', $backgroundMusic) || array_key_exists('audio_switch', $backgroundMusic) || $hasBgmAlias) {
                $music['audioSwitch'] = self::boolValue($backgroundMusic['audioSwitch'] ?? $backgroundMusic['audio_switch'] ?? $rules['bgm'] ?? false);
            }
            $audioUrl = trim((string)($backgroundMusic['audioUrl'] ?? $backgroundMusic['audio_url'] ?? ''));
            if ($audioUrl !== '') {
                $music['audioUrl'] = $audioUrl;
            }
            $volume = $backgroundMusic['volume'] ?? null;
            if ($volume === null || $volume === '') {
                $volume = 0.3;
            }
            if (is_numeric($volume)) {
                $music['volume'] = self::clampVolume((float)$volume);
            } else {
                $music['volume'] = 0.3;
            }
            $normalized['backgroundMusic'] = $music;
        }

        return $normalized;
    }

    private static function boolValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        $value = strtolower(trim((string)$value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private static function clampVolume(float $volume): float
    {
        return round(max(0, min(1, $volume)), 1);
    }

    private static function findRecentDuplicateTask(int $tenantId, int $userId, array $payload): ?SmartClipTask
    {
        $row = SmartClipTask::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('delete_time', 0)
            ->where('clip_type', $payload['api'])
            ->where('style_id', $payload['styleId'])
            ->where('title', $payload['title'])
            ->where('create_time', '>=', time() - self::DUPLICATE_WINDOW_SECONDS)
            ->whereNotIn('status', ['failed', 'canceled'])
            ->order('id', 'desc')
            ->findOrEmpty();
        return $row->isEmpty() ? null : $row;
    }

    private static function duplicateResponse(SmartClipTask $task, int $tenantId, int $userId): array
    {
        return [
            'task_id' => (int)$task['id'],
            'provider_task_id' => (string)$task['provider_task_id'],
            'status' => (string)$task['status'],
            'results' => (string)$task['status'] === 'success' ? self::existingResultRows($tenantId, $userId, (int)$task['id']) : [],
        ];
    }

    private static function sceneForApi(string $api): string
    {
        return match ($api) {
            self::API_MATERIAL => 'oralMixCutting',
            self::API_NEWS => 'newsMixCutting',
            default => 'realMan',
        };
    }

    private static function providerFor(string $provider): SmartClipProviderInterface
    {
        return match (strtolower($provider)) {
            'xhadmin', 'smart_clip' => new XhadminSmartClipProvider(),
            default => new MockSmartClipProvider(),
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

    private static function assertDuration(int $duration): int
    {
        if ($duration <= 0 || $duration > self::MAX_DURATION) {
            throw new Exception('输入媒体时长需在1-300秒内');
        }
        return $duration;
    }

    private static function checkSensitiveWords(int $tenantId, string $content): void
    {
        foreach (SmartClipSensitiveWord::where(['tenant_id' => $tenantId, 'status' => 1])->column('word') as $word) {
            if ($word !== '' && str_contains($content, $word)) {
                throw new Exception('内容包含敏感词');
            }
        }
    }

    private static function paginateRows($query, array $params, int $defaultLimit = 100): array
    {
        $usePage = isset($params['page_no']) || isset($params['page_size']);
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['page_size'] ?? 15)));
        if ($usePage) {
            $count = (int)(clone $query)->count();
            return ['lists' => $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray(), 'count' => $count, 'page_no' => $pageNo, 'page_size' => $pageSize];
        }
        return $query->limit($defaultLimit)->select()->toArray();
    }
}
