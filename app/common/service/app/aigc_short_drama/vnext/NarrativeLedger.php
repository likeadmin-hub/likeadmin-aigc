<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Evidence from an accepted screenplay, never from a roadmap or an unverified summary. */
final class NarrativeLedger
{
    public const VERSION = 1;

    public static function instruction(): string
    {
        return '另返回narrative_changes数组，记录本集正文中发生的核心状态变化，不从未来大纲或人物背景猜测。'
            . '每项为{kind:world或belief,subject_id:已有entity_id或world,predicate:稳定属性名,value:完整状态文本,'
            . 'observer_id:belief的知情角色entity_id，world用null,certainty:confirmed或uncertain,'
            . 'evidence:[{unit:正文短别名,start:0,end:2,quote:逐字引文}]}。'
            . 'world是故事世界的实际状态，至少引用一处动作正文；单纯对白、谎言、误认只能记belief，不自动当作world。'
            . 'belief是observer_id相信subject_id的某种状态，可以与world不同。未知推断标uncertain，不进入下一集已确认状态。'
            . '只报告变化；同一主体/属性/观察者最多一条，取本集结束时有证据的状态；闪回和未来打算不能覆盖当前状态。'
            . '有明确外观变化时，人物属性使用appearance、clothing、hairstyle、makeup或injury；地点使用scene_layout、scene_condition、lighting或time_of_day；道具使用prop_condition或prop_appearance。这些world状态必须有动作证据，不把角色说法当作制作外观。'
            . '不返回系统事实ID、不改写已确认历史；无明确变化返回空数组。';
    }

    public static function evaluate(array $document, array $changes, array $previous = []): array
    {
        if ($previous && (($previous['schema_version'] ?? 0) !== self::VERSION
            || ($previous['content_hash'] ?? '') !== self::digest($previous))) throw new RuntimeException('前集事实版本校验失败', 409);
        $entities = array_column($document['entities'], null, 'entity_id');
        $units = ContentDocument::units($document); $refs = array_column($units, 'unit_id', 'ref');
        $state = (array)($previous['state'] ?? []); $delta = []; $uncertain = []; $seen = [];
        foreach (ContentDocument::rows($changes, 'narrative_changes') as $change) {
            $kind = $change['kind'] ?? '';
            if (!in_array($kind, ['world', 'belief'], true)) throw new RuntimeException('事实与角色认知必须分别记录', 422);
            $subject = ContentDocument::text($change['subject_id'] ?? null, 'fact.subject_id');
            if ($subject !== 'world' && !isset($entities[$subject])) throw new RuntimeException('事实主体未登记', 422);
            $predicate = ContentDocument::text($change['predicate'] ?? null, 'fact.predicate');
            if (!preg_match('/^[\p{L}\p{N}_-]{1,80}$/u', $predicate)) throw new RuntimeException('事实属性名无效', 422);
            $value = ContentDocument::text($change['value'] ?? null, 'fact.value');
            $observer = $change['observer_id'] ?? null;
            if ($kind === 'belief' && (!is_string($observer) || ($entities[$observer]['kind'] ?? '') !== 'character')) {
                throw new RuntimeException('角色认知缺少已登记的知情角色', 422);
            }
            if ($kind === 'world' && $observer !== null) throw new RuntimeException('世界事实不能混入角色认知', 422);
            $certainty = $change['certainty'] ?? '';
            if (!in_array($certainty, ['confirmed', 'uncertain'], true)) throw new RuntimeException('事实确认状态缺失', 422);
            $key = ContentDocument::hash([$kind, $subject, $predicate, $observer]);
            if (isset($seen[$key])) throw new RuntimeException('同一状态出现多个更新，请核对本集结束状态', 422);
            $seen[$key] = true; $evidence = []; $hasAction = false;
            foreach (ContentDocument::rows($change['evidence'] ?? [], 'fact.evidence') as $link) {
                $unit = $units[$refs[$link['unit'] ?? ''] ?? ''] ?? null;
                if (!$unit || !isset($link['quote'])) throw new RuntimeException('事实缺少真实正文证据', 422);
                ContentDocument::slice($unit['text'], $link['start'] ?? null, $link['end'] ?? null, $link['quote']);
                $hasAction = $hasAction || $unit['type'] === 'action';
                $evidence[] = ['unit_id' => $unit['unit_id'], 'text_hash' => $unit['text_hash'],
                    'start' => $link['start'], 'end' => $link['end'], 'quote' => $link['quote']];
            }
            if (!$evidence) throw new RuntimeException('事实缺少真实正文证据', 422);
            if ($kind === 'world' && $certainty === 'confirmed' && !$hasAction) {
                throw new RuntimeException('只有对白的说法不能直接写入世界事实，请区分认知与实际发生', 422);
            }
            $record = ['fact_id' => 'fact_' . substr(ContentDocument::hash([$document['revision_id'], $key, $value, $evidence]), 0, 24),
                'kind' => $kind, 'subject_id' => $subject, 'predicate' => $predicate, 'value' => $value,
                'observer_id' => $observer, 'certainty' => $certainty, 'episode_id' => $document['episode_id'],
                'revision_id' => $document['revision_id'], 'document_hash' => $document['content_hash'], 'evidence' => $evidence,
                'supersedes_fact_id' => $state[$key]['fact_id'] ?? null];
            if ($certainty === 'uncertain') { $uncertain[] = $record; continue; }
            $state[$key] = $record; $delta[] = $record;
        }
        $ledger = ['schema_version' => self::VERSION, 'episode_id' => $document['episode_id'],
            'revision_id' => $document['revision_id'], 'document_hash' => $document['content_hash'],
            'previous_hash' => $previous['content_hash'] ?? '', 'state' => $state, 'delta' => $delta, 'uncertain' => $uncertain];
        $ledger['content_hash'] = self::digest($ledger);
        return $ledger;
    }

    public static function digest(array $ledger): string
    {
        unset($ledger['content_hash']); return ContentDocument::hash($ledger);
    }

    /** Entity-based retrieval preserves each selected record's exact source and separates beliefs. */
    public static function relevant(array $ledger, array $entityIds): array
    {
        if (!$ledger) return ['world_facts' => [], 'character_beliefs' => []];
        if (($ledger['content_hash'] ?? '') !== self::digest($ledger)) throw new RuntimeException('前集事实版本校验失败', 409);
        $result = ['world_facts' => [], 'character_beliefs' => []];
        foreach ($ledger['state'] as $record) {
            if ($record['subject_id'] !== 'world' && !in_array($record['subject_id'], $entityIds, true)
                && !in_array($record['observer_id'], $entityIds, true)) continue;
            $result[$record['kind'] === 'world' ? 'world_facts' : 'character_beliefs'][] = $record;
        }
        return $result;
    }
}
