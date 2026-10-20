<?php

namespace app\common\service\decorate;

use RuntimeException;

/** Versioned, tenant-independent defaults; applying them is restricted to new templates. */
class MobilePresetService
{
    public static function logicAi(): array
    {
        $value = json_decode((string)file_get_contents(__DIR__ . '/presets/logic-ai.json'), true);
        if (!is_array($value) || ($value['preset_code'] ?? '') !== 'logic-ai' || count($value['pages'] ?? []) !== 4) {
            throw new RuntimeException('默认移动端模板文件不完整');
        }
        return $value;
    }
}
