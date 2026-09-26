<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaPlanningUnit as Unit;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Transactional fixtures only, no external provider or billing calls. */
class ShortDramaPlanningUnitPersistenceTest extends TestCase
{
    private bool $transaction = false;
    private string $task;
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Opt in to transactional local DB tests');
        (new \think\App())->initialize(); Db::startTrans(); $this->transaction = true;
        $this->task = 'test_receipt_' . bin2hex(random_bytes(8));
    }
    protected function tearDown(): void { if ($this->transaction) Db::rollback(); }
    public function testReceivedResponseIsReusedAndChangedContextCannotReplayIt(): void
    {
        $calls = 0;
        $provider = static function () use (&$calls) { $calls++; return ['result' => ['content' => '{"ok":true}']]; };
        $first = Unit::call(2000000719, 7, $this->task, 'v3_script', ['content' => 'same'], $provider);
        self::assertSame($first, Unit::call(2000000719, 7, $this->task, 'v3_script', ['content' => 'same'], $provider));
        self::assertSame(1, $calls);
        $this->expectExceptionCode(409);
        Unit::call(2000000719, 7, $this->task, 'v3_script', ['content' => 'changed'], $provider);
    }
    public function testAmbiguousReadTimeoutDoesNotAutomaticallyResubmit(): void
    {
        $calls = 0;
        for ($i = 0; $i < 2; $i++) {
            try { Unit::call(2000000719, 7, $this->task, 'v3_script', [], static function () use (&$calls) { $calls++; throw new \RuntimeException('Operation timed out after 120000 milliseconds'); }); }
            catch (\RuntimeException $error) { self::assertStringContainsString('timed out', $error->getMessage()); }
        }
        self::assertSame(1, $calls);
        self::assertFalse(Unit::retryableBeforeSubmission('Operation timed out after 120000 milliseconds'));
        self::assertTrue(Unit::retryableBeforeSubmission('Could not resolve host: provider.test'));
        self::assertTrue(Unit::retryableBeforeSubmission('cURL error 6: Could not resolve host'));
        self::assertTrue(Unit::retryableBeforeSubmission('Network is unreachable'));
    }
    public function testConnectionEstablishmentFailureBacksOffBeforeRetry(): void
    {
        try { Unit::call(2000000719, 7, $this->task, 'v3_script', [], static function () { throw new \RuntimeException('Connection refused'); }); }
        catch (\RuntimeException $error) { self::assertSame(425, $error->getCode()); }
        self::assertFalse(Unit::ready(2000000719, 7, $this->task));
        $this->expectExceptionCode(425);
        Unit::call(2000000719, 7, $this->task, 'v3_script', [], static function () { self::fail('Backoff bypassed'); });
    }
    public function testCachedInvalidAuditEntersOneCorrectionAndBothReceiptsReplay(): void
    {
        $this->assertAuditReceiptReplay(true);
    }
    public function testRetiredQuotaResumeReadsOriginalReceiptWithoutMutatingIt(): void
    {
        $request = ['prompt'=>'原始剧情','series_context'=>['episode'=>1], '_prompt_task_id'=>$this->task];
        Db::name('aigc_short_drama_script_task')->insert(['tenant_id'=>2000000719,'user_id'=>7,
            'task_id'=>$this->task,'status'=>'running','request_json'=>json_encode($request)]);
        $receipt = ['result'=>['content'=>json_encode(['title'=>'故事','story_outline'=>'完整剧情',
            'subjects'=>[['id'=>'p']], 'locations'=>[['id'=>'l']], 'storyboard'=>[['shot_id'=>'1']]])]];
        Unit::call(2000000719,7,$this->task,'v3_script',['content'=>'不可重写的原始请求'],static fn()=>$receipt);
        $before = Db::name('aigc_short_drama_planning_unit')->where('task_id',$this->task)->find();
        for ($i=0;$i<2;$i++) self::assertSame($receipt,
            \app\common\service\app\aigc_short_drama\ShortDramaShotPolicy::legacyReceipt(2000000719,7,$request));
        self::assertNull(\app\common\service\app\aigc_short_drama\ShortDramaShotPolicy::legacyReceipt(2000000719,8,$request));
        self::assertNull(\app\common\service\app\aigc_short_drama\ShortDramaShotPolicy::legacyReceipt(2000000720,7,$request));
        self::assertSame($before, Db::name('aigc_short_drama_planning_unit')->where('task_id',$this->task)->find());
        $request['prompt']='改变剧情';
        $this->expectExceptionCode(409);
        \app\common\service\app\aigc_short_drama\ShortDramaShotPolicy::legacyReceipt(2000000719,7,$request);
    }
    public function testFailedCorrectionIsRetainedAndNeverResubmittedOnRepeatedRetry(): void
    {
        $this->assertAuditReceiptReplay(false);
    }
    /** @dataProvider retiredPolicyUnsafeReceipts */
    public function testPolicyChangeCannotBypassAnUnsafeOriginalUnit(string $status, string $finish, int $error): void
    {
        $request = ['prompt'=>'原剧情', '_prompt_task_id'=>$this->task];
        Db::name('aigc_short_drama_script_task')->insert(['tenant_id'=>2000000719,'user_id'=>7,
            'task_id'=>$this->task,'status'=>'running','request_json'=>json_encode($request)]);
        Db::name('aigc_short_drama_planning_unit')->insert(['tenant_id'=>2000000719,'user_id'=>7,
            'task_id'=>$this->task,'unit_key'=>'v3_script','status'=>$status,'attempt'=>3,
            'result_json'=>json_encode(['result'=>['content'=>'{}','finish_reason'=>$finish]])]);
        $before = Db::name('aigc_short_drama_planning_unit')->where('task_id',$this->task)->find();
        try {
            \app\common\service\app\aigc_short_drama\ShortDramaShotPolicy::legacyReceipt(2000000719,7,$request);
            self::fail('Unsafe receipt must not fall through to a new paid request');
        } catch (\RuntimeException $exception) {
            self::assertSame($error, $exception->getCode());
        }
        self::assertSame($before, Db::name('aigc_short_drama_planning_unit')->where('task_id',$this->task)->find());
    }
    public static function retiredPolicyUnsafeReceipts(): array
    {
        return [['running','',409], ['failed','',409], ['waiting','',409]];
    }
    public function testLegacyRevisionAndSceneReceiptsKeepTheirStageSpecificShapes(): void
    {
        $request = ['prompt'=>'原剧情','revision_target'=>['type'=>'shot_fields','id'=>'1'], '_prompt_task_id'=>$this->task];
        Db::name('aigc_short_drama_script_task')->insert(['tenant_id'=>2000000719,'user_id'=>7,
            'task_id'=>$this->task,'status'=>'running','request_json'=>json_encode($request)]);
        foreach (['v3_script'=>['storyboard'=>[['shot_id'=>'1','dialogue'=>'你好']]],
            'v3_skeleton'=>['scene_beats'=>[['scene_ref_id'=>'l','shot_count'=>1]]],
            'v3_scene_1_1_1'=>['storyboard'=>[['shot_id'=>'s1_1']]]] as $key=>$payload) {
            $receipt=['result'=>['content'=>json_encode($payload),'finish_reason'=>'stop']];
            Unit::call(2000000719,7,$this->task,$key,[],static fn()=>$receipt);
            self::assertSame($receipt, \app\common\service\app\aigc_short_drama\ShortDramaShotPolicy::legacyReceipt(2000000719,7,$request,$key));
        }
        $receipt=['result'=>['content'=>'{"storyboard":[','finish_reason'=>'length']];
        Unit::call(2000000719,7,$this->task,'v3_format_repair',[],static fn()=>$receipt);
        $cached=\app\common\service\app\aigc_short_drama\ShortDramaShotPolicy::legacyReceipt(2000000719,7,$request,'v3_format_repair');
        self::assertSame($receipt,$cached);
        $this->expectExceptionCode(413);
        \app\common\service\app\aigc_short_drama\ShortDramaStructuredResponse::decode($cached['result']);
    }
    private function assertAuditReceiptReplay(bool $canRepair): void
    {
        $plan = ['subjects' => [['id' => 'p1']], 'locations' => [],
            'storyboard' => [['shot_id' => '1', 'visual_description' => '甲捡起钥匙，推开大门。', 'dialogue' => '']]];
        $bad = ['summary' => '甲开门', 'changes' => [['entity_id' => 'p1', 'field' => 'item', 'before' => null,
            'after' => '钥匙', 'shot_id' => '1', 'quote' => '甲捡起...钥匙']], 'hooks' => [], 'warnings' => []];
        $fixed = $bad; if ($canRepair) $fixed['changes'][0]['quote'] = '甲捡起钥匙';
        $firstInput = \app\common\service\app\aigc_short_drama\ShortDramaContinuity::messages($plan, []);
        $keyFor = static fn($input) => 'v3_continuity_review_' . substr(hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE)), 0, 24);
        Unit::call(2000000719, 7, $this->task, $keyFor($firstInput), $firstInput, static fn() => ['result' => ['content' => json_encode($bad)]]);
        $paidCalls = 0;
        for ($retry = 0; $retry < 3; $retry++) {
            try {
                $ledger = \app\common\service\app\aigc_short_drama\ShortDramaContinuity::review($plan, [], 1,
                    function ($input) use ($keyFor, &$paidCalls, $fixed): array {
                        $receipt = Unit::call(2000000719, 7, $this->task, $keyFor($input), $input, static function () use (&$paidCalls, $fixed) {
                            $paidCalls++; return ['result' => ['content' => json_encode($fixed)]];
                        });
                        return json_decode($receipt['result']['content'], true);
                    });
                self::assertTrue($canRepair); self::assertSame('钥匙', $ledger['state']['p1:item']);
            } catch (\RuntimeException $error) { self::assertFalse($canRepair); self::assertSame(422, $error->getCode()); }
        }
        self::assertSame(1, $paidCalls);
        self::assertSame(2, Db::name('aigc_short_drama_planning_unit')->where(['tenant_id' => 2000000719, 'user_id' => 7, 'task_id' => $this->task, 'status' => 'received'])->count());
    }
}
