<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Manual registration only. Client references never become system identity or asset ownership. */
final class EntityRegistryRevision
{
    public static function protectedIds(array $ledgers): array
    {
        $ids = [];
        foreach ($ledgers as $ledger) {
            if (!$ledger) continue;
            if (!is_array($ledger) || ($ledger['content_hash'] ?? '') !== NarrativeLedger::digest($ledger)) throw new RuntimeException('剧情事实版本无法核对，请先复核', 409);
            foreach (['state', 'delta', 'uncertain'] as $group) foreach ($ledger[$group] ?? [] as $fact) {
                foreach (['subject_id', 'observer_id'] as $field) if (is_string($fact[$field] ?? null) && $fact[$field] !== 'world') $ids[$fact[$field]] = true;
            }
        }
        return array_keys($ids);
    }

    public static function prepare(array $document, array $patch, string $revision): array
    {
        if (array_diff(array_keys($patch), ['before_hash', 'additions', 'removals'])
            || ($patch['before_hash'] ?? '') !== ContentDocument::hash($document['entities'])) throw new RuntimeException('主体登记基线已变化，请刷新后重试', 409);
        $additions = ContentDocument::rows($patch['additions'] ?? [], 'entity_registry.additions');
        $removals = ContentDocument::rows($patch['removals'] ?? [], 'entity_registry.removals');
        if (count($additions) > 64 || count($removals) > 64) throw new RuntimeException('单次最多新增或移除 64 个主体，请分批保存', 413);
        $entities = array_column($document['entities'], null, 'entity_id'); $refs = []; $remove = []; $changes = [];
        foreach ($additions as $row) {
            if (array_diff(array_keys($row), ['client_ref', 'kind', 'canonical_name', 'description'])
                || !is_string($row['client_ref'] ?? null) || !preg_match('/^new_entity_[A-Za-z0-9_-]{1,48}$/D', $row['client_ref'])
                || isset($refs[$row['client_ref']]) || isset($entities[$row['client_ref']])
                || !in_array($row['kind'] ?? '', ['character', 'location', 'prop'], true)) throw new RuntimeException('新增主体须提供唯一临时标识和明确类型', 422);
            $name = trim(ContentDocument::text($row['canonical_name'] ?? null, 'entity.canonical_name'));
            if (mb_strlen($name, 'UTF-8') > 80) throw new RuntimeException('名称超过 80 字，请缩短名称', 422);
            $description = $row['description'] ?? '';
            if (!is_string($description) || !mb_check_encoding($description, 'UTF-8') || mb_strlen($description, 'UTF-8') > 16000) throw new RuntimeException('设定说明应为不超过 16000 字的完整文本', 422);
            foreach ($entities as $entity) if ($entity['kind'] === $row['kind'] && in_array($name, array_merge([$entity['canonical_name']], $entity['aliases']), true)) throw new RuntimeException('名称与已登记主体或别名重名，请明确区分', 422);
            $id = 'ent_' . substr(ContentDocument::hash([$revision, 'manual_registration', $row['client_ref'], $row['kind']]), 0, 24);
            if (isset($entities[$id])) throw new RuntimeException('主体标识冲突，请重新打开修订', 409);
            $entity = ['entity_id' => $id, 'kind' => $row['kind'], 'canonical_name' => $name, 'identity_facts' => [],
                'description' => $description, 'confirmed' => true, 'aliases' => [$name], 'candidate_descriptions' => []];
            $entities[$id] = $entity; $refs[$row['client_ref']] = $id;
            $changes[] = ['id' => $id, 'before' => [], 'after' => $entity, 'label' => '新增' . self::kindName($row['kind']) . '：' . $name];
        }
        foreach ($removals as $row) {
            $id = $row['entity_id'] ?? null;
            if (array_diff(array_keys($row), ['entity_id', 'before_hash']) || !is_string($id)
                || !isset($entities[$id]) || isset($remove[$id]) || in_array($id, $refs, true)
                || ($row['before_hash'] ?? '') !== ContentDocument::hash($entities[$id])) throw new RuntimeException('移除主体基线已变化或目标重复', 409);
            $remove[$id] = $entities[$id];
        }
        return ['entities' => $entities, 'refs' => $refs, 'removals' => $remove, 'changes' => $changes];
    }

    public static function remove(array $document, array $entities, array $removals, array $ledgers): array
    {
        $protected = self::protectedIds($ledgers);
        foreach ($removals as $id => $entity) {
            if (in_array($id, $protected, true)) throw new RuntimeException('主体仍被剧情事实或角色认知引用，请先明确修订事实', 409);
            foreach ($document['scenes'] as $scene) {
                if ($scene['location_id'] === $id) throw new RuntimeException('地点仍被场次引用，请先更换场次地点', 409);
                foreach ($scene['units'] as $unit) if (in_array($id, $unit['entity_ids'], true) || ($unit['speaker_id'] ?? null) === $id) throw new RuntimeException('主体仍被动作或台词引用，请先更换人物绑定', 409);
            }
            unset($entities[$id]);
        }
        return $entities;
    }

    public static function kindName(string $kind): string { return ['character' => '人物', 'location' => '地点', 'prop' => '道具'][$kind]; }
}
