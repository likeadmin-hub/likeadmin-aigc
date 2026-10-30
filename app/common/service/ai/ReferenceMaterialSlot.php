<?php

namespace app\common\service\ai;

use InvalidArgumentException;

/** Optional occurrence identity, independent of storage identity and provider fields. */
final class ReferenceMaterialSlot
{
    public static function id(array $asset): string
    {
        $value = $asset['reference_slot_id'] ?? '';
        if ($value === '') return '';
        if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $value)) {
            throw new InvalidArgumentException('参考素材槽位标识格式不正确');
        }
        return $value;
    }

    public static function distinct(array $left, array $right): bool
    {
        $a = self::id($left);
        $b = self::id($right);
        return $a !== '' && $b !== '' && $a !== $b;
    }

    /** Combine graph/selector aliases without collapsing independent uses. */
    public static function merge(array ...$groups): array
    {
        $result = [];
        foreach ($groups as $references) foreach ($references as $reference) {
            if (!is_array($reference)) continue;
            self::id($reference);
            $duplicate = null;
            foreach ($result as $index => $existing) {
                if (self::semanticKey($reference) !== self::semanticKey($existing)
                    || self::distinct($reference, $existing)) continue;
                if (self::sameSource($reference, $existing)) { $duplicate = $index; break; }
                if (self::id($reference) !== '' && self::id($reference) === self::id($existing)) {
                    throw new InvalidArgumentException('同一参考素材槽位不能绑定不同文件');
                }
            }
            if ($duplicate === null) { $result[] = $reference; continue; }
            $existing = $result[$duplicate];
            // Graph aliases can add ownership without replacing explicit roles.
            $merged = array_replace($reference, $existing);
            if ((int)($existing['asset_id'] ?? 0) <= 0 && (int)($reference['asset_id'] ?? 0) > 0) {
                foreach (['asset_id', 'uri', 'url', 'storage_scope', 'storage_engine', 'storage_domain'] as $key) {
                    if (isset($reference[$key])) $merged[$key] = $reference[$key];
                }
            }
            $slot = self::id($existing) ?: self::id($reference);
            if ($slot !== '') $merged['reference_slot_id'] = $slot;
            $result[$duplicate] = $merged;
        }
        return $result;
    }

    private static function semanticKey(array $asset): string
    {
        $type = (string)($asset['type'] ?? '');
        $role = (string)($asset['role'] ?? '');
        $role = match ($role) {
            '', 'reference', 'workflow_reference', 'agent_dependency' => 'reference_' . $type,
            'first_frame' => 'first_frame_image', 'last_frame' => 'last_frame_image',
            default => $role,
        };
        return $type . '|' . (string)($asset['field'] ?? '') . '|' . $role;
    }

    private static function sameSource(array $left, array $right): bool
    {
        $a = (int)($left['asset_id'] ?? 0); $b = (int)($right['asset_id'] ?? 0);
        if ($a > 0 && $b > 0) return $a === $b;
        $keys = static fn(array $asset): array => array_filter([
            trim((string)($asset['uri'] ?? '')), trim((string)($asset['url'] ?? '')),
        ], static fn(string $value): bool => $value !== '');
        return array_intersect($keys($left), $keys($right)) !== [];
    }
}
