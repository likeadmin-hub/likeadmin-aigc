<?php

namespace app\common\service\app;

use app\common\cache\ExportCache;
use app\common\model\app\aigc_action_transfer\AigcActionTransferResult;
use app\common\model\app\aigc_action_transfer\AigcActionTransferTask;
use app\common\model\app\aigc_person_replacement\AigcPersonReplacementResult;
use app\common\model\app\aigc_person_replacement\AigcPersonReplacementTask;
use app\common\model\app\aigc_background_removal\AigcBackgroundRemovalResult;
use app\common\model\app\aigc_background_removal\AigcBackgroundRemovalTask;
use app\common\model\app\aigc_fitting\AigcFittingTask;
use app\common\model\app\aigc_fashion_lookbook\AigcFashionLookbookResult;
use app\common\model\app\aigc_fashion_lookbook\AigcFashionLookbookTask;
use app\common\model\app\aigc_image_translate\AigcImageTranslateResult;
use app\common\model\app\aigc_image_translate\AigcImageTranslateTask;
use app\common\model\app\aigc_image\AigcImageResult;
use app\common\model\app\aigc_image\AigcImageTask;
use app\common\model\app\aigc_local_redraw\AigcLocalRedrawResult;
use app\common\model\app\aigc_local_redraw\AigcLocalRedrawTask;
use app\common\model\app\aigc_model_wear\AigcModelWearResult;
use app\common\model\app\aigc_model_wear\AigcModelWearTask;
use app\common\model\app\aigc_one_click_cleanup\AigcOneClickCleanupResult;
use app\common\model\app\aigc_one_click_cleanup\AigcOneClickCleanupTask;
use app\common\model\app\aigc_outpaint\AigcOutpaintResult;
use app\common\model\app\aigc_outpaint\AigcOutpaintTask;
use app\common\model\app\aigc_photo_restore\AigcPhotoRestoreResult;
use app\common\model\app\aigc_photo_restore\AigcPhotoRestoreTask;
use app\common\model\app\aigc_product_image\AigcProductImageResult;
use app\common\model\app\aigc_product_image\AigcProductImageTask;
use app\common\model\app\aigc_product_suite\AigcProductSuiteResult;
use app\common\model\app\aigc_product_suite\AigcProductSuiteTask;
use app\common\model\app\aigc_product_multi_angle\AigcProductMultiAngleResult;
use app\common\model\app\aigc_product_multi_angle\AigcProductMultiAngleTask;
use app\common\model\app\aigc_product_promo_video\AigcProductPromoVideoResult;
use app\common\model\app\aigc_product_promo_video\AigcProductPromoVideoTask;
use app\common\model\app\aigc_style_transfer\AigcStyleTransferResult;
use app\common\model\app\aigc_style_transfer\AigcStyleTransferTask;
use app\common\service\FileService;
use Exception;
use ZipArchive;

class AiToolDownloadService
{
    private const APP_NAMES = [
        'aigc_product_image' => 'aigc-product-image',
        'aigc_style_transfer' => 'style-transfer',
        'aigc_photo_restore' => 'photo-restore',
        'aigc_model_wear' => 'model-wear',
        'aigc_background_removal' => 'background-removal',
        'aigc_image_translate' => 'image-translate',
        'aigc_one_click_cleanup' => 'one-click-cleanup',
        'aigc_product_suite' => 'product-suite',
        'aigc_product_multi_angle' => 'product-multi-angle',
        'aigc_fashion_lookbook' => 'fashion-lookbook',
        'aigc_product_promo_video' => 'product-promo-video',
        'aigc_action_transfer' => 'action-transfer',
        'aigc_person_replacement' => 'person-replacement',
        'aigc_outpaint' => 'outpaint',
        'aigc_local_redraw' => 'local-redraw',
        'aigc_fitting' => 'aigc-fitting',
        'aigc_hairstyle' => 'hairstyle',
    ];

    private const OWN_RESULT_APPS = [
        'aigc_product_image' => [
            'task_model' => AigcProductImageTask::class,
            'result_model' => AigcProductImageResult::class,
            'fallback_image_result' => true,
        ],
        'aigc_style_transfer' => [
            'task_model' => AigcStyleTransferTask::class,
            'result_model' => AigcStyleTransferResult::class,
            'fallback_image_result' => true,
        ],
        'aigc_photo_restore' => [
            'task_model' => AigcPhotoRestoreTask::class,
            'result_model' => AigcPhotoRestoreResult::class,
            'fallback_image_result' => true,
        ],
        'aigc_model_wear' => [
            'task_model' => AigcModelWearTask::class,
            'result_model' => AigcModelWearResult::class,
            'fallback_image_result' => true,
        ],
        'aigc_background_removal' => [
            'task_model' => AigcBackgroundRemovalTask::class,
            'result_model' => AigcBackgroundRemovalResult::class,
            'fallback_image_result' => false,
        ],
        'aigc_image_translate' => [
            'task_model' => AigcImageTranslateTask::class,
            'result_model' => AigcImageTranslateResult::class,
            'fallback_image_result' => true,
        ],
        'aigc_one_click_cleanup' => [
            'task_model' => AigcOneClickCleanupTask::class,
            'result_model' => AigcOneClickCleanupResult::class,
            'fallback_image_result' => true,
        ],
        'aigc_product_suite' => [
            'task_model' => AigcProductSuiteTask::class,
            'result_model' => AigcProductSuiteResult::class,
            'fallback_image_result' => true,
        ],
        'aigc_product_multi_angle' => [
            'task_model' => AigcProductMultiAngleTask::class,
            'result_model' => AigcProductMultiAngleResult::class,
            'fallback_image_result' => true,
        ],
        'aigc_fashion_lookbook' => [
            'task_model' => AigcFashionLookbookTask::class,
            'result_model' => AigcFashionLookbookResult::class,
            'fallback_image_result' => true,
        ],
        'aigc_product_promo_video' => [
            'task_model' => AigcProductPromoVideoTask::class,
            'result_model' => AigcProductPromoVideoResult::class,
            'uri_field' => 'video_uri',
            'fallback_image_result' => false,
        ],
        'aigc_action_transfer' => [
            'task_model' => AigcActionTransferTask::class,
            'result_model' => AigcActionTransferResult::class,
            'uri_field' => 'video_uri',
            'fallback_image_result' => false,
        ],
        'aigc_person_replacement' => [
            'task_model' => AigcPersonReplacementTask::class,
            'result_model' => AigcPersonReplacementResult::class,
            'uri_field' => 'video_uri',
            'fallback_image_result' => false,
        ],
        'aigc_outpaint' => [
            'task_model' => AigcOutpaintTask::class,
            'result_model' => AigcOutpaintResult::class,
            'fallback_image_result' => true,
        ],
        'aigc_local_redraw' => [
            'task_model' => AigcLocalRedrawTask::class,
            'result_model' => AigcLocalRedrawResult::class,
            'fallback_image_result' => true,
        ],
    ];

    private const IMAGE_TASK_APPS = [
        'aigc_fitting' => [
            'task_model' => AigcFittingTask::class,
            'style' => '',
        ],
        'aigc_hairstyle' => [
            'task_model' => AigcImageTask::class,
            'style' => 'hairstyle',
        ],
    ];

    public static function createZip(int $tenantId, int $userId, string $appCode, int $taskId): array
    {
        $appCode = trim($appCode);
        if (!isset(self::APP_NAMES[$appCode])) {
            throw new Exception('暂不支持该工具打包下载');
        }
        if ($tenantId <= 0 || $userId <= 0 || $taskId <= 0) {
            throw new Exception('下载任务不存在');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new Exception('服务器未启用 ZIP 扩展，暂无法打包下载');
        }

        $results = self::taskResults($tenantId, $userId, $appCode, $taskId);
        if (!$results) {
            throw new Exception('暂无可下载作品');
        }

        $exportCache = new ExportCache();
        $src = $exportCache->getSrc();
        if (!is_dir($src) && !mkdir($src, 0755, true) && !is_dir($src)) {
            throw new Exception('打包目录创建失败，请稍后重试');
        }

        $prefix = self::APP_NAMES[$appCode];
        $filename = $prefix . '-' . $taskId . '-' . date('YmdHis') . '.zip';
        $zipPath = $src . $filename;
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('打包下载失败，请稍后重试');
        }

        try {
            foreach ($results as $index => $result) {
                $content = self::readResultContent($result);
                if ($content === false || $content === '') {
                    throw new Exception('作品文件不可访问，请稍后重试');
                }
                $entryName = self::zipEntryName($prefix, $result, $index);
                if (!$zip->addFromString($entryName, $content)) {
                    throw new Exception('作品写入压缩包失败，请稍后重试');
                }
            }
        } catch (\Throwable $e) {
            $zip->close();
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
            if ($e instanceof Exception) {
                throw $e;
            }
            throw new Exception('打包下载失败，请稍后重试');
        }

        $zip->close();
        if (!is_file($zipPath) || filesize($zipPath) <= 0) {
            throw new Exception('打包下载失败，请稍后重试');
        }

        $fileKey = $exportCache->setFile($filename);
        return [
            'download_url' => rtrim((string)request()->domain(), '/') . '/api/app.ai_tool_download/export?file=' . urlencode($fileKey),
            'filename' => $filename,
        ];
    }

    private static function taskResults(int $tenantId, int $userId, string $appCode, int $taskId): array
    {
        if (isset(self::OWN_RESULT_APPS[$appCode])) {
            return self::ownResultTaskResults($tenantId, $userId, self::OWN_RESULT_APPS[$appCode], $taskId);
        }
        if (isset(self::IMAGE_TASK_APPS[$appCode])) {
            return self::imageTaskResults($tenantId, $userId, self::IMAGE_TASK_APPS[$appCode], $taskId);
        }
        return [];
    }

    private static function ownResultTaskResults(int $tenantId, int $userId, array $config, int $taskId): array
    {
        $taskModel = $config['task_model'];
        $resultModel = $config['result_model'];
        $task = $taskModel::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('id', $taskId)
            ->where('delete_time', 0)
            ->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('下载任务不存在');
        }

        $baseResultQuery = $resultModel::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('task_id', $taskId);
        $hasMappedRows = (clone $baseResultQuery)->count() > 0;
        $results = (clone $baseResultQuery)
            ->where('delete_time', 0)
            ->where(self::uriField($config), '<>', '')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        if ($results || $hasMappedRows || empty($config['fallback_image_result'])) {
            return $results;
        }

        return self::imageResultsFromMappedTask($tenantId, $userId, $task->toArray());
    }

    private static function imageTaskResults(int $tenantId, int $userId, array $config, int $taskId): array
    {
        $taskModel = $config['task_model'];
        $style = (string)($config['style'] ?? '');
        $taskQuery = $taskModel::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('id', $taskId)
            ->where('delete_time', 0);
        if ($style !== '') {
            $taskQuery->where('style', $style);
        }
        $task = $taskQuery->findOrEmpty();
        if ($task->isEmpty()) {
            throw new Exception('下载任务不存在');
        }

        $imageTaskIds = $taskModel === AigcImageTask::class
            ? [(int)$taskId]
            : self::imageTaskIds($task->toArray());
        if (!$imageTaskIds) {
            return [];
        }

        $validTaskIds = AigcImageTask::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('delete_time', 0)
            ->whereIn('id', $imageTaskIds)
            ->column('id');
        $validTaskIds = array_values(array_unique(array_filter(array_map('intval', $validTaskIds))));
        if (!$validTaskIds) {
            return [];
        }

        return AigcImageResult::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('delete_time', 0)
            ->where('image_uri', '<>', '')
            ->whereIn('task_id', $validTaskIds)
            ->order('task_id', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    private static function imageTaskIds(array $task): array
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
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    private static function imageResultsFromMappedTask(int $tenantId, int $userId, array $task): array
    {
        $imageTaskIds = self::imageTaskIds($task);
        if (!$imageTaskIds) {
            return [];
        }

        $validTaskIds = AigcImageTask::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('delete_time', 0)
            ->whereIn('id', $imageTaskIds)
            ->column('id');
        $validTaskIds = array_values(array_unique(array_filter(array_map('intval', $validTaskIds))));
        if (!$validTaskIds) {
            return [];
        }

        return AigcImageResult::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('delete_time', 0)
            ->where('image_uri', '<>', '')
            ->whereIn('task_id', $validTaskIds)
            ->order('task_id', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    private static function readResultContent(array $result): string|false
    {
        $uri = self::resultUri($result);
        if ($uri === '') {
            return false;
        }

        $localPath = self::localFilePath($uri, (string)($result['storage_engine'] ?? ''));
        if ($localPath !== '' && is_file($localPath)) {
            return file_get_contents($localPath);
        }

        $url = FileService::getFileUrlByStorage(
            $uri,
            (string)($result['storage_scope'] ?? ''),
            (string)($result['storage_engine'] ?? ''),
            (string)($result['storage_domain'] ?? '')
        );
        return self::readRemoteContent($url);
    }

    private static function localFilePath(string $uri, string $storageEngine): string
    {
        $path = '';
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            $urlPath = ltrim((string)(parse_url($uri, PHP_URL_PATH) ?: ''), '/');
            if (str_starts_with($urlPath, 'uploads/') || str_starts_with($urlPath, 'resource/')) {
                $path = $urlPath;
            }
        } elseif ($storageEngine === 'local' || str_starts_with(ltrim($uri, '/'), 'uploads/') || str_starts_with(ltrim($uri, '/'), 'resource/')) {
            $path = ltrim($uri, '/');
        }

        return $path !== '' ? public_path() . $path : '';
    }

    private static function readRemoteContent(string $url): string|false
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => 'LikeAdmin-AiToolDownload/1.0',
            ]);
            $content = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($content !== false && ($status === 0 || ($status >= 200 && $status < 300))) {
                return $content;
            }
            return false;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'follow_location' => 1,
                'ignore_errors' => true,
                'header' => "User-Agent: LikeAdmin-AiToolDownload/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        return @file_get_contents($url, false, $context);
    }

    private static function zipEntryName(string $prefix, array $result, int $index): string
    {
        $extension = self::resultExtension($result);
        return $prefix . '-' . ($index + 1) . '.' . $extension;
    }

    private static function resultExtension(array $result): string
    {
        $uri = strtolower(self::resultUri($result));
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: $uri);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'mp4', 'webm', 'mov'], true) ? $extension : 'png';
    }

    private static function uriField(array $config): string
    {
        return (string)($config['uri_field'] ?? 'image_uri');
    }

    private static function resultUri(array $result): string
    {
        return trim((string)($result['image_uri'] ?? $result['video_uri'] ?? ''));
    }
}
