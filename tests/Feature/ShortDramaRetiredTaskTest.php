<?php
namespace tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaRetiredTask;
use PHPUnit\Framework\TestCase;

final class ShortDramaRetiredTaskTest extends TestCase
{
    public function testLegacyVersionsRemainWritable(): void
    {
        foreach ([[], ['_generation_version' => 3], ['_pipeline_version' => '0']] as $request) {
            self::assertFalse(ShortDramaRetiredTask::isRetired($request));
            ShortDramaRetiredTask::assertWritable($request);
        }
    }

    public function testRetiredAndUnknownVersionsCannotReplayInLegacyEngine(): void
    {
        foreach ([1, '1', 2, 'unknown'] as $version) {
            try { ShortDramaRetiredTask::assertWritable(['_pipeline_version' => $version]); self::fail('Retired task replay allowed'); }
            catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
        }
        self::assertTrue(ShortDramaRetiredTask::isRetired(['_vnext_series' => ['version' => 1]]));
    }

    public function testArchiveDoesNotDiscardTailDialogueOrUnicode(): void
    {
        $candidate = ['document' => ['entities' => [['entity_id' => 'person', 'canonical_name' => '林安']], 'scenes' => [
            ['units' => [['text' => '  第一场😀𠮷  '], ['text' => '我先解释。', 'speaker_id' => 'person']]],
            ['units' => [['text' => '明天见，这次我会先问清楚。', 'speaker_id' => 'person']]],
        ]]];
        self::assertSame("  第一场😀𠮷  \n林安：我先解释。\n林安：明天见，这次我会先问清楚。", ShortDramaRetiredTask::text($candidate));
    }

    public function testRetiredSeriesCannotCreateALegacyChildBeforeProviderSetup(): void
    {
        $method = new \ReflectionMethod(\app\common\service\app\aigc_short_drama\AigcShortDramaService::class, 'createScriptPlanOnce');
        $method->setAccessible(true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(409);
        $method->invoke(null, 1, 1, ['prompt' => '下一集'], 0, ['_vnext_series' => ['version' => 1]]);
    }

    public function testPublicReadAndRetryPreserveArchivedRecordsAndBilling(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Local DB opt-in required');
        (new \think\App())->initialize();
        $db = \think\facade\Db::class;
        $service = \app\common\service\app\aigc_short_drama\AigcShortDramaService::class;
        $tenant = 2000000791; $user = 7; $task = 'retired_test_' . bin2hex(random_bytes(8));
        $db::startTrans();
        try {
            $scope = ['tenant_id' => $tenant, 'user_id' => $user, 'task_id' => $task];
            $project = $db::name('aigc_short_drama_project')->insertGetId(['tenant_id' => $tenant, 'user_id' => $user, 'last_task_id' => $task]);
            $db::name('aigc_short_drama_script_task')->insert($scope + ['project_id' => $project, 'status' => 'failed',
                'request_json' => json_encode(['_pipeline_version' => 1]), 'result_json' => '{}', 'error' => 'reference error']);
            $candidate = ['document' => ['scenes' => [['units' => [['text' => '正文尾句完整保留。']]]]]];
            $db::name('aigc_short_drama_vnext_job')->insert($scope + ['lane_key' => $task, 'input_hash' => str_repeat('a',64),
                'input_json' => '{}', 'budget_json' => '{}', 'candidate_json' => json_encode($candidate),
                'execution_status' => 'needs_attention', 'deadline_at' => time(), 'create_time' => time(), 'update_time' => time()]);
            $before = $db::name('aigc_short_drama_script_task')->where($scope)->find();
            $archive = $db::name('aigc_short_drama_vnext_job')->where($scope)->find();
            $bills = $db::name('ai_consumption_log')->count();
            $view = $service::scriptPlanDetail($tenant, $user, $task, $project);
            self::assertTrue($view['retired_pipeline']);
            self::assertSame('正文尾句完整保留。', $view['retired_content']);
            foreach ([static fn() => $service::retry($tenant, $user, $task),
                static fn() => $service::message($tenant, $user, ['task_id' => $task, 'message' => '重新生成'])] as $write) {
                try { $write(); self::fail('Retired task was resubmitted'); }
                catch (\RuntimeException $error) { self::assertSame(409, $error->getCode()); }
            }
            try { $service::scriptPlanDetail($tenant + 1, $user, $task, $project); self::fail('Cross-tenant archive read accepted'); }
            catch (\Exception $error) { self::assertStringContainsString('任务不存在', $error->getMessage()); }
            self::assertSame($before, $db::name('aigc_short_drama_script_task')->where($scope)->find());
            self::assertSame($archive, $db::name('aigc_short_drama_vnext_job')->where($scope)->find());
            self::assertSame($bills, $db::name('ai_consumption_log')->count());
        } finally { $db::rollback(); }
    }
}
