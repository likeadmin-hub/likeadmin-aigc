<?php
namespace app\common\service\app\aigc_short_drama;

/** Exact arithmetic is server-owned; never send 500 complete outlines to an LLM to sum durations. */
final class ShortDramaDurationAllocation
{
    public static function allocate(array $episodes, float $total, array $fixed = []): array
    {
        $remaining = (int)round($total * 1000); $out = []; $weights = [];
        foreach ($episodes as $index => $episode) {
            $number = (int)($episode['episode_number'] ?? $index + 1);
            if (isset($fixed[$number])) {
                $ms = (int)round((float)$fixed[$number] * 1000);
                if ($ms <= 0) throw new \RuntimeException('指定分集时长必须大于零', 422);
                $out[$index] = $ms; $remaining -= $ms;
            } else {
                $weights[$index] = max(1, mb_strlen((string)($episode['story_outline'] ?? '')));
            }
        }
        if ($remaining < count($weights) || (!$weights && $remaining !== 0)) throw new \RuntimeException('整部时长不足以满足指定分集时长', 422);
        $weightSum = array_sum($weights); $allocated = 0;
        foreach ($weights as $index => $weight) {
            $out[$index] = 1 + (int)floor(($remaining - count($weights)) * $weight / $weightSum);
            $allocated += $out[$index];
        }
        $left = $remaining - $allocated;
        foreach ($weights as $index => $_) { if ($left-- <= 0) break; $out[$index]++; }
        ksort($out);
        return array_values(array_map(static fn($ms) => $ms / 1000, $out));
    }
}
