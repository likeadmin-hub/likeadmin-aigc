<?php

namespace app\common\service\app\aigc_person_replacement;

use app\common\model\app\aigc_person_replacement\AigcPersonReplacementConfig;
use app\common\model\app\aigc_person_replacement\AigcPersonReplacementResult;
use app\common\model\app\aigc_person_replacement\AigcPersonReplacementTask;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\FileService;
use app\common\service\point\PointService;
use app\common\service\storage\StorageConfigService;
use Exception;
use think\facade\Db;

class AigcPersonReplacementService
{
    public const APP_CODE = 'aigc_person_replacement';
    public const UPSTREAM_APP_CODE = 'person_replacement';

    private const MODES = [
        'fast' => ['label' => '快速模式', 'price' => 1],
        'standard' => ['label' => '标准模式', 'price' => 2],
        'max' => ['label' => '高清模式', 'price' => 3],
    ];

    public static function config(int $tenantId): array
    {
        $row = AigcPersonReplacementConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $data = $row->isEmpty() ? self::defaults() : array_merge(self::defaults(), $row->toArray());
        $data = self::sanitizeConfig($data);
        if ($row->isEmpty()) {
            self::saveConfigSnapshot($tenantId, $data);
        }
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, $data);
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        $current = self::config($tenantId);
        $data = [
            'tenant_id' => $tenantId,
            'status' => array_key_exists('status', $params) ? ((int)$params['status'] ? 1 : 0) : (int)$current['status'],
            'default_mode' => self::normalizeMode((string)($params['default_mode'] ?? $current['default_mode'])),
            'default_face_count' => self::normalizeFaceCount($params['default_face_count'] ?? $current['default_face_count']),
            'price_matrix' => self::normalizePriceMatrix($params['price_matrix'] ?? $current['price_matrix']),
            'config_json' => is_array($params['config_json'] ?? null) ? $params['config_json'] : ($current['config_json'] ?? []),
            'update_time' => time(),
        ];
        $row = AigcPersonReplacementConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcPersonReplacementConfig::create($data);
            return;
        }
        $row->save($data);
    }

    public static function estimate(int $tenantId, array $params): array
    {
        self::assertAvailable($tenantId);
        $prepared = self::preparePayload($tenantId, $params, false);
        return self::buildEstimate($prepared);
    }

    public static function generate(int $tenantId, int $userId, array $params): array
    {
        self::assertAvailable($tenantId, $userId);
        $prepared = self::preparePayload($tenantId, $params, true);
        $estimate = self::buildEstimate($prepared);
        PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);

        $task = AigcPersonReplacementTask::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'provider_task_id' => '',
            'reference_images' => $prepared['reference_images'],
            'video_uri' => $prepared['video_uri'],
            'video_url_snapshot' => $prepared['video_url'],
            'prompt' => $prepared['prompt'],
            'mode' => $prepared['mode'],
            'face_count' => $prepared['face_count'],
            'duration' => $prepared['duration'],
            'unit_price' => $estimate['unit_price'],
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
            'billing_status' => 'none',
            'request_snapshot' => $prepared['provider_payload'],
            'upstream_usage' => [],
            'provider_response' => [],
            'status' => 'pending',
            'error' => '',
            'finish_time' => 0,
            'delete_time' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $provider = new XhadminPersonReplacementProvider();
        $result = $provider->submit($prepared['provider_payload']);
        if (!$result->success || $result->taskId === '') {
            $task->save([
                'status' => 'failed',
                'error' => $result->error ?: '动作替换提交失败',
                'provider_response' => $result->raw,
                'finish_time' => time(),
                'update_time' => time(),
            ]);
            throw new Exception($result->error ?: '动作替换提交失败');
        }

        Db::startTrans();
        try {
            $locked = AigcPersonReplacementTask::where('tenant_id', $tenantId)->where('id', (int)$task['id'])->lock(true)->findOrEmpty();
            if ($locked->isEmpty()) {
                throw new Exception('任务不存在');
            }
            PointService::consumeBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points'], self::sourceSn((int)$task['id']), '动作替换消费', [
                'app_code' => self::APP_CODE,
                'task_id' => (int)$task['id'],
                'mode' => $prepared['mode'],
                'duration' => $prepared['duration'],
            ]);
            $locked->save([
                'provider_task_id' => $result->taskId,
                'status' => in_array($result->status, ['pending', 'running'], true) ? $result->status : 'running',
                'billing_status' => 'deducted',
                'provider_response' => $result->raw,
                'upstream_usage' => $result->usage,
                'update_time' => time(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $task->save([
                'provider_task_id' => $result->taskId,
                'status' => 'failed',
                'error' => '扣费失败：' . $e->getMessage(),
                'provider_response' => $result->raw,
                'finish_time' => time(),
                'update_time' => time(),
            ]);
            throw $e;
        }

        return [
            'task_id' => (int)$task['id'],
            'provider_task_id' => $result->taskId,
            'status' => 'running',
            'estimate' => $estimate,
        ];
    }

    public static function taskLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        self::refreshTasks($tenantId, $userId);
        $query = AigcPersonReplacementTask::alias('t')
            ->leftJoin('user u', 'u.id = t.user_id AND u.tenant_id = t.tenant_id')
            ->field('t.*,u.nickname user_nickname,u.account user_account,u.mobile user_mobile')
            ->where('t.tenant_id', $tenantId)
            ->where('t.delete_time', 0)
            ->order('t.id', 'desc');
        if ($userId > 0) {
            $query->where('t.user_id', $userId);
        }
        $status = trim((string)($params['status'] ?? ''));
        if ($status !== '') {
            $query->where('t.status', $status);
        }
        $mode = trim((string)($params['mode'] ?? ''));
        if ($mode !== '') {
            $query->where('t.mode', self::normalizeMode($mode));
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
        $rows = self::appendResults($tenantId, $userId, $rows);
        return ['lists' => array_map([self::class, 'formatTaskRow'], $rows), 'count' => $count, 'page_no' => $pageNo, 'page_size' => $pageSize];
    }

    public static function taskDetail(int $tenantId, int $taskId, int $userId = 0): array
    {
        self::refreshTasks($tenantId, $userId, $taskId);
        $query = AigcPersonReplacementTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        $rows = self::appendResults($tenantId, $userId, [$task->toArray()]);
        return self::formatTaskRow($rows[0] ?? []);
    }

    public static function retryTask(int $tenantId, int $taskId): array
    {
        $task = AigcPersonReplacementTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return self::generate($tenantId, (int)$task['user_id'], [
            'reference_images' => $task['reference_images'] ?? [],
            'video_uri' => $task['video_uri'],
            'prompt' => $task['prompt'],
            'mode' => $task['mode'],
            'face_count' => (int)$task['face_count'],
            'duration' => (float)$task['duration'],
        ]);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = AigcPersonReplacementTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        $task->save(['delete_time' => time(), 'update_time' => time()]);
        AigcPersonReplacementResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time(), 'update_time' => time()]);
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = AigcPersonReplacementResult::where(['tenant_id' => $tenantId, 'id' => $resultId]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('作品不存在');
        }
        $row->save(['delete_time' => time(), 'update_time' => time()]);
    }

    private static function assertAvailable(int $tenantId, int $userId = 0): void
    {
        if (AppAccessService::assertTenantCanUse($tenantId, self::APP_CODE, $userId) !== null) {
            throw new Exception('动作替换应用未开通或未上架');
        }
        $config = self::config($tenantId);
        if ((int)($config['status'] ?? 1) !== 1) {
            throw new Exception('动作替换应用已停用');
        }
    }

    private static function preparePayload(int $tenantId, array $params, bool $requireFiles): array
    {
        $config = self::config($tenantId);
        $images = self::normalizeImages($params['reference_images'] ?? $params['file_url'] ?? $params['images'] ?? []);
        if ($requireFiles && empty($images)) {
            throw new Exception('请上传参考人物图片');
        }
        if (count($images) > 3) {
            throw new Exception('参考人物图片最多上传3张');
        }
        $videoUri = trim((string)($params['video_uri'] ?? $params['video'] ?? $params['video_url'] ?? ''));
        if ($requireFiles && $videoUri === '') {
            throw new Exception('请上传输入视频');
        }
        $duration = round((float)($params['duration'] ?? 0), 2);
        if ($duration <= 0) {
            throw new Exception('无法识别视频时长，请更换视频后重试');
        }
        $mode = self::normalizeMode((string)($params['mode'] ?? $config['default_mode'] ?? 'standard'));
        $faceCount = self::normalizeFaceCount($params['face_count'] ?? $config['default_face_count'] ?? 1);
        $prompt = mb_substr(trim((string)($params['prompt'] ?? '')), 0, 2000);
        $imageUrls = array_map([self::class, 'fileUrl'], $images);
        $videoUrl = self::fileUrl($videoUri);
        if ($requireFiles) {
            foreach ($imageUrls as $url) {
                self::assertOnlineUrl($url, '参考人物图片');
            }
            self::assertOnlineUrl($videoUrl, '输入视频');
        }
        $providerPayload = [
            'type' => self::UPSTREAM_APP_CODE,
            'file_url' => array_values($imageUrls),
            'video_url' => $videoUrl,
            'prompt' => $prompt,
            'mode' => $mode,
            'face_count' => $faceCount,
            'duration' => $duration,
        ];
        return [
            'reference_images' => $images,
            'reference_image_urls' => $imageUrls,
            'video_uri' => $videoUri,
            'video_url' => $videoUrl,
            'prompt' => $prompt,
            'mode' => $mode,
            'mode_label' => self::modeLabel($mode),
            'face_count' => $faceCount,
            'duration' => $duration,
            'config' => $config,
            'provider_payload' => $providerPayload,
        ];
    }

    private static function buildEstimate(array $prepared): array
    {
        $unitPrice = (float)($prepared['config']['price_matrix'][$prepared['mode']] ?? self::MODES[$prepared['mode']]['price'] ?? 1);
        $duration = max(0.01, (float)$prepared['duration']);
        $points = round($unitPrice * $duration, 2);
        return [
            'mode' => $prepared['mode'],
            'mode_label' => $prepared['mode_label'],
            'face_count' => $prepared['face_count'],
            'duration' => $duration,
            'unit_price' => round($unitPrice, 2),
            'tenant_cost_points' => $points,
            'user_charge_points' => $points,
            'display_points' => $points,
        ];
    }

    private static function refreshTasks(int $tenantId, int $userId = 0, int $taskId = 0): void
    {
        $query = AigcPersonReplacementTask::where('tenant_id', $tenantId)->where('delete_time', 0)->whereIn('status', ['pending', 'running']);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($taskId > 0) {
            $query->where('id', $taskId);
        }
        foreach ($query->limit(20)->select() as $task) {
            self::syncTask($task);
        }
    }

    private static function syncTask(AigcPersonReplacementTask $task): void
    {
        $taskId = trim((string)$task['provider_task_id']);
        if ($taskId === '') {
            return;
        }
        $result = (new XhadminPersonReplacementProvider())->query($taskId, (int)$task['tenant_id'], (int)$task['user_id']);
        if ($result->pending) {
            $task->save([
                'status' => $result->status === 'pending' ? 'pending' : 'running',
                'provider_response' => $result->raw,
                'upstream_usage' => $result->usage,
                'update_time' => time(),
            ]);
            return;
        }
        if (!$result->success || $result->status === 'failed') {
            self::markFailed($task, $result->error ?: '动作替换任务失败', $result->raw, $result->usage);
            return;
        }
        self::finishTask($task, $result);
    }

    private static function finishTask(AigcPersonReplacementTask $task, PersonReplacementGenerateResult $result): void
    {
        Db::startTrans();
        try {
            $locked = AigcPersonReplacementTask::where('tenant_id', (int)$task['tenant_id'])->where('id', (int)$task['id'])->lock(true)->findOrEmpty();
            if ($locked->isEmpty() || (string)$locked['status'] === 'success') {
                Db::commit();
                return;
            }
            $existing = AigcPersonReplacementResult::where('tenant_id', (int)$locked['tenant_id'])->where('task_id', (int)$locked['id'])->where('delete_time', 0)->findOrEmpty();
            if ($existing->isEmpty()) {
                $video = $result->videos[0] ?? [];
                $storage = StorageConfigService::getEffectiveConfig((int)$locked['tenant_id']);
                $domain = StorageConfigService::getEffectiveDomain((int)$locked['tenant_id']);
                AigcPersonReplacementResult::create([
                    'tenant_id' => (int)$locked['tenant_id'],
                    'task_id' => (int)$locked['id'],
                    'provider_task_id' => $result->taskId,
                    'user_id' => (int)$locked['user_id'],
                    'video_uri' => (string)($video['uri'] ?? ''),
                    'cover_uri' => (string)($video['cover_uri'] ?? ''),
                    'storage_scope' => (string)($video['storage_scope'] ?? $storage['scope'] ?? 'tenant'),
                    'storage_engine' => (string)($video['storage_engine'] ?? $storage['default'] ?? 'local'),
                    'storage_domain' => (string)($video['storage_domain'] ?? $domain),
                    'duration' => (float)($video['duration'] ?? $locked['duration']),
                    'width' => (int)($video['width'] ?? 0),
                    'height' => (int)($video['height'] ?? 0),
                    'result_json' => $video['raw'] ?? $result->raw,
                    'delete_time' => 0,
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
            }
            $locked->save([
                'status' => 'success',
                'error' => '',
                'provider_response' => $result->raw,
                'upstream_usage' => $result->usage,
                'finish_time' => time(),
                'update_time' => time(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            self::markFailed($task, '生成视频保存失败：' . $e->getMessage(), $result->raw, $result->usage);
        }
    }

    private static function markFailed(AigcPersonReplacementTask $task, string $error, array $raw = [], array $usage = []): void
    {
        Db::startTrans();
        try {
            $locked = AigcPersonReplacementTask::where('tenant_id', (int)$task['tenant_id'])->where('id', (int)$task['id'])->lock(true)->findOrEmpty();
            if ($locked->isEmpty() || in_array((string)$locked['status'], ['success', 'failed', 'canceled'], true)) {
                Db::commit();
                return;
            }
            if ((string)$locked['billing_status'] === 'deducted') {
                PointService::refundBusinessAmountsInCurrentTransaction((int)$locked['tenant_id'], (int)$locked['user_id'], (float)$locked['tenant_cost_points'], (float)$locked['user_charge_points'], self::sourceSn((int)$locked['id']) . '-refund', '动作替换失败退回', [
                    'app_code' => self::APP_CODE,
                    'task_id' => (int)$locked['id'],
                ]);
                $locked->billing_status = 'refunded';
            }
            $locked->status = 'failed';
            $locked->error = mb_substr($error, 0, 1000);
            $locked->provider_response = $raw;
            $locked->upstream_usage = $usage;
            $locked->finish_time = time();
            $locked->update_time = time();
            $locked->save();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $task->save(['status' => 'failed', 'error' => mb_substr($error, 0, 900) . '；退款失败：' . mb_substr($e->getMessage(), 0, 80), 'finish_time' => time(), 'update_time' => time()]);
        }
    }

    private static function appendResults(int $tenantId, int $userId, array $rows): array
    {
        $taskIds = array_values(array_unique(array_filter(array_column($rows, 'id'))));
        $map = [];
        if ($taskIds) {
            $query = AigcPersonReplacementResult::where('tenant_id', $tenantId)->where('delete_time', 0)->whereIn('task_id', $taskIds)->order('id', 'asc');
            if ($userId > 0) {
                $query->where('user_id', $userId);
            }
            foreach ($query->select()->toArray() as $row) {
                $row['video_url'] = FileService::getFileUrlByStorage($row['video_uri'], $row['storage_scope'] ?? '', $row['storage_engine'] ?? '', $row['storage_domain'] ?? '');
                $row['download_url'] = $row['video_url'];
                $map[(int)$row['task_id']][] = $row;
            }
        }
        foreach ($rows as &$row) {
            $results = $map[(int)$row['id']] ?? [];
            $row['results'] = $results;
            $first = $results[0] ?? [];
            $row['video_url'] = (string)($first['video_url'] ?? '');
            $row['download_url'] = (string)($first['download_url'] ?? '');
            $row['reference_image_urls'] = array_map([self::class, 'fileUrl'], self::normalizeImages($row['reference_images'] ?? []));
            $row['source_video_url'] = self::fileUrl((string)($row['video_uri'] ?? ''));
        }
        return $rows;
    }

    private static function formatTaskRow(array $row): array
    {
        $row['task_id'] = (int)($row['id'] ?? 0);
        $row['mode_label'] = self::modeLabel((string)($row['mode'] ?? 'standard'));
        $row['duration_label'] = ((float)($row['duration'] ?? 0)) > 0 ? rtrim(rtrim(number_format((float)$row['duration'], 2, '.', ''), '0'), '.') . '秒' : '';
        $row['status_label'] = match ((string)($row['status'] ?? '')) {
            'success' => '已完成',
            'failed' => '失败',
            'canceled' => '已取消',
            'pending' => '排队中',
            default => '生成中',
        };
        return $row;
    }

    private static function defaults(): array
    {
        return [
            'status' => 1,
            'default_mode' => 'standard',
            'default_face_count' => 1,
            'price_matrix' => ['fast' => 1, 'standard' => 2, 'max' => 3],
            'config_json' => [],
        ];
    }

    private static function sanitizeConfig(array $data): array
    {
        $data['status'] = (int)($data['status'] ?? 1) ? 1 : 0;
        $data['default_mode'] = self::normalizeMode((string)($data['default_mode'] ?? 'standard'));
        $data['default_face_count'] = self::normalizeFaceCount($data['default_face_count'] ?? 1);
        $data['price_matrix'] = self::normalizePriceMatrix($data['price_matrix'] ?? []);
        $data['mode_options'] = self::modeOptions($data['price_matrix']);
        $data['face_count_options'] = range(1, 7);
        return $data;
    }

    private static function saveConfigSnapshot(int $tenantId, array $data): void
    {
        AigcPersonReplacementConfig::create([
            'tenant_id' => $tenantId,
            'status' => $data['status'],
            'default_mode' => $data['default_mode'],
            'default_face_count' => $data['default_face_count'],
            'price_matrix' => $data['price_matrix'],
            'config_json' => $data['config_json'] ?? [],
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private static function normalizePriceMatrix(mixed $matrix): array
    {
        $matrix = is_array($matrix) ? $matrix : [];
        $normalized = [];
        foreach (self::MODES as $mode => $meta) {
            $normalized[$mode] = round(max(0, (float)($matrix[$mode] ?? $meta['price'])), 2);
        }
        return $normalized;
    }

    private static function modeOptions(array $priceMatrix): array
    {
        $options = [];
        foreach (self::MODES as $mode => $meta) {
            $options[] = ['value' => $mode, 'label' => $meta['label'], 'unit_price' => (float)($priceMatrix[$mode] ?? $meta['price'])];
        }
        return $options;
    }

    private static function normalizeMode(string $mode): string
    {
        return array_key_exists($mode, self::MODES) ? $mode : 'standard';
    }

    private static function modeLabel(string $mode): string
    {
        return self::MODES[self::normalizeMode($mode)]['label'];
    }

    private static function normalizeFaceCount(mixed $value): int
    {
        return max(1, min(7, (int)$value));
    }

    private static function normalizeImages(mixed $images): array
    {
        if (is_string($images)) {
            $decoded = json_decode($images, true);
            $images = is_array($decoded) ? $decoded : [$images];
        }
        if (!is_array($images)) {
            return [];
        }
        $normalized = [];
        foreach ($images as $item) {
            $uri = is_array($item) ? (string)($item['uri'] ?? $item['url'] ?? $item['image'] ?? '') : (string)$item;
            $uri = trim($uri);
            if ($uri !== '') {
                $normalized[] = $uri;
            }
        }
        return array_values(array_unique(array_slice($normalized, 0, 3)));
    }

    private static function fileUrl(string $uri): string
    {
        $uri = trim($uri);
        if ($uri === '') {
            return '';
        }
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            return $uri;
        }
        return FileService::getFileUrl($uri);
    }

    private static function assertOnlineUrl(string $url, string $label): void
    {
        if ($url === '' || preg_match('/^(blob:|data:)/i', $url)) {
            throw new Exception($label . '请先上传到素材库');
        }
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            throw new Exception($label . '不能使用本地地址，请配置可公网访问的文件域名或对象存储');
        }
        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
            throw new Exception($label . '地址无效');
        }
    }

    private static function sourceSn(int $taskId): string
    {
        return self::APP_CODE . '-' . $taskId;
    }
}
