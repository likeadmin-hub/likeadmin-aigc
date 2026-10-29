<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;
use think\facade\Db;

/** A user-edited draft is separate from paid receipts and the formal plan. */
final class ShortDramaEditableDraft
{
    private const KEY = 'checkpoint_user_draft_v1';
    private const LIMITS = ['visual_description'=>2000, 'composition'=>500, 'camera_movement'=>500, 'action'=>1000, 'dialogue'=>1000];

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function token(array $plan, int $revision): string
    {
        return hash('sha256', self::json(['revision'=>$revision, 'plan'=>$plan]));
    }

    public static function view(int $tenant, int $user, string $task, array $checkpoint): array
    {
        $row = Db::name('aigc_short_drama_planning_unit')->where(['tenant_id'=>$tenant, 'user_id'=>$user, 'task_id'=>$task,
            'unit_key'=>self::KEY, 'status'=>'checkpoint'])->find();
        $saved = $row ? json_decode((string)$row['result_json'], true) : null;
        $plan = is_array($saved['plan'] ?? null) ? $saved['plan'] : ShortDramaScriptPreview::project($checkpoint);
        $revision = (int)($saved['revision'] ?? 0);
        if (!is_array($plan['storyboard'] ?? null) || !$plan['storyboard']) return [];
        return ['plan'=>$plan, 'token'=>self::token($plan, $revision), 'edited'=>!empty($saved['edits'])];
    }

    public static function save(int $tenant, int $user, string $task, int $project, string $token, string $shotId, array $fields): array
    {
        if ($token === '' || $shotId === '' || !$fields || array_diff(array_keys($fields), array_keys(self::LIMITS))) throw new RuntimeException('草稿修改内容无效', 422);
        foreach ($fields as $field=>$value) {
            if (!is_string($value) || mb_strlen($value) > self::LIMITS[$field]
                || ($field === 'visual_description' && trim($value) === '')) throw new RuntimeException('草稿字段为空或超过允许长度：' . $field, 422);
        }
        return Db::transaction(static function () use ($tenant, $user, $task, $project, $token, $shotId, $fields): array {
            $scope=['tenant_id'=>$tenant, 'user_id'=>$user, 'task_id'=>$task];
            $owner = Db::name('aigc_short_drama_script_task')->where($scope + ['delete_time'=>0])->lock(true)->find();
            if (!$owner || ($project > 0 && (int)$owner['project_id'] !== $project)) throw new RuntimeException('任务不存在', 404);
            if (!in_array($owner['status'], ['failed','canceled'], true)) throw new RuntimeException('当前任务状态已变化，请刷新后再编辑草稿', 409);
            $request = json_decode((string)$owner['request_json'], true) ?: [];
            if ((int)($request['_generation_version'] ?? 0) < 3 || !empty($request['multi_episode']) || ShortDramaStoryWorkflow::enabled($request)) {
                throw new RuntimeException('当前任务暂不支持编辑草稿', 409);
            }
            $rowScope=$scope+['unit_key'=>self::KEY];
            $row=Db::name('aigc_short_drama_planning_unit')->where($rowScope)->lock(true)->find();
            $saved=$row ? json_decode((string)$row['result_json'], true) : null;
            if ($row && (($row['status'] ?? '') !== 'checkpoint' || !is_array($saved['plan'] ?? null))) throw new RuntimeException('草稿状态无效，请刷新后重试', 409);
            $state=json_decode((string)$owner['result_json'], true) ?: [];
            $checkpoint=(array)($state['__script_checkpoint'] ?? []);
            if (!$checkpoint) $checkpoint=ShortDramaScriptPreview::fromReceipts(Db::name('aigc_short_drama_planning_unit')->where($scope)
                ->where('status','received')->order('id')->field('unit_key,status,error,result_json')->select()->toArray());
            $plan=is_array($saved['plan'] ?? null) ? $saved['plan'] : ShortDramaScriptPreview::project($checkpoint);
            $revision=(int)($saved['revision'] ?? 0);
            if (!hash_equals(self::token($plan,$revision),$token)) throw new RuntimeException('草稿已更新，请刷新后再保存', 409);
            $shots=$plan['storyboard'] ?? null;
            if (!is_array($shots) || !array_is_list($shots)) throw new RuntimeException('没有可编辑的已保存分镜', 422);
            $ids=[];$index=null;
            foreach ($shots as $i=>$shot) {
                if (!is_array($shot) || !is_string($shot['shot_id'] ?? null) || $shot['shot_id'] === '' || isset($ids[$shot['shot_id']])) throw new RuntimeException('草稿分镜标识不明确，请先核对', 422);
                $ids[$shot['shot_id']]=true;if ($shot['shot_id'] === $shotId) $index=$i;
            }
            if ($index === null) throw new RuntimeException('草稿分镜不存在，请刷新', 404);
            $edits=(array)($saved['edits'] ?? []);
            foreach ($fields as $field=>$after) {
                $before=$edits[$shotId][$field]['before'] ?? ($shots[$index][$field] ?? '');
                if (!is_string($before)) throw new RuntimeException('草稿字段格式无效，请先核对', 422);
                if ($after === $before) unset($edits[$shotId][$field]);
                else $edits[$shotId][$field]=['before'=>$before,'after'=>$after];
                $plan['storyboard'][$index][$field]=$after;
            }
            if (empty($edits[$shotId])) unset($edits[$shotId]);
            $revision++;
            $payload=['version'=>1,'revision'=>$revision,'plan'=>$plan,'edits'=>$edits];
            if ($row) Db::name('aigc_short_drama_planning_unit')->where($rowScope)->update(['result_json'=>self::json($payload),'update_time'=>time()]);
            else Db::name('aigc_short_drama_planning_unit')->insert($rowScope+['status'=>'checkpoint','attempt'=>0,'request_json'=>'{}',
                'result_json'=>self::json($payload),'create_time'=>time(),'update_time'=>time()]);
            return ['plan'=>$plan,'token'=>self::token($plan,$revision),'edited'=>!empty($edits)];
        });
    }

    /** Merge only where the accepted generated shot still matches the edited base. */
    public static function merge(array $plan, array $saved, array $request): array
    {
        $edits=(array)($saved['edits'] ?? []);
        if (!$edits) return $plan;
        $shots=$plan['storyboard'] ?? null;
        if (!is_array($shots) || !array_is_list($shots)) throw new RuntimeException('已编辑草稿无法与生成结果对应，请查看草稿后处理', 409);
        $indexes=[];
        foreach ($shots as $i=>$shot) {
            $id=is_array($shot) ? ($shot['shot_id'] ?? null) : null;
            if (!is_string($id) || $id === '' || isset($indexes[$id])) throw new RuntimeException('已编辑草稿与生成结果的分镜标识不明确', 409);
            $indexes[$id]=$i;
        }
        foreach ($edits as $id=>$changes) {
            if (!isset($indexes[$id]) || !is_array($changes)) throw new RuntimeException('已编辑草稿的分镜已变化，请查看草稿后处理', 409);
            $i=$indexes[$id];
            foreach ($changes as $field=>$change) {
                if (!isset(self::LIMITS[$field]) || !is_array($change) || !is_string($change['before'] ?? null) || !is_string($change['after'] ?? null)
                    || ($shots[$i][$field] ?? '') !== $change['before']) throw new RuntimeException('已编辑草稿与生成结果不一致，请查看草稿后处理', 409);
                $plan['storyboard'][$i][$field]=$change['after'];
            }
        }
        $dialogue=ShortDramaDialogueContract::prepare($plan);
        if ($dialogue['issues']) throw new RuntimeException('草稿对白缺少明确说话人，请查看草稿后处理', 422);
        $plan=$dialogue['payload'];
        if (ShortDramaEpisodeDuration::active($request)) ShortDramaTimedScriptGeneration::assertCompletePlan($plan, $request);
        return ShortDramaReviewOutcome::pending($plan, $request, '已合并用户草稿修改，请重新复核后继续制作');
    }

    public static function load(int $tenant, int $user, string $task): array
    {
        $value=Db::name('aigc_short_drama_planning_unit')->where(['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$task,
            'unit_key'=>self::KEY,'status'=>'checkpoint'])->value('result_json');
        return is_string($value) ? (json_decode($value,true) ?: []) : [];
    }
}
