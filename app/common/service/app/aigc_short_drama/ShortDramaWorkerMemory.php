<?php
namespace app\common\service\app\aigc_short_drama;

use think\facade\Log;

final class ShortDramaWorkerMemory
{
    /** Daemons have no HTTP request-end hook to flush lazy logs or collect cycles. */
    public static function release(): void
    {
        if (PHP_SAPI !== 'cli') return;
        Log::save();
        gc_collect_cycles();
    }
}
