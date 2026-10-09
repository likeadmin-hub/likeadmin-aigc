<?php
namespace Tests\Feature;

use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionLog;
use app\common\service\power\MarketTextModelRuntimeService as Runtime;
use app\common\service\power\TextRequestNotSubmittedException;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ShortDramaAccountFixture as Fixture;
use think\facade\Db;

require_once dirname(__DIR__).'/fixtures/short_drama_account_fixture.php';

final class MarketTextAccountReservationTest extends TestCase
{
    private bool $transaction = false;
    private int $tenant = 2000000815;
    private int $user;

    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Opt in to private account fixtures');
        (new \think\App())->initialize();
        Db::startTrans(); $this->transaction = true;
        Db::name('tenant')->insert(['id'=>$this->tenant,'sn'=>'account_reservation_fixture','point_balance'=>20,'create_time'=>time(),'update_time'=>time()]);
        $this->user = (int)Db::name('user')->insertGetId(['tenant_id'=>$this->tenant,
            'sn'=>(string)random_int(10000000,99999999),'nickname'=>'account reservation fixture','user_money'=>40]);
    }
    protected function tearDown(): void { if ($this->transaction) Db::rollback(); }
    private function balances(float $tenant, float $user): void
    {
        self::assertSame($tenant,(float)Db::name('tenant')->where('id',$this->tenant)->value('point_balance'));
        self::assertSame($user,(float)Db::name('user')->where('id',$this->user)->value('user_money'));
    }
    private function recover(array $context, int $input=500, int $output=500): array
    { return Runtime::recoverDurableResult($this->tenant,$this->user,'aigc_short_drama_script_task',123,Fixture::receipt($context,$input,$output)); }
    private function rejected(callable $action, int $code=409): void
    {
        try { $action(); self::fail('Unsafe reservation transition accepted'); }
        catch (\RuntimeException $error) { self::assertSame($code,$error->getCode()); }
    }

    public function testPreparedReplayHoldsOnceAndActualUsageRefundsOnce(): void
    {
        $a=Fixture::reserve($this->tenant,$this->user,'same');
        $b=Fixture::reserve($this->tenant,$this->user,'same');
        self::assertSame($a['consumption']['id'],$b['consumption']['id']);
        self::assertSame(48,strlen($a['consumption']['consume_no']));
        $this->balances(16,32);
        self::assertSame(1,Db::name('user_account_log')->where('user_id',$this->user)->count());
        Fixture::call('markAccountSubmission',$a);
        $this->rejected(fn()=>Fixture::call('markAccountSubmission',$b));
        $this->rejected(fn()=>Fixture::reserve($this->tenant,$this->user,'same'));
        $result=$this->recover($a); $this->recover($a);
        self::assertSame(1.0,$result['billing']['tenant_cost_points']);
        self::assertSame(2.0,$result['billing']['user_charge_points']);
        $this->balances(19,38);
        self::assertSame(2,Db::name('user_account_log')->where('user_id',$this->user)->count());
        self::assertSame(2,Db::name('tenant_point_log')->where('tenant_id',$this->tenant)->count());
        self::assertSame(1,Db::name('ai_consumption_event')->where(['consumption_id'=>$a['consumption']['id'],'event_type'=>'settle'])->count());
        self::assertSame('success',AiAppTask::find($a['appTask']['id'])['status']);
    }

    public function testSecondPayerFailureRollsBackFirstPayerAndAllNewLedgers(): void
    {
        Db::name('user')->where('id',$this->user)->update(['user_money'=>1]);
        try { Fixture::reserve($this->tenant,$this->user,'poor_user'); self::fail('Insufficient account admitted'); }
        catch (TextRequestNotSubmittedException $e) { self::assertStringContainsString('不足',$e->getMessage()); }
        $this->balances(20,1);
        self::assertSame(0,AiConsumptionLog::where('user_id',$this->user)->count());
        self::assertSame(0,AiAppTask::where('user_id',$this->user)->count());
        self::assertSame(0,Db::name('tenant_point_log')->where('tenant_id',$this->tenant)->count());
        Db::name('tenant')->where('id',$this->tenant)->update(['point_balance'=>1]);
        Db::name('user')->where('id',$this->user)->update(['user_money'=>40]);
        try { Fixture::reserve($this->tenant,$this->user,'poor_tenant'); self::fail('Insufficient tenant admitted'); }
        catch (TextRequestNotSubmittedException $e) { self::assertStringContainsString('租户',$e->getMessage()); }
        $this->balances(1,40);
        self::assertSame(0,Db::name('user_account_log')->where('user_id',$this->user)->count());
    }

    public function testOnlyProvenPreparedFailureReleasesAndCannotBeReplayed(): void
    {
        $a=Fixture::reserve($this->tenant,$this->user,'unsubmitted');
        $this->rejected(fn()=>$this->recover($a));
        Fixture::call('fail',$a,'before submit','not_submitted');
        Fixture::call('fail',$a,'again','not_submitted');
        $this->balances(20,40);
        self::assertSame('refunded',AiConsumptionLog::find($a['consumption']['id'])['billing_status']);
        $this->rejected(fn()=>Fixture::reserve($this->tenant,$this->user,'unsubmitted'));
        $this->rejected(fn()=>$this->recover($a));
        self::assertSame(2,Db::name('user_account_log')->where('user_id',$this->user)->count());
    }

    public function testUnknownSubmissionAndMissingUsageKeepHoldsAndRecoverWithoutNewRequest(): void
    {
        $a=Fixture::reserve($this->tenant,$this->user,'unknown'); Fixture::call('markAccountSubmission',$a);
        $this->rejected(fn()=>Fixture::call('fail',$a,'network timeout','provider_error'));
        Runtime::failAppTask((int)$a['appTask']['id'],'expired local lease');
        $receipt=Fixture::receipt($a); $receipt['usage']=[];
        $this->rejected(fn()=>Runtime::recoverDurableResult($this->tenant,$this->user,'aigc_short_drama_script_task',123,$receipt));
        $this->balances(16,32);
        self::assertSame('pending_usage',AiConsumptionLog::find($a['consumption']['id'])['billing_status']);
        $this->recover($a); $this->balances(19,38);
    }

    public function testActualUsageAboveAuthorizedBoundCannotBackCharge(): void
    {
        $a=Fixture::reserve($this->tenant,$this->user,'overflow'); Fixture::call('markAccountSubmission',$a);
        $this->rejected(fn()=>$this->recover($a,5000,5000));
        $this->balances(16,32);
        self::assertSame('pending_usage',AiConsumptionLog::find($a['consumption']['id'])['billing_status']);
        self::assertSame(1,Db::name('user_account_log')->where('user_id',$this->user)->count());
    }

    public function testExhaustedAvailableBalanceDoesNotStopSettlementFromExistingHold(): void
    {
        Db::name('tenant')->where('id',$this->tenant)->update(['point_balance'=>4]);
        Db::name('user')->where('id',$this->user)->update(['user_money'=>8]);
        $a=Fixture::reserve($this->tenant,$this->user,'exact_balance'); $this->balances(0,0);
        $b=Fixture::reserve($this->tenant,$this->user,'exact_balance');
        Fixture::call('markAccountSubmission',$b); $this->recover($a); $this->balances(3,6);
    }

    public function testDifferentAttemptAndForeignParentCannotReuseReservation(): void
    {
        $a=Fixture::reserve($this->tenant,$this->user,'attempt',0,1);
        $b=Fixture::reserve($this->tenant,$this->user,'attempt',0,2);
        self::assertNotSame($a['consumption']['id'],$b['consumption']['id']); $this->balances(12,24);
        $a['appTask']->save(['app_code'=>'aigc_canvas']);
        $this->rejected(fn()=>Fixture::reserve($this->tenant,$this->user,'foreign_parent',(int)$a['appTask']['id']),422);
        $this->balances(12,24);
    }

    public function testSealedPreparedAttemptIsTheOnlyLocalProofAndCannotSubmitLate(): void
    {
        $release=fn($key,$attempt=1)=>Runtime::releaseUnsubmittedReservation($this->tenant,$this->user,'aigc_short_drama_script_task',123,hash('sha256',$key),$attempt);
        self::assertFalse($release('absent'));
        $a=Fixture::reserve($this->tenant,$this->user,'prepared');
        self::assertFalse($release('prepared',2));
        self::assertFalse(Runtime::releaseUnsubmittedReservation($this->tenant,$this->user+1,'aigc_short_drama_script_task',123,hash('sha256','prepared'),1));
        self::assertTrue($release('prepared'));self::assertTrue($release('prepared'));
        $this->rejected(fn()=>Fixture::call('markAccountSubmission',$a));
        $this->balances(20,40);
        $b=Fixture::reserve($this->tenant,$this->user,'submitted');Fixture::call('markAccountSubmission',$b);
        self::assertFalse($release('submitted'));$this->balances(16,32);
        $this->recover($b);self::assertFalse($release('submitted'));$this->balances(19,38);
    }

    public static function recoveryStates(): array {return [['reconciling'],['expired_running'],['canceled']];}
    /** @dataProvider recoveryStates */
    public function testRealExecutorReopensOnlySealedPreparedAttemptWithoutResettingBudget(string $state): void
    {
        $task='account_stage_'.bin2hex(random_bytes(8));$directory=sys_get_temp_dir().'/'.$task;
        $buffer=new \app\common\service\app\aigc_short_drama\vnext\ReceiptBuffer($directory);
        $store=new \app\common\service\app\aigc_short_drama\vnext\ExecutionStore($this->tenant,$this->user,$task,$buffer);
        $store->create($task,['context'=>['account_reservation_version'=>1], 'execution'=>['resource_limits'=>
            ['total_tokens'=>8000,'tenant_cost_points'=>8,'user_charge_points'=>16]]],time()+600);
        try {
            $params=['_account_reservation_version'=>1];$fence=$store->acquire('interrupted',100);$context=null;
            try {$store->call($fence,'screenplay',$params,function($input,$key,$persist,$admit)use(&$context) {
                $admit(['total_tokens'=>4000,'tenant_cost_points'=>4,'user_charge_points'=>8]);
                $context=Fixture::reserve($this->tenant,$this->user,$key,0,$input['_account_reservation_attempt']);
                throw new \RuntimeException('fixture process interrupted before marker');
            });self::fail('Interruption ignored');}catch(\RuntimeException $e){self::assertStringContainsString('interrupted',$e->getMessage());}
            $before=$store->snapshot();self::assertSame('reconciling',$before['execution_status']);
            $scope=['tenant_id'=>$this->tenant,'user_id'=>$this->user,'task_id'=>$task];
            if($state==='expired_running') {
                Db::name('aigc_short_drama_vnext_job')->where($scope)->update(['execution_status'=>'running']);
                self::assertSame(0,$store->reconcileUnsubmitted(static function(){self::fail('Active lease sealed');}));
                Db::name('aigc_short_drama_vnext_job')->where($scope)->update(['lease_until'=>0]);
            } elseif($state==='canceled') $store->cancel();
            $release=fn($key,$attempt)=>Runtime::releaseUnsubmittedReservation($this->tenant,$this->user,'aigc_short_drama_script_task',123,$key,$attempt);
            self::assertSame(1,$store->reconcileUnsubmitted($release));self::assertSame(0,$store->reconcileUnsubmitted($release));
            $this->balances(20,40);$this->rejected(fn()=>Fixture::call('markAccountSubmission',$context));
            $after=$store->snapshot();$budget=json_decode($after['budget_json'],true);
            self::assertSame(['expired_running'=>'retry_wait','reconciling'=>'reconciling','canceled'=>'canceled'][$state],$after['execution_status']);
            self::assertSame(1,$budget['calls']);self::assertSame([],$budget['resources']['holds']);
            self::assertSame($before['input_hash'],$after['input_hash']);self::assertSame($before['deadline_at'],$after['deadline_at']);
            if($state==='expired_running') self::assertFalse($store->resume(true));
            elseif($state==='canceled') {self::assertFalse($store->resume(true));self::assertTrue($store->resume());}
            else self::assertTrue($store->resume(true));
            Db::name('aigc_short_drama_vnext_stage')->where(['tenant_id'=>$this->tenant,'user_id'=>$this->user,'task_id'=>$task])->update(['retry_after'=>0]);
            $fence=$store->acquire('recovered',100);
            $result=$store->call($fence,'screenplay',$params,function($input,$key,$persist,$admit) {
                self::assertSame(2,$input['_account_reservation_attempt']);
                $admit(['total_tokens'=>4000,'tenant_cost_points'=>4,'user_charge_points'=>8]);
                $a=Fixture::reserve($this->tenant,$this->user,$key,0,$input['_account_reservation_attempt']);
                Fixture::call('markAccountSubmission',$a);return $this->recover($a);
            });
            self::assertSame('完整正文及最后一句。',$result['content']);$this->balances(19,38);
            $budget=json_decode($store->snapshot()['budget_json'],true);
            self::assertSame(2,$budget['calls']);self::assertSame(1000,$budget['resources']['spent']['total_tokens']);
            self::assertSame([],$budget['resources']['holds']);
        } finally {foreach(glob($directory.'/*')?:[] as $path)unlink($path);rmdir($directory);}
    }
}
