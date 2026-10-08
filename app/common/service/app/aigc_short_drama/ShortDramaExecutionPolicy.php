<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;
use think\facade\Db;

/** A shared durable cap across generation, normalization and all review layers. */
final class ShortDramaExecutionPolicy
{
    public static function limit(array $request): int
    {
        $duration = (float)(ShortDramaEpisodeDuration::policy($request)['target_seconds'] ?? 120);
        return min(512, max(96, (int)ceil($duration / 60) * 32));
    }

    public static function assertAdmission(array $request, int $existingCalls): void
    {
        if ((int)($request['_input_contract_version'] ?? 0) < 9 || !empty($request['multi_episode'])) return;
        if ($existingCalls >= self::limit($request)) throw new RuntimeException('本集生成与核验已达到统一调用预算，已完成内容和已付费结果保留；请缩小未完成范围后继续',429);
    }

    /** Call inside the short receipt-claim transaction, never across provider I/O. */
    public static function lockTask(int $tenant, int $user, string $task): array
    {
        $scope=['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$task];
        $row=Db::name('aigc_short_drama_script_task')->where($scope)->lock(true)->field('request_json')->find();
        return json_decode((string)($row['request_json'] ?? ''),true) ?: [];
    }

    public static function admit(int $tenant, int $user, string $task, array $request): void
    {
        $scope=['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$task];
        if ((int)($request['_input_contract_version']??0)<9 || !empty($request['multi_episode'])) return;
        $calls=Db::name('aigc_short_drama_planning_unit')->where($scope)->where('attempt','>',0)->count();
        self::assertAdmission($request,$calls);
    }
}
