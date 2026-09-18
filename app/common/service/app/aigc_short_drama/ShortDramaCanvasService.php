<?php

namespace app\common\service\app\aigc_short_drama;

use app\common\service\app\aigc_image\AigcImageService;
use app\common\service\app\aigc_llm\AigcLlmService;
use app\common\service\app\aigc_music\AigcMusicService;
use app\common\service\app\aigc_video\AigcVideoService;
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

    public static function current(int $tenantId, int $userId): array
    {
        $row = Db::name(self::DOCUMENT_TABLE)->where([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
        ])->order('id', 'desc')->find();
        if (!$row) {
            $now = time();
            $id = Db::name(self::DOCUMENT_TABLE)->insertGetId([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'title' => '无标题空间',
                'nodes_json' => '[]', 'edges_json' => '[]', 'viewport_json' => '{}',
                'create_time' => $now, 'update_time' => $now, 'delete_time' => 0,
            ]);
            $row = Db::name(self::DOCUMENT_TABLE)->where('id', $id)->find();
        }
        return self::formatDocument($row);
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
        } catch (\Throwable $e) {
            Db::name(self::RUN_TABLE)->where('id', $runId)->update([
                'status' => 'failed', 'progress' => 0, 'error' => mb_substr($e->getMessage(), 0, 500), 'update_time' => time(),
            ]);
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
        if (in_array((string)$run['status'], ['success', 'failed', 'canceled'], true)) return;
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
        $urls = array_values(array_filter(array_map(static fn(array $item): string => (string)($item[$column] ?? ''), $results)));
        $payload = json_decode((string)$run['result_json'], true) ?: [];
        if ($urls) $payload['results'] = array_map(static fn(string $url): array => ['url' => $url], $urls);
        Db::name(self::RUN_TABLE)->where('id', $run['id'])->update([
            'status' => $status, 'progress' => $status === 'success' ? 100 : max(25, (int)($task['progress'] ?? 25)),
            'error' => (string)($task['error'] ?? $task['error_msg'] ?? ''),
            'result_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'update_time' => time(),
        ]);
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
    private static function currentById(int $tenantId, int $userId, int $id): array { return self::formatDocument(self::ownedDocument($tenantId, $userId, $id)); }
    private static function normalizeNodes(array $nodes): array { return array_values(array_slice(array_filter($nodes, static fn($node) => is_array($node) && isset($node['id']) && isset($node['type'])), 0, 200)); }
    private static function normalizeEdges(array $edges, array $nodes): array { $ids = array_flip(array_map(static fn($node) => (string)$node['id'], $nodes)); return array_values(array_filter($edges, static fn($edge) => is_array($edge) && isset($ids[(string)($edge['from'] ?? '')], $ids[(string)($edge['to'] ?? '')]) && (string)$edge['from'] !== (string)$edge['to'])); }
    private static function decode(string $json): array { $decoded = json_decode($json, true); return is_array($decoded) ? $decoded : []; }
    private static function normalizeStatus(string $status): string { return in_array($status, ['success', 'failed', 'canceled'], true) ? $status : 'running'; }
    private static function formatDocument(array $row): array { return ['id' => (int)$row['id'], 'title' => (string)$row['title'], 'nodes' => self::decode((string)$row['nodes_json']), 'edges' => self::decode((string)$row['edges_json']), 'viewport' => self::decode((string)$row['viewport_json']), 'update_time' => (int)$row['update_time']]; }
    private static function formatRun(array $row): array { $result = self::decode((string)$row['result_json']); return ['id' => (int)$row['id'], 'node_id' => (string)$row['node_id'], 'type' => (string)$row['node_type'], 'status' => (string)$row['status'], 'progress' => (int)$row['progress'], 'error' => (string)$row['error'], 'result' => $result, 'results' => (array)($result['results'] ?? $result['images'] ?? $result['videos'] ?? [])]; }
}
