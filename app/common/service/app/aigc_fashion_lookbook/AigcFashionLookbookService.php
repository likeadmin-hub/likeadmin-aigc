<?php

namespace app\common\service\app\aigc_fashion_lookbook;

use app\common\model\app\App;
use app\common\model\app\aigc_fashion_lookbook\AigcFashionLookbookConfig;
use app\common\model\app\aigc_fashion_lookbook\AigcFashionLookbookModel;
use app\common\model\app\aigc_fashion_lookbook\AigcFashionLookbookResult;
use app\common\model\app\aigc_fashion_lookbook\AigcFashionLookbookTask;
use app\common\model\app\aigc_image\AigcImageResult;
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\AppRegistryService;
use app\common\service\app\aigc_image\AigcImageChannelService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\FileService;
use app\common\service\point\PointService;
use Exception;

class AigcFashionLookbookService
{
    public const APP_CODE = 'aigc_fashion_lookbook';
    public const IMAGE_APP_CODE = 'aigc_image';

    private const MAX_CLOTHES_IMAGES = 6;
    private const DEFAULT_PROMPT_TEMPLATE = '基于用户上传的模特图和当前服饰图生成自然真实的服饰穿搭图。保持模特身份、姿态、身形比例、面部特征和画面光线稳定，将当前服饰图中的版型、材质、颜色、纹理、图案和细节准确融合到同一张模特图上，形成适合电商详情页、穿搭种草和服饰套图展示的高质量图片。{user_prompt}';
    private const DEFAULT_NEGATIVE_PROMPT = '服装变形，身体比例异常，手部畸形，多余肢体，低清晰度，文字，水印，严重穿模，脸部扭曲，材质错误，颜色失真，背景杂乱';

    public static function config(int $tenantId): array
    {
        $row = AigcFashionLookbookConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $data = $row->isEmpty() ? self::defaults() : array_merge(self::defaults(), $row->toArray());
        $data = self::sanitizeConfig($data);
        $optionConfig = AigcImageChannelService::userConfig($tenantId);
        $data['option_config'] = $optionConfig;
        $data['spec_options'] = self::buildSpecOptions($optionConfig);
        $data['models'] = self::modelLists($tenantId, true);
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
            'max_clothes_images' => self::normalizeMaxClothes($params['max_clothes_images'] ?? $current['max_clothes_images'] ?? self::MAX_CLOTHES_IMAGES),
            'prompt_template' => self::normalizeTemplate((string)($params['prompt_template'] ?? $current['prompt_template'])),
            'negative_prompt' => trim((string)($params['negative_prompt'] ?? $current['negative_prompt'])),
            'config_json' => self::normalizeConfigJson($configJson),
            'update_time' => time(),
        ];
        $row = AigcFashionLookbookConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcFashionLookbookConfig::create($data);
            return;
        }
        $row->save($data);
    }

    public static function priceDetail(int $tenantId): array
    {
        $config = self::config($tenantId);
        return [
            'channels' => self::buildSpecOptions($config['option_config'] ?? []),
            'unit_price' => round((float)($config['unit_price'] ?? 0), 2),
            'default_channel' => (string)($config['default_channel'] ?? ''),
            'default_quality' => (string)($config['default_quality'] ?? ''),
            'default_ratio' => (string)($config['default_ratio'] ?? ''),
        ];
    }

    public static function savePrice(int $tenantId, array $params): void
    {
        $current = self::config($tenantId);
        self::saveConfig($tenantId, [
            'status' => $current['status'],
            'default_channel' => $params['default_channel'] ?? $current['default_channel'],
            'default_quality' => $params['default_quality'] ?? $current['default_quality'],
            'default_ratio' => $params['default_ratio'] ?? $current['default_ratio'],
            'unit_price' => $params['unit_price'] ?? $current['unit_price'],
            'max_clothes_images' => $current['max_clothes_images'],
            'prompt_template' => $current['prompt_template'],
            'negative_prompt' => $current['negative_prompt'],
            'config_json' => [
                'channel' => $params['default_channel'] ?? $current['default_channel'],
                'quality' => $params['default_quality'] ?? $current['default_quality'],
                'ratio' => $params['default_ratio'] ?? $current['default_ratio'],
            ],
        ]);
    }

    public static function modelLists(int $tenantId, bool $onlyEnabled = false): array
    {
        $query = AigcFashionLookbookModel::where('tenant_id', $tenantId)
            ->where('delete_time', 0)
            ->order('sort', 'desc')
            ->order('id', 'desc');
        if ($onlyEnabled) {
            $query->where('status', 1);
        }
        $rows = $query->select()->toArray();
        foreach ($rows as &$row) {
            $row['model_url'] = self::imageUrl((string)($row['model_image'] ?? ''));
            $row['cover_url'] = self::imageUrl((string)($row['cover_image'] ?: ($row['model_image'] ?? '')));
        }
        return $rows;
    }

    public static function saveModel(int $tenantId, array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $row = $id > 0
            ? AigcFashionLookbookModel::where(['tenant_id' => $tenantId, 'id' => $id])->where('delete_time', 0)->findOrEmpty()
            : new AigcFashionLookbookModel();
        if ($id > 0 && $row->isEmpty()) {
            throw new Exception('模特预设不存在');
        }
        $name = mb_substr(trim((string)($params['name'] ?? '')), 0, 80);
        if ($name === '') {
            throw new Exception('请输入模特名称');
        }
        $modelImage = trim((string)($params['model_image'] ?? ''));
        if ($modelImage === '') {
            throw new Exception('请选择模特图');
        }
        $data = [
            'tenant_id' => $tenantId,
            'name' => $name,
            'model_image' => $modelImage,
            'cover_image' => trim((string)($params['cover_image'] ?? '')),
            'status' => (int)($params['status'] ?? 1) ? 1 : 0,
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
        ];
        if ($id <= 0) {
            $data['delete_time'] = 0;
            $data['create_time'] = time();
            $row = AigcFashionLookbookModel::create($data);
        } else {
            $row->save($data);
        }
        return $row->toArray();
    }

    public static function setModelStatus(int $tenantId, array $params): void
    {
        $row = AigcFashionLookbookModel::where(['tenant_id' => $tenantId, 'id' => (int)($params['id'] ?? 0)])
            ->where('delete_time', 0)
            ->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('模特预设不存在');
        }
        $row->save(['status' => (int)($params['status'] ?? 1) ? 1 : 0, 'update_time' => time()]);
    }

    public static function deleteModel(int $tenantId, array $params): void
    {
        $row = AigcFashionLookbookModel::where(['tenant_id' => $tenantId, 'id' => (int)($params['id'] ?? 0)])
            ->where('delete_time', 0)
            ->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('模特预设不存在');
        }
        $row->save(['delete_time' => time(), 'update_time' => time()]);
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
        $singleEstimate = AigcImageService::estimate($tenantId, $prepared['image_payload']);
        $estimate = self::buildEstimate($prepared, $singleEstimate);
        PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);

        $imageTaskIds = [];
        $submitErrors = [];
        foreach ($prepared['clothes_images'] as $index => $clothesImage) {
            try {
                $payload = array_merge($prepared['image_payload'], [
                    'reference_images' => array_values(array_filter([$prepared['model_image'], $clothesImage])),
                ]);
                $result = AigcImageService::generateWithBillingOverride($tenantId, $userId, $payload, [
                    'tenant_cost_points' => $estimate['platform_unit_cost'],
                    'user_charge_points' => $prepared['unit_price'],
                ]);
                $imageTaskId = (int)($result['task_id'] ?? 0);
                if ($imageTaskId > 0) {
                    $imageTaskIds[] = $imageTaskId;
                }
            } catch (\Throwable $e) {
                $submitErrors[] = '第' . ((int)$index + 1) . '张服饰图提交失败：' . $e->getMessage();
                break;
            }
        }

        if (!$imageTaskIds) {
            throw new Exception($submitErrors[0] ?? '服饰套图任务创建失败');
        }

        $task = self::createBatchTask($tenantId, $userId, $imageTaskIds, $prepared, $estimate, $submitErrors);
        self::syncTaskFromImageTask($task);
        return [
            'task_id' => (int)$task['id'],
            'image_task_id' => (int)$imageTaskIds[0],
            'image_task_ids' => $imageTaskIds,
            'status' => (string)($task['status'] ?: 'running'),
            'error' => (string)($task['error'] ?? ''),
            'results' => self::taskDetail($tenantId, (int)$task['id'], $userId)['results'] ?? [],
            'estimate' => $estimate,
        ];
    }

    public static function taskLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        self::refreshMappedTasks($tenantId, $userId);
        $query = AigcFashionLookbookTask::alias('t')
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
        return ['lists' => array_map([self::class, 'formatTaskRow'], $rows), 'count' => $count, 'page_no' => $pageNo, 'page_size' => $pageSize];
    }

    public static function taskDetail(int $tenantId, int $taskId, int $userId = 0): array
    {
        self::refreshMappedTasks($tenantId, $userId, $taskId);
        $query = AigcFashionLookbookTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
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
        $task = AigcFashionLookbookTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return self::generate($tenantId, (int)$task['user_id'], [
            'clothes_images' => $task['clothes_images'],
            'model_image' => $task['model_image'],
            'ratio' => $task['ratio'],
            'prompt' => $task['user_prompt'],
        ]);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = AigcFashionLookbookTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
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
        AigcFashionLookbookResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time()]);
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = AigcFashionLookbookResult::where(['tenant_id' => $tenantId, 'id' => $resultId]);
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
            'required_for' => '服饰套图生成',
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
            throw new Exception('服饰套图应用未开通或未上架');
        }
        if (AppAccessService::assertTenantCanUse($tenantId, self::IMAGE_APP_CODE) !== null) {
            throw new Exception('AIGC生图应用未开通或未上架');
        }
        $config = self::config($tenantId);
        if ((int)($config['status'] ?? 1) !== 1) {
            throw new Exception('服饰套图应用已停用');
        }
    }

    private static function prepareGeneratePayload(int $tenantId, array $params, bool $requireImages): array
    {
        $config = self::config($tenantId);
        $clothesImages = self::normalizeImages($params['clothes_images'] ?? $params['clothes'] ?? $params['source_images'] ?? []);
        $maxImages = self::normalizeMaxClothes($config['max_clothes_images'] ?? self::MAX_CLOTHES_IMAGES);
        if ($requireImages && !$clothesImages) {
            throw new Exception('请上传服饰图');
        }
        if (count($clothesImages) > $maxImages) {
            throw new Exception('服饰图最多上传' . $maxImages . '张');
        }
        $modelImage = self::normalizeImage($params['model_image'] ?? $params['model'] ?? '');
        if ($requireImages && $modelImage === '') {
            throw new Exception('请上传模特图');
        }
        $channel = (string)($config['default_channel'] ?: ($config['config_json']['channel'] ?? ''));
        $quality = (string)($config['default_quality'] ?: ($config['config_json']['quality'] ?? ''));
        $ratio = trim((string)($params['ratio'] ?? $config['default_ratio'] ?? $config['config_json']['ratio'] ?? ''));
        $userPrompt = mb_substr(trim((string)($params['prompt'] ?? $params['user_prompt'] ?? '')), 0, 1000);
        $imagePayload = [
            'prompt' => self::renderPrompt((string)$config['prompt_template'], ['user_prompt' => $userPrompt]),
            'negative_prompt' => (string)($params['negative_prompt'] ?? $config['negative_prompt']),
            'reference_images' => array_values(array_filter([$modelImage, $clothesImages[0] ?? ''])),
            'channel' => $channel,
            'quality' => $quality,
            'ratio' => $ratio,
            'quantity' => 1,
            'style' => 'fashion_lookbook',
        ];
        $resolved = AigcImageChannelService::resolveSelection($tenantId, $imagePayload);
        return [
            'clothes_images' => $clothesImages,
            'model_image' => $modelImage,
            'model_snapshot' => self::findModelSnapshot($tenantId, $modelImage),
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
            'config' => $config,
        ];
    }

    private static function buildEstimate(array $prepared, array $imageEstimate): array
    {
        $quantity = max(1, count($prepared['clothes_images']));
        $tenantUnitCost = round((float)($imageEstimate['platform_unit_cost'] ?? 0), 2);
        $userUnitPrice = round((float)$prepared['unit_price'], 2);
        return array_merge($imageEstimate, [
            'quantity' => $quantity,
            'clothes_count' => $quantity,
            'target_width' => $prepared['width'],
            'target_height' => $prepared['height'],
            'size_key' => $prepared['size_key'],
            'platform_unit_cost' => $tenantUnitCost,
            'tenant_unit_price' => $userUnitPrice,
            'unit_price' => $userUnitPrice,
            'tenant_cost_points' => round($tenantUnitCost * $quantity, 2),
            'user_charge_points' => round($userUnitPrice * $quantity, 2),
            'display_points' => round($userUnitPrice * $quantity, 2),
        ]);
    }

    private static function createBatchTask(int $tenantId, int $userId, array $imageTaskIds, array $prepared, array $estimate, array $submitErrors = []): AigcFashionLookbookTask
    {
        $firstImageTask = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => (int)$imageTaskIds[0]])->findOrEmpty();
        if ($firstImageTask->isEmpty()) {
            throw new Exception('生图任务不存在');
        }
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'image_task_id' => (int)$imageTaskIds[0],
            'image_task_ids' => $imageTaskIds,
            'clothes_images' => $prepared['clothes_images'],
            'model_image' => $prepared['model_image'],
            'model_snapshot' => $prepared['model_snapshot'],
            'size_key' => $prepared['size_key'],
            'width' => $prepared['width'],
            'height' => $prepared['height'],
            'prompt' => $prepared['image_payload']['prompt'],
            'negative_prompt' => $prepared['image_payload']['negative_prompt'],
            'user_prompt' => $prepared['user_prompt'],
            'channel' => (string)($firstImageTask['channel'] ?? $prepared['image_payload']['channel']),
            'quality' => (string)($firstImageTask['quality'] ?? $prepared['image_payload']['quality']),
            'quality_label' => $prepared['quality_label'],
            'ratio' => (string)($firstImageTask['ratio'] ?? $prepared['image_payload']['ratio']),
            'quantity' => count($prepared['clothes_images']),
            'unit_price' => $prepared['unit_price'],
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
            'status' => 'running',
            'error' => implode('；', array_filter($submitErrors)),
            'finish_time' => 0,
            'delete_time' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ];
        return AigcFashionLookbookTask::create($data);
    }

    private static function syncTaskFromImageTask(AigcFashionLookbookTask $task): void
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
        $syncSummary = self::syncResultsFromImageTask($task);
        $expectedCount = max(count($imageTaskIds), (int)($task['quantity'] ?? 0), 1);
        $submittedCount = count($imageTaskIds);
        $missingSubmitCount = max(0, $expectedCount - $submittedCount);
        $statuses = array_map(static fn($row) => (string)($row['status'] ?? ''), $imageTasks);
        $successCount = count(array_filter($statuses, static fn($status) => $status === 'success'));
        $failedCount = count(array_filter($statuses, static fn($status) => in_array($status, ['failed', 'canceled'], true)));
        $resultCount = (int)($syncSummary['result_count'] ?? 0);
        $missingResultCount = (int)($syncSummary['missing_result_count'] ?? 0);
        if ($missingSubmitCount === 0 && $resultCount >= $expectedCount && $successCount === $expectedCount) {
            $task->status = 'success';
        } elseif ($resultCount > 0 && ($successCount + $failedCount === $submittedCount || $missingSubmitCount > 0)) {
            $task->status = 'partial_failed';
        } elseif (($submittedCount > 0 && $failedCount === $submittedCount) || ($successCount + $failedCount === $submittedCount && $resultCount === 0 && $missingSubmitCount > 0)) {
            $task->status = 'failed';
        } elseif (in_array('pending', $statuses, true)) {
            $task->status = 'pending';
        } else {
            $task->status = 'running';
        }
        $existingError = trim((string)($task['error'] ?? ''));
        $errors = array_values(array_filter(array_merge(
            $existingError !== '' ? [$existingError] : [],
            array_map(static fn($row) => trim((string)($row['error'] ?? '')), $imageTasks)
        )));
        if ($missingSubmitCount > 0 && in_array((string)$task->status, ['failed', 'partial_failed'], true)) {
            $errors[] = '部分服饰图未能提交生成，请重新生成';
        }
        if ($missingResultCount > 0 && in_array((string)$task->status, ['failed', 'partial_failed'], true)) {
            $errors[] = '生成任务未返回可用作品，请重新生成';
        }
        $finishTimes = array_filter(array_map(static fn($row) => (int)($row['finish_time'] ?? 0), $imageTasks));
        $task->error = implode('；', array_unique($errors));
        $task->finish_time = in_array((string)$task->status, ['success', 'failed', 'canceled', 'partial_failed'], true) ? max($finishTimes ?: [time()]) : 0;
        $task->tenant_cost_points = number_format(array_sum(array_map(static fn($row) => (float)($row['tenant_cost_points'] ?? 0), $imageTasks)), 2, '.', '');
        $task->user_charge_points = number_format(array_sum(array_map(static fn($row) => (float)($row['user_charge_points'] ?? 0), $imageTasks)), 2, '.', '');
        $task->update_time = time();
        $task->save();
    }

    private static function syncResultsFromImageTask(AigcFashionLookbookTask $task): array
    {
        $tenantId = (int)$task['tenant_id'];
        $userId = (int)$task['user_id'];
        $resultCount = 0;
        $missingResultCount = 0;
        foreach (self::taskImageIds($task->toArray()) as $imageTaskId) {
            $imageTask = AigcImageTask::where('tenant_id', $tenantId)->where('id', $imageTaskId)->where('delete_time', 0)->findOrEmpty();
            if ($imageTask->isEmpty() || (string)$imageTask['status'] !== 'success') {
                continue;
            }
            $hasUsableResult = false;
            $imageResults = AigcImageResult::where('tenant_id', $tenantId)
                ->where('task_id', $imageTaskId)
                ->where('delete_time', 0)
                ->where('image_uri', '<>', '')
                ->order('id', 'asc')
                ->select()
                ->toArray();
            foreach ($imageResults as $result) {
                $imageResultId = (int)($result['id'] ?? 0);
                $imageUri = trim((string)($result['image_uri'] ?? ''));
                if ($imageResultId <= 0 || $imageUri === '') {
                    continue;
                }
                $hasUsableResult = true;
                $exists = AigcFashionLookbookResult::where(['tenant_id' => $tenantId, 'image_result_id' => $imageResultId])->findOrEmpty();
                if (!$exists->isEmpty()) {
                    continue;
                }
                AigcFashionLookbookResult::create([
                    'tenant_id' => $tenantId,
                    'task_id' => (int)$task['id'],
                    'image_task_id' => $imageTaskId,
                    'image_result_id' => $imageResultId,
                    'user_id' => $userId,
                    'image_uri' => $imageUri,
                    'storage_scope' => (string)($result['storage_scope'] ?? 'tenant'),
                    'storage_engine' => (string)($result['storage_engine'] ?? 'local'),
                    'storage_domain' => (string)($result['storage_domain'] ?? ''),
                    'width' => (int)($task['width'] ?: ($result['width'] ?? 0)),
                    'height' => (int)($task['height'] ?: ($result['height'] ?? 0)),
                    'delete_time' => 0,
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
            }
            if ($hasUsableResult) {
                $resultCount++;
            } else {
                $missingResultCount++;
            }
        }
        return [
            'result_count' => $resultCount,
            'missing_result_count' => $missingResultCount,
        ];
    }

    private static function refreshMappedTasks(int $tenantId, int $userId = 0, int $taskId = 0): void
    {
        $query = AigcFashionLookbookTask::where('tenant_id', $tenantId)->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($taskId > 0) {
            $query->where('id', $taskId);
        } else {
            $query->whereIn('status', ['running', 'pending', 'success', 'partial_failed']);
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
            $query = AigcFashionLookbookResult::where('tenant_id', $tenantId)
                ->where('delete_time', 0)
                ->where('image_uri', '<>', '')
                ->whereIn('task_id', $taskIds)
                ->order('id', 'asc');
            if ($userId > 0) {
                $query->where('user_id', $userId);
            }
            foreach ($query->select()->toArray() as $result) {
                $result['image_url'] = FileService::getFileUrlByStorage($result['image_uri'], $result['storage_scope'] ?? '', $result['storage_engine'] ?? '', $result['storage_domain'] ?? '');
                if (trim((string)$result['image_url']) === '') {
                    continue;
                }
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
            $row['model_image_url'] = self::imageUrl((string)($row['model_image'] ?? ''));
            $clothesImages = is_array($row['clothes_images'] ?? null) ? $row['clothes_images'] : [];
            $row['clothes_image_urls'] = array_values(array_filter(array_map(static fn($uri) => self::imageUrl((string)$uri), $clothesImages)));
            $row['source_image_urls'] = array_values(array_filter(array_merge([$row['model_image_url']], $row['clothes_image_urls'])));
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
            'partial_failed' => '部分失败',
            'canceled' => '已取消',
            'pending' => '排队中',
            default => '生成中',
        };
        return $row;
    }

    private static function findModelSnapshot(int $tenantId, string $modelImage): array
    {
        if ($modelImage === '') {
            return [];
        }
        $row = AigcFashionLookbookModel::where(['tenant_id' => $tenantId, 'model_image' => $modelImage])
            ->where('delete_time', 0)
            ->findOrEmpty();
        return $row->isEmpty() ? [] : [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'model_image' => (string)$row['model_image'],
            'cover_image' => (string)$row['cover_image'],
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
            $channels[] = ['code' => (string)$channel['code'], 'name' => (string)$channel['name'], 'qualities' => $qualities];
        }
        return $channels;
    }

    private static function saveConfigSnapshot(int $tenantId, array $data, AigcFashionLookbookConfig $row): void
    {
        $payload = [
            'tenant_id' => $tenantId,
            'status' => (int)($data['status'] ?? 1),
            'default_channel' => (string)($data['default_channel'] ?? ''),
            'default_quality' => (string)($data['default_quality'] ?? ''),
            'default_ratio' => (string)($data['default_ratio'] ?? ''),
            'unit_price' => round(max(0, (float)($data['unit_price'] ?? 0)), 2),
            'max_clothes_images' => self::normalizeMaxClothes($data['max_clothes_images'] ?? self::MAX_CLOTHES_IMAGES),
            'prompt_template' => (string)($data['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE),
            'negative_prompt' => (string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT),
            'config_json' => self::normalizeConfigJson($data['config_json'] ?? []),
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $payload['create_time'] = time();
            AigcFashionLookbookConfig::create($payload);
            return;
        }
        $row->save($payload);
    }

    private static function defaults(): array
    {
        return [
            'status' => 1,
            'default_channel' => '',
            'default_quality' => '',
            'default_ratio' => '',
            'unit_price' => 0,
            'max_clothes_images' => self::MAX_CLOTHES_IMAGES,
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
        $data['max_clothes_images'] = self::normalizeMaxClothes($data['max_clothes_images'] ?? self::MAX_CLOTHES_IMAGES);
        $data['prompt_template'] = self::normalizeTemplate((string)($data['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE));
        $data['negative_prompt'] = trim((string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT));
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

    private static function normalizeImages(mixed $value): array
    {
        if (is_string($value)) {
            $value = $value !== '' ? explode(',', $value) : [];
        }
        if (!is_array($value)) {
            return [];
        }
        $images = [];
        foreach ($value as $item) {
            $image = trim((string)(is_array($item) ? ($item['uri'] ?? $item['url'] ?? $item['image'] ?? '') : $item));
            if ($image !== '') {
                $images[] = $image;
            }
        }
        return array_values(array_unique($images));
    }

    private static function normalizeImage(mixed $value): string
    {
        return trim((string)(is_array($value) ? ($value['uri'] ?? $value['url'] ?? $value['image'] ?? '') : $value));
    }

    private static function imageUrl(string $uri): string
    {
        if ($uri === '') {
            return '';
        }
        if (preg_match('/^https?:\/\//', $uri) || str_starts_with($uri, 'data:image/') || str_starts_with($uri, 'blob:')) {
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

    private static function normalizeTemplate(string $template): string
    {
        $template = trim($template);
        return $template !== '' ? $template : self::DEFAULT_PROMPT_TEMPLATE;
    }

    private static function normalizeMaxClothes(mixed $value): int
    {
        return max(1, min(self::MAX_CLOTHES_IMAGES, (int)$value ?: self::MAX_CLOTHES_IMAGES));
    }

    private static function normalizeCode(string $code): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($code)) ?: '';
    }
}
