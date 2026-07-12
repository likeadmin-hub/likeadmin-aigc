<?php

namespace app\common\service\app\aigc_music;

use app\common\model\app\aigc_music\AigcMusicAsset;
use app\common\model\app\aigc_music\AigcMusicBilling;
use app\common\model\app\aigc_music\AigcMusicConfig;
use app\common\model\app\aigc_music\AigcMusicExport;
use app\common\model\app\aigc_music\AigcMusicPersona;
use app\common\model\app\aigc_music\AigcMusicResult;
use app\common\model\app\aigc_music\AigcMusicSafetyAudit;
use app\common\model\app\aigc_music\AigcMusicStyle;
use app\common\model\app\aigc_music\AigcMusicTask;
use app\common\model\app\aigc_music\AigcMusicVoiceClone;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\FileService;
use app\common\service\point\PointService;
use app\common\service\storage\StorageConfigService;
use Exception;
use think\facade\Db;
use think\facade\Log;

class AigcMusicService
{
    public const APP_CODE = 'aigc_music';
    public const ACTION_MUSIC_GENERATION = 'music_generation';

    private const DUPLICATE_WINDOW_SECONDS = 6;
    private const MIN_DURATION = 5;
    private const MAX_DURATION = 600;

    public static function exportTypes(): array
    {
        return [
            ['value' => 'wav', 'label' => 'WAV'],
            ['value' => 'mp4', 'label' => 'MP4'],
            ['value' => 'midi', 'label' => 'MIDI'],
            ['value' => 'timing', 'label' => 'Timing'],
            ['value' => 'vox', 'label' => 'Vox'],
        ];
    }

    public static function config(int $tenantId): array
    {
        $config = AigcMusicConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $data = $config->isEmpty() ? [
            'provider_mode' => 'platform',
            'provider' => 'mock',
            'model' => self::ACTION_MUSIC_GENERATION,
            'status' => 1,
            'config_json' => [],
        ] : $config->toArray();
        $data['option_config'] = AigcMusicChannelService::userConfig($tenantId);
        $data['styles'] = self::styleLists($tenantId, ['status' => 1, 'page_size' => 100])['lists'];
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, $data);
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        $row = AigcMusicConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $current = $row->isEmpty() ? [] : $row->toArray();
        $data = [
            'tenant_id' => $tenantId,
            'provider_mode' => $params['provider_mode'] ?? ($current['provider_mode'] ?? 'platform'),
            'provider' => $params['provider'] ?? ($current['provider'] ?? 'mock'),
            'model' => $params['model'] ?? ($current['model'] ?? self::ACTION_MUSIC_GENERATION),
            'config_json' => is_array($params['config_json'] ?? null) ? $params['config_json'] : ($current['config_json'] ?? []),
            'status' => (int)($params['status'] ?? ($current['status'] ?? 1)),
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcMusicConfig::create($data);
            return;
        }
        $row->save($data);
    }

    public static function estimate(int $tenantId, array $params): array
    {
        self::normalizeSubmit($params, false);
        return AigcMusicChannelService::estimate($tenantId, $params);
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
        $payload = self::normalizeSubmit($params, true);
        if ($payload['reference_asset_id'] > 0) {
            self::assertAsset($tenantId, $payload['reference_asset_id'], $userId);
        }
        self::recordSafetyAudit($tenantId, $userId, 'task', 0, 'prompt_review', 'passed', $payload['prompt'] . ' ' . $payload['lyrics']);
        $selection = AigcMusicChannelService::resolveSelection($tenantId, $params);
        $estimate = AigcMusicChannelService::estimate($tenantId, $params);
        $estimate = self::applyBillingOverride($estimate, (int)$estimate['quantity'], $billingOverride);
        PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);

        $duplicate = self::findRecentDuplicateTask($tenantId, $userId, $payload);
        if ($duplicate) {
            return self::duplicateResponse($duplicate, $tenantId, $userId);
        }

        $task = AigcMusicTask::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'app_code' => self::APP_CODE,
            'action' => self::ACTION_MUSIC_GENERATION,
            'title' => $payload['title'],
            'prompt' => $payload['prompt'],
            'lyrics' => $payload['lyrics'],
            'genre' => $payload['genre'],
            'mood' => $payload['mood'],
            'instruments' => $payload['instruments'],
            'style_id' => $payload['style_id'],
            'persona_id' => $payload['persona_id'],
            'voice_clone_id' => $payload['voice_clone_id'],
            'reference_asset_id' => $payload['reference_asset_id'],
            'channel' => $selection['channel']['code'],
            'quality' => $selection['spec']['quality'],
            'ratio' => $selection['spec']['ratio'],
            'duration' => (int)$estimate['duration'],
            'quantity' => (int)$estimate['quantity'],
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
            'provider' => $selection['channel']['provider'],
            'model' => $selection['channel']['model'],
            'idempotency_key' => self::idempotencyKey($payload),
            'billing_status' => 'prechecked',
            'safety_status' => 'passed',
            'status' => 'running',
            'progress' => 10,
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
            $task->error = $result->error ?: '音乐生成失败';
            $task->finish_time = time();
            $task->progress = 100;
        }
        $task->save();

        $rows = [];
        if ($result->success && !empty($result->items)) {
            $rows = self::finishTaskWithItems($task, $selection, $result->items);
        }

        return [
            'task_id' => (int)$task['id'],
            'provider_task_id' => $result->providerTaskId,
            'status' => !empty($rows) ? 'success' : ($result->success ? 'running' : 'failed'),
            'app' => self::APP_CODE,
            'action' => self::ACTION_MUSIC_GENERATION,
            'error' => $result->error,
            'results' => $rows,
        ];
    }

    public static function lyrics(int $tenantId, int $userId, array $params): array
    {
        $prompt = trim((string)($params['prompt'] ?? $params['theme'] ?? ''));
        if ($prompt === '') {
            throw new Exception('请输入歌词主题');
        }
        self::recordSafetyAudit($tenantId, $userId, 'lyrics', 0, 'prompt_review', 'passed', $prompt);
        return self::providerFor('mock')->lyrics($params);
    }

    public static function mashupLyrics(int $tenantId, int $userId, array $params): array
    {
        $a = trim((string)($params['lyrics_a'] ?? $params['source_lyrics'] ?? ''));
        $b = trim((string)($params['lyrics_b'] ?? $params['target_lyrics'] ?? ''));
        if ($a === '' || $b === '') {
            throw new Exception('请提供两段歌词');
        }
        self::recordSafetyAudit($tenantId, $userId, 'lyrics', 0, 'mashup_review', 'passed', $a . ' ' . $b);
        return self::providerFor('mock')->mashupLyrics($params);
    }

    public static function createPersona(int $tenantId, int $userId, array $params): array
    {
        $name = trim((string)($params['name'] ?? ''));
        if ($name === '') {
            throw new Exception('请输入Persona名称');
        }
        $row = AigcMusicPersona::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'name' => $name,
            'description' => trim((string)($params['description'] ?? '')),
            'reference_asset_id' => (int)($params['reference_asset_id'] ?? 0),
            'lyrics_style' => trim((string)($params['lyrics_style'] ?? '')),
            'prompt_json' => is_array($params['prompt_json'] ?? null) ? $params['prompt_json'] : [],
            'auth_status' => (int)($params['authorization_confirmed'] ?? 0) === 1 ? 'confirmed' : 'pending',
            'audit_status' => 'pending',
            'audit_json' => self::authorizationPayload($params),
            'status' => 1,
            'create_time' => time(),
            'update_time' => time(),
            'delete_time' => 0,
        ]);
        self::recordSafetyAudit($tenantId, $userId, 'persona', (int)$row['id'], 'persona_create', 'pending', $name);
        return $row->toArray();
    }

    public static function createVoiceClone(int $tenantId, int $userId, array $params): array
    {
        $name = trim((string)($params['name'] ?? ''));
        $assetId = (int)($params['reference_asset_id'] ?? $params['asset_id'] ?? 0);
        if ($name === '' || $assetId <= 0) {
            throw new Exception('请输入声音名称并选择授权音频');
        }
        $asset = self::assertAsset($tenantId, $assetId, $userId);
        $clone = self::providerFor('mock')->cloneVoice($params + ['asset' => $asset]);
        $row = AigcMusicVoiceClone::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'name' => $name,
            'reference_asset_id' => $assetId,
            'provider_voice_id' => (string)($clone['provider_voice_id'] ?? ''),
            'auth_status' => (int)($params['authorization_confirmed'] ?? 0) === 1 ? 'confirmed' : 'pending',
            'audit_status' => 'pending',
            'auth_json' => self::authorizationPayload($params),
            'provider_payload' => $clone['raw'] ?? [],
            'status' => (string)($clone['status'] ?? 'pending'),
            'create_time' => time(),
            'update_time' => time(),
            'delete_time' => 0,
        ]);
        self::recordSafetyAudit($tenantId, $userId, 'voice_clone', (int)$row['id'], 'voice_clone_create', 'pending', $name);
        return $row->toArray();
    }

    public static function taskLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        self::safeRefreshRunningTasks($tenantId, $userId);
        $query = AigcMusicTask::alias('t')
            ->leftJoin('user u', 'u.id = t.user_id AND u.tenant_id = t.tenant_id')
            ->field('t.*,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
            ->where('t.tenant_id', $tenantId)
            ->where('t.delete_time', 0)
            ->order('t.id', 'desc');
        if ($userId > 0) {
            $query->where('t.user_id', $userId);
        }
        self::applyTaskFilters($query, $params);
        $usePage = isset($params['page_no']) || isset($params['page_size']);
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['page_size'] ?? 15)));
        $count = $usePage ? (int)(clone $query)->count() : 0;
        if ($usePage) {
            $query->limit(($pageNo - 1) * $pageSize, $pageSize);
        } else {
            $query->limit(100);
        }
        $rows = self::attachTaskResults($tenantId, $query->select()->toArray(), $userId);
        return $usePage ? ['lists' => $rows, 'count' => $count, 'page_no' => $pageNo, 'page_size' => $pageSize] : $rows;
    }

    public static function taskDetail(int $tenantId, int $taskId, int $userId = 0): array
    {
        self::safeRefreshRunningTasks($tenantId, $userId, $taskId);
        $query = AigcMusicTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
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
        self::safeRefreshAllRunningTasks();
        $query = AigcMusicTask::alias('t')
            ->leftJoin('user u', 'u.id = t.user_id AND u.tenant_id = t.tenant_id')
            ->field('t.*,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
            ->where('t.delete_time', 0)
            ->order('t.id', 'desc');
        self::applyTaskFilters($query, $params);
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['page_size'] ?? 15)));
        $count = (int)(clone $query)->count();
        $rows = $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();
        return ['lists' => self::attachPlatformTaskResults($rows), 'count' => $count, 'page_no' => $pageNo, 'page_size' => $pageSize];
    }

    public static function platformTaskDetail(int $taskId): array
    {
        $task = AigcMusicTask::where('id', $taskId)->where('delete_time', 0)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return self::taskDetail((int)$task['tenant_id'], $taskId);
    }

    public static function retryTask(int $tenantId, int $taskId): array
    {
        $task = self::taskDetail($tenantId, $taskId);
        return self::generate($tenantId, (int)$task['user_id'], [
            'title' => $task['title'],
            'prompt' => $task['prompt'],
            'lyrics' => $task['lyrics'],
            'genre' => $task['genre'],
            'mood' => $task['mood'],
            'instruments' => $task['instruments'],
            'style_id' => $task['style_id'],
            'persona_id' => $task['persona_id'],
            'voice_clone_id' => $task['voice_clone_id'],
            'reference_asset_id' => $task['reference_asset_id'],
            'channel' => $task['channel'],
            'quality' => $task['quality'],
            'duration' => $task['duration'],
        ]);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = AigcMusicTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
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
        AigcMusicResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time()]);
    }

    public static function resultLists(int $tenantId, int $userId = 0, int $taskId = 0, array $params = []): array
    {
        self::safeRefreshRunningTasks($tenantId, $userId, $taskId);
        $query = AigcMusicResult::where('tenant_id', $tenantId)->where('delete_time', 0)->order('id', 'desc');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($taskId > 0) {
            $query->where('task_id', $taskId);
        }
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['page_size'] ?? 50)));
        $count = (isset($params['page_no']) || isset($params['page_size'])) ? (int)(clone $query)->count() : 0;
        $rows = $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();
        $rows = array_map([self::class, 'formatResultRow'], $rows);
        return $count > 0 ? ['lists' => $rows, 'count' => $count, 'page_no' => $pageNo, 'page_size' => $pageSize] : $rows;
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = AigcMusicResult::where(['tenant_id' => $tenantId, 'id' => $resultId]);
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

    public static function exportResult(int $tenantId, int $userId, int $resultId, string $type, array $params = []): array
    {
        if (!in_array($type, array_column(self::exportTypes(), 'value'), true)) {
            throw new Exception('导出格式不支持');
        }
        $query = AigcMusicResult::where(['tenant_id' => $tenantId, 'id' => $resultId])->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $result = $query->findOrEmpty();
        if ($result->isEmpty()) {
            throw new Exception('作品不存在');
        }
        $payload = self::providerFor('mock')->export($result->toArray(), $type, $params);
        $row = AigcMusicExport::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'result_id' => $resultId,
            'export_type' => $type,
            'file_uri' => (string)($payload['file_uri'] ?? ''),
            'storage_scope' => (string)$result['storage_scope'],
            'storage_engine' => (string)$result['storage_engine'],
            'storage_domain' => (string)$result['storage_domain'],
            'status' => (string)($payload['status'] ?? 'success'),
            'error' => (string)($payload['error'] ?? ''),
            'result_json' => $payload['raw'] ?? [],
            'create_time' => time(),
        ]);
        $data = $row->toArray();
        $data['file_url'] = FileService::getFileUrlByStorage($data['file_uri'], $data['storage_scope'], $data['storage_engine'], $data['storage_domain']);
        return $data;
    }

    public static function assetLists(int $tenantId, array $params = [], int $userId = 0): array
    {
        $query = AigcMusicAsset::where('tenant_id', $tenantId)->where('delete_time', 0)->order('id', 'desc');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $type = trim((string)($params['asset_type'] ?? ''));
        if ($type !== '') {
            $query->where('asset_type', $type);
        }
        $page = self::paginateRows($query, $params, 50);
        foreach ($page['lists'] as &$item) {
            $item['url'] = $item['url'] ?: AigcMusicAssetService::assetUrl($item);
        }
        return $page;
    }

    public static function saveAsset(int $tenantId, array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            return AigcMusicAssetService::registerExisting($tenantId, (int)($params['user_id'] ?? 0), $params);
        }
        $row = AigcMusicAsset::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('素材不存在');
        }
        $row->save([
            'title' => trim((string)($params['title'] ?? $row['title'])),
            'asset_type' => trim((string)($params['asset_type'] ?? $row['asset_type'])),
            'auth_status' => trim((string)($params['auth_status'] ?? $row['auth_status'])),
            'audit_status' => trim((string)($params['audit_status'] ?? $row['audit_status'])),
            'status' => (int)($params['status'] ?? $row['status']),
            'update_time' => time(),
        ]);
        return $row->toArray();
    }

    public static function deleteAsset(int $tenantId, int $id): void
    {
        self::softDelete(AigcMusicAsset::class, $tenantId, $id, '素材不存在');
    }

    public static function styleLists(int $tenantId, array $params = []): array
    {
        $query = AigcMusicStyle::whereIn('tenant_id', [0, $tenantId])->where('delete_time', 0)->order(['sort' => 'desc', 'id' => 'asc']);
        if (isset($params['status'])) {
            $query->where('status', (int)$params['status']);
        }
        return self::paginateRows($query, $params, 50);
    }

    public static function saveStyle(int $tenantId, array $params): array
    {
        $name = trim((string)($params['name'] ?? ''));
        if ($name === '') {
            throw new Exception('请输入风格名称');
        }
        $data = [
            'tenant_id' => $tenantId,
            'name' => $name,
            'description' => trim((string)($params['description'] ?? '')),
            'prompt' => trim((string)($params['prompt'] ?? '')),
            'preset_json' => is_array($params['preset_json'] ?? null) ? $params['preset_json'] : [],
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
        ];
        $id = (int)($params['id'] ?? 0);
        if ($id > 0) {
            $row = AigcMusicStyle::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
            if ($row->isEmpty()) {
                throw new Exception('风格不存在');
            }
            $row->save($data);
            return $row->toArray();
        }
        $data['create_time'] = time();
        $data['delete_time'] = 0;
        return AigcMusicStyle::create($data)->toArray();
    }

    public static function deleteStyle(int $tenantId, int $id): void
    {
        self::softDelete(AigcMusicStyle::class, $tenantId, $id, '风格不存在');
    }

    public static function voiceLists(int $tenantId, array $params = []): array
    {
        $query = AigcMusicVoiceClone::where('tenant_id', $tenantId)->where('delete_time', 0)->order('id', 'desc');
        return self::paginateRows($query, $params, 50);
    }

    public static function saveVoice(int $tenantId, array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $row = AigcMusicVoiceClone::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('声音不存在');
        }
        $row->save([
            'name' => trim((string)($params['name'] ?? $row['name'])),
            'auth_status' => trim((string)($params['auth_status'] ?? $row['auth_status'])),
            'audit_status' => trim((string)($params['audit_status'] ?? $row['audit_status'])),
            'status' => trim((string)($params['status'] ?? $row['status'])),
            'update_time' => time(),
        ]);
        return $row->toArray();
    }

    public static function deleteVoice(int $tenantId, int $id): void
    {
        self::softDelete(AigcMusicVoiceClone::class, $tenantId, $id, '声音不存在');
    }

    public static function stat(int $tenantId = 0): array
    {
        $task = AigcMusicTask::where([])->where('delete_time', 0);
        $result = AigcMusicResult::where([])->where('delete_time', 0);
        if ($tenantId > 0) {
            $task->where('tenant_id', $tenantId);
            $result->where('tenant_id', $tenantId);
        }
        return [
            'task_total' => (clone $task)->count(),
            'task_success' => (clone $task)->where('status', 'success')->count(),
            'task_failed' => (clone $task)->where('status', 'failed')->count(),
            'result_total' => (clone $result)->count(),
            'asset_total' => $tenantId > 0 ? AigcMusicAsset::where('tenant_id', $tenantId)->where('delete_time', 0)->count() : AigcMusicAsset::where('delete_time', 0)->count(),
            'voice_total' => $tenantId > 0 ? AigcMusicVoiceClone::where('tenant_id', $tenantId)->where('delete_time', 0)->count() : AigcMusicVoiceClone::where('delete_time', 0)->count(),
            'tenant_cost_points' => $tenantId > 0 ? AigcMusicBilling::where('tenant_id', $tenantId)->sum('tenant_cost_points') : AigcMusicBilling::where([])->sum('tenant_cost_points'),
            'user_charge_points' => $tenantId > 0 ? AigcMusicBilling::where('tenant_id', $tenantId)->sum('user_charge_points') : AigcMusicBilling::where([])->sum('user_charge_points'),
        ];
    }

    public static function normalizeDuration(mixed $value): int
    {
        $duration = (int)$value;
        if ($duration < self::MIN_DURATION) {
            $duration = 30;
        }
        return min(self::MAX_DURATION, max(self::MIN_DURATION, $duration));
    }

    private static function normalizeSubmit(array $params, bool $strict): array
    {
        $prompt = trim((string)($params['prompt'] ?? ''));
        $lyrics = trim((string)($params['lyrics'] ?? $params['lyric'] ?? ''));
        $style = trim((string)($params['style'] ?? ''));
        $genre = trim((string)($params['genre'] ?? ''));
        if ($genre === '') {
            $genre = $style;
        }
        if ($strict && $prompt === '' && $lyrics === '') {
            throw new Exception('请输入音乐提示词或歌词');
        }
        return [
            'title' => trim((string)($params['title'] ?? '')),
            'prompt' => $prompt,
            'lyrics' => $lyrics,
            'style' => $style,
            'genre' => $genre,
            'mood' => trim((string)($params['mood'] ?? '')),
            'instruments' => trim((string)($params['instruments'] ?? '')),
            'style_id' => (int)($params['style_id'] ?? 0),
            'persona_id' => (int)($params['persona_id'] ?? 0),
            'voice_clone_id' => (int)($params['voice_clone_id'] ?? 0),
            'reference_asset_id' => (int)($params['reference_asset_id'] ?? 0),
            'custom' => self::boolValue($params['custom'] ?? false),
            'instrumental' => self::boolValue($params['instrumental'] ?? false),
            'vocal_gender' => trim((string)($params['vocal_gender'] ?? '')),
            'language' => trim((string)($params['language'] ?? '')),
            'audio_id' => trim((string)($params['audio_id'] ?? '')),
            'audio_url' => trim((string)($params['audio_url'] ?? $params['reference_audio'] ?? '')),
            'duration' => self::normalizeDuration($params['duration'] ?? 30),
        ];
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

    private static function buildRequest(AigcMusicTask $task, array $selection, array $payload): AigcMusicGenerateRequest
    {
        $channelConfig = array_merge($selection['channel']['config_json'] ?? [], [
            'tenant_id' => (int)$task['tenant_id'],
            'user_id' => (int)$task['user_id'],
        ]);
        return new AigcMusicGenerateRequest(
            $payload['title'],
            $payload['prompt'],
            $payload['lyrics'],
            $payload['genre'],
            $payload['mood'],
            $payload['instruments'],
            (int)$task['duration'],
            (int)$task['quantity'],
            $payload,
            $selection['spec'],
            $selection['spec']['provider_params_json'] ?? [],
            $channelConfig
        );
    }

    private static function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    private static function finishTaskWithItems(AigcMusicTask $task, array $selection, array $items): array
    {
        Db::startTrans();
        try {
            $tenantId = (int)$task['tenant_id'];
            $userId = (int)$task['user_id'];
            $task = AigcMusicTask::where('tenant_id', $tenantId)->where('id', (int)$task['id'])->lock(true)->findOrEmpty();
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
            foreach (array_slice($items, 0, 1) as $index => $item) {
                $storageScope = (string)($item['storage_scope'] ?? $storage['scope']);
                $storageEngine = (string)($item['storage_engine'] ?? $storage['default']);
                $storageDomain = array_key_exists('storage_domain', $item) ? (string)$item['storage_domain'] : $domain;
                $row = AigcMusicResult::create([
                    'tenant_id' => $tenantId,
                    'task_id' => (int)$task['id'],
                    'user_id' => $userId,
                    'title' => (string)($item['title'] ?? $task['title']),
                    'audio_uri' => (string)($item['audio_uri'] ?? ''),
                    'wav_uri' => (string)($item['wav_uri'] ?? ''),
                    'mp4_uri' => (string)($item['mp4_uri'] ?? ''),
                    'midi_uri' => (string)($item['midi_uri'] ?? ''),
                    'timing_uri' => (string)($item['timing_uri'] ?? ''),
                    'vox_uri' => (string)($item['vox_uri'] ?? ''),
                    'cover_uri' => (string)($item['cover_uri'] ?? ''),
                    'storage_scope' => $storageScope,
                    'storage_engine' => $storageEngine,
                    'storage_domain' => $storageDomain,
                    'mime_type' => (string)($item['mime_type'] ?? 'audio/mpeg'),
                    'file_size' => (int)($item['file_size'] ?? 0),
                    'duration' => (float)($item['duration'] ?? $task['duration']),
                    'lyrics' => (string)($item['lyrics'] ?? $task['lyrics']),
                    'timing_json' => $item['timing_json'] ?? [],
                    'tenant_cost_points' => $task['tenant_cost_points'],
                    'user_charge_points' => $task['user_charge_points'],
                    'provider_task_id' => (string)($item['provider_task_id'] ?? $task['provider_task_id']),
                    'result_json' => $item['raw'] ?? [],
                    'delete_time' => 0,
                    'create_time' => time(),
                ]);
                $sourceSn = self::APP_CODE . '-' . (int)$task['id'] . '-' . ((int)$index + 1);
                PointService::consumeBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$task['tenant_cost_points'], (float)$task['user_charge_points'], $sourceSn, 'AI音乐生成消费', [
                    'app_code' => self::APP_CODE,
                    'task_id' => (int)$task['id'],
                    'result_id' => (int)$row['id'],
                    'duration' => (int)$task['duration'],
                ]);
                AigcMusicBilling::create([
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
                $rows[] = self::formatResultRow($row->toArray());
            }
            $task->status = 'success';
            $task->billing_status = 'deducted';
            $task->progress = 100;
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

    private static function refreshRunningTasks(int $tenantId, int $userId = 0, int $taskId = 0, bool $swallowErrors = false): void
    {
        $query = AigcMusicTask::where('tenant_id', $tenantId)
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
                $selection = AigcMusicChannelService::resolveSelection($tenantId, [
                    'channel' => $task['channel'],
                    'quality' => $task['quality'],
                ]);
                $provider = self::providerFor((string)$task['provider']);
                if (!method_exists($provider, 'fetchResult')) {
                    continue;
                }
                $result = $provider->fetchResult((string)$task['provider_task_id'], self::buildRequestFromTask($task, $selection));
                if (!$result->success) {
                    $task->status = 'failed';
                    $task->error = $result->error ?: '音乐生成失败';
                    $task->finish_time = time();
                    $task->progress = 100;
                    $task->update_time = time();
                    $task->save();
                    continue;
                }
                if (empty($result->items)) {
                    $task->progress = min(95, max(20, (int)$task['progress'] + 5));
                    $task->update_time = time();
                    $task->save();
                    continue;
                }
                self::finishTaskWithItems($task, $selection, $result->items);
            } catch (\Throwable $e) {
                if (!$swallowErrors) {
                    throw $e;
                }
            }
        }
    }

    private static function safeRefreshRunningTasks(int $tenantId, int $userId = 0, int $taskId = 0): void
    {
        try {
            self::refreshRunningTasks($tenantId, $userId, $taskId, true);
        } catch (\Throwable) {
            // Read APIs must remain available while provider polling is temporarily unavailable.
        }
    }

    private static function safeRefreshAllRunningTasks(): void
    {
        $tenantIds = AigcMusicTask::where('status', 'running')
            ->where('delete_time', 0)
            ->where('provider_task_id', '<>', '')
            ->group('tenant_id')
            ->limit(20)
            ->column('tenant_id');
        foreach ($tenantIds as $tenantId) {
            self::safeRefreshRunningTasks((int)$tenantId);
        }
    }

    private static function buildRequestFromTask(AigcMusicTask $task, array $selection): AigcMusicGenerateRequest
    {
        $payload = [
            'title' => (string)$task['title'],
            'prompt' => (string)$task['prompt'],
            'lyrics' => (string)$task['lyrics'],
            'genre' => (string)$task['genre'],
            'mood' => (string)$task['mood'],
            'instruments' => (string)$task['instruments'],
            'style_id' => (int)$task['style_id'],
            'persona_id' => (int)$task['persona_id'],
            'voice_clone_id' => (int)$task['voice_clone_id'],
            'reference_asset_id' => (int)$task['reference_asset_id'],
            'duration' => (int)$task['duration'],
            'custom' => (string)$task['lyrics'] !== '',
            'instrumental' => (string)$task['lyrics'] === '' && (string)$task['prompt'] !== '',
        ];
        return self::buildRequest($task, $selection, $payload);
    }

    private static function existingResultRows(int $tenantId, int $userId, int $taskId): array
    {
        $query = AigcMusicResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        return array_map([self::class, 'formatResultRow'], $query->select()->toArray());
    }

    private static function attachTaskResults(int $tenantId, array $tasks, int $userId = 0): array
    {
        foreach ($tasks as &$task) {
            $task['results'] = self::existingResultRows($tenantId, $userId, (int)$task['id']);
        }
        return $tasks;
    }

    private static function attachPlatformTaskResults(array $tasks): array
    {
        foreach ($tasks as &$task) {
            $task['results'] = self::existingResultRows((int)$task['tenant_id'], 0, (int)$task['id']);
        }
        return $tasks;
    }

    private static function formatResultRow(array $row): array
    {
        foreach (['audio_uri', 'wav_uri', 'mp4_uri', 'midi_uri', 'timing_uri', 'vox_uri', 'cover_uri'] as $field) {
            $urlField = str_replace('_uri', '_url', $field);
            $row[$urlField] = $row[$field] === '' ? '' : FileService::getFileUrlByStorage($row[$field], $row['storage_scope'] ?? '', $row['storage_engine'] ?? '', $row['storage_domain'] ?? '');
        }
        return $row;
    }

    private static function providerFor(string $provider): AigcMusicProviderInterface
    {
        return match (strtolower(trim($provider))) {
            'xhadmin', 'xhadmin_aigc_music', 'music_generation' => new XhadminAigcMusicProvider(),
            default => new MockAigcMusicProvider(),
        };
    }

    private static function isAsyncProvider(string $provider): bool
    {
        return in_array(strtolower(trim($provider)), ['xhadmin', 'xhadmin_aigc_music', 'music_generation'], true);
    }

    private static function findRecentDuplicateTask(int $tenantId, int $userId, array $payload): ?AigcMusicTask
    {
        $key = self::idempotencyKey($payload);
        $task = AigcMusicTask::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'idempotency_key' => $key,
        ])->where('create_time', '>=', time() - self::DUPLICATE_WINDOW_SECONDS)
            ->where('delete_time', 0)
            ->order('id', 'desc')
            ->findOrEmpty();
        return $task->isEmpty() ? null : $task;
    }

    private static function duplicateResponse(AigcMusicTask $task, int $tenantId, int $userId): array
    {
        return [
            'task_id' => (int)$task['id'],
            'provider_task_id' => (string)$task['provider_task_id'],
            'status' => (string)$task['status'],
            'app' => self::APP_CODE,
            'action' => self::ACTION_MUSIC_GENERATION,
            'error' => (string)$task['error'],
            'results' => self::existingResultRows($tenantId, $userId, (int)$task['id']),
        ];
    }

    private static function idempotencyKey(array $payload): string
    {
        return sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function assertAsset(int $tenantId, int $assetId, int $userId = 0): array
    {
        $query = AigcMusicAsset::where(['tenant_id' => $tenantId, 'id' => $assetId])->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $asset = $query->findOrEmpty();
        if ($asset->isEmpty()) {
            throw new Exception('音频素材不存在');
        }
        return $asset->toArray();
    }

    private static function recordSafetyAudit(int $tenantId, int $userId, string $targetType, int $targetId, string $action, string $decision, string $summary): void
    {
        try {
            AigcMusicSafetyAudit::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'action' => $action,
                'decision' => $decision,
                'policy_hit' => '',
                'summary' => mb_substr($summary, 0, 200),
                'audit_json' => ['app_code' => self::APP_CODE],
                'create_time' => time(),
            ]);
        } catch (\Throwable $e) {
            Log::write('AI music safety audit skipped: ' . $e->getMessage());
        }
    }

    private static function authorizationPayload(array $params): array
    {
        return [
            'authorization_confirmed' => (int)($params['authorization_confirmed'] ?? 0),
            'authorization_text' => trim((string)($params['authorization_text'] ?? '')),
            'source' => trim((string)($params['source'] ?? '')),
            'created_at' => time(),
        ];
    }

    private static function paginateRows($query, array $params, int $defaultPageSize): array
    {
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = max(1, min(200, (int)($params['page_size'] ?? $defaultPageSize)));
        $count = (int)(clone $query)->count();
        return [
            'lists' => $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray(),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ];
    }

    private static function applyTaskFilters($query, array $params): void
    {
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
    }

    private static function softDelete(string $modelClass, int $tenantId, int $id, string $notFound): void
    {
        $row = $modelClass::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception($notFound);
        }
        $row->save(['delete_time' => time(), 'update_time' => time()]);
    }
}
