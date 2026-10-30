<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\service\app\aigc_short_drama\{AigcShortDramaService as Service,ShortDramaFileParseFallback as Fallback};

final class ShortDramaFileParseFallbackPersistenceTest extends TestCase
{
    private bool $transaction=false;
    protected function setUp():void { if(getenv('SHORT_DRAMA_STORY_DB_TESTS')!=='1')self::markTestSkipped('Transactional database test');(new \think\App())->initialize();Db::startTrans();$this->transaction=true; }
    protected function tearDown():void { if($this->transaction)Db::rollback(); }
    private function fixture():array
    {
        $scope=['tenant_id'=>2000000719,'user_id'=>7];$key='parse_fallback_'.bin2hex(random_bytes(8));
        $project=Db::name('aigc_short_drama_project')->insertGetId($scope+['title'=>'兜底事务测试','last_task_id'=>$key]);
        $request=['source'=>'market_file_qa_parse','prompt'=>'按原文分为两集','_generation_version'=>3,'_input_contract_version'=>30,'workflow_variant'=>'story_outline_v2','multi_episode'=>true,
            '_file_parse_fallback'=>['version'=>1,'text'=>"第一集 地址\n0-5秒 甲找到旧信。\n第二集 证人\n0-5秒 乙交出日记。",'model'=>['id'=>'test','context_window'=>32768,'max_output_tokens'=>8192]]];
        $app=Db::name('ai_app_task')->insertGetId($scope+['task_no'=>$key,'idempotency_key'=>$key,'app_code'=>'aigc_short_drama','action_code'=>'script_parse']);
        Db::name('ai_consumption_log')->insert($scope+['app_task_id'=>$app,'consume_no'=>$key,'app_code'=>'aigc_short_drama','run_status'=>'success','billing_status'=>'settled','actual_user_price'=>2,'actual_tenant_cost'=>1,'response_summary'=>'{"parse_result":{}}']);
        $id=Db::name('aigc_short_drama_script_task')->insertGetId($scope+['project_id'=>$project,'task_id'=>$key,'status'=>'running','request_json'=>json_encode($request),'app_task_id'=>$app,'provider_task_id'=>'primary_api_receipt']);
        Db::name('ai_app_task')->where('id',$app)->update(['business_table'=>'aigc_short_drama_script_task','business_id'=>$id]);
        return Db::name('aigc_short_drama_script_task')->where('id',$id)->find();
    }
    private function plan():array { return ['title'=>'旧信','type_judgement'=>'悬疑','core_theme'=>'寻找故人','story_outline'=>'甲找到旧信并向乙追问，乙交出日记。',
        'subjects'=>[['id'=>'s1','name'=>'甲','category'=>'character','description'=>'寻找旧事的来客']],
        'locations'=>[['id'=>'l1','name'=>'旧宅','description'=>'旧式木屋']],
        'episodes'=>[
            ['episode_no'=>1,'title'=>'旧信','source_range'=>['start_line'=>1,'end_line'=>2],'story_outline'=>'甲在旧宅找到旧信。','conflict_point'=>'信中落款与记忆不同。','ending_hook'=>'甲发现故人的署名。'],
            ['episode_no'=>2,'title'=>'日记','source_range'=>['start_line'=>3,'end_line'=>4],'story_outline'=>'乙向甲交出旧日记。','conflict_point'=>'乙不愿说明来历。','ending_hook'=>'日记封面揭开线索。']]]; }
    private function row(array $task):array { return Db::name('aigc_short_drama_script_task')->where('id',$task['id'])->find(); }
    public function testDuplicateSchedulingAndReceiptReplayDoNotPayTwiceOrReplacePrimaryBinding():void
    {
        $t=$this->fixture();$tenant=$t['tenant_id'];$user=$t['user_id'];
        self::assertTrue(Fallback::schedule($tenant,$user,$t,[],'缺少episodes'));
        self::assertFalse(Fallback::schedule($tenant,$user,$t,[],'same stale poll'));
        $calls=0;$provider=function($input,$event)use(&$calls,$t){
            $calls++;$event('app_task');$key=$t['task_id'].'_repair';
            $app=Db::name('ai_app_task')->insertGetId(['tenant_id'=>$t['tenant_id'],'user_id'=>$t['user_id'],'task_no'=>$key,'idempotency_key'=>$key,'app_code'=>'aigc_short_drama','business_table'=>'aigc_short_drama_script_task','business_id'=>$t['id']]);
            Db::name('ai_consumption_log')->insert(['tenant_id'=>$t['tenant_id'],'user_id'=>$t['user_id'],'app_task_id'=>$app,'consume_no'=>$key,'run_status'=>'success','billing_status'=>'settled','actual_user_price'=>3.5,'actual_tenant_cost'=>1.25]);
            return ['content'=>json_encode($this->plan())];
        };
        // Simulate a process ending after the receipt was saved, before publication.
        Fallback::repair($tenant,$user,$t,json_decode($this->row($t)['request_json'],true),$provider,static fn($p)=>$p);
        self::assertTrue(Fallback::run($tenant,$user,$t,static fn($p)=>$p,$provider));
        self::assertFalse(Fallback::run($tenant,$user,$t,static fn($p)=>$p,$provider));
        self::assertSame(1,$calls);$saved=$this->row($t);self::assertSame($t['app_task_id'],$saved['app_task_id']);self::assertSame('primary_api_receipt',$saved['provider_task_id']);
        $sync=new \ReflectionMethod(Service::class,'syncMarketFileQaScriptTask');$sync->setAccessible(true);$sync->invoke(null,$saved);
        $saved=$this->row($t);self::assertSame('success',$saved['status']);$r=json_decode($saved['request_json'],true);
        self::assertSame('done',$r['_file_parse_fallback']['state']);self::assertSame([],$r['_file_parse_fallback']['original_result']);
        self::assertSame(5.5,(float)$saved['user_charge_points']);self::assertSame(2.25,(float)$saved['tenant_cost_points']);
        self::assertSame(['attempts'=>1,'status'=>'passed'],json_decode($saved['result_json'],true)['parse_metadata']['automatic_repair']);
    }
    public function testInvalidRepairIsTerminalAndPollingCannotQueueItAgain():void
    {
        $t=$this->fixture();Fallback::schedule($t['tenant_id'],$t['user_id'],$t,[],'缺少episodes');$calls=0;
        try {Fallback::run($t['tenant_id'],$t['user_id'],$t,static fn($p)=>$p,static function()use(&$calls){$calls++;return ['content'=>'{}'];});self::fail('Invalid repair must fail');}
        catch(\RuntimeException $e){self::assertSame(422,$e->getCode());}
        $saved=$this->row($t);self::assertSame('failed',$saved['status']);self::assertSame('failed',json_decode($saved['request_json'],true)['_file_parse_fallback']['state']);
        self::assertFalse(Fallback::schedule($t['tenant_id'],$t['user_id'],$t,[],'stale poll'));self::assertFalse(Fallback::run($t['tenant_id'],$t['user_id'],$t,static fn($p)=>$p));self::assertSame(1,$calls);
    }
    public function testCancelBeforeSubmitAndCancelDuringProviderCannotPublish():void
    {
        $t=$this->fixture();Fallback::schedule($t['tenant_id'],$t['user_id'],$t,[],'缺少episodes');
        $result=Fallback::run($t['tenant_id'],$t['user_id'],$t,static fn($p)=>$p,function($input,$event)use($t){
            $event('app_task');Db::name('aigc_short_drama_script_task')->where('id',$t['id'])->update(['status'=>'canceled']);
            $key=$t['task_id'].'_late';$app=Db::name('ai_app_task')->insertGetId(['tenant_id'=>$t['tenant_id'],'user_id'=>$t['user_id'],'task_no'=>$key,'idempotency_key'=>$key,'app_code'=>'aigc_short_drama','business_table'=>'aigc_short_drama_script_task','business_id'=>$t['id']]);
            Db::name('ai_consumption_log')->insert(['tenant_id'=>$t['tenant_id'],'user_id'=>$t['user_id'],'app_task_id'=>$app,'consume_no'=>$key,'run_status'=>'success','billing_status'=>'settled','actual_user_price'=>3.5,'actual_tenant_cost'=>1.25]);
            return ['content'=>json_encode($this->plan())];
        });
        self::assertFalse($result);self::assertSame('canceled',$this->row($t)['status']);self::assertSame(5.5,(float)$this->row($t)['user_charge_points']);
        self::assertFalse(Fallback::run($t['tenant_id'],$t['user_id'],$t,static fn($p)=>$p,static function(){self::fail('Canceled task cannot submit');}));
        self::assertSame('received',Db::name('aigc_short_drama_planning_unit')->where('task_id',$t['task_id'])->value('status'));
    }
    public function testMalformedPrimaryQueuesOneFallbackAndRepeatedPollWaits():void
    {
        $t=$this->fixture();$sync=new \ReflectionMethod(Service::class,'syncMarketFileQaScriptTask');$sync->setAccessible(true);
        $sync->invoke(null,$t);self::assertSame('queued',$this->row($t)['status']);$first=$this->row($t);
        $sync->invoke(null,$t);self::assertSame($first,$this->row($t));
        self::assertSame(0,Db::name('aigc_short_drama_planning_unit')->where('task_id',$t['task_id'])->count(),'HTTP polling cannot call the model');
    }
    public function testValidPrimaryBypassesTheModelAndSourceMismatchQueuesRepair():void
    {
        $t=$this->fixture();$r=json_decode($t['request_json'],true);$plan=\app\common\service\app\aigc_short_drama\ShortDramaFileModelParser::attachSource($this->plan(),$r['_file_parse_fallback']['text']);
        Db::name('ai_consumption_log')->where('app_task_id',$t['app_task_id'])->update(['response_summary'=>json_encode(['parse_result'=>$plan])]);
        $sync=new \ReflectionMethod(Service::class,'syncMarketFileQaScriptTask');$sync->setAccessible(true);$sync->invoke(null,$t);
        self::assertSame('success',$this->row($t)['status']);self::assertArrayNotHasKey('state',json_decode($this->row($t)['request_json'],true)['_file_parse_fallback']);
        self::assertSame(0,Db::name('aigc_short_drama_planning_unit')->where('task_id',$t['task_id'])->count());
        $other=$this->fixture();$plan['episodes'][0]['source_content']='甲离开旧宅，丢弃信件。';
        Db::name('ai_consumption_log')->where('app_task_id',$other['app_task_id'])->update(['response_summary'=>json_encode(['parse_result'=>$plan])]);$sync->invoke(null,$other);
        self::assertSame('queued',$this->row($other)['status']);self::assertStringContainsString('原文',json_decode($this->row($other)['request_json'],true)['_file_parse_fallback']['validation_error']);
    }
    public function testCanceledPrimaryCannotScheduleAndPlanningDispatchRecognizesQueuedRepair():void
    {
        $t=$this->fixture();Db::name('aigc_short_drama_script_task')->where('id',$t['id'])->update(['status'=>'canceled']);
        $sync=new \ReflectionMethod(Service::class,'syncMarketFileQaScriptTask');$sync->setAccessible(true);$sync->invoke(null,$t);
        self::assertSame('canceled',$this->row($t)['status']);self::assertFalse(Fallback::schedule($t['tenant_id'],$t['user_id'],$t,[],'bad'));
        $other=$this->fixture();Fallback::schedule($other['tenant_id'],$other['user_id'],$other,[],'bad');$r=json_decode($this->row($other)['request_json'],true);
        self::assertTrue(\app\common\service\app\aigc_short_drama\ShortDramaStoryWorkflow::workerOwned($r));$called=[];
        self::assertTrue(\app\common\service\app\aigc_short_drama\ShortDramaPlanningDispatch::tick($other['task_id'],$other['tenant_id'],static function($tenant,$user,$task)use(&$called){$called[]=[$tenant,$user,$task];return true;}));
        self::assertSame([[$other['tenant_id'],$other['user_id'],$other['task_id']]],$called);
    }
    public function testUnknownProviderSettlementIsRetainedWithoutAutomaticResubmission():void
    {
        $t=$this->fixture();Fallback::schedule($t['tenant_id'],$t['user_id'],$t,[],'原解析缺少字段');$calls=0;
        try {Fallback::run($t['tenant_id'],$t['user_id'],$t,static fn($p)=>$p,static function()use($t,&$calls){
            $calls++;$key=$t['task_id'].'_unknown';
            $app=Db::name('ai_app_task')->insertGetId(['tenant_id'=>$t['tenant_id'],'user_id'=>$t['user_id'],'task_no'=>$key,'idempotency_key'=>$key,'app_code'=>'aigc_short_drama','business_table'=>'aigc_short_drama_script_task','business_id'=>$t['id']]);
            Db::name('ai_consumption_log')->insert(['tenant_id'=>$t['tenant_id'],'user_id'=>$t['user_id'],'app_task_id'=>$app,'consume_no'=>$key,'run_status'=>'failed','billing_status'=>'settlement_failed','error_code'=>'provider_stream_incomplete']);
            throw new \RuntimeException('回复未完整结束，费用待核对');
        });self::fail('Must preserve failure');}catch(\RuntimeException $e){self::assertStringContainsString('费用待核对',$e->getMessage());}
        $saved=$this->row($t);self::assertSame('settlement_failed',$saved['billing_status']);
        $f=json_decode($saved['request_json'],true)['_file_parse_fallback'];self::assertSame('原解析缺少字段',$f['validation_error']);self::assertStringContainsString('费用待核对',$f['repair_error']);
        self::assertFalse(Fallback::run($t['tenant_id'],$t['user_id'],$t,static fn($p)=>$p));self::assertSame(1,$calls);
        // A late settlement updates money only, without resurrecting the failed task.
        Db::name('ai_consumption_log')->where('consume_no',$t['task_id'].'_unknown')->update(['billing_status'=>'settled','run_status'=>'success','actual_user_price'=>3.5,'actual_tenant_cost'=>1.25]);
        $sync=new \ReflectionMethod(Service::class,'syncMarketFileQaScriptTask');$sync->setAccessible(true);$sync->invoke(null,$this->row($t));
        self::assertSame('failed',$this->row($t)['status']);self::assertSame('settled',$this->row($t)['billing_status']);self::assertSame(5.5,(float)$this->row($t)['user_charge_points']);
    }
    public function testCancellationDuringPreflightStopsAtTheRealRuntimeAppTaskEvent():void
    {
        $t=$this->fixture();Fallback::schedule($t['tenant_id'],$t['user_id'],$t,[],'缺少字段');$submitted=0;
        try {Fallback::run($t['tenant_id'],$t['user_id'],$t,static fn($p)=>$p,function($input,$event)use($t,&$submitted){
            Db::name('aigc_short_drama_script_task')->where('id',$t['id'])->update(['status'=>'canceled']);
            $event('app_task');$submitted++;return ['content'=>json_encode($this->plan())];
        });self::fail('Canceled preflight must stop');}catch(\RuntimeException $e){self::assertSame(409,$e->getCode());}
        self::assertSame(0,$submitted);self::assertSame('canceled',$this->row($t)['status']);
    }
}
