<?php

namespace app\common\service\app\aigc_digital_human;

use app\common\model\app\AppCase;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanAvatar;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanBilling;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanConfig;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanQuota;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanResult;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanSensitiveWord;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanTask;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanVoice;
use app\common\model\file\File as UploadFile;
use app\common\model\file\TenantFile;
use app\common\service\app\AppCaseService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\aigc_llm\AigcLlmService;
use app\common\service\FileService;
use app\common\service\point\PointService;
use app\common\service\storage\StorageConfigService;
use app\common\service\SubmitLockService;
use Exception;
use think\facade\Db;
use Throwable;

class AigcDigitalHumanService
{
    public const APP_CODE = 'aigc_digital_human';
    public const LLM_APP_CODE = 'aigc_llm';
    private const VOICE_PREVIEW_TEXT = '欢迎使用 A. PART 声音实验室，这是一段数字人音色试听。';
    private const SCRIPT_MAX_LENGTH = 200;
    private const DUPLICATE_WINDOW_SECONDS = 60;
    private const VOICE_CLONE_MAX_DURATION = 10;
    private const PROVIDER_SUBMIT_STALE_SECONDS = 300;
    private const VOICE_CLONE_DUPLICATE_SECONDS = 600;
    private const VOICE_CLONE_SUBMIT_STALE_SECONDS = 300;

    public static function config(int $tenantId): array
    {
        try {
            $config = AigcDigitalHumanConfig::where('tenant_id', $tenantId)->findOrEmpty();
            $data = $config->isEmpty() ? self::defaultConfigData() : $config->toArray();
        } catch (Throwable $e) {
            $data = self::defaultConfigData();
        }

        try {
            $data['option_config'] = AigcDigitalHumanChannelService::userConfig($tenantId);
        } catch (Throwable $e) {
            $data['option_config'] = self::defaultOptionConfig();
        }

        try {
            $data['base_config'] = self::baseConfig($tenantId);
        } catch (Throwable $e) {
            $data['base_config'] = self::defaultBaseConfig();
        }

        try {
            return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, $data);
        } catch (Throwable $e) {
            $data['display_config'] = [
                'id' => 0,
                'tenant_id' => $tenantId,
                'app_code' => self::APP_CODE,
                'title' => '数字人视频',
                'description' => '形象、音色与文案组合生成数字人视频。',
                'cover_uri' => '',
                'icon_uri' => '',
                'cover_url' => '',
                'icon_url' => '',
                'virtual_use_count' => '',
                'sort' => 90,
                'status' => 1,
                'extra' => [],
                'create_time' => 0,
                'update_time' => 0,
            ];
            return $data;
        }
    }

    private static function defaultConfigData(): array
    {
        return [
            'provider_mode' => 'platform',
            'provider' => 'mock',
            'model' => 'mock-digital-human',
            'status' => 1,
            'config_json' => [],
        ];
    }

    private static function defaultOptionConfig(): array
    {
        return [
            'channels' => [],
            'defaults' => [
                'channel' => '',
                'quality' => '',
                'ratio' => '',
                'quantity' => 1,
            ],
            'quantity_options' => AigcDigitalHumanChannelService::QUANTITY_OPTIONS,
            'max_reference_images' => AigcDigitalHumanChannelService::DEFAULT_REFERENCE_LIMIT,
        ];
    }

    private static function baseConfig(int $tenantId): array
    {
        $base = self::defaultBaseConfig();
        if ($tenantId > 0) {
            $base = array_merge($base, self::baseConfigFromTenant(0));
        }
        $base = array_merge($base, self::baseConfigFromTenant($tenantId));
        return self::normalizeBaseConfig($base);
    }

    private static function defaultBaseConfig(): array
    {
        return [
            'script_max_length' => self::SCRIPT_MAX_LENGTH,
            'voice_preview_text' => self::VOICE_PREVIEW_TEXT,
        ];
    }

    private static function baseConfigFromTenant(int $tenantId): array
    {
        try {
            $row = AigcDigitalHumanConfig::where('tenant_id', $tenantId)->findOrEmpty();
        } catch (Throwable $e) {
            return [];
        }
        if ($row->isEmpty()) {
            return [];
        }
        $config = $row['config_json'] ?? [];
        if (!is_array($config)) {
            return [];
        }
        $base = $config['base_config'] ?? $config['base'] ?? [];
        return is_array($base) ? $base : [];
    }

    private static function normalizeBaseConfig(array $config): array
    {
        $defaults = self::defaultBaseConfig();
        $maxLength = (int)($config['script_max_length'] ?? $defaults['script_max_length']);
        $previewText = trim((string)($config['voice_preview_text'] ?? $defaults['voice_preview_text']));
        return [
            'script_max_length' => max(0, $maxLength),
            'voice_preview_text' => $previewText !== '' ? $previewText : $defaults['voice_preview_text'],
        ];
    }

    public static function estimate(int $tenantId, array $params): array
    {
        return AigcDigitalHumanChannelService::estimate($tenantId, array_merge($params, ['quantity' => 1]));
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        $row = AigcDigitalHumanConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $current = $row->isEmpty() ? [] : $row->toArray();
        $configJson = array_key_exists('config_json', $params) ? $params['config_json'] : ($current['config_json'] ?? []);
        if (!is_array($configJson)) {
            $configJson = [];
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
            'provider_mode' => array_key_exists('provider_mode', $params) ? $params['provider_mode'] : ($current['provider_mode'] ?? 'platform'),
            'provider' => array_key_exists('provider', $params) ? $params['provider'] : ($current['provider'] ?? 'mock'),
            'model' => array_key_exists('model', $params) ? $params['model'] : ($current['model'] ?? 'mock-digital-human'),
            'config_json' => $configJson,
            'status' => array_key_exists('status', $params) ? $params['status'] : ($current['status'] ?? 1),
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcDigitalHumanConfig::create($data);
            return;
        }
        $row->save($data);
    }

    public static function avatarLists(int $tenantId, int $userId, string $source = ''): array
    {
        self::seedOfficialAssets($tenantId);
        $query = AigcDigitalHumanAvatar::where('tenant_id', $tenantId)
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
        $name = self::normalizeAssetText((string)($params['name'] ?? '我的形象'), '我的形象', 80);
        $id = (int)($params['id'] ?? 0);
        if ($id > 0) {
            return self::updateUserAvatar($tenantId, $userId, $id, $params, $name);
        }
        $mediaUri = self::normalizeAssetUri((string)($params['media_uri'] ?? $params['cover_uri'] ?? ''));
        $coverUri = self::normalizeAssetUri((string)($params['cover_uri'] ?? ''));
        if ($coverUri !== '' && self::isVideoUri($coverUri)) {
            $coverUri = '';
        }
        if ($mediaUri === '') {
            throw new Exception('请上传形象素材');
        }
        $estimate = AigcDigitalHumanPricingService::estimateClone($tenantId, AigcDigitalHumanPricingService::TYPE_AVATAR_CLONE);
        PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);
        $storage = StorageConfigService::getEffectiveConfig($tenantId);
        Db::startTrans();
        try {
            $row = AigcDigitalHumanAvatar::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'name' => $name,
                'source' => 'mine',
                'gender' => self::normalizeAssetText((string)($params['gender'] ?? ''), '', 20),
                'scene' => self::normalizeAssetText((string)($params['scene'] ?? ''), '', 50),
                'cover_uri' => $coverUri,
                'media_uri' => $mediaUri,
                'media_type' => (string)($params['media_type'] ?? 'video'),
                'storage_scope' => $storage['scope'],
                'storage_engine' => $storage['default'],
                'storage_domain' => self::storageDomainForTenant($tenantId),
                'provider' => (string)($params['provider'] ?? 'xhadmin'),
                'provider_asset_id' => '',
                'status' => 'ready',
                'sort' => 0,
                'create_time' => time(),
                'update_time' => time(),
                'delete_time' => 0,
            ]);
            self::consumeCloneBilling($tenantId, $userId, AigcDigitalHumanPricingService::TYPE_AVATAR_CLONE, $estimate, (int)$row['id'], 0);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return self::formatAvatar($row->toArray());
    }

    public static function voiceLists(int $tenantId, int $userId, string $source = ''): array
    {
        self::seedOfficialAssets($tenantId);
        $query = AigcDigitalHumanVoice::where('tenant_id', $tenantId)
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
        $name = self::normalizeAssetText((string)($params['name'] ?? '我的声音'), '我的声音', 80);
        $id = (int)($params['id'] ?? 0);
        if ($id > 0) {
            return self::updateUserVoice($tenantId, $userId, $id, $params, $name);
        }
        $audioUri = self::normalizeAssetUri((string)($params['audio_uri'] ?? ''));
        $providerAssetId = trim((string)($params['provider_asset_id'] ?? ''));
        if ($audioUri === '' && $providerAssetId === '') {
            throw new Exception('请上传音频样本');
        }
        $duration = self::validateVoiceCloneDuration($audioUri, $params, $tenantId);
        $estimate = AigcDigitalHumanPricingService::estimateClone($tenantId, AigcDigitalHumanPricingService::TYPE_VOICE_CLONE);
        $coverUri = self::normalizeAssetUri((string)($params['cover_uri'] ?? ''));
        $storage = StorageConfigService::getEffectiveConfig($tenantId);
        $status = $providerAssetId !== '' ? 'ready' : 'running';
        Db::startTrans();
        try {
            self::lockSubmitOwner($userId);
            $duplicateVoice = self::findRecentDuplicateVoice($tenantId, $userId, $audioUri, $providerAssetId);
            if ($duplicateVoice) {
                Db::commit();
                return self::formatVoice($duplicateVoice->toArray());
            }
            PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);
            $row = AigcDigitalHumanVoice::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'name' => $name,
                'source' => 'mine',
                'gender' => self::normalizeAssetText((string)($params['gender'] ?? ''), '', 20),
                'age_group' => self::normalizeAssetText((string)($params['age_group'] ?? ''), '', 20),
                'cover_uri' => $coverUri,
                'audio_uri' => $audioUri,
                'preview_audio_uri' => '',
                'storage_scope' => $storage['scope'],
                'storage_engine' => $storage['default'],
                'storage_domain' => self::storageDomainForTenant($tenantId),
                'duration' => $duration,
                'provider' => (string)($params['provider'] ?? 'xhadmin'),
                'provider_asset_id' => $providerAssetId,
                'status' => $status,
                'sort' => 0,
                'create_time' => time(),
                'update_time' => time(),
                'delete_time' => 0,
            ]);
            self::consumeCloneBilling($tenantId, $userId, AigcDigitalHumanPricingService::TYPE_VOICE_CLONE, $estimate, 0, (int)$row['id']);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        $rowData = $row->toArray();
        if ($providerAssetId !== '') {
            try {
                $previewAudioUri = self::generateVoicePreviewAudio($tenantId, $userId, $rowData);
                AigcDigitalHumanVoice::where(['tenant_id' => $tenantId, 'id' => (int)$row['id']])->update([
                    'preview_audio_uri' => $previewAudioUri,
                    'update_time' => time(),
                ]);
                $rowData['preview_audio_uri'] = $previewAudioUri;
            } catch (\Throwable $e) {
                $rowData['preview_audio_uri'] = '';
            }
        }

        return self::formatVoice($rowData);
    }

    private static function updateUserAvatar(int $tenantId, int $userId, int $id, array $params, string $name): array
    {
        $row = AigcDigitalHumanAvatar::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $id,
            'source' => 'mine',
        ])->where('delete_time', 0)->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('形象不存在');
        }
        $data = [
            'name' => $name,
            'scene' => self::normalizeAssetText((string)($params['scene'] ?? $row['scene'] ?? ''), '', 50),
            'update_time' => time(),
        ];
        if (array_key_exists('gender', $params)) {
            $data['gender'] = self::normalizeAssetText((string)$params['gender'], '', 20);
        }
        if (array_key_exists('cover_uri', $params)) {
            $coverUri = self::normalizeAssetUri((string)$params['cover_uri']);
            if ($coverUri !== '' && !preg_match('/^(blob:|data:)/i', $coverUri)) {
                $data['cover_uri'] = $coverUri;
            }
        }
        $row->save($data);
        return self::formatAvatar($row->toArray());
    }

    private static function updateUserVoice(int $tenantId, int $userId, int $id, array $params, string $name): array
    {
        $row = AigcDigitalHumanVoice::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'id' => $id,
            'source' => 'mine',
        ])->where('delete_time', 0)->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('音色不存在');
        }
        $data = [
            'name' => $name,
            'update_time' => time(),
        ];
        if (array_key_exists('gender', $params)) {
            $data['gender'] = self::normalizeAssetText((string)$params['gender'], '', 20);
        }
        if (array_key_exists('age_group', $params)) {
            $data['age_group'] = self::normalizeAssetText((string)$params['age_group'], '', 20);
        }
        if (array_key_exists('cover_uri', $params)) {
            $coverUri = self::normalizeAssetUri((string)$params['cover_uri']);
            if ($coverUri !== '' && !preg_match('/^(blob:|data:)/i', $coverUri)) {
                $data['cover_uri'] = $coverUri;
            }
        }
        $row->save($data);
        return self::formatVoice($row->toArray());
    }

    private static function findRecentDuplicateVoice(int $tenantId, int $userId, string $audioUri, string $providerAssetId): ?AigcDigitalHumanVoice
    {
        if ($audioUri === '' && $providerAssetId === '') {
            return null;
        }
        $query = AigcDigitalHumanVoice::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('source', 'mine')
            ->where('delete_time', 0)
            ->whereIn('status', ['ready', 'running', 'submitting'])
            ->order('id', 'desc')
            ->limit(5);
        if ($providerAssetId !== '') {
            $query->where('provider_asset_id', $providerAssetId);
        } else {
            $query->where('audio_uri', $audioUri)
                ->where('create_time', '>=', time() - self::VOICE_CLONE_DUPLICATE_SECONDS);
        }
        $rows = $query->select();
        foreach ($rows as $row) {
            if ($providerAssetId !== '' || (string)$row['audio_uri'] === $audioUri) {
                return $row;
            }
        }
        return null;
    }

    public static function processPendingCloneAssets(int $tenantId, int $userId = 0): void
    {
        self::refreshRunningCloneAssets($tenantId, $userId);
    }

    public static function previewVoice(int $tenantId, int $userId, array $params): array
    {
        $voice = self::findUserVoice($tenantId, $userId, (int)($params['voice_id'] ?? 0));
        if ((string)($voice['provider_asset_id'] ?? '') === '') {
            throw new Exception('当前音色未完成克隆，无法试听');
        }

        $previewAudioUri = (string)($voice['preview_audio_uri'] ?? '');
        if ($previewAudioUri !== '') {
            return [
                'audio_uri' => $previewAudioUri,
                'audio_url' => self::voicePreviewAudioUrl($previewAudioUri, $tenantId, $voice),
                'cached' => true,
            ];
        }

        $previewAudioUri = self::generateVoicePreviewAudio($tenantId, $userId, $voice, (string)($params['text'] ?? ''));
        AigcDigitalHumanVoice::where(['tenant_id' => $tenantId, 'id' => (int)$voice['id']])->update([
            'preview_audio_uri' => $previewAudioUri,
            'update_time' => time(),
        ]);
        return [
            'audio_uri' => $previewAudioUri,
            'audio_url' => self::voicePreviewAudioUrl($previewAudioUri, $tenantId, $voice),
            'cached' => false,
        ];
    }

    public static function trimVoiceSample(int $tenantId, int $userId, array $params): array
    {
        $file = request()->file('file');
        if (empty($file)) {
            throw new Exception('请上传需要裁剪的音频');
        }

        $extension = strtolower((string)$file->extension());
        if (!in_array($extension, ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'webm', 'flac', 'opus'], true)) {
            throw new Exception('仅支持 mp3、wav、m4a、aac、ogg、webm 音频裁剪');
        }

        $mime = strtolower((string)$file->getMime());
        $compatibleMimes = ['application/ogg', 'application/octet-stream', 'application/mp4', 'video/mp4', 'video/webm'];
        if ($mime !== '' && !str_starts_with($mime, 'audio/') && !in_array($mime, $compatibleMimes, true)) {
            throw new Exception('请上传有效的音频文件');
        }

        $start = max(0, (float)($params['start'] ?? 0));
        $duration = (float)($params['duration'] ?? self::VOICE_CLONE_MAX_DURATION);
        $duration = $duration > 0 ? min($duration, self::VOICE_CLONE_MAX_DURATION) : self::VOICE_CLONE_MAX_DURATION;
        $sourcePath = (string)$file->getRealPath();
        if ($sourcePath === '' || !is_file($sourcePath)) {
            throw new Exception('音频文件读取失败，请重新上传');
        }

        $ffmpeg = self::findExecutable(['ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg']);
        if ($ffmpeg === '' || !function_exists('exec')) {
            throw new Exception('当前服务器暂不支持兼容裁剪，请换用 10 秒以内的 mp3/wav 音频上传');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'aigc_voice_trim_');
        if ($tmp === false) {
            throw new Exception('音频裁剪临时文件创建失败');
        }
        $originalName = method_exists($file, 'getOriginalName') ? (string)$file->getOriginalName() : '';
        $baseName = trim((string)pathinfo($originalName ?: 'voice', PATHINFO_FILENAME));
        $safeBaseName = preg_replace('/[^a-zA-Z0-9_\x{4e00}-\x{9fa5}-]+/u', '-', $baseName) ?: 'voice';
        $outputPath = $tmp . '-' . $safeBaseName . '-trim-10s.wav';
        @rename($tmp, $outputPath);

        $command = implode(' ', [
            escapeshellarg($ffmpeg),
            '-y',
            '-hide_banner',
            '-loglevel error',
            '-ss ' . escapeshellarg((string)$start),
            '-i ' . escapeshellarg($sourcePath),
            '-t ' . escapeshellarg((string)$duration),
            '-vn',
            '-ac 1',
            '-ar 44100',
            '-acodec pcm_s16le',
            escapeshellarg($outputPath),
        ]);

        $output = [];
        $code = 1;
        @exec($command . ' 2>&1', $output, $code);
        if ($code !== 0 || !is_file($outputPath) || filesize($outputPath) <= 44) {
            @unlink($outputPath);
            throw new Exception('音频兼容裁剪失败，请换用 mp3/wav 格式后重试');
        }

        try {
            $stored = AigcDigitalHumanAssetService::uploadLocalFile($outputPath, $tenantId, $userId, 'audio');
        } finally {
            @unlink($outputPath);
        }

        return [
            'uri' => $stored['uri'],
            'url' => $stored['url'],
            'duration' => (int)ceil($duration),
            'name' => basename($stored['uri']),
            'storage_scope' => $stored['storage_scope'],
            'storage_engine' => $stored['storage_engine'],
            'storage_domain' => $stored['storage_domain'],
        ];
    }

    private static function generateVoicePreviewAudio(int $tenantId, int $userId, array $voice, string $text = ''): string
    {
        $text = trim($text);
        if ($text === '') {
            $text = (string)self::baseConfig($tenantId)['voice_preview_text'];
        }
        $text = mb_substr($text, 0, 80);

        $config = self::config($tenantId);
        $channels = $config['option_config']['channels'] ?? [];
        $defaults = $config['option_config']['defaults'] ?? [];
        $firstChannel = is_array($channels) && !empty($channels) ? $channels[0] : [];
        $firstQuality = $firstChannel['qualities'][0] ?? [];
        $firstRatio = $firstQuality['ratios'][0] ?? [];
        $selection = AigcDigitalHumanChannelService::resolveSelection($tenantId, [
            'channel' => $defaults['channel'] ?? ($firstChannel['value'] ?? 'master'),
            'quality' => $defaults['quality'] ?? ($firstQuality['value'] ?? '1k'),
            'ratio' => $defaults['ratio'] ?? ($firstRatio['value'] ?? '9:16'),
        ]);
        $request = new AigcDigitalHumanGenerateRequest(
            $text,
            '',
            $selection['channel']['code'],
            $selection['spec']['quality'],
            $selection['spec']['ratio'],
            [],
            self::formatVoice($voice),
            $selection['spec'],
            $selection['spec']['provider_params_json'] ?? [],
            array_merge($selection['channel']['config_json'] ?? [], [
                'model' => $selection['channel']['model'],
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ])
        );

        $provider = new XhadminAigcDigitalHumanProvider();
        $submit = $provider->submitTts($request);
        $taskId = (string)($submit['task_id'] ?? '');
        if ($taskId === '') {
            throw new Exception('试听合成提交失败');
        }

        $lastError = '';
        for ($i = 0; $i < 15; $i++) {
            if ($i > 0) {
                usleep(1000000);
            }
            $tts = $provider->fetchTtsResult($taskId, $request);
            if (!empty($tts['pending'])) {
                continue;
            }
            if (empty($tts['success'])) {
                $lastError = (string)($tts['error'] ?? '供应商任务失败');
                break;
            }
            $audioUrl = (string)($tts['audio_url'] ?? '');
            if ($audioUrl === '') {
                throw new Exception('试听合成未返回音频');
            }
            $audio = AigcDigitalHumanAssetService::persistGeneratedAudio($audioUrl, $tenantId, $userId);
            return (string)($audio['uri'] ?? $audioUrl);
        }

        throw new Exception($lastError !== '' ? self::friendlyStageMessage($lastError) : '试听合成仍在处理中，请稍后重试');
    }

    public static function publicAvatarLists(int $tenantId, array $params = []): array
    {
        self::seedOfficialAssets($tenantId);
        return self::paginateRows(AigcDigitalHumanAvatar::where([
            'tenant_id' => $tenantId,
            'source' => 'official',
            'user_id' => 0,
        ])->where('delete_time', 0)->order(['sort' => 'desc', 'id' => 'desc']), $params, 100, [self::class, 'formatAvatar']);
    }

    public static function savePublicAvatar(int $tenantId, array $params): array
    {
        $name = trim((string)($params['name'] ?? '公共形象'));
        $mediaUri = FileService::setFileUrl((string)($params['media_uri'] ?? ''));
        if ($mediaUri === '') {
            throw new Exception('请上传公共形象视频素材');
        }
        $coverUri = FileService::setFileUrl((string)($params['cover_uri'] ?? ''));
        if ($coverUri !== '' && self::isVideoUri($coverUri)) {
            $coverUri = '';
        }
        $storage = StorageConfigService::getEffectiveConfig($tenantId);
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => 0,
            'name' => $name,
            'source' => 'official',
            'gender' => (string)($params['gender'] ?? ''),
            'scene' => (string)($params['scene'] ?? ''),
            'cover_uri' => $coverUri,
            'media_uri' => $mediaUri,
            'media_type' => 'video',
            'storage_scope' => $storage['scope'],
            'storage_engine' => $storage['default'],
            'storage_domain' => self::storageDomainForTenant($tenantId),
            'provider' => (string)($params['provider'] ?? 'xhadmin'),
            'provider_asset_id' => (string)($params['provider_asset_id'] ?? ''),
            'status' => (string)($params['status'] ?? 'ready'),
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
            'delete_time' => 0,
        ];
        $id = (int)($params['id'] ?? 0);
        if ($id > 0) {
            $row = AigcDigitalHumanAvatar::where(['tenant_id' => $tenantId, 'id' => $id, 'source' => 'official', 'user_id' => 0])->findOrEmpty();
            if ($row->isEmpty()) {
                throw new Exception('公共形象不存在');
            }
            $row->save($data);
            return self::formatAvatar($row->toArray());
        }
        $data['create_time'] = time();
        return self::formatAvatar(AigcDigitalHumanAvatar::create($data)->toArray());
    }

    public static function deletePublicAvatar(int $tenantId, int $id): void
    {
        $row = AigcDigitalHumanAvatar::where(['tenant_id' => $tenantId, 'id' => $id, 'source' => 'official', 'user_id' => 0])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('公共形象不存在');
        }
        $row->save(['delete_time' => time(), 'update_time' => time()]);
    }

    public static function userAvatarLists(int $tenantId, array $params = []): array
    {
        $query = AigcDigitalHumanAvatar::alias('a')
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
        $row = AigcDigitalHumanAvatar::where(['tenant_id' => $tenantId, 'id' => $id, 'source' => 'mine'])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('用户形象不存在');
        }
        $row->save(['delete_time' => time(), 'update_time' => time()]);
    }

    public static function publicVoiceLists(int $tenantId, array $params = []): array
    {
        self::seedOfficialAssets($tenantId);
        return self::paginateRows(AigcDigitalHumanVoice::where([
            'tenant_id' => $tenantId,
            'source' => 'official',
            'user_id' => 0,
        ])->where('delete_time', 0)->order(['sort' => 'desc', 'id' => 'desc']), $params, 100, [self::class, 'formatVoice']);
    }

    public static function savePublicVoice(int $tenantId, array $params): array
    {
        $name = trim((string)($params['name'] ?? '公共音色'));
        $audioUri = FileService::setFileUrl((string)($params['audio_uri'] ?? ''));
        $providerAssetId = trim((string)($params['provider_asset_id'] ?? ''));
        if ($providerAssetId === '' && $audioUri === '') {
            throw new Exception('请填写音色ID或上传克隆音频');
        }
        $duration = self::validateVoiceCloneDuration($audioUri, $params, $tenantId);
        if ($providerAssetId === '' && $audioUri !== '') {
            $providerAssetId = (new XhadminAigcDigitalHumanProvider())->cloneVoice([
                'title' => $name,
                'audio_url' => self::fileUrlForTenant($audioUri, $tenantId),
                'visibility' => 'private',
                'description' => '租户公共音色',
                'enhance_audio_quality' => (bool)($params['enhance_audio_quality'] ?? false),
            ], $tenantId, 0);
        }
        $storage = StorageConfigService::getEffectiveConfig($tenantId);
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => 0,
            'name' => $name,
            'source' => 'official',
            'gender' => (string)($params['gender'] ?? ''),
            'age_group' => (string)($params['age_group'] ?? ''),
            'cover_uri' => FileService::setFileUrl((string)($params['cover_uri'] ?? '')),
            'audio_uri' => $audioUri,
            'storage_scope' => $storage['scope'],
            'storage_engine' => $storage['default'],
            'storage_domain' => self::storageDomainForTenant($tenantId),
            'duration' => $duration,
            'provider' => (string)($params['provider'] ?? 'xhadmin'),
            'provider_asset_id' => $providerAssetId,
            'status' => (string)($params['status'] ?? 'ready'),
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
            'delete_time' => 0,
        ];
        $id = (int)($params['id'] ?? 0);
        if ($id > 0) {
            $row = AigcDigitalHumanVoice::where(['tenant_id' => $tenantId, 'id' => $id, 'source' => 'official', 'user_id' => 0])->findOrEmpty();
            if ($row->isEmpty()) {
                throw new Exception('公共音色不存在');
            }
            $row->save($data);
            return self::formatVoice($row->toArray());
        }
        $data['create_time'] = time();
        return self::formatVoice(AigcDigitalHumanVoice::create($data)->toArray());
    }

    public static function deletePublicVoice(int $tenantId, int $id): void
    {
        $row = AigcDigitalHumanVoice::where(['tenant_id' => $tenantId, 'id' => $id, 'source' => 'official', 'user_id' => 0])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('公共音色不存在');
        }
        $row->save(['delete_time' => time(), 'update_time' => time()]);
    }

    public static function userVoiceLists(int $tenantId, array $params = []): array
    {
        $query = AigcDigitalHumanVoice::alias('v')
            ->leftJoin('user u', 'u.id = v.user_id AND u.tenant_id = v.tenant_id')
            ->field('v.*,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
            ->where('v.tenant_id', $tenantId)
            ->where('v.source', 'mine')
            ->where('v.delete_time', 0);
        $userId = (int)($params['user_id'] ?? 0);
        $keyword = trim((string)($params['keyword'] ?? ''));
        $status = trim((string)($params['status'] ?? ''));
        if ($userId > 0) {
            $query->where('v.user_id', $userId);
        }
        if ($keyword !== '') {
            $query->where('v.name', 'like', '%' . $keyword . '%');
        }
        if ($status !== '') {
            $query->where('v.status', $status);
        }
        return self::paginateRows($query->order(['v.id' => 'desc']), $params, 100, [self::class, 'formatVoice']);
    }

    public static function publishUserVoice(int $tenantId, int $id): array
    {
        $row = AigcDigitalHumanVoice::where(['tenant_id' => $tenantId, 'id' => $id, 'source' => 'mine'])->where('delete_time', 0)->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('用户音色不存在');
        }
        if ((string)$row['provider_asset_id'] === '') {
            throw new Exception('当前音色未完成克隆，无法设为公共');
        }
        return self::savePublicVoice($tenantId, [
            'name' => (string)$row['name'],
            'provider_asset_id' => (string)$row['provider_asset_id'],
            'audio_uri' => (string)$row['audio_uri'],
            'cover_uri' => (string)$row['cover_uri'],
            'gender' => (string)$row['gender'],
            'age_group' => (string)$row['age_group'],
            'duration' => (int)$row['duration'],
            'provider' => (string)$row['provider'],
            'status' => 'ready',
            'sort' => 0,
        ]);
    }

    public static function deleteUserVoice(int $tenantId, int $id): void
    {
        $row = AigcDigitalHumanVoice::where(['tenant_id' => $tenantId, 'id' => $id, 'source' => 'mine'])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('用户音色不存在');
        }
        $row->save(['delete_time' => time(), 'update_time' => time()]);
    }

    public static function generate(int $tenantId, int $userId, array $params): array
    {
        $scriptText = trim((string)($params['script_text'] ?? $params['prompt'] ?? ''));
        $driverAudioUri = FileService::setFileUrl((string)($params['driver_audio_uri'] ?? $params['audio_uri'] ?? ''));
        $audioDriven = $driverAudioUri !== '';
        if (!$audioDriven && $scriptText === '') {
            throw new Exception('请输入口播文案');
        }
        $baseConfig = self::baseConfig($tenantId);
        $scriptMaxLength = (int)($baseConfig['script_max_length'] ?? 0);
        if ($scriptText !== '' && $scriptMaxLength > 0 && mb_strlen($scriptText) > $scriptMaxLength) {
            throw new Exception('口播文案不能超过' . $scriptMaxLength . '个字');
        }
        if ($scriptText !== '') {
            self::checkSensitiveWords($tenantId, $scriptText);
        }
        self::seedOfficialAssets($tenantId);
        $avatar = self::findUserAvatar($tenantId, $userId, (int)($params['avatar_id'] ?? 0));
        $voice = $audioDriven ? [] : self::findUserVoice($tenantId, $userId, (int)($params['voice_id'] ?? 0));
        $selection = AigcDigitalHumanChannelService::resolveSelection($tenantId, $params);
        if (self::isXhadminProvider((string)$selection['channel']['provider'])) {
            $audioDriven ? self::assertUsableProviderAvatar($avatar) : self::assertUsableProviderAssets($avatar, $voice);
        }
        $title = trim((string)($params['title'] ?? '数字人口播'));
        $prompt = trim((string)($params['prompt'] ?? ''));
        $duration = max(1, (int)($params['duration'] ?? ($audioDriven ? self::detectAudioDurationFromUri($driverAudioUri, $tenantId) : self::estimateAudioDuration($scriptText))));
        $duplicateCriteria = [
            'avatar_id' => (int)$avatar['id'],
            'voice_id' => $audioDriven ? 0 : (int)$voice['id'],
            'title' => $title,
            'script_text' => $scriptText,
            'prompt' => $prompt,
            'channel' => (string)$selection['channel']['code'],
            'quality' => (string)$selection['spec']['quality'],
            'ratio' => (string)$selection['spec']['ratio'],
            'duration' => $duration,
            'tts_audio_uri' => $driverAudioUri,
        ];
        $estimate = AigcDigitalHumanChannelService::estimate($tenantId, array_merge($params, [
            'channel' => $selection['channel']['code'],
            'quality' => $selection['spec']['quality'],
            'ratio' => $selection['spec']['ratio'],
            'duration' => $duration,
            'quantity' => 1,
        ]));

        $submitLock = SubmitLockService::acquire('aigc_digital_human_submit', $tenantId, $userId, true);
        Db::startTrans();
        try {
            self::lockSubmitOwner($userId);
            $duplicateTask = self::findRecentDuplicateTask($tenantId, $userId, $duplicateCriteria);
            if ($duplicateTask) {
                Db::commit();
                return self::buildDuplicateGenerateResponse($duplicateTask, $tenantId, $userId);
            }
            self::checkQuota($tenantId, $userId, 1);
            PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);
            $task = AigcDigitalHumanTask::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'avatar_id' => (int)$avatar['id'],
                'voice_id' => $audioDriven ? 0 : (int)$voice['id'],
                'title' => $title,
                'script_text' => $scriptText,
                'prompt' => $prompt,
                'channel' => $selection['channel']['code'],
                'quality' => $selection['spec']['quality'],
                'ratio' => $selection['spec']['ratio'],
                'duration' => (int)$estimate['duration'],
                'tenant_cost_points' => $estimate['tenant_cost_points'],
                'user_charge_points' => $estimate['user_charge_points'],
                'provider' => $selection['channel']['provider'],
                'model' => $selection['channel']['model'],
                'provider_task_id' => '',
                'provider_stage' => $audioDriven ? 'lipsync_submitted' : 'created',
                'tts_task_id' => '',
                'tts_audio_uri' => $driverAudioUri,
                'provider_payload_json' => [],
                'status' => 'running',
                'progress' => $audioDriven ? 45 : 5,
                'error' => '',
                'create_time' => time(),
                'update_time' => time(),
                'finish_time' => 0,
                'delete_time' => 0,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        } finally {
            SubmitLockService::release($submitLock);
        }

        $provider = self::providerFor((string)$selection['channel']['provider']);
        $request = self::buildRequestFromData($task->toArray(), $avatar, $voice, $selection);
        if ($audioDriven) {
            $request = self::requestWithAudioUrl($request, self::fileUrlForTenant($driverAudioUri, $tenantId));
        }
        if ($provider instanceof XhadminAigcDigitalHumanProvider) {
            self::advanceRunningTaskSafely($task, $request, $selection, $estimate, $provider);
            $latest = AigcDigitalHumanTask::where(['tenant_id' => $tenantId, 'id' => (int)$task['id']])->findOrEmpty();
            return [
                'task_id' => (int)$task['id'],
                'status' => (string)($latest['status'] ?? $task['status']),
                'provider_stage' => (string)($latest['provider_stage'] ?? $task['provider_stage'] ?? ''),
                'results' => [],
                'error' => (string)($latest['error'] ?? $task['error'] ?? ''),
            ];
        }

        $result = $provider->generate($request);

        $task->provider_task_id = $result->providerTaskId;
        $task->update_time = time();
        $task->save();
        if (!$result->success) {
            $task->save(['status' => 'failed', 'progress' => 100, 'error' => $result->error, 'finish_time' => time()]);
            return ['task_id' => (int)$task['id'], 'status' => 'failed', 'results' => [], 'error' => $result->error];
        }
        if (empty($result->videos) && $result->providerTaskId !== '') {
            return ['task_id' => (int)$task['id'], 'status' => 'running', 'results' => []];
        }
        $rows = self::finishTaskWithVideos($task, $avatar, $voice, $selection, $estimate, $result->videos);
        return ['task_id' => (int)$task['id'], 'status' => 'success', 'results' => $rows];
    }

    private static function findRecentDuplicateTask(int $tenantId, int $userId, array $criteria): ?AigcDigitalHumanTask
    {
        $rows = AigcDigitalHumanTask::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('delete_time', 0)
            ->where('avatar_id', (int)$criteria['avatar_id'])
            ->where('voice_id', (int)$criteria['voice_id'])
            ->where('title', $criteria['title'])
            ->where('script_text', $criteria['script_text'])
            ->where('prompt', $criteria['prompt'])
            ->where('channel', $criteria['channel'])
            ->where('quality', $criteria['quality'])
            ->where('ratio', $criteria['ratio'])
            ->where('duration', (int)$criteria['duration'])
            ->where('create_time', '>=', time() - self::DUPLICATE_WINDOW_SECONDS)
            ->order('id', 'desc')
            ->limit(5)
            ->select();
        foreach ($rows as $row) {
            if (isset($criteria['tts_audio_uri']) && (string)($criteria['tts_audio_uri'] ?? '') !== '' && (string)$row['tts_audio_uri'] !== (string)$criteria['tts_audio_uri']) {
                continue;
            }
            if (in_array((string)$row['status'], ['failed', 'canceled'], true)) {
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

    private static function buildDuplicateGenerateResponse(AigcDigitalHumanTask $task, int $tenantId, int $userId): array
    {
        if ((string)$task['status'] === 'running') {
            self::refreshRunningTasks($tenantId, $userId, (int)$task['id']);
        }
        $latest = AigcDigitalHumanTask::where(['tenant_id' => $tenantId, 'id' => (int)$task['id']])->findOrEmpty();
        if ($latest->isEmpty()) {
            $latest = $task;
        }
        $status = (string)($latest['status'] ?: 'running');
        $response = [
            'task_id' => (int)$latest['id'],
            'status' => $status,
            'provider_stage' => (string)($latest['provider_stage'] ?? ''),
            'progress' => (int)($latest['progress'] ?? 0),
            'results' => [],
            'error' => (string)($latest['error'] ?? ''),
        ];
        if ($status === 'success') {
            $response['results'] = self::resultLists($tenantId, $userId, (int)$latest['id']);
        }
        return $response;
    }

    public static function assistScript(int $tenantId, int $userId, array $params): array
    {
        $action = (string)($params['action'] ?? '');
        if (!in_array($action, ['translate', 'copywrite'], true)) {
            throw new Exception('助手类型错误');
        }
        $content = trim((string)($params['content'] ?? $params['script_text'] ?? $params['prompt'] ?? ''));
        if ($content === '') {
            throw new Exception($action === 'translate' ? '请输入需要翻译的文案' : '请输入需要润色的文案');
        }
        self::checkSensitiveWords($tenantId, $content);
        $targetLanguage = trim((string)($params['target_language'] ?? ''));
        if ($action === 'translate' && $targetLanguage === '') {
            throw new Exception('请选择目标语言');
        }

        $result = AigcLlmService::generateText($tenantId, $userId, [
            'content' => $content,
            'system_prompt' => self::scriptAssistPrompt($action, $targetLanguage, $tenantId),
            'model_code' => (string)($params['model_code'] ?? $params['model'] ?? ''),
            'source_app_code' => self::APP_CODE,
            'source_type' => 'digital_human_' . $action,
            'source_id' => '',
        ]);
        $script = self::normalizeAssistedScript((string)($result['content'] ?? ''), $tenantId);
        if ($script === '') {
            throw new Exception('文案生成失败，请稍后重试');
        }

        return array_merge($result, [
            'content' => $script,
            'action' => $action,
        ]);
    }

    public static function taskLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        self::refreshRunningTasks($tenantId, $userId);
        $query = AigcDigitalHumanTask::alias('t')
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
        if ($taskIds) {
            $resultRows = AigcDigitalHumanResult::where('tenant_id', $tenantId)
                ->where('delete_time', 0)
                ->whereIn('task_id', $taskIds)
                ->order('id', 'asc')
                ->select()
                ->toArray();
            foreach ($resultRows as $result) {
                $result = self::formatResult($result);
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
            $row['cover_url'] = (string)($first['cover_url'] ?? '');
            $row['tts_audio_url'] = self::fileUrlForTenant((string)($row['tts_audio_uri'] ?? ''), $tenantId, $row);
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

    public static function platformTaskLogs(array $params): array
    {
        $query = AigcDigitalHumanTask::where('delete_time', 0)->order('id', 'desc');
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
            $query->where(function ($query) use ($providerTaskId) {
                $query->whereOr([
                    ['provider_task_id', '=', $providerTaskId],
                    ['tts_task_id', '=', $providerTaskId],
                ]);
            });
        }
        $limit = min(100, max(10, (int)($params['limit'] ?? 50)));
        $rows = $query->limit($limit)->select()->toArray();
        foreach ($rows as &$row) {
            $row['provider_payload_summary'] = self::providerPayloadSummary($row['provider_payload_json'] ?? []);
        }
        return $rows;
    }

    public static function taskDetail(int $tenantId, int $taskId, int $userId = 0): array
    {
        self::refreshRunningTasks($tenantId, $userId, $taskId);
        $query = AigcDigitalHumanTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        $data = $task->toArray();
        $data['results'] = self::taskResults($tenantId, $userId, $taskId);
        return $data;
    }

    public static function platformTaskLogDetail(int $taskId): array
    {
        $task = AigcDigitalHumanTask::where(['id' => $taskId])->where('delete_time', 0)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        $data = $task->toArray();
        $data['results'] = self::taskResults((int)$data['tenant_id'], 0, (int)$data['id']);
        $data['provider_payload_summary'] = self::providerPayloadSummary($data['provider_payload_json'] ?? []);
        return $data;
    }

    public static function retryTask(int $tenantId, int $taskId): array
    {
        $task = self::taskDetail($tenantId, $taskId);
        return self::generate($tenantId, (int)$task['user_id'], [
            'title' => $task['title'],
            'script_text' => $task['script_text'],
            'prompt' => $task['prompt'],
            'avatar_id' => $task['avatar_id'],
            'voice_id' => $task['voice_id'],
            'audio_uri' => $task['tts_audio_uri'],
            'channel' => $task['channel'],
            'quality' => $task['quality'],
            'ratio' => $task['ratio'],
        ]);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = AigcDigitalHumanTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        $task->save(['delete_time' => time(), 'update_time' => time()]);
        AigcDigitalHumanResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time()]);
    }

    public static function resultLists(int $tenantId, int $userId = 0, int $taskId = 0, string $status = '', bool $refresh = true): array
    {
        if ($refresh) {
            self::refreshRunningTasks($tenantId, $userId, $taskId);
        }
        $query = AigcDigitalHumanTask::where('tenant_id', $tenantId)->where('delete_time', 0)->order('id', 'desc');
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
        if ($taskIds) {
            $rows = AigcDigitalHumanResult::where('tenant_id', $tenantId)
                ->where('delete_time', 0)
                ->whereIn('task_id', $taskIds)
                ->order('id', 'asc')
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                $row = self::formatResult($row);
                $resultMap[(int)$row['task_id']][] = $row;
            }
        }
        foreach ($tasks as &$task) {
            $results = $resultMap[(int)$task['id']] ?? [];
            $first = $results[0] ?? [];
            $task['task_id'] = (int)$task['id'];
            $task['results'] = $results;
            $task['result_count'] = count($results);
            $task['result_id'] = (int)($first['id'] ?? 0);
            $task['video_uri'] = (string)($first['video_uri'] ?? '');
            $task['video_url'] = (string)($first['video_url'] ?? '');
            $task['cover_url'] = (string)($first['cover_url'] ?? '');
            $task['width'] = (int)($first['width'] ?? 0);
            $task['height'] = (int)($first['height'] ?? 0);
        }
        return $tasks;
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = AigcDigitalHumanResult::where(['tenant_id' => $tenantId, 'id' => $resultId]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $result = $query->findOrEmpty();
        if ($result->isEmpty()) {
            throw new Exception('作品不存在');
        }
        $result->save(['delete_time' => time()]);
    }

    private static function taskResults(int $tenantId, int $userId, int $taskId): array
    {
        $query = AigcDigitalHumanResult::where('tenant_id', $tenantId)
            ->where('task_id', $taskId)
            ->where('delete_time', 0)
            ->order('id', 'asc');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        return array_map([self::class, 'formatResult'], $query->select()->toArray());
    }

    public static function saveCaseFromTask(int $tenantId, int $taskId, array $params = []): array
    {
        $task = self::taskDetail($tenantId, $taskId);
        if (($task['status'] ?? '') !== 'success') {
            throw new Exception('只有已完成任务可以设为案例');
        }
        $first = $task['results'][0] ?? [];
        if (empty($first['video_uri'])) {
            throw new Exception('任务暂无可用作品');
        }
        return AppCaseService::save($tenantId, self::APP_CODE, [
            'title' => trim((string)($params['title'] ?? '')) ?: ((string)$task['title'] ?: '数字人案例'),
            'prompt' => $task['script_text'] ?? '',
            'media_type' => 'video',
            'cover_uri' => $first['cover_uri'] ?: $first['video_uri'],
            'media_uri' => $first['video_uri'],
            'reference_images' => [],
            'config_json' => [
                'channel' => $task['channel'] ?? '',
                'model' => $task['model'] ?? '',
                'ratio' => $task['ratio'] ?? '',
                'quality' => $task['quality'] ?? '',
                'avatar_id' => $task['avatar_id'] ?? 0,
                'voice_id' => $task['voice_id'] ?? 0,
            ],
            'source_task_id' => (int)$task['id'],
            'source_result_id' => (int)($first['id'] ?? 0),
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? 0),
        ]);
    }

    public static function quotaLists(int $tenantId, array $params = []): array
    {
        return self::paginateRows(AigcDigitalHumanQuota::where('tenant_id', $tenantId)->order('id', 'desc'), $params, 100);
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
        $row = AigcDigitalHumanQuota::where(['tenant_id' => $tenantId, 'user_id' => $userId])->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcDigitalHumanQuota::create($data);
            return;
        }
        $row->save($data);
    }

    public static function sensitiveWordLists(int $tenantId, array $params = []): array
    {
        return self::paginateRows(AigcDigitalHumanSensitiveWord::where('tenant_id', $tenantId)->order('id', 'desc'), $params, 200);
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
            $row = AigcDigitalHumanSensitiveWord::where(['tenant_id' => $tenantId, 'id' => $id])->findOrEmpty();
            if ($row->isEmpty()) {
                throw new Exception('敏感词不存在');
            }
            $row->save($data);
            return;
        }
        $data['create_time'] = time();
        AigcDigitalHumanSensitiveWord::create($data);
    }

    public static function stat(int $tenantId = 0): array
    {
        $task = AigcDigitalHumanTask::where([])->where('delete_time', 0);
        $result = AigcDigitalHumanResult::where([])->where('delete_time', 0);
        $avatar = AigcDigitalHumanAvatar::where([])->where('delete_time', 0);
        $voice = AigcDigitalHumanVoice::where([])->where('delete_time', 0);
        $billing = AigcDigitalHumanBilling::where([])->where('billing_status', 'deducted');
        if ($tenantId > 0) {
            $task->where('tenant_id', $tenantId);
            $result->where('tenant_id', $tenantId);
            $avatar->where('tenant_id', $tenantId);
            $voice->where('tenant_id', $tenantId);
            $billing->where('tenant_id', $tenantId);
        }
        return [
            'task_total' => (clone $task)->count(),
            'task_success' => (clone $task)->where('status', 'success')->count(),
            'task_failed' => (clone $task)->where('status', 'failed')->count(),
            'result_total' => (clone $result)->count(),
            'avatar_total' => (clone $avatar)->count(),
            'voice_total' => (clone $voice)->count(),
            'quota_total' => $tenantId > 0 ? AigcDigitalHumanQuota::where('tenant_id', $tenantId)->sum('total_quota') : AigcDigitalHumanQuota::where([])->sum('total_quota'),
            'quota_used' => $tenantId > 0 ? AigcDigitalHumanQuota::where('tenant_id', $tenantId)->sum('used_quota') : AigcDigitalHumanQuota::where([])->sum('used_quota'),
            'tenant_cost_points' => (clone $billing)->sum('tenant_cost_points'),
            'user_charge_points' => (clone $billing)->sum('user_charge_points'),
        ];
    }

    private static function findUserAvatar(int $tenantId, int $userId, int $id): array
    {
        $query = AigcDigitalHumanAvatar::where(['tenant_id' => $tenantId, 'id' => $id])->where('delete_time', 0);
        $query->whereRaw("(source = 'official' OR (source = 'mine' AND user_id = " . (int)$userId . '))');
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('请选择当前用户可用的形象');
        }
        return $row->toArray();
    }

    private static function findUserVoice(int $tenantId, int $userId, int $id): array
    {
        $query = AigcDigitalHumanVoice::where(['tenant_id' => $tenantId, 'id' => $id])->where('delete_time', 0);
        $query->whereRaw("(source = 'official' OR (source = 'mine' AND user_id = " . (int)$userId . '))');
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('请选择当前用户可用的声音');
        }
        return $row->toArray();
    }

    private static function seedOfficialAssets(int $tenantId): void
    {
        if ($tenantId <= 0) {
            return;
        }
        if (AigcDigitalHumanAvatar::where(['tenant_id' => $tenantId, 'source' => 'official'])->count() <= 0) {
            $storage = StorageConfigService::getEffectiveConfig($tenantId);
            AigcDigitalHumanAvatar::create([
                'tenant_id' => $tenantId,
                'user_id' => 0,
                'name' => '官方主播',
                'source' => 'official',
                'gender' => 'female',
                'scene' => '口播',
                'cover_uri' => 'resource/image/common/menu_generator.png',
                'media_uri' => 'resource/image/common/menu_generator.png',
                'media_type' => 'image',
                'storage_scope' => $storage['scope'],
                'storage_engine' => $storage['default'],
                'storage_domain' => self::storageDomainForTenant($tenantId),
                'provider' => 'mock',
                'provider_asset_id' => 'official-avatar',
                'status' => 'ready',
                'sort' => 100,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }
        if (AigcDigitalHumanVoice::where(['tenant_id' => $tenantId, 'source' => 'official'])->count() <= 0) {
            $storage = StorageConfigService::getEffectiveConfig($tenantId);
            AigcDigitalHumanVoice::create([
                'tenant_id' => $tenantId,
                'user_id' => 0,
                'name' => '自然女声',
                'source' => 'official',
                'gender' => 'female',
                'age_group' => 'young',
                'cover_uri' => 'resource/image/common/menu_generator.png',
                'audio_uri' => '',
                'preview_audio_uri' => '',
                'storage_scope' => $storage['scope'],
                'storage_engine' => $storage['default'],
                'storage_domain' => self::storageDomainForTenant($tenantId),
                'duration' => 0,
                'provider' => 'mock',
                'provider_asset_id' => 'official-voice',
                'status' => 'ready',
                'sort' => 100,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }
    }

    private static function refreshRunningTasks(int $tenantId, int $userId = 0, int $taskId = 0): void
    {
        $query = AigcDigitalHumanTask::where('tenant_id', $tenantId)
            ->where('delete_time', 0)
            ->where('status', 'running');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($taskId > 0) {
            $query->where('id', $taskId);
        }
        $tasks = $query->order('id', 'asc')->limit(10)->select();
        foreach ($tasks as $task) {
            if (!self::isXhadminProvider((string)$task['provider'])) {
                continue;
            }
            try {
                $avatar = self::findUserAvatar((int)$task['tenant_id'], (int)$task['user_id'], (int)$task['avatar_id']);
                $voice = (int)$task['voice_id'] > 0 ? self::findUserVoice((int)$task['tenant_id'], (int)$task['user_id'], (int)$task['voice_id']) : [];
                $selection = AigcDigitalHumanChannelService::resolveSelection((int)$task['tenant_id'], [
                    'channel' => $task['channel'],
                    'quality' => $task['quality'],
                    'ratio' => $task['ratio'],
                ]);
                $estimate = AigcDigitalHumanChannelService::estimate((int)$task['tenant_id'], [
                    'channel' => $task['channel'],
                    'quality' => $task['quality'],
                    'ratio' => $task['ratio'],
                    'duration' => max(1, (int)($task['duration'] ?? 1)),
                    'quantity' => 1,
                ]);
                $request = self::buildRequestFromData($task->toArray(), $avatar, $voice, $selection);
                self::advanceRunningTaskSafely($task, $request, $selection, $estimate, self::providerFor((string)$task['provider']));
            } catch (\Throwable $e) {
                self::failTask($task, (string)($task['provider_stage'] ?? 'failed'), self::stageErrorPrefix((string)($task['provider_stage'] ?? '')) . self::friendlyStageMessage($e->getMessage()));
            }
        }
    }

    private static function refreshRunningCloneAssets(int $tenantId, int $userId = 0): void
    {
        $query = AigcDigitalHumanVoice::where('tenant_id', $tenantId)
            ->where('source', 'mine')
            ->whereIn('status', ['running', 'submitting'])
            ->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $voices = $query->order('id', 'asc')->limit(3)->select();
        foreach ($voices as $voice) {
            if ((string)$voice['provider_asset_id'] !== '') {
                $voice->save(['status' => 'ready', 'update_time' => time()]);
                continue;
            }
            if ((string)$voice['status'] === 'submitting') {
                if ((int)$voice['update_time'] >= time() - self::VOICE_CLONE_SUBMIT_STALE_SECONDS) {
                    continue;
                }
                self::refundCloneBilling((int)$voice['tenant_id'], (int)$voice['user_id'], AigcDigitalHumanPricingService::TYPE_VOICE_CLONE, 0, (int)$voice['id'], '音色克隆提交结果未确认');
                $voice->save([
                    'status' => 'failed',
                    'update_time' => time(),
                ]);
                continue;
            }
            $affected = AigcDigitalHumanVoice::where('tenant_id', (int)$voice['tenant_id'])
                ->where('id', (int)$voice['id'])
                ->where('status', 'running')
                ->where('provider_asset_id', '')
                ->update([
                    'status' => 'submitting',
                    'update_time' => time(),
                ]);
            if ((int)$affected <= 0) {
                continue;
            }
            $claimedVoice = AigcDigitalHumanVoice::where('tenant_id', (int)$voice['tenant_id'])
                ->where('id', (int)$voice['id'])
                ->findOrEmpty();
            if (!$claimedVoice->isEmpty()) {
                $voice = $claimedVoice;
            }
            try {
                $providerAssetId = (new XhadminAigcDigitalHumanProvider())->cloneVoice([
                    'title' => (string)$voice['name'],
                    'audio_url' => self::fileUrlForTenant((string)$voice['audio_uri'], (int)$voice['tenant_id'], $voice->toArray()),
                    'visibility' => 'private',
                    'description' => '用户克隆音色',
                    'enhance_audio_quality' => false,
                ], (int)$voice['tenant_id'], (int)$voice['user_id']);
                $data = [
                    'provider_asset_id' => $providerAssetId,
                    'status' => 'ready',
                    'update_time' => time(),
                ];
                try {
                    $previewAudioUri = self::generateVoicePreviewAudio((int)$voice['tenant_id'], (int)$voice['user_id'], array_merge($voice->toArray(), $data));
                    $data['preview_audio_uri'] = $previewAudioUri;
                } catch (\Throwable $e) {
                    $data['preview_audio_uri'] = '';
                }
                $voice->save($data);
            } catch (\Throwable $e) {
                self::refundCloneBilling((int)$voice['tenant_id'], (int)$voice['user_id'], AigcDigitalHumanPricingService::TYPE_VOICE_CLONE, 0, (int)$voice['id'], $e->getMessage());
                $voice->save([
                    'status' => 'failed',
                    'update_time' => time(),
                ]);
            }
        }
    }

    private static function advanceRunningTaskSafely(
        AigcDigitalHumanTask $task,
        AigcDigitalHumanGenerateRequest $request,
        array $selection,
        array $estimate,
        AigcDigitalHumanProviderInterface $provider
    ): void {
        $lock = SubmitLockService::tryAcquire(self::taskAdvanceLockAction($task), (int)$task['tenant_id'], (int)$task['user_id']);
        if (!$lock) {
            return;
        }
        try {
            $latest = AigcDigitalHumanTask::where(['tenant_id' => (int)$task['tenant_id'], 'id' => (int)$task['id']])->findOrEmpty();
            if ($latest->isEmpty()) {
                return;
            }
            self::advanceRunningTask($latest, $request, $selection, $estimate, $provider);
        } finally {
            SubmitLockService::release($lock);
        }
    }

    private static function advanceRunningTask(
        AigcDigitalHumanTask $task,
        AigcDigitalHumanGenerateRequest $request,
        array $selection,
        array $estimate,
        AigcDigitalHumanProviderInterface $provider
    ): void {
        if (!$provider instanceof XhadminAigcDigitalHumanProvider || in_array((string)$task['status'], ['success', 'failed', 'canceled'], true)) {
            return;
        }
        $stage = (string)($task['provider_stage'] ?? '');
        if (in_array($stage, ['tts_submitting', 'lipsync_submitting'], true)) {
            if ((int)$task['update_time'] >= time() - self::PROVIDER_SUBMIT_STALE_SECONDS) {
                return;
            }
            self::failStaleProviderSubmit($task, $stage);
            return;
        }
        if ($stage === '' || $stage === 'created' || $stage === 'tts_submitted') {
            $lock = SubmitLockService::tryAcquire(self::providerSubmitLockAction($task, 'tts'), (int)$task['tenant_id'], (int)$task['user_id']);
            if (!$lock) {
                return;
            }
            try {
                $latest = AigcDigitalHumanTask::where(['tenant_id' => (int)$task['tenant_id'], 'id' => (int)$task['id']])->findOrEmpty();
                if ($latest->isEmpty()) {
                    return;
                }
                $task = $latest;
                $stage = (string)($task['provider_stage'] ?? '');
                if ((string)$task['status'] !== 'running' || !in_array($stage, ['', 'created', 'tts_submitted'], true) || trim((string)($task['tts_task_id'] ?? '')) !== '') {
                    return;
                }
            $claimed = self::claimTaskStage($task, $stage === 'tts_submitted' ? ['tts_submitted'] : ['', 'created'], 'tts_submitting');
            if (!$claimed) {
                return;
            }
            $task = $claimed;
            try {
                $request = self::requestWithProviderParams($request, self::providerTaskParams($task, 'tts'));
                $submit = $provider->submitTts($request);
                $task->save([
                    'provider_stage' => 'tts_running',
                    'tts_task_id' => (string)$submit['task_id'],
                    'provider_payload_json' => self::mergeProviderPayload($task, ['tts_submit' => $submit['payload'] ?? []]),
                    'progress' => 20,
                    'update_time' => time(),
                ]);
            } catch (\Throwable $e) {
                self::failTask($task, 'tts_failed', '音频合成失败：' . self::friendlyStageMessage($e->getMessage()));
            }
            } finally {
                SubmitLockService::release($lock);
            }
            return;
        }
        if ($stage === 'tts_running') {
            try {
                $tts = $provider->fetchTtsResult((string)$task['tts_task_id'], $request);
                $payload = ['tts_query' => $tts['payload'] ?? []];
                if ($tts['pending']) {
                    $task->save([
                        'provider_payload_json' => self::mergeProviderPayload($task, $payload),
                        'progress' => max(25, (int)$task['progress']),
                        'update_time' => time(),
                    ]);
                    return;
                }
                if (!$tts['success']) {
                    self::failTask($task, 'tts_failed', '音频合成失败：' . self::friendlyStageMessage((string)($tts['error'] ?? '供应商任务失败')));
                    return;
                }
                $audioUrl = (string)($tts['audio_url'] ?? '');
                if ($audioUrl === '') {
                    self::failTask($task, 'tts_failed', '音频合成失败：供应商未返回音频');
                    return;
                }
                $audio = AigcDigitalHumanAssetService::persistGeneratedAudio($audioUrl, (int)$task['tenant_id'], (int)$task['user_id']);
                $storedAudioUrl = !empty($audio['stored']) ? self::fileUrlForTenant((string)$audio['uri'], (int)$task['tenant_id']) : (string)($audio['uri'] ?? $audioUrl);
                $audioDuration = max((int)$task['duration'], (int)($audio['duration'] ?? 0));
                $task->save([
                    'provider_stage' => 'lipsync_submitted',
                    'tts_audio_uri' => (string)($audio['uri'] ?? $audioUrl),
                    'duration' => $audioDuration,
                    'provider_payload_json' => self::mergeProviderPayload($task, $payload),
                    'progress' => 45,
                    'update_time' => time(),
                ]);
                $request = self::requestWithAudioUrl($request, $storedAudioUrl);
            } catch (\Throwable $e) {
                self::failTask($task, 'tts_failed', '音频合成失败：' . self::friendlyStageMessage($e->getMessage()));
                return;
            }
            $stage = 'lipsync_submitted';
        }
        if ($stage === 'lipsync_submitted') {
            $lock = SubmitLockService::tryAcquire(self::providerSubmitLockAction($task, 'lipsync'), (int)$task['tenant_id'], (int)$task['user_id']);
            if (!$lock) {
                return;
            }
            try {
                $latest = AigcDigitalHumanTask::where(['tenant_id' => (int)$task['tenant_id'], 'id' => (int)$task['id']])->findOrEmpty();
                if ($latest->isEmpty()) {
                    return;
                }
                $task = $latest;
                if ((string)$task['status'] !== 'running' || (string)($task['provider_stage'] ?? '') !== 'lipsync_submitted' || trim((string)($task['provider_task_id'] ?? '')) !== '') {
                    return;
                }
            $claimed = self::claimTaskStage($task, ['lipsync_submitted', ''], 'lipsync_submitting');
            if (!$claimed) {
                return;
            }
            $task = $claimed;
            try {
                $request = self::requestWithProviderParams($request, self::providerTaskParams($task, 'lipsync'));
                $audioUrl = (string)($request->providerParams['audio_url'] ?? '');
                if ($audioUrl === '') {
                    $audioUrl = self::fileUrlForTenant((string)$task['tts_audio_uri'], (int)$task['tenant_id']);
                }
                $submit = $provider->submitLipsync($request, $audioUrl);
                $task->save([
                    'provider_stage' => 'lipsync_running',
                    'provider_task_id' => (string)$submit['task_id'],
                    'provider_payload_json' => self::mergeProviderPayload($task, ['lipsync_submit' => $submit['payload'] ?? []]),
                    'progress' => 60,
                    'update_time' => time(),
                ]);
            } catch (\Throwable $e) {
                self::failTask($task, 'lipsync_failed', '视频合成失败：' . self::friendlyStageMessage($e->getMessage()));
            }
            } finally {
                SubmitLockService::release($lock);
            }
            return;
        }
        if ($stage === 'lipsync_running') {
            try {
                $video = $provider->fetchLipsyncResult((string)$task['provider_task_id'], $request);
                $payload = ['lipsync_query' => $video['payload'] ?? []];
                if ($video['pending']) {
                    $task->save([
                        'provider_payload_json' => self::mergeProviderPayload($task, $payload),
                        'progress' => max(70, (int)$task['progress']),
                        'update_time' => time(),
                    ]);
                    return;
                }
                if (!$video['success']) {
                    self::failTask($task, 'lipsync_failed', '视频合成失败：' . self::friendlyStageMessage((string)($video['error'] ?? '供应商任务失败')));
                    return;
                }
                $videoUrl = (string)($video['video_url'] ?? '');
                if ($videoUrl === '') {
                    self::failTask($task, 'lipsync_failed', '视频合成失败：供应商未返回视频');
                    return;
                }
                $task->save(['provider_stage' => 'storing', 'progress' => 88, 'provider_payload_json' => self::mergeProviderPayload($task, $payload), 'update_time' => time()]);
                $stored = AigcDigitalHumanAssetService::persistGeneratedVideo($videoUrl, (int)$task['tenant_id'], (int)$task['user_id']);
                $duration = max((int)$task['duration'], (int)($stored['duration'] ?? 0), 1);
                $estimate = self::applyBillableDuration($estimate, $duration);
                self::finishTaskWithVideos($task, $request->avatar, $request->voice, $selection, $estimate, [array_merge($stored, [
                    'cover_uri' => (string)($request->avatar['cover_uri'] ?? ''),
                    'provider_task_id' => (string)$task['provider_task_id'],
                    'duration' => $duration,
                ])]);
            } catch (\Throwable $e) {
                self::failTask($task, 'failed', '视频转存失败：' . self::friendlyStageMessage($e->getMessage()));
            }
        }
    }

    private static function claimTaskStage(AigcDigitalHumanTask $task, array $fromStages, string $toStage): ?AigcDigitalHumanTask
    {
        $affected = AigcDigitalHumanTask::where('tenant_id', (int)$task['tenant_id'])
            ->where('id', (int)$task['id'])
            ->where('status', 'running')
            ->whereIn('provider_stage', $fromStages)
            ->update([
                'provider_stage' => $toStage,
                'update_time' => time(),
            ]);
        if ((int)$affected <= 0) {
            return null;
        }
        $latest = AigcDigitalHumanTask::where('tenant_id', (int)$task['tenant_id'])
            ->where('id', (int)$task['id'])
            ->findOrEmpty();
        return $latest->isEmpty() ? null : $latest;
    }

    private static function buildRequestFromData(array $task, array $avatar, array $voice, array $selection): AigcDigitalHumanGenerateRequest
    {
        $avatar = self::formatAvatar($avatar);
        $voice = !empty($voice) ? self::formatVoice($voice) : [
            'id' => 0,
            'name' => '音频驱动',
            'audio_uri' => '',
            'audio_url' => '',
            'provider_asset_id' => '',
        ];
        $avatar['media_url'] = self::fileUrlForTenant((string)($avatar['media_uri'] ?? ''), (int)$task['tenant_id'], $avatar);
        $voice['audio_url'] = !empty($voice['audio_uri']) ? self::fileUrlForTenant((string)($voice['audio_uri'] ?? ''), (int)$task['tenant_id'], $voice) : '';
        return new AigcDigitalHumanGenerateRequest(
            (string)($task['script_text'] ?? ''),
            (string)($task['prompt'] ?? ''),
            $selection['channel']['code'],
            $selection['spec']['quality'],
            $selection['spec']['ratio'],
            $avatar,
            $voice,
            $selection['spec'],
            $selection['spec']['provider_params_json'] ?? [],
            array_merge($selection['channel']['config_json'] ?? [], [
                'model' => $selection['channel']['model'],
                'tenant_id' => (int)$task['tenant_id'],
                'user_id' => (int)$task['user_id'],
            ])
        );
    }

    private static function requestWithAudioUrl(AigcDigitalHumanGenerateRequest $request, string $audioUrl): AigcDigitalHumanGenerateRequest
    {
        return new AigcDigitalHumanGenerateRequest(
            $request->scriptText,
            $request->prompt,
            $request->channel,
            $request->quality,
            $request->ratio,
            $request->avatar,
            $request->voice,
            $request->spec,
            array_merge($request->providerParams, ['audio_url' => $audioUrl]),
            $request->channelConfig
        );
    }

    private static function requestWithProviderParams(AigcDigitalHumanGenerateRequest $request, array $providerParams): AigcDigitalHumanGenerateRequest
    {
        return new AigcDigitalHumanGenerateRequest(
            $request->scriptText,
            $request->prompt,
            $request->channel,
            $request->quality,
            $request->ratio,
            $request->avatar,
            $request->voice,
            $request->spec,
            array_merge($request->providerParams, $providerParams),
            $request->channelConfig
        );
    }

    private static function providerClientTaskId(AigcDigitalHumanTask $task, string $stage): string
    {
        return implode(':', [
            self::APP_CODE,
            (int)$task['tenant_id'],
            (int)$task['user_id'],
            (int)$task['id'],
            $stage,
        ]);
    }

    private static function providerTaskParams(AigcDigitalHumanTask $task, string $stage): array
    {
        $clientTaskId = self::providerClientTaskId($task, $stage);
        return [
            'client_task_id' => $clientTaskId,
            'idempotency_key' => $clientTaskId,
            'local_task_id' => (string)$task['id'],
            'local_task_sn' => $clientTaskId,
        ];
    }

    private static function providerSubmitLockAction(AigcDigitalHumanTask $task, string $stage): string
    {
        return implode('|', [
            'provider_submit',
            self::providerClientTaskId($task, $stage),
        ]);
    }

    private static function taskAdvanceLockAction(AigcDigitalHumanTask $task): string
    {
        return implode('|', [
            'task_advance',
            self::APP_CODE,
            (int)$task['tenant_id'],
            (int)$task['user_id'],
            (int)$task['id'],
        ]);
    }

    private static function mergeProviderPayload(AigcDigitalHumanTask $task, array $payload): array
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
        foreach (['tts_submit', 'tts_query', 'lipsync_submit', 'lipsync_query'] as $key) {
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

    private static function failTask(AigcDigitalHumanTask $task, string $stage, string $message): void
    {
        $refundError = self::refundTaskBillings($task, $message);
        $error = trim($message . ($refundError !== '' ? ' ' . $refundError : ''));
        $task->save([
            'provider_stage' => $stage,
            'status' => 'failed',
            'progress' => 100,
            'error' => $error,
            'finish_time' => time(),
            'update_time' => time(),
        ]);
    }

    private static function failStaleProviderSubmit(AigcDigitalHumanTask $task, string $stage): void
    {
        $failedStage = $stage === 'tts_submitting' ? 'tts_failed' : 'lipsync_failed';
        $prefix = $stage === 'tts_submitting' ? '音频合成失败：' : '视频合成失败：';
        self::failTask($task, $failedStage, $prefix . '上游提交结果未确认，系统已停止自动重试以避免重复扣费，请重新提交任务。如上游已产生扣费请联系平台核对。');
    }

    private static function refundTaskBillings(AigcDigitalHumanTask $task, string $reason = ''): string
    {
        $tenantId = (int)$task['tenant_id'];
        $userId = (int)$task['user_id'];
        $taskId = (int)$task['id'];
        if ($tenantId <= 0 || $userId <= 0 || $taskId <= 0) {
            return '';
        }
        $refundErrors = [];
        $billings = AigcDigitalHumanBilling::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => $taskId,
            'billing_status' => 'deducted',
        ])->select();
        foreach ($billings as $billing) {
            Db::startTrans();
            try {
                $locked = AigcDigitalHumanBilling::where([
                    'tenant_id' => $tenantId,
                    'id' => (int)$billing['id'],
                    'billing_status' => 'deducted',
                ])->lock(true)->findOrEmpty();
                if ($locked->isEmpty()) {
                    Db::commit();
                    continue;
                }
                $sourceSn = (string)($locked['user_point_sn'] ?: $locked['tenant_point_sn'] ?: (self::APP_CODE . '-' . $taskId . '-' . (int)$locked['id']));
                $refundSn = $sourceSn . '-refund';
                PointService::refundBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$locked['tenant_cost_points'], (float)$locked['user_charge_points'], $refundSn, '数字人合成失败退回', [
                    'app_code' => self::APP_CODE,
                    'task_id' => $taskId,
                    'billing_id' => (int)$locked['id'],
                    'origin_source_sn' => $sourceSn,
                    'reason' => $reason,
                ]);
                $locked->save([
                    'billing_status' => 'refunded',
                    'update_time' => time(),
                ]);
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                $refundErrors[] = '退款失败：' . $e->getMessage();
            }
        }
        return implode(' ', array_unique($refundErrors));
    }

    private static function stageErrorPrefix(string $stage): string
    {
        return match ($stage) {
            'created', 'tts_submitted', 'tts_running', 'tts_failed' => '音频合成失败：',
            'lipsync_submitted', 'lipsync_running', 'lipsync_failed' => '视频合成失败：',
            'storing' => '视频转存失败：',
            default => '',
        };
    }

    private static function friendlyStageMessage(string $message): string
    {
        $message = trim($message);
        return $message === '' ? '供应商任务失败' : $message;
    }

    private static function normalizeAssetText(string $value, string $fallback = '', int $maxLength = 80): string
    {
        $value = trim($value);
        if ($value !== '' && function_exists('mb_convert_encoding')) {
            $value = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $value) ?? $value;
        $value = trim($value);
        if ($value === '') {
            $value = $fallback;
        }
        if ($maxLength > 0 && function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }
        return $maxLength > 0 ? substr($value, 0, $maxLength) : $value;
    }

    private static function normalizeAssetUri(string $value, int $maxLength = 500): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^(blob:|data:)/i', $value)) {
            return '';
        }
        $value = FileService::setFileUrl($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', trim($value)) ?? '';
        if ($value === '' || preg_match('/^(blob:|data:)/i', $value)) {
            return '';
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($maxLength > 0 && $length > $maxLength) {
            throw new Exception('素材地址过长，请重新上传后再保存');
        }
        return $value;
    }

    private static function isXhadminProvider(string $provider): bool
    {
        return in_array(strtolower(trim($provider)), ['xhadmin', 'xhadmin_aigc', 'xiaohe'], true);
    }

    private static function assertUsableProviderAssets(array $avatar, array $voice): void
    {
        self::assertUsableProviderAvatar($avatar);
        if ((string)($voice['provider_asset_id'] ?? '') === '') {
            throw new Exception('当前音色未完成克隆，无法合成');
        }
    }

    private static function assertUsableProviderAvatar(array $avatar): void
    {
        if ((string)($avatar['media_uri'] ?? '') === '' || (string)($avatar['media_type'] ?? '') !== 'video') {
            throw new Exception('请选择可合成的视频形象');
        }
    }

    private static function estimateAudioDuration(string $text): int
    {
        return max(1, (int)ceil(mb_strlen($text) / 4));
    }

    private static function applyBillableDuration(array $estimate, int $duration): array
    {
        $duration = max(1, $duration);
        if (($estimate['billing_unit'] ?? 'second') !== 'second') {
            return $estimate;
        }
        $estimate['duration'] = $duration;
        $estimate['billable_quantity'] = $duration;
        $estimate['tenant_cost_points'] = number_format((float)$estimate['platform_unit_cost'] * $duration, 2, '.', '');
        $estimate['user_charge_points'] = number_format((float)$estimate['tenant_unit_price'] * $duration, 2, '.', '');
        return $estimate;
    }

    private static function validateVoiceCloneDuration(string $audioUri, array $params, int $tenantId = 0): int
    {
        $detectedDuration = $audioUri !== '' ? self::detectAudioDurationFromUri($audioUri, $tenantId) : 0;
        $duration = $detectedDuration > 0 ? $detectedDuration : max(0, (int)($params['duration'] ?? 0));
        if ($duration <= 0 && $audioUri !== '') {
            throw new Exception('音频时长校验失败，请重新上传 mp3、wav、m4a、aac、ogg、webm 音频');
        }
        if ($duration > self::VOICE_CLONE_MAX_DURATION) {
            throw new Exception('克隆音频不能超过10秒，请重新上传');
        }
        return $duration;
    }

    private static function detectAudioDurationFromUri(string $audioUri, int $tenantId = 0): int
    {
        if ($audioUri === '') {
            return 0;
        }
        if (str_starts_with($audioUri, 'http://') || str_starts_with($audioUri, 'https://')) {
            return self::detectRemoteAudioDuration($audioUri);
        }
        foreach (self::candidateLocalAudioPaths($audioUri) as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }
            $duration = self::detectAudioDurationNative($filePath);
            if ($duration > 0) {
                return $duration;
            }
            $duration = self::detectAudioDurationByFfprobe($filePath);
            if ($duration > 0) {
                return $duration;
            }
        }
        $storedUrl = self::storedAudioUrl($audioUri, $tenantId);
        return $storedUrl !== '' ? self::detectRemoteAudioDuration($storedUrl) : 0;
    }

    private static function detectAudioDurationNative(string $filePath): int
    {
        $data = @file_get_contents($filePath);
        if ($data === false || $data === '') {
            return 0;
        }
        return self::detectAudioDurationFromBinary($data);
    }

    private static function detectRemoteAudioDuration(string $url): int
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 20,
                'follow_location' => 1,
                'ignore_errors' => true,
                'header' => "User-Agent: LikeAdminAigcDurationProbe/1.0\r\n",
            ],
        ]);
        $data = @file_get_contents($url, false, $context);
        if ($data === false || $data === '') {
            return 0;
        }
        return self::detectAudioDurationFromBinary($data);
    }

    private static function detectAudioDurationFromBinary(string $data): int
    {
        foreach ([
            self::detectWavDuration($data),
            self::detectMp4Duration($data),
            self::detectOggDuration($data),
            self::detectWebmDuration($data),
            self::detectFlacDuration($data),
            self::detectAdtsDuration($data),
            self::detectMp3Duration($data),
        ] as $duration) {
            if ($duration > 0) {
                return $duration;
            }
        }
        return 0;
    }

    private static function candidateLocalAudioPaths(string $audioUri): array
    {
        $path = ltrim($audioUri, '/');
        $rootPath = self::rootPath();
        return array_values(array_unique([
            self::publicPath() . $path,
            $rootPath . 'public/' . $path,
            $rootPath . $path,
        ]));
    }

    private static function storedAudioUrl(string $audioUri, int $tenantId): string
    {
        $uri = ltrim($audioUri, '/');
        if ($uri === '') {
            return '';
        }
        $tenantFile = TenantFile::where(['tenant_id' => $tenantId, 'uri' => $uri])->findOrEmpty();
        if (!$tenantFile->isEmpty()) {
            $row = $tenantFile->toArray();
            return FileService::getFileUrlByStorage($uri, (string)($row['storage_scope'] ?? ''), (string)($row['storage_engine'] ?? ''), (string)($row['storage_domain'] ?? ''));
        }
        $file = UploadFile::where(['uri' => $uri])->findOrEmpty();
        if (!$file->isEmpty()) {
            $row = $file->toArray();
            return FileService::getFileUrlByStorage($uri, (string)($row['storage_scope'] ?? ''), (string)($row['storage_engine'] ?? ''), (string)($row['storage_domain'] ?? ''));
        }
        return '';
    }

    private static function voicePreviewAudioUrl(string $audioUri, int $tenantId, array $voice = []): string
    {
        $storedUrl = self::storedAudioUrl($audioUri, $tenantId);
        return $storedUrl !== '' ? $storedUrl : self::fileUrlForTenant($audioUri, $tenantId, $voice);
    }

    private static function detectAudioDurationByFfprobe(string $filePath): int
    {
        if (!function_exists('exec')) {
            return 0;
        }
        $ffprobe = self::findExecutable(['ffprobe', '/usr/bin/ffprobe', '/usr/local/bin/ffprobe', '/opt/homebrew/bin/ffprobe']);
        if ($ffprobe === '') {
            return 0;
        }
        $output = [];
        $code = 1;
        @exec(escapeshellarg($ffprobe) . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($filePath), $output, $code);
        if ($code !== 0 || empty($output)) {
            return 0;
        }
        $duration = (float)trim((string)$output[0]);
        return $duration > 0 ? (int)ceil($duration) : 0;
    }

    private static function detectWavDuration(string $data): int
    {
        if (strlen($data) < 44 || substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WAVE') {
            return 0;
        }
        $offset = 12;
        $byteRate = 0;
        $dataSize = 0;
        $length = strlen($data);
        while ($offset + 8 <= $length) {
            $chunkId = substr($data, $offset, 4);
            $chunkSize = self::u32le($data, $offset + 4);
            $chunkOffset = $offset + 8;
            if ($chunkId === 'fmt ' && $chunkOffset + 16 <= $length) {
                $byteRate = self::u32le($data, $chunkOffset + 8);
            } elseif ($chunkId === 'data') {
                $dataSize = $chunkSize;
            }
            if ($byteRate > 0 && $dataSize > 0) {
                return (int)ceil($dataSize / $byteRate);
            }
            $offset += 8 + $chunkSize + ($chunkSize % 2);
        }
        return 0;
    }

    private static function detectMp3Duration(string $data): int
    {
        $length = strlen($data);
        if ($length < 4) {
            return 0;
        }
        $offset = 0;
        if (substr($data, 0, 3) === 'ID3' && $length >= 10) {
            $offset = 10 + self::synchsafeInt(substr($data, 6, 4));
            if ((ord($data[5]) & 0x10) !== 0) {
                $offset += 10;
            }
        }
        $scanLimit = min($length - 4, $offset + 65536);
        for ($i = $offset; $i <= $scanLimit; $i++) {
            $header = self::u32be($data, $i);
            if (($header & 0xFFE00000) !== 0xFFE00000) {
                continue;
            }
            $versionBits = ($header >> 19) & 0x03;
            $layerBits = ($header >> 17) & 0x03;
            $bitrateIndex = ($header >> 12) & 0x0F;
            $sampleRateIndex = ($header >> 10) & 0x03;
            if ($versionBits === 1 || $layerBits === 0 || $bitrateIndex === 0 || $bitrateIndex === 15 || $sampleRateIndex === 3) {
                continue;
            }
            $bitrate = self::mp3Bitrate($versionBits, $layerBits, $bitrateIndex);
            if ($bitrate <= 0) {
                continue;
            }
            $audioBytes = $length - $i;
            if ($length >= 128 && substr($data, -128, 3) === 'TAG') {
                $audioBytes -= 128;
            }
            return $audioBytes > 0 ? (int)ceil(($audioBytes * 8) / ($bitrate * 1000)) : 0;
        }
        return 0;
    }

    private static function detectAdtsDuration(string $data): int
    {
        $sampleRates = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];
        $length = strlen($data);
        $offset = 0;
        $frames = 0;
        $sampleRate = 0;
        while ($offset + 7 <= $length) {
            $b0 = ord($data[$offset]);
            $b1 = ord($data[$offset + 1]);
            if ($b0 !== 0xFF || ($b1 & 0xF0) !== 0xF0) {
                $offset++;
                continue;
            }
            $sampleRateIndex = (ord($data[$offset + 2]) >> 2) & 0x0F;
            if (!isset($sampleRates[$sampleRateIndex])) {
                break;
            }
            $frameLength = ((ord($data[$offset + 3]) & 0x03) << 11)
                | (ord($data[$offset + 4]) << 3)
                | ((ord($data[$offset + 5]) & 0xE0) >> 5);
            if ($frameLength <= 7) {
                break;
            }
            $sampleRate = $sampleRates[$sampleRateIndex];
            $frames++;
            $offset += $frameLength;
        }
        return ($frames > 0 && $sampleRate > 0) ? (int)ceil(($frames * 1024) / $sampleRate) : 0;
    }

    private static function detectMp4Duration(string $data): int
    {
        if (strpos(substr($data, 0, 64), 'ftyp') === false && strpos($data, 'mdhd') === false) {
            return 0;
        }
        return self::scanMp4AtomsDuration($data, 0, strlen($data), 0);
    }

    private static function scanMp4AtomsDuration(string $data, int $start, int $end, int $depth): int
    {
        if ($depth > 8) {
            return 0;
        }
        $offset = $start;
        while ($offset + 8 <= $end) {
            $size = self::u32be($data, $offset);
            $type = substr($data, $offset + 4, 4);
            $headerSize = 8;
            if ($size === 1 && $offset + 16 <= $end) {
                $size = self::u64be($data, $offset + 8);
                $headerSize = 16;
            } elseif ($size === 0) {
                $size = $end - $offset;
            }
            if ($size < $headerSize || $offset + $size > $end) {
                break;
            }
            if ($type === 'mdhd') {
                $version = ord($data[$offset + $headerSize] ?? "\0");
                $timeScaleOffset = $offset + $headerSize + ($version === 1 ? 20 : 12);
                $durationOffset = $timeScaleOffset + 4;
                if ($durationOffset + ($version === 1 ? 8 : 4) <= $offset + $size) {
                    $timeScale = self::u32be($data, $timeScaleOffset);
                    $duration = $version === 1 ? self::u64be($data, $durationOffset) : self::u32be($data, $durationOffset);
                    return ($timeScale > 0 && $duration > 0) ? (int)ceil($duration / $timeScale) : 0;
                }
            }
            if (in_array($type, ['moov', 'trak', 'mdia', 'minf', 'stbl', 'edts'], true)) {
                $duration = self::scanMp4AtomsDuration($data, $offset + $headerSize, $offset + $size, $depth + 1);
                if ($duration > 0) {
                    return $duration;
                }
            }
            $offset += $size;
        }
        return 0;
    }

    private static function detectOggDuration(string $data): int
    {
        if (substr($data, 0, 4) !== 'OggS') {
            return 0;
        }
        $sampleRate = 0;
        $opusPos = strpos($data, 'OpusHead');
        if ($opusPos !== false) {
            $sampleRate = 48000;
        }
        $vorbisPos = strpos($data, "\x01vorbis");
        if ($sampleRate <= 0 && $vorbisPos !== false && $vorbisPos + 16 <= strlen($data)) {
            $sampleRate = self::u32le($data, $vorbisPos + 12);
        }
        if ($sampleRate <= 0) {
            return 0;
        }
        $offset = 0;
        $granule = 0;
        $length = strlen($data);
        while (($pos = strpos($data, 'OggS', $offset)) !== false && $pos + 27 <= $length) {
            $granule = max($granule, self::u64le($data, $pos + 6));
            $segments = ord($data[$pos + 26]);
            $segmentTableEnd = $pos + 27 + $segments;
            if ($segmentTableEnd > $length) {
                break;
            }
            $pageSize = 27 + $segments;
            for ($i = 0; $i < $segments; $i++) {
                $pageSize += ord($data[$pos + 27 + $i]);
            }
            $offset = $pos + max($pageSize, 27);
        }
        return $granule > 0 ? (int)ceil($granule / $sampleRate) : 0;
    }

    private static function detectWebmDuration(string $data): int
    {
        if (strpos(substr($data, 0, 64), "\x1A\x45\xDF\xA3") === false && strpos($data, "\x44\x89") === false) {
            return 0;
        }
        $scale = 1000000;
        $scalePos = strpos($data, "\x2A\xD7\xB1");
        if ($scalePos !== false) {
            $scaleSize = self::readEbmlVint($data, $scalePos + 3);
            if ($scaleSize !== null) {
                $scale = self::readEbmlUint($data, $scaleSize[2], min($scaleSize[0], 8)) ?: $scale;
            }
        }
        $durationPos = strpos($data, "\x44\x89");
        if ($durationPos === false) {
            return 0;
        }
        $durationSize = self::readEbmlVint($data, $durationPos + 2);
        if ($durationSize === null) {
            return 0;
        }
        $durationOffset = $durationSize[2];
        $duration = match ($durationSize[0]) {
            4 => (float)(unpack('G', substr($data, $durationOffset, 4))[1] ?? 0),
            8 => (float)(unpack('E', substr($data, $durationOffset, 8))[1] ?? 0),
            default => 0.0,
        };
        return $duration > 0 ? (int)ceil(($duration * $scale) / 1000000000) : 0;
    }

    private static function detectFlacDuration(string $data): int
    {
        if (substr($data, 0, 4) !== 'fLaC' || strlen($data) < 42) {
            return 0;
        }
        $offset = 4;
        while ($offset + 4 <= strlen($data)) {
            $header = ord($data[$offset]);
            $type = $header & 0x7F;
            $size = (ord($data[$offset + 1]) << 16) | (ord($data[$offset + 2]) << 8) | ord($data[$offset + 3]);
            $block = $offset + 4;
            if ($type === 0 && $size >= 34 && $block + 34 <= strlen($data)) {
                $sampleRate = (ord($data[$block + 10]) << 12) | (ord($data[$block + 11]) << 4) | ((ord($data[$block + 12]) & 0xF0) >> 4);
                $totalSamples = ((ord($data[$block + 13]) & 0x0F) * 4294967296)
                    + (ord($data[$block + 14]) << 24)
                    + (ord($data[$block + 15]) << 16)
                    + (ord($data[$block + 16]) << 8)
                    + ord($data[$block + 17]);
                return ($sampleRate > 0 && $totalSamples > 0) ? (int)ceil($totalSamples / $sampleRate) : 0;
            }
            if (($header & 0x80) !== 0) {
                break;
            }
            $offset += 4 + $size;
        }
        return 0;
    }

    private static function mp3Bitrate(int $versionBits, int $layerBits, int $index): int
    {
        $isMpeg1 = $versionBits === 3;
        if ($isMpeg1) {
            $tables = [
                3 => [0, 32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448],
                2 => [0, 32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384],
                1 => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320],
            ];
        } else {
            $tables = [
                3 => [0, 32, 48, 56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 224, 256],
                2 => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
                1 => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],
            ];
        }
        return (int)($tables[$layerBits][$index] ?? 0);
    }

    private static function readEbmlVint(string $data, int $offset): ?array
    {
        if ($offset >= strlen($data)) {
            return null;
        }
        $first = ord($data[$offset]);
        $mask = 0x80;
        $length = 1;
        while ($length <= 8 && ($first & $mask) === 0) {
            $mask >>= 1;
            $length++;
        }
        if ($length > 8 || $offset + $length > strlen($data)) {
            return null;
        }
        $value = $first & ($mask - 1);
        for ($i = 1; $i < $length; $i++) {
            $value = ($value << 8) | ord($data[$offset + $i]);
        }
        return [$value, $length, $offset + $length];
    }

    private static function readEbmlUint(string $data, int $offset, int $size): int
    {
        if ($size <= 0 || $offset + $size > strlen($data)) {
            return 0;
        }
        $value = 0;
        for ($i = 0; $i < $size; $i++) {
            $value = ($value << 8) | ord($data[$offset + $i]);
        }
        return $value;
    }

    private static function synchsafeInt(string $bytes): int
    {
        if (strlen($bytes) < 4) {
            return 0;
        }
        return ((ord($bytes[0]) & 0x7F) << 21)
            | ((ord($bytes[1]) & 0x7F) << 14)
            | ((ord($bytes[2]) & 0x7F) << 7)
            | (ord($bytes[3]) & 0x7F);
    }

    private static function u32be(string $data, int $offset): int
    {
        if ($offset + 4 > strlen($data)) {
            return 0;
        }
        return (int)(unpack('N', substr($data, $offset, 4))[1] ?? 0);
    }

    private static function u32le(string $data, int $offset): int
    {
        if ($offset + 4 > strlen($data)) {
            return 0;
        }
        return (int)(unpack('V', substr($data, $offset, 4))[1] ?? 0);
    }

    private static function u64be(string $data, int $offset): int
    {
        if ($offset + 8 > strlen($data)) {
            return 0;
        }
        $high = self::u32be($data, $offset);
        $low = self::u32be($data, $offset + 4);
        return (int)($high * 4294967296 + $low);
    }

    private static function u64le(string $data, int $offset): int
    {
        if ($offset + 8 > strlen($data)) {
            return 0;
        }
        $low = self::u32le($data, $offset);
        $high = self::u32le($data, $offset + 4);
        return (int)($high * 4294967296 + $low);
    }

    private static function findExecutable(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $candidate = (string)$candidate;
            if ($candidate === '') {
                continue;
            }
            if (str_contains($candidate, '/') && is_executable($candidate)) {
                return $candidate;
            }
            if (!str_contains($candidate, '/')) {
                $output = [];
                $code = 1;
                @exec('command -v ' . escapeshellarg($candidate), $output, $code);
                if ($code === 0 && !empty($output[0]) && is_executable((string)$output[0])) {
                    return (string)$output[0];
                }
            }
        }
        return '';
    }

    private static function publicPath(): string
    {
        if (function_exists('public_path')) {
            return public_path();
        }
        return rtrim(dirname(__DIR__, 5), '/\\') . '/public/';
    }

    private static function rootPath(): string
    {
        if (function_exists('root_path')) {
            return root_path();
        }
        return rtrim(dirname(__DIR__, 5), '/\\') . '/';
    }

    private static function fileUrlForTenant(string $uri, int $tenantId, array $storage = []): string
    {
        if ($uri === '' || str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
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
        $default = StorageConfigService::getEffectiveDefault($tenantId);
        if ($default === 'local') {
            return FileService::format(self::localStorageDomain(), $uri);
        }
        $config = StorageConfigService::getEffectiveConfig($tenantId);
        $storage = $config['engine'][$default] ?? [];
        return FileService::format((string)($storage['domain'] ?? ''), $uri);
    }

    private static function storageDomainForTenant(int $tenantId): string
    {
        if (StorageConfigService::getEffectiveDefault($tenantId) === 'local') {
            return self::localStorageDomain();
        }
        return StorageConfigService::getEffectiveDomain($tenantId);
    }

    private static function localStorageDomain(): string
    {
        $domain = trim((string)request()->domain());
        if ($domain !== '' && !in_array($domain, ['http://', 'https://'], true)) {
            return $domain;
        }
        $host = trim((string)config('project.http_host'));
        if ($host === '') {
            return '';
        }
        return preg_match('/^https?:\/\//i', $host) ? $host : 'http://' . $host;
    }

    private static function consumeCloneBilling(int $tenantId, int $userId, string $type, array $estimate, int $avatarId = 0, int $voiceId = 0): void
    {
        $sourceSn = $type . '-' . $userId . '-' . ($avatarId ?: $voiceId) . '-' . time();
        $remark = $type === AigcDigitalHumanPricingService::TYPE_AVATAR_CLONE ? '数字人形象克隆消费' : '数字人音色克隆消费';
        PointService::consumeBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points'], $sourceSn, $remark, [
            'app_code' => self::APP_CODE,
            'billing_type' => $type,
            'avatar_id' => $avatarId,
            'voice_id' => $voiceId,
        ]);
        AigcDigitalHumanBilling::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => 0,
            'result_id' => 0,
            'channel' => '',
            'quality' => '',
            'ratio' => '',
            'billing_type' => $type,
            'billing_unit' => 'count',
            'quantity' => 1,
            'platform_unit_cost' => $estimate['platform_unit_cost'],
            'tenant_unit_price' => $estimate['tenant_unit_price'],
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
            'billing_status' => 'deducted',
            'tenant_point_sn' => $sourceSn,
            'user_point_sn' => $sourceSn,
            'extra_json' => [
                'avatar_id' => $avatarId,
                'voice_id' => $voiceId,
            ],
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private static function refundCloneBilling(int $tenantId, int $userId, string $type, int $avatarId = 0, int $voiceId = 0, string $reason = ''): string
    {
        if ($tenantId <= 0 || $userId <= 0 || ($avatarId <= 0 && $voiceId <= 0)) {
            return '';
        }
        $refundErrors = [];
        $billings = AigcDigitalHumanBilling::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => 0,
            'result_id' => 0,
            'billing_type' => $type,
            'billing_status' => 'deducted',
        ])->select();
        foreach ($billings as $billing) {
            $extra = is_array($billing['extra_json'] ?? null) ? $billing['extra_json'] : [];
            if ((int)($extra['avatar_id'] ?? 0) !== $avatarId || (int)($extra['voice_id'] ?? 0) !== $voiceId) {
                continue;
            }
            Db::startTrans();
            try {
                $locked = AigcDigitalHumanBilling::where([
                    'tenant_id' => $tenantId,
                    'id' => (int)$billing['id'],
                    'billing_status' => 'deducted',
                ])->lock(true)->findOrEmpty();
                if ($locked->isEmpty()) {
                    Db::commit();
                    continue;
                }
                $lockedExtra = is_array($locked['extra_json'] ?? null) ? $locked['extra_json'] : [];
                if ((int)($lockedExtra['avatar_id'] ?? 0) !== $avatarId || (int)($lockedExtra['voice_id'] ?? 0) !== $voiceId) {
                    Db::commit();
                    continue;
                }
                $sourceSn = (string)($locked['user_point_sn'] ?: $locked['tenant_point_sn'] ?: ($type . '-' . $userId . '-' . ($avatarId ?: $voiceId) . '-' . (int)$locked['id']));
                $remark = $type === AigcDigitalHumanPricingService::TYPE_AVATAR_CLONE ? '数字人形象克隆失败退回' : '数字人音色克隆失败退回';
                PointService::refundBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$locked['tenant_cost_points'], (float)$locked['user_charge_points'], $sourceSn . '-refund', $remark, [
                    'app_code' => self::APP_CODE,
                    'billing_type' => $type,
                    'billing_id' => (int)$locked['id'],
                    'avatar_id' => $avatarId,
                    'voice_id' => $voiceId,
                    'origin_source_sn' => $sourceSn,
                    'reason' => $reason,
                ]);
                $locked->save([
                    'billing_status' => 'refunded',
                    'update_time' => time(),
                ]);
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                $refundErrors[] = '退款失败：' . $e->getMessage();
            }
        }
        return implode(' ', array_unique($refundErrors));
    }

    private static function finishTaskWithVideos(AigcDigitalHumanTask $task, array $avatar, array $voice, array $selection, array $estimate, array $videos): array
    {
        $rows = [];
        Db::startTrans();
        try {
            $tenantId = (int)$task['tenant_id'];
            $userId = (int)$task['user_id'];
            $task = AigcDigitalHumanTask::where('tenant_id', $tenantId)
                ->where('id', (int)$task['id'])
                ->lock(true)
                ->findOrEmpty();
            if ($task->isEmpty()) {
                throw new Exception('任务不存在');
            }
            $existing = self::taskResults($tenantId, $userId, (int)$task['id']);
            if (!empty($existing)) {
                $task->save(['status' => 'success', 'provider_stage' => 'success', 'progress' => 100, 'finish_time' => $task['finish_time'] ?: time(), 'update_time' => time()]);
                Db::commit();
                return $existing;
            }
            $storage = StorageConfigService::getEffectiveConfig($tenantId);
            foreach ($videos as $index => $video) {
                $row = AigcDigitalHumanResult::create([
                    'tenant_id' => $tenantId,
                    'task_id' => (int)$task['id'],
                    'user_id' => $userId,
                    'avatar_id' => (int)$avatar['id'],
                    'voice_id' => (int)($voice['id'] ?? 0),
                    'title' => (string)$task['title'],
                    'cover_uri' => (string)($video['cover_uri'] ?? $avatar['cover_uri'] ?? ''),
                    'video_uri' => (string)$video['uri'],
                    'storage_scope' => (string)($video['storage_scope'] ?? $storage['scope']),
                    'storage_engine' => (string)($video['storage_engine'] ?? $storage['default']),
                    'storage_domain' => (string)($video['storage_domain'] ?? self::storageDomainForTenant($tenantId)),
                    'width' => $video['width'] ?? 0,
                    'height' => $video['height'] ?? 0,
                    'duration' => $video['duration'] ?? 0,
                    'tenant_cost_points' => $estimate['tenant_cost_points'],
                    'user_charge_points' => $estimate['user_charge_points'],
                    'provider_task_id' => $video['provider_task_id'] ?? $task['provider_task_id'],
                    'delete_time' => 0,
                    'create_time' => time(),
                ]);
                $sourceSn = self::APP_CODE . '-' . (string)$task['id'] . '-' . ((int)$index + 1);
                PointService::consumeBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points'], $sourceSn, '数字人合成消费', [
                    'app_code' => self::APP_CODE,
                    'task_id' => (int)$task['id'],
                    'result_id' => (int)$row['id'],
                ]);
                AigcDigitalHumanBilling::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'task_id' => (int)$task['id'],
                    'result_id' => (int)$row['id'],
                    'channel' => $selection['channel']['code'],
                    'quality' => $selection['spec']['quality'],
                    'ratio' => $selection['spec']['ratio'],
                    'billing_type' => 'generate',
                    'billing_unit' => $estimate['billing_unit'] ?? 'second',
                    'quantity' => (int)($estimate['billable_quantity'] ?? 1),
                    'platform_unit_cost' => $estimate['platform_unit_cost'],
                    'tenant_unit_price' => $estimate['tenant_unit_price'],
                    'tenant_cost_points' => $estimate['tenant_cost_points'],
                    'user_charge_points' => $estimate['user_charge_points'],
                    'billing_status' => 'deducted',
                    'tenant_point_sn' => $sourceSn,
                    'user_point_sn' => $sourceSn,
                    'extra_json' => [
                        'duration' => (int)($video['duration'] ?? $estimate['duration'] ?? 0),
                        'unit' => $estimate['billing_unit'] ?? 'second',
                    ],
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
                $rows[] = self::formatResult($row->toArray());
            }
            self::consumeQuota($tenantId, $userId, count($rows));
            $task->save([
                'status' => 'success',
                'provider_stage' => 'success',
                'progress' => 100,
                'duration' => (int)($rows[0]['duration'] ?? 0),
                'tenant_cost_points' => number_format((float)$estimate['tenant_cost_points'] * count($rows), 2, '.', ''),
                'user_charge_points' => number_format((float)$estimate['user_charge_points'] * count($rows), 2, '.', ''),
                'provider_task_id' => (string)($videos[0]['provider_task_id'] ?? $task['provider_task_id']),
                'finish_time' => time(),
                'update_time' => time(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $task->save(['status' => 'failed', 'progress' => 100, 'error' => $e->getMessage(), 'finish_time' => time(), 'update_time' => time()]);
            throw $e;
        }
        return $rows;
    }

    private static function checkSensitiveWords(int $tenantId, string $text): void
    {
        $words = AigcDigitalHumanSensitiveWord::where(['tenant_id' => $tenantId, 'status' => 1])->column('word');
        foreach ($words as $word) {
            if ($word !== '' && str_contains($text, $word)) {
                throw new Exception('文案包含敏感词');
            }
        }
    }

    private static function scriptAssistPrompt(string $action, string $targetLanguage = '', int $tenantId = 0): string
    {
        $maxLength = (int)self::baseConfig($tenantId)['script_max_length'];
        $limitText = $maxLength > 0 ? ('输出不超过' . $maxLength . '个字符。') : '输出长度以口播自然完整为准。';
        if ($action === 'translate') {
            $targetLanguage = $targetLanguage !== '' ? $targetLanguage : '简体中文';
            return implode("\n", [
                '你是数字人口播脚本翻译助手。',
                '任务：将用户输入翻译成自然、适合数字人口播的' . $targetLanguage . '。',
                '要求：',
                '1. 严格使用目标语言：' . $targetLanguage . '。',
                '2. 保留原意、产品名、数字、专有名词和关键信息。',
                '3. 只输出最终口播文案，不要标题、解释、引号、项目符号。',
                '4. ' . $limitText,
            ]);
        }

        return implode("\n", [
            '你是数字人口播短视频文案专家。',
            '任务：把用户输入润色成适合数字人直接朗读的短口播文案。',
            '要求：',
            '1. 语气自然、有亲和力，适合短视频或产品介绍场景。',
            '2. 句子要口语化、节奏清晰，避免书面腔和夸张营销词。',
            '3. 不编造用户未提供的事实，不添加价格、承诺或敏感表述。',
            '4. 只输出最终口播文案，不要标题、解释、引号、项目符号。',
            '5. ' . $limitText,
        ]);
    }

    private static function normalizeAssistedScript(string $text, int $tenantId = 0): string
    {
        $text = trim($text);
        $text = preg_replace('/^["“”\'`]+|["“”\'`]+$/u', '', $text) ?? $text;
        $text = preg_replace('/^\s*(?:标题|文案|输出|翻译|润色结果)\s*[:：]\s*/u', '', $text) ?? $text;
        $text = trim($text);
        $maxLength = (int)self::baseConfig($tenantId)['script_max_length'];
        return $maxLength > 0 ? mb_substr($text, 0, $maxLength) : $text;
    }

    private static function checkQuota(int $tenantId, int $userId, int $quantity): void
    {
        $quota = AigcDigitalHumanQuota::where(['tenant_id' => $tenantId, 'user_id' => $userId])->findOrEmpty();
        if (!$quota->isEmpty() && !empty($quota['expire_time']) && (int)$quota['expire_time'] < time()) {
            throw new Exception('数字人额度已过期');
        }
        if (!$quota->isEmpty() && (int)$quota['total_quota'] > 0 && ((int)$quota['used_quota'] + $quantity) > (int)$quota['total_quota']) {
            throw new Exception('数字人额度不足');
        }
    }

    private static function consumeQuota(int $tenantId, int $userId, int $quantity): void
    {
        $quota = AigcDigitalHumanQuota::where(['tenant_id' => $tenantId, 'user_id' => $userId])->findOrEmpty();
        if ($quota->isEmpty()) {
            return;
        }
        $quota->used_quota = (int)$quota['used_quota'] + $quantity;
        $quota->save();
    }

    private static function formatAvatar(array $row): array
    {
        $tenantId = (int)($row['tenant_id'] ?? 0);
        $coverUri = (string)($row['cover_uri'] ?? '');
        $row['cover_url'] = self::isVideoUri($coverUri) ? '' : self::fileUrlForTenant($coverUri, $tenantId, $row);
        $row['media_url'] = self::fileUrlForTenant((string)($row['media_uri'] ?? ''), $tenantId, $row);
        return $row;
    }

    private static function isVideoUri(string $uri): bool
    {
        $path = strtolower((string)(parse_url($uri, PHP_URL_PATH) ?: $uri));
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, ['mp4', 'mov', 'webm', 'm4v', 'avi', 'mkv'], true);
    }

    private static function formatVoice(array $row): array
    {
        $tenantId = (int)($row['tenant_id'] ?? 0);
        if ((string)($row['status'] ?? '') === 'submitting') {
            $row['status'] = 'running';
        }
        $row['cover_url'] = self::fileUrlForTenant((string)($row['cover_uri'] ?? ''), $tenantId, $row);
        $row['audio_url'] = self::fileUrlForTenant((string)($row['audio_uri'] ?? ''), $tenantId, $row);
        $row['preview_audio_url'] = self::voicePreviewAudioUrl((string)($row['preview_audio_uri'] ?? ''), $tenantId, $row);
        $row['preview_url'] = $row['preview_audio_url'] ?: $row['audio_url'];
        return $row;
    }

    private static function formatResult(array $row): array
    {
        $tenantId = (int)($row['tenant_id'] ?? 0);
        $row['cover_url'] = self::fileUrlForTenant((string)($row['cover_uri'] ?? ''), $tenantId, $row);
        $row['video_url'] = self::fileUrlForTenant((string)($row['video_uri'] ?? ''), $tenantId, $row);
        return $row;
    }

    private static function providerFor(string $provider): AigcDigitalHumanProviderInterface
    {
        if (self::isXhadminProvider($provider)) {
            return new XhadminAigcDigitalHumanProvider();
        }
        return new MockAigcDigitalHumanProvider();
    }
}
