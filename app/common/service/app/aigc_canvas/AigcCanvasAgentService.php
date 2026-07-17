<?php

namespace app\common\service\app\aigc_canvas;

use app\common\model\app\aigc_canvas\AigcCanvasProject;
use app\common\model\app\aigc_short_drama\AigcShortDramaAsset;
use app\common\service\FileService;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use Exception;

class AigcCanvasAgentService
{
    public static function createScriptPlan(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::createScriptPlan($tenantId, $userId, self::withCanvasSource($params, 'canvas_script_plan'));
    }

    public static function scriptPlanDetail(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::scriptPlanDetail(
            $tenantId,
            $userId,
            (string)($params['task_id'] ?? ''),
            (int)($params['project_id'] ?? 0)
        );
    }

    public static function streamScriptPlan(int $tenantId, int $userId, array $params, callable $emit): array
    {
        return AigcShortDramaService::streamScriptPlan($tenantId, $userId, self::withCanvasSource($params, 'canvas_script_stream'), $emit);
    }

    public static function listSubjects(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::subjectLibraryLists($tenantId, $userId, $params);
    }

    public static function saveSubject(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::saveSubjectLibrary($tenantId, $userId, self::withCanvasSource($params, 'canvas_subject_save'));
    }

    public static function describeSubject(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::describeSubjectImage($tenantId, $userId, self::withCanvasSource($params, 'canvas_subject_describe'));
    }

    public static function generateSubject(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::createSubjectLibraryGeneration($tenantId, $userId, self::withCanvasSource($params, 'canvas_subject_generate'));
    }

    public static function saveStoryboard(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::saveStoryboard($tenantId, $userId, self::withCanvasSource($params, 'canvas_storyboard_save'));
    }

    public static function saveVisualPlan(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::saveVisualPlan($tenantId, $userId, self::withCanvasSource($params, 'canvas_visual_plan_save'));
    }

    public static function insertStoryboardShot(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::insertStoryboardShot($tenantId, $userId, self::withCanvasSource($params, 'canvas_storyboard_insert'));
    }

    public static function copyStoryboardShot(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::copyStoryboardShot($tenantId, $userId, self::withCanvasSource($params, 'canvas_storyboard_copy'));
    }

    public static function deleteStoryboardShot(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::deleteStoryboardShot($tenantId, $userId, self::withCanvasSource($params, 'canvas_storyboard_delete'));
    }

    public static function createGeneration(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::createShotGenerationTask($tenantId, $userId, self::withCanvasSource($params, 'canvas_generation_create'));
    }

    public static function estimateGeneration(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::estimateShotGenerationTask($tenantId, $userId, self::withCanvasSource($params, 'canvas_generation_estimate'));
    }

    public static function generationDetail(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::generationTaskDetail($tenantId, $userId, (string)($params['task_id'] ?? ''));
    }

    public static function generationLists(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::generationTaskLists($tenantId, $userId, $params);
    }

    public static function retryGeneration(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::retryGenerationTask($tenantId, $userId, (string)($params['task_id'] ?? ''));
    }

    public static function cancelGeneration(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::cancelGenerationTask($tenantId, $userId, (string)($params['task_id'] ?? ''));
    }

    public static function deleteGeneration(int $tenantId, int $userId, array $params): void
    {
        AigcShortDramaService::deleteGenerationTask($tenantId, $userId, (string)($params['task_id'] ?? ''));
    }

    public static function streamGeneration(int $tenantId, int $userId, array $params, callable $emit): array
    {
        return AigcShortDramaService::streamGenerationTask($tenantId, $userId, self::withCanvasSource($params, 'canvas_generation_stream'), $emit);
    }

    public static function subjectImageHistory(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::subjectImageHistory($tenantId, $userId, (int)($params['subject_id'] ?? $params['item_id'] ?? 0));
    }

    public static function registerSubjectImage(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::registerSubjectImageAsset($tenantId, $userId, self::withCanvasSource($params, 'canvas_subject_register_image'));
    }

    public static function selectSubjectImage(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::selectSubjectImageAsset($tenantId, $userId, self::withCanvasSource($params, 'canvas_subject_select_image'));
    }

    public static function subjectThreeViewHistory(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::subjectThreeViewHistory($tenantId, $userId, (int)($params['subject_id'] ?? $params['item_id'] ?? 0));
    }

    public static function registerAsset(int $tenantId, int $userId, array $params): array
    {
        $params = self::withCanvasSource($params, 'canvas_asset_register');
        $projectId = (int)($params['project_id'] ?? $params['canvas_project_id'] ?? 0);
        if ($projectId > 0) {
            $project = AigcCanvasProject::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'id' => $projectId,
                'delete_time' => 0,
            ])->findOrEmpty();
            if ($project->isEmpty()) {
                throw new Exception('项目不存在');
            }
        }

        $assetType = self::normalizeAssetType((string)($params['asset_type'] ?? $params['type'] ?? 'reference_image'));
        $uri = FileService::setFileUrl((string)($params['uri'] ?? $params['url'] ?? ''));
        if ($uri === '') {
            throw new Exception('Asset file is required');
        }
        $coverUri = FileService::setFileUrl((string)($params['cover_uri'] ?? $params['cover_url'] ?? ''));
        $meta = is_array($params['meta'] ?? null) ? $params['meta'] : [];
        $meta = array_merge($meta, [
            'source' => (string)($meta['source'] ?? $params['source'] ?? 'aigc_canvas'),
            'source_app_code' => AigcCanvasService::APP_CODE,
            'source_type' => (string)($params['source_type'] ?? 'canvas_asset_register'),
            'canvas_project_id' => (string)$projectId,
            'canvas_node_id' => (string)($params['canvas_node_id'] ?? $params['node_id'] ?? ''),
            'asset_type' => $assetType,
            'title' => (string)($params['title'] ?? $meta['title'] ?? ''),
            'preview_url' => (string)($params['preview_url'] ?? $meta['preview_url'] ?? ''),
            'cover_preview_url' => (string)($params['cover_preview_url'] ?? $meta['cover_preview_url'] ?? ''),
        ]);
        $isRemoteUri = preg_match('/^https?:\/\//i', $uri) === 1;
        $time = time();
        $asset = AigcShortDramaAsset::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'task_id' => (string)($params['task_id'] ?? ''),
            'shot_id' => (string)($params['shot_id'] ?? ''),
            'asset_type' => $assetType,
            'title' => mb_substr(trim((string)($params['title'] ?? '')), 0, 120, 'UTF-8'),
            'uri' => $uri,
            'cover_uri' => $coverUri,
            'storage_scope' => (string)($params['storage_scope'] ?? 'tenant'),
            'storage_engine' => (string)($params['storage_engine'] ?? ($isRemoteUri ? '' : 'local')),
            'storage_domain' => (string)($params['storage_domain'] ?? ''),
            'mime_type' => mb_substr(trim((string)($params['mime_type'] ?? '')), 0, 120, 'UTF-8'),
            'file_size' => (int)($params['file_size'] ?? 0),
            'width' => (int)($params['width'] ?? 0),
            'height' => (int)($params['height'] ?? 0),
            'duration' => (float)($params['duration'] ?? 0),
            'checksum' => mb_substr(trim((string)($params['checksum'] ?? '')), 0, 100, 'UTF-8'),
            'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'ready',
            'create_time' => $time,
            'update_time' => $time,
            'delete_time' => 0,
        ]);

        return self::formatAsset($asset->toArray());
    }

    public static function selectAsset(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::selectStoryboardAsset($tenantId, $userId, self::withCanvasSource($params, 'canvas_asset_select'));
    }

    public static function scriptMessage(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::message($tenantId, $userId, self::withCanvasSource($params, 'canvas_script_message'));
    }

    public static function retryScript(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::retry($tenantId, $userId, (string)($params['task_id'] ?? ''));
    }

    public static function cancelScript(int $tenantId, int $userId, array $params): array
    {
        return AigcShortDramaService::cancel($tenantId, $userId, (string)($params['task_id'] ?? ''));
    }

    private static function withCanvasSource(array $params, string $source): array
    {
        $meta = is_array($params['meta'] ?? null) ? $params['meta'] : [];
        $params['source_app_code'] = AigcCanvasService::APP_CODE;
        $params['source_type'] = (string)($params['source_type'] ?? $source);
        $params['meta'] = array_merge($meta, [
            'source_app_code' => AigcCanvasService::APP_CODE,
            'source_type' => (string)$params['source_type'],
            'canvas_project_id' => (string)($params['canvas_project_id'] ?? ''),
            'canvas_id' => (string)($params['canvas_id'] ?? ''),
            'canvas_node_id' => (string)($params['canvas_node_id'] ?? $params['node_id'] ?? ''),
        ]);
        return $params;
    }

    private static function normalizeAssetType(string $type): string
    {
        return in_array($type, [
            'reference_image',
            'reference_video',
            'reference_audio',
            'subject_image',
            'scene_image',
            'shot_image',
            'shot_video',
        ], true) ? $type : 'reference_image';
    }

    public static function formatAsset(array $row): array
    {
        $uri = (string)($row['uri'] ?? '');
        $coverUri = (string)($row['cover_uri'] ?? '');
        $storageScope = (string)($row['storage_scope'] ?? '');
        $storageEngine = (string)($row['storage_engine'] ?? '');
        $storageDomain = (string)($row['storage_domain'] ?? '');
        $meta = json_decode((string)($row['meta_json'] ?? ''), true);
        return [
            'id' => (int)($row['id'] ?? 0),
            'project_id' => (int)($row['project_id'] ?? 0),
            'task_id' => (string)($row['task_id'] ?? ''),
            'shot_id' => (string)($row['shot_id'] ?? ''),
            'asset_type' => (string)($row['asset_type'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'uri' => $uri,
            'url' => $uri === '' ? '' : FileService::getFileUrlByStorage($uri, $storageScope, $storageEngine, $storageDomain),
            'preview_url' => (string)($meta['preview_url'] ?? ''),
            'cover_url' => $coverUri === '' ? '' : FileService::getFileUrlByStorage($coverUri, $storageScope, $storageEngine, $storageDomain),
            'cover_preview_url' => (string)($meta['cover_preview_url'] ?? ''),
            'mime_type' => (string)($row['mime_type'] ?? ''),
            'file_size' => (int)($row['file_size'] ?? 0),
            'width' => (int)($row['width'] ?? 0),
            'height' => (int)($row['height'] ?? 0),
            'duration' => (float)($row['duration'] ?? 0),
            'status' => (string)($row['status'] ?? 'ready'),
            'meta' => is_array($meta) ? $meta : [],
            'created_at' => (int)($row['create_time'] ?? 0),
        ];
    }
}
