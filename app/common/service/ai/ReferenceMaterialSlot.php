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
}
