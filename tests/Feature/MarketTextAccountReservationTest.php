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
}
