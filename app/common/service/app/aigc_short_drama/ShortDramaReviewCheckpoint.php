<?php
namespace app\common\service\app\aigc_short_drama;

use think\facade\Db;

/** Server snapshot, separate from immutable paid provider receipts and published versions. */
final class ShortDramaReviewCheckpoint
{
    public static function save(int $tenant, int $user, string $task, array $plan, int $reviewedShots): void
    {
        if ($task === '') return;
        $scope = ['tenant_id'=>$tenant, 'user_id'=>$user, 'task_id'=>$task, 'unit_key'=>'checkpoint_review_v2'];
        Db::transaction(static function () use ($scope, $plan, $reviewedShots): void {
            $row = Db::name('aigc_short_drama_planning_unit')->where($scope)->lock(true)->find();
            $data = ['status'=>'checkpoint', 'attempt'=>0, 'request_json'=>'{}', 'error'=>'',
                'result_json'=>json_encode(['kind'=>'review_checkpoint', 'protocol_version'=>2,
                    'content_status'=>'draft', 'reviewed_shots'=>$reviewedShots, 'plan'=>$plan], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'update_time'=>time()];
            if ($row) Db::name('aigc_short_drama_planning_unit')->where($scope)->update($data);
            else Db::name('aigc_short_drama_planning_unit')->insert($scope + $data + ['create_time'=>time()]);
        });
    }
}
