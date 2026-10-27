<?php
namespace Tests\Feature;
use app\common\service\app\aigc_short_drama\ShortDramaPlanningUnit as Unit;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class ShortDramaInterruptedRetryTest extends TestCase
{
    private bool $transaction=false;
    private array $scope=['tenant_id'=>2000000723,'user_id'=>7];
    protected function setUp(): void
    {
        if(getenv('SHORT_DRAMA_STORY_DB_TESTS')!=='1')self::markTestSkipped('Transactional local fixtures only');
        (new \think\App())->initialize();Db::startTrans();$this->transaction=true;
    }
    protected function tearDown(): void { if($this->transaction)Db::rollback(); }
    private function fixture(int $updated=1,string $status='failed'): string
    {
        $task='interrupt_test_'.bin2hex(random_bytes(8));
        Db::name('aigc_short_drama_script_task')->insert($this->scope+['project_id'=>0,'task_id'=>$task,'status'=>$status,
            'request_json'=>'{}','result_json'=>'{"checkpoint":"keep"}','billing_status'=>'deducted','user_charge_points'=>35]);
        Db::name('aigc_short_drama_planning_unit')->insert($this->scope+['task_id'=>$task,'unit_key'=>'v3_piece',
            'status'=>'running','attempt'=>1,'request_json'=>'{"content":"frozen"}','update_time'=>$updated]);
        return $task;
    }
    public function testConfirmationRetainsUncertainOriginalAndReusesCompletedUnits(): void
    {
        $task=$this->fixture();$query=Db::name('aigc_short_drama_planning_unit')->where('task_id',$task);$before=$query->find();
        $primary=Db::name('aigc_short_drama_script_task')->where('task_id',$task)->find();
        try{Unit::prepareExplicitRetry(2000000723,7,$task);self::fail('Confirmation required');}
        catch(\RuntimeException $e){self::assertSame(Unit::INTERRUPTED_CONFIRMATION,$e->getMessage());}
        self::assertSame($before,Db::name('aigc_short_drama_planning_unit')->where('id',$before['id'])->find());
        Unit::prepareExplicitRetry(2000000723,7,$task,true);
        $new=Db::name('aigc_short_drama_planning_unit')->where('task_id',$task)->order('id','desc')->find();
        self::assertSame((int)$before['id'],json_decode($new['request_json'],true)['_interrupted_retry_of']);
        self::assertSame('pending',$new['status']);
        $calls=0;$generate=static function()use(&$calls){$calls++;return ['result'=>['content'=>'new result']];};
        $result=Unit::call(2000000723,7,$task,'v3_piece',['content'=>'frozen'],$generate);
        // A late write to the old receipt cannot replace the explicitly created successor.
        Db::name('aigc_short_drama_planning_unit')->where('id',$before['id'])->update(['status'=>'received','result_json'=>'{"result":{"content":"late old result"}}']);
        Unit::prepareExplicitRetry(2000000723,7,$task);
        self::assertSame($result,Unit::call(2000000723,7,$task,'v3_piece',['content'=>'frozen'],$generate));
        self::assertSame(1,$calls);
        self::assertSame($primary,Db::name('aigc_short_drama_script_task')->where('task_id',$task)->find());
        self::assertSame(2,Db::name('aigc_short_drama_planning_unit')->where('task_id',$task)->count());
    }
    /** @dataProvider unsafeOwners */
    public function testRecentOrActiveRequestsCannotBeResubmittedEvenWithConfirmation(string $status,bool $recent): void
    {
        $task=$this->fixture($recent?time():1,$status);
        $this->expectExceptionMessage('上次模型请求仍在收尾');
        Unit::prepareExplicitRetry(2000000723,7,$task,true);
    }
    public static function unsafeOwners(): array { return [['failed',true],['running',false],['success',false]]; }
    public function testOtherTenantCannotRecoverOrModifyOriginal(): void
    {
        $task=$this->fixture();$before=Db::name('aigc_short_drama_planning_unit')->where('task_id',$task)->find();
        Unit::prepareExplicitRetry(2000000724,7,$task,true);
        self::assertSame($before,Db::name('aigc_short_drama_planning_unit')->where('task_id',$task)->find());
    }
}
