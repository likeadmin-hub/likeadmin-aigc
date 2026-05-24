<?php

namespace app\common\service\app\image_human;

use app\common\model\app\image_human\ImageHumanBilling;
use app\common\model\app\image_human\ImageHumanAvatar;
use app\common\model\app\image_human\ImageHumanConfig;
use app\common\model\app\image_human\ImageHumanResult;
use app\common\model\app\image_human\ImageHumanTask;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanVoice;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanAssetService;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanGenerateRequest;
use app\common\service\app\aigc_digital_human\AigcDigitalHumanService;
use app\common\service\app\aigc_digital_human\XhadminAigcDigitalHumanProvider;
use app\common\service\FileService;
use app\common\service\point\PointService;
use app\common\service\storage\StorageConfigService;
use Exception;
use think\facade\Db;

class ImageHumanService
{
    public const APP_CODE = 'image_human';
    private const PROMPT_MAX_LENGTH = 200;
    private const DUPLICATE_WINDOW_SECONDS = 6;

    public static function config(int $tenantId): array
    {
        $config = self::effectiveConfig($tenantId);
        return [
            'provider' => $config['provider'],
            'model' => $config['model'],
            'status' => $config['status'],
            'config_json' => $config['config_json'],
            'base_config' => self::baseConfig($tenantId),
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
        $driverAudioUri = FileService::setFileUrl((string)($params['driver_audio_uri'] ?? $params['audio_uri'] ?? $params['ref_file_uri'] ?? $params['ref_file_url'] ?? ''));
        if ($duration <= 0 && $driverAudioUri !== '') {
            $duration = self::detectAudioDurationFromUri($driverAudioUri, $tenantId);
        }
        if ($duration <= 0 && (int)($params['voice_id'] ?? 0) > 0) {
            $scriptText = trim((string)($params['script_text'] ?? $params['text'] ?? $params['prompt'] ?? ''));
            $duration = self::estimateScriptDuration($scriptText);
        }
        $pricing = self::pricing($tenantId);
        return self::buildEstimate($duration, $pricing, self::normalizeMode((string)($params['mode'] ?? 'fast')));
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        $current = self::effectiveConfig($tenantId);
        $configJson = is_array($params['config_json'] ?? null) ? $params['config_json'] : [];
        $currentPricing = (array)($current['config_json']['pricing'] ?? []);
        if (isset($params['pricing']) && is_array($params['pricing'])) {
            $incomingPricing = (array)$params['pricing'];
        } else {
            $incomingPricing = (array)($configJson['pricing'] ?? []);
        }
        if ($tenantId > 0) {
            $configJson['pricing'] = self::tenantPricingOverrides($incomingPricing, self::normalizePricing($currentPricing));
        } else {
            $configJson['pricing'] = self::normalizePricing(array_merge($currentPricing, $incomingPricing));
        }
        if (isset($params['provider_config']) && is_array($params['provider_config'])) {
            $configJson['provider'] = self::normalizeProviderConfig((array)$params['provider_config']);
        } else {
            $configJson['provider'] = self::normalizeProviderConfig(array_merge((array)($current['config_json']['provider'] ?? []), (array)($configJson['provider'] ?? [])));
        }
        if (isset($params['base_config']) && is_array($params['base_config'])) {
            $configJson['base_config'] = $params['base_config'];
        }
        if (isset($configJson['base']) && is_array($configJson['base'])) {
            $configJson['base_config'] = $configJson['base'];
            unset($configJson['base']);
        }
        $configJson['base_config'] = self::normalizeBaseConfig((array)($configJson['base_config'] ?? []));
        $data = [
            'tenant_id' => $tenantId,
            'provider' => trim((string)($params['provider'] ?? $current['provider'] ?? 'xhadmin')) ?: 'xhadmin',
            'model' => trim((string)($params['model'] ?? $current['model'] ?? 'image_human')) ?: 'image_human',
            'config_json' => $configJson,
            'status' => (int)($params['status'] ?? 1),
            'update_time' => time(),
        ];
        $row = ImageHumanConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            ImageHumanConfig::create($data);
            return;
        }
        $row->save($data);
    }

    public static function saveTenantPricing(int $tenantId, array $params): void
    {
        if ($tenantId <= 0) {
            self::saveConfig(0, $params);
            return;
        }
        $current = self::effectiveConfig($tenantId);
        $configJson = [];
        $incomingConfigJson = is_array($params['config_json'] ?? null) ? $params['config_json'] : [];
        $incomingPricing = (array)($params['pricing'] ?? ($incomingConfigJson['pricing'] ?? []));
        $configJson['pricing'] = self::tenantPricingOverrides($incomingPricing, self::normalizePricing((array)($current['config_json']['pricing'] ?? [])));
        if (isset($params['base_config']) && is_array($params['base_config'])) {
            $configJson['base_config'] = self::normalizeBaseConfig($params['base_config']);
        } elseif (isset($incomingConfigJson['base_config']) && is_array($incomingConfigJson['base_config'])) {
            $configJson['base_config'] = self::normalizeBaseConfig($incomingConfigJson['base_config']);
        }
        $data = [
            'tenant_id' => $tenantId,
            'provider' => (string)($current['provider'] ?? 'xhadmin'),
            'model' => (string)($current['model'] ?? 'image_human'),
            'config_json' => $configJson,
            'status' => (int)($current['status'] ?? 1),
            'update_time' => time(),
        ];
        $row = ImageHumanConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            ImageHumanConfig::create($data);
            return;
        }
        $row->save($data);
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

    public static function publicAvatarLists(int $tenantId, array $params = []): array
    {
        return self::paginateRows(ImageHumanAvatar::where([
            'tenant_id' => $tenantId,
            'source' => 'official',
            'user_id' => 0,
        ])->where('delete_time', 0)->order(['sort' => 'desc', 'id' => 'desc']), $params, 100, [self::class, 'formatAvatar']);
    }

    public static function savePublicAvatar(int $tenantId, array $params): array
    {
        $name = self::normalizeAssetText((string)($params['name'] ?? '公共图片形象'), '公共图片形象', 80);
        $imageUri = self::normalizeAssetUri((string)($params['image_uri'] ?? $params['media_uri'] ?? $params['cover_uri'] ?? ''));
        $coverUri = self::normalizeAssetUri((string)($params['cover_uri'] ?? $imageUri));
        self::assertPersistedAssetUri($imageUri, '图片形象');
        if ($coverUri !== '') {
            self::assertPersistedAssetUri($coverUri, '形象封面');
        }
        if ($imageUri === '') {
            throw new Exception('请上传图片形象');
        }
        $storage = StorageConfigService::getEffectiveConfig($tenantId);
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => 0,
            'name' => $name,
            'source' => 'official',
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
            'status' => (string)($params['status'] ?? 'ready') ?: 'ready',
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
            'delete_time' => 0,
        ];
        $id = (int)($params['id'] ?? 0);
        if ($id > 0) {
            $row = ImageHumanAvatar::where(['tenant_id' => $tenantId, 'id' => $id, 'source' => 'official', 'user_id' => 0])->findOrEmpty();
            if ($row->isEmpty()) {
                throw new Exception('公共图片形象不存在');
            }
            $row->save($data);
            return self::formatAvatar($row->toArray());
        }
        $data['create_time'] = time();
        return self::formatAvatar(ImageHumanAvatar::create($data)->toArray());
    }

    public static function deletePublicAvatar(int $tenantId, int $id): void
    {
        $row = ImageHumanAvatar::where(['tenant_id' => $tenantId, 'id' => $id, 'source' => 'official', 'user_id' => 0])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('公共图片形象不存在');
        }
        $row->save(['delete_time' => time(), 'update_time' => time()]);
    }

    public static function userAvatarLists(int $tenantId, array $params = []): array
    {
        $query = ImageHumanAvatar::alias('a')
            ->leftJoin('user u', 'u.id = a.user_id AND u.tenant_id = a.tenant_id')
            ->field('a.*,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
            ->where('a.tenant_id', $tenantId)
            ->where('a.source', 'mine')
            ->where('a.delete_time', 0);
        $userId = (int)($params['user_id'] ?? 0);
        $keyword = trim((string)($params['keyword'] ?? ''));
        $status = trim((string)($params['status'] ?? ''));
        if ($userId > 0) {
            $query->where('a.user_id', $userId);
        }
        if ($keyword !== '') {
            $query->where('a.name', 'like', '%' . $keyword . '%');
        }
        if ($status !== '') {
            $query->where('a.status', $status);
        }
        return self::paginateRows($query->order(['a.id' => 'desc']), $params, 100, [self::class, 'formatAvatar']);
    }

    public static function deleteUserAvatar(int $tenantId, int $id): void
    {
        $row = ImageHumanAvatar::where(['tenant_id' => $tenantId, 'id' => $id, 'source' => 'mine'])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('用户图片形象不存在');
        }
        $row->save(['delete_time' => time(), 'update_time' => time()]);
    }

    public static function saveAvatar(int $tenantId, int $userId, array $params): array
    {
        $name = self::normalizeAssetText((string)($params['name'] ?? '我的图片形象'), '我的图片形象', 80);
        $imageUri = self::normalizeAssetUri((string)($params['image_uri'] ?? $params['media_uri'] ?? $params['cover_uri'] ?? ''));
        $coverUri = self::normalizeAssetUri((string)($params['cover_uri'] ?? $imageUri));
        self::assertPersistedAssetUri($imageUri, '人物图片');
        if ($coverUri !== '') {
            self::assertPersistedAssetUri($coverUri, '形象封面');
        }
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
        return AigcDigitalHumanService::voiceLists($tenantId, $userId, $source);
    }

    public static function saveVoice(int $tenantId, int $userId, array $params): array
    {
        return AigcDigitalHumanService::saveVoice($tenantId, $userId, $params);
    }

    public static function previewVoice(int $tenantId, int $userId, array $params): array
    {
        return AigcDigitalHumanService::previewVoice($tenantId, $userId, $params);
    }

    public static function deleteVoice(int $tenantId, int $userId, int $id): void
    {
        if ($id <= 0) {
            throw new Exception('参考音频不存在');
        }
        $row = AigcDigitalHumanVoice::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'id' => $id, 'source' => 'mine'])
            ->where('delete_time', 0)
            ->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('参考音频不存在');
        }
        $row->save(['delete_time' => time(), 'update_time' => time()]);
    }

    public static function publicVoiceLists(int $tenantId, array $params = []): array
    {
        return AigcDigitalHumanService::publicVoiceLists($tenantId, $params);
    }

    public static function savePublicVoice(int $tenantId, array $params): array
    {
        return AigcDigitalHumanService::savePublicVoice($tenantId, $params);
    }

    public static function deletePublicVoice(int $tenantId, int $id): void
    {
        AigcDigitalHumanService::deletePublicVoice($tenantId, $id);
    }

    public static function userVoiceLists(int $tenantId, array $params = []): array
    {
        return AigcDigitalHumanService::userVoiceLists($tenantId, $params);
    }

    private static function paginateRows($query, array $params, int $defaultLimit = 100, ?callable $formatter = null): array
    {
        $format = static function (array $rows) use ($formatter) {
            return $formatter ? array_map($formatter, $rows) : $rows;
        };
        $usePage = isset($params['page_no']) || isset($params['page_size']);
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['page_size'] ?? 15)));
        if ($usePage) {
            $count = (int)(clone $query)->count();
            return [
                'lists' => $format($query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray()),
                'count' => $count,
                'page_no' => $pageNo,
                'page_size' => $pageSize,
            ];
        }
        return $format($query->limit($defaultLimit)->select()->toArray());
    }

    public static function publishUserVoice(int $tenantId, int $id): array
    {
        return AigcDigitalHumanService::publishUserVoice($tenantId, $id);
    }

    public static function deleteUserVoice(int $tenantId, int $id): void
    {
        AigcDigitalHumanService::deleteUserVoice($tenantId, $id);
    }

    public static function submit(int $tenantId, int $userId, array $params): array
    {
        self::ensureTaskSchema();
        $prompt = trim((string)($params['prompt'] ?? ''));
        $scriptText = trim((string)($params['script_text'] ?? $params['text'] ?? ''));
        $avatarId = (int)($params['avatar_id'] ?? 0);
        $voiceId = (int)($params['voice_id'] ?? 0);
        $avatar = $avatarId > 0 ? self::findAvatar($tenantId, $userId, $avatarId) : [];
        $voice = $voiceId > 0 ? self::findVoice($tenantId, $userId, $voiceId) : [];
        $imageUri = FileService::setFileUrl((string)($avatar['image_uri'] ?? $avatar['media_uri'] ?? ''));
        $driverAudioUri = FileService::setFileUrl((string)($params['driver_audio_uri'] ?? $params['audio_uri'] ?? $params['ref_file_uri'] ?? $params['ref_file_url'] ?? ''));
        $audioUri = $driverAudioUri;
        $audioDriven = $driverAudioUri !== '';
        $mode = self::normalizeMode((string)($params['mode'] ?? 'fast'));
        if ($avatarId <= 0 || empty($avatar)) {
            throw new Exception('请先选择已保存的图片形象');
        }
        if ($imageUri === '') {
            throw new Exception('请上传人物图片');
        }
        self::assertPersistedAssetUri($imageUri, '人物图片');
        if (!$audioDriven && $scriptText === '') {
            throw new Exception('请输入文案内容');
        }
        if (!$audioDriven && (int)($voice['id'] ?? 0) <= 0) {
            throw new Exception('请选择已克隆完成的音色');
        }
        if (!$audioDriven && trim((string)($voice['provider_asset_id'] ?? '')) === '') {
            throw new Exception('当前音色未完成克隆，无法合成');
        }
        $baseConfig = self::baseConfig($tenantId);
        $promptMaxLength = (int)($baseConfig['prompt_max_length'] ?? 0);
        $scriptMaxLength = (int)($baseConfig['script_max_length'] ?? 0);
        if (!$audioDriven && $scriptMaxLength > 0 && mb_strlen($scriptText) > $scriptMaxLength) {
            throw new Exception('文案不能超过' . $scriptMaxLength . '个字');
        }
        if ($promptMaxLength > 0 && mb_strlen($prompt) > $promptMaxLength) {
            throw new Exception('提示词不能超过' . $promptMaxLength . '个字');
        }
        $duration = self::normalizeDuration($params['duration'] ?? 0);
        if ($audioDriven && $duration <= 0) {
            $duration = self::detectAudioDurationFromUri($audioUri, $tenantId);
        }
        if (!$audioDriven && $duration <= 0) {
            $duration = self::estimateScriptDuration($scriptText);
        }
        if ($duration <= 0) {
            throw new Exception('请填写音频时长');
        }
        self::assertHttpsProviderFileUrl(self::fileUrlForTenant($imageUri, $tenantId, $avatar), '人物图片');
        if ($audioDriven) {
            self::assertHttpsProviderFileUrl(self::fileUrlForTenant($audioUri, $tenantId), '参考音频');
        }
        $config = self::effectiveConfig($tenantId);
        if ((int)$config['status'] !== 1) {
            throw new Exception('全驱动数字人应用未启用');
        }

        $estimate = self::buildEstimate($duration, self::pricing($tenantId), $mode);
        PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);

        $duplicate = self::findRecentDuplicateTask($tenantId, $userId, $imageUri, $audioUri, $scriptText, $prompt, $mode, $duration);
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
            'script_text' => $scriptText,
            'prompt' => $prompt,
            'mode' => $mode,
            'duration' => $duration,
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
            'provider' => (string)$config['provider'],
            'model' => (string)$config['model'],
            'provider_task_id' => '',
            'provider_stage' => $audioDriven ? 'video_submitted' : 'created',
            'tts_task_id' => '',
            'provider_payload_json' => [],
            'status' => 'running',
            'progress' => $audioDriven ? 10 : 5,
            'error' => '',
            'create_time' => time(),
            'update_time' => time(),
            'finish_time' => 0,
            'delete_time' => 0,
        ]);

        self::advanceRunningTask($task, $config, $voice);
        $latest = ImageHumanTask::where(['tenant_id' => $tenantId, 'id' => (int)$task['id']])->findOrEmpty();
        return [
            'task_id' => (int)$task['id'],
            'status' => (string)($latest['status'] ?? $task['status']),
            'provider_stage' => (string)($latest['provider_stage'] ?? $task['provider_stage'] ?? ''),
            'progress' => (int)($latest['progress'] ?? $task['progress']),
            'results' => [],
            'error' => (string)($latest['error'] ?? ''),
        ];
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
        $usePage = isset($params['page_no']) || isset($params['page_size']);
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['page_size'] ?? 15)));
        $count = $usePage ? (int)(clone $query)->count() : 0;
        if ($usePage) {
            $query->limit(($pageNo - 1) * $pageSize, $pageSize);
        } else {
            $query->limit(100);
        }
        $rows = self::attachResults($tenantId, $query->select()->toArray());
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

    public static function platformTaskLogs(array $params): array
    {
        $query = ImageHumanTask::where('delete_time', 0)->order('id', 'desc');
        $tenantId = (int)($params['tenant_id'] ?? 0);
        $userId = (int)($params['user_id'] ?? 0);
        $taskId = (int)($params['task_id'] ?? $params['id'] ?? 0);
        $status = trim((string)($params['status'] ?? ''));
        $providerTaskId = trim((string)($params['provider_task_id'] ?? ''));
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($taskId > 0) {
            $query->where('id', $taskId);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($providerTaskId !== '') {
            $query->where('provider_task_id', $providerTaskId);
        }
        $limit = min(100, max(10, (int)($params['limit'] ?? 50)));
        $rows = $query->limit($limit)->select()->toArray();
        foreach ($rows as &$row) {
            $row['image_url'] = self::fileUrlForTenant((string)($row['image_uri'] ?? ''), (int)$row['tenant_id']);
            $row['audio_url'] = self::fileUrlForTenant((string)($row['audio_uri'] ?? ''), (int)$row['tenant_id']);
            $row['script_text'] = (string)($row['script_text'] ?? '');
            $row['provider_payload_summary'] = self::providerPayloadSummary($row['provider_payload_json'] ?? []);
        }
        return $rows;
    }

    public static function platformTaskLogDetail(int $taskId): array
    {
        $task = ImageHumanTask::where('id', $taskId)->where('delete_time', 0)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        $data = $task->toArray();
        $rows = self::attachResults((int)$data['tenant_id'], [$data]);
        $data = $rows[0] ?? $data;
        $data['provider_payload_summary'] = self::providerPayloadSummary($data['provider_payload_json'] ?? []);
        return $data;
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

    public static function stat(int $tenantId = 0): array
    {
        $task = ImageHumanTask::where([])->where('delete_time', 0);
        $result = ImageHumanResult::where([])->where('delete_time', 0);
        $avatar = ImageHumanAvatar::where([])->where('delete_time', 0);
        $voice = AigcDigitalHumanVoice::where([])->where('delete_time', 0);
        if ($tenantId > 0) {
            $task->where('tenant_id', $tenantId);
            $result->where('tenant_id', $tenantId);
            $avatar->where('tenant_id', $tenantId);
            $voice->where('tenant_id', $tenantId);
        }
        return [
            'task_total' => (clone $task)->count(),
            'task_success' => (clone $task)->where('status', 'success')->count(),
            'task_failed' => (clone $task)->where('status', 'failed')->count(),
            'result_total' => (clone $result)->count(),
            'avatar_total' => (clone $avatar)->count(),
            'voice_total' => (clone $voice)->count(),
            'tenant_cost_points' => $tenantId > 0 ? ImageHumanBilling::where('tenant_id', $tenantId)->sum('tenant_cost_points') : ImageHumanBilling::where([])->sum('tenant_cost_points'),
            'user_charge_points' => $tenantId > 0 ? ImageHumanBilling::where('tenant_id', $tenantId)->sum('user_charge_points') : ImageHumanBilling::where([])->sum('user_charge_points'),
        ];
    }

    private static function refreshRunningTasks(int $tenantId, int $userId = 0, int $taskId = 0): void
    {
        $query = ImageHumanTask::where('tenant_id', $tenantId)
            ->where('status', 'running')
            ->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($taskId > 0) {
            $query->where('id', $taskId);
        }
        $tasks = $query->limit(10)->select();
        foreach ($tasks as $task) {
            $config = self::effectiveConfig((int)$task['tenant_id']);
            $voice = (int)($task['voice_id'] ?? 0) > 0 ? self::findVoice((int)$task['tenant_id'], (int)$task['user_id'], (int)$task['voice_id'], false) : [];
            try {
                self::advanceRunningTask($task, $config, $voice);
            } catch (\Throwable $e) {
                $task->status = 'failed';
                $task->progress = 100;
                $task->error = $e->getMessage();
                $task->finish_time = time();
                $task->update_time = time();
                $task->save();
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
                    $locked->save(['status' => 'success', 'provider_stage' => 'success', 'progress' => 100, 'finish_time' => $locked['finish_time'] ?: time(), 'update_time' => time()]);
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
            PointService::consumeBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$locked['tenant_cost_points'], (float)$locked['user_charge_points'], $sourceSn, '全驱数字人消费', [
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
                'platform_unit_cost' => self::modePricing(self::pricing($tenantId), (string)$locked['mode'])['platform_unit_cost'],
                'tenant_unit_price' => self::modePricing(self::pricing($tenantId), (string)$locked['mode'])['tenant_unit_price'],
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
                'provider_stage' => 'success',
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
                'provider_stage' => 'failed',
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
            $tenantPricing = (array)($tenantData['config_json']['pricing'] ?? []);
            $tenantBaseConfig = (array)($tenantData['config_json']['base_config'] ?? $tenantData['config_json']['base'] ?? []);
            $base['config_json'] = (array)($base['config_json'] ?? []);
            if (!empty($tenantPricing)) {
                $basePricing = self::normalizePricing((array)($base['config_json']['pricing'] ?? []));
                $base['config_json']['pricing'] = self::mergeTenantPricing($basePricing, $tenantPricing);
            }
            if (!empty($tenantBaseConfig)) {
                $base['config_json']['base_config'] = array_merge((array)($base['config_json']['base_config'] ?? $base['config_json']['base'] ?? []), $tenantBaseConfig);
            }
        }
        $base['config_json'] = is_array($base['config_json'] ?? null) ? $base['config_json'] : [];
        return $base;
    }

    private static function baseConfig(int $tenantId): array
    {
        $config = self::effectiveConfig($tenantId);
        return self::normalizeBaseConfig((array)($config['config_json']['base_config'] ?? $config['config_json']['base'] ?? []));
    }

    private static function normalizeBaseConfig(array $config): array
    {
        return [
            'script_max_length' => max(0, (int)($config['script_max_length'] ?? self::PROMPT_MAX_LENGTH)),
            'prompt_max_length' => max(0, (int)($config['prompt_max_length'] ?? $config['script_max_length'] ?? self::PROMPT_MAX_LENGTH)),
        ];
    }

    private static function pricing(int $tenantId): array
    {
        $config = self::effectiveConfig($tenantId);
        return self::normalizePricing((array)($config['config_json']['pricing'] ?? []));
    }

    private static function normalizePricing(array $pricing): array
    {
        $fastPlatform = max(0, (float)($pricing['modes']['fast']['platform_unit_cost'] ?? $pricing['platform_unit_cost'] ?? 1.666667));
        $fastTenant = max(0, (float)($pricing['modes']['fast']['tenant_unit_price'] ?? $pricing['tenant_unit_price'] ?? 2.0));
        $standardPlatform = max(0, (float)($pricing['modes']['standard']['platform_unit_cost'] ?? $pricing['standard_platform_unit_cost'] ?? max($fastPlatform, 2.5)));
        $standardTenant = max(0, (float)($pricing['modes']['standard']['tenant_unit_price'] ?? $pricing['standard_tenant_unit_price'] ?? max($fastTenant, 3.0)));
        $billingUnit = (string)($pricing['billing_unit'] ?? 'second') ?: 'second';
        return [
            'platform_unit_cost' => $fastPlatform,
            'tenant_unit_price' => $fastTenant,
            'billing_unit' => $billingUnit,
            'modes' => [
                'fast' => [
                    'label' => '快速模式',
                    'platform_unit_cost' => $fastPlatform,
                    'tenant_unit_price' => $fastTenant,
                ],
                'standard' => [
                    'label' => '标准模式',
                    'platform_unit_cost' => $standardPlatform,
                    'tenant_unit_price' => $standardTenant,
                ],
            ],
        ];
    }

    private static function mergeTenantPricing(array $basePricing, array $tenantPricing): array
    {
        $merged = self::normalizePricing($basePricing);
        $tenantOverrides = self::tenantPricingOverrides($tenantPricing, $merged);
        foreach (['fast', 'standard'] as $mode) {
            if (isset($tenantOverrides['modes'][$mode]['tenant_unit_price'])) {
                $merged['modes'][$mode]['tenant_unit_price'] = (float)$tenantOverrides['modes'][$mode]['tenant_unit_price'];
            }
        }
        $merged['tenant_unit_price'] = (float)$merged['modes']['fast']['tenant_unit_price'];
        return $merged;
    }

    private static function tenantPricingOverrides(array $incomingPricing, array $currentPricing): array
    {
        $currentPricing = self::normalizePricing($currentPricing);
        $fastTenant = max(0, (float)($incomingPricing['modes']['fast']['tenant_unit_price'] ?? $incomingPricing['tenant_unit_price'] ?? $currentPricing['modes']['fast']['tenant_unit_price'] ?? 2.0));
        $standardTenant = max(0, (float)($incomingPricing['modes']['standard']['tenant_unit_price'] ?? $incomingPricing['standard_tenant_unit_price'] ?? $currentPricing['modes']['standard']['tenant_unit_price'] ?? 3.0));
        return [
            'tenant_unit_price' => $fastTenant,
            'billing_unit' => $currentPricing['billing_unit'],
            'modes' => [
                'fast' => [
                    'tenant_unit_price' => $fastTenant,
                ],
                'standard' => [
                    'tenant_unit_price' => $standardTenant,
                ],
            ],
        ];
    }

    private static function modePricing(array $pricing, string $mode): array
    {
        $pricing = self::normalizePricing($pricing);
        $mode = self::normalizeMode($mode);
        $modePricing = (array)($pricing['modes'][$mode] ?? $pricing['modes']['fast']);
        return [
            'label' => (string)($modePricing['label'] ?? ($mode === 'standard' ? '标准模式' : '快速模式')),
            'platform_unit_cost' => max(0, (float)($modePricing['platform_unit_cost'] ?? $pricing['platform_unit_cost'])),
            'tenant_unit_price' => max(0, (float)($modePricing['tenant_unit_price'] ?? $pricing['tenant_unit_price'])),
        ];
    }

    private static function normalizeProviderConfig(array $provider): array
    {
        $submitPath = trim((string)($provider['submit_path'] ?? '/api/v1/apps/image_human/submit'));
        $queryPath = trim((string)($provider['query_path'] ?? '/api/v1/apps/image_human/query'));
        return [
            'submit_path' => $submitPath !== '' ? $submitPath : '/api/v1/apps/image_human/submit',
            'query_path' => $queryPath !== '' ? $queryPath : '/api/v1/apps/image_human/query',
            'timeout' => max(5, (int)($provider['timeout'] ?? 60)),
            'extra_payload' => is_array($provider['extra_payload'] ?? null) ? $provider['extra_payload'] : [],
        ];
    }

    private static function buildEstimate(float $duration, array $pricing, string $mode): array
    {
        $duration = max(1, $duration);
        $modePricing = self::modePricing($pricing, $mode);
        return [
            'mode' => $mode,
            'mode_label' => $modePricing['label'],
            'duration' => $duration,
            'billing_unit' => $pricing['billing_unit'] ?? 'second',
            'billable_quantity' => $duration,
            'platform_unit_cost' => $modePricing['platform_unit_cost'],
            'tenant_unit_price' => $modePricing['tenant_unit_price'],
            'tenant_cost_points' => number_format($duration * (float)$modePricing['platform_unit_cost'], 2, '.', ''),
            'user_charge_points' => number_format($duration * (float)$modePricing['tenant_unit_price'], 2, '.', ''),
        ];
    }

    private static function buildRequestFromTask(ImageHumanTask $task, array $config): ImageHumanGenerateRequest
    {
        return new ImageHumanGenerateRequest(
            self::fileUrlForTenant((string)$task['image_uri'], (int)$task['tenant_id']),
            self::fileUrlForTenant((string)$task['audio_uri'], (int)$task['tenant_id']),
            (string)$task['prompt'],
            (string)($task['script_text'] ?? ''),
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
            $task['script_text'] = (string)($task['script_text'] ?? '');
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
        $videoUri = (string)($row['video_uri'] ?? '');
        $row['video_url'] = preg_match('/^https?:\/\//i', $videoUri)
            ? $videoUri
            : FileService::getFileUrlByStorage(
                $videoUri,
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
        $query = AigcDigitalHumanVoice::where('tenant_id', $tenantId)
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

    private static function assertPersistedAssetUri(string $uri, string $label): void
    {
        $uri = trim($uri);
        if ($uri === '') {
            return;
        }
        if (preg_match('/^(blob:|data:)/i', $uri)) {
            throw new Exception($label . '不能使用本地临时地址，请等待上传完成后再保存');
        }
        $host = strtolower((string)(parse_url($uri, PHP_URL_HOST) ?: ''));
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            throw new Exception($label . '不能使用本地地址，请上传到对象存储或配置可访问文件域名');
        }
    }

    private static function assertHttpsProviderFileUrl(string $url, string $label): void
    {
        $url = trim($url);
        if ($url === '') {
            throw new Exception('请上传' . $label);
        }
        if (preg_match('/^(blob:|data:)/i', $url)) {
            throw new Exception($label . '不能使用本地临时地址，请等待上传完成后再提交');
        }
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            throw new Exception($label . '不能使用本地地址，请配置 HTTPS 文件域名或对象存储');
        }
        if (!str_starts_with($url, 'https://')) {
            throw new Exception($label . '必须是 HTTPS 在线文件地址，请先上传到对象存储或配置 HTTPS 文件域名');
        }
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

    private static function estimateScriptDuration(string $text): float
    {
        return max(1, (float)ceil(mb_strlen($text) / 4));
    }

    private static function advanceRunningTask(ImageHumanTask $task, array $config, array $voice): void
    {
        $stage = (string)($task['provider_stage'] ?? '');
        if ($stage === '') {
            $stage = (string)$task['provider_task_id'] !== ''
                ? 'video_running'
                : (((string)$task['audio_uri'] !== '' && ((int)($task['voice_id'] ?? 0) <= 0 || trim((string)($task['script_text'] ?? '')) === '')) ? 'video_submitted' : 'created');
        }
        if ($stage === 'created' || $stage === 'tts_submitted') {
            $request = self::buildTtsRequestFromTask($task, $voice, $config);
            try {
                $submit = (new XhadminAigcDigitalHumanProvider())->submitTts($request);
                $task->save([
                    'provider_stage' => 'tts_running',
                    'tts_task_id' => (string)($submit['task_id'] ?? ''),
                    'provider_payload_json' => self::mergePayload($task, ['tts_submit' => $submit['payload'] ?? []]),
                    'progress' => 15,
                    'update_time' => time(),
                ]);
            } catch (\Throwable $e) {
                self::failTask($task, 'tts_failed', '音频合成失败：' . self::friendlyStageMessage($e->getMessage()));
            }
            return;
        }
        if ($stage === 'tts_running') {
            $request = self::buildTtsRequestFromTask($task, $voice, $config);
            try {
                $tts = (new XhadminAigcDigitalHumanProvider())->fetchTtsResult((string)$task['tts_task_id'], $request);
                $payload = ['tts_result' => $tts['payload'] ?? []];
                if (!empty($tts['pending'])) {
                    $task->save([
                        'provider_payload_json' => self::mergePayload($task, $payload),
                        'progress' => max(20, (int)$task['progress']),
                        'update_time' => time(),
                    ]);
                    return;
                }
                if (empty($tts['success'])) {
                    self::failTask($task, 'tts_failed', '音频合成失败：' . self::friendlyStageMessage((string)($tts['error'] ?? '供应商任务失败')));
                    return;
                }
                $audioUrl = (string)($tts['audio_url'] ?? '');
                if ($audioUrl === '') {
                    self::failTask($task, 'tts_failed', '音频合成失败：供应商未返回音频');
                    return;
                }
                $audio = AigcDigitalHumanAssetService::persistGeneratedAudio($audioUrl, (int)$task['tenant_id'], (int)$task['user_id']);
                $duration = max((float)$task['duration'], self::normalizeDuration($audio['duration'] ?? 0));
                if ($duration <= 0) {
                    $duration = max((float)$task['duration'], self::detectAudioDurationFromUri((string)($audio['uri'] ?? $audioUrl), (int)$task['tenant_id']));
                }
                $estimate = self::buildEstimate($duration, self::pricing((int)$task['tenant_id']), (string)$task['mode']);
                PointService::assertCanConsumeAmounts((int)$task['tenant_id'], (int)$task['user_id'], (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);
                $task->save([
                    'provider_stage' => 'video_submitted',
                    'audio_uri' => (string)($audio['uri'] ?? $audioUrl),
                    'duration' => $duration,
                    'tenant_cost_points' => $estimate['tenant_cost_points'],
                    'user_charge_points' => $estimate['user_charge_points'],
                    'provider_payload_json' => self::mergePayload($task, $payload),
                    'progress' => 35,
                    'update_time' => time(),
                ]);
                $latest = ImageHumanTask::where(['tenant_id' => (int)$task['tenant_id'], 'id' => (int)$task['id']])->findOrEmpty();
                if (!$latest->isEmpty()) {
                    $task = $latest;
                }
            } catch (\Throwable $e) {
                self::failTask($task, 'tts_failed', '音频合成失败：' . self::friendlyStageMessage($e->getMessage()));
                return;
            }
            $stage = 'video_submitted';
        }
        if ($stage === 'video_submitted') {
            try {
                $request = self::buildRequestFromTask($task, $config);
                $result = self::providerFor((string)$config['provider'])->submit($request);
                if (!$result->success) {
                    self::failTask($task, 'video_failed', $result->error ?: '提交失败');
                    return;
                }
                $task->save([
                    'provider_stage' => 'video_running',
                    'provider_task_id' => $result->providerTaskId,
                    'provider_payload_json' => self::mergePayload($task, $result->payload),
                    'progress' => 45,
                    'update_time' => time(),
                ]);
            } catch (\Throwable $e) {
                self::failTask($task, 'video_failed', '视频合成失败：' . self::friendlyStageMessage($e->getMessage()));
            }
            return;
        }
        if ($stage === 'video_running') {
            try {
                $request = self::buildRequestFromTask($task, $config);
                $result = self::providerFor((string)$task['provider'])->query((string)$task['provider_task_id'], $request);
                $task->provider_payload_json = self::mergePayload($task, $result->payload);
                $task->update_time = time();
                if ($result->pending) {
                    $task->progress = max(60, (int)$task['progress']);
                    $task->save();
                    return;
                }
                if (!$result->success) {
                    self::failTask($task, 'video_failed', $result->error ?: '生成失败');
                    return;
                }
                if (!empty($result->videos)) {
                    $task->save(['provider_stage' => 'storing', 'progress' => 88, 'update_time' => time()]);
                    self::finishTaskWithVideos($task, $result->videos);
                }
            } catch (\Throwable $e) {
                self::failTask($task, 'video_failed', '视频合成失败：' . self::friendlyStageMessage($e->getMessage()));
            }
        }
    }

    private static function buildTtsRequestFromTask(ImageHumanTask $task, array $voice, array $config): AigcDigitalHumanGenerateRequest
    {
        $channelConfig = $config['config_json'] ?? [];
        $providerConfig = is_array($channelConfig['provider'] ?? null) ? $channelConfig['provider'] : [];
        if (!isset($channelConfig['timeout']) && isset($providerConfig['timeout'])) {
            $channelConfig['timeout'] = $providerConfig['timeout'];
        }
        return new AigcDigitalHumanGenerateRequest(
            (string)($task['script_text'] ?? ''),
            '',
            'image_human',
            'default',
            '',
            [],
            self::formatVoice($voice),
            [],
            [],
            array_merge($channelConfig, [
                'model' => $config['model'] ?? 'image_human',
                'tenant_id' => (int)$task['tenant_id'],
                'user_id' => (int)$task['user_id'],
            ])
        );
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

    private static function findRecentDuplicateTask(int $tenantId, int $userId, string $imageUri, string $audioUri, string $scriptText, string $prompt, string $mode, float $duration): ?ImageHumanTask
    {
        $rows = ImageHumanTask::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('delete_time', 0)
            ->where('image_uri', $imageUri)
            ->where('audio_uri', $audioUri)
            ->where('script_text', $scriptText)
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

    private static function ensureTaskSchema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $table = (new ImageHumanTask())->getTable();
            if (empty(Db::query("SHOW COLUMNS FROM `{$table}` WHERE Field = 'script_text'"))) {
                Db::execute("ALTER TABLE `{$table}` ADD COLUMN `script_text` text COMMENT '文案内容' AFTER `audio_uri`");
                Db::execute("UPDATE `{$table}` SET `script_text` = COALESCE(NULLIF(`script_text`, ''), `prompt`, '') WHERE `script_text` IS NULL OR `script_text` = ''");
            }
            if (empty(Db::query("SHOW COLUMNS FROM `{$table}` WHERE Field = 'provider_stage'"))) {
                Db::execute("ALTER TABLE `{$table}` ADD COLUMN `provider_stage` varchar(30) NOT NULL DEFAULT '' COMMENT '供应商阶段' AFTER `provider_task_id`");
                Db::execute("UPDATE `{$table}` SET `provider_stage` = IF(`provider_task_id` <> '', 'video_running', IF(`audio_uri` <> '' AND (`voice_id` <= 0 OR COALESCE(`script_text`, '') = ''), 'video_submitted', 'created')) WHERE `provider_stage` = ''");
            }
            if (empty(Db::query("SHOW COLUMNS FROM `{$table}` WHERE Field = 'tts_task_id'"))) {
                Db::execute("ALTER TABLE `{$table}` ADD COLUMN `tts_task_id` varchar(120) NOT NULL DEFAULT '' COMMENT '音频合成任务ID' AFTER `provider_stage`");
            }
        } catch (\Throwable $e) {
            throw new Exception('全驱动数字人任务表结构未更新，请执行 image_human 迁移：' . $e->getMessage());
        }
    }

    private static function failTask(ImageHumanTask $task, string $stage, string $error): void
    {
        $task->save([
            'status' => 'failed',
            'provider_stage' => $stage,
            'progress' => 100,
            'error' => $error,
            'finish_time' => time(),
            'update_time' => time(),
        ]);
    }

    private static function friendlyStageMessage(string $message): string
    {
        $message = trim($message);
        return $message === '' ? '供应商任务失败' : $message;
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

    private static function providerPayloadSummary($payload): array
    {
        if (!is_array($payload)) {
            return [];
        }
        $summary = [];
        foreach (['tts_submit', 'tts_result', 'submit', 'query'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $summary[] = [
                'stage' => $key,
                'payload' => $payload[$key],
            ];
        }
        return $summary;
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
