<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Completed provider receipts only; no provider request, worker dispatch or real wallet writes. */
final class ShortDramaUploadedSourceDeliveryTest extends TestCase
{
    private bool $transaction = false;
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Disposable local database only');
        (new \think\App())->initialize();
        self::assertSame('short_drama_alignment_test', Db::query('SELECT DATABASE() AS db')[0]['db']);
        Db::startTrans(); $this->transaction = true;
    }
    protected function tearDown(): void { if ($this->transaction) Db::rollback(); }
    private function fixture(string $status = 'running', bool $invalid = false): array
    {
        $scope=['tenant_id'=>2000000801,'user_id'=>7];$key='upload_align_'.bin2hex(random_bytes(8));
        $source="人物设定：甲是信使。\r\n\r\n镜头1：甲找到旧信。\r\n甲循着地址赶往旧宅。\r\n镜头2：乙交出日记。\r\n乙坦白了信件的来历。\r\n附录：保持人物服饰一致。\r\n";
        $raw=['title'=>'旧信','type_judgement'=>'悬疑','core_theme'=>'寻找故人','story_outline'=>'甲找到旧信，向乙追问来历并拿到日记。',
            'subjects'=>[['name'=>'甲','category'=>'character','description'=>'寻找故人的信使']],
            'locations'=>[['name'=>'旧宅','description'=>'存放旧信的木屋']],
            'episodes'=>[
                ['episode_no'=>1,'title'=>'旧信','summary'=>'甲找到旧信，循着地址赶往旧宅。','conflict_point'=>'署名与记忆矛盾。','ending_hook'=>'甲在门前遇到乙。',
                    'content'=>$invalid?'甲烧毁了旧信。':"镜头1：甲找到旧信。 甲循着地址赶往旧宅。",'source_range'=>['start_line'=>1,'end_line'=>2]],
                ['episode_no'=>2,'title'=>'日记','summary'=>'乙交出日记，坦白了信件的来历。','conflict_point'=>'乙不愿透露过去。','ending_hook'=>'日记揭开另一段往事。',
                    'content'=>"镜头2：乙交出日记。\n乙坦白了信件的来历。",'source_range'=>['start_line'=>3,'end_line'=>4]],
            ]];
        $project=Db::name('aigc_short_drama_project')->insertGetId($scope+['title'=>'旧信','last_task_id'=>$key,'multi_episode'=>1,'episode_count'=>2]);
        $app=Db::name('ai_app_task')->insertGetId($scope+['task_no'=>$key,'idempotency_key'=>$key,'app_code'=>'aigc_short_drama','action_code'=>'script_parse','status'=>'success']);
        $receipt=json_encode(['parse_result'=>$raw],JSON_UNESCAPED_UNICODE);
        $consumption=Db::name('ai_consumption_log')->insertGetId($scope+['consume_no'=>$key,'app_task_id'=>$app,'app_code'=>'aigc_short_drama',
            'action_code'=>'script_parse','run_status'=>'success','billing_status'=>'settled','actual_user_price'=>2,'actual_tenant_cost'=>1,'response_summary'=>$receipt]);
        $request=['source'=>'market_file_qa_parse','parse_mode'=>'split_by_ai','multi_episode'=>true,'episode_count'=>2,'_generation_version'=>3,
            '_input_contract_version'=>30,'workflow_variant'=>'story_outline_v2','multi_episode_stage'=>'story',
            '_file_parse_fallback'=>['version'=>1,'text'=>$source],'submission'=>['type'=>'script_upload','attachment_name'=>'fixture.docx','instruction'=>'按照剧本进行策划']];
        $id=Db::name('aigc_short_drama_script_task')->insertGetId($scope+['project_id'=>$project,'task_id'=>$key,'app_task_id'=>$app,'status'=>$status,
            'prompt'=>'按照剧本进行策划','request_json'=>json_encode($request),'provider_task_id'=>'completed_fixture','create_time'=>time()]);
        Db::name('ai_app_task')->where('id',$app)->update(['business_table'=>'aigc_short_drama_script_task','business_id'=>$id]);
        Db::name('aigc_short_drama_generation_task')->insert($scope+['project_id'=>$project,'task_id'=>$key,'task_type'=>'script_plan','app_task_id'=>$app,
            'consumption_id'=>$consumption,'source_app_code'=>'file_qa','status'=>$status,'billing_status'=>'settled']);
        return ['task'=>Db::name('aigc_short_drama_script_task')->where('id',$id)->find(),'source'=>$source,'receipt'=>$receipt,'consumption'=>$consumption];
    }
    private function sync(array $task): void { (new \ReflectionMethod(Service::class,'syncMarketFileQaScriptTask'))->invoke(null,$task); }
    public function testCompletedReceiptIsPublishedAndReturnedWithoutAnotherModelCallOrCharge(): void
    {
        $fixture=$this->fixture();$task=$fixture['task'];$this->sync($task);
        $saved=Db::name('aigc_short_drama_script_task')->where('id',$task['id'])->find();
        self::assertSame('success',$saved['status']);self::assertSame('',$saved['error']);
        $request=json_decode($saved['request_json'],true);$outline=$request['_imported_outline_snapshot'];
        self::assertSame($fixture['source'],implode('',array_column($outline['episodes'],'source_content')));
        self::assertArrayNotHasKey('state',$request['_file_parse_fallback']);
        $detail=Service::scriptPlanDetail($task['tenant_id'],$task['user_id'],$task['task_id'],$task['project_id']);
        self::assertSame('success',$detail['status']);self::assertSame('',$detail['error']);
        self::assertSame('旧信',$detail['result']['title']);self::assertIsArray($detail['story_workspace']);
        self::assertSame('fixture.docx',$detail['submission']['attachment_name']);
        self::assertSame('success',Db::name('aigc_short_drama_generation_task')->where('task_id',$task['task_id'])->value('status'));
        $versions=Db::name('aigc_short_drama_plan_version')->where('task_id',$task['task_id'])->count();self::assertSame(1,$versions);
        $this->sync($task);self::assertSame($versions,Db::name('aigc_short_drama_plan_version')->where('task_id',$task['task_id'])->count());
        self::assertSame(1,Db::name('ai_app_task')->where(['tenant_id'=>$task['tenant_id'],'business_id'=>$task['id']])->count());
        self::assertSame(0,Db::name('aigc_short_drama_planning_unit')->where('task_id',$task['task_id'])->count());
        $receipt=Db::name('ai_consumption_log')->where('id',$fixture['consumption'])->find();
        self::assertSame($fixture['receipt'],$receipt['response_summary']);self::assertSame('settled',$receipt['billing_status']);
        self::assertSame(2.0,(float)$receipt['actual_user_price']);self::assertSame(1.0,(float)$receipt['actual_tenant_cost']);
    }
    public function testUnrelatedContentIsRejectedAndOriginalReceiptIsRetained(): void
    {
        $fixture=$this->fixture('running',true);$this->sync($fixture['task']);
        $saved=Db::name('aigc_short_drama_script_task')->where('id',$fixture['task']['id'])->find();
        self::assertSame('failed',$saved['status']);self::assertStringContainsString('原文',$saved['error']);
        self::assertSame($fixture['receipt'],Db::name('ai_consumption_log')->where('id',$fixture['consumption'])->value('response_summary'));
        self::assertSame(0,Db::name('aigc_short_drama_plan_version')->where('task_id',$saved['task_id'])->count());
    }
    public function testCanceledOrSupersededTaskCannotPublishAnAlignedReceipt(): void
    {
        $canceled=$this->fixture('canceled');$this->sync($canceled['task']);
        self::assertSame('canceled',Db::name('aigc_short_drama_script_task')->where('id',$canceled['task']['id'])->value('status'));
        $other=$this->fixture();Db::name('aigc_short_drama_project')->where('id',$other['task']['project_id'])->update(['last_task_id'=>'newer_attempt']);
        $this->sync($other['task']);
        foreach([$canceled,$other] as $fixture) self::assertSame(0,Db::name('aigc_short_drama_plan_version')->where('task_id',$fixture['task']['task_id'])->count());
    }
}
