<?php

namespace app\common\service\app\aigc_pic_lipsync;

use app\common\model\app\aigc_pic_lipsync\AigcPicLipsyncConfig;
use app\common\model\app\aigc_pic_lipsync\AigcPicLipsyncResult;
use app\common\model\app\aigc_pic_lipsync\AigcPicLipsyncTask;
use app\common\model\ai\AiConsumptionLog;
use app\common\service\FileService;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\power\MarketApplicationApiRuntimeService;
use app\common\service\power\MarketPicLipsyncAppRuntimeService;
use app\common\model\app\image_human\ImageHumanAvatar;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanVoice;
use app\common\model\app\aigc_music\AigcMusicAsset;
use app\common\service\app\aigc_music\AigcMusicAssetService;
use app\common\service\MediaDurationService;
use Exception;

class AigcPicLipsyncService
{
    public const APP_CODE = 'aigc_pic_lipsync';
    public const UPSTREAM_APP_CODE = 'pic_lipsync';
    public const UPSTREAM_API_CODE = 'submit';

    public static function config(int $tenantId): array
    {
        $row = AigcPicLipsyncConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $data = $row->isEmpty() ? self::defaults() : array_merge(self::defaults(), $row->toArray());
        $data['status'] = (int)($data['status'] ?? 1) === 1 ? 1 : 0;
        $data['platform_status'] = $tenantId === 0 ? $data['status'] : (int)(AigcPicLipsyncConfig::where('tenant_id', 0)->value('status') ?? 1);
        $data['config_json'] = is_array($data['config_json'] ?? null) ? $data['config_json'] : [];
        $data['options'] = self::marketOptions($tenantId);
        $sharedConfig = \app\common\service\app\image_human\ImageHumanService::config($tenantId);
        $data['pricing'] = $sharedConfig['pricing'] ?? $sharedConfig['option_config']['pricing'] ?? [];
        $data['base_config'] = array_merge($sharedConfig['base_config'] ?? [], ['script_max_length' => 10000, 'prompt_max_length' => 2000]);
        $data['input_type'] = 'image_audio';
        $data['drive_modes'] = ['audio', 'text'];
        $data['quality_modes'] = ['fast', 'standard', 'max'];
        $data['input_label'] = '人物图片与驱动音频或参考音色';
        $data['max_videos'] = 1;
        $data['api_path'] = '/api/v1/apps/' . self::UPSTREAM_APP_CODE . '/' . self::UPSTREAM_API_CODE;
        $data['dependencies'] = self::dependencies($data['options']);
        if ($row->isEmpty()) {
            AigcPicLipsyncConfig::create(['tenant_id' => $tenantId, 'status' => $data['status'], 'config_json' => $data['config_json'], 'create_time' => time(), 'update_time' => time()]);
        }
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, $data);
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        $row = AigcPicLipsyncConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $payload = ['tenant_id' => $tenantId, 'status' => array_key_exists('status', $params) ? ((int)$params['status'] === 1 ? 1 : 0) : 1, 'config_json' => is_array($params['config_json'] ?? null) ? $params['config_json'] : [], 'update_time' => time()];
        if ($row->isEmpty()) { $payload['create_time'] = time(); AigcPicLipsyncConfig::create($payload); } else $row->save($payload);
    }

    public static function estimate(int $tenantId, array $params): array
    {
        self::assertAvailable($tenantId);
        $prepared = self::prepare($tenantId, $params, false, (int)($params['_user_id'] ?? 0));
        $quote = MarketPicLipsyncAppRuntimeService::quote($tenantId, $prepared['selection'], $prepared['duration']);
        return array_merge($quote, ['quantity' => $prepared['duration'], 'input_seconds' => $prepared['duration'], 'quality' => $prepared['request']['quality'], 'display_points' => (float)$quote['user_charge_points']]);
    }

    public static function generate(int $tenantId, int $userId, array $params): array
    {
        self::assertAvailable($tenantId);
        $requestKey = trim((string)($params['request_key'] ?? ''));
        if ($requestKey !== '' && !preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $requestKey)) throw new Exception('提交标识无效');
        if ($requestKey !== '') {
            $existing = AigcPicLipsyncTask::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'request_key' => $requestKey])->findOrEmpty();
            if (!$existing->isEmpty()) return self::formatTask($existing->toArray(), $tenantId);
        }
        $prepared = self::prepare($tenantId, $params, true, $userId);
        $quote = MarketPicLipsyncAppRuntimeService::quote($tenantId, $prepared['selection'], $prepared['duration']);
        try { $task = AigcPicLipsyncTask::create(['tenant_id' => $tenantId, 'user_id' => $userId, 'app_task_id' => 0, 'consumption_id' => 0, 'provider_task_id' => '', 'market_product_id' => (int)$quote['market_product_id'], 'market_sku_id' => (int)$quote['market_sku_id'], 'pricing_snapshot' => $quote['market_snapshot'], 'source_url' => $prepared['request']['image_url'], 'avatar_id' => $prepared['avatar_id'], 'audio_asset_id' => $prepared['audio_asset_id'], 'title' => mb_substr(trim((string)($params['title'] ?? '图片数字人作品')), 0, 160), 'quality' => $prepared['request']['quality'], 'drive_mode' => $prepared['request']['mode'], 'input_seconds' => $prepared['duration'], 'request_key' => $requestKey ?: null, 'request_snapshot' => $prepared['request'], 'status' => 'pending', 'error' => '', 'tenant_cost_points' => (float)$quote['tenant_cost_points'], 'user_charge_points' => (float)$quote['user_charge_points'], 'create_time' => time(), 'update_time' => time(), 'finish_time' => 0, 'delete_time' => 0]);
        } catch (\Throwable $e) {
            if ($requestKey !== '') {
                $existing = AigcPicLipsyncTask::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'request_key' => $requestKey])->findOrEmpty();
                if (!$existing->isEmpty()) return self::formatTask($existing->toArray(), $tenantId);
            }
            throw $e;
        }
        try {
            $reserve = MarketPicLipsyncAppRuntimeService::reserve($tenantId, $userId, self::UPSTREAM_API_CODE, (string)$task['id'], $prepared['selection'], $prepared['request'], $prepared['duration'], self::APP_CODE, 'aigc_pic_lipsync_task');
            $task->save(['app_task_id' => (int)$reserve['app_task_id'], 'consumption_id' => (int)$reserve['consumption_id'], 'market_product_id' => (int)($reserve['market_snapshot']['product_id'] ?? $quote['market_product_id']), 'market_sku_id' => (int)($reserve['market_snapshot']['sku_id'] ?? $quote['market_sku_id']), 'pricing_snapshot' => $reserve['market_snapshot'], 'status' => 'running', 'update_time' => time()]);
            MarketPicLipsyncAppRuntimeService::linkBusinessTask((int)$reserve['app_task_id'], (int)$task['id']);
            $result = MarketPicLipsyncAppRuntimeService::submit((int)$reserve['consumption_id'], $prepared['request']);
            $task->save(['provider_task_id' => (string)($result['provider_task_id'] ?? ''), 'status' => (string)($result['status'] ?? 'running'), 'update_time' => time()]);
            self::syncConsumption((int)$task['id']);
            return self::response($task, $quote);
        } catch (\Throwable $e) {
            if ((int)$task['consumption_id'] > 0) { try { MarketPicLipsyncAppRuntimeService::fail((int)$task['consumption_id'], $e->getMessage(), 'submit_failed'); } catch (\Throwable) {} }
            $task->save(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'finish_time' => time(), 'update_time' => time()]);
            throw $e instanceof Exception ? $e : new Exception('图片数字人任务提交失败');
        }
    }

    public static function taskLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        $query = AigcPicLipsyncTask::where(['tenant_id' => $tenantId, 'delete_time' => 0])->order('id', 'desc');
        if ($userId > 0) $query->where('user_id', $userId);
        $status = trim((string)($params['status'] ?? '')); if ($status !== '') $query->where('status', $status);
        $pageNo = max(1, (int)($params['page_no'] ?? 1)); $pageSize = max(1, min(100, (int)($params['page_size'] ?? 15))); $count = (int)(clone $query)->count();
        $rows = $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();
        foreach ($rows as &$row) { self::syncConsumption((int)$row['id'], $userId); $fresh = AigcPicLipsyncTask::find((int)$row['id']); $row = self::formatTask($fresh?->toArray() ?: $row, $tenantId); } unset($row);
        return ['lists' => $rows, 'count' => $count, 'page_no' => $pageNo, 'page_size' => $pageSize];
    }

    public static function platformTaskLists(array $params): array
    {
        $query = AigcPicLipsyncTask::where('delete_time', 0)->order('id', 'desc');
        if ((int)($params['tenant_id'] ?? 0) > 0) $query->where('tenant_id', (int)$params['tenant_id']);
        if (!empty($params['status'])) $query->where('status', (string)$params['status']);
        $pageNo = max(1, (int)($params['page_no'] ?? 1)); $pageSize = max(1, min(100, (int)($params['page_size'] ?? 15)));
        $count = (int)(clone $query)->count(); $rows = $query->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();
        foreach ($rows as &$row) { self::syncConsumption((int)$row['id']); $fresh = AigcPicLipsyncTask::find((int)$row['id']); $row = self::formatTask($fresh?->toArray() ?: $row, (int)$row['tenant_id']); }
        return ['lists' => $rows, 'count' => $count, 'page_no' => $pageNo, 'page_size' => $pageSize];
    }

    public static function taskDetail(int $tenantId, int $taskId, int $userId = 0): array
    {
        $query = AigcPicLipsyncTask::where(['tenant_id' => $tenantId, 'id' => $taskId, 'delete_time' => 0]); if ($userId > 0) $query->where('user_id', $userId); $task = $query->findOrEmpty(); if ($task->isEmpty()) throw new Exception('任务不存在');
        self::syncConsumption($taskId, $userId); $fresh = AigcPicLipsyncTask::find($taskId); return self::formatTask($fresh?->toArray() ?: $task->toArray(), $tenantId);
    }

    public static function resultLists(int $tenantId, int $userId, array $params = []): array { $params['status'] = 'success'; return self::taskLists($tenantId, $userId, $params); }
    public static function retryTask(int $tenantId, int $taskId): array
    {
        $task = AigcPicLipsyncTask::where(['tenant_id' => $tenantId, 'id' => $taskId, 'delete_time' => 0])->findOrEmpty();
        if ($task->isEmpty()) throw new Exception('任务不存在');
        if ((string)$task['status'] !== 'failed') throw new Exception('只有失败任务可以重试');
        return self::generate($tenantId, (int)$task['user_id'], ['avatar_id' => (int)$task['avatar_id'], 'audio_asset_id' => (int)$task['audio_asset_id'], 'voice_id' => (int)($task['request_snapshot']['voice_id'] ?? 0), 'driver_voice_id' => (int)($task['request_snapshot']['driver_voice_id'] ?? 0), 'title' => (string)$task['title'], 'quality' => (string)$task['quality'], 'mode' => (string)$task['drive_mode'], 'content' => (string)($task['request_snapshot']['content'] ?? ''), 'prompt' => (string)($task['request_snapshot']['prompt'] ?? ''), 'market_product_id' => (int)$task['market_product_id'], 'market_sku_id' => (int)$task['market_sku_id']]);
    }
    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void { $query = AigcPicLipsyncTask::where(['tenant_id' => $tenantId, 'id' => $taskId, 'delete_time' => 0]); if ($userId > 0) $query->where('user_id', $userId); $task = $query->findOrEmpty(); if ($task->isEmpty()) throw new Exception('任务不存在'); if ((int)$task['consumption_id'] > 0 && in_array((string)$task['status'], ['pending', 'running'], true)) { MarketPicLipsyncAppRuntimeService::cancel((int)$task['consumption_id']); } $task->save(['delete_time' => time(), 'update_time' => time()]); AigcPicLipsyncResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time()]); }
    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void { $query = AigcPicLipsyncResult::where(['tenant_id' => $tenantId, 'id' => $resultId, 'delete_time' => 0]); if ($userId > 0) $query->where('user_id', $userId); $result = $query->findOrEmpty(); if ($result->isEmpty()) throw new Exception('作品不存在'); $result->save(['delete_time' => time()]); }

    public static function syncConsumption(int $taskId, int $userId = 0): void
    {
        $query = AigcPicLipsyncTask::where(['id' => $taskId, 'delete_time' => 0]); if ($userId > 0) $query->where('user_id', $userId); $task = $query->findOrEmpty(); if ($task->isEmpty() || (int)$task['consumption_id'] <= 0) return;
        $consumption = AiConsumptionLog::findOrEmpty((int)$task['consumption_id']); if ($consumption->isEmpty()) return;
        $terminal = in_array((string)$consumption['run_status'], ['success', 'failed', 'canceled', 'cancelled'], true) || in_array((string)$consumption['billing_status'], ['settled', 'refunded'], true); if (!$terminal) return;
        $summary = $consumption['response_summary'] ?? []; if (is_string($summary)) $summary = json_decode($summary, true) ?: []; $videos = is_array($summary) ? (array)($summary['videos'] ?? []) : [];
        if ((string)$consumption['run_status'] === 'success' && $videos !== []) {
            foreach ($videos as $video) { if (!is_array($video) || trim((string)($video['video_uri'] ?? '')) === '') continue; $exists = AigcPicLipsyncResult::where(['tenant_id' => (int)$task['tenant_id'], 'task_id' => $taskId, 'video_uri' => (string)$video['video_uri'], 'delete_time' => 0])->findOrEmpty(); if ($exists->isEmpty()) AigcPicLipsyncResult::create(['tenant_id' => (int)$task['tenant_id'], 'task_id' => $taskId, 'user_id' => (int)$task['user_id'], 'video_uri' => (string)$video['video_uri'], 'storage_scope' => (string)($video['storage_scope'] ?? 'tenant'), 'storage_engine' => (string)($video['storage_engine'] ?? 'local'), 'storage_domain' => (string)($video['storage_domain'] ?? ''), 'width' => (int)($video['width'] ?? 0), 'height' => (int)($video['height'] ?? 0), 'create_time' => time(), 'update_time' => time(), 'delete_time' => 0]); }
            $task->save(['actual_tenant_cost' => (float)$consumption['actual_tenant_cost'], 'actual_user_price' => (float)$consumption['actual_user_price'], 'status' => 'success', 'error' => '', 'finish_time' => self::timestampValue($consumption['finish_time'] ?? 0) ?: time(), 'update_time' => time()]);
        } elseif (in_array((string)$consumption['run_status'], ['failed', 'canceled', 'cancelled'], true) || (string)$consumption['billing_status'] === 'refunded') $task->save(['status' => (string)$consumption['run_status'] === 'canceled' ? 'canceled' : 'failed', 'error' => (string)($consumption['error_message'] ?? '图片数字人任务失败'), 'finish_time' => self::timestampValue($consumption['finish_time'] ?? 0) ?: time(), 'update_time' => time()]);
    }

    private static function prepare(int $tenantId, array $params, bool $required, int $userId): array
    {
        $quality = (string)($params['quality'] ?? 'standard');
        $mode = (string)($params['mode'] ?? 'audio');
        if (!in_array($quality, ['fast', 'standard', 'max'], true)) throw new Exception('质量模式无效');
        if (!in_array($mode, ['audio', 'text'], true)) throw new Exception('驱动模式无效');
        $options = self::marketOptions($tenantId);
        $selected = null;
        foreach ($options as $item) {
            if ((string)$item['quality'] !== $quality) continue;
            if (!empty($params['market_product_id']) && (int)$params['market_product_id'] !== (int)$item['market_product_id']) continue;
            if (!empty($params['market_sku_id']) && (int)$params['market_sku_id'] !== (int)$item['market_sku_id']) continue;
            $selected = $item; break;
        }
        if ($selected === null) throw new Exception('所选质量模式暂未上架');
        $avatarId = (int)($params['avatar_id'] ?? 0);
        $audioId = (int)($params['audio_asset_id'] ?? 0);
        $avatar = ImageHumanAvatar::where(['tenant_id' => $tenantId, 'id' => $avatarId, 'delete_time' => 0])
            ->whereRaw("(source = 'official' OR (source = 'mine' AND user_id = " . $userId . '))')->findOrEmpty();
        $audio = AigcMusicAsset::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'id' => $audioId, 'delete_time' => 0, 'asset_type' => 'pic_lipsync_audio'])->findOrEmpty();
        if ($required && $avatar->isEmpty()) throw new Exception('请上传或选择人物图片');
        $voiceId = (int)($params[$mode === 'text' ? 'voice_id' : 'driver_voice_id'] ?? 0);
        $voice = $voiceId > 0 ? AigcDigitalHumanVoice::where(['tenant_id' => $tenantId, 'id' => $voiceId, 'delete_time' => 0])
            ->whereRaw("(source = 'official' OR (source = 'mine' AND user_id = " . $userId . '))')->findOrEmpty() : null;
        if ($voiceId > 0 && ($voice === null || $voice->isEmpty())) throw new Exception('音色不存在或无权使用');
        if ($required && $audio->isEmpty() && $voice === null) throw new Exception($mode === 'text' ? '请选择音色或上传参考音频' : '请上传驱动音频');
        $imageUrl = $avatar->isEmpty() ? '' : FileService::getFileUrlByStorage((string)$avatar['image_uri'], (string)$avatar['storage_scope'], (string)$avatar['storage_engine'], (string)$avatar['storage_domain']);
        $audioUrl = $audio->isEmpty() ? '' : AigcMusicAssetService::assetUrl($audio->toArray());
        $duration = $audio->isEmpty() ? 0 : (float)$audio['duration'];
        $audioUri = $audio->isEmpty() ? '' : (string)$audio['uri'];
        $voiceDurationKey = '';
        if ($voice !== null) {
            // The selected voice supplies its existing sample. Never synthesize speech here.
            $audioUri = trim((string)$voice['audio_uri']) ?: trim((string)$voice['preview_audio_uri']);
            if ($audioUri === '') throw new Exception('所选音色缺少参考音频，请更换音色');
            $audioUrl = FileService::getFileUrlByStorage($audioUri, (string)$voice['storage_scope'], (string)$voice['storage_engine'], (string)$voice['storage_domain']);
            // The shared voice table stores whole seconds. Cache the measured
            // sample duration separately so fractional input billing stays exact.
            $voiceDurationKey = 'pic-reference-duration:' . sha1($tenantId . '|' . $voiceId . '|' . $audioUrl . '|' . (string)$voice['update_time']);
            $duration = (float)\think\facade\Cache::get($voiceDurationKey, 0);
            $audioId = 0;
        }
        if (($required || $voice !== null) && $duration <= 0) {
            $path = public_path() . ltrim($audioUri, '/');
            $duration = is_file($path) ? MediaDurationService::detect($path) : 0;
            if ($duration <= 0 && $audioUrl !== '') {
                $stored = AigcMusicAssetService::persistGeneratedAudio($audioUrl, $tenantId, $userId);
                $duration = (float)($stored['duration'] ?? 0);
            }
            if ($duration <= 0) throw new Exception('无法检测音频时长，请重新上传可播放的音频');
            if ($voice !== null) \think\facade\Cache::set($voiceDurationKey, $duration, 600);
            elseif (!$audio->isEmpty()) $audio->save(['duration' => $duration, 'update_time' => time()]);
        }
        $request = ['model' => 'super-lipsync-pro', 'image_url' => $imageUrl, 'audio_url' => $audioUrl, 'mode' => $mode, 'quality' => $quality, 'prompt' => trim((string)($params['prompt'] ?? '')), 'content' => trim((string)($params['content'] ?? ''))];
        if ($voice !== null) $request[$mode === 'text' ? 'voice_id' : 'driver_voice_id'] = $voiceId;
        if ($required) MarketPicLipsyncAppRuntimeService::buildPayload(['locked_params' => $selected['locked_params']], $request);
        return ['selection' => $selected, 'request' => $request, 'avatar_id' => $avatarId, 'audio_asset_id' => $audioId, 'duration' => $duration];
    }

    private static function marketOptions(int $tenantId): array { return MarketPicLipsyncAppRuntimeService::options($tenantId); }
    private static function refreshRunningTask(array $task): void
    {
        if (!in_array((string)($task['status'] ?? ''), ['pending', 'running'], true) || (int)($task['consumption_id'] ?? 0) <= 0) return;
        try { MarketApplicationApiRuntimeService::refresh((int)$task['consumption_id']); } catch (\Throwable) { }
    }
    private static function assertAvailable(int $tenantId): void { if (AppAccessService::assertTenantCanUse($tenantId, self::APP_CODE) !== null) throw new Exception('图片数字人应用未开通或未上架'); $config = self::config($tenantId); if ((int)$config['status'] !== 1 || (int)$config['platform_status'] !== 1) throw new Exception('图片数字人应用已停用'); if (empty($config['options'])) throw new Exception('暂无上架的图片数字人应用 API'); }
    private static function dependencies(array $options): array { return ['items' => [['app_code' => 'power_market', 'name' => '图片数字人应用 API', 'required_for' => '图片数字人', 'installed' => true, 'tenant_enabled' => true, 'channel_ready' => $options !== [], 'ready' => $options !== [], 'message' => $options !== [] ? '可用' : '暂无上架的图片数字人应用 API']], 'ready' => $options !== []]; }

    private static function formatTask(array $row, int $tenantId): array { $results = AigcPicLipsyncResult::where(['tenant_id' => $tenantId, 'task_id' => (int)$row['id'], 'delete_time' => 0])->order('id', 'asc')->select()->toArray(); foreach ($results as &$result) { $result['video_url'] = FileService::getFileUrlByStorage($result['video_uri'], $result['storage_scope'] ?? '', $result['storage_engine'] ?? '', $result['storage_domain'] ?? ''); $result['download_url'] = $result['video_url']; } unset($result); $row['task_id'] = (int)$row['id']; $row['results'] = $results; $row['video_url'] = (string)($results[0]['video_url'] ?? ''); $row['status_label'] = match ((string)($row['status'] ?? '')) { 'success' => '已完成', 'failed' => '失败', 'canceled' => '已取消', 'pending' => '排队中', default => '处理中' }; return $row; }
    private static function response(AigcPicLipsyncTask $task, array $quote): array { return ['task_id' => (int)$task['id'], 'status' => (string)$task['status'], 'error' => (string)($task['error'] ?? ''), 'results' => self::formatTask($task->toArray(), (int)$task['tenant_id'])['results'], 'estimate' => array_merge($quote, ['quantity' => (float)$task['input_seconds'], 'input_seconds' => (float)$task['input_seconds'], 'display_points' => (float)$quote['user_charge_points']])]; }
    private static function defaults(): array { return ['status' => 1, 'config_json' => []]; }
    private static function validUrl(string $url): bool { $parts = parse_url($url); return is_array($parts) && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) && trim((string)($parts['host'] ?? '')) !== ''; }
    private static function timestampValue(mixed $value): int { if (is_numeric($value)) return (int)$value; $timestamp = strtotime((string)$value); return $timestamp === false ? 0 : $timestamp; }
}
