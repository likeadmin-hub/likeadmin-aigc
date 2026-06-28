<?php

namespace app\common\service\app\aigc_outpaint;

use app\common\model\app\App;
use app\common\model\app\aigc_outpaint\AigcOutpaintConfig;
use app\common\model\app\aigc_outpaint\AigcOutpaintResult;
use app\common\model\app\aigc_outpaint\AigcOutpaintTask;
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\AppRegistryService;
use app\common\service\app\aigc_image\AigcImageChannelService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\FileService;
use app\common\service\point\PointService;
use app\common\service\storage\StorageConfigService;
use Exception;

class AigcOutpaintService
{
    public const APP_CODE = 'aigc_outpaint';
    public const IMAGE_APP_CODE = 'aigc_image';

    private const RESULT_SYNC_ERROR = '无缝扩图结果同步失败，请稍后重试';
    private const DEFAULT_PROMPT_TEMPLATE = '基于用户上传的图片进行无缝扩图，保持主体、构图、光影、材质和透视一致，向画面外自然延展内容，生成边缘连续、结构合理、无明显拼接痕迹的高质量图片。';
    private const DEFAULT_NEGATIVE_PROMPT = '边缘断裂，拼接痕迹，主体变形，透视错误，重复主体，水印，文字，低清晰度，模糊，畸变，色彩断层';

    public static function config(int $tenantId): array
    {
        $row = AigcOutpaintConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $data = $row->isEmpty() ? self::defaults() : array_merge(self::defaults(), $row->toArray());
        $data = self::sanitizeConfig($data);
        $optionConfig = AigcImageChannelService::userConfig($tenantId);
        [$data['price_config'], $priceChanged] = self::ensurePricePackages($data['price_config'], $optionConfig);
        if ($priceChanged) {
            self::saveConfigSnapshot($tenantId, $data, $row);
        }
        $data['option_config'] = $optionConfig;
        $data['price_packages'] = self::buildPricePackages($optionConfig, $data['price_config']);
        $data['price_options'] = $data['price_packages'];
        $data['dependencies'] = self::dependencies($tenantId);
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, $data);
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        $current = self::config($tenantId);
        $data = [
            'tenant_id' => $tenantId,
            'status' => array_key_exists('status', $params) ? (int)$params['status'] : (int)$current['status'],
            'default_channel' => '',
            'default_quality' => '',
            'default_ratio' => '',
            'prompt_template' => self::normalizeTemplate((string)($params['prompt_template'] ?? $current['prompt_template'])),
            'negative_prompt' => trim((string)($params['negative_prompt'] ?? $current['negative_prompt'])),
            'price_config' => self::normalizePriceConfig($params['price_config'] ?? $current['price_config'] ?? []),
            'config_json' => [],
            'update_time' => time(),
        ];
        $row = AigcOutpaintConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcOutpaintConfig::create($data);
            return;
        }
        $row->save($data);
    }

    public static function priceDetail(int $tenantId): array
    {
        $config = self::config($tenantId);
        return [
            'channels' => self::buildPackageSourceOptions($config['option_config'] ?? []),
            'packages' => $config['price_packages'],
            'price_config' => $config['price_config'],
        ];
    }

    public static function savePrice(int $tenantId, array $params): void
    {
        $current = self::config($tenantId);
        self::saveConfig($tenantId, [
            'status' => $current['status'],
            'default_channel' => '',
            'default_quality' => '',
            'default_ratio' => '',
            'prompt_template' => $current['prompt_template'],
            'negative_prompt' => $current['negative_prompt'],
            'price_config' => self::normalizePriceConfig($params['price_config'] ?? $params['packages'] ?? $params['items'] ?? []),
            'config_json' => [],
        ]);
    }

    public static function estimate(int $tenantId, array $params): array
    {
        self::assertAvailable($tenantId);
        $prepared = self::prepareGeneratePayload($tenantId, $params, false);
        $imageEstimate = AigcImageService::estimate($tenantId, $prepared['image_payload']);
        return self::buildEstimate($prepared, $imageEstimate);
    }

    public static function generate(int $tenantId, int $userId, array $params): array
    {
        self::assertAvailable($tenantId);
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
            throw new Exception('无缝扩图任务创建失败');
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
        $query = AigcOutpaintTask::alias('t')
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
        $query = AigcOutpaintTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
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
        $task = AigcOutpaintTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return self::generate($tenantId, (int)$task['user_id'], [
            'source_image' => $task['source_image'],
            'price_package_code' => $task['price_package_code'],
        ]);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = AigcOutpaintTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
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
        AigcOutpaintResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time()]);
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = AigcOutpaintResult::where(['tenant_id' => $tenantId, 'id' => $resultId]);
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
            $imageConfig = AigcImageService::config($tenantId);
            $channels = $imageConfig['option_config']['channels'] ?? [];
        } catch (Exception) {
            $channels = [];
        }
        $item = [
            'app_code' => self::IMAGE_APP_CODE,
            'name' => 'AIGC生图',
            'required_for' => '无缝扩图生成',
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
            throw new Exception('无缝扩图应用未开通或未上架');
        }
        if (AppAccessService::assertTenantCanUse($tenantId, self::IMAGE_APP_CODE) !== null) {
            throw new Exception('AIGC生图应用未开通或未上架');
        }
        $config = self::config($tenantId);
        if ((int)($config['status'] ?? 1) !== 1) {
            throw new Exception('无缝扩图应用已停用');
        }
    }

    private static function prepareGeneratePayload(int $tenantId, array $params, bool $requireImage): array
    {
        $config = self::config($tenantId);
        $sourceImage = self::normalizeImage($params['source_image'] ?? $params['image'] ?? '');
        if ($requireImage && $sourceImage === '') {
            throw new Exception('请上传待扩图图片');
        }
        $package = self::resolvePricePackage($config['price_config'], $params, $config);
        $channel = (string)$package['channel'];
        $quality = (string)$package['quality'];
        $ratio = (string)$package['ratio'];
        $imagePayload = [
            'prompt' => self::normalizeTemplate((string)$config['prompt_template']),
            'negative_prompt' => (string)($params['negative_prompt'] ?? $config['negative_prompt']),
            'reference_images' => array_values(array_filter([$sourceImage])),
            'channel' => $channel,
            'quality' => $quality,
            'ratio' => $ratio,
            'quantity' => 1,
            'style' => 'outpaint',
        ];
        $resolved = AigcImageChannelService::resolveSelection($tenantId, $imagePayload);
        return [
            'price_package' => $package,
            'source_image' => $sourceImage,
            'unit_price' => round(max(0, (float)$package['unit_price']), 2),
            'image_payload' => array_merge($imagePayload, [
                'channel' => (string)$resolved['channel']['code'],
                'quality' => (string)$resolved['spec']['quality'],
                'ratio' => (string)$resolved['spec']['ratio'],
            ]),
            'width' => (int)$resolved['spec']['width'],
            'height' => (int)$resolved['spec']['height'],
            'quality_label' => (string)($package['quality_label'] ?: $resolved['spec']['quality_label']),
            'size_key' => (string)$resolved['spec']['ratio'],
        ];
    }

    private static function buildEstimate(array $prepared, array $imageEstimate): array
    {
        $tenantUnitCost = (float)($imageEstimate['platform_unit_cost'] ?? 0);
        $userUnitPrice = (float)$prepared['unit_price'];
        return array_merge($imageEstimate, [
            'quantity' => 1,
            'target_width' => $prepared['width'],
            'target_height' => $prepared['height'],
            'size_key' => $prepared['size_key'],
            'price_package' => $prepared['price_package'],
            'price_package_code' => $prepared['price_package']['code'] ?? '',
            'price_package_name' => $prepared['price_package']['name'] ?? '',
            'platform_unit_cost' => round($tenantUnitCost, 2),
            'tenant_unit_price' => round($userUnitPrice, 2),
            'tenant_cost_points' => round($tenantUnitCost, 2),
            'user_charge_points' => round($userUnitPrice, 2),
            'display_points' => round($userUnitPrice, 2),
        ]);
    }

    private static function upsertTaskFromImageTask(int $tenantId, int $userId, int $imageTaskId, array $prepared, array $estimate): AigcOutpaintTask
    {
        $imageTask = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => $imageTaskId])->findOrEmpty();
        if ($imageTask->isEmpty()) {
            throw new Exception('生图任务不存在');
        }
        $row = AigcOutpaintTask::where(['tenant_id' => $tenantId, 'image_task_id' => $imageTaskId])->findOrEmpty();
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'image_task_id' => $imageTaskId,
            'image_task_ids' => [$imageTaskId],
            'source_image' => $prepared['source_image'],
            'price_package_code' => (string)($prepared['price_package']['code'] ?? ''),
            'price_package_name' => (string)($prepared['price_package']['name'] ?? ''),
            'price_package_snapshot' => $prepared['price_package'],
            'size_key' => $prepared['size_key'],
            'width' => $prepared['width'],
            'height' => $prepared['height'],
            'prompt' => $prepared['image_payload']['prompt'],
            'negative_prompt' => $prepared['image_payload']['negative_prompt'],
            'channel' => $imageTask['channel'],
            'quality' => $imageTask['quality'],
            'quality_label' => $prepared['quality_label'],
            'ratio' => $imageTask['ratio'],
            'quantity' => 1,
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
            return AigcOutpaintTask::create($data);
        }
        $row->save($data);
        return $row;
    }

    private static function syncTaskFromImageTask(AigcOutpaintTask $task): void
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
        $imageTasks = AigcImageTask::where('tenant_id', $tenantId)
            ->whereIn('id', $imageTaskIds)
            ->where('delete_time', 0)
            ->select()
            ->toArray();
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

    private static function syncResultsFromImageTask(AigcOutpaintTask $task): void
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
                $exists = AigcOutpaintResult::where(['tenant_id' => $tenantId, 'image_result_id' => $imageResultId])->findOrEmpty();
                if (!$exists->isEmpty()) {
                    continue;
                }
                AigcOutpaintResult::create([
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
        $hasResult = AigcOutpaintResult::where(['tenant_id' => $tenantId, 'task_id' => (int)$task['id']])->where('delete_time', 0)->count() > 0;
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
        $query = AigcOutpaintTask::where('tenant_id', $tenantId)->where('delete_time', 0);
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
            $query = AigcOutpaintResult::where('tenant_id', $tenantId)
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
        }
        return $rows;
    }

    private static function formatTaskRow(array $row): array
    {
        $row['task_id'] = (int)($row['id'] ?? 0);
        $row['image_task_id'] = (int)($row['image_task_id'] ?? 0);
        $row['image_task_ids'] = self::taskImageIds($row);
        $row['price_package_code'] = (string)($row['price_package_code'] ?? '');
        $row['price_package_name'] = (string)($row['price_package_name'] ?? '');
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
            'prompt_template' => self::DEFAULT_PROMPT_TEMPLATE,
            'negative_prompt' => self::DEFAULT_NEGATIVE_PROMPT,
            'price_config' => [],
            'config_json' => [],
        ];
    }

    private static function sanitizeConfig(array $data): array
    {
        $data['status'] = (int)($data['status'] ?? 1);
        $data['default_channel'] = '';
        $data['default_quality'] = '';
        $data['default_ratio'] = '';
        $data['prompt_template'] = self::normalizeTemplate((string)($data['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE));
        $data['negative_prompt'] = trim((string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT));
        $data['price_config'] = is_array($data['price_config'] ?? null) ? self::normalizePriceConfig($data['price_config']) : [];
        $data['config_json'] = [];
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

    private static function normalizePriceConfig(mixed $config): array
    {
        $items = [];
        if (!is_array($config)) {
            return [];
        }
        foreach (array_values($config) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $channel = self::normalizeCode((string)($item['channel'] ?? $item['channel_code'] ?? ''));
            $quality = trim((string)($item['quality'] ?? ''));
            $ratio = trim((string)($item['ratio'] ?? $item['ratio_code'] ?? ''));
            if ($channel === '' || $quality === '' || $ratio === '') {
                continue;
            }
            $code = self::normalizePackageCode((string)($item['code'] ?? $item['package_code'] ?? ''));
            if ($code === '') {
                $code = self::normalizePackageCode('spec_' . $channel . '_' . $quality . '_' . str_replace(':', '_', $ratio));
            }
            $items[$code] = [
                'code' => $code,
                'name' => mb_substr(trim((string)($item['name'] ?? $ratio)), 0, 80),
                'channel' => $channel,
                'quality' => $quality,
                'ratio' => $ratio,
                'ratio_label' => mb_substr(trim((string)($item['ratio_label'] ?? $ratio)), 0, 80),
                'quality_label' => mb_substr(trim((string)($item['quality_label'] ?? '')), 0, 80),
                'unit_price' => round(max(0, (float)($item['unit_price'] ?? $item['tenant_unit_price'] ?? 0)), 2),
                'status' => (int)($item['status'] ?? $item['tenant_status'] ?? 1) ? 1 : 0,
                'sort' => (int)($item['sort'] ?? (100 - $index)),
            ];
        }
        return array_values($items);
    }

    private static function buildPricePackages(array $optionConfig, array $priceConfig): array
    {
        $sourceMap = self::packageSourceMap($optionConfig);
        $packages = [];
        $enabledRatios = [];
        $configItems = self::normalizePriceConfig($priceConfig);
        usort($configItems, static fn($left, $right) => ((int)($right['sort'] ?? 0) <=> (int)($left['sort'] ?? 0)) ?: strcmp((string)($left['code'] ?? ''), (string)($right['code'] ?? '')));
        foreach ($configItems as $item) {
            $key = self::priceKey((string)$item['channel'], (string)$item['quality'], (string)$item['ratio']);
            $source = $sourceMap[$key] ?? [];
            if (!$source) {
                continue;
            }
            $status = (int)($item['status'] ?? 1);
            $ratio = (string)$item['ratio'];
            if ($status === 1 && isset($enabledRatios[$ratio])) {
                continue;
            }
            if ($status === 1) {
                $enabledRatios[$ratio] = true;
            }
            $packages[] = [
                'code' => (string)$item['code'],
                'name' => (string)$item['name'],
                'channel' => (string)$item['channel'],
                'channel_name' => (string)($source['channel_name'] ?? $item['channel']),
                'quality' => (string)$item['quality'],
                'quality_label' => (string)($item['quality_label'] ?: ($source['quality_label'] ?? $item['quality'])),
                'ratio' => $ratio,
                'ratio_label' => (string)($item['ratio_label'] ?: ($source['ratio_label'] ?? $item['ratio'])),
                'width' => (int)($source['width'] ?? 0),
                'height' => (int)($source['height'] ?? 0),
                'platform_unit_cost' => round((float)($source['platform_unit_cost'] ?? 0), 2),
                'unit_price' => round((float)$item['unit_price'], 2),
                'status' => $status,
                'sort' => (int)($item['sort'] ?? 0),
            ];
        }
        usort($packages, static fn($left, $right) => ((int)($right['sort'] ?? 0) <=> (int)($left['sort'] ?? 0)) ?: strcmp((string)$left['code'], (string)$right['code']));
        return $packages;
    }

    private static function buildPackageSourceOptions(array $optionConfig): array
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

    private static function resolvePricePackage(array $priceConfig, array $params, array $config): array
    {
        $packages = self::buildPricePackages($config['option_config'] ?? [], $priceConfig);
        $enabled = array_values(array_filter($packages, static fn($item) => (int)($item['status'] ?? 1) === 1));
        if (!$enabled) {
            throw new Exception('请先配置可用扩图比例');
        }
        $code = self::normalizePackageCode((string)($params['spec_code'] ?? $params['price_package_code'] ?? $params['price_package'] ?? $params['package_code'] ?? ''));
        if ($code !== '') {
            foreach ($enabled as $item) {
                if ((string)$item['code'] === $code) {
                    return $item;
                }
            }
        }
        $ratio = trim((string)($params['ratio_code'] ?? $params['ratio'] ?? ''));
        if ($ratio !== '') {
            foreach ($enabled as $item) {
                if ((string)$item['ratio'] === $ratio) {
                    return $item;
                }
            }
        }
        return $enabled[0];
    }

    private static function ensurePricePackages(array $priceConfig, array $optionConfig): array
    {
        $normalized = self::normalizePriceConfig($priceConfig);
        $sourceMap = self::packageSourceMap($optionConfig);
        $packages = [];
        foreach ($normalized as $item) {
            $key = self::priceKey((string)$item['channel'], (string)$item['quality'], (string)$item['ratio']);
            if (!isset($sourceMap[$key])) {
                continue;
            }
            $source = $sourceMap[$key];
            $item['quality_label'] = (string)($item['quality_label'] ?: ($source['quality_label'] ?? $item['quality']));
            $item['ratio_label'] = (string)($item['ratio_label'] ?: ($source['ratio_label'] ?? $item['ratio']));
            $packages[] = $item;
        }
        $packages = self::dedupeEnabledRatios($packages);
        if ($packages) {
            $packages = array_values($packages);
            return [$packages, json_encode($packages, JSON_UNESCAPED_UNICODE) !== json_encode(array_values($normalized), JSON_UNESCAPED_UNICODE)];
        }
        $sourceItems = array_values($sourceMap);
        if (!$sourceItems) {
            return [[], false];
        }
        $usedRatios = [];
        foreach ($sourceItems as $index => $source) {
            $ratio = (string)($source['ratio'] ?? '');
            if ($ratio === '' || isset($usedRatios[$ratio])) {
                continue;
            }
            $usedRatios[$ratio] = true;
            $packages[] = [
                'code' => self::normalizePackageCode('default_' . str_replace(':', '_', $ratio) . '_' . ($index + 1)),
                'name' => (string)($source['ratio_label'] ?? $ratio),
                'channel' => (string)$source['channel'],
                'quality' => (string)$source['quality'],
                'ratio' => $ratio,
                'ratio_label' => (string)$source['ratio_label'],
                'quality_label' => (string)$source['quality_label'],
                'unit_price' => round(max(0, (float)$source['platform_unit_cost']), 2),
                'status' => 1,
                'sort' => 100 - $index,
            ];
            if (count($packages) >= 5) {
                break;
            }
        }
        return [$packages, true];
    }

    private static function dedupeEnabledRatios(array $packages): array
    {
        usort($packages, static fn($left, $right) => ((int)($right['sort'] ?? 0) <=> (int)($left['sort'] ?? 0)) ?: strcmp((string)($left['code'] ?? ''), (string)($right['code'] ?? '')));
        $seen = [];
        foreach ($packages as &$item) {
            $ratio = (string)($item['ratio'] ?? '');
            if ((int)($item['status'] ?? 1) === 1 && $ratio !== '') {
                if (isset($seen[$ratio])) {
                    $item['status'] = 0;
                } else {
                    $seen[$ratio] = true;
                }
            }
        }
        unset($item);
        return $packages;
    }

    private static function packageSourceMap(array $optionConfig): array
    {
        $map = [];
        foreach (($optionConfig['channels'] ?? []) as $channel) {
            foreach (($channel['qualities'] ?? []) as $quality) {
                $qualityValue = (string)($quality['value'] ?? '');
                if ($qualityValue === '') {
                    continue;
                }
                foreach (($quality['ratios'] ?? []) as $ratio) {
                    $ratioValue = (string)($ratio['ratio'] ?? $ratio['value'] ?? '');
                    if ($ratioValue === '') {
                        continue;
                    }
                    $map[self::priceKey((string)$channel['code'], $qualityValue, $ratioValue)] = [
                        'channel' => (string)$channel['code'],
                        'channel_name' => (string)$channel['name'],
                        'quality' => $qualityValue,
                        'quality_label' => (string)($quality['label'] ?? $quality['quality_label'] ?? $qualityValue),
                        'ratio' => $ratioValue,
                        'ratio_label' => (string)($ratio['label'] ?? $ratioValue),
                        'width' => (int)($ratio['width'] ?? 0),
                        'height' => (int)($ratio['height'] ?? 0),
                        'platform_unit_cost' => round((float)($ratio['platform_unit_cost'] ?? 0), 2),
                    ];
                }
            }
        }
        return $map;
    }

    private static function saveConfigSnapshot(int $tenantId, array $data, AigcOutpaintConfig $row): void
    {
        $payload = [
            'tenant_id' => $tenantId,
            'status' => (int)($data['status'] ?? 1),
            'default_channel' => '',
            'default_quality' => '',
            'default_ratio' => '',
            'prompt_template' => (string)($data['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE),
            'negative_prompt' => (string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT),
            'price_config' => self::normalizePriceConfig($data['price_config'] ?? []),
            'config_json' => [],
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $payload['create_time'] = time();
            AigcOutpaintConfig::create($payload);
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
        if (preg_match('/^https?:\/\//', $uri)) {
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
        return $template !== '' ? $template : self::DEFAULT_PROMPT_TEMPLATE;
    }

    private static function normalizeCode(string $code): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($code)) ?: '';
    }

    private static function qualityKey(string $channel, string $quality): string
    {
        return $channel . '|' . $quality;
    }

    private static function priceKey(string $channel, string $quality, string $ratio): string
    {
        return $channel . '|' . $quality . '|' . $ratio;
    }

    private static function normalizePackageCode(string $code): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($code)) ?: '';
    }
}
