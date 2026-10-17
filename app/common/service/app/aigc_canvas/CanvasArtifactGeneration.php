<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_canvas;

use app\common\service\app\aigc_llm\agent\{AgentArtifactService,AgentStore,AgentWorkspaceScope};

/** Public submitted-task fields only. Never copy model/provider snapshots into a graph. */
final class CanvasArtifactGeneration
{
    public static function metadata(array $artifact): ?array
    {
        $generation = AgentArtifactService::canvasGeneration($artifact);
        if (!$generation) return null;
        return self::fromGeneration((string)$artifact['kind'], $generation);
    }

    public static function fromGeneration(string $kind, array $generation): array
    {
        $settings = array_intersect_key($generation, array_flip(['channel','model_id','quality','ratio','duration','quantity','market_product_id','market_sku_id','mode','negative_prompt','style']));
        if ($kind === 'audio') $settings += array_intersect_key($generation, array_flip(['title','lyrics','genre','custom','instrumental','vocal_gender','language']));
        $settings['submittedModelLabel'] = (string)($generation['model_name'] ?? '');
        foreach (['market_product_id','market_sku_id'] as $key) if (empty($settings[$key])) unset($settings[$key]);
        if (isset($settings['market_sku_id'])) $settings['sku_id'] = (int)$settings['market_sku_id'];
        if (empty($settings['mode'])) unset($settings['mode']);
        foreach (['duration','quantity'] as $key) if (isset($settings[$key])) $settings[$key] = (int)$settings[$key];
        $settings['count'] = (int)($settings['quantity'] ?? 1);
        $settings['resolution'] = $kind === 'video' ? strtoupper((string)($settings['quality'] ?? '')) : (string)($settings['quality'] ?? '');
        $references = ['referenceImages'=>[], 'referenceVideos'=>[], 'referenceAudios'=>[]];
        foreach ($generation['references'] ?? [] as $item) {
            $key = ['image'=>'referenceImages','video'=>'referenceVideos','audio'=>'referenceAudios'][$item['type'] ?? ''] ?? '';
            if ($key === '' || empty($item['url'])) continue;
            $references[$key][] = array_intersect_key($item, array_flip(['url','name','role','generation_method','duration','start','end']));
        }
        if ($kind === 'video' && !empty($generation['generation_method'])) {
            $settings['generation_method'] = $generation['generation_method'];
            $settings['generation_method_source'] = 'manual';
        }
        $prompt = (string)($generation['prompt'] ?? '');
        return $settings + $references + [
            'prompt'=>$prompt, 'submittedPrompt'=>$prompt, 'compiledPrompt'=>'',
            // Missing legacy method is inferred from the restored inputs by the
            // current model catalogue, never from a stale text-to-video default.
            'generation_method'=>'', 'generationMethod'=>'', 'generation_method_source'=>'auto',
            'agentGeneration'=>['version'=>1, 'task_id'=>(int)$generation['task_id'], 'tool_code'=>$generation['tool_code'], 'settings'=>$settings, 'options'=>$generation['options'] ?? []],
        ];
    }

    /** Only incomplete old result nodes in the original bound page may be upgraded. */
    public static function repair(array $project, array $nodes, array $pages): array
    {
        foreach ($nodes as &$node) {
            $meta = $node['metadata'] ?? [];
            if (!in_array($node['type'] ?? '', ['image','video','audio'], true) || ($meta['source'] ?? '') !== 'agent' || empty($meta['agentArtifactId']) || !empty($meta['agentGeneration'])) continue;
            $prompt = trim((string)($meta['prompt'] ?? ''));
            if ($prompt !== '' && !in_array($prompt, [$meta['url'] ?? '', $meta['image'] ?? '', $meta['content'] ?? ''], true)) continue;
            if (!empty($meta['submittedPrompt']) || !empty($meta['compiledPrompt'])) continue;
            $tenant = (int)($project['tenant_id'] ?? 0); $user = (int)($project['user_id'] ?? 0);
            if ($tenant < 1 || $user < 1) continue;
            $artifact = AgentStore::owner('artifact', $tenant, $user)->where('id', (int)$meta['agentArtifactId'])->find();
            if (!$artifact || $artifact['kind'] !== $node['type']) continue;
            $data = AgentStore::json($artifact['data']);
            if (($data['file_id'] ?? '') !== ($meta['agentFileId'] ?? '') || $node['id'] !== ($data['canvas_node_id'] ?? 'agent_artifact_'.$artifact['id'])) continue;
            $run = AgentStore::owner('run', $tenant, $user)->where(['id'=>$artifact['run_id'], 'session_id'=>$artifact['session_id']])->find();
            $scope = $run ? AgentWorkspaceScope::fromRun($run) : [];
            if (($scope['app'] ?? '') !== 'aigc_canvas' || (int)($scope['project_id'] ?? 0) !== (int)$project['id']) continue;
            $page = array_values(array_filter($pages, static fn($page) => ($page['id'] ?? '') === ($scope['page_id'] ?? '')))[0] ?? [];
            if (!in_array($node['id'], $page['nodeIds'] ?? [], true)) continue;
            $patch = self::metadata($artifact);
            if ($patch) $node['metadata'] = array_replace($meta, $patch);
        }
        unset($node);
        return $nodes;
    }
}
