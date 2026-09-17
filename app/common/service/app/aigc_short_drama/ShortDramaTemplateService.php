<?php

namespace app\common\service\app\aigc_short_drama;

use app\common\model\app\aigc_short_drama\AigcShortDramaTemplate;
use app\common\model\file\TenantFile;
use app\common\service\FileService;
use Exception;

/** Read-only homepage workflow-template catalogue. It never creates canvas content or tasks. */
final class ShortDramaTemplateService
{
    /** Existing homepage covers are bundled with the PC application, not tenant uploads. */
    private const BUNDLED_COVER_URLS = [
        '/imagine-home/character.jpeg',
        '/imagine-home/fashion.jpeg',
        '/imagine-home/snooker.jpeg',
        '/imagine-home/sunglasses.jpeg',
        '/imagine-home/social.jpeg',
    ];

    public static function adminLists(int $tenantId, array $params = []): array
    {
        $query = AigcShortDramaTemplate::where(['tenant_id' => $tenantId, 'delete_time' => 0]);
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') $query->where(static fn($q) => $q->whereLike('title', '%' . $keyword . '%')->whereOrLike('description', '%' . $keyword . '%')->whereOrLike('category', '%' . $keyword . '%'));
        if (($status = (string)($params['status'] ?? '')) !== '') $query->where('status', (int)$status);
        $pageNo = max(1, (int)($params['page_no'] ?? 1));
        $pageSize = min(100, max(1, (int)($params['page_size'] ?? 20)));
        $count = (int)(clone $query)->count();
        $rows = $query->order(['sort' => 'desc', 'id' => 'desc'])->limit(($pageNo - 1) * $pageSize, $pageSize)->select()->toArray();
        return ['lists' => array_map([self::class, 'format'], $rows), 'count' => $count, 'page_no' => $pageNo, 'page_size' => $pageSize];
    }

    public static function detail(int $tenantId, int $id, bool $publicOnly = false): array
    {
        $query = AigcShortDramaTemplate::where(['tenant_id' => $tenantId, 'id' => $id, 'delete_time' => 0]);
        if ($publicOnly) $query->where('status', 1);
        $row = $query->findOrEmpty();
        if ($row->isEmpty()) throw new Exception('模板不存在或已停用');
        return self::format($row->toArray());
    }

    public static function save(int $tenantId, array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $data = self::payload($tenantId, $params);
        $now = time();
        if ($id > 0) {
            $row = AigcShortDramaTemplate::where(['tenant_id' => $tenantId, 'id' => $id, 'delete_time' => 0])->findOrEmpty();
            if ($row->isEmpty()) throw new Exception('模板不存在');
            $row->save($data + ['update_time' => $now]);
            return self::format($row->toArray());
        }
        return self::format(AigcShortDramaTemplate::create($data + ['tenant_id' => $tenantId, 'create_time' => $now, 'update_time' => $now, 'delete_time' => 0])->toArray());
    }

    public static function status(int $tenantId, int $id, bool $enabled): void
    {
        $row = AigcShortDramaTemplate::where(['tenant_id' => $tenantId, 'id' => $id, 'delete_time' => 0])->findOrEmpty();
        if ($row->isEmpty()) throw new Exception('模板不存在');
        $row->save(['status' => $enabled ? 1 : 0, 'update_time' => time()]);
    }

    public static function delete(int $tenantId, int $id): void
    {
        $row = AigcShortDramaTemplate::where(['tenant_id' => $tenantId, 'id' => $id, 'delete_time' => 0])->findOrEmpty();
        if ($row->isEmpty()) throw new Exception('模板不存在');
        $row->save(['delete_time' => time(), 'update_time' => time()]);
    }

    public static function homepage(int $tenantId, array $params = []): array
    {
        $limit = min(20, max(1, (int)($params['limit'] ?? 5)));
        $rows = AigcShortDramaTemplate::where(['tenant_id' => $tenantId, 'status' => 1, 'show_on_home' => 1, 'delete_time' => 0])
            ->order(['sort' => 'desc', 'id' => 'desc'])->limit($limit)->select()->toArray();
        return ['lists' => array_map([self::class, 'format'], $rows), 'count' => count($rows)];
    }

    private static function payload(int $tenantId, array $params): array
    {
        $title = mb_substr(trim((string)($params['title'] ?? '')), 0, 120, 'UTF-8');
        if ($title === '') throw new Exception('请输入模板名称');
        $coverUrl = mb_substr(trim((string)($params['cover_url'] ?? '')), 0, 500, 'UTF-8');
        $coverAssetId = max(0, (int)($params['cover_asset_id'] ?? 0));
        if ($coverUrl === '' && $coverAssetId <= 0) throw new Exception('请选择模板封面');
        if (in_array($coverUrl, self::BUNDLED_COVER_URLS, true)) {
            // These five legacy homepage covers are a controlled, read-only
            // static asset set. All other covers must remain tenant-owned.
            $coverAssetId = 0;
        } else {
            $files = TenantFile::where('tenant_id', $tenantId);
            if ($coverUrl !== '') {
                $uri = ltrim((string)(parse_url($coverUrl, PHP_URL_PATH) ?: $coverUrl), '/');
                $files->whereIn('uri', array_values(array_unique([$coverUrl, $uri, '/' . $uri])));
            } else $files->where('id', $coverAssetId);
            $file = $files->find();
            if (!$file) throw new Exception('请从当前租户素材库选择模板封面');
            $coverAssetId = (int)$file['id'];
            $coverUrl = FileService::getFileUrlByStorage((string)$file['uri'], (string)$file['storage_scope'], (string)$file['storage_engine'], (string)$file['storage_domain']);
        }
        [$nodes, $edges] = self::workflow($params['workflow_nodes'] ?? [], $params['workflow_edges'] ?? []);
        $slots = self::slots($params['input_slots'] ?? [], $nodes);
        return [
            'title' => $title,
            'description' => mb_substr(trim((string)($params['description'] ?? '')), 0, 1200, 'UTF-8'),
            'category' => mb_substr(trim((string)($params['category'] ?? '')), 0, 60, 'UTF-8'),
            'cover_asset_id' => $coverAssetId,
            'cover_url' => $coverUrl,
            'cover_type' => in_array((string)($params['cover_type'] ?? ''), ['image', 'video'], true) ? (string)$params['cover_type'] : 'image',
            'workflow_nodes_json' => json_encode($nodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'workflow_edges_json' => json_encode($edges, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'input_slots_json' => json_encode($slots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'show_on_home' => empty($params['show_on_home']) ? 0 : 1,
            'sort' => max(0, min(9999, (int)($params['sort'] ?? 0))),
            'status' => empty($params['status']) ? 0 : 1,
        ];
    }

    /** @return array{0: array<int,array<string,string>>, 1: array<int,array<string,string>>} */
    private static function workflow(mixed $rawNodes, mixed $rawEdges): array
    {
        $allowedTypes = ['input', 'text', 'image', 'video', 'output'];
        $nodes = []; $keys = [];
        foreach ((array)$rawNodes as $index => $node) {
            $key = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim((string)($node['key'] ?? '')))) ?: '';
            $title = mb_substr(trim((string)($node['title'] ?? '')), 0, 80, 'UTF-8');
            if ($key === '' && $title === '') continue;
            if ($key === '' || !preg_match('/^[a-z][a-z0-9_]{0,59}$/', $key)) throw new Exception('工作流节点标识需以小写字母开头，只能包含字母、数字和下划线');
            if ($title === '') throw new Exception('请填写工作流节点名称');
            if (isset($keys[$key])) throw new Exception('工作流节点标识不能重复');
            $keys[$key] = true;
            $type = (string)($node['type'] ?? 'text');
            $nodes[] = ['key' => $key, 'title' => $title, 'type' => in_array($type, $allowedTypes, true) ? $type : 'text', 'model' => mb_substr(trim((string)($node['model'] ?? '')), 0, 120, 'UTF-8'), 'description' => mb_substr(trim((string)($node['description'] ?? '')), 0, 300, 'UTF-8'), 'sort' => (string)$index];
        }
        $edges = []; $seen = [];
        foreach ((array)$rawEdges as $edge) {
            $from = (string)($edge['from'] ?? ''); $to = (string)($edge['to'] ?? '');
            if ($from === '' && $to === '') continue;
            if (!isset($keys[$from], $keys[$to]) || $from === $to) throw new Exception('节点连接必须引用两个不同的已配置节点');
            if (isset($seen[$from . '>' . $to])) continue;
            $seen[$from . '>' . $to] = true; $edges[] = ['from' => $from, 'to' => $to];
        }
        return [$nodes, $edges];
    }

    /** Slots describe future replaceable user inputs only; this service does not execute them. */
    private static function slots(mixed $rawSlots, array $nodes): array
    {
        $nodeKeys = array_flip(array_column($nodes, 'key')); $result = []; $seen = [];
        foreach ((array)$rawSlots as $slot) {
            $key = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim((string)($slot['key'] ?? '')))) ?: '';
            $label = mb_substr(trim((string)($slot['label'] ?? '')), 0, 80, 'UTF-8');
            if ($key === '' && $label === '') continue;
            if ($key === '' || !preg_match('/^[a-z][a-z0-9_]{0,59}$/', $key)) throw new Exception('素材槽位标识需以小写字母开头，只能包含字母、数字和下划线');
            if ($label === '') throw new Exception('请填写素材槽位名称');
            if (isset($seen[$key])) throw new Exception('素材槽位标识不能重复'); $seen[$key] = true;
            $type = (string)($slot['type'] ?? 'image');
            $nodeKey = (string)($slot['node_key'] ?? '');
            if ($nodeKey !== '' && !isset($nodeKeys[$nodeKey])) throw new Exception('素材槽位关联的工作流节点不存在');
            $result[] = ['key' => $key, 'label' => $label, 'type' => in_array($type, ['image', 'video', 'text', 'audio'], true) ? $type : 'image', 'required' => empty($slot['required']) ? 0 : 1, 'node_key' => $nodeKey, 'description' => mb_substr(trim((string)($slot['description'] ?? '')), 0, 300, 'UTF-8')];
        }
        return $result;
    }

    private static function decode(mixed $value): array { if (is_array($value)) return $value; $value = json_decode((string)$value, true); return is_array($value) ? $value : []; }
    private static function format(array $row): array
    {
        return ['id' => (int)$row['id'], 'title' => (string)$row['title'], 'description' => (string)$row['description'], 'category' => (string)$row['category'],
            'cover_asset_id' => (int)$row['cover_asset_id'], 'cover_url' => (string)$row['cover_url'], 'cover_type' => (string)$row['cover_type'],
            'workflow_nodes' => self::decode($row['workflow_nodes_json'] ?? []), 'workflow_edges' => self::decode($row['workflow_edges_json'] ?? []), 'input_slots' => self::decode($row['input_slots_json'] ?? []),
            'show_on_home' => (int)$row['show_on_home'], 'sort' => (int)$row['sort'], 'status' => (int)$row['status'],
            'create_time' => (int)$row['create_time'], 'update_time' => (int)$row['update_time']];
    }
}
