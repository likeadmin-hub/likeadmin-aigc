<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use app\common\service\app\aigc_short_drama\ShortDramaPlanningContext;
use RuntimeException;
use think\facade\Db;

/** Server-only bridge from a confirmed series to independently versioned episode screenplays. */
final class SeriesContext
{
    public static function capture(int $tenant, int $user, int $project, string $task, array $request, array $plan, array $policy): ?array
    {
        if (empty($policy['series_enabled']) || empty($request['confirmed_story_snapshot'])
            || !isset($request['confirmed_outline_snapshot'])) return null;
        $attachment = ($request['script_source'] ?? '') === 'upload';
        if ($attachment && (empty($policy['attachment_enabled']) || !isset($request['_attachment_source']))) return null;
        $model = (array)($request['model_selections']['script_plan'] ?? []);
        $execution = ApplicationWorkflow::enrollment($tenant, [], $model, $policy, true);
        if ($execution === null) return null;
        $scope = $tenant . ':' . $user . ':series:' . $project; $entities = []; $ids = [];
        foreach (['subjects', 'locations'] as $group) foreach (ContentDocument::rows($plan[$group] ?? [], $group) as $item) {
            $original = ContentDocument::text((string)($item['id'] ?? ''), 'confirmed_entity.id');
            $kind = $group === 'locations' ? 'location' : (($item['category'] ?? '') === 'prop' ? 'prop' : 'character');
            $id = 'ent_' . substr(ContentDocument::hash([$scope, $kind, $original]), 0, 24);
            if (isset($ids[$id])) throw new RuntimeException('已确认设定包含重复实体标识', 422);
            $ids[$id] = true;
            $entities[] = ['ref' => ($kind === 'location' ? 'l' : ($kind === 'prop' ? 'p' : 'c')) . (count($entities) + 1),
                'entity_id' => $id, 'kind' => $kind, 'name' => ContentDocument::text($item['name'] ?? null, 'confirmed_entity.name'),
                'description' => (string)($item['description'] ?? ''),
                'identity_facts' => array_intersect_key($item, array_flip(['library_subject_id', 'gender', 'age', 'age_stage']))];
        }
        $snapshot = ['schema_version' => 1, 'tenant_id' => $tenant, 'user_id' => $user, 'project_id' => $project,
            'outline_task_id' => $task, 'outline_hash' => ContentDocument::hash($plan),
            'screenplay_preflight_version' => SceneScreenplayExecution::PREFLIGHT_VERSION,
            'account_reservation_version' => isset($execution['resource_limits']) ? 1 : 0,
            'entity_scope' => $scope, 'entities' => $entities, 'model_id' => (string)$model['id'], 'execution' => $execution,
            'story' => ShortDramaPlanningContext::lockedStory($request['confirmed_story_snapshot']),
            'story_hash' => ContentDocument::hash($request['confirmed_story_snapshot']),
            'source' => SourceTrace::capture('series_source_' . $project, $task, ContentDocument::text($request['prompt'] ?? null, 'series.prompt'), 'inspiration')];
        if (PlanningTrace::enabled($request)) {
            $issues = PlanningTrace::issues($request, $plan, 'episodes', count($plan['episodes']));
            if ($issues) throw new RuntimeException($issues[0]['message'], 422);
            $snapshot['content_trace'] = $plan['content_trace'];
            $snapshot['source'] = $plan['content_trace']['source'];
            $snapshot['source_events'] = PlanningTrace::events($plan['content_trace'], $plan['episodes'], $snapshot['outline_hash']);
        }
        if ($attachment) {
            $package = $request['_attachment_source'];
            if (($package['tenant_id'] ?? null) !== $tenant || ($package['user_id'] ?? null) !== $user) throw new RuntimeException('附件不属于当前系列用户', 409);
            foreach ($plan['episodes'] as $episode) AttachmentScreenplay::prepare($package, $episode, $entities);
            $snapshot['attachment_source'] = $package;
        }
        $snapshot['snapshot_hash'] = ContentDocument::hash($snapshot);
        return $snapshot;
    }

    public static function prepare(array $snapshot, array $episode, array $plan): array
    {
        $unsigned = $snapshot; unset($unsigned['snapshot_hash']);
        if (($snapshot['schema_version'] ?? 0) !== 1 || ($snapshot['snapshot_hash'] ?? '') !== ContentDocument::hash($unsigned)
            || (int)$episode['tenant_id'] !== (int)$snapshot['tenant_id'] || (int)$episode['user_id'] !== (int)$snapshot['user_id']
            || (int)$episode['project_id'] !== (int)$snapshot['project_id'] || $episode['outline_task_id'] !== $snapshot['outline_task_id']
            || ContentDocument::hash($plan) !== $snapshot['outline_hash']) throw new RuntimeException('分集确认快照已变化或归属不符', 409);
        $number = (int)$episode['episode_number']; $outline = $plan['episodes'][$number - 1] ?? [];
        if ((int)($outline['episode_number'] ?? 0) !== $number) throw new RuntimeException('本集不属于已确认大纲', 409);
        $stored = json_decode((string)$episode['outline_json'], true, 512, JSON_THROW_ON_ERROR);
        if (ContentDocument::hash($stored) !== ContentDocument::hash($outline)) throw new RuntimeException('本集大纲与确认版本不一致', 409);
        $episodeId = 'series:' . $snapshot['project_id'] . ':episode:' . $number;
        $sources = [$snapshot['source']['source_id'] => $snapshot['source']]; $events = [];
        foreach (['story_outline' => true, 'conflict_point' => false, 'ending_hook' => false] as $field => $blocking) {
            $text = ContentDocument::text($outline[$field] ?? null, 'episode.' . $field);
            $source = SourceTrace::capture('outline_' . $episodeId . '_' . $field, $snapshot['outline_hash'], $text, 'adaptation');
            $sources[$source['source_id']] = $source;
            $events[] = ['id' => 'event_' . substr(ContentDocument::hash([$episodeId, $field, $snapshot['outline_hash']]), 0, 24),
                'text' => $text, 'label' => $text, 'blocking' => $blocking, 'confirmed' => true, 'episode_ids' => [$episodeId],
                'outline_revision' => $snapshot['outline_hash'], 'requirement_ids' => [], 'origin' => 'confirmed_outline',
                'source_id' => $source['source_id'], 'source_hash' => $source['hash'], 'start' => 0, 'end' => mb_strlen($text, 'UTF-8')];
        }
        $requirements = [];
        foreach ($snapshot['content_trace']['requirements'] ?? [] as $requirement) {
            if ($requirement['kind'] === 'event') continue; // Check the allocated event, not the whole cross-episode instruction in each episode.
            if ($requirement['episode_numbers'] && !in_array($number, $requirement['episode_numbers'], true)) continue;
            $requirement['episode_ids'] = [$episodeId]; $requirements[] = $requirement;
        }
        foreach ($snapshot['source_events'] ?? [] as $event) {
            if ($event['episode_number'] !== $number) continue;
            $event['episode_ids'] = [$episodeId]; $events[] = $event;
        }
        $inputHistory = [];
        if (($snapshot['content_trace']['schema_version'] ?? 1) >= 2) {
            foreach (PlanningTrace::sources($snapshot['content_trace']) as $source) {
                if (($source['stage'] ?? '') === 'episodes' && !empty($source['episode_numbers'])
                    && !in_array($number, $source['episode_numbers'], true)) continue;
                $sources[$source['source_id']] = $source;
                $inputHistory[] = $source;
            }
            $revisionCheck = PlanningTrace::revisionCheck($inputHistory, $episodeId);
            if ($revisionCheck !== null) $requirements[] = $revisionCheck;
        }
        $previous = self::publishedBefore((int)$snapshot['tenant_id'], (int)$snapshot['user_id'], (int)$snapshot['project_id'], $number);
        $identityReceipts = $previous['identity_revision_receipts'] ?? [];
        $mergeReceipts = $previous['entity_merge_receipts'] ?? [];
        $entities = SeriesVisualState::inherit($snapshot['entities'], $previous['entities'], $identityReceipts,
            ['tenant_id' => (int)$snapshot['tenant_id'], 'user_id' => (int)$snapshot['user_id']], $mergeReceipts); $known = array_column($entities, 'entity_id');
        foreach ($previous['entities'] as $entity) {
            if (in_array($entity['entity_id'], $known, true)) continue;
            $known[] = $entity['entity_id'];
            // A prior published entity keeps its system identity. Model-local
            // short aliases are reassigned to avoid collisions across episodes.
            $prefix = $entity['kind'] === 'location' ? 'l' : ($entity['kind'] === 'prop' ? 'p' : 'c');
            $refNumber = count($entities) + 1; $usedRefs = array_merge(array_column($entities,'ref'),array_column($snapshot['entities'],'ref'));
            foreach ($mergeReceipts as $proof) $usedRefs = array_merge($usedRefs,$proof['source_entity']['aliases']);
            while (in_array($prefix.$refNumber,$usedRefs,true)) $refNumber++;
            $entities[] = ['ref' => $prefix.$refNumber,
                'entity_id' => $entity['entity_id'], 'kind' => $entity['kind'], 'name' => $entity['canonical_name'],
                'description' => $entity['description'], 'identity_facts' => $entity['identity_facts'],
                'aliases' => array_values(array_filter($entity['aliases'], static fn($alias) => !preg_match('/^[clp]\d+$/', $alias)))];
        }
        $attachmentContext = [];
        if (isset($snapshot['attachment_source'])) {
            $import = AttachmentScreenplay::prepare($snapshot['attachment_source'], $outline, $snapshot['entities']);
            $sources[$import['source']['source_id']] = $import['source'];
            $attachmentContext = ['attachment_import' => $import, 'structured_screenplay' => $import['screenplay'], 'source_mode' => 'faithful'];
        }
        return ['snapshot' => $snapshot, 'context' => array_replace([
            'episode_id' => $episodeId, 'entity_scope' => $snapshot['entity_scope'], 'entities' => $entities,
            'screenplay_preflight_version' => $snapshot['screenplay_preflight_version'] ?? 0,
            'account_reservation_version' => $snapshot['account_reservation_version'] ?? 0,
            'prompt' => $snapshot['source']['text'], 'source_mode' => 'inspiration', 'sources' => $sources, 'requirements' => $requirements, 'events' => $events,
            'confirmed_story' => $snapshot['story'], 'current_episode' => $outline,
            // Story arc is a plan; only evidence from published episodes is historical state.
            'confirmed_facts' => ['identities' => $entities, 'world_rules' => $snapshot['story']['series_bible']['world_rules'] ?? []],
            'previous_narrative' => $previous['ledger'],
            'dependency_versions' => ['source' => $snapshot['source']['hash'], 'bible' => $snapshot['story_hash'],
                'outline' => $snapshot['outline_hash'], 'episodes' => $previous['dependencies'],
                'series' => ['tenant_id' => $snapshot['tenant_id'], 'user_id' => $snapshot['user_id'],
                    'project_id' => $snapshot['project_id'], 'episode_number' => $number, 'snapshot_hash' => $snapshot['snapshot_hash']]],
        ] + ($inputHistory ? ['input_history' => $inputHistory] : []) + ($identityReceipts ? ['identity_revision_receipts' => $identityReceipts] : [])
            + ($mergeReceipts ? ['entity_merge_receipts'=>$mergeReceipts] : []), $attachmentContext)];
    }

    /** Bulk-read lightweight revision metadata; load complete evidence only for the latest published state. */
    public static function publishedBefore(int $tenant, int $user, int $project, int $number, bool $lock = false): array
    {
        $scope = ['tenant_id' => $tenant, 'user_id' => $user, 'delete_time' => 0];
        $rows = Db::name('aigc_short_drama_episode_task')->where($scope + ['project_id' => $project])
            ->where('episode_number', '<', $number)->order('episode_number')->field('episode_number,status,production_project_id,completed_once')->lock($lock)->select()->toArray();
        if (count($rows) !== $number - 1) throw new RuntimeException('前置剧集尚未建立，不能跳集生成', 409);
        if (!$rows) return ['dependencies' => [], 'ledger' => [], 'entities' => []];
        $projects = Db::name('aigc_short_drama_project')->where($scope)->whereIn('id', array_column($rows, 'production_project_id'))->lock($lock)->column('current_version_id', 'id');
        $versions = Db::name('aigc_short_drama_plan_version')->where($scope)->whereIn('id', array_values($projects))
            ->field('id,project_id,continuity_json')->lock($lock)->select()->toArray();
        $versions = array_column($versions, null, 'project_id'); $dependencies = []; $lastVersion = 0;
        foreach ($rows as $row) {
            $version = $versions[$row['production_project_id']] ?? [];
            $meta = json_decode($version['continuity_json'] ?? '{}', true) ?: [];
            $canonical = $meta['canonical'] ?? [];
            if (!$row['completed_once'] || $row['status'] !== 'success' || !$canonical
                || ($canonical['dependencies'] ?? []) !== $dependencies) throw new RuntimeException('请先完成前集发布或复核，不能沿用过期剧情', 409);
            $dependencies[(string)$row['episode_number']] = ['revision_id' => $canonical['revision_id'], 'document_hash' => $canonical['document_hash'],
                'ledger_hash' => $canonical['ledger_hash']];
            $lastVersion = (int)$version['id'];
        }
        $plan = json_decode((string)Db::name('aigc_short_drama_plan_version')->where($scope + ['id' => $lastVersion])->lock($lock)->value('plan_json'), true, 512, JSON_THROW_ON_ERROR);
        $ledger = $plan['narrative_ledger'] ?? [];
        if (!$ledger || ($ledger['content_hash'] ?? '') !== NarrativeLedger::digest($ledger)
            || $ledger['content_hash'] !== end($dependencies)['ledger_hash']) throw new RuntimeException('前集事实收据不完整，不能推测状态', 409);
        $document = $plan['canonical_document'] ?? []; $unsignedDocument = $document;
        unset($unsignedDocument['content_hash'], $unsignedDocument['status'], $unsignedDocument['transforms']);
        $current = end($dependencies);
        if (!$document || ($document['content_hash'] ?? '') !== ContentDocument::hash($unsignedDocument)
            || $document['content_hash'] !== $current['document_hash'] || ($document['revision_id'] ?? '') !== $current['revision_id']
            || ($ledger['document_hash'] ?? '') !== $document['content_hash'] || ($ledger['revision_id'] ?? '') !== $document['revision_id']
            || ($ledger['episode_id'] ?? '') !== ($document['episode_id'] ?? null)) throw new RuntimeException('前集正文、人物与事实不属于同一发布版本', 409);
        $identityReceipts = EntityIdentityRevision::carry($document['identity_revision_receipts'] ?? [], $document['entities'], ['tenant_id' => $tenant, 'user_id' => $user]);
        $mergeReceipts = EntityMergeRevision::carry($document['entity_merge_receipts'] ?? [], $document['entities'], ['tenant_id'=>$tenant,'user_id'=>$user]);
        return ['dependencies' => $dependencies, 'ledger' => $ledger, 'entities' => $document['entities']] + ($identityReceipts ? ['identity_revision_receipts' => $identityReceipts] : [])
            + ($mergeReceipts ? ['entity_merge_receipts'=>$mergeReceipts] : []);
    }

    public static function assertDependencies(array $context, bool $lock = false): void
    {
        $versions = $context['dependency_versions'] ?? []; $series = $versions['series'] ?? [];
        if (!$series) return;
        $parent = Db::name('aigc_short_drama_project')->where(['tenant_id' => $series['tenant_id'], 'user_id' => $series['user_id'],
            'id' => $series['project_id'], 'delete_time' => 0])->lock($lock)->find();
        if (!$parent) throw new RuntimeException('系列项目已不存在，候选保留但不能发布', 409);
        // A locking parent alone does not refresh an earlier REPEATABLE READ
        // snapshot (e.g. stage receipt reads). Publication uses current reads.
        $now = self::publishedBefore((int)$series['tenant_id'], (int)$series['user_id'], (int)$series['project_id'], (int)$series['episode_number'], $lock);
        if (($versions['episodes'] ?? []) !== $now['dependencies']) throw new RuntimeException('前集已更新，本集候选已保留，需要按最新依赖复核', 409);
    }

    public static function metadata(array $result): array
    {
        $document = $result['canonical_document']; $ledger = $result['narrative_ledger'];
        return ['canonical' => ['revision_id' => $document['revision_id'], 'document_hash' => $document['content_hash'],
            'ledger_hash' => $ledger['content_hash'], 'dependencies' => $document['dependency_versions']['episodes'] ?? []]];
    }

    public static function dependencyStatus(array $rows, array $current): array
    {
        $dependencies = []; $stale = false;
        foreach ($rows as &$row) {
            $meta = $current[(int)$row['production_project_id']]['canonical'] ?? [];
            if (!$meta) continue;
            $mismatch = $stale || ($meta['dependencies'] ?? []) !== $dependencies;
            $row['needs_review'] = $mismatch && $row['status'] === 'success';
            $row['review_reason'] = $row['needs_review'] ? '前集已更新，需要按最新正文和事实复核；原剧本和素材保留' : '';
            $stale = $stale || $mismatch;
            $dependencies[(string)$row['episode_number']] = array_intersect_key($meta, array_flip(['revision_id', 'document_hash', 'ledger_hash']));
        }
        unset($row); return $rows;
    }
}
