<?php
namespace Tests\Feature;
use app\common\service\app\aigc_short_drama\ShortDramaPlanningState as State;
use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class ShortDramaPlanningStatePersistenceTest extends TestCase
{
    private bool $transaction=false;
    private array $scope=['tenant_id'=>2000000719,'user_id'=>7];
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Transactional fixture only');
        (new \think\App())->initialize();Db::startTrans();$this->transaction=true;
    }
    protected function tearDown(): void { if($this->transaction)Db::rollback(); }
    private function fixture(array $primary=[], array $mirror=[]): string
    {
        $id='state_test_' . bin2hex(random_bytes(8));
        $project=Db::name('aigc_short_drama_project')->insertGetId($this->scope+['title'=>'旧作品保留','last_task_id'=>$id]);
        Db::name('aigc_short_drama_script_task')->insert(array_replace($this->scope+['project_id'=>$project,'task_id'=>$id,
            'request_json'=>'{}','result_json'=>'{}','status'=>'pending','create_time'=>1,'update_time'=>1],$primary));
        Db::name('aigc_short_drama_generation_task')->insert(array_replace($this->scope+['project_id'=>$project,'task_id'=>$id,
            'task_type'=>'script_plan','request_json'=>'{"frozen_model":"old"}','result_json'=>'{}','output_asset_ids'=>'[]',
            'status'=>'pending','billing_status'=>'none','create_time'=>1,'update_time'=>1],$mirror));
        return $id;
    }
    private function row(string $id,string $kind): array { return Db::name('aigc_short_drama_'.$kind)->where($this->scope+['task_id'=>$id])->find(); }
    private function reconcile(string $id,bool $apply=true): array { return State::reconcile($this->scope['tenant_id'],7,$id,$apply,100000); }

    public function testExpiredWaitDryRunApplyAndRepeatPreserveEverythingExceptState(): void
    {
        $id=$this->fixture();$before=$this->row($id,'script_task');$mirror=$this->row($id,'generation_task');
        self::assertContains('expire_unstarted_legacy_wait',$this->reconcile($id,false)['actions']);
        self::assertSame($before,$this->row($id,'script_task'));
        self::assertSame($mirror,$this->row($id,'generation_task'));
        $this->reconcile($id);
        self::assertSame('failed',$this->row($id,'script_task')['status']);
        self::assertSame('legacy_queue_expired',$this->row($id,'generation_task')['error_code']);
        foreach(['request_json','result_json','output_asset_ids','billing_status','tenant_cost_points','user_charge_points'] as $key)
            self::assertSame($mirror[$key],$this->row($id,'generation_task')[$key]);
        self::assertSame([],$this->reconcile($id)['actions']);
        self::assertSame('旧作品保留',Db::name('aigc_short_drama_project')->where('id',$before['project_id'])->value('title'));
    }

    public function testReceiptsBillingAndPartialResultsPreventLegacyExpiry(): void
    {
        foreach ([['billing_status'=>'deducted','user_charge_points'=>10],['provider_request_id'=>'accepted'],['result_json'=>'{"partial":"preserved"}'],['status'=>'success'],['output_asset_ids'=>'[99]']] as $mirror) {
            $id=$this->fixture([],$mirror);
            self::assertSame([],$this->reconcile($id)['actions']); self::assertSame('pending',$this->row($id,'script_task')['status']);
        }
        $id=$this->fixture();
        Db::name('aigc_short_drama_planning_unit')->insert($this->scope+['task_id'=>$id,'unit_key'=>'accepted','status'=>'received','result_json'=>'{"content":"keep"}']);
        self::assertSame([],$this->reconcile($id)['actions']);
        $id=$this->fixture(['result_json'=>'{"__script_checkpoint":{"title":"keep"}}']);
        self::assertSame([],$this->reconcile($id)['actions']);
    }

    public function testTerminalMirrorRepairPreservesBillingReceiptsAndProjectIsolation(): void
    {
        $id=$this->fixture(['status'=>'failed','error'=>'正文引用范围无效','result_json'=>'{"partial":"keep"}'],
            ['status'=>'pending','billing_status'=>'deducted','user_charge_points'=>12,'pricing_snapshot'=>'{"old_price":12}', 'result_json'=>'{"receipt":"keep"}']);
        $primary=$this->row($id,'script_task');$before=$this->row($id,'generation_task');
        self::assertSame('not_found',State::reconcile(2000000720,7,$id)['reason']);
        self::assertSame('not_found',State::reconcile(2000000719,8,$id)['reason']);
        $this->reconcile($id);$after=$this->row($id,'generation_task');
        self::assertSame('failed',$after['status']);self::assertSame('validation_failed',$after['error_code']);
        self::assertSame($primary,$this->row($id,'script_task'));
        foreach(['request_json','result_json','pricing_snapshot','billing_status','user_charge_points'] as $key)self::assertSame($before[$key],$after[$key]);
        self::assertSame([],$this->reconcile($id)['actions']);
    }

    public function testSuccessfulAndCanceledTasksCannotBeFailedByLateCallbacks(): void
    {
        foreach(['success','canceled'] as $status){
            $id=$this->fixture(['status'=>$status],['status'=>$status]);$before=$this->row($id,'generation_task');
            self::assertFalse(State::failExecution(2000000719,7,$id,new \RuntimeException('late'),'late',0));
            self::assertSame($before,$this->row($id,'generation_task'));
        }
        $id=$this->fixture(['status'=>'failed','error'=>'bad'],['status'=>'success','result_json'=>'{"old":"success"}']);
        self::assertSame([],$this->reconcile($id)['actions']);self::assertSame('success',$this->row($id,'generation_task')['status']);
    }

    public function testExecutionAndSetupFailuresSynchronizeWithoutDiscardingPartialOutput(): void
    {
        $id=$this->fixture(['status'=>'running','retry_count'=>4,'result_json'=>'{"checkpoint":"keep"}'],['status'=>'running','billing_status'=>'deducted','user_charge_points'=>8]);
        self::assertFalse(State::failExecution(2000000719,7,$id,new \RuntimeException('old'),'old',3));
        self::assertTrue(State::failExecution(2000000719,7,$id,new \RuntimeException('场次时长与片段合计不一致',422),'场次时长与片段合计不一致',4,'receipt-accepted'));
        self::assertSame('validation_failed',$this->row($id,'generation_task')['error_code']);
        self::assertSame('receipt-accepted',$this->row($id,'generation_task')['provider_request_id']);
        self::assertSame('{"checkpoint":"keep"}',$this->row($id,'script_task')['result_json']);
        self::assertEquals(8,$this->row($id,'generation_task')['user_charge_points']);
        $setup=$this->fixture(['request_json'=>'{"_generation_version":3}']);
        State::failSetup(2000000719,7,$setup,new \RuntimeException('SQLSTATE: fixture'));
        self::assertSame('failed',$this->row($setup,'script_task')['status']);
        self::assertSame('persistence_failed',$this->row($setup,'generation_task')['error_code']);
    }

    public function testReadHealsMirrorWithoutGenerationAndExplicitRetryReactivatesBoth(): void
    {
        $id=$this->fixture(['status'=>'failed','request_json'=>'{"_generation_version":3}','error'=>'正文引用范围无效'],['status'=>'pending']);
        $read=new \ReflectionMethod(Service::class,'prepareGenerationTaskForRead');$read->setAccessible(true);
        $row=$read->invoke(null,2000000719,7,$this->row($id,'generation_task'));
        self::assertSame('failed',$row['status']);
        Service::retry(2000000719,7,$id);
        self::assertSame('pending',$this->row($id,'script_task')['status']);
        self::assertSame('pending',$this->row($id,'generation_task')['status']);
        self::assertSame('', $this->row($id,'generation_task')['error_code']);
        self::assertSame(0,Db::name('aigc_short_drama_planning_unit')->where($this->scope+['task_id'=>$id])->count());
    }

    public function testExplicitRetryOfLegacyLimitKeepsSameTaskAndCheckpoint(): void
    {
        $request=['_generation_version'=>3,'_input_contract_version'=>23];
        $id=$this->fixture(['status'=>'failed','request_json'=>json_encode($request),'result_json'=>'{"__script_checkpoint":{"story_outline":"保留"}}',
            'error'=>'本集已达到自动处理上限，已保留完成内容，请缩小生成范围'],['status'=>'failed','billing_status'=>'deducted','user_charge_points'=>8]);
        $before=$this->row($id,'script_task');
        Service::retry(2000000719,7,$id);
        $after=$this->row($id,'script_task');$resumed=json_decode($after['request_json'],true);
        self::assertSame('pending',$after['status']);self::assertSame(23,$resumed['_input_contract_version']);
        self::assertSame(1,$resumed['_segmented_generation']);self::assertSame($before['result_json'],$after['result_json']);
        self::assertEquals(8,$this->row($id,'generation_task')['user_charge_points']);
        self::assertSame(0,Db::name('aigc_short_drama_planning_unit')->where($this->scope+['task_id'=>$id])->count());
    }

    public function testOldProviderLabelsAreReclassifiedAndFileQaIsUntouched(): void
    {
        $id=$this->fixture(['status'=>'failed','error'=>'场次时长与片段合计不一致'],['status'=>'failed','error_code'=>'provider_failed']);
        self::assertSame(['classify_failure'],$this->reconcile($id)['actions']);
        self::assertSame('validation_failed',$this->row($id,'generation_task')['error_code']);
        $file=$this->fixture(['request_json'=>'{"source":"market_file_qa_parse"}']);
        self::assertSame('file_qa_owned',$this->reconcile($file)['reason']);
        self::assertSame('pending',$this->row($file,'script_task')['status']);
    }
}
