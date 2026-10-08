<?php
namespace app\common\service\app\aigc_short_drama;

/** State references belong to the server. A model proposes evidence-backed
 * changes; a separate semantic check decides whether the transition is valid. */
final class ShortDramaContinuityState
{
    public static function prepare(array $review, array $context): array
    {
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
