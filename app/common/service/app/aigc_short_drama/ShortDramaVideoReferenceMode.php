<?php

namespace app\common\service\app\aigc_short_drama;

/** Choose contextual image references separately from first/last frames and timed keyframes. */
final class ShortDramaVideoReferenceMode
{
    public static function referenceMethod(array $capabilities, int $count): string
    {
        if ($count <= 0) return '';
        $methods = (array)($capabilities['generation_modes'] ?? []);
        foreach (['omni_reference', 'image_reference', 'multi_frame'] as $method) {
            if ($count >= 2 && in_array($method, $methods, true) && self::acceptsImages($capabilities, $method, $count)) return $method;
        }
        if (in_array('image_to_video', $methods, true)) return 'image_to_video';
        foreach (['omni_reference', 'image_reference'] as $method) {
            if ($count === 1 && in_array($method, $methods, true) && self::acceptsImages($capabilities, $method, $count)) return $method;
        }
        return '';
    }

    private static function acceptsImages(array $capabilities, string $method, int $count): bool
    {
        return self::referenceLimit($capabilities, $method, $count) >= min(2, $count);
    }

    public static function referenceLimit(array $capabilities, string $method, int $limit): int
    {
        $modes = (array)($capabilities['material_modes'] ?? []);
        if ($modes === [] || !in_array($method, ['omni_reference', 'image_reference', 'multi_frame'], true)) return $limit;
        $available = 0;
        foreach ($modes as $mode) {
            if (($mode['method'] ?? '') !== $method) continue;
            $minimum = 0; $maximum = 0; $unsupported = false;
            foreach ((array)($mode['fields'] ?? []) as $field) {
                $required = !empty($field['required']) ? max(1, (int)($field['minimum'] ?? 0)) : 0;
                if (($field['kind'] ?? '') !== 'image' || ($field['role'] ?? 'reference') !== 'reference') {
                    if ($required > 0) $unsupported = true;
                    continue;
                }
                $minimum += $required;
                $maximum += (int)($field['maximum'] ?? $field['minimum'] ?? 0);
            }
            if (!$unsupported && $limit >= $minimum) $available = max($available, $maximum);
        }
        return min($limit, $available);
    }
}
