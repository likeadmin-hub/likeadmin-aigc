<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;
use app\common\service\app\aigc_short_drama\vnext\ApplicationWorkflow as Workflow;
use app\common\service\app\aigc_short_drama\vnext\ReceiptBuffer;
use app\common\service\power\MarketTextModelRuntimeService as Provider;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class ShortDramaVnextWorkerTest extends TestCase
{
    /** @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRealWorkerPublishesReadableVersionAndPreservesReceiptsAfterRollbackSwitch(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Opt in to local DB fixtures');
        require __DIR__ . '/../fixtures/short_drama_vnext_fake_provider.php';
        (new \think\App())->initialize();
        $task = 'vnext_worker_' . bin2hex(random_bytes(8)); $tenant = 2000000723; $user = 7;
        $scope = ['tenant_id' => $tenant, 'user_id' => $user];
        $ownedTasks = [$task];
        Db::startTrans();
        try {
            $project = Db::name('aigc_short_drama_project')->insertGetId($scope + ['title' => '测试项目', 'prompt' => '保留结尾',
                'last_task_id' => $task, 'current_agent_run_id' => 'agent_' . $task, 'generation_settings_json' => '{}']);
            $request = ['_pipeline_version' => 1, '_generation_version' => 3, 'prompt' => '保留结尾', 'multi_episode' => false,
                'episode_count' => 1, '_vnext_style_snapshot' => ['id' => '123', 'name' => '写实', 'prompt' => '自然光'], 'model_selections' => ['script_plan' => ['id' => 'frozen_model']], 'quality_review_enabled' => true];
            Db::name('aigc_short_drama_script_task')->insert($scope + ['project_id' => $project, 'task_id' => $task,
                'prompt' => '保留结尾', 'request_json' => json_encode($request), 'result_json' => '{}']);
            $input = Workflow::input($tenant, $user, $project, $task, $request,
                ['id' => 'frozen_model', 'context_window' => 32768, 'max_output_tokens' => 8192],
                ['stage_timeout_seconds' => 100, 'lease_seconds' => 150, 'deadline_seconds' => 1800]);
            $store = Workflow::store($tenant, $user, $task); $store->create('project:' . $project, $input, time() + 1800);
            // Rollback of enrollment must not reroute already saved jobs into the old engine.
            \think\facade\Config::set(['vnext' => ['enabled' => false]], 'short_drama');
            $run = new \ReflectionMethod(Service::class, 'executeScriptPlanTask'); $run->setAccessible(true);
            $row = Db::name('aigc_short_drama_script_task')->where('task_id', $task)->find();
            Provider::$failSettlementOnce = true;
            $paused = $run->invoke(null, $tenant, $user, $row, static function () {});
            self::assertSame('pending', $paused['status']); // Public task API normalizes queued to pending.
            self::assertSame('queued', Db::name('aigc_short_drama_script_task')->where('task_id', $task)->value('status'));
            self::assertSame('reconciling', $paused['generation_snapshot']['execution_status']);
            self::assertCount(1, Provider::$requests);
            self::assertFalse(Service::runStoryPlanningTask($tenant, $user, $task), 'Backoff must not spin or call the provider');
            Db::name('aigc_short_drama_vnext_job')->where($scope + ['task_id' => $task])->update(['update_time' => time() - 31]);
            self::assertTrue(Service::runStoryPlanningTask($tenant, $user, $task));
            self::assertSame(1, Provider::$recoveries);
            $result = Service::scriptPlanDetail($tenant, $user, $task, $project);
            self::assertSame('success', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
            self::assertCount(3, Provider::$requests);
            self::assertSame('ready', $result['generation_snapshot']['document_status']);
            self::assertStringEndsWith('结尾保留。', $result['result']['storyboard'][0]['dialogue']);
            self::assertGreaterThan(1000, mb_strlen($result['result']['storyboard'][0]['dialogue']));
            self::assertSame('frozen_model', Provider::$requests[0]['model_selection']['id']);
            $saved = Db::name('aigc_short_drama_script_task')->where('task_id', $task)->find();
            self::assertSame('6.00', $saved['user_charge_points']);
            self::assertSame(6, json_decode($store->snapshot()['budget_json'], true)['user_points']);
            $second = Service::scriptPlanDetail($tenant, $user, $task, $project);
            self::assertSame($result['result'], $second['result']);
            $readVersion = new \ReflectionMethod(Service::class, 'currentPlanVersion'); $readVersion->setAccessible(true);
            $versionView = $readVersion->invoke(null, $tenant, $user, $project);
            self::assertSame($result['result']['canonical_document'], $versionView['plan']['canonical_document']);
            self::assertNull($versionView['storyboard'][0]['recommended_duration_seconds']);
            $readProduction = new \ReflectionMethod(Service::class, 'currentProjectPlanRaw'); $readProduction->setAccessible(true);
            self::assertSame($result['result'], $readProduction->invoke(null, $tenant, $user, $project));
            self::assertSame($saved['result_json'], Db::name('aigc_short_drama_script_task')->where('task_id', $task)->value('result_json'));
            $run->invoke(null, $tenant, $user, $saved, static function () {});
            self::assertCount(3, Provider::$requests);
            self::assertSame(1, Db::name('aigc_short_drama_plan_version')->where('task_id', $task)->count());
            self::assertSame(1, Db::name('aigc_short_drama_vnext_revision')->where('task_id', $task)->count());
            self::assertSame('succeeded', Service::cancel($tenant, $user, $task)['generation_snapshot']['execution_status']);
            $document = $result['result']['canonical_document']; $unit = $document['scenes'][0]['units'][0];
            self::assertContains('generate_images', $result['generation_snapshot']['allowed_actions']);
            $targets = $result['production_handoff']['targets'];
            self::assertCount(2, $targets); // Scene + narration-only shot; no invented subject is required.
            self::assertTrue($targets[0]['can_generate']); self::assertFalse($targets[1]['can_generate']);
            $imageRequest = ['project_id' => $project, 'task_id' => $task, 'task_type' => 'scene_image',
                'scene_id' => $targets[0]['target_id'], 'expected_revision_id' => $document['revision_id'],
                'model_id' => 'market_image_model:fixture', 'ratio' => '9:16', 'prompt' => 'FORGED PROMPT',
                '_canonical_image' => ['prompt' => 'FORGED SNAPSHOT'], 'reference_images' => ['https://invalid.example/forged.png']];
            $quote = Service::estimateShotGenerationTask($tenant, $user, $imageRequest);
            self::assertSame('0.00', $quote['user_charge_points']);
            $image = Service::createShotGenerationTask($tenant, $user, $imageRequest);
            self::assertSame('success', $image['status'], json_encode($image, JSON_UNESCAPED_UNICODE));
            self::assertCount(1, \app\common\service\power\MarketImageModelRuntimeService::$requests);
            $submitted = \app\common\service\power\MarketImageModelRuntimeService::$requests[0];
            self::assertStringNotContainsString('FORGED', $submitted['prompt']);
            self::assertStringStartsWith("写实\n自然光", $submitted['prompt']);
            self::assertEmpty($submitted['reference_images']);
            self::assertSame($document['content_hash'], $image['assets'][0]['meta']['canonical_trace']['document_hash']);
            self::assertSame($image['task_id'], Service::createShotGenerationTask($tenant, $user, $imageRequest)['task_id']);
            self::assertCount(1, \app\common\service\power\MarketImageModelRuntimeService::$requests);
            $handoff = Service::scriptPlanDetail($tenant, $user, $task, $project)['production_handoff'];
            self::assertSame($image['assets'][0]['id'], $handoff['targets'][0]['asset']['id']);
            self::assertTrue($handoff['targets'][1]['can_generate']);
            $shotRequest = ['project_id' => $project, 'task_id' => $task, 'task_type' => 'shot_image',
                'shot_id' => $targets[1]['target_id'], 'expected_revision_id' => $document['revision_id'],
                'model_id' => 'market_image_model:fixture', 'ratio' => '9:16'];
            \app\common\service\power\MarketImageModelRuntimeService::$limit = 0;
            try { Service::estimateShotGenerationTask($tenant, $user, $shotRequest); self::fail('Unverified reference capacity accepted'); }
            catch (\RuntimeException $e) { self::assertSame(422, $e->getCode()); }
            self::assertCount(1, \app\common\service\power\MarketImageModelRuntimeService::$requests);
            \app\common\service\power\MarketImageModelRuntimeService::$limit = 8;
            $frame = Service::createShotGenerationTask($tenant, $user, $shotRequest);
            self::assertSame('success', $frame['status'], json_encode($frame, JSON_UNESCAPED_UNICODE));
            self::assertCount(2, \app\common\service\power\MarketImageModelRuntimeService::$requests);
            self::assertSame([$image['assets'][0]['id']], $frame['input_asset_ids']);
            self::assertSame($frame['request']['provider_input_hash'], $frame['assets'][0]['meta']['canonical_trace']['provider_input_hash']);
            self::assertSame($unit['unit_id'], $frame['request']['params']['_canonical_image']['trace']['unit_refs'][0]['unit_id']);
            // Another tenant/user cannot price or dispatch this project's content.
            try { Service::estimateShotGenerationTask($tenant, $user + 1, $imageRequest); self::fail('Foreign owner accepted'); }
            catch (\Exception $e) { self::assertNotSame('', $e->getMessage()); }

            Db::name('aigc_short_drama_generation_task')->where('task_id', $frame['task_id'])->update(['status' => 'canceled']);
            $retriedFrame = Service::retryGenerationTask($tenant, $user, $frame['task_id']);
            self::assertSame('success', $retriedFrame['status']);
            self::assertNotSame($frame['task_id'], $retriedFrame['task_id']);
            self::assertSame($retriedFrame['task_id'], Service::retryGenerationTask($tenant, $user, $frame['task_id'])['task_id']);
            self::assertCount(3, \app\common\service\power\MarketImageModelRuntimeService::$requests, 'Duplicate explicit retries must not submit twice');
            self::assertSame('ready', Service::scriptPlanDetail($tenant, $user, $task, $project)['generation_snapshot']['document_status']);
            $edit = ['project_id' => $project, 'task_id' => $task, 'expected_revision_id' => $document['revision_id'],
                'canonical_patches' => [['unit_id' => $unit['unit_id'], 'before_hash' => $unit['text_hash'], 'text' => $unit['text'] . '新说明。']]];
            $revision = Service::saveStoryboard($tenant, $user, $edit); $ownedTasks[] = $revision['task_id'];
            self::assertNotSame($task, $revision['task_id']);
            self::assertSame('draft', $revision['generation_snapshot']['document_status']);
            self::assertSame($revision['task_id'], Service::saveStoryboard($tenant, $user, $edit)['task_id'], 'Repeated save must reuse the revision');
            $newRow = Db::name('aigc_short_drama_script_task')->where('task_id', $revision['task_id'])->find();
            $revised = $run->invoke(null, $tenant, $user, $newRow, static function () {});
            self::assertSame('success', $revised['status'], json_encode($revised, JSON_UNESCAPED_UNICODE));
            self::assertCount(5, Provider::$requests, 'Manual content must skip creative regeneration');
            self::assertSame($unit['unit_id'], $revised['result']['canonical_document']['scenes'][0]['units'][0]['unit_id']);
            self::assertSame($unit['text'] . '新说明。', $revised['result']['canonical_document']['scenes'][0]['units'][0]['text']);
            self::assertSame($image['assets'][0]['id'], $revised['production_handoff']['targets'][0]['asset']['id'], 'Unchanged scene state reuses managed media across text revisions');
            self::assertNull($revised['production_handoff']['targets'][1]['asset'], 'Old shot images cannot imply new content coverage');
            self::assertSame(3, Db::name('aigc_short_drama_asset')->where($scope + ['project_id' => $project])->count(), 'Historical media is never deleted on revision');
            Db::name('aigc_short_drama_generation_task')->where('task_id', $frame['task_id'])->update(['status' => 'failed']);
            try { Service::retryGenerationTask($tenant, $user, $frame['task_id']); self::fail('Old media retry submitted after revision'); }
            catch (\RuntimeException $e) { self::assertSame(409, $e->getCode()); }
            self::assertSame('ready', $revised['generation_snapshot']['document_status']);

            try { Service::createShotGenerationTask($tenant, $user, $shotRequest); self::fail('Stale revision dispatched'); }
            catch (\RuntimeException $e) { self::assertSame(409, $e->getCode()); }
            self::assertCount(3, \app\common\service\power\MarketImageModelRuntimeService::$requests);
            self::assertSame($saved['result_json'], Db::name('aigc_short_drama_script_task')->where('task_id', $task)->value('result_json'));
            self::assertNotContains('revise_content' , Service::scriptPlanDetail($tenant, $user, $task, $project)['generation_snapshot']['allowed_actions']);
        } finally {
            $directory = runtime_path() . 'short_drama_vnext_receipts';
            $buffer = new ReceiptBuffer($directory);
            $paths = [];
            foreach (Db::name('aigc_short_drama_vnext_stage')->where($scope)->whereIn('task_id', $ownedTasks)->select()->toArray() as $stage) {
                $key = $buffer->key($scope + ['task_id' => $stage['task_id'], 'stage_key' => $stage['stage_key']], $stage['input_hash']);
                $paths[] = $directory . '/' . $key . '.json';
                $paths[] = $directory . '/' . hash('sha256', 'provider-result:' . $key) . '.json';
            }
            Db::rollback();
            foreach ($paths as $path) if (is_file($path)) unlink($path);
        }
    }
}
