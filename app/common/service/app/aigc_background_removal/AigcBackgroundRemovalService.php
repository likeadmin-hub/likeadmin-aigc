<?php

namespace app\common\service\app\aigc_background_removal;

use app\common\enum\FileEnum;
use app\common\model\app\App;
use app\common\model\app\aigc_background_removal\AigcBackgroundRemovalConfig;
use app\common\model\app\aigc_background_removal\AigcBackgroundRemovalResult;
use app\common\model\app\aigc_background_removal\AigcBackgroundRemovalTask;
use app\common\model\app\aigc_image\AigcImageBilling;
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\model\file\TenantFile;
use app\common\service\app\AppAccessService;
use app\common\service\app\AppDisplayConfigService;
use app\common\service\app\AppRegistryService;
use app\common\service\app\aigc_image\AigcImageChannelService;
use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\FileService;
use app\common\service\point\PointService;
use app\common\service\storage\Driver as StorageDriver;
use app\common\service\storage\StorageConfigService;
use Exception;
use think\facade\Db;

class AigcBackgroundRemovalService
{
    public const APP_CODE = 'aigc_background_removal';
    public const IMAGE_APP_CODE = 'aigc_image';

    private const TRANSPARENT_RESULT_ERROR = '生成结果未返回透明背景，请重试或更换规格';
    private const DEFAULT_PROMPT_TEMPLATE = '基于用户上传的图片进行高精度去背景抠图，完整保留主体轮廓、发丝、半透明材质、阴影边缘和商品细节，移除所有背景元素，输出真实透明背景 PNG，主体干净自然，边缘无白边、无锯齿、无残留背景。';
    private const DEFAULT_NEGATIVE_PROMPT = '纯色背景，白色背景，黑色背景，背景残留，边缘毛刺，白边，水印，文字，主体缺失，主体变形，低清晰度，阴影污染，非透明背景';
    private const DEFAULT_PRICE_PACKAGE_NAMES = ['标准抠图', '高清抠图'];

    public static function config(int $tenantId): array
    {
        $row = AigcBackgroundRemovalConfig::where('tenant_id', $tenantId)->findOrEmpty();
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
        $row = AigcBackgroundRemovalConfig::where('tenant_id', $tenantId)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcBackgroundRemovalConfig::create($data);
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
            'default_channel' => $current['default_channel'],
            'default_quality' => $current['default_quality'],
            'default_ratio' => $current['default_ratio'],
            'prompt_template' => $current['prompt_template'],
            'negative_prompt' => $current['negative_prompt'],
            'price_config' => self::normalizePriceConfig($params['price_config'] ?? $params['packages'] ?? $params['items'] ?? []),
            'config_json' => $current['config_json'] ?? [],
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
            throw new Exception('图片去背景任务创建失败');
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
        $query = AigcBackgroundRemovalTask::alias('t')
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
        $query = AigcBackgroundRemovalTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0);
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
        $task = AigcBackgroundRemovalTask::where(['tenant_id' => $tenantId, 'id' => $taskId])->where('delete_time', 0)->findOrEmpty();
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
        $query = AigcBackgroundRemovalTask::where(['tenant_id' => $tenantId, 'id' => $taskId]);
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
        AigcBackgroundRemovalResult::where(['tenant_id' => $tenantId, 'task_id' => $taskId])->update(['delete_time' => time()]);
    }

    public static function deleteResult(int $tenantId, int $resultId, int $userId = 0): void
    {
        $query = AigcBackgroundRemovalResult::where(['tenant_id' => $tenantId, 'id' => $resultId]);
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
            'required_for' => '图片去背景生成',
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
            throw new Exception('图片去背景应用未开通或未上架');
        }
        if (AppAccessService::assertTenantCanUse($tenantId, self::IMAGE_APP_CODE) !== null) {
            throw new Exception('AIGC生图应用未开通或未上架');
        }
        $config = self::config($tenantId);
        if ((int)($config['status'] ?? 1) !== 1) {
            throw new Exception('图片去背景应用已停用');
        }
    }

    private static function prepareGeneratePayload(int $tenantId, array $params, bool $requireImage): array
    {
        $config = self::config($tenantId);
        $sourceImage = self::normalizeImage($params['source_image'] ?? $params['image'] ?? '');
        if ($requireImage && $sourceImage === '') {
            throw new Exception('请上传待抠图图片');
        }
        $package = self::resolvePricePackage($config['price_config'], $params, $config);
        $channel = (string)$package['channel'];
        $quality = (string)$package['quality'];
        $ratio = self::resolvePackageRatio($package, trim((string)($params['ratio'] ?? $config['default_ratio'] ?? '')));
        $imagePayload = [
            'prompt' => self::normalizeTemplate((string)$config['prompt_template']),
            'negative_prompt' => (string)($params['negative_prompt'] ?? $config['negative_prompt']),
            'reference_images' => array_values(array_filter([$sourceImage])),
            'channel' => $channel,
            'quality' => $quality,
            'ratio' => $ratio,
            'quantity' => 1,
            'style' => 'background_removal',
            'output_format' => 'png',
            'transparent_background' => 1,
            'background' => 'transparent',
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

    private static function upsertTaskFromImageTask(int $tenantId, int $userId, int $imageTaskId, array $prepared, array $estimate): AigcBackgroundRemovalTask
    {
        $imageTask = AigcImageTask::where(['tenant_id' => $tenantId, 'id' => $imageTaskId])->findOrEmpty();
        if ($imageTask->isEmpty()) {
            throw new Exception('生图任务不存在');
        }
        $row = AigcBackgroundRemovalTask::where(['tenant_id' => $tenantId, 'image_task_id' => $imageTaskId])->findOrEmpty();
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
            return AigcBackgroundRemovalTask::create($data);
        }
        $row->save($data);
        return $row;
    }

    private static function syncTaskFromImageTask(AigcBackgroundRemovalTask $task): void
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

    private static function syncResultsFromImageTask(AigcBackgroundRemovalTask $task): void
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
                if ($imageResultId <= 0) {
                    continue;
                }
                if (!self::hasDeductedImageBilling($tenantId, $userId, $imageTaskId, $imageResultId)) {
                    continue;
                }
                $exists = AigcBackgroundRemovalResult::where(['tenant_id' => $tenantId, 'image_result_id' => $imageResultId])->findOrEmpty();
                if (!$exists->isEmpty()) {
                    continue;
                }
                try {
                    $sourceUrl = (string)($result['image_url'] ?? $result['url'] ?? '');
                    if ($sourceUrl === '' && (string)($result['image_uri'] ?? '') !== '') {
                        $sourceUrl = FileService::getFileUrlByStorage(
                            (string)$result['image_uri'],
                            (string)($result['storage_scope'] ?? ''),
                            (string)($result['storage_engine'] ?? ''),
                            (string)($result['storage_domain'] ?? '')
                        );
                    }
                    $stored = self::persistTransparentPng($sourceUrl, $tenantId, $userId);
                    AigcBackgroundRemovalResult::create([
                        'tenant_id' => $tenantId,
                        'task_id' => (int)$task['id'],
                        'image_task_id' => $imageTaskId,
                        'image_result_id' => $imageResultId,
                        'user_id' => $userId,
                        'image_uri' => $stored['uri'],
                        'mime_type' => 'image/png',
                        'has_alpha' => 1,
                        'storage_scope' => (string)$stored['storage_scope'],
                        'storage_engine' => (string)$stored['storage_engine'],
                        'storage_domain' => (string)$stored['storage_domain'],
                        'width' => (int)$stored['width'],
                        'height' => (int)$stored['height'],
                        'delete_time' => 0,
                        'create_time' => time(),
                    ]);
                } catch (\Throwable) {
                    $refundError = self::refundImageBillings($task, self::TRANSPARENT_RESULT_ERROR);
                    $task->save([
                        'status' => 'failed',
                        'error' => trim(self::TRANSPARENT_RESULT_ERROR . ($refundError !== '' ? ' ' . $refundError : '')),
                        'finish_time' => time(),
                        'update_time' => time(),
                    ]);
                    return;
                }
            }
        }
        $hasResult = AigcBackgroundRemovalResult::where(['tenant_id' => $tenantId, 'task_id' => (int)$task['id']])->where('delete_time', 0)->count() > 0;
        if (!$hasResult) {
            $refundError = self::refundImageBillings($task, self::TRANSPARENT_RESULT_ERROR);
            $task->save([
                'status' => 'failed',
                'error' => trim(self::TRANSPARENT_RESULT_ERROR . ($refundError !== '' ? ' ' . $refundError : '')),
                'finish_time' => time(),
                'update_time' => time(),
            ]);
        }
    }

    private static function hasDeductedImageBilling(int $tenantId, int $userId, int $imageTaskId, int $imageResultId): bool
    {
        return AigcImageBilling::where([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'task_id' => $imageTaskId,
            'result_id' => $imageResultId,
            'billing_status' => 'deducted',
        ])->count() > 0;
    }

    private static function refundImageBillings(AigcBackgroundRemovalTask $task, string $reason): string
    {
        $tenantId = (int)$task['tenant_id'];
        $userId = (int)$task['user_id'];
        $imageTaskIds = self::taskImageIds($task->toArray());
        if ($tenantId <= 0 || $userId <= 0 || !$imageTaskIds) {
            return '';
        }
        Db::startTrans();
        try {
            $rows = AigcImageBilling::where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->whereIn('task_id', $imageTaskIds)
                ->where('billing_status', 'deducted')
                ->lock(true)
                ->select();
            foreach ($rows as $row) {
                $sourceSn = (string)($row['user_point_sn'] ?: $row['tenant_point_sn'] ?: (self::APP_CODE . '-' . (int)$task['id'] . '-' . (int)$row['id']));
                $refundSn = substr($sourceSn . '-refund', 0, 64);
                PointService::refundBusinessAmountsInCurrentTransaction(
                    $tenantId,
                    $userId,
                    (float)$row['tenant_cost_points'],
                    (float)$row['user_charge_points'],
                    $refundSn,
                    '图片去背景失败退回',
                    [
                        'app_code' => self::APP_CODE,
                        'task_id' => (int)$task['id'],
                        'image_task_id' => (int)$row['task_id'],
                        'image_billing_id' => (int)$row['id'],
                        'reason' => $reason,
                    ]
                );
                $row->save([
                    'billing_status' => 'refunded',
                    'update_time' => time(),
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return '退款失败：' . $e->getMessage();
        }
        return '';
    }

    private static function persistTransparentPng(string $url, int $tenantId, int $userId): array
    {
        if ($url === '') {
            throw new Exception(self::TRANSPARENT_RESULT_ERROR);
        }
        $content = self::readImageContent($url);
        if ($content === '') {
            throw new Exception(self::TRANSPARENT_RESULT_ERROR);
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new Exception('服务器未安装 GD 图片处理扩展');
        }
        $image = @imagecreatefromstring($content);
        if (!$image) {
            throw new Exception(self::TRANSPARENT_RESULT_ERROR);
        }
        $width = imagesx($image);
        $height = imagesy($image);
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        if (!self::hasAlphaPixels($image, $width, $height)) {
            $image = self::fallbackRemoveEdgeBackground($image, $width, $height);
            if (!self::hasAlphaPixels($image, $width, $height)) {
                imagedestroy($image);
                throw new Exception(self::TRANSPARENT_RESULT_ERROR);
            }
        }
        $tmp = tempnam(sys_get_temp_dir(), 'aigc_bg_remove_');
        if ($tmp === false) {
            imagedestroy($image);
            throw new Exception('生成图片临时文件创建失败');
        }
        $tmpPath = $tmp . '.png';
        @rename($tmp, $tmpPath);
        $saved = imagepng($image, $tmpPath);
        imagedestroy($image);
        if (!$saved) {
            @unlink($tmpPath);
            throw new Exception('生成图片保存失败');
        }
        try {
            $stored = self::uploadPngFile($tmpPath, $tenantId, $userId);
        } finally {
            @unlink($tmpPath);
        }
        return array_merge($stored, [
            'width' => $width,
            'height' => $height,
            'mime_type' => 'image/png',
            'has_alpha' => 1,
        ]);
    }

    private static function readImageContent(string $url): string
    {
        if (str_starts_with($url, 'data:image/')) {
            if (!preg_match('/^data:image\/[a-zA-Z0-9.+-]+;base64,(.+)$/', $url, $matches)) {
                return '';
            }
            $decoded = base64_decode($matches[1], true);
            return $decoded === false ? '' : $decoded;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 25,
                    'follow_location' => 1,
                    'ignore_errors' => true,
                    'header' => "User-Agent: LikeAdminAigcBackgroundRemoval/1.0\r\n",
                ],
            ]);
            $content = @file_get_contents($url, false, $context, 0, 20 * 1024 * 1024);
            return is_string($content) ? $content : '';
        }
        $path = public_path() . ltrim($url, '/');
        if (is_file($path) && filesize($path) <= 20 * 1024 * 1024) {
            return (string)@file_get_contents($path);
        }
        return '';
    }

    private static function hasAlphaPixels($image, int $width, int $height): bool
    {
        if ($width <= 0 || $height <= 0) {
            return false;
        }
        $stepX = max(1, (int)floor($width / 160));
        $stepY = max(1, (int)floor($height / 160));
        for ($y = 0; $y < $height; $y += $stepY) {
            for ($x = 0; $x < $width; $x += $stepX) {
                $rgba = imagecolorat($image, $x, $y);
                if ((($rgba >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }
        $points = [[0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1], [(int)($width / 2), (int)($height / 2)]];
        foreach ($points as [$x, $y]) {
            $rgba = imagecolorat($image, max(0, $x), max(0, $y));
            if ((($rgba >> 24) & 0x7F) > 0) {
                return true;
            }
        }
        return false;
    }

    private static function fallbackRemoveEdgeBackground($image, int $width, int $height)
    {
        if ($width <= 0 || $height <= 0) {
            return $image;
        }
        $bg = self::detectEdgeBackgroundColor($image, $width, $height);
        if ($bg === null) {
            return $image;
        }
        $maxPixels = 360000;
        $scale = min(1, sqrt($maxPixels / max(1, $width * $height)));
        $workWidth = max(1, (int)floor($width * $scale));
        $workHeight = max(1, (int)floor($height * $scale));
        $work = imagecreatetruecolor($workWidth, $workHeight);
        imagealphablending($work, false);
        imagesavealpha($work, true);
        imagecopyresampled($work, $image, 0, 0, 0, 0, $workWidth, $workHeight, $width, $height);
        $mask = self::edgeBackgroundMask($work, $workWidth, $workHeight, $bg);
        if (empty($mask)) {
            imagedestroy($work);
            return $image;
        }
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $softRadius = max(1, (int)round(2 / max($scale, 0.01)));
        for ($y = 0; $y < $height; $y++) {
            $wy = min($workHeight - 1, (int)floor($y * $scale));
            for ($x = 0; $x < $width; $x++) {
                $wx = min($workWidth - 1, (int)floor($x * $scale));
                if (empty($mask[$wy][$wx])) {
                    continue;
                }
                if (self::isNearMaskEdge($mask, $workWidth, $workHeight, $wx, $wy)) {
                    self::softenPixelAlpha($image, $x, $y, $bg, $softRadius);
                    continue;
                }
                imagesetpixel($image, $x, $y, $transparent);
            }
        }
        imagedestroy($work);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        return $image;
    }

    private static function detectEdgeBackgroundColor($image, int $width, int $height): ?array
    {
        $samples = [];
        $stepX = max(1, (int)floor($width / 24));
        $stepY = max(1, (int)floor($height / 24));
        for ($x = 0; $x < $width; $x += $stepX) {
            $samples[] = self::rgbAt($image, $x, 0);
            $samples[] = self::rgbAt($image, $x, $height - 1);
        }
        for ($y = 0; $y < $height; $y += $stepY) {
            $samples[] = self::rgbAt($image, 0, $y);
            $samples[] = self::rgbAt($image, $width - 1, $y);
        }
        if (!$samples) {
            return null;
        }
        $buckets = [];
        foreach ($samples as $rgb) {
            $key = ((int)round($rgb[0] / 16)) . ':' . ((int)round($rgb[1] / 16)) . ':' . ((int)round($rgb[2] / 16));
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['count' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
            }
            $buckets[$key]['count']++;
            $buckets[$key]['r'] += $rgb[0];
            $buckets[$key]['g'] += $rgb[1];
            $buckets[$key]['b'] += $rgb[2];
        }
        uasort($buckets, static fn($a, $b) => $b['count'] <=> $a['count']);
        $best = reset($buckets);
        if (!$best || (int)$best['count'] < max(4, (int)floor(count($samples) * 0.18))) {
            return null;
        }
        return [
            (int)round($best['r'] / $best['count']),
            (int)round($best['g'] / $best['count']),
            (int)round($best['b'] / $best['count']),
        ];
    }

    private static function edgeBackgroundMask($image, int $width, int $height, array $bg): array
    {
        $mask = [];
        $visited = [];
        $queue = [];
        $enqueue = static function (int $x, int $y) use (&$queue, &$visited, $width, $height): void {
            if ($x < 0 || $y < 0 || $x >= $width || $y >= $height || isset($visited[$y][$x])) {
                return;
            }
            $visited[$y][$x] = true;
            $queue[] = [$x, $y];
        };
        for ($x = 0; $x < $width; $x++) {
            $enqueue($x, 0);
            $enqueue($x, $height - 1);
        }
        for ($y = 0; $y < $height; $y++) {
            $enqueue(0, $y);
            $enqueue($width - 1, $y);
        }
        $head = 0;
        $limit = $width * $height;
        while ($head < count($queue) && $head < $limit) {
            [$x, $y] = $queue[$head++];
            $rgb = self::rgbAt($image, $x, $y);
            if (!self::isBackgroundLike($rgb, $bg)) {
                continue;
            }
            $mask[$y][$x] = true;
            $enqueue($x + 1, $y);
            $enqueue($x - 1, $y);
            $enqueue($x, $y + 1);
            $enqueue($x, $y - 1);
        }
        return $mask;
    }

    private static function isBackgroundLike(array $rgb, array $bg): bool
    {
        $distance = abs($rgb[0] - $bg[0]) + abs($rgb[1] - $bg[1]) + abs($rgb[2] - $bg[2]);
        $brightness = max($bg) - min($bg);
        $threshold = $brightness < 28 ? 58 : 74;
        return $distance <= $threshold;
    }

    private static function isNearMaskEdge(array $mask, int $width, int $height, int $x, int $y): bool
    {
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $nx = $x + $dx;
            $ny = $y + $dy;
            if ($nx < 0 || $ny < 0 || $nx >= $width || $ny >= $height || empty($mask[$ny][$nx])) {
                return true;
            }
        }
        return false;
    }

    private static function softenPixelAlpha($image, int $x, int $y, array $bg, int $radius): void
    {
        $rgb = self::rgbAt($image, $x, $y);
        $distance = abs($rgb[0] - $bg[0]) + abs($rgb[1] - $bg[1]) + abs($rgb[2] - $bg[2]);
        $alpha = max(70, min(127, 127 - (int)floor($distance * 0.8) + $radius));
        imagesetpixel($image, $x, $y, imagecolorallocatealpha($image, $rgb[0], $rgb[1], $rgb[2], $alpha));
    }

    private static function rgbAt($image, int $x, int $y): array
    {
        $rgba = imagecolorat($image, max(0, $x), max(0, $y));
        return [($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF];
    }

    private static function uploadPngFile(string $filePath, int $tenantId, int $userId): array
    {
        $config = StorageConfigService::getEffectiveConfig($tenantId);
        $saveDir = 'uploads/aigc_background_removal/' . date('Ymd');
        $driver = new StorageDriver($config);
        $driver->setUploadFileByReal($filePath);
        if (!$driver->upload($saveDir)) {
            throw new Exception($driver->getError() ?: '透明图片保存失败');
        }
        $uri = $saveDir . '/' . str_replace('\\', '/', $driver->getFileName());
        $storageScope = (string)($config['scope'] ?? 'tenant');
        $storageEngine = (string)($config['default'] ?? 'local');
        $storageDomain = StorageConfigService::getEffectiveDomain($tenantId);
        TenantFile::create([
            'cid' => 0,
            'type' => FileEnum::IMAGE_TYPE,
            'name' => basename($uri),
            'uri' => $uri,
            'storage_scope' => $storageScope,
            'storage_engine' => $storageEngine,
            'storage_domain' => $storageDomain,
            'source' => FileEnum::SOURCE_USER,
            'source_id' => $userId,
            'create_time' => time(),
        ]);
        return [
            'uri' => $uri,
            'url' => FileService::getFileUrlByStorage($uri, $storageScope, $storageEngine, $storageDomain),
            'storage_scope' => $storageScope,
            'storage_engine' => $storageEngine,
            'storage_domain' => $storageDomain,
        ];
    }

    private static function refreshMappedTasks(int $tenantId, int $userId = 0, int $taskId = 0): void
    {
        $query = AigcBackgroundRemovalTask::where('tenant_id', $tenantId)->where('delete_time', 0);
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
            $query = AigcBackgroundRemovalResult::where('tenant_id', $tenantId)
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
        if (!is_array($config)) {
            return [];
        }
        foreach (array_values($config) as $index => $item) {
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
                'name' => mb_substr(trim((string)($item['name'] ?? '输出规格' . ($index + 1))), 0, 80),
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
            throw new Exception('请先配置可用输出规格');
        }
        $code = self::normalizePackageCode((string)($params['price_package_code'] ?? $params['price_package'] ?? $params['package_code'] ?? ''));
        if ($code === '') {
            return $enabled[0];
        }
        foreach ($enabled as $item) {
            if ((string)$item['code'] === $code) {
                return $item;
            }
        }
        throw new Exception('请选择可用输出规格');
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
        foreach ($normalized as $item) {
            $key = self::qualityKey((string)$item['channel'], (string)$item['quality']);
            if (!isset($sourceMap[$key])) {
                continue;
            }
            $source = $sourceMap[$key];
            $item['quality_label'] = (string)($item['quality_label'] ?: ($source['quality_label'] ?? $item['quality']));
            $packages[] = $item;
        }
        if ($packages) {
            return [array_values($packages), count($packages) !== count($normalized)];
        }
        $sourceItems = array_values($sourceMap);
        if (!$sourceItems) {
            return [[], false];
        }
        foreach (array_slice($sourceItems, 0, 2) as $index => $source) {
            $packages[] = [
                'code' => 'default_' . ($index + 1),
                'name' => self::DEFAULT_PRICE_PACKAGE_NAMES[$index] ?? ('输出规格' . ($index + 1)),
                'channel' => (string)$source['channel'],
                'quality' => (string)$source['quality'],
                'quality_label' => (string)$source['quality_label'],
                'unit_price' => round(max(0, (float)$source['default_unit_price']), 2),
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

    private static function saveConfigSnapshot(int $tenantId, array $data, AigcBackgroundRemovalConfig $row): void
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
            AigcBackgroundRemovalConfig::create($payload);
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

    private static function normalizePackageCode(string $code): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($code)) ?: '';
    }
}
