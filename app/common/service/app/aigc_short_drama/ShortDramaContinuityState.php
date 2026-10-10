<?php
namespace app\common\service\app\aigc_short_drama;

/** State references belong to the server. A model proposes evidence-backed
 * changes; a separate semantic check decides whether the transition is valid. */
final class ShortDramaContinuityState
{
    public static function field(string $field): string
    {
        $field = preg_replace('~^(?:state/)+~', '', $field);
        return ['current_location'=>'location', 'current_holder'=>'item_owner', 'holder'=>'item_owner', 'owner'=>'item_owner'][$field] ?? $field;
    }

    /** Resolve aliases only on an evidence-backed update, never by guessing which old value wins. */
    public static function align(array $review, array $context): array
    {
        $state = (array)($context['continuity']['state'] ?? []);
        foreach ($review['changes'] ?? [] as $index => $record) {
            if (!is_string($record['field'] ?? null) || !is_string($record['entity_id'] ?? null)) continue;
            $field = self::field($record['field']); $canonical = $record['entity_id'] . ':' . $field;
            // Keep the registered spelling until applying the verified transition.
            // This preserves before-value and evidence validation for old ledgers.
            $key = $canonical;
            if (!array_key_exists($key, $state)) foreach ($state as $existing => $value) {
                [$entity, $property] = array_pad(explode(':', $existing, 2), 2, '');
                if ($entity === $record['entity_id'] && self::field($property) === $field) { $key = $existing; break; }
            }
            $review['changes'][$index]['field'] = explode(':', $key, 2)[1];
        }
        return $review;
    }

    public static function replaceAliases(array $state, string $entity, string $field, string $after): array
    {
        $field = self::field($field);
        foreach (array_keys($state) as $key) {
            [$oldEntity, $oldField] = array_pad(explode(':', $key, 2), 2, '');
            if ($oldEntity === $entity && self::field($oldField) === $field) unset($state[$key]);
        }
        $state[$entity . ':' . $field] = $after;
        return $state;
    }

    public static function prepare(array $review, array $context): array
    {
        if (!empty($context['_canonical_state'])) $review = self::align($review, $context);
        $state = (array)($context['continuity']['state'] ?? []);
        foreach ($review['changes'] ?? [] as $index => $record) {
            if (!is_array($record) || !is_string($record['entity_id'] ?? null) || !is_string($record['field'] ?? null)) continue;
            $key = $record['entity_id'] . ':' . $record['field'];
            $before = $state[$key] ?? null;
            if (array_key_exists('before', $record) && $record['before'] !== $before) {
                $review['audit_corrections'][] = ['original'=>$record, 'field'=>'before', 'corrected'=>$before,
                    'reason'=>'旧值由服务端状态表读取，模型旧值仅保留诊断；正文变化仍须独立核验'];
            }
            $review['changes'][$index]['before'] = $before;
            $after = $record['after'] ?? null;
            $state[$key] = is_bool($after) || is_int($after) || is_float($after)
                ? json_encode($after, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) : $after;
        }
        return $review;
    }
}
