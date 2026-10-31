<?php
namespace app\common\service\app\aigc_image;

use think\Model;

/** Parent image apps may replay child facts when opening history. */
final class ImageAppTaskSync
{
    public static function finishTime(Model $task, array $childTimes, array $terminalStatuses): int
    {
        if (!in_array((string)$task['status'], $terminalStatuses, true)) return 0;
        if ($childTimes) return max($childTimes);
        // A missing old child timestamp must not redate a completed parent.
        if ((string)$task->getOrigin('status') === (string)$task['status'] && (int)$task['finish_time'] > 0) {
            return (int)$task['finish_time'];
        }
        return time();
    }

    public static function saveChangedFacts(Model $task): void
    {
        if (!$task->getChangedData()) return;
        $task->update_time = time();
        $task->save();
    }
}
