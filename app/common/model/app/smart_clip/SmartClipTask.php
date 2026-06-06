<?php

namespace app\common\model\app\smart_clip;

use app\common\model\app\AppBaseModel;
use think\facade\Db;

class SmartClipTask extends AppBaseModel
{
    protected $name = 'smart_clip_task';
    protected $append = ['duration'];
    protected $json = [
        'materials',
        'introduce_card',
        'pack_rules',
        'process_rules',
        'struct_layers',
        'subtitle',
        'provider_payload',
    ];
    protected $jsonAssoc = true;

    public function getDurationAttr($value, $data): int
    {
        if (!self::hasDurationColumn()) {
            return 0;
        }
        return (int)($data['duration'] ?? 0);
    }

    public static function hasDurationColumn(): bool
    {
        static $checked = null;
        if ($checked !== null) {
            return $checked;
        }
        try {
            $fields = array_column(Db::query('SHOW COLUMNS FROM `la_smart_clip_task`'), 'Field');
            $checked = in_array('duration', $fields, true);
        } catch (\Throwable) {
            $checked = false;
        }
        return $checked;
    }
}
