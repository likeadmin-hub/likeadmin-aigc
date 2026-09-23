<?php

namespace app\common\service\app\aigc_short_drama;

use think\facade\Db;

/** Read-only source discovery for an explicitly confirmed formal writeback. */
final class ShortDramaCanvasWritebackService
{
    private const TEXT_ARTIFACTS = ['story_setting', 'episode_script', 'episode_outline', 'storyboard_script'];

    public static function sources(int $tenantId, int $userId, int $canvasId): array
    {
        // The binding lookup validates ownership even when no formal target is selected.
        $binding = ShortDramaCanvasBindingService::current($tenantId, $userId, $canvasId);
        $document = Db::name('aigc_short_drama_canvas')->where([
            'id' => $canvasId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'delete_time' => 0,
        ])->field('nodes_json,removed_node_ids_json,graph_revision')->find();
        $nodes = json_decode((string)($document['nodes_json'] ?? '[]'), true);
        $removed = json_decode((string)($document['removed_node_ids_json'] ?? '[]'), true);
        $removed = is_array($removed) ? array_fill_keys(array_map('strval', $removed), true) : [];
        $sources = [];
        $seen = [];
        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (!is_array($node) || (string)($node['type'] ?? '') !== 'text') continue;
            $id = (string)($node['id'] ?? '');
            if ($id === '' || isset($removed[$id]) || isset($seen[$id])) continue;
            $seen[$id] = true;
            $meta = (array)($node['metadata'] ?? []);
            if ((string)($meta['workflow_source_stage'] ?? '') !== 'script') continue;
            $artifact = (string)($meta['workflow_artifact'] ?? '');
            if (!in_array($artifact, self::TEXT_ARTIFACTS, true)) continue;
            $content = (string)($meta['content'] ?? '');
            if (trim($content) === '') continue;
            $sources[] = [
                'node_id' => $id,
                'artifact' => $artifact,
                'title' => (string)($node['title'] ?? ''),
                'content_revision' => (int)($meta['content_revision'] ?? 0),
                'content_hash' => hash('sha256', $content),
            ];
        }
        return ['binding' => $binding, 'graph_revision' => (int)($document['graph_revision'] ?? 0), 'sources' => $sources];
    }
}
