<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Server-owned enrollment and lossless projections; persisted tasks never follow today's switch. */
final class ApplicationWorkflow
{
    public static function current(array $request): bool
    {
        $version = $request['_pipeline_version'] ?? 0;
        if (!in_array($version, [0, '0', GenerationPipeline::VERSION, (string)GenerationPipeline::VERSION], true)) throw new RuntimeException('当前服务不支持此任务版本，请恢复对应版本的服务', 409);
        return (int)$version === GenerationPipeline::VERSION;
    }

    public static function enrollment(int $tenant, array $request, array $model, array $policy, bool $newProject): ?array
    {
        // Existing projects, uploads and series stay on their recorded engine until their adapters pass acceptance.
        if (!$newProject || empty($policy['enabled']) || !in_array($tenant, (array)($policy['tenant_ids'] ?? []), true)
            || !empty($request['multi_episode']) || (int)($request['episode_count'] ?? 1) > 1
            || !empty($request['episode_id']) || ($request['script_source'] ?? '') === 'upload'
            || ($request['source'] ?? '') === 'revision') return null;
        $key = (string)($model['id'] ?? '');
        $execution = $policy['models'][$key] ?? null;
        if (!is_array($execution)) return null;
        $timeout = (int)($execution['stage_timeout_seconds'] ?? 0);
        $lease = (int)($execution['lease_seconds'] ?? 0);
        $deadline = (int)($execution['deadline_seconds'] ?? 0);
        if ($timeout < 10 || $lease <= $timeout || $deadline <= $lease) throw new RuntimeException('新生成流程的模型执行策略尚未配置完整', 422);
        if (array_key_exists('resource_limits', $execution)) {
            if (!is_array($execution['resource_limits'])) throw new RuntimeException('任务资源预算配置无效', 422);
            ResourceBudget::create($execution['resource_limits']);
        }
        return $execution;
    }

    public static function input(int $tenant, int $user, int $project, string $task, array $request, array $model, array $execution): array
    {
        $prompt = ContentDocument::text($request['prompt'] ?? null, 'prompt');
        $scope = $tenant . ':' . $user . ':' . $project;
        $source = SourceTrace::capture('source_' . $task, '1', $prompt, 'inspiration');
        $entities = [];
        foreach (ContentDocument::rows($request['locked_subject_references'] ?? [], 'locked_subject_references') as $index => $subject) {
            $kind = ($subject['category'] ?? '') === 'prop' ? 'prop' : 'character';
            $facts = array_filter(array_intersect_key($subject, array_flip(['gender', 'age_stage'])), static fn($value) => $value !== '' && $value !== 'unknown');
            $entities[] = ['ref' => ($kind === 'prop' ? 'p' : 'c') . ($index + 1), 'entity_id' => 'library:' . $subject['id'], 'kind' => $kind,
                'name' => $subject['name'], 'description' => (string)($subject['description'] ?? ''),
                'identity_facts' => ['library_subject_id' => (string)$subject['id']] + $facts];
        }
        return ['context' => ['review_format_version' => GenerationPipeline::REVIEW_FORMAT_VERSION, 'history_evidence_index_version' => NarrativeHistoryIndex::VERSION, 'missing_content_location_version' => MissingContentLocation::VERSION, 'scene_screenplay_version' => SceneScreenplayExecution::VERSION, 'window_content_repair_version' => SceneContentRepair::VERSION, 'window_shot_repair_version' => SceneShotRepair::VERSION, 'scene_review_version' => SceneReviewExecution::VERSION, 'scene_shot_version' => SceneShotExecution::VERSION, 'prompt_projection_version' => PromptProjection::VERSION, 'narrative_contract_version' => 1, 'repair_contract_version' => 2, 'revision_id' => 'revision_' . substr(ContentDocument::hash([$task, 1]), 0, 24),
            'episode_id' => 'project:' . $project, 'entity_scope' => $scope, 'prompt' => $prompt, 'source_mode' => 'inspiration',
            'sources' => [$source['source_id'] => $source], 'entities' => $entities,
            'requirements' => SourceTrace::requirements($source, [['start' => 0, 'end' => mb_strlen($prompt, 'UTF-8'), 'quote' => $prompt]]),
            'target_duration_seconds' => (int)($request['target_duration_seconds'] ?? 0),
            'style' => isset($request['_vnext_style_snapshot']) ? trim(($request['_vnext_style_snapshot']['name'] ?? '') . "\n" . ($request['_vnext_style_snapshot']['prompt'] ?? '')) : (string)($request['style_id'] ?? ''), 'dependency_versions' => ['source' => $source['hash']]],
            'model' => $model, 'execution' => $execution];
    }

    public static function store(int $tenant, int $user, string $task): ExecutionStore
    {
        return new ExecutionStore($tenant, $user, $task, new ReceiptBuffer(runtime_path() . 'short_drama_vnext_receipts'));
    }

    public static function assertLegacyWrite(array $task): void
    {
        $request = json_decode((string)($task['request_json'] ?? ''), true);
        if (self::current(is_array($request) ? $request : [])) throw new RuntimeException('当前版本不支持此编辑或制作操作，已保存的正文不会改变', 409);
    }

    public static function revisionInput(array $input, array $candidate, array $patches, string $expectedRevision, string $newTask, array $origin = []): array
    {
        $document = $candidate['document'] ?? [];
        if (!$document || $expectedRevision !== $document['revision_id']) throw new RuntimeException('正文已更新，请刷新后再修改', 409);
        if (!$patches) throw new RuntimeException('没有提交正文修改', 422);
        $revision = 'revision_' . substr(ContentDocument::hash([$newTask, 1]), 0, 24);
        if ($origin) {
            if (($origin['task_id'] ?? '') !== $newTask) throw new RuntimeException('修改来源任务不符', 409);
            $captured = ManualContentRevision::capture($input, $document, $patches, $revision, $origin);
            $edited = $captured['document']; $input['context']['sources'] = $captured['sources'];
            $input['context']['manual_edit_history'] = $captured['history'];
        } else $edited = ContentDocument::patch($document, $patches, $revision);
        if (array_column(ContentDocument::units($document), 'text') === array_column(ContentDocument::units($edited), 'text')) {
            throw new RuntimeException('正文没有变化', 422);
        }
        unset($input['context']['structured_screenplay']);
        $input['context']['canonical_input'] = $edited;
        $input['context']['revision_id'] = $revision;
        // User edits are exact content. A failed semantic review may report issues but cannot rewrite them.
        return $input;
    }

    public static function recheckInput(array $input, array $candidate, array $context, string $expectedRevision, string $newTask): array
    {
        $document = $candidate['document'] ?? [];
        if (!$document || $document['revision_id'] !== $expectedRevision) throw new RuntimeException('复核正文版本已变化', 409);
        $revision = 'revision_' . substr(ContentDocument::hash([$newTask, 1]), 0, 24);
        $input['context'] = array_replace($input['context'], $context);
        $input['context']['canonical_input'] = ContentDocument::rebind($document, $context['dependency_versions'], $revision);
        $input['context']['revision_id'] = $revision;
        unset($input['context']['structured_screenplay']);
        return $input;
    }

    /** The compatible view is derived from accepted content, never fed back into creative validation. */
    public static function project(array $candidate): array
    {
        $document = $candidate['document']; $plan = $candidate['shot_plan'];
        ShotCompiler::assertCurrent($document, $plan);
        $subjects = []; $locations = []; $names = array_column($document['entities'], 'canonical_name', 'entity_id');
        foreach ($document['entities'] as $entity) {
            $row = ['id' => $entity['entity_id'], 'name' => $entity['canonical_name'], 'description' => $entity['description'],
                'entity_id' => $entity['entity_id'], 'image_prompt' => $entity['description'] ?: $entity['canonical_name']];
            if ($entity['kind'] === 'location') $locations[] = $row;
            else $subjects[] = $row + ['type' => $entity['kind']];
        }
        $units = ContentDocument::units($document); $shots = [];
        foreach ($plan['shots'] as $shot) {
            $actions = [];
            foreach ($shot['unit_refs'] as $ref) if ($units[$ref['unit_id']]['type'] === 'action') {
                $actions[] = ContentDocument::slice($units[$ref['unit_id']]['text'], $ref['start'], $ref['end']);
            }
            $shots[] = $shot + ['title' => '分镜 ' . $shot['order'], 'sort' => $shot['order'], 'scene_name' => $names[$shot['location_id']],
                'scene_ref_id' => $shot['location_id'], 'subject_ref_ids' => $shot['entity_ids'],
                'action' => implode("\n", $actions), 'dialogue' => $shot['dialogue_preview'],
                'recommended_duration_seconds' => null];
        }
        return ['pipeline_version' => GenerationPipeline::VERSION, 'title' => $document['title'], 'multi_episode' => false,
            'episode_count' => 1, 'multi_episode_stage' => 'production', 'subjects' => $subjects, 'locations' => $locations,
            'storyboard' => $shots, 'script_lines' => ContentDocument::render($document),
            'canonical_document' => $document, 'shot_plan' => $plan, 'coverage_report' => $candidate['coverage_report']]
            + (isset($candidate['coverage_report']['narrative_ledger']) ? ['narrative_ledger' => $candidate['coverage_report']['narrative_ledger']] : []);
    }
}
