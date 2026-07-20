<?php

namespace app\common\model\app\aigc_video;

use app\common\model\app\AppBaseModel;
use think\facade\Db;

class AigcVideoTask extends AppBaseModel
{
    protected $name = 'aigc_video_task';
    protected $append = ['duration'];
    protected $json = ['reference_images', 'reference_assets', 'model_json', 'pricing_snapshot'];
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
            $fields = array_column(Db::query('SHOW COLUMNS FROM `la_aigc_video_task`'), 'Field');
            $checked = in_array('duration', $fields, true);
        } catch (\Throwable) {
            $checked = false;
        }
        return $checked;
    }
}
