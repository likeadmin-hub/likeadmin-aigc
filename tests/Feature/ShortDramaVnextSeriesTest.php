<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;
use app\common\service\app\aigc_short_drama\ShortDramaEpisodeService as Episodes;
use app\common\service\app\aigc_short_drama\vnext\ApplicationWorkflow as Workflow;
use app\common\service\app\aigc_short_drama\vnext\ReceiptBuffer;
use app\common\service\app\aigc_short_drama\vnext\SeriesContext;
use app\common\service\app\aigc_short_drama\vnext\PlanningTrace;
use app\common\service\app\aigc_short_drama\vnext\CanvasSeriesBridge;
use app\common\service\power\MarketTextModelRuntimeService as Provider;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class ShortDramaVnextSeriesTest extends TestCase
{
    public static function cohorts(): array { return [[false, false], [true, false], [true, true], [true, false, true]]; }

    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     * @dataProvider cohorts
     */
    public function testConfirmedSeriesUsesStableIdentitiesPublishedHistoryAndExplicitDependencyRecheck(bool $withTrace, bool $withRevision, bool $canvas = false): void
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
            if ($withTrace) {
                $request['prompt'] = '陈默是调查员。第一集发现书信，第二集辨认印记。';
                $request['_source_trace'] = \app\common\service\app\aigc_short_drama\vnext\SourceTrace::capture('input_' . $task, $task, $request['prompt'], 'inspiration');
                $trace = \app\common\service\app\aigc_short_drama\vnext\PlanningTrace::build($request['_source_trace'], [
                    ['quote' => '第一集发现书信', 'kind' => 'event', 'episode_numbers' => [1]],
                    ['quote' => '第二集辨认印记', 'kind' => 'event', 'episode_numbers' => [2]],
                    ['quote' => '陈默是调查员', 'kind' => 'invariant', 'episode_numbers' => []],
                ], 2);
                $request['confirmed_story_snapshot']['content_trace'] = $trace; $plan['content_trace'] = $trace;
                foreach ($plan['episodes'] as $index => &$episode) $episode['source_events'] = [['requirement_id' => $trace['requirements'][$index]['id'], 'field' => 'story_outline', 'quote' => $episode['story_outline']]];
                unset($episode);
            }
            Db::name('aigc_short_drama_script_task')->insert($scope + ['project_id' => $project, 'task_id' => $task, 'status' => 'success',
                'prompt' => $request['prompt'], 'request_json' => json_encode($request), 'result_json' => json_encode($plan)]);
            \think\facade\Config::set(['generation_version' => 3, 'vnext' => ['enabled' => true, 'series_enabled' => true, 'tenant_ids' => [$tenant],
                'models' => ['frozen_model' => ['stage_timeout_seconds' => 100, 'lease_seconds' => 150, 'deadline_seconds' => 1800]]]], 'short_drama');
            $created = Service::createScriptPlan($tenant, $user, ['prompt' => '第一集发现书信，第二集辨认印记。', 'multi_episode' => true,
                'episode_count' => 2, 'model_selections' => ['script_plan' => ['id' => 'frozen_model']], '_source_trace' => ['text' => '伪造来源']]);
            $enrolled = json_decode(Db::name('aigc_short_drama_script_task')->where($scope + ['task_id' => $created['task_id']])->value('request_json'), true);
            self::assertSame('第一集发现书信，第二集辨认印记。', $enrolled['_source_trace']['text']);
            self::assertSame(hash('sha256', $enrolled['prompt']), $enrolled['_source_trace']['hash']);
            self::assertArrayNotHasKey('_pipeline_version', $enrolled, 'Story confirmation remains a planning step');
            self::assertCount(0, Provider::$requests);
            if ($withRevision) {
                foreach (['修改第2集，辨认印记时保持沉默', '修改第2集，不再保持沉默，辨认印记后说出发现'] as $instruction) {
                $beforeTrace = $plan['content_trace'];
                $params = ['task_id' => $task, 'message' => $instruction, 'mode' => 'revise_plan',
                    'draft_version' => 0, 'edit_stage' => 'episodes', 'submission_key' => 'trace_revision_' . $task,
                    '_trace_revision' => ['source' => ['text' => '伪造来源']]];
                $revision = Service::message($tenant, $user, $params);
                $again = Service::message($tenant, $user, $params);
                self::assertSame($revision['task_id'], $again['task_id']);
                $request = json_decode(Db::name('aigc_short_drama_script_task')->where($scope + ['task_id' => $revision['task_id']])->value('request_json'), true);
                self::assertSame($params['message'], $request['_trace_revision']['source']['text']);
                self::assertSame([2], $request['_trace_revision']['source']['episode_numbers']);
                self::assertSame($beforeTrace, $request['_trace_revision']['base']);
                self::assertCount(0, Provider::$requests, 'Message capture must not add a provider call');
                $plan['content_trace'] = PlanningTrace::outlineTrace($request);
                $task = $revision['task_id'];
                // Simulate the completed planning response; the episode worker below is real.
                Db::name('aigc_short_drama_script_task')->where($scope + ['task_id' => $task])->update(['status' => 'success', 'result_json' => json_encode($plan)]);
                }
                self::assertCount(3, PlanningTrace::sources($plan['content_trace']));
            }
            if ($canvas) {
                // No enrollment while switches are off, and no migration after legacy episode one.
                $policy=\think\facade\Config::get('short_drama');
                \think\facade\Config::set(['generation_version'=>3,'vnext'=>['enabled'=>false,'series_enabled'=>false]],'short_drama');
                self::assertNull(CanvasSeriesBridge::create($tenant,$user,$project,$task,1,0,42));
                self::assertSame(0,Db::name('aigc_short_drama_episode_task')->where($scope+['project_id'=>$project])->count());
                \think\facade\Config::set($policy,'short_drama');
                self::assertNull(CanvasSeriesBridge::create($tenant,$user,$project,$task,2,0,42));
                $firstChild=CanvasSeriesBridge::create($tenant,$user,$project,$task,1,0,42);
                self::assertSame($firstChild['task_id'],CanvasSeriesBridge::create($tenant,$user,$project,$task,1,0,42)['task_id']);
                self::assertTrue(CanvasSeriesBridge::manual($tenant,$user,$project));
                self::assertSame(0,Db::name('aigc_short_drama_episode_job')->where($scope+['project_id'=>$project])->count());
                foreach ([[$tenant,$user,$project,$task,1,0,43],[$tenant,$user+1,$project,$task,1,0,42],[$tenant+1,$user,$project,$task,1,0,42],[$tenant,$user,$project,'wrong-outline',1,0,42]] as $foreign) {
                    try {CanvasSeriesBridge::create(...$foreign);self::fail('Foreign canvas binding accepted');}
                    catch (\RuntimeException $error) {self::assertSame(409,$error->getCode());}
                }
                $rows=Episodes::lists($tenant,$user,$project)['lists'];
            } else $rows = Episodes::start($tenant, $user, ['project_id' => $project, 'task_id' => $task, 'draft_version' => 0])['lists'];
            self::assertCount(2, $rows);
            $snapshot = json_decode(Db::name('aigc_short_drama_episode_task')->where('id', $rows[0]['id'])->value('series_json'), true);
            self::assertSame(1, $snapshot['request']['_vnext_series']['schema_version']);
            $rawRows = Db::name('aigc_short_drama_episode_task')->where($scope + ['project_id' => $project])->order('episode_number')->select()->toArray();
            $foreign = $rawRows[0]; $foreign['user_id']++;
            $changed = $rawRows[0]; $changed['outline_json'] = '{}';
            foreach ([$foreign, $changed, $rawRows[1]] as $invalid) {
                try { SeriesContext::prepare($snapshot['request']['_vnext_series'], $invalid, $snapshot['plan']); self::fail('Invalid or unpublished dependency accepted'); }
                catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
            }
            self::assertCount(0, Provider::$requests);
            // Turning off enrollment cannot reroute an already confirmed series.
            \think\facade\Config::set(['generation_version' => 3, 'vnext' => ['enabled' => false, 'series_enabled' => false]], 'short_drama');
            self::assertSame(1, Episodes::tick($tenant, $project));
            $list = Episodes::lists($tenant, $user, $project)['lists'];
            self::assertSame('success', $list[0]['status'], json_encode($list, JSON_UNESCAPED_UNICODE));
            self::assertSame('pending', $list[1]['status']); self::assertCount(3, Provider::$requests);
            $first = Service::scriptPlanDetail($tenant, $user, $list[0]['task_id']);
            if ($withRevision) {
                $firstInput = json_decode(Provider::$requests[0]['content'], true);
                self::assertCount(1, $firstInput['input_history'], 'Second-episode instruction must not affect the first');
                self::assertArrayNotHasKey($request['_trace_revision']['source']['source_id'], $firstInput['sources']);
                self::assertCount(4, $firstInput['sources'], 'Only original input and three local outline fields belong to episode one');
            }
            self::assertCount(1, $first['result']['narrative_ledger']['delta']);
            if ($canvas) {
                self::assertSame(0,Episodes::tick($tenant,$project),'Canvas must not automatically dispatch episode two');
                self::assertCount(3,Provider::$requests);
                self::assertSame('',Db::name('aigc_short_drama_episode_task')->where('id',$rows[1]['id'])->value('task_id'));
                self::assertSame(0,(int)Db::name('aigc_short_drama_episode_task')->where('id',$rows[1]['id'])->value('queue_job_id'));
                $secondChild=CanvasSeriesBridge::create($tenant,$user,$project,$task,2,0,42);
                self::assertSame($secondChild['task_id'],CanvasSeriesBridge::create($tenant,$user,$project,$task,2,0,42)['task_id']);
                self::assertCount(3,Provider::$requests,'Explicit creation saves a task before Provider I/O');
            }
            self::assertSame(1, Episodes::tick($tenant, $project));
            $list = Episodes::lists($tenant, $user, $project)['lists'];
            self::assertSame(['success', 'success'], array_column($list, 'status'), json_encode($list, JSON_UNESCAPED_UNICODE));
            self::assertCount(6, Provider::$requests);
            $second = Service::scriptPlanDetail($tenant, $user, $list[1]['task_id']);
            self::assertSame(array_column($first['result']['canonical_document']['entities'], 'entity_id'), array_column($second['result']['canonical_document']['entities'], 'entity_id'));
            $secondInput = json_decode(Provider::$requests[3]['content'], true);
            if ($withRevision) {
                self::assertCount(3, $secondInput['input_history']);
                self::assertSame($params['message'], $secondInput['input_history'][2]['text']);
                self::assertSame('修改第2集，辨认印记时保持沉默', $secondInput['input_history'][1]['text']);
                $reviewInput = json_decode(Provider::$requests[5]['content'], true);
                self::assertSame($secondInput['input_history'], $reviewInput['input_history'], 'Review sees the same immutable revision inputs');
            }
            $inherited = array_values(array_filter($secondInput['entities'], static fn($e) => $e['kind'] === 'prop'))[0];
            self::assertSame('书信', $inherited['name']); self::assertSame('封面有印记的书信', $inherited['description']);
            self::assertNotSame('p99', $inherited['ref']); self::assertNotContains('p99', $inherited['aliases']);
            self::assertSame($first['result']['narrative_ledger']['delta'][0]['fact_id'], $secondInput['previous_narrative']['world_facts'][0]['fact_id']);
            self::assertCount($withTrace ? 4 : 3, $secondInput['events']); self::assertCount($withRevision ? 2 : ($withTrace ? 1 : 0), $secondInput['requirements']);
            if ($withRevision) {
                $check = $secondInput['requirements'][1];
                self::assertSame('revision_instructions', $check['kind']);
                self::assertCount(2, $check['source_refs'], 'Successive changes form one ordered check, not competing hard constraints');
                self::assertSame($params['message'], $check['source_refs'][1]['quote']);
                $report = $second['result']['coverage_report'];
                $coverage = array_column($report['coverage'], null, 'id');
                self::assertSame($check['source_refs'], $coverage[$check['id']]['source_refs']);
                self::assertNotEmpty($coverage[$check['id']]['unit_refs']);
            }
            if ($withTrace) {
                self::assertSame([$trace['requirements'][1]['id']], $secondInput['events'][3]['requirement_ids']);
                self::assertSame($plan['episodes'][1]['story_outline'], $secondInput['events'][3]['text']);
                self::assertSame('陈默是调查员', $secondInput['requirements'][0]['text']);
                self::assertSame($request['_source_trace']['hash'], $secondInput['events'][3]['source_hash']);
                self::assertNotContains($trace['requirements'][0]['id'], array_merge(...array_column($secondInput['events'], 'requirement_ids')));
            }
            $scene = array_values(array_filter($second['production_handoff']['targets'], static fn($target) => $target['task_type'] === 'scene_image'))[0];
            $imageRequest = ['project_id' => $list[1]['production_project_id'], 'task_id' => $list[1]['task_id'], 'task_type' => 'scene_image',
                'scene_id' => $scene['target_id'], 'expected_revision_id' => $second['result']['canonical_document']['revision_id'],
                'model_id' => 'market_image_model:fixture', 'ratio' => '9:16'];
            $image = Service::createShotGenerationTask($tenant, $user, $imageRequest);
            self::assertSame('success', $image['status']);
            $unit = $first['result']['canonical_document']['scenes'][0]['units'][0];
            $revised = Service::saveStoryboard($tenant, $user, ['project_id' => $list[0]['production_project_id'], 'task_id' => $list[0]['task_id'],
                'expected_revision_id' => $first['result']['canonical_document']['revision_id'],
                'canonical_patches' => [['unit_id' => $unit['unit_id'], 'before_hash' => $unit['text_hash'], 'text' => $unit['text'] . '他又看了一遍信封。']]]);
            self::assertSame('canceled', Episodes::cancel($tenant, $user, $list[0]['id'])['status']);
            self::assertSame('canceled', Workflow::store($tenant, $user, $revised['task_id'])->snapshot()['execution_status']);
            $saved = Episodes::detail($tenant, $user, $list[0]['id']);
            self::assertTrue($saved['has_saved_content']); self::assertSame(1, $saved['pipeline_version']);
            self::assertSame($revised['task_id'], $saved['task_id'], 'Canceled draft must not be hidden behind an older success');
            self::assertCount(6, Provider::$requests);
            self::assertSame($revised['task_id'], Episodes::retry($tenant, $user, $list[0]['id'])['task_id']);
            self::assertSame(1, Episodes::cancelAll($tenant, $user, $project)['canceled_count']);
            self::assertSame('canceled', Workflow::store($tenant, $user, $revised['task_id'])->snapshot()['execution_status']);
            self::assertSame($revised['task_id'], Episodes::retry($tenant, $user, $list[0]['id'])['task_id']);
            self::assertCount(6, Provider::$requests, 'Cancel/retry admission must not call the model');
            self::assertSame(1, Episodes::tick($tenant, $project));
            $list = Episodes::lists($tenant, $user, $project)['lists'];
            self::assertTrue($list[1]['needs_review']);
            self::assertSame($second['result'], Service::scriptPlanDetail($tenant, $user, $list[1]['task_id'])['result']);
            $stale = Service::scriptPlanDetail($tenant, $user, $list[1]['task_id']);
            self::assertContains('read_images', $stale['generation_snapshot']['allowed_actions']);
            self::assertNotContains('generate_images', $stale['generation_snapshot']['allowed_actions']);
            self::assertNotEmpty($stale['production_handoff']['blocked_reason']);
            self::assertNotContains(true, array_column($stale['production_handoff']['targets'], 'can_generate'));
            self::assertCount(1, array_filter($stale['production_handoff']['targets'], static fn($target) => !empty($target['asset'])));
            foreach (['estimateShotGenerationTask', 'createShotGenerationTask'] as $method) {
                try { Service::$method($tenant, $user, $imageRequest); self::fail('Stale episode accepted for production'); }
                catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
            }
            Db::name('aigc_short_drama_generation_task')->where('task_id', $image['task_id'])->update(['status' => 'failed']);
            try { Service::retryGenerationTask($tenant, $user, $image['task_id']); self::fail('Stale episode image retry accepted'); }
            catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
            self::assertCount(1, \app\common\service\power\MarketImageModelRuntimeService::$requests);
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
            // A prior episode changes after this episode's preflight, during its review call.
            $secondUnit = $checked['result']['canonical_document']['scenes'][0]['units'][0];
            $secondEdit = Service::saveStoryboard($tenant, $user, ['project_id' => $list[1]['production_project_id'], 'task_id' => $recheck['task_id'],
                'expected_revision_id' => $checked['result']['canonical_document']['revision_id'],
                'canonical_patches' => [['unit_id' => $secondUnit['unit_id'], 'before_hash' => $secondUnit['text_hash'], 'text' => $secondUnit['text'] . '他收好信封。']]]);
            Provider::$afterResponse = static function (array $input) use ($tenant, $user, $list): void {
                if (!isset($input['shot_plan'])) return;
                Provider::$afterResponse = null;
                $firstNow = Service::scriptPlanDetail($tenant, $user, $list[0]['task_id']);
                $unit = $firstNow['result']['canonical_document']['scenes'][0]['units'][0];
                $edit = Service::saveStoryboard($tenant, $user, ['project_id' => $list[0]['production_project_id'], 'task_id' => $list[0]['task_id'],
                    'expected_revision_id' => $firstNow['result']['canonical_document']['revision_id'],
                    'canonical_patches' => [['unit_id' => $unit['unit_id'], 'before_hash' => $unit['text_hash'], 'text' => $unit['text'] . '窗外响起脚步声。']]]);
                Service::streamScriptPlan($tenant, $user, ['project_id' => $list[0]['production_project_id'], 'task_id' => $edit['task_id']], static function () {}, true);
            };
            self::assertSame(1, Episodes::tick($tenant, $project));
            $failed = Workflow::store($tenant, $user, $secondEdit['task_id'])->snapshot();
            self::assertSame('needs_attention', $failed['execution_status']);
            self::assertSame(0, Db::name('aigc_short_drama_vnext_revision')->where($scope + ['task_id' => $secondEdit['task_id']])->count());
            $saved = Episodes::detail($tenant, $user, $list[1]['id']);
            self::assertTrue($saved['has_saved_content']); self::assertSame($secondEdit['task_id'], $saved['task_id']);
            self::assertSame('needs_attention', $saved['generation_execution_status']);
            Db::name('aigc_short_drama_episode_task')->where('id', $list[1]['id'])->update(['completed_once' => 0]);
            $initialFailure = Episodes::detail($tenant, $user, $list[1]['id']);
            self::assertFalse($initialFailure['ready']); self::assertTrue($initialFailure['has_saved_content']);
            self::assertSame($secondEdit['task_id'], $initialFailure['task_id']);
            Db::name('aigc_short_drama_episode_task')->where('id', $list[1]['id'])->update(['completed_once' => 1]);
            self::assertCount(14, Provider::$requests);
            self::assertSame(1, Episodes::tick($tenant, $project)); // Finalize the already published first revision.
            self::assertCount(14, Provider::$requests);
            $resume = Episodes::retry($tenant, $user, $list[1]['id']);
            self::assertNotSame($secondEdit['task_id'], $resume['task_id']);
            self::assertSame(1, Episodes::tick($tenant, $project));
            $fresh = Service::scriptPlanDetail($tenant, $user, $resume['task_id']);
            self::assertSame(json_decode($failed['candidate_json'], true)['document']['scenes'], $fresh['result']['canonical_document']['scenes']);
            self::assertCount(16, Provider::$requests);
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
