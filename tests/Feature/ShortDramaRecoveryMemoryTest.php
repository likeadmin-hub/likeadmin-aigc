<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;
use app\common\service\app\aigc_short_drama\ShortDramaPlanningUnit as Unit;
use app\common\service\app\aigc_short_drama\ShortDramaWorkerMemory as Memory;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use think\facade\Log;

class ShortDramaRecoveryMemoryTest extends TestCase
{
    private bool $transaction = false;
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Transactional local DB test');
        (new \think\App())->initialize(); Db::startTrans(); $this->transaction = true;
    }
    protected function tearDown(): void { if ($this->transaction) Db::rollback(); }

    public function testRepeatedDiagnosticsDoNotGrowTheTaskOrWorkerMemory(): void
    {
        $task = 'test_memory_' . bin2hex(random_bytes(8));
        $scope = ['tenant_id'=>2000000719, 'user_id'=>7, 'task_id'=>$task];
        $request = json_encode(['prompt'=>'原文', '_prompt_task_id'=>$task]);
        Db::name('aigc_short_drama_script_task')->insert($scope + ['request_json'=>$request]);
        $record = new \ReflectionMethod(Service::class, 'recordScriptPromptRequest'); $record->setAccessible(true);
        $payload = ['content'=>str_repeat('完整场景原文', 10000), 'system_prompt'=>'测试'];
        Memory::release(); $before = memory_get_usage(true);
        for ($i=0; $i<60; $i++) {
            $record->invoke(null, $scope['tenant_id'], $scope['user_id'], ['_prompt_task_id'=>$task], $payload, 'test_scene_' . $i);
            Log::info('bounded worker test ' . $i);
            Memory::release();
        }
        self::assertSame($request, Db::name('aigc_short_drama_script_task')->where($scope)->value('request_json'));
        self::assertSame(60, Db::name('aigc_short_drama_prompt_request')->where($scope)->count());
        self::assertLessThan(16 * 1024 * 1024, memory_get_usage(true) - $before);
    }

    public function testCheckpointSurvivesReadAndFailureWithoutBecomingACompletePlan(): void
    {
        $task = 'test_preview_' . bin2hex(random_bytes(8));
        $scope = ['tenant_id'=>2000000719, 'user_id'=>7, 'task_id'=>$task];
        Db::name('aigc_short_drama_script_task')->insert($scope + ['status'=>'running']);
        Db::name('aigc_short_drama_script_task')->insert(array_replace($scope, ['tenant_id'=>2000000720]) + ['status'=>'running']);
        $persist = new \ReflectionMethod(Service::class, 'persistScriptPlanStreamState'); $persist->setAccessible(true);
        $history = ['title'=>'第二集','storyboard'=>[['shot_id'=>'s1_1','visual_description'=>'已完成场景']]];
        $persist->invoke(null, $scope['tenant_id'], $scope['user_id'], $task, '', '', '', 12, $history, 'v3_scene_2');
        Db::name('aigc_short_drama_script_task')->where($scope)->update(['status'=>'failed']);
        $stored = json_decode(Db::name('aigc_short_drama_script_task')->where($scope)->value('result_json'), true);
        self::assertSame($history, $stored['__script_checkpoint']);
        self::assertSame(12, $stored['__stream_revision']);
        self::assertArrayNotHasKey('storyboard', $stored);
        self::assertEmpty(Db::name('aigc_short_drama_script_task')->where(array_replace($scope, ['tenant_id'=>2000000720]))->value('result_json'));
    }

    public function testRestartReplaysReceiptsWithoutCallingProviderAgain(): void
    {
        $task = 'test_restart_' . bin2hex(random_bytes(8)); $calls = 0;
        $provider = static function () use (&$calls) { $calls++; return ['result'=>['content'=>'{"storyboard":[]}']]; };
        Unit::call(2000000719, 7, $task, 'v3_scene_1', ['content'=>'一'], $provider, true);
        $again = Unit::call(2000000719, 7, $task, 'v3_scene_1', ['content'=>'一'], $provider, true);
        self::assertTrue($again['_replayed']); self::assertSame(1, $calls);
        Db::name('aigc_short_drama_planning_unit')->insert(['tenant_id'=>2000000719,'user_id'=>7,'task_id'=>$task,
            'unit_key'=>'v3_scene_2','status'=>'running','attempt'=>1,'request_json'=>'{"content":"二"}']);
        try { Unit::call(2000000719, 7, $task, 'v3_scene_2', ['content'=>'二'], $provider, true); self::fail('Ambiguous submission replayed'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('中断', $e->getMessage()); }
        self::assertSame(1, $calls);
    }
}
