<?php

namespace app\common\service\app\aigc_one_click_cleanup;

use app\common\model\app\App;
use app\common\model\app\aigc_image\AigcImageResult;
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\model\app\aigc_one_click_cleanup\AigcOneClickCleanupConfig;
use app\common\model\app\aigc_one_click_cleanup\AigcOneClickCleanupOption;
use app\common\model\app\aigc_one_click_cleanup\AigcOneClickCleanupResult;
use app\common\model\app\aigc_one_click_cleanup\AigcOneClickCleanupTask;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\AppRegistryService;
use app\common\service\app\aigc_image\AigcImageChannelService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\FileService;
use app\common\service\point\PointService;
use Exception;

class AigcOneClickCleanupService
{
    public const APP_CODE = 'aigc_one_click_cleanup';
    public const IMAGE_APP_CODE = 'aigc_image';
    public const MAX_IMAGES = 10;

    private const DEFAULT_PROMPT_TEMPLATE = '针对用户上传的主体图执行一键消除，重点清理所选干扰内容，尽量保留主体结构、材质细节、边缘过渡、原始光影和画面完整度，输出更干净、自然、可直接使用的结果图。';
    private const DEFAULT_NEGATIVE_PROMPT = '主体缺失，主体变形，材质丢失，边缘破损，画面涂抹，低清晰度，新增水印，新增文字，新增图标，过度锐化，色偏';

    private const DEFAULT_OPTIONS = [
        'watermark' => ['name' => '消除水印', 'description' => '清理图片中的水印、半透明标识和遮挡印记', 'prompt' => '移除图片中的水印、半透明标识、品牌遮挡印记，保持原背景纹理自然连续。', 'sort' => 100],
        'icon' => ['name' => '消除Icon', 'description' => '清理多余图标、贴纸和角标元素', 'prompt' => '移除图片中的多余图标、贴纸、角标和装饰符号，补全被遮挡区域的纹理和光影。', 'sort' => 90],
        'sticker' => ['name' => '消除牛皮癣', 'description' => '清理广告贴片、杂乱标注和干扰块', 'prompt' => '移除广告贴片、促销标签、杂乱标注、噪点块和影响画面观感的干扰内容。', 'sort' => 80],
        'text' => ['name' => '消除文字', 'description' => '清理图片中的文字、标题和说明标注', 'prompt' => '移除图片中的文字、标题、说明标注和局部字幕，保持背景、商品或人物结构完整。', 'sort' => 70],
    ];

    public static function config(int $tenantId): array
    {
        $row = AigcOneClickCleanupConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $data = $row->isEmpty() ? self::defaults() : array_merge(self::defaults(), $row->toArray());
        $data = self::sanitizeConfig($data);
        $optionConfig = AigcImageChannelService::userConfig($tenantId);
        $data['option_config'] = $optionConfig;
        $data['spec_options'] = self::buildSpecOptions($optionConfig);
        $data['options'] = self::optionLists($tenantId, true);
        $data['max_images'] = self::MAX_IMAGES;
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
            'max_images' => self::MAX_IMAGES,
            'prompt_template' => self::normalizeTemplate((string)($params['prompt_template'] ?? $current['prompt_template'])),
            'negative_prompt' => trim((string)($params['negative_prompt'] ?? $current['negative_prompt'])),
            'config_json' => self::normalizeConfigJson($configJson),
            'update_time' => time(),
        ];
        $row = AigcOneClickCleanupConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcOneClickCleanupConfig::create($data);
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
            'prompt_template' => $current['prompt_template'],
            'negative_prompt' => $current['negative_prompt'],
            'config_json' => [
                'channel' => $params['default_channel'] ?? $current['default_channel'],
                'quality' => $params['default_quality'] ?? $current['default_quality'],
                'ratio' => $params['default_ratio'] ?? $current['default_ratio'],
            ],
        ]);
    }

    public static function optionLists(int $tenantId, bool $onlyEnabled = false): array
    {
        self::ensureDefaultOptions($tenantId);
        $query = AigcOneClickCleanupOption::where('tenant_id', $tenantId)->where('delete_time', 0)->order('sort', 'desc')->order('id', 'asc');
        if ($onlyEnabled) {
            $query->where('status', 1);
        }
        $rows = $query->select()->toArray();
        foreach ($rows as &$row) {
            $row['cover_url'] = self::imageUrl((string)($row['cover_image'] ?? ''));
            $row['is_builtin'] = (int)($row['is_builtin'] ?? 0);
        }
        return $rows;
    }

    public static function saveOption(int $tenantId, array $params): array
    {
        self::ensureDefaultOptions($tenantId);
        $id = (int)($params['id'] ?? 0);
        $code = self::normalizeOptionCode((string)($params['code'] ?? ''));
        $row = $id > 0 ? AigcOneClickCleanupOption::where(['tenant_id' => $tenantId, 'id' => $id])->where('delete_time', 0)->findOrEmpty() : AigcOneClickCleanupOption::where(['tenant_id' => $tenantId, 'code' => $code])->where('delete_time', 0)->findOrEmpty();
        if ($id <= 0 && $code === '') {
            $code = 'custom_' . time();
        }
        if ($row->isEmpty() && AigcOneClickCleanupOption::where(['tenant_id' => $tenantId, 'code' => $code])->where('delete_time', 0)->count() > 0) {
            throw new Exception('选项标识已存在');
        }
        if (!$row->isEmpty()) {
            $code = (string)$row['code'];
        }
        $name = mb_substr(trim((string)($params['name'] ?? '')), 0, 80);
        if ($name === '') {
            throw new Exception('请输入选项名称');
        }
        $data = [
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'description' => mb_substr(trim((string)($params['description'] ?? '')), 0, 200),
            'prompt' => mb_substr(trim((string)($params['prompt'] ?? '')), 0, 1000),
            'cover_image' => trim((string)($params['cover_image'] ?? '')),
            'status' => (int)($params['status'] ?? 1) ? 1 : 0,
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $data['is_builtin'] = 0;
            $data['delete_time'] = 0;
            $data['create_time'] = time();
            $row = AigcOneClickCleanupOption::create($data);
        } else {
            $row->save($data);
        }
        return $row->toArray();
    }

    public static function setOptionStatus(int $tenantId, array $params): void
    {
        $code = self::normalizeOptionCode((string)($params['code'] ?? ''));
        $id = (int)($params['id'] ?? 0);
        $query = AigcOneClickCleanupOption::where('tenant_id', $tenantId)->where('delete_time', 0);
        $id > 0 ? $query->where('id', $id) : $query->where('code', $code);
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('消除选项不存在');
        }
        $row->save(['status' => (int)($params['status'] ?? 1) ? 1 : 0, 'update_time' => time()]);
    }

    public static function deleteOption(int $tenantId, array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $row = AigcOneClickCleanupOption::where(['tenant_id' => $tenantId, 'id' => $id])->where('delete_time', 0)->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('消除选项不存在');
        }
        if ((int)$row['is_builtin'] === 1) {
            throw new Exception('内置消除选项不允许删除');
        }
        $row->save(['delete_time' => time(), 'update_time' => time()]);
    }

    public static function estimate(int $tenantId, array $params): array
    {
        self::assertAvailable($tenantId);
        $prepared = self::prepareGeneratePayload($tenantId, $params, false);
        $singleEstimate = AigcImageService::estimate($tenantId, $prepared['image_payload']);
        return self::buildEstimate($prepared, $singleEstimate);
    }

    public static function generate(int $tenantId, int $userId, array $params): array
    {
        self::assertAvailable($tenantId);
        $prepared = self::prepareGeneratePayload($tenantId, $params, true);
        $singleEstimate = AigcImageService::estimate($tenantId, $prepared['image_payload']);
        $estimate = self::buildEstimate($prepared, $singleEstimate);
        PointService::assertCanConsumeAmounts($tenantId, $userId, (float)$estimate['tenant_cost_points'], (float)$estimate['user_charge_points']);
        $batchNo = 'cleanup_' . date('YmdHis') . '_' . substr(md5($tenantId . '_' . $userId . '_' . microtime(true)), 0, 8);
        $imageTaskIds = [];
        foreach ($prepared['source_images'] as $sourceImage) {
            $payload = array_merge($prepared['image_payload'], ['reference_images' => [$sourceImage]]);
            $result = AigcImageService::generateWithBillingOverride($tenantId, $userId, $payload, [
                'tenant_cost_points' => $estimate['platform_unit_cost'],
                'user_charge_points' => $prepared['unit_price'],
            ]);
            $imageTaskId = (int)($result['task_id'] ?? 0);
            if ($imageTaskId > 0) {
                $imageTaskIds[] = $imageTaskId;
            }
        }
        if (!$imageTaskIds) {
            throw new Exception('一键消除任务创建失败');
        }
        $task = self::createBatchTask($tenantId, $userId, $batchNo, $imageTaskIds, $prepared, $estimate);
        self::syncTaskFromImageTask($task);
        return [
            'task_id' => (int)$task['id'],
            'batch_no' => $batchNo,
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
        $query = AigcOneClickCleanupTask::alias('t')
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
        $optionCode = self::normalizeOptionCode((string)($params['option_code'] ?? ''));
        if ($optionCode !== '') {
            $query->whereLike('t.option_codes', '%"' . $optionCode . '"%');
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
        $query = AigcOneClickCleanupTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
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
        $task = AigcOneClickCleanupTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return self::generate($tenantId, (int)$task['user_id'], [
            'source_images' => $task['source_images'],
            'option_codes' => $task['option_codes'],
        ]);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = AigcOneClickCleanupTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
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
        AigcOneClickCleanupResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time()]);
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = AigcOneClickCleanupResult::where(['tenant_id' => $tenantId, 'id' => $resultId]);
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('作品不存在');
        }
        $row->save(['delete_time' => time(), 'update_time' => time()]);
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
            'required_for' => '一键消除生成',
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
            throw new Exception('一键消除应用未开通或未上架');
        }
        if (AppAccessService::assertTenantCanUse($tenantId, self::IMAGE_APP_CODE) !== null) {
            throw new Exception('AIGC生图应用未开通或未上架');
        }
        $config = self::config($tenantId);
        if ((int)($config['status'] ?? 1) !== 1) {
            throw new Exception('一键消除应用已停用');
        }
    }

    private static function prepareGeneratePayload(int $tenantId, array $params, bool $requireImage): array
    {
        $config = self::config($tenantId);
        $sourceImages = self::normalizeImages($params['source_images'] ?? $params['images'] ?? $params['source_image'] ?? []);
        if ($requireImage && !$sourceImages) {
            throw new Exception('请上传主体图');
        }
        if (count($sourceImages) > self::MAX_IMAGES) {
            throw new Exception('一次最多上传' . self::MAX_IMAGES . '张图片');
        }
        $optionCodes = self::normalizeOptionCodes($params['option_codes'] ?? $params['options'] ?? []);
        if (!$optionCodes) {
            throw new Exception('请选择消除选项');
        }
        $options = self::resolveOptions($tenantId, $optionCodes);
        $channel = (string)($config['default_channel'] ?: ($config['config_json']['channel'] ?? ''));
        $quality = (string)($config['default_quality'] ?: ($config['config_json']['quality'] ?? ''));
        $ratio = (string)($config['default_ratio'] ?: ($config['config_json']['ratio'] ?? ''));
        $prompt = self::buildPrompt($config, $options, count($sourceImages));
        $imagePayload = [
            'prompt' => $prompt,
            'negative_prompt' => (string)($params['negative_prompt'] ?? $config['negative_prompt']),
            'reference_images' => $sourceImages ? [$sourceImages[0]] : [],
            'channel' => $channel,
            'quality' => $quality,
            'ratio' => $ratio,
            'quantity' => 1,
            'style' => 'one_click_cleanup',
        ];
        $resolved = AigcImageChannelService::resolveSelection($tenantId, $imagePayload);
        $unitPrice = round(max(0, (float)($config['unit_price'] ?? 0)), 2);
        return [
            'source_images' => $sourceImages,
            'option_codes' => array_values(array_column($options, 'code')),
            'options' => $options,
            'unit_price' => $unitPrice,
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

    private static function buildEstimate(array $prepared, array $singleEstimate): array
    {
        $quantity = max(1, count($prepared['source_images']));
        $platformUnitCost = round((float)($singleEstimate['platform_unit_cost'] ?? 0), 2);
        $userUnitPrice = round((float)$prepared['unit_price'], 2);
        return array_merge($singleEstimate, [
            'quantity' => $quantity,
            'image_count' => $quantity,
            'target_width' => $prepared['width'],
            'target_height' => $prepared['height'],
            'size_key' => $prepared['size_key'],
            'options' => $prepared['options'],
            'option_codes' => $prepared['option_codes'],
            'platform_unit_cost' => $platformUnitCost,
            'tenant_unit_price' => $userUnitPrice,
            'unit_price' => $userUnitPrice,
            'tenant_cost_points' => round($platformUnitCost * $quantity, 2),
            'user_charge_points' => round($userUnitPrice * $quantity, 2),
            'display_points' => round($userUnitPrice * $quantity, 2),
        ]);
    }

    private static function createBatchTask(int $tenantId, int $userId, string $batchNo, array $imageTaskIds, array $prepared, array $estimate): AigcOneClickCleanupTask
    {
        $firstImageTask = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => $imageTaskIds[0]])->findOrEmpty();
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'batch_no' => $batchNo,
            'image_task_id' => (int)$imageTaskIds[0],
            'image_task_ids' => $imageTaskIds,
            'source_images' => $prepared['source_images'],
            'option_codes' => $prepared['option_codes'],
            'option_snapshot' => $prepared['options'],
            'size_key' => $prepared['size_key'],
            'width' => $prepared['width'],
            'height' => $prepared['height'],
            'prompt' => $prepared['image_payload']['prompt'],
            'negative_prompt' => $prepared['image_payload']['negative_prompt'],
            'channel' => (string)($firstImageTask['channel'] ?? $prepared['image_payload']['channel']),
            'quality' => (string)($firstImageTask['quality'] ?? $prepared['image_payload']['quality']),
            'quality_label' => $prepared['quality_label'],
            'ratio' => (string)($firstImageTask['ratio'] ?? $prepared['image_payload']['ratio']),
            'quantity' => count($prepared['source_images']),
            'unit_price' => $prepared['unit_price'],
            'tenant_cost_points' => $estimate['tenant_cost_points'],
            'user_charge_points' => $estimate['user_charge_points'],
            'status' => 'running',
            'error' => '',
            'finish_time' => 0,
            'delete_time' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ];
        return AigcOneClickCleanupTask::create($data);
    }

    private static function syncTaskFromImageTask(AigcOneClickCleanupTask $task): void
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
        $statuses = array_map(static fn($row) => (string)($row['status'] ?? ''), $imageTasks);
        $successCount = count(array_filter($statuses, static fn($status) => $status === 'success'));
        $failedCount = count(array_filter($statuses, static fn($status) => in_array($status, ['failed', 'canceled'], true)));
        $resultCount = (int)($syncSummary['result_count'] ?? 0);
        $missingResultCount = (int)($syncSummary['missing_result_count'] ?? 0);
        if ($resultCount >= count($imageTaskIds) && $successCount === count($imageTaskIds)) {
            $task->status = 'success';
        } elseif ($resultCount > 0 && $successCount + $failedCount === count($imageTaskIds)) {
            $task->status = 'partial_failed';
        } elseif (($failedCount === count($imageTaskIds)) || ($successCount + $failedCount === count($imageTaskIds) && $resultCount === 0)) {
            $task->status = 'failed';
        } elseif (in_array('pending', $statuses, true)) {
            $task->status = 'pending';
        } else {
            $task->status = 'running';
        }
        $errors = array_values(array_filter(array_map(static fn($row) => trim((string)($row['error'] ?? '')), $imageTasks)));
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

    private static function syncResultsFromImageTask(AigcOneClickCleanupTask $task): array
    {
        $tenantId = (int)$task['tenant_id'];
        $userId = (int)$task['user_id'];
        $sourceImages = is_array($task['source_images'] ?? null) ? $task['source_images'] : [];
        $resultCount = 0;
        $missingResultCount = 0;
        foreach (self::taskImageIds($task->toArray()) as $index => $imageTaskId) {
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
                if ($imageResultId <= 0) {
                    continue;
                }
                $imageUri = trim((string)($result['image_uri'] ?? ''));
                if ($imageUri === '') {
                    continue;
                }
                $hasUsableResult = true;
                $exists = AigcOneClickCleanupResult::where(['tenant_id' => $tenantId, 'image_result_id' => $imageResultId])->findOrEmpty();
                if (!$exists->isEmpty()) {
                    if ((string)$exists['image_uri'] === '') {
                        $exists->save([
                            'task_id' => (int)$task['id'],
                            'image_task_id' => $imageTaskId,
                            'user_id' => $userId,
                            'source_image' => (string)($sourceImages[$index] ?? ''),
                            'image_uri' => $imageUri,
                            'storage_scope' => (string)($result['storage_scope'] ?? 'tenant'),
                            'storage_engine' => (string)($result['storage_engine'] ?? 'local'),
                            'storage_domain' => (string)($result['storage_domain'] ?? ''),
                            'width' => (int)($result['width'] ?? 0),
                            'height' => (int)($result['height'] ?? 0),
                        ]);
                    }
                    continue;
                }
                $emptyMapped = AigcOneClickCleanupResult::where([
                    'tenant_id' => $tenantId,
                    'task_id' => (int)$task['id'],
                    'image_task_id' => $imageTaskId,
                ])->where('image_uri', '')->findOrEmpty();
                if (!$emptyMapped->isEmpty()) {
                    $emptyMapped->save([
                        'image_result_id' => $imageResultId,
                        'user_id' => $userId,
                        'source_image' => (string)($sourceImages[$index] ?? ''),
                        'image_uri' => $imageUri,
                        'storage_scope' => (string)($result['storage_scope'] ?? 'tenant'),
                        'storage_engine' => (string)($result['storage_engine'] ?? 'local'),
                        'storage_domain' => (string)($result['storage_domain'] ?? ''),
                        'width' => (int)($result['width'] ?? 0),
                        'height' => (int)($result['height'] ?? 0),
                    ]);
                    continue;
                }
                AigcOneClickCleanupResult::create([
                    'tenant_id' => $tenantId,
                    'task_id' => (int)$task['id'],
                    'image_task_id' => $imageTaskId,
                    'image_result_id' => $imageResultId,
                    'user_id' => $userId,
                    'source_image' => (string)($sourceImages[$index] ?? ''),
                    'image_uri' => $imageUri,
                    'storage_scope' => (string)($result['storage_scope'] ?? 'tenant'),
                    'storage_engine' => (string)($result['storage_engine'] ?? 'local'),
                    'storage_domain' => (string)($result['storage_domain'] ?? ''),
                    'width' => (int)($result['width'] ?? 0),
                    'height' => (int)($result['height'] ?? 0),
                    'delete_time' => 0,
                    'create_time' => time(),
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
        $query = AigcOneClickCleanupTask::where('tenant_id', $tenantId)->where('delete_time', 0);
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
            $query = AigcOneClickCleanupResult::where('tenant_id', $tenantId)
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
                $result['source_image_url'] = self::imageUrl((string)($result['source_image'] ?? ''));
                $resultMap[(int)$result['task_id']][] = $result;
            }
        }
        foreach ($rows as &$row) {
            $results = $resultMap[(int)$row['id']] ?? [];
            $sourceImages = is_array($row['source_images'] ?? null) ? $row['source_images'] : [];
            foreach ($results as &$result) {
                $sourceIndex = array_search((string)($result['source_image'] ?? ''), $sourceImages, true);
                $result['source_index'] = $sourceIndex === false ? null : (int)$sourceIndex;
            }
            unset($result);
            $row['results'] = $results;
            $row['result_count'] = count($results);
            $row['source_image_urls'] = array_map([self::class, 'imageUrl'], $sourceImages);
            $first = $results[0] ?? [];
            $row['image_url'] = (string)($first['image_url'] ?? '');
            $row['download_url'] = (string)($first['download_url'] ?? '');
            $row['image_uri'] = (string)($first['image_uri'] ?? '');
            $row['source_image_url'] = (string)($row['source_image_urls'][0] ?? '');
        }
        return $rows;
    }

    private static function formatTaskRow(array $row): array
    {
        $row['task_id'] = (int)($row['id'] ?? 0);
        $row['image_task_id'] = (int)($row['image_task_id'] ?? 0);
        $row['image_task_ids'] = self::taskImageIds($row);
        $row['option_names'] = array_values(array_filter(array_map(static fn($item) => (string)($item['name'] ?? ''), is_array($row['option_snapshot'] ?? null) ? $row['option_snapshot'] : [])));
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

    private static function resolveOptions(int $tenantId, array $codes): array
    {
        self::ensureDefaultOptions($tenantId);
        $rows = AigcOneClickCleanupOption::where('tenant_id', $tenantId)->where('delete_time', 0)->where('status', 1)->whereIn('code', $codes)->select()->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(string)$row['code']] = [
                'id' => (int)$row['id'],
                'code' => (string)$row['code'],
                'name' => (string)$row['name'],
                'description' => (string)($row['description'] ?? ''),
                'prompt' => (string)($row['prompt'] ?? ''),
            ];
        }
        $resolved = [];
        foreach ($codes as $code) {
            if (isset($map[$code])) {
                $resolved[] = $map[$code];
            }
        }
        if (!$resolved || count($resolved) !== count($codes)) {
            throw new Exception('请选择可用消除选项');
        }
        return $resolved;
    }

    private static function buildPrompt(array $config, array $options, int $imageCount): string
    {
        $names = implode('、', array_map(static fn($item) => (string)$item['name'], $options));
        $prompts = implode('；', array_values(array_filter(array_map(static fn($item) => trim((string)($item['prompt'] ?? '')), $options))));
        $template = self::normalizeTemplate((string)($config['prompt_template'] ?? ''));
        return trim($template . ' 当前批次共' . max(1, $imageCount) . '张图，重点清理：' . ($names ?: '干扰内容') . '。' . ($prompts !== '' ? ' 选项要求：' . $prompts : ''));
    }

    private static function ensureDefaultOptions(int $tenantId): void
    {
        foreach (self::DEFAULT_OPTIONS as $code => $item) {
            $exists = AigcOneClickCleanupOption::where(['tenant_id' => $tenantId, 'code' => $code])->where('delete_time', 0)->count() > 0;
            if ($exists) {
                continue;
            }
            AigcOneClickCleanupOption::create([
                'tenant_id' => $tenantId,
                'code' => $code,
                'name' => $item['name'],
                'description' => $item['description'],
                'prompt' => $item['prompt'],
                'cover_image' => '',
                'status' => 1,
                'sort' => $item['sort'],
                'is_builtin' => 1,
                'delete_time' => 0,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }
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

    private static function saveConfigSnapshot(int $tenantId, array $data, AigcOneClickCleanupConfig $row): void
    {
        $payload = [
            'tenant_id' => $tenantId,
            'status' => (int)($data['status'] ?? 1),
            'default_channel' => (string)($data['default_channel'] ?? ''),
            'default_quality' => (string)($data['default_quality'] ?? ''),
            'default_ratio' => (string)($data['default_ratio'] ?? ''),
            'unit_price' => round(max(0, (float)($data['unit_price'] ?? 0)), 2),
            'max_images' => self::MAX_IMAGES,
            'prompt_template' => (string)($data['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE),
            'negative_prompt' => (string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT),
            'config_json' => self::normalizeConfigJson($data['config_json'] ?? []),
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $payload['create_time'] = time();
            AigcOneClickCleanupConfig::create($payload);
            return;
        }
        $row->save($payload);
    }

    private static function defaults(): array
    {
        return ['status' => 1, 'default_channel' => '', 'default_quality' => '', 'default_ratio' => '', 'unit_price' => 0, 'max_images' => self::MAX_IMAGES, 'prompt_template' => self::DEFAULT_PROMPT_TEMPLATE, 'negative_prompt' => self::DEFAULT_NEGATIVE_PROMPT, 'config_json' => []];
    }

    private static function sanitizeConfig(array $data): array
    {
        $data['status'] = (int)($data['status'] ?? 1);
        $data['default_channel'] = self::normalizeCode((string)($data['default_channel'] ?? ''));
        $data['default_quality'] = trim((string)($data['default_quality'] ?? ''));
        $data['default_ratio'] = trim((string)($data['default_ratio'] ?? ''));
        $data['unit_price'] = round(max(0, (float)($data['unit_price'] ?? 0)), 2);
        $data['max_images'] = self::MAX_IMAGES;
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
        return ['channel' => self::normalizeCode((string)($config['channel'] ?? '')), 'quality' => trim((string)($config['quality'] ?? '')), 'ratio' => trim((string)($config['ratio'] ?? ''))];
    }

    private static function normalizeImages(mixed $value): array
    {
        if (is_string($value)) {
            $value = $value !== '' ? [$value] : [];
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
        return array_values($images);
    }

    private static function normalizeOptionCodes(mixed $value): array
    {
        if (is_string($value)) {
            $value = $value !== '' ? explode(',', $value) : [];
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map(static fn($item) => self::normalizeOptionCode((string)$item), $value))));
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

    private static function normalizeCode(string $code): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($code)) ?: '';
    }

    private static function normalizeOptionCode(string $code): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($code)) ?: '';
    }
}
