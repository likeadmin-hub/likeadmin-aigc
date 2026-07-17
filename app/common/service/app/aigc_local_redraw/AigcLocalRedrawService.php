<?php

namespace app\common\service\app\aigc_local_redraw;

use app\common\model\app\App;
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\model\app\aigc_local_redraw\AigcLocalRedrawConfig;
use app\common\model\app\aigc_local_redraw\AigcLocalRedrawResult;
use app\common\model\app\aigc_local_redraw\AigcLocalRedrawTask;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\AppRegistryService;
use app\common\service\app\aigc_image\AigcImageAssetService;
use app\common\service\app\aigc_image\AigcImageChannelService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\FileService;
use app\common\service\point\PointService;
use app\common\service\storage\StorageConfigService;
use Exception;

class AigcLocalRedrawService
{
    public const APP_CODE = 'aigc_local_redraw';
    public const IMAGE_APP_CODE = 'aigc_image';

    private const RESULT_SYNC_ERROR = '局部重绘结果同步失败，请稍后重试';
    private const LEGACY_PROMPT_TEMPLATE = '基于用户上传的原图和蒙版区域进行局部重绘。只重绘蒙版覆盖区域，严格保留未遮罩区域的主体结构、构图、光影、材质、透视和画面风格。重绘内容需要符合用户描述：{prompt}。输出自然、边缘融合平滑、无明显涂抹痕迹的高质量图片。';
    private const DEFAULT_PROMPT_TEMPLATE = '基于用户上传的原图进行图生图局部重绘。参考用户绘制的蒙版标记意图，按用户描述优先调整对应局部区域，并尽量保持其他区域的主体结构、构图、光影、材质、透视和画面风格稳定。重绘内容需要符合用户描述：{prompt}。输出自然、边缘融合平滑、无明显涂抹痕迹的高质量图片。';
    private const LEGACY_NEGATIVE_PROMPT = '非蒙版区域变化，主体变形，边缘破损，涂抹痕迹，拼接痕迹，水印，文字，低清晰度，模糊，畸变，色偏';
    private const DEFAULT_NEGATIVE_PROMPT = '主体变形，结构错误，边缘破损，涂抹痕迹，拼接痕迹，水印，文字，低清晰度，模糊，畸变，色偏';

    public static function config(int $tenantId): array
    {
        $row = AigcLocalRedrawConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $data = $row->isEmpty() ? self::defaults() : array_merge(self::defaults(), $row->toArray());
        $data = self::sanitizeConfig($data);
        $optionConfig = AigcImageChannelService::userConfig($tenantId);
        $data['option_config'] = $optionConfig;
        $data['spec_options'] = self::buildSpecOptions($optionConfig);
        $data['dependencies'] = self::dependencies($tenantId);
        if ($row->isEmpty()) {
            self::saveConfigSnapshot($tenantId, $data, $row);
        }
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, $data);
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        $current = self::config($tenantId);
        $configJson = is_array($params['config_json'] ?? null) ? $params['config_json'] : ($current['config_json'] ?? []);
        $data = [
            'tenant_id' => $tenantId,
            'status' => array_key_exists('status', $params) ? (int)$params['status'] : (int)$current['status'],
            'default_channel' => self::normalizeCode((string)($params['default_channel'] ?? $configJson['channel'] ?? $current['default_channel'] ?? '')),
            'default_quality' => trim((string)($params['default_quality'] ?? $configJson['quality'] ?? $current['default_quality'] ?? '')),
            'default_ratio' => trim((string)($params['default_ratio'] ?? $configJson['ratio'] ?? $current['default_ratio'] ?? '')),
            'unit_price' => round(max(0, (float)($params['unit_price'] ?? $current['unit_price'] ?? 0)), 2),
            'prompt_template' => self::normalizeTemplate((string)($params['prompt_template'] ?? $current['prompt_template'])),
            'negative_prompt' => self::normalizeNegativePrompt((string)($params['negative_prompt'] ?? $current['negative_prompt'])),
            'config_json' => self::normalizeConfigJson($configJson),
            'update_time' => time(),
        ];
        $row = AigcLocalRedrawConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcLocalRedrawConfig::create($data);
            return;
        }
        $row->save($data);
    }

    public static function estimate(int $tenantId, array $params): array
    {
        self::assertAvailable($tenantId);
        $prepared = self::prepareGeneratePayload($tenantId, $params, true);
        $imageEstimate = AigcImageService::estimate($tenantId, $prepared['image_payload']);
        return self::buildEstimate($prepared, $imageEstimate);
    }

    public static function generate(int $tenantId, int $userId, array $params): array
    {
        self::assertAvailable($tenantId);
        if (isset($params['mask_image']) && str_starts_with((string)$params['mask_image'], 'data:image/')) {
            $stored = AigcImageAssetService::persistGeneratedImage((string)$params['mask_image'], $tenantId, $userId);
            $params['mask_image'] = (string)($stored['uri'] ?? '');
        }
        $prepared = self::prepareGeneratePayload($tenantId, $params, true);
        $imageEstimate = AigcImageService::estimate($tenantId, $prepared['image_payload']);
        $estimate = self::buildEstimate($prepared, $imageEstimate);
        PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);
        $imageResult = AigcImageService::generateWithBillingOverride($tenantId, $userId, $prepared['image_payload'], [
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
        ]);
        $imageTaskId = (int)($imageResult['task_id'] ?? 0);
        if ($imageTaskId <= 0) {
            throw new Exception('局部重绘任务创建失败');
        }
        $task = self::upsertTaskFromImageTask($tenantId, $userId, $imageTaskId, $prepared, $estimate);
        self::syncTaskFromImageTask($task);
        return [
            'task_id' => (int)$task['id'],
            'image_task_id' => $imageTaskId,
            'status' => (string)($task['status'] ?: 'running'),
            'error' => (string)($task['error'] ?? ''),
            'results' => self::taskDetail($tenantId, (int)$task['id'], $userId)['results'] ?? [],
            'estimate' => $estimate,
        ];
    }

    public static function taskLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        self::refreshMappedTasks($tenantId, $userId);
        $query = AigcLocalRedrawTask::alias('t')
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
        $rows = self::appendTaskResults($tenantId, $userId, $rows);
        return [
            'lists' => array_map([self::class, 'formatTaskRow'], $rows),
            'count' => $count,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ];
    }

    public static function taskDetail(int $tenantId, int $taskId, int $userId = 0): array
    {
        self::refreshMappedTasks($tenantId, $userId, $taskId);
        $query = AigcLocalRedrawTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        $rows = self::appendTaskResults($tenantId, $userId, [$task->toArray()]);
        return self::formatTaskRow($rows[0] ?? []);
    }

    public static function resultLists(int $tenantId, int $userId, array $params = []): array
    {
        $params['status'] = $params['status'] ?? 'success';
        return self::taskLists($tenantId, $userId, $params);
    }

    public static function retryTask(int $tenantId, int $taskId): array
    {
        $task = AigcLocalRedrawTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return self::generate($tenantId, (int)$task['user_id'], [
            'source_image' => $task['source_image'],
            'mask_image' => $task['mask_image'],
            'prompt' => $task['user_prompt'],
        ]);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = AigcLocalRedrawTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $task = $query->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        foreach (self::taskImageIds($task->toArray()) as $imageTaskId) {
            AigcImageService::deleteTask($tenantId, $imageTaskId, $userId);
        }
        $task->save(['delete_time' => time(), 'update_time' => time()]);
        AigcLocalRedrawResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time()]);
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = AigcLocalRedrawResult::where(['tenant_id' => $tenantId, 'id' => $resultId]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('作品不存在');
        }
        $row->save(['delete_time' => time()]);
    }

    public static function dependencies(int $tenantId = 0): array
    {
        $installed = App::where(['code' => self::IMAGE_APP_CODE, 'status' => AppRegistryService::STATUS_INSTALLED])->count() > 0;
        $tenantEnabled = $tenantId <= 0 ? true : AppAccessService::tenantCanUse($tenantId, self::IMAGE_APP_CODE);
        $channels = [];
        try {
            $channels = AigcImageService::config($tenantId)['option_config']['channels'] ?? [];
        } catch (Exception) {
            $channels = [];
        }
        $item = [
            'app_code' => self::IMAGE_APP_CODE,
            'name' => 'AIGC生图',
            'required_for' => '局部重绘生成',
            'installed' => $installed,
            'tenant_enabled' => $tenantEnabled,
            'channel_ready' => !empty($channels),
            'ready' => $installed && $tenantEnabled && !empty($channels),
            'message' => $installed ? ($tenantEnabled ? (!empty($channels) ? '可用' : '暂无可用生图通道') : '租户未开通或未上架') : '应用未安装或未启用',
        ];
        return ['items' => [$item], 'ready' => (bool)$item['ready']];
    }

    private static function assertAvailable(int $tenantId): void
    {
        if (AppAccessService::assertTenantCanUse($tenantId, self::APP_CODE) !== null) {
            throw new Exception('局部重绘应用未开通或未上架');
        }
        if (AppAccessService::assertTenantCanUse($tenantId, self::IMAGE_APP_CODE) !== null) {
            throw new Exception('AIGC生图应用未开通或未上架');
        }
        $config = self::config($tenantId);
        if ((int)($config['status'] ?? 1) !== 1) {
            throw new Exception('局部重绘应用已停用');
        }
    }

    private static function prepareGeneratePayload(int $tenantId, array $params, bool $require): array
    {
        $config = self::config($tenantId);
        $sourceImage = self::normalizeImage($params['source_image'] ?? $params['image'] ?? '');
        $maskImage = self::normalizeImage($params['mask_image'] ?? $params['mask_url'] ?? '');
        $userPrompt = trim((string)($params['prompt'] ?? $params['description'] ?? ''));
        if ($require && $sourceImage === '') {
            throw new Exception('请上传原图');
        }
        if ($require && $maskImage === '') {
            throw new Exception('请绘制蒙版');
        }
        if ($require && $userPrompt === '') {
            throw new Exception('请输入局部重绘描述词');
        }
        $channel = trim((string)($params['channel'] ?? ''));
        $quality = trim((string)($params['quality'] ?? ''));
        $ratio = trim((string)($params['ratio'] ?? ''));
        if ($channel === '') {
            $channel = (string)($config['default_channel'] ?: ($config['config_json']['channel'] ?? ''));
        }
        if ($quality === '') {
            $quality = (string)($config['default_quality'] ?: ($config['config_json']['quality'] ?? ''));
        }
        if ($ratio === '') {
            $ratio = (string)($config['default_ratio'] ?: ($config['config_json']['ratio'] ?? ''));
        }
        $prompt = self::buildPrompt((string)$config['prompt_template'], $userPrompt);
        $imagePayload = [
            'prompt' => $prompt,
            'negative_prompt' => trim((string)($params['negative_prompt'] ?? '')),
            'reference_images' => array_values(array_filter([$sourceImage])),
            'mask_url' => $maskImage,
            'channel' => $channel,
            'quality' => $quality,
            'ratio' => $ratio,
            'quantity' => 1,
            'style' => 'local_redraw',
        ];
        $resolved = AigcImageChannelService::resolveSelection($tenantId, $imagePayload);
        return [
            'source_image' => $sourceImage,
            'mask_image' => $maskImage,
            'user_prompt' => $userPrompt,
            'unit_price' => round(max(0, (float)($config['unit_price'] ?? 0)), 2),
            'image_payload' => array_merge($imagePayload, [
                'channel' => (string)$resolved['channel']['code'],
                'quality' => (string)$resolved['spec']['quality'],
                'ratio' => (string)$resolved['spec']['ratio'],
            ]),
            'width' => (int)$resolved['spec']['width'],
            'height' => (int)$resolved['spec']['height'],
            'quality_label' => (string)($resolved['spec']['quality_label'] ?? $resolved['spec']['quality']),
            'size_key' => (string)$resolved['spec']['ratio'],
        ];
    }

    private static function buildPrompt(string $template, string $userPrompt): string
    {
        $template = self::normalizeTemplate($template);
        if (str_contains($template, '{prompt}')) {
            return trim(str_replace('{prompt}', $userPrompt, $template));
        }
        return trim($template . "\n局部重绘描述：" . $userPrompt);
    }

    private static function buildEstimate(array $prepared, array $imageEstimate): array
    {
        $platformUnitCost = round((float)($imageEstimate['platform_unit_cost'] ?? 0), 2);
        $userUnitPrice = round((float)$prepared['unit_price'], 2);
        return array_merge($imageEstimate, [
            'quantity' => 1,
            'target_width' => $prepared['width'],
            'target_height' => $prepared['height'],
            'size_key' => $prepared['size_key'],
            'platform_unit_cost' => $platformUnitCost,
            'tenant_unit_price' => $userUnitPrice,
            'unit_price' => $userUnitPrice,
            'tenant_cost_points' => $platformUnitCost,
            'user_charge_points' => $userUnitPrice,
            'display_points' => $userUnitPrice,
        ]);
    }

    private static function upsertTaskFromImageTask(int $tenantId, int $userId, int $imageTaskId, array $prepared, array $estimate): AigcLocalRedrawTask
    {
        $imageTask = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => $imageTaskId])->findOrEmpty();
        if ($imageTask->isEmpty()) {
            throw new Exception('生图任务不存在');
        }
        $row = AigcLocalRedrawTask::where(['tenant_id' => $tenantId, 'image_task_id' => $imageTaskId])->findOrEmpty();
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'image_task_id' => $imageTaskId,
            'image_task_ids' => [$imageTaskId],
            'source_image' => $prepared['source_image'],
            'mask_image' => $prepared['mask_image'],
            'user_prompt' => $prepared['user_prompt'],
            'size_key' => $prepared['size_key'],
            'width' => $prepared['width'],
            'height' => $prepared['height'],
            'prompt' => $prepared['image_payload']['prompt'],
            'negative_prompt' => $prepared['image_payload']['negative_prompt'],
            'channel' => (string)$imageTask['channel'],
            'quality' => (string)$imageTask['quality'],
            'quality_label' => $prepared['quality_label'],
            'ratio' => (string)$imageTask['ratio'],
            'quantity' => 1,
            'unit_price' => $prepared['unit_price'],
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
            'status' => (string)($imageTask['status'] ?: 'running'),
            'error' => (string)$imageTask['error'],
            'finish_time' => (int)$imageTask['finish_time'],
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $data['delete_time'] = 0;
            $data['create_time'] = time();
            return AigcLocalRedrawTask::create($data);
        }
        $row->save($data);
        return $row;
    }

    private static function syncTaskFromImageTask(AigcLocalRedrawTask $task): void
    {
        $imageTaskIds = self::taskImageIds($task->toArray());
        if (!$imageTaskIds) {
            return;
        }
        $tenantId = (int)$task['tenant_id'];
        $userId = (int)$task['user_id'];
        foreach ($imageTaskIds as $imageTaskId) {
            try {
                AigcImageService::taskDetail($tenantId, $imageTaskId, $userId);
            } catch (\Throwable) {
            }
        }
        $imageTasks = AigcImageTask::where('tenant_id', $tenantId)->whereIn('id', $imageTaskIds)->where('delete_time', 0)->select()->toArray();
        if (!$imageTasks) {
            return;
        }
        $statuses = array_map(static fn($row) => (string)($row['status'] ?? ''), $imageTasks);
        if (in_array('failed', $statuses, true)) {
            $task->status = 'failed';
        } elseif (in_array('canceled', $statuses, true)) {
            $task->status = 'canceled';
        } elseif (count(array_filter($statuses, static fn($status) => $status === 'success')) === count($imageTaskIds)) {
            $task->status = 'success';
        } elseif (in_array('pending', $statuses, true)) {
            $task->status = 'pending';
        } else {
            $task->status = 'running';
        }
        $errors = array_values(array_filter(array_map(static fn($row) => trim((string)($row['error'] ?? '')), $imageTasks)));
        $finishTimes = array_filter(array_map(static fn($row) => (int)($row['finish_time'] ?? 0), $imageTasks));
        $task->error = implode('；', array_unique($errors));
        $task->finish_time = in_array((string)$task->status, ['success', 'failed', 'canceled'], true) ? max($finishTimes ?: [time()]) : 0;
        $task->tenant_cost_points = number_format(array_sum(array_map(static fn($row) => (float)($row['tenant_cost_points'] ?? 0), $imageTasks)), 2, '.', '');
        $task->user_charge_points = number_format(array_sum(array_map(static fn($row) => (float)($row['user_charge_points'] ?? 0), $imageTasks)), 2, '.', '');
        $task->update_time = time();
        $task->save();
        self::syncResultsFromImageTask($task);
    }

    private static function syncResultsFromImageTask(AigcLocalRedrawTask $task): void
    {
        if ((string)$task['status'] !== 'success') {
            return;
        }
        $tenantId = (int)$task['tenant_id'];
        $userId = (int)$task['user_id'];
        foreach (self::taskImageIds($task->toArray()) as $imageTaskId) {
            try {
                $imageTask = AigcImageService::taskDetail($tenantId, $imageTaskId, $userId);
            } catch (\Throwable) {
                continue;
            }
            foreach (($imageTask['results'] ?? []) as $result) {
                $imageResultId = (int)($result['id'] ?? 0);
                $imageUri = (string)($result['image_uri'] ?? '');
                if ($imageResultId <= 0 || $imageUri === '') {
                    continue;
                }
                $exists = AigcLocalRedrawResult::where(['tenant_id' => $tenantId, 'image_result_id' => $imageResultId])->findOrEmpty();
                if (!$exists->isEmpty()) {
                    continue;
                }
                AigcLocalRedrawResult::create([
                    'tenant_id' => $tenantId,
                    'task_id' => (int)$task['id'],
                    'image_task_id' => $imageTaskId,
                    'image_result_id' => $imageResultId,
                    'user_id' => $userId,
                    'image_uri' => $imageUri,
                    'storage_scope' => (string)($result['storage_scope'] ?? 'tenant'),
                    'storage_engine' => (string)($result['storage_engine'] ?? 'local'),
                    'storage_domain' => (string)($result['storage_domain'] ?? StorageConfigService::getEffectiveDomain($tenantId)),
                    'width' => (int)$task['width'] ?: (int)($result['width'] ?? 0),
                    'height' => (int)$task['height'] ?: (int)($result['height'] ?? 0),
                    'delete_time' => 0,
                    'create_time' => time(),
                ]);
            }
        }
        $hasResult = AigcLocalRedrawResult::where(['tenant_id' => $tenantId, 'task_id' => (int)$task['id']])->where('delete_time', 0)->count() > 0;
        if (!$hasResult) {
            $task->save([
                'status' => 'failed',
                'error' => self::RESULT_SYNC_ERROR,
                'finish_time' => time(),
                'update_time' => time(),
            ]);
        }
    }

    private static function refreshMappedTasks(int $tenantId, int $userId = 0, int $taskId = 0): void
    {
        $query = AigcLocalRedrawTask::where('tenant_id', $tenantId)->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($taskId > 0) {
            $query->where('id', $taskId);
        } else {
            $query->whereIn('status', ['running', 'pending', 'success']);
        }
        foreach ($query->limit(20)->select() as $row) {
            self::syncTaskFromImageTask($row);
        }
    }

    private static function appendTaskResults(int $tenantId, int $userId, array $rows): array
    {
        $taskIds = array_values(array_unique(array_filter(array_column($rows, 'id'))));
        $resultMap = [];
        if ($taskIds) {
            $query = AigcLocalRedrawResult::where('tenant_id', $tenantId)
                ->where('delete_time', 0)
                ->whereIn('task_id', $taskIds)
                ->order('id', 'asc');
            if ($userId > 0) {
                $query->where('user_id', $userId);
            }
            foreach ($query->select()->toArray() as $result) {
                $result['image_url'] = FileService::getFileUrlByStorage(
                    $result['image_uri'],
                    $result['storage_scope'] ?? '',
                    $result['storage_engine'] ?? '',
                    $result['storage_domain'] ?? ''
                );
                $result['download_url'] = $result['image_url'];
                $resultMap[(int)$result['task_id']][] = $result;
            }
        }
        foreach ($rows as &$row) {
            $results = $resultMap[(int)$row['id']] ?? [];
            $row['results'] = $results;
            $row['result_count'] = count($results);
            $first = $results[0] ?? [];
            $row['image_url'] = (string)($first['image_url'] ?? '');
            $row['download_url'] = (string)($first['download_url'] ?? '');
            $row['image_uri'] = (string)($first['image_uri'] ?? '');
            $row['source_image_url'] = self::imageUrl((string)($row['source_image'] ?? ''));
            $row['mask_image_url'] = self::imageUrl((string)($row['mask_image'] ?? ''));
        }
        return $rows;
    }

    private static function formatTaskRow(array $row): array
    {
        $row['task_id'] = (int)($row['id'] ?? 0);
        $row['image_task_id'] = (int)($row['image_task_id'] ?? 0);
        $row['image_task_ids'] = self::taskImageIds($row);
        $row['size_label'] = self::sizeLabel((string)($row['quality_label'] ?? $row['quality'] ?? ''), (string)($row['ratio'] ?? ''), (int)($row['width'] ?? 0), (int)($row['height'] ?? 0));
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
            'default_channel' => '',
            'default_quality' => '',
            'default_ratio' => '',
            'unit_price' => 0,
            'prompt_template' => self::DEFAULT_PROMPT_TEMPLATE,
            'negative_prompt' => self::DEFAULT_NEGATIVE_PROMPT,
            'config_json' => [],
        ];
    }

    private static function sanitizeConfig(array $data): array
    {
        $data['status'] = (int)($data['status'] ?? 1);
        $data['default_channel'] = self::normalizeCode((string)($data['default_channel'] ?? ''));
        $data['default_quality'] = trim((string)($data['default_quality'] ?? ''));
        $data['default_ratio'] = trim((string)($data['default_ratio'] ?? ''));
        $data['unit_price'] = round(max(0, (float)($data['unit_price'] ?? 0)), 2);
        $data['prompt_template'] = self::normalizeTemplate((string)($data['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE));
        $data['negative_prompt'] = self::normalizeNegativePrompt((string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT));
        $data['config_json'] = is_array($data['config_json'] ?? null) ? self::normalizeConfigJson($data['config_json']) : [];
        $data['config_json']['channel'] = $data['default_channel'] ?: ($data['config_json']['channel'] ?? '');
        $data['config_json']['quality'] = $data['default_quality'] ?: ($data['config_json']['quality'] ?? '');
        $data['config_json']['ratio'] = $data['default_ratio'] ?: ($data['config_json']['ratio'] ?? '');
        return $data;
    }

    private static function normalizeConfigJson(array $config): array
    {
        return [
            'channel' => self::normalizeCode((string)($config['channel'] ?? '')),
            'quality' => trim((string)($config['quality'] ?? '')),
            'ratio' => trim((string)($config['ratio'] ?? '')),
        ];
    }

    private static function buildSpecOptions(array $optionConfig): array
    {
        $channels = [];
        foreach (($optionConfig['channels'] ?? []) as $channel) {
            $qualities = [];
            foreach (($channel['qualities'] ?? []) as $quality) {
                $qualities[] = [
                    'value' => (string)($quality['value'] ?? ''),
                    'label' => (string)($quality['label'] ?? $quality['quality_label'] ?? $quality['value'] ?? ''),
                    'ratios' => array_map(static fn($ratio) => [
                        'value' => (string)($ratio['value'] ?? $ratio['ratio'] ?? ''),
                        'label' => (string)($ratio['label'] ?? $ratio['ratio'] ?? $ratio['value'] ?? ''),
                        'ratio' => (string)($ratio['ratio'] ?? $ratio['value'] ?? ''),
                        'width' => (int)($ratio['width'] ?? 0),
                        'height' => (int)($ratio['height'] ?? 0),
                        'platform_unit_cost' => round((float)($ratio['platform_unit_cost'] ?? 0), 2),
                    ], $quality['ratios'] ?? []),
                ];
            }
            $channels[] = [
                'code' => (string)$channel['code'],
                'name' => (string)$channel['name'],
                'qualities' => $qualities,
            ];
        }
        return $channels;
    }

    private static function saveConfigSnapshot(int $tenantId, array $data, AigcLocalRedrawConfig $row): void
    {
        $payload = [
            'tenant_id' => $tenantId,
            'status' => (int)($data['status'] ?? 1),
            'default_channel' => (string)($data['default_channel'] ?? ''),
            'default_quality' => (string)($data['default_quality'] ?? ''),
            'default_ratio' => (string)($data['default_ratio'] ?? ''),
            'unit_price' => round(max(0, (float)($data['unit_price'] ?? 0)), 2),
            'prompt_template' => (string)($data['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE),
            'negative_prompt' => self::normalizeNegativePrompt((string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT)),
            'config_json' => self::normalizeConfigJson($data['config_json'] ?? []),
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $payload['create_time'] = time();
            AigcLocalRedrawConfig::create($payload);
            return;
        }
        $row->save($payload);
    }

    private static function taskImageIds(array $task): array
    {
        $ids = [];
        if (!empty($task['image_task_ids']) && is_array($task['image_task_ids'])) {
            $ids = array_map('intval', $task['image_task_ids']);
        }
        if (!empty($task['image_task_id'])) {
            $ids[] = (int)$task['image_task_id'];
        }
        return array_values(array_unique(array_filter($ids)));
    }

    private static function imageUrl(string $uri): string
    {
        if ($uri === '') {
            return '';
        }
        if (preg_match('/^(https?:|data:|blob:)/', $uri)) {
            return $uri;
        }
        return FileService::getFileUrl($uri);
    }

    private static function sizeLabel(string $qualityLabel, string $ratio, int $width, int $height): string
    {
        $meta = [];
        if ($qualityLabel !== '') {
            $meta[] = $qualityLabel;
        }
        if ($ratio !== '') {
            $meta[] = $ratio;
        }
        if ($width > 0 && $height > 0) {
            $meta[] = $width . '*' . $height;
        }
        return implode(' ', $meta);
    }

    private static function normalizeImage(mixed $value): string
    {
        return trim((string)$value);
    }

    private static function normalizeTemplate(string $template): string
    {
        $template = trim($template);
        if ($template === self::LEGACY_PROMPT_TEMPLATE) {
            return self::DEFAULT_PROMPT_TEMPLATE;
        }
        return $template !== '' ? $template : self::DEFAULT_PROMPT_TEMPLATE;
    }

    private static function normalizeNegativePrompt(string $prompt): string
    {
        $prompt = trim($prompt);
        if ($prompt === self::LEGACY_NEGATIVE_PROMPT) {
            return self::DEFAULT_NEGATIVE_PROMPT;
        }
        return $prompt !== '' ? $prompt : self::DEFAULT_NEGATIVE_PROMPT;
    }

    private static function normalizeCode(string $code): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($code)) ?: '';
    }
}
