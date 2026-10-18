<?php
namespace app\common\service\app\aigc_canvas;
use app\common\model\app\aigc_canvas\{AigcCanvasProject,AigcCanvasAsset};
use app\common\service\FileService;
use Exception;
/** Native canvas assets; independent of conversation orchestration. */
final class CanvasAssetService
{
    public static function registerAsset(int $tenantId, int $userId, array $params): array
    {
        if (($params['asset_type'] ?? $params['type'] ?? '') === 'text') {
            return self::registerText($tenantId, $userId, $params);
        }
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
        $asset = AigcCanvasAsset::create([
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

    /** Text is an owned database snapshot, not an external file URL. */
    private static function registerText(int $tenantId, int $userId, array $params): array
    {
        $content = $params['content'] ?? '';
        $nodeId = $params['node_id'] ?? '';
        if (!is_string($content) || trim($content) === '' || mb_strlen($content) > 200000) throw new Exception('请输入非空且20万字以内的文本');
        if (!is_string($nodeId) || $nodeId === '' || mb_strlen($nodeId) > 200) throw new Exception('文本节点标识无效');
        $projectId = (int)($params['project_id'] ?? 0);
        return \think\facade\Db::transaction(static function () use ($tenantId, $userId, $projectId, $nodeId, $content, $params) {
            // Serialize re-adds/retries for this project without a schema change.
            $project = AigcCanvasProject::where(['tenant_id'=>$tenantId, 'user_id'=>$userId, 'id'=>$projectId, 'delete_time'=>0])->lock(true)->findOrEmpty();
            if ($projectId <= 0 || $project->isEmpty()) throw new Exception('项目不存在');
            $asset = AigcCanvasAsset::where(['tenant_id'=>$tenantId, 'user_id'=>$userId, 'project_id'=>$projectId, 'asset_type'=>'text', 'delete_time'=>0])
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.canvas_node_id')) = ?", [$nodeId])->findOrEmpty();
            $now = time();
            $data = [
                'title'=>mb_substr(trim((string)($params['title'] ?? '')), 0, 120),
                'asset_type'=>'text', 'uri'=>'', 'cover_uri'=>'',
                'storage_scope'=>'tenant', 'storage_engine'=>'', 'storage_domain'=>'',
                'mime_type'=>'text/plain', 'file_size'=>strlen($content), 'checksum'=>hash('sha256', $content),
                'meta_json'=>json_encode(['source'=>'aigc_canvas', 'source_app_code'=>'aigc_canvas', 'source_type'=>'canvas_text_register', 'canvas_project_id'=>(string)$projectId, 'canvas_node_id'=>$nodeId, 'node_id'=>$nodeId, 'node_type'=>'text', 'content'=>$content], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status'=>'ready', 'update_time'=>$now,
            ];
            if ($asset->isEmpty()) $asset = AigcCanvasAsset::create($data + ['tenant_id'=>$tenantId, 'user_id'=>$userId, 'project_id'=>$projectId, 'task_id'=>'', 'shot_id'=>'', 'create_time'=>$now, 'delete_time'=>0]);
            else $asset->save($data);
            return self::formatAsset($asset->toArray());
        });
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
            'logo',
            'brand_logo',
            'brand_asset',
            'subject',
            'subject_image',
            'subject_three_view',
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
