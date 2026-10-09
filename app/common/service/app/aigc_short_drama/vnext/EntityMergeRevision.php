<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Explicit identity equivalence, with the original registration retained as server evidence. */
final class EntityMergeRevision
{
    public const VERSION = 1;

    public static function carry(array $receipts, array $entities, array $scope = []): array
    {
        if (count($receipts) > 512) throw new RuntimeException('身份合并记录超过容量，请保留当前版本并整理设定', 413);
        $active = array_column($entities, null, 'entity_id');
        foreach ($receipts as $id => $receipt) {
            if (!is_string($id) || !is_array($receipt)) throw new RuntimeException('身份合并记录无效', 409);
            $unsigned = $receipt; unset($unsigned['receipt_hash']);
            $source = $receipt['source_entity'] ?? [];
            if (array_diff(array_keys($receipt), ['schema_version','source_entity','source_hash','source_base_facts_hash','source_identity_receipt',
                'target_entity_id','kind','target_library_link','revision_id','origin','receipt_hash'])
                || ($receipt['schema_version'] ?? 0) !== self::VERSION || ($receipt['receipt_hash'] ?? '') !== ContentDocument::hash($unsigned)
                || !is_array($source) || ($source['entity_id'] ?? '') !== $id || isset($active[$id])
                || ($receipt['source_hash'] ?? '') !== ContentDocument::hash($source)
                || !in_array($receipt['kind'] ?? '', ['character','location','prop'], true) || ($source['kind'] ?? '') !== $receipt['kind']
                || !is_string($receipt['target_entity_id'] ?? null) || $receipt['target_entity_id'] === $id
                || !is_array($source['identity_facts'] ?? null) || !is_array($source['aliases'] ?? null)
                || !is_string($source['canonical_name'] ?? null) || !is_array($receipt['origin'] ?? null)) throw new RuntimeException('身份合并证明与登记不符', 409);
            ManualContentRevision::assertOrigin($receipt['origin']);
            ContentDocument::text($receipt['revision_id'] ?? null, 'entity_merge.revision_id');
            foreach (['tenant_id','user_id'] as $field) if (isset($scope[$field]) && $scope[$field] !== $receipt['origin'][$field]) throw new RuntimeException('身份合并不属于当前用户', 409);
            $identity = $receipt['source_identity_receipt'] ?? [];
            if (!is_array($identity)) throw new RuntimeException('合并前身份确认无效', 409);
            if ($identity) EntityIdentityRevision::assertReceipt($identity, $source, $scope);
            if (($receipt['source_base_facts_hash'] ?? '') !== ($identity['base_facts_hash'] ?? ContentDocument::hash($source['identity_facts']))) throw new RuntimeException('合并前身份基线不符', 409);
        }
        foreach ($receipts as $id => $receipt) {
            $root = self::resolve($id, $receipts);
            $target = $active[$root] ?? null;
            $direct = $active[$receipt['target_entity_id']] ?? ($receipts[$receipt['target_entity_id']]['source_entity'] ?? null);
            if (!$target || !$direct || $target['kind'] !== $receipt['kind'] || $direct['kind'] !== $receipt['kind']
                || ($receipt['target_library_link'] ?? null) !== self::libraryLink($direct)
                || !in_array($receipt['source_entity']['canonical_name'], $target['aliases'], true)) throw new RuntimeException('合并后的保留身份或别名不符', 409);
        }
        return $receipts;
    }

    public static function resolve(string $id, array $receipts): string
    {
        $seen = [];
        while (isset($receipts[$id])) {
            if (isset($seen[$id]) || count($seen) >= 512) throw new RuntimeException('身份合并关系存在循环', 409);
            $seen[$id] = true; $id = $receipts[$id]['target_entity_id'];
        }
        return $id;
    }

    public static function apply(array $document, array $patches, string $revision, array $origin, array $identities, array $protected): array
    {
        ManualContentRevision::assertOrigin($origin);
        $receipts = self::carry($document['entity_merge_receipts'] ?? [], $document['entities'], $origin);
        $entities = array_column($document['entities'], null, 'entity_id'); $used = []; $changes = []; $mapping = [];
        if (!$patches || count($patches) > 64) throw new RuntimeException('单次身份合并须为 1 至 64 项', 413);
        foreach (ContentDocument::rows($patches, 'entity_merges') as $patch) {
            $source = $patch['source_entity_id'] ?? null; $target = $patch['target_entity_id'] ?? null;
            if (array_diff(array_keys($patch), ['source_entity_id','target_entity_id','source_before_hash','target_before_hash'])
                || !is_string($source) || !is_string($target) || $source === $target || !isset($entities[$source], $entities[$target])
                || isset($used[$source]) || isset($used[$target])
                || ($patch['source_before_hash'] ?? '') !== ContentDocument::hash($entities[$source])
                || ($patch['target_before_hash'] ?? '') !== ContentDocument::hash($entities[$target])) throw new RuntimeException('身份合并基线已变化或对象重复，请分次确认', 409);
            $old = $entities[$source]; $kept = $entities[$target];
            if ($old['kind'] !== $kept['kind']) throw new RuntimeException('不同类型的主体不能合并', 422);
            if (in_array($source, $protected, true)) throw new RuntimeException('原身份仍有剧情事实或角色认知引用，请先明确修订事实', 409);
            foreach ($old['identity_facts'] as $key => $value) {
                if (!array_key_exists($key, $kept['identity_facts']) || $kept['identity_facts'][$key] !== $value) throw new RuntimeException('身份属性或主体库关联不一致，请先明确修订并选择保留身份', 409);
            }
            $after = $kept;
            $after['aliases'] = array_values(array_unique(array_merge($kept['aliases'], [$old['canonical_name']], $old['aliases'])));
            foreach ($entities as $otherId => $other) if (!in_array($otherId, [$source,$target], true) && $other['kind'] === $old['kind']) {
                $newAliases = array_diff($after['aliases'], $kept['aliases']);
                if (array_intersect($newAliases, array_merge([$other['canonical_name']], $other['aliases']))) throw new RuntimeException('合并别名与其他主体冲突，请先明确区分', 409);
            }
            $after['candidate_descriptions'] = array_values(array_unique(array_merge($kept['candidate_descriptions'],
                $old['candidate_descriptions'], $old['description'] !== '' ? [$old['description']] : [])));
            $after['confirmed'] = true;
            $proof = ['schema_version'=>self::VERSION, 'source_entity'=>$old, 'source_hash'=>ContentDocument::hash($old),
                'source_base_facts_hash'=>$identities[$source]['base_facts_hash'] ?? ContentDocument::hash($old['identity_facts']),
                'source_identity_receipt'=>$identities[$source] ?? [], 'target_entity_id'=>$target, 'kind'=>$kept['kind'],
                'target_library_link'=>self::libraryLink($kept), 'revision_id'=>$revision, 'origin'=>$origin];
            $proof['receipt_hash'] = ContentDocument::hash($proof); $receipts[$source] = $proof;
            $entities[$target] = $after; unset($entities[$source], $identities[$source]);
            $mapping[$source] = $target; $used[$source] = true; $used[$target] = true;
            $changes[] = ['id'=>$source, 'before'=>['source'=>$old,'target'=>$kept], 'after'=>['target'=>$after,'mapping'=>[$source=>$target]],
                'label'=>'确认同一'.EntityRegistryRevision::kindName($old['kind']).'：'.$old['canonical_name'].' → '.$kept['canonical_name']];
        }
        foreach ($document['scenes'] as &$scene) {
            $scene['location_id'] = $mapping[$scene['location_id']] ?? $scene['location_id'];
            foreach ($scene['units'] as &$unit) {
                $unit['entity_ids'] = array_values(array_unique(array_map(static fn($id)=>$mapping[$id] ?? $id, $unit['entity_ids'])));
                if (is_string($unit['speaker_id'] ?? null)) $unit['speaker_id'] = $mapping[$unit['speaker_id']] ?? $unit['speaker_id'];
            }
            unset($unit);
        }
        unset($scene);
        $document['entities'] = array_values($entities);
        $document['entity_merge_receipts'] = self::carry($receipts, $document['entities'], $origin);
        return ['document'=>$document,'identities'=>$identities,'changes'=>$changes];
    }

    public static function inherit(array $confirmed, array $published, array $receipts, array $scope): array
    {
        self::carry($receipts, $published, $scope);
        foreach ($confirmed as $entity) if (isset($receipts[$entity['entity_id']])) {
            $proof = $receipts[$entity['entity_id']];
            if ($entity['kind'] !== $proof['kind'] || ContentDocument::hash($entity['identity_facts']) !== $proof['source_base_facts_hash']) throw new RuntimeException('身份合并与系列最初确认基线不符', 409);
        }
        return array_values(array_filter($confirmed, static fn($entity)=>!isset($receipts[$entity['entity_id']])));
    }

    public static function modelChoices(array $receipts, array $entities): array
    {
        self::carry($receipts, $entities); $active = array_column($entities, null, 'entity_id'); $result = [];
        foreach ($receipts as $id => $proof) {
            $target = $active[self::resolve($id, $receipts)];
            $result[] = ['kind'=>$proof['kind'],'previous_name'=>$proof['source_entity']['canonical_name'],
                'entity_id'=>$target['entity_id'],'name'=>$target['canonical_name']];
        }
        return $result;
    }

    private static function libraryLink(array $entity): array
    {
        return array_intersect_key($entity['identity_facts'], ['library_subject_id'=>true]);
    }
}
