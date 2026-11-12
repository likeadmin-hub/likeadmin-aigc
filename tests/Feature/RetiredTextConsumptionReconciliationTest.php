<?php
namespace Tests\Feature;
use app\common\service\ai\RetiredTextConsumptionReconciliationService as Service;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class RetiredTextConsumptionReconciliationTest extends TestCase
{
    private bool $transaction = false;
    protected function setUp(): void
    {
        if (getenv('AIGC_TEST_DATABASE') !== 'x_cn') self::markTestSkipped('Disposable x_cn only');
        (new \think\App())->initialize();
        self::assertSame('x_cn',Db::connect()->getConfig('database'));
        self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);
        Db::startTrans(); $this->transaction = true;
    }
    protected function tearDown(): void { if($this->transaction) Db::rollback(); }
    private function fixture(bool $rejected = false): array
    {
        $key='retired-reconcile-'.bin2hex(random_bytes(8));
        $task=Db::name('ai_app_task')->insertGetId(['task_no'=>$key,'idempotency_key'=>$key,'tenant_id'=>1,'user_id'=>1,'app_code'=>'aigc_micro_video','status'=>'running']);
        $id=Db::name('ai_consumption_log')->insertGetId(['consume_no'=>$key,'app_task_id'=>$task,'tenant_id'=>1,'user_id'=>1,'app_code'=>'aigc_micro_video','resource_type'=>'model','usage_unit'=>'token',
            'run_status'=>'pending_confirmation','billing_status'=>'pending_usage','price_snapshot'=>'{"input":{"tenant_price":1200}}','usage_snapshot'=>'{"usage_complete":false}',
            'response_summary'=>'{"protocol_evidence":{"terminal_status":"failed"}}','error_message'=>'业务内容验证失败']);
        return ['app_code'=>'aigc_micro_video','database'=>'x_cn','evidence_sha256'=>str_repeat('a',64),'records'=>[['id'=>$id,
            'local_fingerprint'=>Service::fingerprint(Db::name('ai_consumption_log')->where('id',$id)->find()),
            'receipt'=>['identity_verified'=>true,'request_verified'=>true,'task_id'=>$key,'status'=>$rejected?'failed':'success','actual_points'=>$rejected?'0.00':'2.71']]]];
    }
    public function testWorkerGuardOnlyMatchesThisProjectsRetiredDaemon(): void
    {
        $guard=\app\common\service\app\RetiredAppWorkerGuard::class;
        self::assertTrue($guard::matches(['/php','/repo/think','micro-video:worker'],'/repo/'));
        self::assertFalse($guard::matches(['/php','/other/think','micro-video:worker'],'/repo/'));
        self::assertFalse($guard::matches(['/php','/repo/think','ai:task-worker'],'/repo/'));
        self::assertFalse($guard::matches(['/php','/repo/scripts/retire-micro-video.php'],'/repo/'));
    }
    public function testDryRunCannotAlterMoneyStatusOrReceipts(): void
    {
        $plan=$this->fixture();$id=$plan['records'][0]['id'];$before=Db::name('ai_consumption_log')->where('id',$id)->find();
        $result=Service::process($plan);self::assertSame('audit',$result['mode']);
        self::assertSame($before,Db::name('ai_consumption_log')->where('id',$id)->find());
        self::assertSame(0,Db::name('ai_consumption_event')->where('consumption_id',$id)->count());
    }
    public function testHistoricalChargeRequiresExplicitWriteoffDecision(): void
    {
        $plan=$this->fixture();$this->expectExceptionMessage('明确批准平台核销');Service::process($plan,true,'operator','历史失败结果不补扣');
    }
    public function testExplicitWriteoffPreservesOriginalEvidenceAndIsIdempotent(): void
    {
        $plan=$this->fixture();$id=$plan['records'][0]['id'];$before=Db::name('ai_consumption_log')->where('id',$id)->find();
        $money=[Db::name('tenant')->where('id',1)->value('point_balance'),Db::name('user')->where('id',1)->value('user_money'),Db::name('tenant_point_log')->count(),Db::name('user_account_log')->count()];
        self::assertSame('applied',Service::process($plan,true,'operator','管理员批准历史失败结果平台核销',true)['mode']);
        $after=Db::name('ai_consumption_log')->where('id',$id)->find();
        foreach(['price_snapshot','usage_snapshot','response_summary','error_message','actual_user_price','actual_tenant_cost']as$key) self::assertSame($before[$key],$after[$key],$key);
        self::assertSame('settled',$after['billing_status']);self::assertSame('failed',$after['run_status']);
        self::assertSame('already_reconciled',Service::process($plan,true,'operator','管理员批准历史失败结果平台核销',true)['records'][0]['status']);
        self::assertSame(1,Db::name('ai_consumption_event')->where('consumption_id',$id)->count());
        self::assertSame($money,[Db::name('tenant')->where('id',1)->value('point_balance'),Db::name('user')->where('id',1)->value('user_money'),Db::name('tenant_point_log')->count(),Db::name('user_account_log')->count()]);
    }
    public function testConfirmedProviderRejectionClosesWithZeroRefundAndNoWriteoff(): void
    {
        $plan=$this->fixture(true);Service::process($plan,true,'operator','上游明确拒绝且无扣费记录');
        self::assertSame('refunded',Db::name('ai_consumption_log')->where('id',$plan['records'][0]['id'])->value('billing_status'));
    }
    public function testNonzeroReservationCannotBeWrittenOff(): void
    {
        $plan=$this->fixture();$id=$plan['records'][0]['id'];Db::name('ai_consumption_log')->where('id',$id)->update(['reserved_user_price'=>1]);
        $plan['records'][0]['local_fingerprint']=Service::fingerprint(Db::name('ai_consumption_log')->where('id',$id)->find());
        $this->expectExceptionMessage('有预扣');Service::process($plan,true,'operator','管理员批准历史失败结果平台核销',true);
    }
    public function testStalePlanRollsBackTheWholeBatch(): void
    {
        $plan=$this->fixture(true);$second=$this->fixture(true);$second['records'][0]['local_fingerprint']=str_repeat('b',64);$plan['records'][]=$second['records'][0];
        try { Service::process($plan,true,'operator','上游明确拒绝且无扣费记录'); self::fail('stale plan accepted'); } catch(\RuntimeException $e){self::assertStringContainsString('记录已变化',$e->getMessage());}
        foreach($plan['records']as$row){self::assertSame('pending_usage',Db::name('ai_consumption_log')->where('id',$row['id'])->value('billing_status'));self::assertSame(0,Db::name('ai_consumption_event')->where('consumption_id',$row['id'])->count());}
    }
    public function testAnotherAppCannotUseThisAdministrativeClosure(): void
    {
        $plan=$this->fixture();$id=$plan['records'][0]['id'];Db::name('ai_consumption_log')->where('id',$id)->update(['app_code'=>'aigc_video']);
        $this->expectExceptionMessage('不属于已退役文本调用');Service::process($plan,true,'operator','管理员批准历史失败结果平台核销',true);
    }
}
