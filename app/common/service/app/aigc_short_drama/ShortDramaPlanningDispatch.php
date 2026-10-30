<?php
namespace app\common\service\app\aigc_short_drama;

use think\facade\Db;

/** Walk past occupied/backing-off tasks without loading the whole queue. */
final class ShortDramaPlanningDispatch
{
    public static function tick(string $taskId = '', int $tenantId = 0, ?callable $run = null, ?callable $stopping = null): bool
    {
        $query = Db::name('aigc_short_drama_script_task')->where('delete_time', 0)->whereIn('status', ['pending','queued','running'])
            ->whereRaw("JSON_VALID(request_json) AND COALESCE(JSON_EXTRACT(request_json, '$.episode_id'), 0) = 0")
            ->whereRaw("(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(request_json, '$.source')), '') <> 'market_file_qa_parse' OR JSON_TYPE(JSON_EXTRACT(request_json, '$._file_parse_model')) = 'OBJECT' OR JSON_UNQUOTE(JSON_EXTRACT(request_json, '$._file_parse_fallback.state')) IN ('queued','running'))")
            ->where(function ($q) {
                $q->whereLike('request_json', '%story_outline_v2%')->whereOr(function ($v3) {
                    $v3->whereRaw("JSON_EXTRACT(request_json, '$._generation_version') >= 3")
                        ->whereRaw("COALESCE(JSON_EXTRACT(request_json, '$.multi_episode'), false) = false");
                });
            });
        if ($taskId !== '') $query->where('task_id', $taskId);
        if ($tenantId > 0) $query->where('tenant_id', $tenantId);
        $run ??= [AigcShortDramaService::class, 'runStoryPlanningTask'];
        $cursor = null;
        do {
            $page = clone $query;
            if ($cursor) $page->where(function ($q) use ($cursor) {
                $q->where('update_time', '>', $cursor['update_time'])->whereOr(function ($same) use ($cursor) {
                    $same->where('update_time', $cursor['update_time'])->where('id', '>', $cursor['id']);
                });
            });
            $rows = $page->order('update_time,id')->limit(20)->field('id,update_time,tenant_id,user_id,task_id')->select()->toArray();
            foreach ($rows as $row) {
                if ($stopping && $stopping()) return false;
                if (!ShortDramaPlanningUnit::ready((int)$row['tenant_id'], (int)$row['user_id'], $row['task_id'])) continue;
                if ($run((int)$row['tenant_id'], (int)$row['user_id'], $row['task_id'])) return true;
            }
            $cursor = $rows ? $rows[count($rows) - 1] : null;
        } while (count($rows) === 20);
        return false;
    }
}
