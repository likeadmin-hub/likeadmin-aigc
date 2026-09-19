<?php

namespace app\common\service\app\aigc_short_drama;

use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_local_redraw\AigcLocalRedrawService;
use app\common\service\app\aigc_llm\AigcLlmService;
use app\common\service\app\aigc_music\AigcMusicService;
use app\common\service\app\aigc_video\AigcVideoPosterService;
use app\common\service\app\aigc_video\AigcVideoService;
use app\common\service\power\MarketTextModelRuntimeService;
use app\common\service\FileService;
use Exception;
use think\facade\Db;

/**
 * Short-drama-owned free canvas persistence and execution facade.
 *
 * This class delegates only to platform generation runtimes, while retaining document ownership,
 * run history and result projection inside the aigc_short_drama namespace.
 */
class ShortDramaCanvasService
{
    private const DOCUMENT_TABLE = 'aigc_short_drama_canvas';
    private const RUN_TABLE = 'aigc_short_drama_canvas_run';

    public static function current(int $tenantId, int $userId, int $id = 0): array
    {
        $query = Db::name(self::DOCUMENT_TABLE)->where([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
        ]);
        if ($id > 0) {
            $row = $query->where('id', $id)->find();
            if (!$row) throw new Exception('画布项目不存在或无权访问');
            return self::formatDocument($row, true);
        }
        $row = $query->order('id', 'desc')->find();
        if (!$row) {
            return self::create($tenantId, $userId, []);
        }
        return self::formatDocument($row, true);
    }

    public static function create(int $tenantId, int $userId, array $params): array
    {
        $title = mb_substr(trim((string)($params['title'] ?? '')) ?: '无标题空间', 0, 40);
        $now = time();
        $id = Db::name(self::DOCUMENT_TABLE)->insertGetId([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'title' => $title,
            'nodes_json' => '[]', 'edges_json' => '[]', 'viewport_json' => '{}',
            'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
        ]);
        return self::formatDocument(Db::name(self::DOCUMENT_TABLE)->where('id', $id)->find(), true);
    }

    public static function lists(int $tenantId, int $userId, array $params = []): array
    {
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(50, max(1, (int)($params['page_size'] ?? 20)));
        $query = Db::name(self::DOCUMENT_TABLE)->where(['tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0]);
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') $query->whereLike('title', '%' . $keyword . '%');
        $count = (int)(clone $query)->count();
        $rows = $query->order(['update_time' => 'desc', 'id' => 'desc'])->page($pageNo, $pageSize)->select()->toArray();
        return ['lists' => array_map(static fn(array $row): array => self::formatDocument($row), $rows), 'count' => $count, 'page_no' => $pageNo, 'page_size' => $pageSize];
    }

    public static function save(int $tenantId, int $userId, array $params): array
    {
        $document = self::ownedDocument($tenantId, $userId, (int)($params['id'] ?? 0));
        $nodes = self::normalizeNodes((array)($params['nodes'] ?? []));
        $nodes = self::mergePersistedVideoPosters($nodes, self::decode((string)($document['nodes_json'] ?? '')));
        $edges = self::normalizeEdges((array)($params['edges'] ?? []), $nodes);
        self::queueVideoPosters($tenantId, $userId, (int)$document['id'], $nodes, false);
        $title = trim((string)($params['title'] ?? $document['title']));
        $title = mb_substr($title ?: '无标题空间', 0, 40);
        Db::name(self::DOCUMENT_TABLE)->where('id', $document['id'])->update([
            'title' => $title, 'nodes_json' => json_encode($nodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'edges_json' => json_encode($edges, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'viewport_json' => json_encode((array)($params['viewport'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'update_time' => time(),
        ]);
        self::queueVideoPosters($tenantId, $userId, (int)$document['id'], $nodes);
        return self::currentById($tenantId, $userId, (int)$document['id']);
    }

    /**
     * Persist a still image for one owned video node.
     *
     * This deliberately accepts a canvas/node pair instead of a URL. It keeps
     * the request tenant-scoped and lets the server read object storage without
     * relying on the browser's CORS and Content-Disposition behaviour.
     */
    public static function captureVideoFrame(int $tenantId, int $userId, array $params): array
    {
        $canvasId = (int)($params['canvas_id'] ?? 0);
        if ($canvasId <= 0) throw new Exception('缺少画布项目');
        $document = self::ownedDocument($tenantId, $userId, $canvasId);
        $nodeId = trim((string)($params['node_id'] ?? ''));
        if ($nodeId === '') throw new Exception('缺少视频节点');
        $node = null;
        foreach (self::decode((string)($document['nodes_json'] ?? '[]')) as $item) {
            if ((string)($item['id'] ?? '') === $nodeId) {
                $node = $item;
                break;
            }
        }
        if (!is_array($node)) throw new Exception('视频节点不存在或已被删除');
        $metadata = is_array($node['metadata'] ?? null) ? $node['metadata'] : [];
        $isVideo = (string)($node['type'] ?? '') === 'video'
            || str_starts_with(strtolower((string)($metadata['mimeType'] ?? $metadata['mime_type'] ?? '')), 'video/');
        if (!$isVideo) throw new Exception('只能对视频节点截帧');

        $uri = self::canvasStoredUri((string)($metadata['video_url'] ?? $metadata['url'] ?? ''));
        if ($uri === '') throw new Exception('该视频尚未保存到可截帧的存储');
        $mode = strtolower(trim((string)($params['mode'] ?? 'current')));
        if (!in_array($mode, ['first', 'current', 'last'], true)) $mode = 'current';
        $duration = max(0, min(28800, (float)($params['duration'] ?? 0)));
        $requestedTime = max(0, min(28800, (float)($params['time'] ?? 0)));
        $time = match ($mode) {
            'first' => $requestedTime,
            'last' => $requestedTime > 0 ? $requestedTime : max(0.001, $duration - 0.05),
            default => $requestedTime,
        };
        if ($time <= 0) $time = 0.001;
        // A playhead at duration is beyond the final decodable frame.
        if ($duration > 0) $time = min($time, max(0.001, $duration - 0.05));

        $frame = AigcVideoPosterService::createFrame(
            $tenantId,
            $uri,
            (string)($metadata['storage_scope'] ?? ''),
            (string)($metadata['storage_engine'] ?? ''),
            (string)($metadata['storage_domain'] ?? ''),
            $time,
            'frames'
        );
        return $frame + [
            'url' => FileService::getFileUrlByStorage(
                (string)$frame['uri'],
                (string)$frame['storage_scope'],
                (string)$frame['storage_engine'],
                (string)$frame['storage_domain']
            ),
            'source_node_id' => $nodeId,
            'capture_time' => $time,
        ];
    }

    /** Reuse native canvas image processing, with short-drama ownership checks. */
    public static function editImage(int $tenantId, int $userId, array $params): array
    {
        $id = (int)($params['canvas_id'] ?? 0);
        if ($id <= 0) throw new Exception('缺少画布项目');
        $document = self::ownedDocument($tenantId, $userId, $id);
        foreach (self::decode((string)$document['nodes_json']) as $node) {
            if ((string)$node['id'] !== (string)($params['node_id'] ?? '')) continue;
            if (($node['type'] ?? '') !== 'image') throw new Exception('只能编辑图片节点');
            $meta = (array)($node['metadata'] ?? []);
            $source = (string)($meta['image'] ?? $meta['url'] ?? '');
            if ($source === '' || str_starts_with($source, 'blob:')) throw new Exception('请等待图片上传完成');
            // Never allow the request to choose a different source file.
            $params['source_url'] = $source;
            return match ((string)($params['operation'] ?? '')) {
                'crop' => AigcCanvasService::cropImage($tenantId, $userId, $params),
                'transform' => AigcCanvasService::rotateImage($tenantId, $userId, $params),
                default => throw new Exception('不支持的图片编辑操作'),
            };
        }
        throw new Exception('图片节点不存在或已被删除');
    }

    /**
     * Soft-delete only the canvas workspace and its execution projections.
     * Generated assets remain part of the user's historical asset library.
     */
    public static function delete(int $tenantId, int $userId, int $id): array
    {
        $document = self::ownedDocument($tenantId, $userId, $id);
        Db::transaction(function () use ($tenantId, $userId, $document): void {
            $running = Db::name(self::RUN_TABLE)->where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'canvas_id' => (int)$document['id'],
                'delete_time' => 0,
            ])->whereIn('status', ['pending', 'running'])->lock(true)->count();
            if ($running > 0) throw new Exception('请等待画布任务完成后删除项目');

            $time = time();
            Db::name(self::DOCUMENT_TABLE)->where([
                'id' => (int)$document['id'],
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'delete_time' => 0,
            ])->update(['delete_time' => $time, 'update_time' => $time]);
            Db::name(self::RUN_TABLE)->where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'canvas_id' => (int)$document['id'],
                'delete_time' => 0,
            ])->update(['delete_time' => $time, 'update_time' => $time]);
        });
        return ['id' => (int)$document['id']];
    }

    public static function submit(int $tenantId, int $userId, array $params): array
    {
        $document = self::ownedDocument($tenantId, $userId, (int)($params['canvas_id'] ?? 0));
        $nodeId = trim((string)($params['node_id'] ?? ''));
        $type = strtolower(trim((string)($params['type'] ?? '')));
        if (!in_array($type, ['text', 'image', 'video', 'audio'], true)) throw new Exception('不支持的短剧画布节点类型');
        if ($nodeId === '') throw new Exception('缺少画布节点');
        $payload = self::generationPayload($type, $params);
        $now = time();
        $runId = Db::name(self::RUN_TABLE)->insertGetId([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'canvas_id' => (int)$document['id'], 'node_id' => $nodeId,
            'node_type' => $type, 'status' => 'running', 'progress' => 5,
            'request_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result_json' => '{}', 'error' => '', 'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
        ]);
        try {
            $result = match ($type) {
                'text' => AigcLlmService::generateText($tenantId, $userId, $payload),
                'image' => ($payload['operation'] ?? '') === 'local_redraw'
                    ? AigcLocalRedrawService::generate($tenantId, $userId, $payload)
                    : AigcImageService::generate($tenantId, $userId, $payload),
                'video' => AigcVideoService::generate($tenantId, $userId, $payload),
                'audio' => AigcMusicService::generate($tenantId, $userId, $payload),
            };
            // Text generation is a synchronous market request. Its successful
            // response carries content rather than an asynchronous task status,
            // so treating an omitted status as "running" leaves the canvas node
            // polling forever even though the provider has already finished.
            $status = $type === 'text'
                ? 'success'
                : self::normalizeStatus((string)($result['status'] ?? 'running'));
            Db::name(self::RUN_TABLE)->where('id', $runId)->update([
                'provider_task_id' => (string)($result['image_task_id'] ?? $result['task_id'] ?? $result['id'] ?? ''),
                'status' => $status, 'progress' => $status === 'success' ? 100 : 25,
                'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'update_time' => time(),
            ]);
            if ($type === 'text') {
                MarketTextModelRuntimeService::bindBusinessTask(
                    (int)($result['app_task_id'] ?? 0),
                    self::RUN_TABLE,
                    $runId
                );
            }
            self::syncShortDramaTask($runId);
        } catch (\Throwable $e) {
            Db::name(self::RUN_TABLE)->where('id', $runId)->update([
                'status' => 'failed', 'progress' => 0, 'error' => mb_substr($e->getMessage(), 0, 500), 'update_time' => time(),
            ]);
            self::syncShortDramaTask($runId);
            throw $e instanceof Exception ? $e : new Exception('短剧画布任务提交失败，请稍后重试');
        }
        return self::runDetail($tenantId, $userId, $runId);
    }

    public static function runDetail(int $tenantId, int $userId, int $runId): array
    {
        $run = Db::name(self::RUN_TABLE)->where(['id' => $runId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0])->find();
        if (!$run) throw new Exception('画布任务不存在或无权访问');
        self::refreshRun($run);
        $run = Db::name(self::RUN_TABLE)->where('id', $runId)->find();
        return self::formatRun($run);
    }

    private static function refreshRun(array $run): void
    {
        // Successful runs are reconciled again on detail reads. This repairs an
        // expired/raw provider URI into a storage-authorized delivery URL and
        // backfills short-drama history if a browser closed before polling.
        if (in_array((string)$run['status'], ['failed', 'canceled'], true)) {
            self::syncShortDramaTask((int)$run['id']);
            return;
        }
        $externalId = (int)($run['provider_task_id'] ?? 0);
        if ($externalId <= 0 || (string)$run['node_type'] === 'text') return;
        $type = (string)$run['node_type'];
        // Image-market tasks expose an explicit reconciliation hook. Video and
        // music runtimes publish their status through their own callback flows,
        // so reading their task rows is the non-invasive reconciliation path.
        if ($type === 'image') {
            try { AigcImageService::syncMarketTaskResult((int)$run['tenant_id'], $externalId, (int)$run['user_id']); } catch (\Throwable) { }
        }
        $table = $type === 'image' ? 'aigc_image_task' : ($type === 'video' ? 'aigc_video_task' : 'aigc_music_task');
        $task = Db::name($table)->where(['id' => $externalId, 'tenant_id' => (int)$run['tenant_id'], 'user_id' => (int)$run['user_id'], 'delete_time' => 0])->find();
        if (!$task) return;
        $status = self::normalizeStatus((string)($task['status'] ?? 'running'));
        $resultTable = $type === 'image' ? 'aigc_image_result' : ($type === 'video' ? 'aigc_video_result' : 'aigc_music_result');
        $column = $type === 'image' ? 'image_uri' : ($type === 'video' ? 'video_uri' : 'audio_uri');
        $results = Db::name($resultTable)->where(['tenant_id' => (int)$run['tenant_id'], 'task_id' => $externalId, 'delete_time' => 0])->order('id', 'asc')->select()->toArray();
        $urls = array_values(array_filter(array_map(static function (array $item) use ($column, $type): array {
            $uri = (string)($item[$column] ?? '');
            $scope = (string)($item['storage_scope'] ?? 'tenant');
            $engine = (string)($item['storage_engine'] ?? 'local');
            $domain = (string)($item['storage_domain'] ?? '');
            $coverUri = $type === 'video' ? (string)($item['cover_uri'] ?? '') : '';
            return [
                'url' => $uri === '' ? '' : FileService::getFileUrlByStorage($uri, $scope, $engine, $domain),
                'uri' => $uri, 'storage_scope' => $scope, 'storage_engine' => $engine, 'storage_domain' => $domain,
                'poster_uri' => $coverUri,
                'poster_url' => $coverUri === '' ? '' : FileService::getFileUrlByStorage($coverUri, $scope, $engine, $domain),
            ];
        }, $results), static fn(array $item): bool => $item['url'] !== ''));
        $payload = json_decode((string)$run['result_json'], true) ?: [];
        if ($urls) $payload['results'] = $urls;
        Db::name(self::RUN_TABLE)->where('id', $run['id'])->update([
            'status' => $status, 'progress' => $status === 'success' ? 100 : max(25, (int)($task['progress'] ?? 25)),
            'error' => (string)($task['error'] ?? $task['error_msg'] ?? ''),
            'result_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'update_time' => time(),
        ]);
        if ($type === 'video' && $status === 'success' && $urls) {
            self::projectVideoRunToCanvas($run, $urls[0]);
        }
        self::syncShortDramaTask((int)$run['id']);
    }

    /** Persist completed video metadata even when the browser closes before its next poll. */
    private static function projectVideoRunToCanvas(array $run, array $result): void
    {
        $canvasId = (int)($run['canvas_id'] ?? 0);
        $nodeId = (string)($run['node_id'] ?? '');
        $uri = self::canvasStoredUri((string)($result['uri'] ?? $result['url'] ?? ''));
        if ($canvasId <= 0 || $nodeId === '' || $uri === '') return;
        Db::transaction(function () use ($run, $result, $canvasId, $nodeId, $uri): void {
            $document = Db::name(self::DOCUMENT_TABLE)->where([
                'id' => $canvasId, 'tenant_id' => (int)$run['tenant_id'], 'user_id' => (int)$run['user_id'], 'delete_time' => 0,
            ])->lock(true)->find();
            if (!$document) return;
            $nodes = self::decode((string)($document['nodes_json'] ?? ''));
            $changed = false;
            foreach ($nodes as &$node) {
                if ((string)($node['id'] ?? '') !== $nodeId) continue;
                $metadata = is_array($node['metadata'] ?? null) ? $node['metadata'] : [];
                if ((int)($metadata['canvasRunId'] ?? 0) !== (int)$run['id']) continue;
                $metadata = array_merge($metadata, [
                    'url' => (string)$result['url'], 'video_url' => (string)$result['url'],
                    'storage_scope' => (string)($result['storage_scope'] ?? ''),
                    'storage_engine' => (string)($result['storage_engine'] ?? ''),
                    'storage_domain' => (string)($result['storage_domain'] ?? ''),
                    'poster_url' => (string)($result['poster_url'] ?? ''),
                    'poster_uri' => (string)($result['poster_uri'] ?? ''),
                    'poster_status' => !empty($result['poster_url']) ? 'ready' : 'pending',
                    'status' => 'success', 'progress' => 100, 'error' => '',
                ]);
                $node['metadata'] = $metadata;
                $changed = true;
                break;
            }
            unset($node);
            if (!$changed) return;
            self::queueVideoPosters((int)$run['tenant_id'], (int)$run['user_id'], $canvasId, $nodes);
            Db::name(self::DOCUMENT_TABLE)->where('id', $canvasId)->update([
                'nodes_json' => json_encode($nodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'update_time' => time(),
            ]);
        });
    }

    /** Mirror canvas-owned work into the short-drama task/asset history without sharing another canvas app. */
    private static function syncShortDramaTask(int $runId): void
    {
        $run = Db::name(self::RUN_TABLE)->where('id', $runId)->find();
        if (!$run) return;
        $canvas = Db::name(self::DOCUMENT_TABLE)->where([
            'id' => (int)$run['canvas_id'], 'tenant_id' => (int)$run['tenant_id'], 'user_id' => (int)$run['user_id'], 'delete_time' => 0,
        ])->find();
        $taskId = 'canvas_run_' . (int)$run['id'];
        $status = (string)$run['status'];
        $result = self::decode((string)$run['result_json']);
        $source = self::sourceTaskProjection($run);
        if ((string)$run['node_type'] === 'text') {
            $source = array_merge($source, self::textResultProjection($result));
        }
        $now = time();
        $data = [
            'tenant_id' => (int)$run['tenant_id'], 'user_id' => (int)$run['user_id'], 'project_id' => 0, 'canvas_id' => (int)$run['canvas_id'], 'shot_id' => '',
            'task_id' => $taskId, 'parent_task_id' => '', 'source_task_id' => (string)$run['provider_task_id'],
            'source_app_code' => AigcShortDramaService::APP_CODE, 'task_type' => 'canvas_' . (string)$run['node_type'],
            'skill_id' => 0, 'skill_version' => 0, 'skill_source' => 'none', 'skill_snapshot_json' => '{}',
            'app_task_id' => (int)($source['app_task_id'] ?? 0), 'consumption_id' => (int)($source['consumption_id'] ?? 0),
            'market_product_id' => (int)($source['market_product_id'] ?? 0), 'market_sku_id' => (int)($source['market_sku_id'] ?? 0),
            'status' => $status, 'progress' => (int)$run['progress'], 'provider' => (string)($source['provider'] ?? 'canvas'), 'provider_task_id' => (string)($source['provider_task_id'] ?? ''),
            'provider_request_id' => (string)($source['provider_request_id'] ?? ''), 'model_json' => json_encode((array)($source['model'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'request_json' => (string)$run['request_json'],
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'input_asset_ids' => '[]',
            'pricing_snapshot' => json_encode((array)($source['pricing'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'billing_status' => (string)($source['billing_status'] ?? 'delegated'), 'tenant_cost_points' => (float)($source['tenant_cost_points'] ?? 0), 'user_charge_points' => (float)($source['user_charge_points'] ?? 0),
            'idempotency_key' => sha1((int)$run['tenant_id'] . '|' . (int)$run['user_id'] . '|' . $taskId), 'retry_count' => 0,
            'error_code' => $status === 'failed' ? 'canvas_generation_failed' : '', 'error_msg' => (string)$run['error'],
            'operator_error' => '', 'safety_status' => $status === 'success' ? 'passed' : 'pending', 'started_at' => (int)$run['create_time'],
            'finished_at' => in_array($status, ['success', 'failed', 'canceled'], true) ? $now : 0, 'update_time' => $now, 'delete_time' => 0,
        ];
        $existing = Db::name('aigc_short_drama_generation_task')->where(['tenant_id' => (int)$run['tenant_id'], 'task_id' => $taskId, 'delete_time' => 0])->find();
        if ($existing) Db::name('aigc_short_drama_generation_task')->where('id', $existing['id'])->update($data);
        else Db::name('aigc_short_drama_generation_task')->insert($data + ['output_asset_ids' => '[]', 'create_time' => $now]);
        if ($status !== 'success' || !in_array((string)$run['node_type'], ['image', 'video', 'audio'], true)) return;
        $type = (string)$run['node_type'];
        $resultTable = $type === 'image' ? 'aigc_image_result' : ($type === 'video' ? 'aigc_video_result' : 'aigc_music_result');
        $column = $type === 'image' ? 'image_uri' : ($type === 'video' ? 'video_uri' : 'audio_uri');
        $providerTaskId = (int)$run['provider_task_id'];
        if ($providerTaskId <= 0) return;
        $assetIds = [];
        foreach (Db::name($resultTable)->where(['tenant_id' => (int)$run['tenant_id'], 'task_id' => $providerTaskId, 'delete_time' => 0])->select()->toArray() as $index => $item) {
            $uri = (string)($item[$column] ?? '');
            if ($uri === '') continue;
            $asset = Db::name('aigc_short_drama_asset')->where(['tenant_id' => (int)$run['tenant_id'], 'user_id' => (int)$run['user_id'], 'project_id' => 0, 'canvas_id' => (int)$run['canvas_id'], 'task_id' => $taskId, 'uri' => $uri, 'delete_time' => 0])->find();
            if (!$asset) {
                $assetId = Db::name('aigc_short_drama_asset')->insertGetId([
                    'tenant_id' => (int)$run['tenant_id'], 'user_id' => (int)$run['user_id'], 'project_id' => 0, 'canvas_id' => (int)$run['canvas_id'], 'task_id' => $taskId, 'shot_id' => '',
                    'asset_type' => 'canvas_' . $type, 'title' => (string)($canvas['title'] ?? '画布') . ' · ' . ($type === 'image' ? '图片' : ($type === 'video' ? '视频' : '音频')) . ((int)$index + 1),
                    'uri' => $uri, 'cover_uri' => '', 'storage_scope' => (string)($item['storage_scope'] ?? 'tenant'), 'storage_engine' => (string)($item['storage_engine'] ?? 'local'), 'storage_domain' => (string)($item['storage_domain'] ?? ''),
                    'mime_type' => $type === 'image' ? 'image/png' : ($type === 'video' ? 'video/mp4' : 'audio/mpeg'), 'file_size' => 0, 'width' => (int)($item['width'] ?? 0), 'height' => (int)($item['height'] ?? 0), 'duration' => (float)($item['duration'] ?? 0), 'checksum' => '',
                    'meta_json' => json_encode(['source' => 'short_drama_canvas', 'canvas_id' => (int)$run['canvas_id'], 'canvas_run_id' => (int)$run['id'], 'provider_task_id' => $providerTaskId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'status' => 'ready', 'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
                ]);
            } else $assetId = (int)$asset['id'];
            $assetIds[] = $assetId;
        }
        if ($assetIds) Db::name('aigc_short_drama_generation_task')->where(['tenant_id' => (int)$run['tenant_id'], 'task_id' => $taskId])->update(['output_asset_ids' => json_encode($assetIds), 'update_time' => time()]);
    }

    /** Read only the auditable fields from the task created by the delegated runtime. */
    private static function sourceTaskProjection(array $run): array
    {
        $type = (string)($run['node_type'] ?? '');
        $table = $type === 'image' ? 'aigc_image_task' : ($type === 'video' ? 'aigc_video_task' : ($type === 'audio' ? 'aigc_music_task' : ''));
        $sourceId = (int)($run['provider_task_id'] ?? 0);
        if ($table === '' || $sourceId <= 0) return [];
        $row = Db::name($table)->where(['id' => $sourceId, 'tenant_id' => (int)$run['tenant_id'], 'user_id' => (int)$run['user_id'], 'delete_time' => 0])->find();
        if (!$row) return [];
        $model = self::decode((string)($row['model_json'] ?? ''));
        if ($model === []) $model = array_filter(['model' => (string)($row['model'] ?? ''), 'channel' => (string)($row['channel'] ?? '')]);
        return [
            'app_task_id' => (int)($row['app_task_id'] ?? 0), 'consumption_id' => (int)($row['consumption_id'] ?? 0),
            'market_product_id' => (int)($row['market_product_id'] ?? 0), 'market_sku_id' => (int)($row['market_sku_id'] ?? 0),
            'provider' => (string)($row['provider'] ?? 'canvas'),
            'provider_task_id' => (string)($row['provider_task_id'] ?? ''),
            'provider_request_id' => (string)($row['provider_request_id'] ?? $row['market_request_id'] ?? ''),
            'model' => $model, 'pricing' => self::decode((string)($row['pricing_snapshot'] ?? '')),
            'billing_status' => (string)($row['billing_status'] ?? 'delegated'),
            'tenant_cost_points' => (float)($row['tenant_cost_points'] ?? 0), 'user_charge_points' => (float)($row['user_charge_points'] ?? 0),
        ];
    }

    /** Map the synchronous text runtime result into short-drama task history. */
    private static function textResultProjection(array $result): array
    {
        $billing = (array)($result['billing'] ?? []);
        return [
            'app_task_id' => (int)($result['app_task_id'] ?? 0),
            'consumption_id' => (int)($result['consumption_id'] ?? 0),
            'market_product_id' => (int)($result['market_product_id'] ?? 0),
            'market_sku_id' => (int)($result['market_sku_id'] ?? 0),
            'provider' => (string)($result['provider'] ?? 'power_market'),
            'provider_task_id' => (string)($result['provider_task_id'] ?? ''),
            'provider_request_id' => (string)($result['provider_request_id'] ?? ''),
            'model' => array_filter([
                'model_code' => (string)($result['model_code'] ?? ''),
                'channel_code' => (string)($result['channel_code'] ?? ''),
            ]),
            'pricing' => $billing,
            'billing_status' => (string)($billing['billing_status'] ?? 'settled'),
            'tenant_cost_points' => (float)($billing['tenant_cost_points'] ?? 0),
            'user_charge_points' => (float)($billing['user_charge_points'] ?? 0),
        ];
    }

    private static function generationPayload(string $type, array $params): array
    {
        $prompt = trim((string)($params['prompt'] ?? $params['content'] ?? ''));
        if ($prompt === '') throw new Exception('请输入提示内容');
        $payload = [
            'prompt' => $prompt, 'content' => $prompt, 'channel' => (string)($params['channel'] ?? ''),
            'model_code' => (string)($params['model_code'] ?? ''), 'model_id' => (string)($params['model_id'] ?? ''),
            'ratio' => (string)($params['ratio'] ?? $params['aspect_ratio'] ?? ''), 'duration' => (int)($params['duration'] ?? 0),
            'quantity' => max(1, min(4, (int)($params['count'] ?? $params['quantity'] ?? 1))),
            'generation_method' => (string)($params['generation_method'] ?? $params['generationMethod'] ?? ''),
            'reference_images' => array_values((array)($params['reference_images'] ?? [])),
            'reference_assets' => array_values((array)($params['reference_assets'] ?? [])),
            'source_app_code' => AigcShortDramaService::APP_CODE,
        ];
        if ($type === 'audio') $payload['lyrics'] = (string)($params['lyrics'] ?? '');
        if ($type === 'image' && ($params['operation'] ?? '') === 'local_redraw') {
            $payload['operation'] = 'local_redraw';
            $payload['source_image'] = trim((string)($params['source_image'] ?? ''));
            $payload['mask_image'] = trim((string)($params['mask_image'] ?? ''));
            if ($payload['source_image'] === '' || $payload['mask_image'] === '') throw new Exception('请选择原图并绘制蒙版');
        }
        foreach (['quality', 'resolution', 'negative_prompt'] as $key) {
            if (isset($params[$key])) $payload[$key] = (string)$params[$key];
        }
        return array_filter($payload, static fn($value) => $value !== '' && $value !== 0 || is_array($value));
    }

    private static function ownedDocument(int $tenantId, int $userId, int $id): array
    {
        if ($id <= 0) return self::current($tenantId, $userId);
        $row = Db::name(self::DOCUMENT_TABLE)->where(['id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0])->find();
        if (!$row) throw new Exception('画布不存在或无权访问');
        return $row;
    }
    private static function currentById(int $tenantId, int $userId, int $id): array { return self::formatDocument(self::ownedDocument($tenantId, $userId, $id), true); }
    private static function normalizeNodes(array $nodes): array { return array_values(array_slice(array_filter($nodes, static fn($node) => is_array($node) && isset($node['id']) && isset($node['type'])), 0, 200)); }
    /** Keep a completed poster when an older browser snapshot saves unrelated canvas changes. */
    private static function mergePersistedVideoPosters(array $nodes, array $persisted): array
    {
        $persistedById = [];
        foreach ($persisted as $node) $persistedById[(string)($node['id'] ?? '')] = $node;
        foreach ($nodes as &$node) {
            $metadata = is_array($node['metadata'] ?? null) ? $node['metadata'] : [];
            if (!empty($metadata['poster_url']) || !empty($metadata['poster_uri'])) continue;
            $saved = $persistedById[(string)($node['id'] ?? '')] ?? null;
            $savedMetadata = is_array($saved['metadata'] ?? null) ? $saved['metadata'] : [];
            $source = self::canvasStoredUri((string)($metadata['video_url'] ?? $metadata['url'] ?? ''));
            $sameVideo = $source !== '' && $source === self::canvasStoredUri((string)($savedMetadata['video_url'] ?? $savedMetadata['url'] ?? ''));
            if ($sameVideo && (!empty($savedMetadata['poster_url']) || !empty($savedMetadata['poster_uri']))) {
                foreach (['poster_url', 'poster_uri', 'poster_status'] as $key) if (isset($savedMetadata[$key])) $metadata[$key] = $savedMetadata[$key];
                $node['metadata'] = $metadata;
            }
        }
        unset($node);
        return $nodes;
    }
    /** Queue only persisted video nodes without a durable poster. Saving stays constant-time. */
    private static function queueVideoPosters(int $tenantId, int $userId, int $canvasId, array &$nodes, bool $enqueue = true): void
    {
        foreach ($nodes as &$node) {
            $metadata = is_array($node['metadata'] ?? null) ? $node['metadata'] : [];
            $isVideo = (string)($node['type'] ?? '') === 'video'
                || str_starts_with(strtolower((string)($metadata['mimeType'] ?? $metadata['mime_type'] ?? '')), 'video/');
            if (!$isVideo || !empty($metadata['poster_url']) || !empty($metadata['poster_uri'])) {
                continue;
            }
            $source = (string)($metadata['video_url'] ?? $metadata['url'] ?? '');
            $uri = self::canvasStoredUri($source);
            if ($uri === '') {
                continue;
            }
            $metadata['poster_status'] = 'pending';
            $node['metadata'] = $metadata;
            if ($enqueue) {
                ShortDramaCanvasPosterJobService::enqueue(
                    $tenantId,
                    $userId,
                    $canvasId,
                    (string)$node['id'],
                    $uri,
                    (string)($metadata['storage_scope'] ?? ''),
                    (string)($metadata['storage_engine'] ?? ''),
                    (string)($metadata['storage_domain'] ?? '')
                );
            }
        }
        unset($node);
    }
    private static function canvasStoredUri(string $value): string
    {
        $value = trim($value);
        if (preg_match('#^https?://#i', $value) === 1) {
            $value = ltrim(rawurldecode((string)(parse_url($value, PHP_URL_PATH) ?: '')), '/');
        }
        $value = ltrim($value, '/');
        return str_starts_with($value, 'uploads/') || str_starts_with($value, 'resource/') ? $value : '';
    }
    private static function normalizeEdges(array $edges, array $nodes): array { $ids = array_flip(array_map(static fn($node) => (string)$node['id'], $nodes)); return array_values(array_filter($edges, static fn($edge) => is_array($edge) && isset($ids[(string)($edge['from'] ?? '')], $ids[(string)($edge['to'] ?? '')]) && (string)$edge['from'] !== (string)$edge['to'])); }
    private static function decode(string $json): array { $decoded = json_decode($json, true); return is_array($decoded) ? $decoded : []; }
    private static function normalizeStatus(string $status): string { return in_array($status, ['success', 'failed', 'canceled'], true) ? $status : 'running'; }
    private static function formatDocument(array $row, bool $includeRuns = false): array
    {
        $nodes = self::decode((string)$row['nodes_json']);
        $createTime = (int)($row['create_time'] ?? 0);
        $data = [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'nodes' => $nodes,
            'edges' => self::decode((string)$row['edges_json']),
            'viewport' => self::decode((string)$row['viewport_json']),
            'created_at' => $createTime > 0 ? date('Y-m-d H:i:s', $createTime) : '',
            'update_time' => (int)$row['update_time'],
        ];
        if (!$includeRuns) return $data;
        // A browser can be refreshed after the backend creates a run but before
        // its debounce save writes canvasRunId into nodes_json. Recreate only the
        // missing visual nodes from owned run history, then return the latest run
        // per node so task results remain durable across browser refreshes.
        $runs = Db::name(self::RUN_TABLE)->where([
            'tenant_id' => (int)$row['tenant_id'], 'user_id' => (int)$row['user_id'], 'canvas_id' => (int)$row['id'], 'delete_time' => 0,
        ])->order('id', 'asc')->select()->toArray();
        $nodeIds = array_fill_keys(array_map(static fn(array $node): string => (string)($node['id'] ?? ''), $nodes), true);
        $recovered = false;
        foreach ($runs as $index => $run) {
            $nodeId = trim((string)$run['node_id']);
            if ($nodeId === '' || isset($nodeIds[$nodeId])) continue;
            $nodes[] = self::recoveredNode($run, count($nodes));
            $nodeIds[$nodeId] = true;
            $recovered = true;
        }
        if ($recovered) {
            $now = time();
            Db::name(self::DOCUMENT_TABLE)->where('id', (int)$row['id'])->update([
                'nodes_json' => json_encode($nodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'update_time' => $now,
            ]);
            $data['nodes'] = $nodes;
            $data['update_time'] = $now;
        }
        $latest = [];
        foreach (array_reverse($runs) as $run) {
            $nodeId = (string)$run['node_id'];
            if ($nodeId !== '' && !isset($latest[$nodeId])) $latest[$nodeId] = self::formatRun($run);
        }
        $data['runs'] = array_values($latest);
        return $data;
    }

    /** Build a durable canvas node for an already-owned generation run. */
    private static function recoveredNode(array $run, int $index): array
    {
        $type = (string)($run['node_type'] ?? 'image');
        $request = self::decode((string)($run['request_json'] ?? ''));
        $labels = ['text' => '文本生成器', 'image' => '图像生成器', 'video' => '视频生成器', 'audio' => '音频生成器'];
        $tones = ['text' => 'orange', 'image' => 'blue', 'video' => 'green', 'audio' => 'purple'];
        return [
            'id' => (string)$run['node_id'],
            'type' => $type,
            'title' => $labels[$type] ?? '生成器',
            'description' => '已恢复的画布任务',
            'tone' => $tones[$type] ?? 'gray',
            'x' => 120 + ($index % 3) * 340,
            'y' => 160 + intdiv($index, 3) * 340,
            'width' => $type === 'audio' ? 444 : 250,
            'height' => 250,
            'metadata' => [
                'canvasRunId' => (int)$run['id'],
                'status' => (string)($run['status'] ?? 'running'),
                'progress' => (int)($run['progress'] ?? 0),
                'prompt' => (string)($request['prompt'] ?? $request['content'] ?? ''),
                'source' => 'recovered_task',
            ],
        ];
    }
    private static function formatRun(array $row): array { $result = self::decode((string)$row['result_json']); return ['id' => (int)$row['id'], 'node_id' => (string)$row['node_id'], 'type' => (string)$row['node_type'], 'status' => (string)$row['status'], 'progress' => (int)$row['progress'], 'error' => (string)$row['error'], 'result' => $result, 'results' => (array)($result['results'] ?? $result['images'] ?? $result['videos'] ?? [])]; }
}
