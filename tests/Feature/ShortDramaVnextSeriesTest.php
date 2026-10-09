<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;
use app\common\service\app\aigc_short_drama\ShortDramaEpisodeService as Episodes;
use app\common\service\app\aigc_short_drama\vnext\ApplicationWorkflow as Workflow;
use app\common\service\app\aigc_short_drama\vnext\ReceiptBuffer;
use app\common\service\power\MarketTextModelRuntimeService as Provider;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class ShortDramaVnextSeriesTest extends TestCase
{
    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testConfirmedSeriesUsesStableIdentitiesPublishedHistoryAndExplicitDependencyRecheck(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Opt in to local DB fixtures');
        require __DIR__ . '/../fixtures/short_drama_vnext_fake_provider.php';
        require __DIR__ . '/../fixtures/short_drama_vnext_fake_queue.php';
        (new \think\App())->initialize();
        $scope = ['tenant_id' => 2000000793, 'user_id' => 7]; $tenant = $scope['tenant_id']; $user = $scope['user_id'];
        $task = 'series_fixture_' . bin2hex(random_bytes(8));
        Db::startTrans();
        try {
            $project = Db::name('aigc_short_drama_project')->insertGetId($scope + ['title' => '追查真相', 'prompt' => '调查员在书房寻找线索。',
                'multi_episode' => 1, 'episode_count' => 2, 'last_task_id' => $task]);
            $story = ['title' => '追查真相', 'type_judgement' => '悬疑', 'core_theme' => '真相', 'story_outline' => '调查员逐步解开书房谜团。',
                'subjects' => [['id' => 'hero', 'name' => '陈默', 'description' => '调查员']],
                'locations' => [['id' => 'room', 'name' => '书房', 'description' => '旧书房']]];
            $plan = $story + ['episodes' => [
                ['episode_number' => 1, 'title' => '发现书信', 'story_outline' => '陈默在书房发现一封书信。', 'conflict_point' => '书信内容不全', 'ending_hook' => '信封藏着线索'],
                ['episode_number' => 2, 'title' => '发现印记', 'story_outline' => '陈默在书房辨认出信封上的印记。', 'conflict_point' => '印记来源不明', 'ending_hook' => '找到印记主人'],
            ]];
            $request = ['prompt' => '调查员在书房寻找线索。', 'workflow_variant' => 'story_outline_v2', 'multi_episode' => true,
                'episode_count' => 2, 'multi_episode_stage' => 'episodes', '_generation_version' => 3,
                'confirmed_story_snapshot' => $story, 'confirmed_story_version' => 0, 'model_selections' => ['script_plan' => ['id' => 'frozen_model']]];
            Db::name('aigc_short_drama_script_task')->insert($scope + ['project_id' => $project, 'task_id' => $task, 'status' => 'success',
                'prompt' => $request['prompt'], 'request_json' => json_encode($request), 'result_json' => json_encode($plan)]);
            \think\facade\Config::set(['generation_version' => 3, 'vnext' => ['enabled' => true, 'series_enabled' => true, 'tenant_ids' => [$tenant],
                'models' => ['frozen_model' => ['stage_timeout_seconds' => 100, 'lease_seconds' => 150, 'deadline_seconds' => 1800]]]], 'short_drama');
            $rows = Episodes::start($tenant, $user, ['project_id' => $project, 'task_id' => $task, 'draft_version' => 0])['lists'];
            self::assertCount(2, $rows);
            $snapshot = json_decode(Db::name('aigc_short_drama_episode_task')->where('id', $rows[0]['id'])->value('series_json'), true);
            self::assertSame(1, $snapshot['request']['_vnext_series']['schema_version']);
            // Turning off enrollment cannot reroute an already confirmed series.
            \think\facade\Config::set(['generation_version' => 3, 'vnext' => ['enabled' => false, 'series_enabled' => false]], 'short_drama');
            self::assertSame(1, Episodes::tick($tenant, $project));
            $list = Episodes::lists($tenant, $user, $project)['lists'];
            self::assertSame('success', $list[0]['status'], json_encode($list, JSON_UNESCAPED_UNICODE));
            self::assertSame('pending', $list[1]['status']); self::assertCount(3, Provider::$requests);
            $first = Service::scriptPlanDetail($tenant, $user, $list[0]['task_id']);
            self::assertCount(1, $first['result']['narrative_ledger']['delta']);
            self::assertSame(1, Episodes::tick($tenant, $project));
            $list = Episodes::lists($tenant, $user, $project)['lists'];
            self::assertSame(['success', 'success'], array_column($list, 'status'), json_encode($list, JSON_UNESCAPED_UNICODE));
            self::assertCount(6, Provider::$requests);
            $second = Service::scriptPlanDetail($tenant, $user, $list[1]['task_id']);
            self::assertSame(array_column($first['result']['canonical_document']['entities'], 'entity_id'), array_column($second['result']['canonical_document']['entities'], 'entity_id'));
            $secondInput = json_decode(Provider::$requests[3]['content'], true);
            self::assertSame($first['result']['narrative_ledger']['delta'][0]['fact_id'], $secondInput['previous_narrative']['world_facts'][0]['fact_id']);
            self::assertCount(3, $secondInput['events']); self::assertSame([], $secondInput['requirements']);
            $unit = $first['result']['canonical_document']['scenes'][0]['units'][0];
            $revised = Service::saveStoryboard($tenant, $user, ['project_id' => $list[0]['production_project_id'], 'task_id' => $list[0]['task_id'],
                'expected_revision_id' => $first['result']['canonical_document']['revision_id'],
                'canonical_patches' => [['unit_id' => $unit['unit_id'], 'before_hash' => $unit['text_hash'], 'text' => $unit['text'] . '他又看了一遍信封。']]]);
            self::assertSame(1, Episodes::tick($tenant, $project));
            $list = Episodes::lists($tenant, $user, $project)['lists'];
            self::assertTrue($list[1]['needs_review']);
            self::assertSame($second['result'], Service::scriptPlanDetail($tenant, $user, $list[1]['task_id'])['result']);
            $oldSecondTask = $list[1]['task_id'];
            $recheck = Episodes::retry($tenant, $user, $list[1]['id']);
            self::assertNotSame($oldSecondTask, $recheck['task_id']);
            self::assertSame($recheck['task_id'], Episodes::retry($tenant, $user, $list[1]['id'])['task_id']);
            self::assertSame(1, Episodes::tick($tenant, $project));
            $list = Episodes::lists($tenant, $user, $project)['lists'];
            self::assertFalse($list[1]['needs_review']); self::assertSame('success', $list[1]['status']);
            self::assertCount(10, Provider::$requests, 'Initial 3+3, edit 2, dependency recheck 2; never rewrite the second screenplay');
            $checked = Service::scriptPlanDetail($tenant, $user, $recheck['task_id']);
            self::assertSame($second['result']['script_lines'], $checked['result']['script_lines']);
            self::assertSame($second['result']['canonical_document']['scenes'], $checked['result']['canonical_document']['scenes']);
        } finally {
            $directory = runtime_path() . 'short_drama_vnext_receipts'; $buffer = new ReceiptBuffer($directory); $paths = [];
            foreach (Db::name('aigc_short_drama_vnext_stage')->where($scope)->select()->toArray() as $stage) {
                $key = $buffer->key($scope + ['task_id' => $stage['task_id'], 'stage_key' => $stage['stage_key']], $stage['input_hash']);
                $paths[] = $directory . '/' . $key . '.json'; $paths[] = $directory . '/' . hash('sha256', 'provider-result:' . $key) . '.json';
            }
            Db::rollback(); foreach ($paths as $path) if (is_file($path)) unlink($path);
        }
    }
}
