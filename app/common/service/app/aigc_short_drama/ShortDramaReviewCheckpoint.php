<?php
namespace app\common\service\app\aigc_short_drama;

use think\facade\Db;

/** Server snapshot, separate from immutable paid provider receipts and published versions. */
final class ShortDramaReviewCheckpoint
{
    public static function signature(array $base, array $request): string
    {
        // Execution attempts and provider tracing change during recovery; they
        // are not creative inputs. Model/prompt/source facts remain signed.
        foreach (['_generation_recovery_version','_execution_attempt','_prompt_requests','_submission_key','_submission_hash'] as $field) unset($request[$field]);
        return hash('sha256', json_encode((ShortDramaContentAcceptance::enabled($request) ? [3, ShortDramaContentAcceptance::VERSION, $base, $request] : [3, $base, $request]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function load(int $tenant, int $user, string $task): array
    {
        if ($task === '') return [];
        $json = Db::name('aigc_short_drama_planning_unit')->where(['tenant_id'=>$tenant, 'user_id'=>$user,
            'task_id'=>$task, 'unit_key'=>'checkpoint_review_v2', 'status'=>'checkpoint'])->value('result_json');
        $value = json_decode((string)$json, true);
        return is_array($value) && ($value['protocol_version'] ?? 0) === 3 ? $value : [];
    }

    public static function save(int $tenant, int $user, string $task, array $plan, int $reviewedShots, array $resume = []): void
    {
        if ($task === '') return;
        $scope = ['tenant_id'=>$tenant, 'user_id'=>$user, 'task_id'=>$task, 'unit_key'=>'checkpoint_review_v2'];
        Db::transaction(static function () use ($scope, $plan, $reviewedShots, $resume): void {
            $row = Db::name('aigc_short_drama_planning_unit')->where($scope)->lock(true)->find();
            $data = ['status'=>'checkpoint', 'attempt'=>0, 'request_json'=>'{}', 'error'=>'',
                'result_json'=>json_encode(['kind'=>'review_checkpoint', 'protocol_version'=>$resume ? 3 : 2,
                    'content_status'=>'draft', 'reviewed_shots'=>$reviewedShots, 'plan'=>$plan, 'resume'=>$resume], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'update_time'=>time()];
            if ($row) Db::name('aigc_short_drama_planning_unit')->where($scope)->update($data);
            else Db::name('aigc_short_drama_planning_unit')->insert($scope + $data + ['create_time'=>time()]);
        });
    }
}
