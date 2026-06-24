<?php

namespace app\common\service\app\aigc_model_wear;

use app\common\model\app\App;
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\model\app\aigc_model_wear\AigcModelWearConfig;
use app\common\model\app\aigc_model_wear\AigcModelWearResult;
use app\common\model\app\aigc_model_wear\AigcModelWearTask;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\AppRegistryService;
use app\common\service\app\aigc_image\AigcImageChannelService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\FileService;
use app\common\service\point\PointService;
use app\common\service\storage\StorageConfigService;
use Exception;

class AigcModelWearService
{
    public const APP_CODE = 'aigc_model_wear';
    public const IMAGE_APP_CODE = 'aigc_image';

    private const DEFAULT_PROMPT_TEMPLATE = '基于用户上传的模特图和穿戴图生成真实自然的模特穿戴效果图。保持模特身份、姿态、身形比例、面部特征和画面光线稳定，将穿戴图中的服饰、配饰或穿戴元素自然融合到模特身上，准确保留版型、材质、颜色、图案和细节，输出适合电商展示和种草内容使用的高质量图片。{user_prompt}';
    private const DEFAULT_NEGATIVE_PROMPT = '服装变形，身体比例异常，手部畸形，多余肢体，低清晰度，文字，水印，严重穿模，脸部扭曲，材质错误，颜色失真，背景杂乱';
    private const DEFAULT_PRICE_PACKAGE_NAMES = ['标准穿戴', '高清穿戴'];

    public static function config(int $tenantId): array
    {
        $row = AigcModelWearConfig::where('tenant_id', $tenantId)->findOrEmpty();
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
        $configJson = is_array($params['config_json'] ?? null) ? $params['config_json'] : ($current['config_json'] ?? []);
        $data = [
            'tenant_id' => $tenantId,
            'status' => array_key_exists('status', $params) ? (int)$params['status'] : (int)$current['status'],
            'default_channel' => self::normalizeCode((string)($params['default_channel'] ?? $configJson['channel'] ?? $current['default_channel'] ?? '')),
            'default_quality' => trim((string)($params['default_quality'] ?? $configJson['quality'] ?? $current['default_quality'] ?? '')),
            'default_ratio' => trim((string)($params['default_ratio'] ?? $configJson['ratio'] ?? $current['default_ratio'] ?? '')),
            'prompt_template' => self::normalizeTemplate((string)($params['prompt_template'] ?? $current['prompt_template'])),
            'negative_prompt' => trim((string)($params['negative_prompt'] ?? $current['negative_prompt'])),
            'price_config' => self::normalizePriceConfig($params['price_config'] ?? $current['price_config'] ?? []),
            'config_json' => self::normalizeConfigJson($configJson),
            'update_time' => time(),
        ];
        $row = AigcModelWearConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcModelWearConfig::create($data);
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
        $config = [
            'status' => $current['status'],
            'default_channel' => $current['default_channel'],
            'default_quality' => $current['default_quality'],
            'default_ratio' => $current['default_ratio'],
            'prompt_template' => $current['prompt_template'],
            'negative_prompt' => $current['negative_prompt'],
            'price_config' => self::normalizePriceConfig($params['price_config'] ?? $params['packages'] ?? $params['items'] ?? []),
            'config_json' => $current['config_json'] ?? [],
        ];
        self::saveConfig($tenantId, $config);
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
            throw new Exception('模特穿戴任务创建失败');
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
        $query = AigcModelWearTask::alias('t')
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
        $query = AigcModelWearTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
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
        $task = AigcModelWearTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return self::generate($tenantId, (int)$task['user_id'], [
            'model_image' => $task['model_image'],
            'wear_image' => $task['wear_image'],
            'channel' => $task['channel'],
            'quality' => $task['quality'],
            'ratio' => $task['ratio'],
            'prompt' => $task['user_prompt'],
        ]);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = AigcModelWearTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
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
        AigcModelWearResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time()]);
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = AigcModelWearResult::where(['tenant_id' => $tenantId, 'id' => $resultId]);
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
            'required_for' => '模特穿戴生成',
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
            throw new Exception('模特穿戴应用未开通或未上架');
        }
        if (AppAccessService::assertTenantCanUse($tenantId, self::IMAGE_APP_CODE) !== null) {
            throw new Exception('AIGC生图应用未开通或未上架');
        }
        $config = self::config($tenantId);
        if ((int)($config['status'] ?? 1) !== 1) {
            throw new Exception('模特穿戴应用已停用');
        }
    }

    private static function prepareGeneratePayload(int $tenantId, array $params, bool $requireImage): array
    {
        $config = self::config($tenantId);
        $modelImage = self::normalizeImage($params['model_image'] ?? $params['model'] ?? '');
        $wearImage = self::normalizeImage($params['wear_image'] ?? $params['item_image'] ?? $params['image'] ?? '');
        if ($requireImage && $modelImage === '') {
            throw new Exception('请上传模特图');
        }
        if ($requireImage && $wearImage === '') {
            throw new Exception('请上传穿戴图');
        }
        $package = self::resolvePricePackage($config['price_config'], $params, $config);
        $channel = (string)$package['channel'];
        $quality = (string)$package['quality'];
        $ratio = self::resolvePackageRatio($package, trim((string)($params['ratio'] ?? $config['default_ratio'] ?? '')));
        $userPrompt = trim((string)($params['prompt'] ?? $params['user_prompt'] ?? ''));
        $imagePayload = [
            'prompt' => self::renderPrompt((string)$config['prompt_template'], [
                'user_prompt' => $userPrompt,
            ]),
            'negative_prompt' => (string)($params['negative_prompt'] ?? $config['negative_prompt']),
            'reference_images' => array_values(array_filter([$modelImage, $wearImage])),
            'channel' => $channel,
            'quality' => $quality,
            'ratio' => $ratio,
            'quantity' => 1,
            'style' => 'model_wear',
        ];
        $resolved = AigcImageChannelService::resolveSelection($tenantId, $imagePayload);
        return [
            'price_package' => $package,
            'model_image' => $modelImage,
            'wear_image' => $wearImage,
            'user_prompt' => $userPrompt,
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

    private static function upsertTaskFromImageTask(int $tenantId, int $userId, int $imageTaskId, array $prepared, array $estimate): AigcModelWearTask
    {
        $imageTask = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => $imageTaskId])->findOrEmpty();
        if ($imageTask->isEmpty()) {
            throw new Exception('生图任务不存在');
        }
        $row = AigcModelWearTask::where(['tenant_id' => $tenantId, 'image_task_id' => $imageTaskId])->findOrEmpty();
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'image_task_id' => $imageTaskId,
            'image_task_ids' => [$imageTaskId],
            'model_image' => $prepared['model_image'],
            'wear_image' => $prepared['wear_image'],
            'price_package_code' => (string)($prepared['price_package']['code'] ?? ''),
            'price_package_name' => (string)($prepared['price_package']['name'] ?? ''),
            'price_package_snapshot' => $prepared['price_package'],
            'size_key' => $prepared['size_key'],
            'width' => $prepared['width'],
            'height' => $prepared['height'],
            'prompt' => $prepared['image_payload']['prompt'],
            'negative_prompt' => $prepared['image_payload']['negative_prompt'],
            'user_prompt' => $prepared['user_prompt'],
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
            return AigcModelWearTask::create($data);
        }
        $row->save($data);
        return $row;
    }

    private static function syncTaskFromImageTask(AigcModelWearTask $task): void
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

    private static function syncResultsFromImageTask(AigcModelWearTask $task): void
    {
        if ((string)$task['status'] !== 'success') {
            return;
        }
        $tenantId = (int)$task['tenant_id'];
        $userId = (int)$task['user_id'];
        $storage = StorageConfigService::getEffectiveConfig($tenantId);
        foreach (self::taskImageIds($task->toArray()) as $imageTaskId) {
            try {
                $imageTask = AigcImageService::taskDetail($tenantId, $imageTaskId, $userId);
            } catch (\Throwable) {
                continue;
            }
            foreach (($imageTask['results'] ?? []) as $result) {
                $imageResultId = (int)($result['id'] ?? 0);
                if ($imageResultId <= 0) {
                    continue;
                }
                $exists = AigcModelWearResult::where(['tenant_id' => $tenantId, 'image_result_id' => $imageResultId])->findOrEmpty();
                if (!$exists->isEmpty()) {
                    continue;
                }
                AigcModelWearResult::create([
                    'tenant_id' => $tenantId,
                    'task_id' => (int)$task['id'],
                    'image_task_id' => $imageTaskId,
                    'image_result_id' => $imageResultId,
                    'user_id' => $userId,
                    'image_uri' => (string)($result['image_uri'] ?? ''),
                    'storage_scope' => (string)($result['storage_scope'] ?? $storage['scope'] ?? 'tenant'),
                    'storage_engine' => (string)($result['storage_engine'] ?? $storage['default'] ?? 'local'),
                    'storage_domain' => (string)($result['storage_domain'] ?? StorageConfigService::getEffectiveDomain($tenantId)),
                    'width' => (int)($task['width'] ?: ($result['width'] ?? 0)),
                    'height' => (int)($task['height'] ?: ($result['height'] ?? 0)),
                    'delete_time' => 0,
                    'create_time' => time(),
                ]);
            }
        }
    }

    private static function refreshMappedTasks(int $tenantId, int $userId = 0, int $taskId = 0): void
    {
        $query = AigcModelWearTask::where('tenant_id', $tenantId)->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($taskId > 0) {
            $query->where('id', $taskId);
        } else {
            $query->whereIn('status', ['running', 'pending']);
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
            $query = AigcModelWearResult::where('tenant_id', $tenantId)
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
                $resultMap[(int)$result['task_id']][] = $result;
            }
        }
        foreach ($rows as &$row) {
            $results = $resultMap[(int)$row['id']] ?? [];
            $row['results'] = $results;
            $row['result_count'] = count($results);
            $first = $results[0] ?? [];
            $row['image_url'] = (string)($first['image_url'] ?? '');
            $row['image_uri'] = (string)($first['image_uri'] ?? '');
            $row['model_image_url'] = self::imageUrl((string)($row['model_image'] ?? ''));
            $row['wear_image_url'] = self::imageUrl((string)($row['wear_image'] ?? ''));
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
        $data['default_channel'] = self::normalizeCode((string)($data['default_channel'] ?? ''));
        $data['default_quality'] = trim((string)($data['default_quality'] ?? ''));
        $data['default_ratio'] = trim((string)($data['default_ratio'] ?? ''));
        $data['prompt_template'] = self::normalizeTemplate((string)($data['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE));
        $data['negative_prompt'] = trim((string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT));
        $data['price_config'] = is_array($data['price_config'] ?? null) ? self::normalizePriceConfig($data['price_config']) : [];
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

    private static function normalizePriceConfig(mixed $config): array
    {
        $items = [];
        if (is_array($config) && array_is_list($config)) {
            $isPackageList = false;
            foreach ($config as $item) {
                if (is_array($item) && (isset($item['code']) || isset($item['name']) || isset($item['package_code']))) {
                    $isPackageList = true;
                    break;
                }
            }
            if ($isPackageList) {
                foreach ($config as $index => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $channel = self::normalizeCode((string)($item['channel'] ?? $item['channel_code'] ?? ''));
                    $quality = trim((string)($item['quality'] ?? ''));
                    if ($channel === '' || $quality === '') {
                        continue;
                    }
                    $code = self::normalizePackageCode((string)($item['code'] ?? $item['package_code'] ?? ''));
                    if ($code === '') {
                        $code = 'package_' . ($index + 1);
                    }
                    $items[$code] = [
                        'code' => $code,
                        'name' => mb_substr(trim((string)($item['name'] ?? '价格包' . ($index + 1))), 0, 80),
                        'channel' => $channel,
                        'quality' => $quality,
                        'quality_label' => mb_substr(trim((string)($item['quality_label'] ?? '')), 0, 80),
                        'unit_price' => round(max(0, (float)($item['unit_price'] ?? $item['tenant_unit_price'] ?? 0)), 2),
                        'status' => (int)($item['status'] ?? $item['tenant_status'] ?? 1) ? 1 : 0,
                        'sort' => (int)($item['sort'] ?? (100 - $index)),
                    ];
                }
                return array_values($items);
            }
            foreach ($config as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $channel = self::normalizeCode((string)($item['channel'] ?? $item['channel_code'] ?? ''));
                $quality = trim((string)($item['quality'] ?? ''));
                $ratio = trim((string)($item['ratio'] ?? ''));
                if ($channel === '' || $quality === '' || $ratio === '') {
                    continue;
                }
                $items[self::priceKey($channel, $quality, $ratio)] = [
                    'channel' => $channel,
                    'quality' => $quality,
                    'ratio' => $ratio,
                    'unit_price' => round(max(0, (float)($item['unit_price'] ?? $item['tenant_unit_price'] ?? 0)), 2),
                    'status' => (int)($item['status'] ?? $item['tenant_status'] ?? 1) ? 1 : 0,
                ];
            }
            return array_values($items);
        }
        if (!is_array($config)) {
            return [];
        }
        foreach ($config as $key => $item) {
            if (is_array($item)) {
                $channel = self::normalizeCode((string)($item['channel'] ?? $item['channel_code'] ?? ''));
                $quality = trim((string)($item['quality'] ?? ''));
                $ratio = trim((string)($item['ratio'] ?? ''));
                [$fallbackChannel, $fallbackQuality, $fallbackRatio] = array_pad(explode('|', (string)$key), 3, '');
                $channel = $channel ?: self::normalizeCode($fallbackChannel);
                $quality = $quality ?: $fallbackQuality;
                $ratio = $ratio ?: $fallbackRatio;
                if ($channel === '' || $quality === '' || $ratio === '') {
                    continue;
                }
                $items[self::priceKey($channel, $quality, $ratio)] = [
                    'channel' => $channel,
                    'quality' => $quality,
                    'ratio' => $ratio,
                    'unit_price' => round(max(0, (float)($item['unit_price'] ?? $item['tenant_unit_price'] ?? 0)), 2),
                    'status' => (int)($item['status'] ?? $item['tenant_status'] ?? 1) ? 1 : 0,
                ];
            }
        }
        return array_values($items);
    }

    private static function buildPricePackages(array $optionConfig, array $priceConfig): array
    {
        $sourceMap = self::packageSourceMap($optionConfig);
        $packages = [];
        foreach (self::normalizePriceConfig($priceConfig) as $item) {
            $key = self::qualityKey((string)$item['channel'], (string)$item['quality']);
            $source = $sourceMap[$key] ?? [];
            $ratios = $source['ratios'] ?? [];
            if (empty($ratios)) {
                continue;
            }
            $packages[] = [
                'code' => (string)$item['code'],
                'name' => (string)$item['name'],
                'channel' => (string)$item['channel'],
                'channel_name' => (string)($source['channel_name'] ?? $item['channel']),
                'quality' => (string)$item['quality'],
                'quality_label' => (string)($item['quality_label'] ?: ($source['quality_label'] ?? $item['quality'])),
                'unit_price' => round((float)$item['unit_price'], 2),
                'status' => (int)($item['status'] ?? 1),
                'sort' => (int)($item['sort'] ?? 0),
                'ratios' => $ratios,
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
            throw new Exception('请先配置可用价格包');
        }
        $code = self::normalizePackageCode((string)($params['price_package_code'] ?? $params['price_package'] ?? $params['package_code'] ?? ''));
        if ($code === '') {
            $channel = self::normalizeCode((string)($params['channel'] ?? $config['default_channel'] ?? ''));
            $quality = trim((string)($params['quality'] ?? $config['default_quality'] ?? ''));
            foreach ($enabled as $item) {
                if (($channel === '' || $item['channel'] === $channel) && ($quality === '' || $item['quality'] === $quality)) {
                    return $item;
                }
            }
            return $enabled[0];
        }
        foreach ($enabled as $item) {
            if ((string)$item['code'] === $code) {
                return $item;
            }
        }
        throw new Exception('请选择可用价格包');
    }

    private static function resolvePackageRatio(array $package, string $ratio): string
    {
        $ratios = $package['ratios'] ?? [];
        $values = array_values(array_filter(array_map(static fn($item) => (string)($item['value'] ?? $item['ratio'] ?? ''), $ratios)));
        if (!$values) {
            return $ratio;
        }
        if ($ratio !== '' && in_array($ratio, $values, true)) {
            return $ratio;
        }
        return $values[0];
    }

    private static function ensurePricePackages(array $priceConfig, array $optionConfig): array
    {
        $normalized = self::normalizePriceConfig($priceConfig);
        $sourceMap = self::packageSourceMap($optionConfig);
        $packages = [];
        $isPackageConfig = false;
        foreach ($normalized as $item) {
            if (isset($item['code'])) {
                $isPackageConfig = true;
                $key = self::qualityKey((string)$item['channel'], (string)$item['quality']);
                if (!isset($sourceMap[$key])) {
                    continue;
                }
                $source = $sourceMap[$key];
                $item['quality_label'] = (string)($item['quality_label'] ?: ($source['quality_label'] ?? $item['quality']));
                $packages[] = $item;
            }
        }
        if ($isPackageConfig && $packages) {
            return [array_values($packages), count($packages) !== count($normalized)];
        }

        $sourceItems = array_values($sourceMap);
        if (!$sourceItems) {
            return [[], false];
        }
        $legacyMap = [];
        foreach ($normalized as $item) {
            if (!isset($item['ratio'])) {
                continue;
            }
            $key = self::qualityKey((string)$item['channel'], (string)$item['quality']);
            $legacyMap[$key][] = $item;
        }
        foreach (array_slice($sourceItems, 0, 2) as $index => $source) {
            $legacyRows = $legacyMap[self::qualityKey((string)$source['channel'], (string)$source['quality'])] ?? [];
            $legacyPrice = $legacyRows ? max(array_map(static fn($row) => (float)($row['unit_price'] ?? 0), $legacyRows)) : (float)$source['default_unit_price'];
            $packages[] = [
                'code' => 'default_' . ($index + 1),
                'name' => self::DEFAULT_PRICE_PACKAGE_NAMES[$index] ?? ('价格包' . ($index + 1)),
                'channel' => (string)$source['channel'],
                'quality' => (string)$source['quality'],
                'quality_label' => (string)$source['quality_label'],
                'unit_price' => round(max(0, $legacyPrice), 2),
                'status' => 1,
                'sort' => 100 - $index,
            ];
        }
        return [$packages, true];
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
                $ratios = [];
                foreach (($quality['ratios'] ?? []) as $ratio) {
                    $ratios[] = [
                        'value' => (string)($ratio['value'] ?? $ratio['ratio'] ?? ''),
                        'label' => (string)($ratio['label'] ?? $ratio['ratio'] ?? $ratio['value'] ?? ''),
                        'ratio' => (string)($ratio['ratio'] ?? $ratio['value'] ?? ''),
                        'width' => (int)($ratio['width'] ?? 0),
                        'height' => (int)($ratio['height'] ?? 0),
                        'platform_unit_cost' => round((float)($ratio['platform_unit_cost'] ?? 0), 2),
                    ];
                }
                if (!$ratios) {
                    continue;
                }
                $map[self::qualityKey((string)$channel['code'], $qualityValue)] = [
                    'channel' => (string)$channel['code'],
                    'channel_name' => (string)$channel['name'],
                    'quality' => $qualityValue,
                    'quality_label' => (string)($quality['label'] ?? $quality['quality_label'] ?? $qualityValue),
                    'default_unit_price' => (float)($ratios[0]['platform_unit_cost'] ?? 0),
                    'ratios' => $ratios,
                ];
            }
        }
        return $map;
    }

    private static function buildPriceOptions(array $optionConfig, array $priceConfig): array
    {
        $priceMap = [];
        foreach ($priceConfig as $item) {
            $priceMap[self::priceKey((string)$item['channel'], (string)$item['quality'], (string)$item['ratio'])] = $item;
        }
        $channels = [];
        foreach (($optionConfig['channels'] ?? []) as $channel) {
            $next = [
                'code' => (string)$channel['code'],
                'name' => (string)$channel['name'],
                'status' => (int)($channel['status'] ?? 1),
                'qualities' => $channel['qualities'] ?? [],
                'specs' => [],
            ];
            foreach (($channel['qualities'] ?? []) as $quality) {
                foreach (($quality['ratios'] ?? []) as $ratio) {
                    $key = self::priceKey((string)$channel['code'], (string)$quality['value'], (string)$ratio['ratio']);
                    $price = $priceMap[$key] ?? [];
                    $next['specs'][] = [
                        'channel_code' => (string)$channel['code'],
                        'channel_name' => (string)$channel['name'],
                        'quality' => (string)$quality['value'],
                        'quality_label' => (string)($quality['label'] ?? $ratio['quality_label'] ?? $quality['value']),
                        'ratio' => (string)$ratio['ratio'],
                        'value' => (string)($ratio['value'] ?? $ratio['ratio']),
                        'label' => (string)($ratio['label'] ?? $ratio['ratio']),
                        'width' => (int)($ratio['width'] ?? 0),
                        'height' => (int)($ratio['height'] ?? 0),
                        'platform_unit_cost' => round((float)($ratio['platform_unit_cost'] ?? 0), 2),
                        'unit_price' => round((float)($price['unit_price'] ?? 0), 2),
                        'status' => (int)($price['status'] ?? 1),
                    ];
                }
            }
            $channels[] = $next;
        }
        return $channels;
    }

    private static function unitPrice(array $priceConfig, string $channel, string $quality, string $ratio): float
    {
        $key = self::priceKey($channel, $quality, $ratio);
        foreach ($priceConfig as $item) {
            if (self::priceKey((string)$item['channel'], (string)$item['quality'], (string)$item['ratio']) !== $key) {
                continue;
            }
            if ((int)($item['status'] ?? 1) !== 1) {
                throw new Exception('当前模型规格已停用');
            }
            return round(max(0, (float)($item['unit_price'] ?? 0)), 2);
        }
        throw new Exception('请先配置模特穿戴价格');
    }

    private static function mergeMissingPriceConfig(array $priceConfig, array $optionConfig): array
    {
        $items = [];
        foreach ($priceConfig as $item) {
            $key = self::priceKey((string)$item['channel'], (string)$item['quality'], (string)$item['ratio']);
            $items[$key] = $item;
        }
        $changed = false;
        foreach (($optionConfig['channels'] ?? []) as $channel) {
            foreach (($channel['qualities'] ?? []) as $quality) {
                foreach (($quality['ratios'] ?? []) as $ratio) {
                    $channelCode = (string)($channel['code'] ?? '');
                    $qualityValue = (string)($quality['value'] ?? '');
                    $ratioValue = (string)($ratio['ratio'] ?? $ratio['value'] ?? '');
                    if ($channelCode === '' || $qualityValue === '' || $ratioValue === '') {
                        continue;
                    }
                    $key = self::priceKey($channelCode, $qualityValue, $ratioValue);
                    if (isset($items[$key])) {
                        continue;
                    }
                    $items[$key] = [
                        'channel' => $channelCode,
                        'quality' => $qualityValue,
                        'ratio' => $ratioValue,
                        'unit_price' => round(max(0, (float)($ratio['tenant_unit_price'] ?? $ratio['platform_unit_cost'] ?? 0)), 2),
                        'status' => (int)($ratio['status'] ?? 1) ? 1 : 0,
                    ];
                    $changed = true;
                }
            }
        }
        return [array_values($items), $changed];
    }

    private static function saveConfigSnapshot(int $tenantId, array $data, AigcModelWearConfig $row): void
    {
        $payload = [
            'tenant_id' => $tenantId,
            'status' => (int)($data['status'] ?? 1),
            'default_channel' => (string)($data['default_channel'] ?? ''),
            'default_quality' => (string)($data['default_quality'] ?? ''),
            'default_ratio' => (string)($data['default_ratio'] ?? ''),
            'prompt_template' => (string)($data['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE),
            'negative_prompt' => (string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT),
            'price_config' => self::normalizePriceConfig($data['price_config'] ?? []),
            'config_json' => self::normalizeConfigJson($data['config_json'] ?? []),
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $payload['create_time'] = time();
            AigcModelWearConfig::create($payload);
            return;
        }
        $row->save($payload);
    }

    private static function renderPrompt(string $template, array $data): string
    {
        $userPrompt = trim((string)($data['user_prompt'] ?? ''));
        return trim(strtr(self::normalizeTemplate($template), [
            '{user_prompt}' => $userPrompt !== '' ? '用户补充要求：' . $userPrompt . '。' : '',
        ]) . ($userPrompt !== '' && !str_contains($template, '{user_prompt}') ? ' 用户补充要求：' . $userPrompt . '。' : ''));
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

    private static function priceKey(string $channel, string $quality, string $ratio): string
    {
        return $channel . '|' . $quality . '|' . $ratio;
    }

    private static function qualityKey(string $channel, string $quality): string
    {
        return $channel . '|' . $quality;
    }

    private static function normalizePackageCode(string $code): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($code)) ?: '';
    }
}
