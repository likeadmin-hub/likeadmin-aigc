<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Server-created receipts distinguish a human identity correction from model disagreement. */
final class EntityIdentityRevision
{
    public static function capture(array $entity, array $facts, string $revision, array $origin, array $previous = []): array
    {
        ManualContentRevision::assertOrigin($origin);
        if ($previous) self::assertReceipt($previous, $entity, ['tenant_id' => $origin['tenant_id'], 'user_id' => $origin['user_id']]);
        if (count($facts) > 32 || ($facts && array_keys($facts) === range(0, count($facts) - 1))) throw new RuntimeException('身份事实须为不超过 32 项的属性记录', 422);
        $old = $entity['identity_facts'];
        // A library link governs asset ownership, and is never an editable identity assertion.
        if (array_key_exists('library_subject_id', $facts) !== array_key_exists('library_subject_id', $old)
            || ($facts['library_subject_id'] ?? null) !== ($old['library_subject_id'] ?? null)) throw new RuntimeException('主体库关联不能通过身份修订修改', 422);
        foreach ($facts as $key => $value) {
            if (!is_string($key) || !preg_match('/^[\p{L}\p{N}_-]{1,80}$/u', $key)) throw new RuntimeException('身份属性名无效', 422);
            if (array_key_exists($key, $old) && $old[$key] === $value) continue;
            if ((!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null)
                || (is_string($value) && (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 2000))) throw new RuntimeException('身份属性应为明确的文本、整数或确认值', 422);
        }
        if (strlen(json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 64000) throw new RuntimeException('身份事实超过单次修订容量', 413);
        $receipt = ['schema_version' => 1, 'entity_id' => $entity['entity_id'], 'kind' => $entity['kind'],
            'base_facts_hash' => $previous['base_facts_hash'] ?? ContentDocument::hash($old),
            'before_facts_hash' => ContentDocument::hash($old), 'after_facts_hash' => ContentDocument::hash($facts),
            'previous_receipt_hash' => $previous['receipt_hash'] ?? '', 'revision_id' => $revision, 'origin' => $origin];
        $receipt['receipt_hash'] = ContentDocument::hash($receipt);
        return $receipt;
    }

    public static function assertReceipt(array $receipt, array $entity, array $scope = []): void
    {
        $unsigned = $receipt; unset($unsigned['receipt_hash']);
        if (array_diff(array_keys($receipt), ['schema_version','entity_id','kind','base_facts_hash','before_facts_hash','after_facts_hash','previous_receipt_hash','revision_id','origin','receipt_hash'])
            || ($receipt['schema_version'] ?? null) !== 1 || ($receipt['receipt_hash'] ?? '') !== ContentDocument::hash($unsigned)
            || ($receipt['entity_id'] ?? '') !== $entity['entity_id'] || ($receipt['kind'] ?? '') !== $entity['kind']
            || ($receipt['after_facts_hash'] ?? '') !== ContentDocument::hash($entity['identity_facts'])) throw new RuntimeException('身份修订收据与当前主体不符', 409);
        foreach (['base_facts_hash','before_facts_hash','after_facts_hash'] as $field) if (!is_string($receipt[$field] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $receipt[$field])) throw new RuntimeException('身份修订收据不完整', 409);
        if (!is_string($receipt['previous_receipt_hash'] ?? null) || ($receipt['previous_receipt_hash'] !== '' && !preg_match('/^[a-f0-9]{64}$/D', $receipt['previous_receipt_hash']))) throw new RuntimeException('身份修订来源链无效', 409);
        ContentDocument::text($receipt['revision_id'] ?? null, 'identity_revision.revision_id');
        if (!is_array($receipt['origin'] ?? null)) throw new RuntimeException('身份修订缺少确认来源', 409);
        ManualContentRevision::assertOrigin($receipt['origin']);
        foreach (['tenant_id', 'user_id'] as $field) if (isset($scope[$field]) && $scope[$field] !== $receipt['origin'][$field]) throw new RuntimeException('身份修订不属于当前用户', 409);
    }

    public static function carry(array $receipts, array $entities, array $scope = []): array
    {
        $result = []; $byId = array_column($entities, null, 'entity_id');
        foreach ($receipts as $id => $receipt) {
            if (!isset($byId[$id]) || !is_array($receipt)) throw new RuntimeException('身份修订主体缺失', 409);
            self::assertReceipt($receipt, $byId[$id], $scope); $result[$id] = $receipt;
        }
        return $result;
    }

    public static function confirmedEntities(array $previous, array $document): array
    {
        $byId = array_column($previous, null, 'entity_id'); $used = array_column($previous, 'ref'); $result = [];
        foreach ($document['entities'] as $entity) {
            $ref = $byId[$entity['entity_id']]['ref'] ?? null;
            if (!$ref) {
                $prefix = ['character'=>'c','location'=>'l','prop'=>'p'][$entity['kind']]; $number = 1;
                do { $ref = $prefix . $number++; } while (in_array($ref, $used, true));
                $used[] = $ref;
            }
            $result[] = ['ref'=>$ref, 'entity_id'=>$entity['entity_id'], 'kind'=>$entity['kind'], 'name'=>$entity['canonical_name'],
                'description'=>$entity['description'], 'identity_facts'=>$entity['identity_facts'],
                'aliases'=>array_values(array_filter($entity['aliases'], static fn($alias)=>!preg_match('/^[clp]\d+$/D', $alias)))];
        }
        return $result;
    }
}
