<?php

namespace app\common\service\app\aigc_fitting;

use app\common\model\app\aigc_fitting\AigcFittingConfig;
use app\common\model\app\aigc_fitting\AigcFittingTask;
use app\common\model\app\App;
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\AppRegistryService;
use app\common\service\app\aigc_image\AigcImageChannelService;
use app\common\service\app\aigc_image\AigcImageAssetService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\FileService;
use app\common\service\membership\MembershipService;
use app\common\service\point\PointService;
use app\common\service\storage\StorageConfigService;
use Exception;
use think\facade\Db;

class AigcFittingService
{
    public const APP_CODE = 'aigc_fitting';
    public const IMAGE_APP_CODE = 'aigc_image';

    public const MODE_SINGLE = 'single';
    public const MODE_GROUP = 'group';
    public const MODE_CUSTOM = 'custom';

    public const MODE_LABELS = [
        self::MODE_SINGLE => '单图',
        self::MODE_GROUP => '组图',
        self::MODE_CUSTOM => '自定义模特',
    ];

    public const CATEGORY_LABELS = [
        'full' => '整套',
        'top' => '上装',
        'bottom' => '下装',
        'dress' => '连衣裙',
        'suit' => '套装',
    ];

    public const MODEL_GENDER_LABELS = [
        'all' => '全部模特',
        'male' => '男',
        'female' => '女',
        'child' => '儿童',
    ];

    public const MODEL_CLOTHES_LABELS = [
        'all' => '全部服饰',
        'sleeveless' => '无袖',
        'coat' => '外套',
        'long-pants' => '长裤',
        'long-skirt' => '长裙',
        'short-sleeve' => '短袖',
        'short-skirt' => '短裙',
        'long-sleeve' => '长袖',
        'short-pants' => '短裤',
    ];

    public const MODEL_POSE_LABELS = [
        'all' => '全部姿势',
        'full' => '全身',
        'half' => '半身',
    ];

    public const MODEL_MODE_LABELS = [
        self::MODE_SINGLE => '单图',
        self::MODE_GROUP => '组图',
    ];

    private const DEFAULT_PROMPT_TEMPLATE = '基于{garment_images}中的服装图片和{model_images}中的模特参考，生成{mode} AI试衣效果。服装分类为{garment_category}，模特筛选为{model_filter}，服装风格筛选为{clothes_filter}，姿势要求为{pose_filter}。保持服装版型、材质、颜色和图案准确，人物比例自然，画面真实清晰。{user_prompt}';
    private const DEFAULT_NEGATIVE_PROMPT = '服装变形，身体比例异常，手部畸形，多余肢体，低清晰度，文字，水印，严重穿模，脸部扭曲';

    public static function config(int $tenantId): array
    {
        $row = AigcFittingConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $data = $row->isEmpty() ? self::defaults() : array_merge(self::defaults(), $row->toArray());
        $data = self::sanitizeConfig($data);
        $data['config_json']['model_examples'] = self::appendExampleImageUrls($data['config_json']['model_examples'] ?? []);
        $data['mode_options'] = self::modeOptions();
        $data['category_options'] = self::categoryOptions();
        $data['model_gender_options'] = self::modelGenderOptions();
        $data['model_clothes_options'] = self::modelClothesOptions($data['config_json']['model_examples'] ?? []);
        $data['model_pose_options'] = self::modelPoseOptions($data['config_json']['model_examples'] ?? []);
        $data['option_config'] = AigcImageChannelService::userConfig($tenantId);
        return AppDisplayConfigService::appendToConfig($tenantId, self::APP_CODE, $data);
    }

    public static function saveConfig(int $tenantId, array $params): void
    {
        AppDisplayConfigService::saveFromConfigPayload($tenantId, self::APP_CODE, $params);
        $current = self::config($tenantId);
        $configJson = is_array($params['config_json'] ?? null) ? $params['config_json'] : [];
        $currentConfigJson = is_array($current['config_json'] ?? null) ? $current['config_json'] : [];
        foreach (['model_examples'] as $exampleKey) {
            if (!array_key_exists($exampleKey, $configJson) && array_key_exists($exampleKey, $currentConfigJson)) {
                $configJson[$exampleKey] = $currentConfigJson[$exampleKey];
            }
        }
        $data = [
            'tenant_id' => $tenantId,
            'status' => array_key_exists('status', $params) ? (int)$params['status'] : (int)$current['status'],
            'default_mode' => self::normalizeMode($params['default_mode'] ?? $current['default_mode']),
            'default_upload_category' => self::normalizeCategory($params['default_upload_category'] ?? $current['default_upload_category']),
            'prompt_template' => self::normalizeTemplate((string)($params['prompt_template'] ?? $current['prompt_template'])),
            'negative_prompt' => trim((string)($params['negative_prompt'] ?? $current['negative_prompt'])),
            'config_json' => self::normalizeConfigJson($configJson ?: ($current['config_json'] ?? [])),
            'update_time' => time(),
        ];

        $row = AigcFittingConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcFittingConfig::create($data);
            return;
        }
        $row->save($data);
    }

    public static function materialDetail(int $tenantId, string $type): array
    {
        $config = self::config($tenantId);
        $configJson = is_array($config['config_json'] ?? null) ? $config['config_json'] : [];
        $lists = self::normalizeExamples($configJson['model_examples'] ?? [], '模特', 'model');
        foreach ($lists as &$item) {
            $item['image_url'] = FileService::getFileUrl((string)$item['image']);
            $item['image_urls'] = self::imageUrls($item['images'] ?? []);
        }
        unset($item);
        return [
            'type' => 'model',
            'title' => '模特管理',
            'lists' => $lists,
            'model_mode_options' => self::modelModeOptions(),
            'category_options' => self::modelClothesOptions($lists),
            'gender_options' => self::modelGenderOptions(),
            'clothes_options' => self::modelClothesOptions($lists),
            'pose_options' => self::modelPoseOptions($lists, false),
        ];
    }

    public static function saveMaterial(int $tenantId, string $type, array $items): void
    {
        $current = self::config($tenantId);
        $configJson = is_array($current['config_json'] ?? null) ? $current['config_json'] : [];
        $modelExamples = self::normalizeExamples($items, '模特', 'model');
        self::assertValidModelExamples($modelExamples);
        $configJson['model_examples'] = $modelExamples;
        self::saveConfig($tenantId, [
            'status' => $current['status'] ?? 1,
            'default_mode' => $current['default_mode'] ?? self::MODE_SINGLE,
            'default_upload_category' => $current['default_upload_category'] ?? 'full',
            'prompt_template' => $current['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE,
            'negative_prompt' => $current['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT,
            'config_json' => $configJson,
        ]);
    }

    public static function estimate(int $tenantId, array $params): array
    {
        self::assertAvailable($tenantId);
        $prepared = self::prepareGeneratePayload($tenantId, $params, false);
        $imageEstimate = AigcImageService::estimate($tenantId, self::singleImagePayload($prepared['image_payload'], 1));
        return self::buildFittingEstimate($prepared, $imageEstimate);
    }

    public static function generate(int $tenantId, int $userId, array $params): array
    {
        self::assertAvailable($tenantId);
        $prepared = self::prepareGeneratePayload($tenantId, $params, true, $userId);
        $imageEstimate = AigcImageService::estimate($tenantId, self::singleImagePayload($prepared['image_payload'], 1));
        $estimate = self::buildFittingEstimate($prepared, $imageEstimate);
        PointService::assertCanConsumeAmounts(
            $tenantId,
            $userId,
            (float)$estimate['tenant_cost_points'],
            (float)$estimate['user_charge_points']
        );

        $imageResults = [];
        $imageTaskIds = [];
        $imageTaskId = 0;
        foreach (self::splitImagePayloads($prepared) as $index => $imagePayload) {
            $imageResult = AigcImageService::generateWithBillingOverride($tenantId, $userId, $imagePayload, [
                'tenant_cost_points' => $imageEstimate['platform_unit_cost'] ?? 0,
                'user_charge_points' => $prepared['unit_price'],
            ]);
            $currentImageTaskId = (int)($imageResult['task_id'] ?? 0);
            if ($currentImageTaskId <= 0) {
                throw new Exception('试衣任务创建失败');
            }
            if ($imageTaskId <= 0) {
                $imageTaskId = $currentImageTaskId;
            }
            $imageTaskIds[] = $currentImageTaskId;
            foreach (($imageResult['results'] ?? []) as $result) {
                $imageResults[] = $result;
            }
            if (($imageResult['status'] ?? '') === 'failed') {
                $task = self::upsertTaskFromImageTask($tenantId, $userId, $imageTaskId, $prepared, $estimate, $imageTaskIds);
                self::syncTaskFromImageTask($task);
                return [
                    'task_id' => (int)$task['id'],
                    'image_task_id' => $imageTaskId,
                    'image_task_ids' => $imageTaskIds,
                    'status' => 'failed',
                    'error' => (string)($imageResult['error'] ?? '生成失败'),
                    'results' => $imageResults,
                    'estimate' => $estimate,
                ];
            }
        }
        $task = self::upsertTaskFromImageTask($tenantId, $userId, $imageTaskId, $prepared, $estimate, $imageTaskIds);
        self::syncTaskFromImageTask($task);
        return [
            'task_id' => (int)$task['id'],
            'image_task_id' => $imageTaskId,
            'image_task_ids' => $imageTaskIds,
            'status' => (string)($task['status'] ?: 'running'),
            'results' => $imageResults,
            'estimate' => $estimate,
        ];
    }

    public static function taskLists(int $tenantId, int $userId = 0, array $params = []): array
    {
        if (!empty($params['sync_running'])) {
            self::refreshMappedTasks($tenantId, $userId);
        }
        $query = AigcFittingTask::alias('t')
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
        $query = AigcFittingTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
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
            'required_for' => '图片生成',
            'installed' => $installed,
            'tenant_enabled' => $tenantEnabled,
            'channel_ready' => !empty($channels),
            'ready' => $installed && $tenantEnabled && !empty($channels),
            'message' => $installed ? ($tenantEnabled ? (!empty($channels) ? '可用' : '暂无可用通道') : '租户未开通或未上架') : '应用未安装或未启用',
        ];
        return [
            'items' => [$item],
            'ready' => (bool)$item['ready'],
        ];
    }

    public static function stat(int $tenantId = 0): array
    {
        $task = AigcFittingTask::where('delete_time', 0);
        if ($tenantId > 0) {
            $task->where('tenant_id', $tenantId);
        }
        return [
            'task_total' => (int)(clone $task)->count(),
            'task_success' => (int)(clone $task)->where('status', 'success')->count(),
            'task_failed' => (int)(clone $task)->where('status', 'failed')->count(),
            'result_total' => (int)(clone $task)->where('status', 'success')->sum('quantity'),
            'tenant_cost_points' => round((float)(clone $task)->sum('tenant_cost_points'), 2),
            'user_charge_points' => round((float)(clone $task)->sum('user_charge_points'), 2),
            'dependencies' => self::dependencies($tenantId),
        ];
    }

    public static function tenantUsageLists(array $params = []): array
    {
        $tenantId = (int)($params['tenant_id'] ?? 0);
        $query = AigcFittingTask::where('delete_time', 0);
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        return $query
            ->field('tenant_id,count(*) as task_total,count(distinct user_id) as user_total,max(update_time) as last_task_time')
            ->fieldRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS task_success")
            ->fieldRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS task_failed")
            ->group('tenant_id')
            ->order('last_task_time', 'desc')
            ->limit(100)
            ->select()
            ->toArray();
    }

    public static function retryTask(int $tenantId, int $taskId): array
    {
        $task = AigcFittingTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        $payload = [
            'mode' => $task['mode'],
            'upload_category' => $task['upload_category'],
            'garment_images' => $task['garment_images'] ?: [],
            'model_images' => $task['model_images'] ?: [],
            'selected_preset_ids' => $task['selected_preset_ids'] ?: [],
            'model_filter' => $task['model_filter'],
            'clothes_filter' => $task['clothes_filter'],
            'pose_filter' => $task['pose_filter'],
            'prompt' => $task['user_prompt'],
            'channel' => $task['channel'],
            'quality' => $task['quality'],
            'ratio' => $task['ratio'],
            'quantity' => (int)$task['quantity'],
        ];
        return self::generate($tenantId, (int)$task['user_id'], $payload);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = AigcFittingTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
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
        $task->delete_time = time();
        $task->update_time = time();
        $task->save();
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0, int $imageTaskId = 0): void
    {
        if ($resultId > 0) {
            AigcImageService::deleteResult($tenantId, $resultId, $userId);
        }
        if ($imageTaskId > 0) {
            AigcImageService::deleteTask($tenantId, $imageTaskId, $userId);
            self::detachImageTask($tenantId, $imageTaskId, $userId);
        }
    }

    public static function modeOptions(): array
    {
        return array_map(fn($value, $label) => ['value' => $value, 'label' => $label], array_keys(self::MODE_LABELS), self::MODE_LABELS);
    }

    public static function modelModeOptions(): array
    {
        return self::labelOptions(self::MODEL_MODE_LABELS);
    }

    public static function categoryOptions(): array
    {
        return array_map(fn($value, $label) => ['value' => $value, 'label' => $label], array_keys(self::CATEGORY_LABELS), self::CATEGORY_LABELS);
    }

    public static function modelGenderOptions(): array
    {
        return self::labelOptions(self::MODEL_GENDER_LABELS);
    }

    public static function modelClothesOptions(mixed $items = []): array
    {
        return self::mergeDynamicOptions(self::MODEL_CLOTHES_LABELS, $items, 'category');
    }

    public static function modelPoseOptions(mixed $items = [], bool $includeAll = true): array
    {
        $labels = $includeAll
            ? self::MODEL_POSE_LABELS
            : array_diff_key(self::MODEL_POSE_LABELS, ['all' => true]);
        return self::labelOptions($labels);
    }

    private static function assertAvailable(int $tenantId): void
    {
        if (AppAccessService::assertTenantCanUse($tenantId, self::APP_CODE) !== null) {
            throw new Exception('AI试衣应用未开通或未上架');
        }
        if (AppAccessService::assertTenantCanUse($tenantId, self::IMAGE_APP_CODE) !== null) {
            throw new Exception('AIGC生图应用未开通或未上架');
        }
        $config = self::config($tenantId);
        if ((int)($config['status'] ?? 1) !== 1) {
            throw new Exception('AI试衣应用已停用');
        }
    }

    private static function prepareGeneratePayload(int $tenantId, array $params, bool $requireImages, int $userId = 0): array
    {
        $config = self::config($tenantId);
        $configJson = is_array($config['config_json'] ?? null) ? $config['config_json'] : [];
        $mode = self::normalizeMode($params['mode'] ?? $config['default_mode'] ?? self::MODE_SINGLE);
        $category = self::normalizeCategory($params['upload_category'] ?? $params['garment_category'] ?? $config['default_upload_category'] ?? 'full');
        $garmentImages = self::normalizeImages($params['garment_images'] ?? $params['clothes_images'] ?? $params['images'] ?? []);
        if (!$garmentImages) {
            $garmentImages = self::normalizeImages([
                $params['garment_image'] ?? '',
                $params['top_image'] ?? '',
                $params['bottom_image'] ?? '',
            ]);
        }
        $modelImages = self::normalizeImages($params['model_images'] ?? $params['model_image'] ?? []);
        $selectedPresetIds = self::normalizeStringList($params['selected_preset_ids'] ?? []);
        if ($requireImages && !$garmentImages) {
            throw new Exception('请上传服装图片');
        }
        if ($requireImages) {
            self::assertModelSelectionAllowed($tenantId, $userId, $mode, $selectedPresetIds, $modelImages, $configJson['model_examples'] ?? []);
        }

        $quantity = self::resolveQuantity($mode, $params, count($modelImages), $requireImages);
        if ($requireImages) {
            $garmentImages = self::prepareProviderImages($tenantId, $userId, $garmentImages);
            $modelImages = self::prepareProviderImages($tenantId, $userId, $modelImages);
        }
        $userPrompt = trim((string)($params['prompt'] ?? $params['user_prompt'] ?? ''));
        $prompt = self::renderPrompt((string)($config['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE), [
            'garment_images' => $garmentImages,
            'model_images' => $modelImages,
            'mode' => $mode,
            'garment_category' => $category,
            'model_filter' => trim((string)($params['model_filter'] ?? '')),
            'clothes_filter' => trim((string)($params['clothes_filter'] ?? '')),
            'pose_filter' => trim((string)($params['pose_filter'] ?? '')),
            'user_prompt' => $userPrompt,
        ]);
        $imagePayload = [
            'prompt' => $prompt,
            'negative_prompt' => (string)($params['negative_prompt'] ?? $config['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT),
            'reference_images' => array_values(array_unique(array_merge($garmentImages, $modelImages))),
            'channel' => (string)($params['channel'] ?? $configJson['channel'] ?? ''),
            'quality' => (string)($params['quality'] ?? $configJson['quality'] ?? ''),
            'ratio' => (string)($params['ratio'] ?? $configJson['ratio'] ?? ''),
            'quantity' => $quantity,
            'style' => 'fitting',
        ];
        return [
            'mode' => $mode,
            'upload_category' => $category,
            'garment_images' => $garmentImages,
            'model_images' => $modelImages,
            'selected_preset_ids' => $selectedPresetIds,
            'model_filter' => trim((string)($params['model_filter'] ?? '')),
            'clothes_filter' => trim((string)($params['clothes_filter'] ?? '')),
            'pose_filter' => trim((string)($params['pose_filter'] ?? '')),
            'user_prompt' => $userPrompt,
            'image_payload' => $imagePayload,
            'unit_price' => self::modeUnitPrice($configJson, $mode),
        ];
    }

    private static function buildFittingEstimate(array $prepared, array $imageEstimate): array
    {
        $quantity = max(1, (int)($prepared['image_payload']['quantity'] ?? 1));
        $tenantUnitCost = (float)($imageEstimate['platform_unit_cost'] ?? 0);
        $userUnitPrice = (float)$prepared['unit_price'];
        return array_merge($imageEstimate, [
            'mode' => $prepared['mode'],
            'mode_label' => self::MODE_LABELS[$prepared['mode']] ?? '单图',
            'quantity' => $quantity,
            'platform_unit_cost' => $tenantUnitCost,
            'tenant_unit_price' => $userUnitPrice,
            'tenant_cost_points' => round($tenantUnitCost * $quantity, 2),
            'user_charge_points' => round($userUnitPrice * $quantity, 2),
            'display_points' => round($userUnitPrice * $quantity, 2),
        ]);
    }

    private static function upsertTaskFromImageTask(int $tenantId, int $userId, int $imageTaskId, array $prepared, array $estimate, array $imageTaskIds = []): AigcFittingTask
    {
        $imageTask = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => $imageTaskId])->findOrEmpty();
        if ($imageTask->isEmpty()) {
            throw new Exception('生图任务不存在');
        }
        $row = AigcFittingTask::where(['tenant_id' => $tenantId, 'image_task_id' => $imageTaskId])->findOrEmpty();
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'image_task_id' => $imageTaskId,
            'image_task_ids' => $imageTaskIds ?: [$imageTaskId],
            'mode' => $prepared['mode'],
            'upload_category' => $prepared['upload_category'],
            'model_filter' => $prepared['model_filter'],
            'clothes_filter' => $prepared['clothes_filter'],
            'pose_filter' => $prepared['pose_filter'],
            'garment_images' => $prepared['garment_images'],
            'model_images' => $prepared['model_images'],
            'selected_preset_ids' => $prepared['selected_preset_ids'],
            'prompt' => $prepared['image_payload']['prompt'],
            'negative_prompt' => $prepared['image_payload']['negative_prompt'],
            'user_prompt' => $prepared['user_prompt'],
            'channel' => $imageTask['channel'],
            'quality' => $imageTask['quality'],
            'ratio' => $imageTask['ratio'],
            'quantity' => (int)($prepared['image_payload']['quantity'] ?? $estimate['quantity'] ?? $imageTask['quantity'] ?? 1),
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
            'status' => (string)($imageTask['status'] ?: 'running'),
            'error' => (string)$imageTask['error'],
            'finish_time' => (int)$imageTask['finish_time'],
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            return AigcFittingTask::create($data);
        }
        $row->save($data);
        return $row;
    }

    private static function syncTaskFromImageTask(AigcFittingTask $task): void
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
        $finishTimes = array_filter(array_map(static fn($row) => (int)($row['finish_time'] ?? 0), $imageTasks));
        $successCount = count(array_filter($statuses, static fn($status) => $status === 'success'));
        $terminalCount = count(array_filter($statuses, static fn($status) => in_array($status, ['success', 'failed', 'canceled'], true)));
        if ($successCount === count($imageTaskIds) || ($successCount > 0 && $terminalCount === count($imageTaskIds))) {
            $task->status = 'success';
        } elseif (in_array('failed', $statuses, true)) {
            $task->status = 'failed';
        } else {
            $task->status = 'running';
        }
        $errors = array_values(array_filter(array_map(static fn($row) => trim((string)($row['error'] ?? '')), $imageTasks)));
        $task->error = implode('；', array_unique($errors));
        $task->finish_time = in_array((string)$task->status, ['success', 'failed', 'canceled'], true) ? max($finishTimes ?: [time()]) : 0;
        $task->tenant_cost_points = number_format(array_sum(array_map(static fn($row) => (float)($row['tenant_cost_points'] ?? 0), $imageTasks)), 2, '.', '');
        $task->user_charge_points = number_format(array_sum(array_map(static fn($row) => (float)($row['user_charge_points'] ?? 0), $imageTasks)), 2, '.', '');
        $task->update_time = time();
        $task->save();
    }

    private static function refreshMappedTasks(int $tenantId, int $userId = 0, int $taskId = 0): void
    {
        $query = AigcFittingTask::where('tenant_id', $tenantId)->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($taskId > 0) {
            $query->where('id', $taskId);
        } else {
            $query->whereIn('status', ['running', 'pending', 'failed']);
        }
        $rows = $query->limit(20)->select();
        foreach ($rows as $row) {
            self::syncTaskFromImageTask($row);
        }
    }

    private static function appendTaskResults(int $tenantId, int $userId, array $rows): array
    {
        foreach ($rows as &$row) {
            $imageTaskIds = self::taskImageIds($row);
            $results = [];
            $resultTasks = [];
            foreach ($imageTaskIds as $imageTaskId) {
                try {
                    $imageTask = AigcImageService::taskDetail($tenantId, $imageTaskId, $userId > 0 ? (int)$row['user_id'] : 0);
                    $imageTask['reference_image_urls'] = self::imageUrls($imageTask['reference_images'] ?? []);
                    $resultTasks[] = $imageTask;
                    $results = array_merge($results, $imageTask['results'] ?? []);
                } catch (\Throwable) {
                }
            }
            $row['results'] = $results;
            $row['result_tasks'] = $resultTasks;
            $row['result_count'] = count($results);
            $first = $results[0] ?? [];
            $row['image_url'] = (string)($first['image_url'] ?? '');
            $row['image_uri'] = (string)($first['image_uri'] ?? '');
        }
        return $rows;
    }

    private static function detachImageTask(int $tenantId, int $imageTaskId, int $userId = 0): void
    {
        $query = AigcFittingTask::where('tenant_id', $tenantId)->where('delete_time', 0);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        foreach ($query->select() as $task) {
            $imageTaskIds = self::taskImageIds($task->toArray());
            if (!in_array($imageTaskId, $imageTaskIds, true)) {
                continue;
            }
            $nextImageTaskIds = array_values(array_filter($imageTaskIds, static fn($id) => (int)$id !== $imageTaskId));
            if (!$nextImageTaskIds) {
                $task->delete_time = time();
                $task->update_time = time();
                $task->save();
                continue;
            }
            $task->image_task_ids = $nextImageTaskIds;
            if ((int)$task['image_task_id'] === $imageTaskId) {
                $task->image_task_id = (int)$nextImageTaskIds[0];
            }
            $task->quantity = count($nextImageTaskIds);
            $task->update_time = time();
            $task->save();
            self::syncTaskFromImageTask($task);
        }
    }

    private static function formatTaskRow(array $row): array
    {
        $row['task_id'] = (int)($row['id'] ?? 0);
        $row['image_task_id'] = (int)($row['image_task_id'] ?? 0);
        $row['image_task_ids'] = self::taskImageIds($row);
        $row['mode_label'] = self::MODE_LABELS[$row['mode'] ?? ''] ?? '单图';
        $row['category_label'] = self::CATEGORY_LABELS[$row['upload_category'] ?? ''] ?? '整套';
        $row['garment_image_urls'] = self::imageUrls($row['garment_images'] ?? []);
        $row['model_image_urls'] = self::imageUrls($row['model_images'] ?? []);
        $row['reference_images'] = array_values(array_unique(array_merge($row['garment_image_urls'], $row['model_image_urls'])));
        $row['status_label'] = match ((string)($row['status'] ?? '')) {
            'success' => '已完成',
            'failed' => '失败',
            'canceled' => '已取消',
            default => '生成中',
        };
        return $row;
    }

    private static function imageUrls(mixed $images): array
    {
        $images = self::normalizeImages($images);
        $urls = [];
        foreach ($images as $image) {
            $urls[] = FileService::getFileUrl($image);
        }
        return array_values(array_filter($urls));
    }

    private static function appendExampleImageUrls(mixed $items): array
    {
        $items = is_array($items) ? $items : [];
        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $images = self::normalizeImages($item['images'] ?? [$item['image'] ?? '']);
            $item['image_url'] = FileService::getFileUrl((string)($item['image'] ?? ($images[0] ?? '')));
            $item['image_urls'] = self::imageUrls($images);
        }
        unset($item);
        return $items;
    }

    private static function prepareProviderImages(int $tenantId, int $userId, array $images): array
    {
        $prepared = [];
        foreach ($images as $image) {
            $url = self::prepareProviderImage($tenantId, $userId, (string)$image);
            if ($url !== '' && !in_array($url, $prepared, true)) {
                $prepared[] = $url;
            }
        }
        return $prepared;
    }

    private static function prepareProviderImage(int $tenantId, int $userId, string $image): string
    {
        $image = trim($image);
        if ($image === '') {
            return '';
        }
        if (str_starts_with($image, 'data:image/')) {
            $stored = AigcImageAssetService::persistGeneratedImage($image, $tenantId, $userId);
            return FileService::getFileUrl((string)($stored['uri'] ?? ''));
        }

        $url = (str_starts_with($image, 'http://') || str_starts_with($image, 'https://'))
            ? $image
            : FileService::getFileUrl($image);

        if (self::shouldMirrorProviderImage($tenantId, $url)) {
            try {
                $stored = AigcImageAssetService::persistGeneratedImage($url, $tenantId, $userId);
                $storedUri = (string)($stored['uri'] ?? '');
                if ($storedUri !== '') {
                    return FileService::getFileUrl($storedUri);
                }
            } catch (\Throwable) {
                return $url;
            }
        }
        return $url;
    }

    private static function shouldMirrorProviderImage(int $tenantId, string $url): bool
    {
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            return false;
        }
        if (StorageConfigService::getEffectiveDefault($tenantId) === 'local') {
            return false;
        }
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
        if ($path === '') {
            return false;
        }
        $localUri = ltrim($path, '/');
        if (!is_file(public_path() . $localUri)) {
            return false;
        }
        $storageDomain = rtrim(StorageConfigService::getEffectiveDomain($tenantId), '/');
        return $storageDomain === '' || !str_starts_with($url, $storageDomain . '/');
    }

    private static function renderPrompt(string $template, array $data): string
    {
        $template = self::normalizeTemplate($template);
        $extra = trim((string)($data['user_prompt'] ?? ''));
        return trim(strtr($template, [
            '{garment_images}' => '服装参考图',
            '{model_images}' => '模特参考图',
            '{mode}' => self::MODE_LABELS[$data['mode']] ?? '单图',
            '{garment_category}' => self::CATEGORY_LABELS[$data['garment_category']] ?? '整套',
            '{model_filter}' => (string)($data['model_filter'] ?: '不限'),
            '{clothes_filter}' => (string)($data['clothes_filter'] ?: '不限'),
            '{pose_filter}' => (string)($data['pose_filter'] ?: '不限'),
            '{user_prompt}' => $extra !== '' ? '用户补充要求：' . $extra . '。' : '',
        ]));
    }

    private static function resolveQuantity(string $mode, array $params, int $modelCount, bool $strict = false): int
    {
        $quantity = (int)($params['quantity'] ?? 0);
        if ($modelCount > 0) {
            $quantity = $modelCount;
        }
        if ($quantity <= 0) {
            $quantity = $mode === self::MODE_GROUP ? 2 : 1;
        }
        if ($strict && ($quantity < 1 || $quantity > 4)) {
            throw new Exception('模特图片数量需在1-4张之间');
        }
        return max(1, min(4, $quantity));
    }

    private static function splitImagePayloads(array $prepared): array
    {
        $payload = $prepared['image_payload'];
        $payload['quantity'] = 1;
        $modelImages = array_values($prepared['model_images'] ?? []);
        $quantity = max(1, (int)($prepared['image_payload']['quantity'] ?? 1));
        $items = [];
        for ($index = 0; $index < $quantity; $index++) {
            $item = $payload;
            $currentModelImages = isset($modelImages[$index]) ? [$modelImages[$index]] : $modelImages;
            $item['reference_images'] = array_values(array_unique(array_merge($prepared['garment_images'] ?? [], $currentModelImages)));
            $items[] = $item;
        }
        return $items ?: [$payload];
    }

    private static function assertModelSelectionAllowed(int $tenantId, int $userId, string $mode, array $selectedPresetIds, array $modelImages, mixed $modelExamples): void
    {
        $modelCount = count($modelImages);
        if ($mode === self::MODE_CUSTOM) {
            if ($modelCount < 1 || $modelCount > 4) {
                throw new Exception('请上传1-4张模特图片');
            }
            return;
        }

        $modelExamples = self::normalizeExamples($modelExamples, '模特示例', 'model');
        $enabledMap = [];
        foreach ($modelExamples as $item) {
            if ((int)($item['status'] ?? 1) !== 1) {
                continue;
            }
            $enabledMap[(string)$item['id']] = $item;
        }

        if ($mode === self::MODE_SINGLE) {
            if (count($selectedPresetIds) < 1 || count($selectedPresetIds) > 4) {
                throw new Exception('请选择1-4个单图模特');
            }
            $expectedImages = [];
            foreach ($selectedPresetIds as $id) {
                $item = $enabledMap[$id] ?? null;
                if (!$item || ($item['mode'] ?? self::MODE_SINGLE) !== self::MODE_SINGLE) {
                    throw new Exception('请选择启用的单图模特');
                }
                $expectedImages[] = (string)($item['image'] ?? '');
            }
            if ($modelCount < 1 || $modelCount > 4) {
                throw new Exception('请选择1-4个单图模特');
            }
            if (!self::imagesMatch($expectedImages, $modelImages)) {
                throw new Exception('所选单图模特与提交图片不一致');
            }
        } elseif ($mode === self::MODE_GROUP) {
            if (count($selectedPresetIds) !== 1) {
                throw new Exception('请选择一个组图模特');
            }
            $item = $enabledMap[(string)$selectedPresetIds[0]] ?? null;
            if (!$item || ($item['mode'] ?? self::MODE_SINGLE) !== self::MODE_GROUP) {
                throw new Exception('请选择启用的组图模特');
            }
            $images = self::normalizeImages($item['images'] ?? []);
            if (count($images) < 2 || count($images) > 4 || $modelCount < 2 || $modelCount > 4) {
                throw new Exception('组图模特需包含2-4张姿势图');
            }
            if (!self::imagesMatch($images, $modelImages)) {
                throw new Exception('所选组图模特与提交图片不一致');
            }
        }

        self::assertVipModelAllowed($tenantId, $userId, $selectedPresetIds, $modelImages, $modelExamples);
    }

    private static function assertVipModelAllowed(int $tenantId, int $userId, array $selectedPresetIds, array $modelImages, mixed $modelExamples): void
    {
        if (!$selectedPresetIds && !$modelImages) {
            return;
        }
        $modelExamples = self::normalizeExamples($modelExamples, '模特示例', 'model');
        $selectedMap = array_flip($selectedPresetIds);
        $selectedImageMap = array_flip(self::normalizeComparableImages($modelImages));
        $hasVip = false;
        foreach ($modelExamples as $item) {
            $imageKeys = [];
            foreach (self::normalizeImages($item['images'] ?? [$item['image'] ?? '']) as $image) {
                $imageKeys = array_merge($imageKeys, self::normalizeComparableImages([
                    $image,
                    FileService::getFileUrl($image),
                ]));
            }
            $imageMatched = false;
            foreach ($imageKeys as $imageKey) {
                if (isset($selectedImageMap[$imageKey])) {
                    $imageMatched = true;
                    break;
                }
            }
            if ((isset($selectedMap[(string)$item['id']]) || $imageMatched) && (int)($item['vip'] ?? 0) === 1) {
                $hasVip = true;
                break;
            }
        }
        if (!$hasVip) {
            return;
        }
        $membership = $userId > 0 ? MembershipService::status($tenantId, $userId) : [];
        if (($membership['member_status'] ?? MembershipService::MEMBER_NONE) !== MembershipService::MEMBER_ACTIVE) {
            throw new Exception('该模特为 VIP 专属，请开通会员后使用');
        }
    }

    private static function singleImagePayload(array $payload, int $quantity = 1): array
    {
        $payload['quantity'] = max(1, $quantity);
        return $payload;
    }

    private static function imagesMatch(array $expected, array $actual): bool
    {
        $expectedImages = self::normalizeImages($expected);
        $actualImages = self::normalizeImages($actual);
        if (!$expectedImages || !$actualImages || count($expectedImages) !== count($actualImages)) {
            return false;
        }
        $actualKeyMap = array_flip(self::normalizeComparableImages(self::withFileUrls($actualImages)));
        foreach ($expectedImages as $image) {
            if (!self::hasImageKeyMatch(self::normalizeComparableImages(self::withFileUrls([$image])), $actualKeyMap)) {
                return false;
            }
        }
        $expectedKeyMap = array_flip(self::normalizeComparableImages(self::withFileUrls($expectedImages)));
        foreach ($actualImages as $image) {
            if (!self::hasImageKeyMatch(self::normalizeComparableImages(self::withFileUrls([$image])), $expectedKeyMap)) {
                return false;
            }
        }
        return true;
    }

    private static function hasImageKeyMatch(array $keys, array $targetMap): bool
    {
        foreach ($keys as $key) {
            if (isset($targetMap[$key])) {
                return true;
            }
        }
        return false;
    }

    private static function withFileUrls(array $images): array
    {
        $values = [];
        foreach (self::normalizeImages($images) as $image) {
            $values[] = $image;
            $values[] = FileService::getFileUrl($image);
        }
        return $values;
    }

    private static function taskImageIds(array $task): array
    {
        $ids = $task['image_task_ids'] ?? [];
        if (is_string($ids) && $ids !== '') {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : [$ids];
        }
        if (!is_array($ids)) {
            $ids = [];
        }
        if ((int)($task['image_task_id'] ?? 0) > 0) {
            array_unshift($ids, (int)$task['image_task_id']);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        return $ids;
    }

    private static function modeUnitPrice(array $configJson, string $mode): float
    {
        $prices = is_array($configJson['mode_prices'] ?? null) ? $configJson['mode_prices'] : [];
        $defaults = self::defaultModePrices();
        return max(0, round((float)($prices[$mode] ?? $defaults[$mode] ?? 0), 2));
    }

    private static function normalizeMode(mixed $mode): string
    {
        $mode = (string)$mode;
        return array_key_exists($mode, self::MODE_LABELS) ? $mode : self::MODE_SINGLE;
    }

    private static function normalizeModelMode(mixed $mode): string
    {
        $mode = (string)$mode;
        return array_key_exists($mode, self::MODEL_MODE_LABELS) ? $mode : self::MODE_SINGLE;
    }

    private static function normalizeCategory(mixed $category): string
    {
        $category = (string)$category;
        return array_key_exists($category, self::CATEGORY_LABELS) ? $category : 'full';
    }

    private static function normalizeTemplate(string $template): string
    {
        $template = trim($template);
        return $template !== '' ? $template : self::DEFAULT_PROMPT_TEMPLATE;
    }

    private static function normalizeImages(mixed $images): array
    {
        if (is_string($images)) {
            $images = [$images];
        }
        $images = is_array($images) ? $images : [];
        $normalized = [];
        foreach ($images as $image) {
            if (is_array($image)) {
                $image = $image['image'] ?? $image['url'] ?? $image['uri'] ?? '';
            }
            $image = trim((string)$image);
            if ($image !== '' && !in_array($image, $normalized, true)) {
                $normalized[] = $image;
            }
        }
        return $normalized;
    }

    private static function normalizeStringList(mixed $items): array
    {
        if (is_string($items)) {
            $items = [$items];
        }
        $items = is_array($items) ? $items : [];
        $normalized = [];
        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item !== '' && !in_array($item, $normalized, true)) {
                $normalized[] = $item;
            }
        }
        return $normalized;
    }

    private static function normalizeComparableImages(array $images): array
    {
        $normalized = [];
        foreach (self::normalizeImages($images) as $image) {
            $parts = parse_url($image);
            $path = isset($parts['path']) ? ltrim((string)$parts['path'], '/') : ltrim($image, '/');
            foreach (array_unique([$image, $path]) as $value) {
                $value = trim($value);
                if ($value !== '' && !in_array($value, $normalized, true)) {
                    $normalized[] = $value;
                }
            }
        }
        return $normalized;
    }

    private static function normalizeConfigJson(mixed $config): array
    {
        $config = is_array($config) ? $config : [];
        return [
            'channel' => trim((string)($config['channel'] ?? '')),
            'quality' => trim((string)($config['quality'] ?? '')),
            'ratio' => trim((string)($config['ratio'] ?? '')),
            'quantity' => max(1, min(4, (int)($config['quantity'] ?? 1))),
            'mode_prices' => self::normalizeModePrices($config['mode_prices'] ?? []),
            'model_examples' => self::normalizeExamples($config['model_examples'] ?? [], '模特示例', 'model'),
        ];
    }

    private static function normalizeModePrices(mixed $prices): array
    {
        $prices = is_array($prices) ? $prices : [];
        $defaults = self::defaultModePrices();
        return [
            self::MODE_SINGLE => max(0, round((float)($prices[self::MODE_SINGLE] ?? $defaults[self::MODE_SINGLE]), 2)),
            self::MODE_GROUP => max(0, round((float)($prices[self::MODE_GROUP] ?? $defaults[self::MODE_GROUP]), 2)),
            self::MODE_CUSTOM => max(0, round((float)($prices[self::MODE_CUSTOM] ?? $defaults[self::MODE_CUSTOM]), 2)),
        ];
    }

    private static function normalizeExamples(mixed $items, string $defaultName, string $type = 'garment'): array
    {
        $type = $type === 'model' ? 'model' : 'garment';
        $items = is_array($items) ? $items : [];
        $normalized = [];
        foreach ($items as $index => $item) {
            $item = is_array($item) ? $item : ['image' => $item];
            $images = self::normalizeImages($item['images'] ?? []);
            $legacyImage = trim((string)($item['image'] ?? $item['url'] ?? $item['uri'] ?? ''));
            if ($legacyImage !== '') {
                array_unshift($images, $legacyImage);
            }
            $images = array_values(array_unique(array_filter($images)));
            $mode = $type === 'model' ? self::normalizeModelMode($item['mode'] ?? self::MODE_SINGLE) : self::MODE_SINGLE;
            if ($type === 'model') {
                $images = array_slice($images, 0, $mode === self::MODE_GROUP ? 4 : 1);
            }
            $image = (string)($images[0] ?? '');
            if ($image === '') {
                continue;
            }
            $normalized[] = [
                'id' => trim((string)($item['id'] ?? md5($image))),
                'name' => trim((string)($item['name'] ?? $defaultName . ((int)$index + 1))),
                'mode' => $mode,
                'image' => $image,
                'images' => $images,
                'category' => $type === 'model'
                    ? self::normalizeModelOptionValue($item['category'] ?? 'sleeveless', 'sleeveless')
                    : self::normalizeCategory($item['category'] ?? 'full'),
                'gender' => $type === 'model'
                    ? self::normalizeModelGender($item['gender'] ?? 'female')
                    : trim((string)($item['gender'] ?? '')),
                'pose' => $type === 'model'
                    ? self::normalizeModelPose($item['pose'] ?? 'full')
                    : trim((string)($item['pose'] ?? '')),
                'group' => trim((string)($item['group'] ?? '')),
                'vip' => (int)($item['vip'] ?? 0),
                'sort' => (int)($item['sort'] ?? $index),
                'status' => (int)($item['status'] ?? 1),
            ];
            if (count($normalized) >= 60) {
                break;
            }
        }
        usort($normalized, fn($a, $b) => ((int)$a['sort'] <=> (int)$b['sort']));
        return $normalized;
    }

    private static function assertValidModelExamples(array $items): void
    {
        foreach ($items as $item) {
            $mode = self::normalizeModelMode($item['mode'] ?? self::MODE_SINGLE);
            $count = count(self::normalizeImages($item['images'] ?? []));
            if ($mode === self::MODE_SINGLE && $count !== 1) {
                throw new Exception('单图模特需上传1张图片');
            }
            if ($mode === self::MODE_GROUP && ($count < 2 || $count > 4)) {
                throw new Exception('组图模特需上传2-4张不同姿势图');
            }
        }
    }

    private static function sanitizeConfig(array $data): array
    {
        $data['status'] = (int)($data['status'] ?? 1);
        $data['default_mode'] = self::normalizeMode($data['default_mode'] ?? self::MODE_SINGLE);
        $data['default_upload_category'] = self::normalizeCategory($data['default_upload_category'] ?? 'full');
        $data['prompt_template'] = self::normalizeTemplate((string)($data['prompt_template'] ?? ''));
        $data['negative_prompt'] = trim((string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT));
        $data['config_json'] = self::normalizeConfigJson($data['config_json'] ?? []);
        return $data;
    }

    private static function labelOptions(array $labels): array
    {
        return array_map(fn($value, $label) => ['value' => $value, 'label' => $label], array_keys($labels), $labels);
    }

    private static function mergeDynamicOptions(array $defaults, mixed $items, string $field): array
    {
        $labels = $defaults;
        $items = is_array($items) ? $items : [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $value = self::normalizeModelOptionValue($item[$field] ?? '', '');
            if ($value === '') {
                continue;
            }
            $label = trim((string)($item[$field . '_label'] ?? ''));
            $labels[$value] = $label !== '' ? $label : ($labels[$value] ?? $value);
        }
        return self::labelOptions($labels);
    }

    private static function normalizeModelGender(mixed $gender): string
    {
        $gender = self::normalizeModelOptionValue($gender, 'all');
        return array_key_exists($gender, self::MODEL_GENDER_LABELS) ? $gender : 'all';
    }

    private static function normalizeModelPose(mixed $pose): string
    {
        $pose = self::normalizeModelOptionValue($pose, 'full');
        $map = [
            'full_body' => 'full',
            'half_body' => 'half',
            '全身' => 'full',
            '半身' => 'half',
        ];
        $pose = $map[$pose] ?? $pose;
        return in_array($pose, ['full', 'half'], true) ? $pose : 'full';
    }

    private static function normalizeModelOptionValue(mixed $value, string $default): string
    {
        $value = trim((string)$value);
        return $value !== '' ? $value : $default;
    }

    private static function defaults(): array
    {
        return [
            'id' => 0,
            'tenant_id' => 0,
            'status' => 1,
            'default_mode' => self::MODE_SINGLE,
            'default_upload_category' => 'full',
            'prompt_template' => self::DEFAULT_PROMPT_TEMPLATE,
            'negative_prompt' => self::DEFAULT_NEGATIVE_PROMPT,
            'config_json' => self::normalizeConfigJson([]),
            'create_time' => 0,
            'update_time' => 0,
        ];
    }

    private static function defaultModePrices(): array
    {
        return [
            self::MODE_SINGLE => 10,
            self::MODE_GROUP => 10,
            self::MODE_CUSTOM => 12,
        ];
    }
}
