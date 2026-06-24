<?php

namespace app\common\service\app\aigc_image_translate;

use app\common\model\app\App;
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\model\app\aigc_image_translate\AigcImageTranslateConfig;
use app\common\model\app\aigc_image_translate\AigcImageTranslateResult;
use app\common\model\app\aigc_image_translate\AigcImageTranslateTask;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\AppRegistryService;
use app\common\service\app\aigc_image\AigcImageChannelService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\FileService;
use app\common\service\point\PointService;
use app\common\service\storage\StorageConfigService;
use Exception;

class AigcImageTranslateService
{
    public const APP_CODE = 'aigc_image_translate';
    public const IMAGE_APP_CODE = 'aigc_image';

    private const DEFAULT_PROMPT_TEMPLATE = '识别用户上传图片中的文字内容，将原文语言「{source_language_label}」翻译为目标语言「{target_language_label}」。请尽量保留原图版式结构、字号层级、标题/标注位置、阅读顺序、品牌视觉和背景细节，生成可直接展示的图片翻译结果。{user_prompt}';
    private const DEFAULT_NEGATIVE_PROMPT = '错译，漏译，乱码，多余文字，文字重叠，排版错乱，水印，低清晰度，主体变形，背景破坏，颜色失真';
    private const DEFAULT_PRICE_PACKAGE_NAMES = ['标准翻译', '高清翻译'];
    private const DEFAULT_TARGET_LANGUAGE = 'en';
    private const LANGUAGE_OPTIONS = [
        'auto' => '自动识别',
        'zh-hans' => '简体中文',
        'zh-hant' => '繁体中文',
        'en' => '英文',
        'ja' => '日文',
        'ko' => '韩文',
        'fr' => '法文',
        'de' => '德文',
        'es' => '西班牙文',
        'pt' => '葡萄牙文',
        'ru' => '俄文',
    ];

    public static function config(int $tenantId): array
    {
        $row = AigcImageTranslateConfig::where('tenant_id', $tenantId)->findOrEmpty();
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
        $data['language_options'] = self::languageOptions();
        $data['source_language_options'] = self::languageOptions(true);
        $data['target_language_options'] = self::languageOptions(false);
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
            'default_target_language' => self::normalizeLanguage((string)($params['default_target_language'] ?? $configJson['target_language'] ?? $current['default_target_language'] ?? self::DEFAULT_TARGET_LANGUAGE), false),
            'prompt_template' => self::normalizeTemplate((string)($params['prompt_template'] ?? $current['prompt_template'])),
            'negative_prompt' => trim((string)($params['negative_prompt'] ?? $current['negative_prompt'])),
            'price_config' => self::normalizePriceConfig($params['price_config'] ?? $current['price_config'] ?? []),
            'config_json' => self::normalizeConfigJson($configJson + ['target_language' => $params['default_target_language'] ?? $current['default_target_language'] ?? self::DEFAULT_TARGET_LANGUAGE]),
            'update_time' => time(),
        ];
        $row = AigcImageTranslateConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcImageTranslateConfig::create($data);
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
            'default_target_language' => $current['default_target_language'] ?? self::DEFAULT_TARGET_LANGUAGE,
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
            throw new Exception('图片翻译任务创建失败');
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
        $query = AigcImageTranslateTask::alias('t')
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
        $sourceLanguage = trim((string)($params['source_language'] ?? ''));
        if ($sourceLanguage !== '') {
            $query->where('t.source_language', self::normalizeLanguage($sourceLanguage, true));
        }
        $targetLanguage = trim((string)($params['target_language'] ?? ''));
        if ($targetLanguage !== '') {
            $query->where('t.target_language', self::normalizeLanguage($targetLanguage, false));
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
        $query = AigcImageTranslateTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
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
        $task = AigcImageTranslateTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0)->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('任务不存在');
        }
        return self::generate($tenantId, (int)$task['user_id'], [
            'source_image' => $task['source_image'],
            'source_language' => $task['source_language'],
            'target_language' => $task['target_language'],
            'price_package_code' => $task['price_package_code'],
            'prompt' => $task['user_prompt'],
        ]);
    }

    public static function deleteTask(int $tenantId, int $taskId, int $userId = 0): void
    {
        $query = AigcImageTranslateTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
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
        AigcImageTranslateResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time()]);
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = AigcImageTranslateResult::where(['tenant_id' => $tenantId, 'id' => $resultId]);
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
            'required_for' => '图片翻译生成',
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
            throw new Exception('图片翻译应用未开通或未上架');
        }
        if (AppAccessService::assertTenantCanUse($tenantId, self::IMAGE_APP_CODE) !== null) {
            throw new Exception('AIGC生图应用未开通或未上架');
        }
        $config = self::config($tenantId);
        if ((int)($config['status'] ?? 1) !== 1) {
            throw new Exception('图片翻译应用已停用');
        }
    }

    private static function prepareGeneratePayload(int $tenantId, array $params, bool $requireImage): array
    {
        $config = self::config($tenantId);
        $sourceImage = self::normalizeImage($params['source_image'] ?? $params['image'] ?? '');
        if ($requireImage && $sourceImage === '') {
            throw new Exception('请上传需要翻译的图片');
        }
        $sourceLanguage = self::normalizeLanguage((string)($params['source_language'] ?? 'auto'), true);
        $targetLanguage = self::normalizeLanguage((string)($params['target_language'] ?? $config['default_target_language'] ?? self::DEFAULT_TARGET_LANGUAGE), false);
        self::assertLanguagePair($sourceLanguage, $targetLanguage);
        $package = self::resolvePricePackage($config['price_config'], $params, $config);
        $channel = (string)$package['channel'];
        $quality = (string)$package['quality'];
        $ratio = self::resolvePackageRatio(
            $package,
            self::resolveSourceImageRatio($sourceImage, trim((string)($config['default_ratio'] ?? '')))
        );
        $userPrompt = trim((string)($params['prompt'] ?? $params['user_prompt'] ?? ''));
        $imagePayload = [
            'prompt' => self::renderPrompt((string)$config['prompt_template'], [
                'user_prompt' => $userPrompt,
                'source_language' => $sourceLanguage,
                'source_language_label' => self::languageLabel($sourceLanguage),
                'target_language' => $targetLanguage,
                'target_language_label' => self::languageLabel($targetLanguage),
            ]),
            'negative_prompt' => (string)($params['negative_prompt'] ?? $config['negative_prompt']),
            'reference_images' => array_values(array_filter([$sourceImage])),
            'channel' => $channel,
            'quality' => $quality,
            'ratio' => $ratio,
            'quantity' => 1,
            'style' => 'image_translate',
        ];
        $resolved = AigcImageChannelService::resolveSelection($tenantId, $imagePayload);
        return [
            'price_package' => $package,
            'source_image' => $sourceImage,
            'source_language' => $sourceLanguage,
            'source_language_label' => self::languageLabel($sourceLanguage),
            'target_language' => $targetLanguage,
            'target_language_label' => self::languageLabel($targetLanguage),
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

    private static function upsertTaskFromImageTask(int $tenantId, int $userId, int $imageTaskId, array $prepared, array $estimate): AigcImageTranslateTask
    {
        $imageTask = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => $imageTaskId])->findOrEmpty();
        if ($imageTask->isEmpty()) {
            throw new Exception('生图任务不存在');
        }
        $row = AigcImageTranslateTask::where(['tenant_id' => $tenantId, 'image_task_id' => $imageTaskId])->findOrEmpty();
        $data = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'image_task_id' => $imageTaskId,
            'image_task_ids' => [$imageTaskId],
            'source_image' => $prepared['source_image'],
            'source_language' => $prepared['source_language'],
            'source_language_label' => $prepared['source_language_label'],
            'target_language' => $prepared['target_language'],
            'target_language_label' => $prepared['target_language_label'],
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
            return AigcImageTranslateTask::create($data);
        }
        $row->save($data);
        return $row;
    }

    private static function syncTaskFromImageTask(AigcImageTranslateTask $task): void
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

    private static function syncResultsFromImageTask(AigcImageTranslateTask $task): void
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
                $exists = AigcImageTranslateResult::where(['tenant_id' => $tenantId, 'image_result_id' => $imageResultId])->findOrEmpty();
                if (!$exists->isEmpty()) {
                    continue;
                }
                AigcImageTranslateResult::create([
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
        $query = AigcImageTranslateTask::where('tenant_id', $tenantId)->where('delete_time', 0);
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
            $query = AigcImageTranslateResult::where('tenant_id', $tenantId)
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
            $row['image_uri'] = (string)($first['image_uri'] ?? '');
            $row['download_url'] = (string)($first['image_url'] ?? '');
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
            'default_target_language' => self::DEFAULT_TARGET_LANGUAGE,
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
        $data['default_target_language'] = self::normalizeLanguage((string)($data['default_target_language'] ?? $data['config_json']['target_language'] ?? self::DEFAULT_TARGET_LANGUAGE), false);
        $data['prompt_template'] = self::normalizeTemplate((string)($data['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE));
        $data['negative_prompt'] = trim((string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT));
        $data['price_config'] = is_array($data['price_config'] ?? null) ? self::normalizePriceConfig($data['price_config']) : [];
        $data['config_json'] = is_array($data['config_json'] ?? null) ? self::normalizeConfigJson($data['config_json']) : [];
        $data['config_json']['channel'] = $data['default_channel'] ?: ($data['config_json']['channel'] ?? '');
        $data['config_json']['quality'] = $data['default_quality'] ?: ($data['config_json']['quality'] ?? '');
        $data['config_json']['ratio'] = $data['default_ratio'] ?: ($data['config_json']['ratio'] ?? '');
        $data['config_json']['target_language'] = $data['default_target_language'];
        return $data;
    }

    private static function normalizeConfigJson(array $config): array
    {
        return [
            'channel' => self::normalizeCode((string)($config['channel'] ?? '')),
            'quality' => trim((string)($config['quality'] ?? '')),
            'ratio' => trim((string)($config['ratio'] ?? '')),
            'target_language' => self::normalizeLanguage((string)($config['target_language'] ?? self::DEFAULT_TARGET_LANGUAGE), false),
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
                        'name' => mb_substr(trim((string)($item['name'] ?? '翻译质量' . ($index + 1))), 0, 80),
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
            if (!isset($item['code'])) {
                continue;
            }
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
            throw new Exception('请先配置可用翻译质量');
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
        throw new Exception('请选择可用翻译质量');
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
        $desiredValue = self::ratioValue($ratio);
        $bestRatio = '';
        $bestDiff = PHP_FLOAT_MAX;
        foreach ($values as $value) {
            $current = self::ratioValue($value);
            if ($current <= 0 || $desiredValue <= 0) {
                continue;
            }
            $diff = abs($current - $desiredValue);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestRatio = $value;
            }
        }
        return $bestRatio ?: $values[0];
    }

    private static function resolveSourceImageRatio(string $sourceImage, string $fallback = ''): string
    {
        [$width, $height] = self::sourceImageSize($sourceImage);
        if ($width <= 0 || $height <= 0) {
            return $fallback;
        }
        $gcd = self::gcd($width, $height);
        return (int)($width / $gcd) . ':' . (int)($height / $gcd);
    }

    private static function sourceImageSize(string $sourceImage): array
    {
        if ($sourceImage === '') {
            return [0, 0];
        }
        $path = '';
        if (!preg_match('/^https?:\/\//', $sourceImage)) {
            $path = public_path() . ltrim($sourceImage, '/');
        } else {
            $urlPath = (string)(parse_url($sourceImage, PHP_URL_PATH) ?: '');
            $localUri = ltrim($urlPath, '/');
            if ($localUri !== '' && (str_starts_with($localUri, 'uploads/') || str_starts_with($localUri, 'resource/'))) {
                $path = public_path() . $localUri;
            }
        }
        if ($path !== '' && is_file($path)) {
            $size = @getimagesize($path) ?: [];
            return [(int)($size[0] ?? 0), (int)($size[1] ?? 0)];
        }
        if (preg_match('/^https?:\/\//', $sourceImage)) {
            $context = stream_context_create(['http' => ['timeout' => 3], 'https' => ['timeout' => 3]]);
            $content = @file_get_contents($sourceImage, false, $context, 0, 1024 * 1024 * 4);
            if (is_string($content) && $content !== '') {
                $size = @getimagesizefromstring($content) ?: [];
                return [(int)($size[0] ?? 0), (int)($size[1] ?? 0)];
            }
        }
        return [0, 0];
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
                'name' => self::DEFAULT_PRICE_PACKAGE_NAMES[$index] ?? ('翻译质量' . ($index + 1)),
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

    private static function saveConfigSnapshot(int $tenantId, array $data, AigcImageTranslateConfig $row): void
    {
        $payload = [
            'tenant_id' => $tenantId,
            'status' => (int)($data['status'] ?? 1),
            'default_channel' => (string)($data['default_channel'] ?? ''),
            'default_quality' => (string)($data['default_quality'] ?? ''),
            'default_ratio' => (string)($data['default_ratio'] ?? ''),
            'default_target_language' => self::normalizeLanguage((string)($data['default_target_language'] ?? $data['config_json']['target_language'] ?? self::DEFAULT_TARGET_LANGUAGE), false),
            'prompt_template' => (string)($data['prompt_template'] ?? self::DEFAULT_PROMPT_TEMPLATE),
            'negative_prompt' => (string)($data['negative_prompt'] ?? self::DEFAULT_NEGATIVE_PROMPT),
            'price_config' => self::normalizePriceConfig($data['price_config'] ?? []),
            'config_json' => self::normalizeConfigJson($data['config_json'] ?? []),
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $payload['create_time'] = time();
            AigcImageTranslateConfig::create($payload);
            return;
        }
        $row->save($payload);
    }

    private static function renderPrompt(string $template, array $data): string
    {
        $userPrompt = trim((string)($data['user_prompt'] ?? ''));
        $sourceLabel = (string)($data['source_language_label'] ?? self::languageLabel((string)($data['source_language'] ?? 'auto')));
        $targetLabel = (string)($data['target_language_label'] ?? self::languageLabel((string)($data['target_language'] ?? self::DEFAULT_TARGET_LANGUAGE)));
        return trim(strtr(self::normalizeTemplate($template), [
            '{source_language}' => (string)($data['source_language'] ?? 'auto'),
            '{source_language_label}' => $sourceLabel,
            '{target_language}' => (string)($data['target_language'] ?? self::DEFAULT_TARGET_LANGUAGE),
            '{target_language_label}' => $targetLabel,
            '{user_prompt}' => $userPrompt !== '' ? '用户补充要求：' . $userPrompt . '。' : '',
        ]) . ($userPrompt !== '' && !str_contains($template, '{user_prompt}') ? ' 用户补充要求：' . $userPrompt . '。' : ''));
    }

    private static function languageOptions(bool $includeAuto = true): array
    {
        $items = [];
        foreach (self::LANGUAGE_OPTIONS as $value => $label) {
            if (!$includeAuto && $value === 'auto') {
                continue;
            }
            $items[] = ['value' => $value, 'label' => $label];
        }
        return $items;
    }

    private static function normalizeLanguage(string $language, bool $allowAuto): string
    {
        $language = strtolower(trim($language));
        if ($language === '') {
            return $allowAuto ? 'auto' : self::DEFAULT_TARGET_LANGUAGE;
        }
        if ($language === 'zh') {
            $language = 'zh-hans';
        }
        if ($language === 'zh-tw' || $language === 'zh-hk') {
            $language = 'zh-hant';
        }
        if (!isset(self::LANGUAGE_OPTIONS[$language])) {
            throw new Exception('请选择支持的语言');
        }
        if (!$allowAuto && $language === 'auto') {
            throw new Exception('目标语言不能选择自动识别');
        }
        return $language;
    }

    private static function assertLanguagePair(string $sourceLanguage, string $targetLanguage): void
    {
        if ($sourceLanguage !== 'auto' && $sourceLanguage === $targetLanguage) {
            throw new Exception('目标语言不能与原文语言一致');
        }
    }

    private static function languageLabel(string $language): string
    {
        return self::LANGUAGE_OPTIONS[$language] ?? $language;
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

    private static function ratioValue(string $ratio): float
    {
        if (!str_contains($ratio, ':')) {
            return 0.0;
        }
        [$width, $height] = array_map('floatval', explode(':', $ratio, 2));
        return $height > 0 ? $width / $height : 0.0;
    }

    private static function qualityKey(string $channel, string $quality): string
    {
        return $channel . '|' . $quality;
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return max(1, abs($a));
    }

    private static function normalizePackageCode(string $code): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($code)) ?: '';
    }
}
