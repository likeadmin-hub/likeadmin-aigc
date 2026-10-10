<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationProductionRevision as Revision;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow as Workflow;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService;
use app\common\service\app\aigc_short_drama\canvas_agent\MarketTextConversationProvider as Provider;
use app\common\service\app\aigc_short_drama\canvas_agent\AutoGenerationDispatcher as Dispatcher;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\power\MarketTextModelRuntimeService as Runtime;
use app\common\service\power\MarketTextModelProviderException as Rejection;
use think\facade\Db;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CanvasCreativeRuntimeTest extends TestCase
{
    protected function setUp(): void { require __DIR__.'/../fixtures/canvas_creative_runtime.php'; }
    private function request(bool $image=false): array { return ['messages'=>[['role'=>'user','content'=>'Describe']], 'settings'=>['reasoning_model'=>['id'=>'text']], 'context'=>['_input_contract_version'=>1,'test_images'=>$image?['https://example.test/owned.png']:[]],'max_tokens'=>1024]; }
    private function models(): void { Runtime::$models=[['id'=>'text','context_window'=>100000,'max_tokens'=>8192],['id'=>'vision1','supports_vision'=>true,'context_window'=>100000,'max_tokens'=>8192],['id'=>'vision2','supports_vision'=>true,'context_window'=>100000,'max_tokens'=>8192]]; }
    public function testImageRoutesOnlyToVisionModelsAndRejectFallsThrough(): void {
        $this->models();Runtime::$errors['vision1']=new Rejection('unavailable',true);
        $p=new Provider();$p->preflight(701,23,$this->request(true));$r=$p->generate(701,23,$this->request(true));
        self::assertSame('vision description',$r['content']);self::assertSame(['vision1','vision2'],array_column(array_column(Runtime::$calls,'model_selection'),'id'));
        self::assertSame('image_url',Runtime::$calls[0]['messages'][0]['content'][1]['type']);
        self::assertTrue(Runtime::$calls[0]['_disable_model_fallback']);
    }
    public function testNoVisionModelFailsBeforeAnyPaidCall(): void {
        $this->models();Runtime::$models=[Runtime::$models[0]];
        try {(new Provider())->preflight(701,23,$this->request(true));self::fail('accepted');}
        catch(\RuntimeException $e) {self::assertSame('VISION_MODEL_UNAVAILABLE',$e->getMessage());self::assertSame([],Runtime::$calls);}
    }
    public function testUnknownOutcomeNeverUsesAnotherModel(): void {
        $this->models();Runtime::$errors['text']=new Rejection('timeout or partial result',false);
        try {(new Provider())->generate(701,23,$this->request());self::fail('accepted');}
        catch(Rejection $e) {self::assertCount(1,Runtime::$calls);}
    }
    public function testAllRejectedCandidatesAreTriedExactlyOnce(): void {
        $this->models();foreach(Runtime::$models as $m) Runtime::$errors[$m['id']]=new Rejection('unavailable',true);
        try {(new Provider())->generate(701,23,$this->request());self::fail('accepted');}
        catch(Rejection $e) {self::assertSame(['text','vision1','vision2'],array_column(array_column(Runtime::$calls,'model_selection'),'id'));}
    }
    public function testEvaluationCannotFallBackOutsideExplicitlyAllowedModel(): void {
        $this->models();Runtime::$errors['text']=new Rejection('unavailable',true);
        $request=$this->request();$request['context']['evaluation_model_allowlist']=['text'];
        try {(new Provider())->generate(701,23,$request);self::fail('Fallback escaped evaluation scope');}
        catch(Rejection $e) {self::assertSame(['text'],array_column(array_column(Runtime::$calls,'model_selection'),'id'));}
    }
    private function production(): array {
        $source=['id'=>1,'tenant_id'=>701,'user_id'=>23,'delete_time'=>0,'project_id'=>9,'task_id'=>'original','status'=>'success','request_json'=>'{}','result_json'=>json_encode(['storyboard'=>[['shot_id'=>'s1','dialogue'=>'old dialogue']]])];
        Db::$tables['aigc_short_drama_script_task']=[$source];Db::$tables['aigc_short_drama_project']=[['id'=>9,'tenant_id'=>701,'user_id'=>23,'delete_time'=>0,'last_task_id'=>'original']];
        $state=['stage_state'=>['key'=>'episode','status'=>'awaiting_stage_confirmation'],'state_revision'=>5,'series'=>['tasks'=>['episode_1'=>['task_id'=>'original','project_id'=>9]]],'artifact_memory'=>[]];
        return Revision::reopen($state,'episode','第一个镜头只改对白',['task_id'=>'original','project_id'=>9,'stamp'=>Revision::stamp($source)]);
    }
    public function testRevisionIsIdempotentAndAdoptionUsesSuccessfulFormalVersion(): void {
        $s=$this->production();$b=Revision::bind(701,23,77,$s);self::assertSame($b,Revision::bind(701,23,77,$s));self::assertCount(1,AigcShortDramaService::$calls);
        self::assertSame('original',$s['series']['tasks']['episode_1']['task_id']);
        $s['revision_request']['result_stamp']=Revision::stamp(Revision::task(701,23,'revised'));
        Revision::adopt($s,701,23);self::assertSame('revised',$s['series']['tasks']['episode_1']['task_id']);
        self::assertSame('original',$s['series']['task_history']['episode_1'][0]['task_id']);
    }
    public function testSourceEditOrWrongTenantCannotStartRevision(): void {
        $s=$this->production();
        try {Revision::bind(702,23,77,$s);self::fail('cross tenant');}catch(\RuntimeException $e) {self::assertSame('WORKFLOW_PRODUCTION_NOT_FOUND',$e->getMessage());}
        Db::$tables['aigc_short_drama_script_task'][0]['request_json']='{"edited":true}';
        try {Revision::bind(701,23,77,$s);self::fail('stale source');}catch(\RuntimeException $e) {self::assertSame('WORKFLOW_VERSION_CONFLICT',$e->getMessage());self::assertSame([],AigcShortDramaService::$calls);}
    }
    public function testProjectionKeepsNodeIdsAndRejectsManualEdits(): void {
        $meta=['workflow_source_stage'=>'episode','workflow_artifact'=>'episode_script','content'=>'original','prompt'=>'original','content_revision'=>2];
        $doc=['id'=>1,'tenant_id'=>701,'user_id'=>23,'delete_time'=>0,'graph_revision'=>1,'nodes_json'=>json_encode([['id'=>'1','type'=>'text','metadata'=>$meta],['id'=>'2','type'=>'image','metadata'=>['asset_id'=>55]]])];
        Db::$tables[GraphService::TABLE]=[$doc];
        $memory=[['stage'=>'episode','artifact'=>'episode_script','key'=>'episode_1_1','node_id'=>'1','content'=>'original']];
        $proposals=[['type'=>'text','artifact'=>'episode_script','key'=>'episode_1_1','prompt'=>'revised','title'=>'new']];
        $r=GraphService::replaceWorkflowScriptLocked($doc,$proposals,$memory,'episode');self::assertSame('1',$r['nodes'][0]['id']);
        $nodes=json_decode(Db::$tables[GraphService::TABLE][0]['nodes_json'],true);self::assertSame('revised',$nodes[0]['metadata']['content']);self::assertSame(55,$nodes[1]['metadata']['asset_id']);
        try {GraphService::replaceWorkflowScriptLocked(Db::$tables[GraphService::TABLE][0],$proposals,$memory,'episode');self::fail('manual edit overwritten');} catch(\RuntimeException $e) {self::assertSame('WORKFLOW_PLAN_SOURCE_CHANGED',$e->getMessage());}
    }
    public function testScannerReachesThirtyFifthCanvasAndWrapsPerTenant(): void {
        Db::$tables[GraphService::TABLE]=[];
        for($i=1;$i<=35;$i++) Db::$tables[GraphService::TABLE][]=['id'=>$i,'tenant_id'=>701,'user_id'=>23,'delete_time'=>0,'nodes_json'=>'[]','edges_json'=>'[]'];
        self::assertSame(20,Dispatcher::tick(701)['scanned']);self::assertSame(20,\think\facade\Cache::$values['short-drama:auto-canvas-cursor:701']);
        self::assertSame(15,Dispatcher::tick(701)['scanned']);self::assertSame(35,\think\facade\Cache::$values['short-drama:auto-canvas-cursor:701']);
        self::assertSame(0,Dispatcher::tick(702)['scanned']);self::assertSame(20,Dispatcher::tick(701)['scanned']);
    }
}
