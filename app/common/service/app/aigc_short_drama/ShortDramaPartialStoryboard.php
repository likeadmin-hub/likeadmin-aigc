<?php
namespace app\common\service\app\aigc_short_drama;

/** Preserve only unambiguous, individually valid shots from a received part. */
final class ShortDramaPartialStoryboard
{
    /** A draft may display only the individually validated prefix before any gap. */
    public static function validPrefix(array $part, array $ids, callable $validate): array
    {
        $prefix = [];
        foreach ((array)($part['storyboard'] ?? []) as $index => $shot) {
            if (!is_array($shot) || ($shot['shot_id'] ?? null) !== ($ids[$index] ?? null)) break;
            try { $validate($shot, $index); }
            catch (\RuntimeException $error) {
                if (!in_array($error->getCode(), [409, 413, 422], true)) throw $error;
                break;
            }
            $prefix[] = $shot;
        }
        return $prefix;
    }

    /** Return field targets only for an ordered, unambiguous incomplete part. */
    public static function fieldTargets(array $part, array $ids, array $plan, array $beat, array $rule, ?array $durations, callable $validate): array
    {
        $shots = $part['storyboard'] ?? null;
        if (!is_array($shots) || !array_is_list($shots) || !$shots || count($shots) >= count($ids)) return [];
        $presentIds = []; $presentDurations = []; $indexes = []; $last = -1;
        foreach ($shots as $shot) {
            if (!is_array($shot)) return [];
            $index = array_search($shot['shot_id'] ?? null, $ids, true);
            if ($index === false || $index <= $last) return [];
            $last = $index;
            $indexes[] = $index;
            $presentIds[] = $ids[$index];
            if ($durations !== null) $presentDurations[] = $durations[$index];
        }
        return ShortDramaShotFieldRepair::targets($part, $presentIds, $plan, $beat, $rule,
            $durations === null ? null : $presentDurations,
            static fn($shot, $index) => $validate($shot, $indexes[$index]));
    }

    public static function accepted(array $part, array $ids, callable $validate): ?array
    {
        $shots = $part['storyboard'] ?? null;
        if (!is_array($shots) || !array_is_list($shots) || count($shots) >= count($ids)) return null;
        $accepted = []; $last = -1;
        foreach ($shots as $shot) {
            if (!is_array($shot)) return null;
            $index = array_search($shot['shot_id'] ?? null, $ids, true);
            // An unknown, repeated or reordered ID makes the response ambiguous.
            if ($index === false || $index <= $last) return null;
            $last = $index;
            try { $validate($shot, $index); }
            catch (\RuntimeException $error) {
                if (!in_array($error->getCode(), [409, 413, 422], true)) throw $error;
                return null;
            }
            $accepted[$index] = $shot;
        }
        return $accepted;
    }
}
