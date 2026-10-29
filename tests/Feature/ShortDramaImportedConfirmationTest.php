<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;
use app\common\service\app\aigc_short_drama\ShortDramaImportedScript as Imported;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class ShortDramaImportedConfirmationTest extends TestCase
{
    private bool $transaction = false;
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Transactional database test');
        (new \think\App())->initialize();
        Db::startTrans(); $this->transaction = true;
    }
    protected function tearDown(): void { if ($this->transaction) Db::rollback(); }

    public function testConfirmationIsLocalLosslessAndIdempotent(): void
    {
        $scope = ['tenant_id' => 2000000719, 'user_id' => 7];
        $task = 'import_confirm_' . bin2hex(random_bytes(8));
        $plan = ['title' => '旧信', 'type_judgement' => '悬疑', 'core_theme' => '真相', 'story_outline' => '通过旧信寻找故人',
            'multi_episode' => true, 'episode_count' => 2, 'storyboard' => [],
            'subjects' => [['id' => 's1', 'name' => '调查员']], 'locations' => [['id' => 'l1', 'name' => '旧宅']],
            'episodes' => [
                ['episode_number' => 1, 'title' => '信', 'story_outline' => '发现旧信', 'conflict_point' => '署名矛盾', 'ending_hook' => '找到地址', 'source_content' => " 原文甲\n", 'source_range' => ['start_line' => 1, 'end_line' => 5]],
                ['episode_number' => 2, 'title' => '人', 'story_outline' => '找到故人', 'conflict_point' => '拒绝相认', 'ending_hook' => '交出日记', 'source_content' => str_repeat('完整原文乙。', 10000)]]];
        $story = Imported::storyDraft($plan);
        $request = ['workflow_variant' => 'story_outline_v2', 'multi_episode' => true, 'episode_count' => 2,
            'multi_episode_stage' => 'story', 'source' => 'market_file_qa_parse', '_imported_outline_snapshot' => $plan,
            'episode_duration_policy'=>['version'=>1,'source'=>'default','target_seconds'=>120,'min_seconds'=>60,
                'max_seconds'=>180,'target_planning_version'=>1], '_upload_timing_rule_snapshot'=>['enabled'=>true,
                'target_seconds'=>120,'min_seconds'=>60,'max_seconds'=>180]];
        $project = Db::name('aigc_short_drama_project')->insertGetId($scope + ['title' => '旧信', 'last_task_id' => $task, 'multi_episode' => 1, 'episode_count' => 2]);
        $id = Db::name('aigc_short_drama_script_task')->insertGetId($scope + ['project_id' => $project, 'task_id' => $task,
            'status' => 'success', 'prompt' => '按原文策划', 'request_json' => json_encode($request), 'result_json' => json_encode($story),
            'config_snapshot' => '{}', 'billing_status' => 'settled', 'user_charge_points' => '5.00']);
        $source = Db::name('aigc_short_drama_script_task')->where('id', $id)->find();
        $method = new \ReflectionMethod(Service::class, 'confirmImportedStory'); $method->setAccessible(true);
        $result = $method->invoke(null, $scope['tenant_id'], $scope['user_id'], $source, $request, $story, ['draft_version' => 0]);
        $again = $method->invoke(null, $scope['tenant_id'], $scope['user_id'], $source, $request, $story, ['draft_version' => 0]);
        self::assertSame($result, $again);
        $saved = Db::name('aigc_short_drama_script_task')->where('task_id', $result['task_id'])->find();
        self::assertSame('success', $saved['status']);
        self::assertSame(0.0, (float)$saved['user_charge_points']);
        self::assertSame($plan['episodes'], json_decode($saved['result_json'], true)['episodes']);
        self::assertSame('episodes', json_decode($saved['request_json'], true)['multi_episode_stage']);
        self::assertSame($request['episode_duration_policy'],json_decode($saved['request_json'], true)['episode_duration_policy']);
        self::assertSame($request['_upload_timing_rule_snapshot'],json_decode($saved['request_json'], true)['_upload_timing_rule_snapshot']);
        self::assertSame($source, Db::name('aigc_short_drama_script_task')->where('id', $id)->find());
        self::assertSame(2, Db::name('aigc_short_drama_script_task')->where($scope)->where('project_id', $project)->count());
    }
}
