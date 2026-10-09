<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use app\common\service\app\aigc_short_drama\canvas_agent\ConversationSeries;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationSeriesVisuals;
use RuntimeException;

/** Readable canvas artifacts retain the same accepted content and production identities. */
final class CanvasProductionProjection
{
    public static function proposals(array $candidate, int $project, string $task, string $stage, int $episode, string $style): array
    {
        if (!in_array($stage, ['art', 'assets', 'storyboard'], true)) throw new RuntimeException('规范剧本的声音和视频制作尚未开放', 409);
        $plan = ApplicationWorkflow::project($candidate);
        $targets = ImageHandoff::targets($candidate, $style); $byId = array_column($targets, null, 'target_id');
        $proposals = ConversationSeriesVisuals::proposals($plan, $stage, $episode,
            static function (string $artifact, array $item) use ($candidate, $style): string {
                $id = (string)($item['id'] ?? $item['shot_id'] ?? '');
                return self::prompt($candidate, $style, $artifact, $id);
            });
        $keys = [];
        foreach ($targets as $target) {
            $id = $target['target_id'];
            if ($target['task_type'] === 'shot_image') $keys['ep'.$episode.'_shot'.$target['shot']['order']] = $id;
            elseif ($target['task_type'] === 'scene_image') $keys[ConversationSeriesVisuals::key('scene', $id)] = $id;
            else {
                $keys[ConversationSeriesVisuals::key('subject', $id)] = $id;
                $keys[ConversationSeriesVisuals::key('view', $id)] = $id;
            }
        }
        foreach ($proposals as &$proposal) {
            $artifact = $proposal['artifact']; $id = $artifact === 'art_bible' ? '' : ($keys[$proposal['key']] ?? '');
            if ($artifact !== 'art_bible' && !isset($byId[$id])) throw new RuntimeException('画布制作对象缺失', 409);
            if ($artifact === 'art_bible') $proposal['prompt'] = self::prompt($candidate, $style, $artifact, '');
            $proposal['canonical_binding'] = self::binding($candidate, $project, $task, $artifact, $id, $style);
        }
        return $proposals;
    }

    public static function prompt(array $candidate, string $style, string $artifact, string $id): string
    {
        if ($artifact === 'art_bible') return json_encode(['style' => $style, 'entities' => $candidate['document']['entities']], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $type = match ($artifact) {'subject', 'three_view' => 'subject_image', 'scene' => 'scene_image', 'storyboard' => 'shot_image', default => throw new RuntimeException('画布制作类型无效', 422)};
        $bindings = [];
        foreach ($candidate['document']['entities'] as $entity) $bindings[$entity['entity_id']] = ['entity_id' => $entity['entity_id']];
        $input = ImageHandoff::compile($candidate, $style, $type, $id, $candidate['document']['revision_id'], $bindings);
        return $input['prompt'] . ($artifact === 'three_view' ? "\n按主体参考图绘制正面、侧面和背面三视图，保持同一身份和服装。" : '');
    }

    public static function binding(array $candidate, int $project, string $task, string $artifact, string $id, string $style): array
    {
        $document = $candidate['document']; $shots = $candidate['shot_plan'];
        $target = null;
        foreach (ImageHandoff::targets($candidate, $style) as $item) if ($item['target_id'] === $id) $target = $item;
        $binding = ['version' => 1, 'project_id' => $project, 'task_id' => $task,
            'document_revision_id' => $document['revision_id'], 'document_hash' => $document['content_hash'],
            'shot_revision_id' => $shots['revision_id'], 'shot_hash' => $shots['content_hash'],
            'artifact' => $artifact, 'target_id' => $id, 'state_hash' => $target['state_hash'] ?? ContentDocument::hash($document['entities']),
            'style' => $style, 'prompt_hash' => hash('sha256', self::prompt($candidate, $style, $artifact, $id))];
        $binding['hash'] = ContentDocument::hash($binding);
        return $binding;
    }

    /** Scope is always taken from the saved server graph or confirmed workflow. */
    public static function assertBinding(int $tenant, int $user, array $binding, ?array $candidate = null): array
    {
        $hash = $binding['hash'] ?? ''; $body = $binding; unset($body['hash']);
        if (!is_string($hash) || !hash_equals(ContentDocument::hash($body), $hash) || ($binding['version'] ?? 0) !== 1
            || !is_int($binding['project_id'] ?? null) || !is_string($binding['task_id'] ?? null)
            || !is_string($binding['artifact'] ?? null) || !is_string($binding['target_id'] ?? null) || !is_string($binding['style'] ?? null)) throw new RuntimeException('画布制作来源校验失败', 409);
        $candidate = $candidate ?? AudioProduction::context($tenant, $user, $binding['project_id'], $binding['task_id']);
        $expected = self::binding($candidate, $binding['project_id'], $binding['task_id'], $binding['artifact'], $binding['target_id'], $binding['style']);
        if ($binding !== $expected) throw new RuntimeException('剧本版本已变化，请重新准备当前版本的制作节点', 409);
        return $candidate;
    }

    public static function assertProposals(int $tenant, int $user, array $workflow, array $proposals): void
    {
        $source = $workflow['series']['tasks']['episode_'.ConversationSeries::episode($workflow)] ?? [];
        $candidate = null;
        foreach ($proposals as $proposal) {
            if (!array_key_exists('canonical_binding', $proposal)) continue;
            $binding = $proposal['canonical_binding'];
            if (!is_array($binding) || ($source['project_id'] ?? 0) !== ($binding['project_id'] ?? -1)
                || ($source['task_id'] ?? '') !== ($binding['task_id'] ?? null) || ($proposal['artifact'] ?? '') !== ($binding['artifact'] ?? null)
                || (string)($workflow['creative_settings']['style_name'] ?? '') !== ($binding['style'] ?? null)) throw new RuntimeException('画布制作来源不属于当前工作流', 409);
            $candidate = self::assertBinding($tenant, $user, $binding, $candidate);
            if (hash('sha256', $proposal['prompt']) !== $binding['prompt_hash']) throw new RuntimeException('画布制作文字与已保存剧本不符', 409);
        }
    }

    public static function assertNode(int $tenant, int $user, array $node): ?array
    {
        $metadata = (array)($node['metadata'] ?? []);
        if (!array_key_exists('canonical_binding', $metadata)) return null;
        if (!is_array($metadata['canonical_binding'])) throw new RuntimeException('画布制作来源无效', 409);
        $binding = $metadata['canonical_binding']; $candidate = self::assertBinding($tenant, $user, $binding);
        if (($metadata['workflow_artifact'] ?? '') !== $binding['artifact'] || hash('sha256', (string)($metadata['prompt'] ?? '')) !== $binding['prompt_hash']) throw new RuntimeException('制作节点已修改，请从剧本修订重新准备节点', 409);
        $expectedType = $binding['artifact'] === 'art_bible' ? 'text' : 'image';
        if (($node['type'] ?? '') !== $expectedType) throw new RuntimeException('制作节点类型与剧本不符', 409);
        return $candidate;
    }

    /** Re-check the persisted intent, including source edges, before any upstream submission. */
    public static function assertInput(int $tenant, int $user, array $document, array $node, array $input): void
    {
        if (self::assertNode($tenant, $user, $node) === null) {
            if (isset($input['_canonical_canvas'])) throw new RuntimeException('普通节点不能冒用规范制作来源', 409);
            return;
        }
        $trace = $input['_canonical_canvas'] ?? [];
        if (($trace['binding'] ?? null) !== $node['metadata']['canonical_binding'] || !is_array($trace['image_input'] ?? null)) throw new RuntimeException('制作请求缺少当前剧本来源', 409);
        ImageHandoff::assertIntact($trace['image_input']);
        if (($input['prompt'] ?? '') !== $trace['image_input']['prompt']) throw new RuntimeException('制作请求文字与来源不符', 409);
        $required = $trace['image_input']['trace']['canvas_reference_bindings'] ?? [];
        $nodes = array_column(json_decode($document['nodes_json'], true) ?: [], null, 'id'); $connected = [];
        foreach (json_decode($document['edges_json'], true) ?: [] as $edge) if ((string)($edge['to'] ?? '') === (string)$node['id'] && ($edge['kind'] ?? 'reference') === 'reference') {
            $meta = $nodes[$edge['from'] ?? '']['metadata'] ?? []; $binding = $meta['canonical_binding'] ?? [];
            if (($meta['status'] ?? '') === 'success' && in_array($binding['artifact'] ?? '', ['subject', 'scene'], true)) $connected[] = [
                'asset_id' => (int)($meta['asset_id'] ?? 0), 'entity_id' => $binding['target_id'] ?? '', 'state_hash' => $binding['state_hash'] ?? ''];
        }
        foreach ($required as $reference) if (!in_array($reference, $connected, true)) throw new RuntimeException('制作参考素材已变化，请重新确认', 409);
        $ids = array_map(static fn(array $row): int => (int)$row['asset_id'], $required);
        $submitted = array_map(static fn(array $row): int => (int)$row['asset_id'], $input['reference_assets'] ?? []);
        sort($ids); sort($submitted);
        if ($ids !== $submitted || !empty($input['skill_snapshot']) || isset($input['operation'])) throw new RuntimeException('规范制作输入已被替换', 409);
    }

    /** One owned context read per episode, regardless of how many canvas targets are displayed. */
    public static function displayStates(int $tenant, int $user, array $nodes): array
    {
        $contexts = [];
        foreach ($nodes as &$node) {
            $meta = $node['metadata'] ?? []; $binding = $meta['canonical_binding'] ?? null;
            if (!is_array($binding)) continue;
            $key = (string)($binding['project_id'] ?? '') . ':' . (string)($binding['task_id'] ?? '');
            if (!array_key_exists($key, $contexts)) {
                try { $contexts[$key] = AudioProduction::context($tenant, $user, (int)($binding['project_id'] ?? 0), (string)($binding['task_id'] ?? '')); }
                catch (RuntimeException $error) { $contexts[$key] = null; }
            }
            $status = 'stale';
            if ($contexts[$key] !== null) {
                try {
                    self::assertBinding($tenant, $user, $binding, $contexts[$key]);
                    $status = hash('sha256', (string)($meta['prompt'] ?? '')) === $binding['prompt_hash'] ? 'current' : 'changed';
                } catch (RuntimeException $error) { $status = 'unavailable'; }
            }
            $node['metadata']['canonical_state'] = ['status' => $status, 'revision_id' => $binding['document_revision_id'] ?? ''];
        }
        unset($node); return $nodes;
    }
}
