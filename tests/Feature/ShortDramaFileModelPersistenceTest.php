<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\service\app\aigc_short_drama\{ShortDramaFileModelParser as Parser,AigcShortDramaService as Service};
use app\common\service\power\MarketTextModelRuntimeService as Text;
final class ShortDramaFileModelPersistenceTest extends TestCase
{
    protected function setUp():void{if(getenv('SHORT_DRAMA_STORY_DB_TESTS')!=='1')self::markTestSkipped('Local rollback only');(new \think\App())->initialize();Db::startTrans();}
    protected function tearDown():void{if(getenv('SHORT_DRAMA_STORY_DB_TESTS')==='1')Db::rollback();}
    private function task(string $status):array
    {
        $key='file_model_'.bin2hex(random_bytes(8));$owner=['tenant_id'=>2000000719,'user_id'=>7];
        $project=Db::name('aigc_short_drama_project')->insertGetId($owner+['title'=>'原项目','last_task_id'=>$key]);
        $app=Db::name('ai_app_task')->insertGetId($owner+['task_no'=>$key,'idempotency_key'=>$key,'app_code'=>'aigc_short_drama','action_code'=>'script_parse']);
        Db::name('ai_consumption_log')->insert($owner+['consume_no'=>$key,'app_task_id'=>$app,'app_code'=>'aigc_short_drama','run_status'=>'success','billing_status'=>'settled','response_summary'=>'{"output_length":100}']);
        $id=Db::name('aigc_short_drama_script_task')->insertGetId($owner+['task_id'=>$key,'project_id'=>$project,'app_task_id'=>$app,'status'=>$status,'retry_count'=>2,
            'request_json'=>json_encode(['source'=>'market_file_qa_parse','_file_parse_model'=>['model'=>['id'=>'65'],'text'=>'甲\n乙']])]);
        return Db::name('aigc_short_drama_script_task')->where('id',$id)->find();
    }
    public function testPollingWaitsBetweenTextSettlementAndValidatedParsePublication():void
    {
        $task=$this->task('running');$m=new \ReflectionMethod(Service::class,'syncMarketFileQaScriptTask');$m->invoke(null,$task);
        $saved=Db::name('aigc_short_drama_script_task')->where('id',$task['id'])->find();self::assertSame('running',$saved['status']);self::assertSame('',$saved['error']);
    }
    public function testCanceledOrOlderAttemptCannotBindAnotherPaidTextCall():void
    {
        $task=$this->task('canceled');try{Parser::bind(2000000719,7,$task,['app_task_id'=>1,'consumption_id'=>1]);self::fail('Canceled task bound');}catch(\RuntimeException $e){self::assertSame(409,$e->getCode());}
        self::assertSame('canceled',Db::name('aigc_short_drama_script_task')->where('id',$task['id'])->value('status'));
        $task=$this->task('running');Db::name('aigc_short_drama_script_task')->where('id',$task['id'])->inc('retry_count')->update();
        $this->expectException(\RuntimeException::class);Parser::bind(2000000719,7,$task,['app_task_id'=>1,'consumption_id'=>1]);
    }
    public function testCanceledAdmissionReleasesLocalReservationBeforeProviderSubmission():void
    {
        // The callback always throws before transport; no provider call is made.
        $id=0;
        try{Text::generate(1,3,['content'=>'离线取消边界验收','model_selection'=>'65','_disable_model_fallback'=>true],static function($event,$data)use(&$id){if($event==='app_task'){$id=$data['consumption_id'];throw new \RuntimeException('剧本解析已停止',409);}});self::fail('Canceled admission continued');}
        catch(\RuntimeException $e){self::assertSame('剧本解析已停止',$e->getMessage());}
        self::assertGreaterThan(0,$id);self::assertSame('refunded',Db::name('ai_consumption_log')->where('id',$id)->value('billing_status'));
        self::assertSame(0,(int)Db::name('ai_consumption_event')->where(['consumption_id'=>$id,'event_type'=>'submit'])->count());
    }
    public function testPreSubmissionBackoffKeepsTheFileParseQueued():void
    {
        $task=$this->task('running');$request=json_decode($task['request_json'],true);$input=Parser::request($task,$request);
        Db::name('aigc_short_drama_planning_unit')->insert(['tenant_id'=>2000000719,'user_id'=>7,'task_id'=>$task['task_id'],'unit_key'=>'v3_uploaded_file_parse',
            'status'=>'waiting','attempt'=>1,'request_json'=>json_encode($input),'update_time'=>time()]);
        (new \ReflectionMethod(Service::class,'executeUploadedModelParse'))->invoke(null,2000000719,7,$task,$request);
        self::assertSame('queued',Db::name('aigc_short_drama_script_task')->where('id',$task['id'])->value('status'));
    }
    public function testBusinessSchemaFailureCreatesARepairableValidationReceipt():void
    {
        $task=$this->task('running');$request=json_decode($task['request_json'],true);$request['_file_parse_model']['text']="甲\n乙\n丙\n丁";
        $input=Parser::request($task,$request);$receipt=['content'=>json_encode(['episodes'=>[
            ['episode_no'=>1,'source_range'=>['start_line'=>1,'end_line'=>2]],['episode_no'=>2,'source_range'=>['start_line'=>3,'end_line'=>4]]]]),'finish_reason'=>'stop'];
        \app\common\service\app\aigc_short_drama\ShortDramaPlanningUnit::call(2000000719,7,$task['task_id'],'v3_uploaded_file_parse',$input,static fn()=>$receipt,true);
        try{Parser::run(2000000719,7,$task,$request,static function(){self::fail('Paid call replayed');},static function(){throw new \Exception('缺少主题');});self::fail('Invalid schema accepted');}
        catch(\RuntimeException $e){self::assertSame(422,$e->getCode());}
        self::assertStringStartsWith('validation:',Db::name('aigc_short_drama_planning_unit')->where('task_id',$task['task_id'])->value('error'));
    }
    public function testDisplayedBillingIncludesEverySettledRepairAttempt():void
    {
        $task=$this->task('running');$owner=['tenant_id'=>2000000719,'user_id'=>7];
        foreach([1,2]as$n){$key=bin2hex(random_bytes(12));$id=Db::name('ai_app_task')->insertGetId($owner+['task_no'=>$key,'idempotency_key'=>$key,
            'app_code'=>'aigc_short_drama','business_table'=>'aigc_short_drama_script_task','business_id'=>$task['id']]);
            Db::name('ai_consumption_log')->insert($owner+['consume_no'=>$key,'app_task_id'=>$id,'billing_status'=>'settled','actual_user_price'=>$n,'actual_tenant_cost'=>$n]);}
        self::assertSame(['user_charge_points'=>3.0,'tenant_cost_points'=>3.0,'billing_status'=>'settled'],Parser::billing(2000000719,7,(int)$task['id']));
        self::assertSame(0.0,Parser::billing(2000000720,7,(int)$task['id'])['user_charge_points']);
    }
}
