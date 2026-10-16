<?php
namespace app\common\service\app\aigc_canvas;

use app\common\model\app\aigc_canvas\AigcCanvasProject;
use think\facade\Db;

/** One-way data adaptation; no generation, provider calls or asset deletion. */
final class CanvasProjectCompatibility
{
    public static function upgrade(array $project): array
    {
        $preview=self::adapt($project);
        $changed=false;
        foreach (['nodes_json','edges_json','viewport_json'] as $key) if (self::json($project[$key] ?? [])!==$preview[$key]) {$changed=true;break;}
        if (!$changed) return $project;
        return Db::transaction(static function () use ($project): array {
            $row = AigcCanvasProject::where(['id'=>$project['id'], 'tenant_id'=>$project['tenant_id'], 'user_id'=>$project['user_id'], 'delete_time'=>0])->lock(true)->findOrEmpty();
            if ($row->isEmpty()) throw new \InvalidArgumentException('项目不存在');
            $original = $row->toArray();
            $adapted = self::adapt($original);
            $changes = [];
            foreach (['nodes_json','edges_json','viewport_json'] as $key) {
                if (self::json($original[$key] ?? []) !== $adapted[$key]) $changes[$key] = $adapted[$key];
            }
            if ($changes) {
                $owner=['tenant_id'=>$original['tenant_id'],'user_id'=>$original['user_id'],'project_id'=>$original['id']];
                if (!Db::name('aigc_canvas_project_compat_backup')->where($owner)->find()) {
                    Db::name('aigc_canvas_project_compat_backup')->insert($owner+['snapshot_json'=>json_encode($original,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'create_time'=>time()]);
                }
                $viewport = $adapted['viewport_json'];
                $viewport['agentRevision'] = (int)($viewport['agentRevision'] ?? 0) + 1;
                $changes['viewport_json'] = $viewport;
                // Keep project identity, timestamps, storage and original node metadata.
                $row->isAutoWriteTimestamp(false)->save($changes);
                return $row->toArray();
            }
            return $original;
        });
    }

    public static function adapt(array $project): array
    {
        $nodes = self::json($project['nodes_json'] ?? []);
        foreach ($nodes as $i => &$node) {
            if (!is_array($node)) throw new \InvalidArgumentException('历史节点数据无效，原数据已保留');
            $data = (array)($node['data'] ?? $node['metadata'] ?? []);
            $native = isset($node['position'], $node['metadata']) && in_array($node['type'] ?? '', ['text','image','video','audio','config'], true);
            if ($native) {
                foreach (['url','image','video_url','audio_url','poster'] as $key) if (empty($node['metadata'][$key]) && !empty($node[$key])) $node['metadata'][$key]=$node[$key];
                continue;
            }
            $kind = strtolower((string)($data['variant'] ?? $node['type'] ?? 'text'));
            $mime = strtolower((string)($data['mimeType'] ?? $data['mime_type'] ?? ''));
            if (str_contains($kind,'audio') || str_contains($kind,'music') || str_starts_with($mime,'audio/')) $type='audio';
            elseif (str_contains($kind,'image') || $kind==='subject' || str_starts_with($mime,'image/')) $type='image';
            elseif (str_contains($kind,'video') || str_starts_with($mime,'video/')) $type='video';
            elseif ($kind==='llmconfig' || str_contains($kind,'text') || in_array($kind,['script','storyboardgroup'],true)) $type='text';
            elseif (!empty($data['audio_url']) || !empty($data['audio_uri'])) $type='audio';
            elseif (!empty($data['image']) || !empty($data['threeViewImage']) || !empty($data['image_url'])) $type='image';
            elseif (!empty($data['video_url']) || !empty($data['url'])) $type='video';
            else $type=$kind==='config'?'config':'text';
            $metadata = array_replace($data, [
                'content'=>$data['content'] ?? $data['outputContent'] ?? '', 'prompt'=>$data['prompt'] ?? $data['content'] ?? '',
                'image'=>$data['image'] ?? $data['threeViewImage'] ?? '',
                'url'=>$data['url'] ?? $data['audio_url'] ?? $data['audio_uri'] ?? $data['video_url'] ?? $data['image_url'] ?? '',
                'status'=>$data['status'] ?? $data['agentStatus'] ?? 'idle',
            ]);
            $position = (array)($node['position'] ?? $data['position'] ?? []);
            $node = array_replace($node, ['id'=>(string)($node['id'] ?? 'legacy_node_'.$i), 'type'=>$type,
                'title'=>(string)($node['title'] ?? $data['title'] ?? $data['label'] ?? ['text'=>'文本','image'=>'图片','video'=>'视频','audio'=>'音乐','config'=>'生成配置'][$type]),
                'position'=>['x'=>(float)($position['x'] ?? 0), 'y'=>(float)($position['y'] ?? 0)],
                'width'=>(float)($node['width'] ?? $data['width'] ?? 340),
                'height'=>(float)($node['height'] ?? $data['height'] ?? ($type==='audio'?170:($type==='config'?146:217))), 'metadata'=>$metadata]);
        }
        unset($node);
        $edges = self::json($project['edges_json'] ?? []);
        foreach ($edges as $i => &$edge) {
            if (!is_array($edge)) throw new \InvalidArgumentException('历史连线数据无效，原数据已保留');
            $edge['id'] ??= 'legacy_edge_'.$i;
            $edge['fromNodeId'] ??= $edge['source'] ?? '';
            $edge['toNodeId'] ??= $edge['target'] ?? '';
        }
        unset($edge);
        $viewport = self::json($project['viewport_json'] ?? []);
        if (empty($viewport['pages'])) {
            $viewport['pages'] = [['id'=>'canvas_1','name'=>'页面 1','nodeIds'=>array_column($nodes,'id'),
                'viewport'=>['x'=>(float)($viewport['x'] ?? 100),'y'=>(float)($viewport['y'] ?? 50),'k'=>(float)($viewport['zoom'] ?? $viewport['k'] ?? .82)]]];
            $viewport['currentCanvasId']='canvas_1';
        }
        // Existing page identity is authoritative. Keep unindexed historical nodes on the active page.
        $pageIds=array_column($viewport['pages'],'id');
        $active=array_search($viewport['currentCanvasId'] ?? '',$pageIds,true);
        if ($active===false) $active=0;
        $claimed=[];
        foreach ($viewport['pages'] as $page) $claimed=array_merge($claimed,(array)($page['nodeIds'] ?? []));
        $orphan=array_values(array_diff(array_column($nodes,'id'),$claimed));
        if ($orphan) $viewport['pages'][$active]['nodeIds']=array_merge((array)($viewport['pages'][$active]['nodeIds'] ?? []),$orphan);
        return array_replace($project,['nodes_json'=>$nodes,'edges_json'=>$edges,'viewport_json'=>$viewport]);
    }

    private static function json($value): array
    {
        if (is_array($value)) return $value;
        if ($value===null || $value==='') return [];
        $decoded=json_decode((string)$value,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new \InvalidArgumentException('历史项目数据无效，原数据已保留');
        return $decoded;
    }
}
