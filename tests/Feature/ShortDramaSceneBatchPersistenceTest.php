<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{ShortDramaSceneBatch as Batch, ShortDramaPlanningUnit as Unit,
    ShortDramaScriptGeneration as Script, ShortDramaStructuredResponse as Response};
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/ShortDramaSceneBatchTest.php';

/** Synthetic task rows roll back; the provider boundary is local and never touches a real wallet. */
class ShortDramaSceneBatchPersistenceTest extends TestCase
{
    private const TENANT = 2000000740;
    private bool $transaction = false;
    private string $task;
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Opt in to transactional local fixtures');
        (new \think\App())->initialize(); Db::startTrans(); $this->transaction = true;
        $this->task = 'batch_test_' . bin2hex(random_bytes(8));
        Db::name('aigc_short_drama_script_task')->insert(['tenant_id' => self::TENANT, 'user_id' => 7,
            'task_id' => $this->task, 'project_id' => 0, 'status' => 'running', 'request_json' => '{}']);
    }
    protected function tearDown(): void { if ($this->transaction) Db::rollback(); }

    private function snapshot(): array
    {
        return ['version' => 1, 'signature' => 'same-skeleton', 'part_size' => 4, 'groups' => [[0, 1], [2, 3]]];
    }

    public function testGroupingIsFrozenAndOwnershipIsCheckedWithoutPaidUnits(): void
    {
        $first = Unit::freezeSceneBatches(self::TENANT, 7, $this->task, $this->snapshot());
        $next = $this->snapshot(); $next['part_size'] = 2; $next['groups'] = [];
        self::assertSame($first, Unit::freezeSceneBatches(self::TENANT, 7, $this->task, $next));
        $row = Db::name('aigc_short_drama_planning_unit')->where('task_id', $this->task)->find();
        self::assertSame('checkpoint', $row['status']); self::assertSame(0, (int)$row['attempt']);
        self::assertSame('{}', $row['request_json']);
        foreach ([[self::TENANT + 1, 7], [self::TENANT, 8]] as [$tenant, $user]) {
            try { Unit::freezeSceneBatches($tenant, $user, $this->task, $next); self::fail('Wrong owner'); }
            catch (\RuntimeException $error) { self::assertSame(403, $error->getCode()); }
        }
        Unit::prepareExplicitRetry(self::TENANT, 7, $this->task);
        self::assertSame($row, Db::name('aigc_short_drama_planning_unit')->where('id', $row['id'])->find());
        $next['signature'] = 'changed-skeleton';
        $this->expectExceptionCode(409);
        Unit::freezeSceneBatches(self::TENANT, 7, $this->task, $next);
    }

    public function testAlreadyStartedSingleSceneUnitsKeepTheWholeTaskOnOriginalPath(): void
    {
        Db::name('aigc_short_drama_planning_unit')->insert(['tenant_id' => self::TENANT, 'user_id' => 7,
            'task_id' => $this->task, 'unit_key' => 'v3_policy2_timed_scene_1_1', 'status' => 'received',
            'request_json' => '{"content":"original-paid-input"}', 'result_json' => '{"result":{"content":"paid-result"}}']);
        $before = Db::name('aigc_short_drama_planning_unit')->where('task_id', $this->task)->find();
        $plan = Unit::freezeSceneBatches(self::TENANT, 7, $this->task, $this->snapshot());
        self::assertSame([], $plan['groups']);
        self::assertSame($before, Db::name('aigc_short_drama_planning_unit')->where('id', $before['id'])->find());
    }

    public function testDispatchPauseAndRejectedSceneRecoveryReuseEveryReceivedPaidUnit(): void
    {
        $plan = ShortDramaSceneBatchTest::fixture(); $request = ShortDramaSceneBatchTest::request();
        $text = '{"user_prompt":"只交信A，信B在柜内，第十集揭晓"}';
        $messages = ['system_prompt' => '保留全部剧情与对白', 'content' => $text, '_stage_content' => $text];
        $calls = [];
        $provider = function ($key, $input) use ($plan, &$calls): array {
            return Unit::call(self::TENANT, 7, $this->task, 'v3_policy2_' . substr($key, 3), $input,
                static function () use ($key, $input, $plan, &$calls) {
                    $calls[] = $key;
                    if (str_contains($key, 'skeleton')) $payload = $plan;
                    else {
                        $context = Response::decode(['content' => $input['content']]);
                        $specs = $context['required_shots'];
                        if (!isset($context['scene_groups'])) foreach ($specs as &$spec) $spec['scene_ref_id'] = $context['scene_beat']['scene_ref_id'];
                        unset($spec);
                        $payload = ['storyboard' => array_map(static fn($spec) => ShortDramaSceneBatchTest::shot($spec, $plan), $specs)];
                        if ($key === 'v3_timed_batch_1_2') $payload['storyboard'][1]['recommended_duration_seconds'] = 7;
                    }
                    return ['result' => ['content' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'finish_reason' => 'stop',
                        'billing' => ['user_charge_points' => 9]]];
                }, true);
        };
        $run = fn() => Script::generate($request, ['context_window' => 65536, 'max_output_tokens' => 8192], $messages,
            $provider, null, null, fn($snapshot) => Unit::freezeSceneBatches(self::TENANT, 7, $this->task, $snapshot));
        $result = null; $turns = 0;
        do {
            $before = count($calls); $turns++;
            try { $result = Unit::withinDispatch(self::TENANT, 7, $this->task, 1, $run); }
            catch (\RuntimeException $error) { self::assertSame(425, $error->getCode()); }
            self::assertLessThanOrEqual(1, count($calls) - $before);
            self::assertLessThan(5, $turns);
        } while ($result === null);
        self::assertSame(['v3_timed_skeleton_0', 'v3_timed_batch_1_2', 'v3_timed_scene_2_1'], $calls);
        self::assertSame(['s1_1', 's2_1'], array_column($result['payload']['storyboard'], 'shot_id'));
        $rows = Db::name('aigc_short_drama_planning_unit')->where('task_id', $this->task)->order('id')->select()->toArray();
        self::assertCount(4, $rows); // one non-billable checkpoint + three paid receipts
        self::assertCount(3, array_filter($rows, static fn($r) => $r['status'] === 'received' && (int)$r['attempt'] === 1));
        self::assertSame($result['payload'], $run()['payload']);
        self::assertCount(3, $calls);
        self::assertSame($rows, Db::name('aigc_short_drama_planning_unit')->where('task_id', $this->task)->order('id')->select()->toArray());
    }
}
