<?php

namespace app\common\service\app\aigc_short_drama;

use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\app\aigc_llm\AigcLlmService;
use app\common\service\app\aigc_music\AigcMusicService;
use app\common\service\app\aigc_video\AigcVideoService;
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
        $edges = self::normalizeEdges((array)($params['edges'] ?? []), $nodes);
        $title = trim((string)($params['title'] ?? $document['title']));
        $title = mb_substr($title ?: '无标题空间', 0, 40);
        Db::name(self::DOCUMENT_TABLE)->where('id', $document['id'])->update([
            'title' => $title, 'nodes_json' => json_encode($nodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'edges_json' => json_encode($edges, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'viewport_json' => json_encode((array)($params['viewport'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'update_time' => time(),
        ]);
        return self::currentById($tenantId, $userId, (int)$document['id']);
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
                'image' => AigcImageService::generate($tenantId, $userId, $payload),
                'video' => AigcVideoService::generate($tenantId, $userId, $payload),
                'audio' => AigcMusicService::generate($tenantId, $userId, $payload),
            };
            $status = self::normalizeStatus((string)($result['status'] ?? 'running'));
            Db::name(self::RUN_TABLE)->where('id', $runId)->update([
                'provider_task_id' => (string)($result['task_id'] ?? $result['id'] ?? ''),
                'status' => $status, 'progress' => $status === 'success' ? 100 : 25,
                'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'update_time' => time(),
            ]);
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
        $urls = array_values(array_filter(array_map(static function (array $item) use ($column): array {
            $uri = (string)($item[$column] ?? '');
            return ['url' => $uri === '' ? '' : FileService::getFileUrlByStorage($uri, (string)($item['storage_scope'] ?? 'tenant'), (string)($item['storage_engine'] ?? 'local'), (string)($item['storage_domain'] ?? '')),
                'uri' => $uri, 'storage_scope' => (string)($item['storage_scope'] ?? 'tenant'), 'storage_engine' => (string)($item['storage_engine'] ?? 'local'), 'storage_domain' => (string)($item['storage_domain'] ?? '')];
        }, $results), static fn(array $item): bool => $item['url'] !== ''));
        $payload = json_decode((string)$run['result_json'], true) ?: [];
        if ($urls) $payload['results'] = $urls;
        Db::name(self::RUN_TABLE)->where('id', $run['id'])->update([
            'status' => $status, 'progress' => $status === 'success' ? 100 : max(25, (int)($task['progress'] ?? 25)),
            'error' => (string)($task['error'] ?? $task['error_msg'] ?? ''),
            'result_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'update_time' => time(),
        ]);
        self::syncShortDramaTask((int)$run['id']);
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

    private static function generationPayload(string $type, array $params): array
    {
        $prompt = trim((string)($params['prompt'] ?? $params['content'] ?? ''));
        if ($prompt === '') throw new Exception('请输入提示内容');
        $payload = [
            'prompt' => $prompt, 'content' => $prompt, 'channel' => (string)($params['channel'] ?? ''),
            'model_code' => (string)($params['model_code'] ?? ''), 'model_id' => (string)($params['model_id'] ?? ''),
            'ratio' => (string)($params['ratio'] ?? $params['aspect_ratio'] ?? ''), 'duration' => (int)($params['duration'] ?? 0),
            'quantity' => max(1, min(4, (int)($params['count'] ?? $params['quantity'] ?? 1))),
            'reference_images' => array_values((array)($params['reference_images'] ?? [])),
            'reference_assets' => array_values((array)($params['reference_assets'] ?? [])),
            'source_app_code' => AigcShortDramaService::APP_CODE,
        ];
        if ($type === 'audio') $payload['lyrics'] = (string)($params['lyrics'] ?? '');
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
    private static function normalizeEdges(array $edges, array $nodes): array { $ids = array_flip(array_map(static fn($node) => (string)$node['id'], $nodes)); return array_values(array_filter($edges, static fn($edge) => is_array($edge) && isset($ids[(string)($edge['from'] ?? '')], $ids[(string)($edge['to'] ?? '')]) && (string)$edge['from'] !== (string)$edge['to'])); }
    private static function decode(string $json): array { $decoded = json_decode($json, true); return is_array($decoded) ? $decoded : []; }
    private static function normalizeStatus(string $status): string { return in_array($status, ['success', 'failed', 'canceled'], true) ? $status : 'running'; }
    private static function formatDocument(array $row, bool $includeRuns = false): array
    {
        $data = ['id' => (int)$row['id'], 'title' => (string)$row['title'], 'nodes' => self::decode((string)$row['nodes_json']), 'edges' => self::decode((string)$row['edges_json']), 'viewport' => self::decode((string)$row['viewport_json']), 'update_time' => (int)$row['update_time']];
        if (!$includeRuns) return $data;
        // A browser can be refreshed after the backend creates a run but before
        // its debounce save writes canvasRunId into nodes_json. Return the latest
        // run per node so that task ownership and recovery stay server-backed.
        $latest = [];
        foreach (Db::name(self::RUN_TABLE)->where(['tenant_id' => (int)$row['tenant_id'], 'user_id' => (int)$row['user_id'], 'canvas_id' => (int)$row['id'], 'delete_time' => 0])->order('id', 'desc')->select()->toArray() as $run) {
            $nodeId = (string)$run['node_id'];
            if ($nodeId !== '' && !isset($latest[$nodeId])) $latest[$nodeId] = self::formatRun($run);
        }
        $data['runs'] = array_values($latest);
        return $data;
    }
    private static function formatRun(array $row): array { $result = self::decode((string)$row['result_json']); return ['id' => (int)$row['id'], 'node_id' => (string)$row['node_id'], 'type' => (string)$row['node_type'], 'status' => (string)$row['status'], 'progress' => (int)$row['progress'], 'error' => (string)$row['error'], 'result' => $result, 'results' => (array)($result['results'] ?? $result['images'] ?? $result['videos'] ?? [])]; }
}
