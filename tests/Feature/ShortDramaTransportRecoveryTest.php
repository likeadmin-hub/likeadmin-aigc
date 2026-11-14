<?php
namespace Tests\Feature;

use app\common\service\power\{MarketTextModelProviderException as Failure, MarketTextModelRuntimeService as Runtime, TextSettlementPending};
use app\common\service\app\aigc_short_drama\ShortDramaPlanningUnit as Unit;
use PHPUnit\Framework\TestCase;
use think\facade\{Config,Db};

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ShortDramaTransportRecoveryTest extends TestCase
{
    private static function noRequest(): array
    {
        return ['errno'=>28,'request_size'=>0,'size_upload'=>0,'size_download'=>0,'pretransfer_time'=>0.0,'http_code'=>0,'header_size'=>0];
    }

    public function testConnectionTimeoutRequiresAffirmativeEvidenceBeforeRetry(): void
    {
        $info=self::noRequest();
        self::assertTrue((new Failure('timeout',false,null,$info))->requestNotSent());
        foreach (array_keys($info) as $key) {
            $missing=$info;unset($missing[$key]);
            self::assertFalse((new Failure('Connection timed out after 10001 milliseconds',false,null,$missing))->requestNotSent());
        }
        foreach (['request_size','size_upload','size_download','pretransfer_time','http_code','header_size'] as $key) {
            $sent=$info;$sent[$key]=1;
            self::assertFalse(Unit::retryableBeforeSubmission(new Failure('Connection timed out',false,null,$sent)));
        }
        self::assertFalse(Unit::retryableBeforeSubmission('文本模型网络请求失败：Connection timed out after 10001 milliseconds'));
        self::assertFalse(Unit::retryableBeforeSubmission('Operation timed out after 120000 milliseconds'));
        self::assertFalse((new Failure('timeout',false,['content'=>'received'],$info))->requestNotSent());
        self::assertFalse((new Failure('read error',false,null,array_replace($info,['errno'=>56])))->requestNotSent());
    }

    public function testRuntimeRetainsSanitizedEvidenceAndNeverMarksPartialOutputSafe(): void
    {
        $m=new \ReflectionMethod(Runtime::class,'transportException');$m->setAccessible(true);
        $info=self::noRequest()+['url'=>'https://secret.invalid/?key=secret','request_header'=>'Bearer secret'];
        $e=$m->invoke(null,'Connection timed out after 30001 milliseconds',[],$info);
        self::assertTrue(Unit::retryableBeforeSubmission($e));
        self::assertTrue(Unit::retryableBeforeSubmission($e->getMessage()));
        self::assertArrayNotHasKey('url',$e->transportInfo);
        self::assertArrayNotHasKey('request_header',$e->transportInfo);
        $e=$m->invoke(null,'Operation timed out',['content'=>'partial','usage'=>[]],$info);
        self::assertFalse($e->requestNotSent());
        self::assertNotNull($e->partialResult);
    }

    public function testSettlementFailurePreservesReceiptAndCannotResubmitEvenAfterRetry(): void
    {
        (new \think\App())->initialize();
        Config::set(['default'=>'transport_fixture','connections'=>['transport_fixture'=>[
            'type'=>'sqlite','database'=>':memory:','prefix'=>'la_','fields_strict'=>true,'fields_cache'=>false,
        ]]],'database');
        Db::execute('CREATE TABLE la_aigc_short_drama_planning_unit (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, task_id TEXT, unit_key TEXT, status TEXT, attempt INTEGER, error TEXT DEFAULT "", request_json TEXT, result_json TEXT, create_time INTEGER, update_time INTEGER)');
        Db::execute('CREATE TABLE la_ai_consumption_log (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, app_code TEXT, run_status TEXT, billing_status TEXT, actual_user_price NUMERIC, actual_tenant_cost NUMERIC)');
        $calls=0;$input=['content'=>'original','model_selection'=>['id'=>9]];
        $provider=static function()use(&$calls){$calls++;throw new TextSettlementPending(77,['content'=>'{"ok":true}','provider_request_id'=>'req-original'],['prompt_tokens'=>5,'completion_tokens'=>3],new \RuntimeException('wallet failed'));};
        try {Unit::call(21,7,'fixture','v3_script',$input,$provider);self::fail('Expected billing hold');} catch(TextSettlementPending) {}
        $row=Db::name('aigc_short_drama_planning_unit')->where('task_id','fixture')->find();
        self::assertSame('received',$row['status']);
        $receipt=json_decode($row['result_json'],true);
        self::assertSame('{"ok":true}',$receipt['result']['content']);
        self::assertSame(77,$receipt['result']['consumption_id']);
        self::assertSame(['id'=>9],$receipt['model']);
        Unit::prepareExplicitRetry(21,7,'fixture');
        for($i=0;$i<2;$i++) {
            try {Unit::call(21,7,'fixture','v3_script',$input,$provider);self::fail('Unsettled receipt used');}
            catch(\RuntimeException $e){self::assertStringContainsString('费用结算待核对',$e->getMessage());}
        }
        Db::name('ai_consumption_log')->insert(['id'=>77,'tenant_id'=>22,'user_id'=>7,'app_code'=>'aigc_short_drama','run_status'=>'success','billing_status'=>'settled','actual_user_price'=>4,'actual_tenant_cost'=>2]);
        try {Unit::call(21,7,'fixture','v3_script',$input,$provider);self::fail('Other tenant bill accepted');} catch(\RuntimeException) {}
        Db::name('ai_consumption_log')->where('id',77)->update(['tenant_id'=>21]);
        $result=Unit::call(21,7,'fixture','v3_script',$input,$provider);
        self::assertSame('{"ok":true}',$result['result']['content']);
        self::assertSame('settled',$result['result']['billing']['billing_status']);
        self::assertSame(1,$calls);
        self::assertSame(1,Db::name('aigc_short_drama_planning_unit')->count());
        // A prior partial stream remains blocked even if somebody settles its bill.
        $partialCalls=0;
        $partial=static function()use(&$partialCalls){$partialCalls++;throw new TextSettlementPending(78,
            ['content'=>'partial'],[],new Failure('stream interrupted',false,['content'=>'partial']));};
        try {Unit::call(21,7,'partial','v3_script',$input,$partial);self::fail('Expected partial hold');} catch(TextSettlementPending) {}
        Db::name('ai_consumption_log')->insert(['id'=>78,'tenant_id'=>21,'user_id'=>7,'app_code'=>'aigc_short_drama','run_status'=>'success','billing_status'=>'settled']);
        try {Unit::call(21,7,'partial','v3_script',$input,$partial);self::fail('Partial output replayed');}
        catch(\RuntimeException $e){self::assertStringContainsString('未完整结束',$e->getMessage());}
        self::assertSame(1,$partialCalls);

        // Certified connection failures enter the existing delayed queue and obey its backoff.
        $networkCalls=0;
        $network=static function()use(&$networkCalls){$networkCalls++;throw new Failure('文本模型连接暂时不可用（请求尚未发送）：timeout',false,null,self::noRequest());};
        for($i=0;$i<2;$i++) {
            try {Unit::call(21,7,'not_sent','v3_script',$input,$network);self::fail('Expected queue deferral');}
            catch(\RuntimeException $e){self::assertSame(425,$e->getCode());}
        }
        self::assertSame(1,$networkCalls);
        self::assertSame('waiting',Db::name('aigc_short_drama_planning_unit')->where('task_id','not_sent')->value('status'));
        $legacy=['tenant_id'=>21,'user_id'=>7,'task_id'=>'legacy','unit_key'=>'v3_script','status'=>'failed','attempt'=>1,
            'request_json'=>json_encode($input),'error'=>'文本模型网络请求失败：Connection timed out after 10001 milliseconds','create_time'=>1,'update_time'=>1];
        $legacyId=Db::name('aigc_short_drama_planning_unit')->insertGetId($legacy);
        $original=Db::name('aigc_short_drama_planning_unit')->where('id',$legacyId)->find();
        try {Unit::prepareExplicitRetry(21,7,'legacy',false,true);self::fail('Unconfirmed legacy timeout allowed');}
        catch(\RuntimeException $e){self::assertSame(409,$e->getCode());self::assertStringContainsString('新的费用',$e->getMessage());}
        self::assertSame(1,Db::name('aigc_short_drama_planning_unit')->where('task_id','legacy')->count());
        try {Unit::prepareExplicitRetry(21,7,'legacy',false,true,true);self::fail('Changed context bypassed confirmation');}
        catch(\RuntimeException $e){self::assertSame(Unit::CHANGED_CONTEXT_CONFIRMATION,$e->getMessage());}
        Unit::prepareExplicitRetry(21,7,'legacy',true);
        self::assertSame($original,Db::name('aigc_short_drama_planning_unit')->where('id',$legacyId)->find());
        self::assertSame(2,Db::name('aigc_short_drama_planning_unit')->where('task_id','legacy')->count());
        $successor=Db::name('aigc_short_drama_planning_unit')->where('task_id','legacy')->order('id','desc')->find();
        self::assertSame('pending',$successor['status']);
        self::assertSame($legacyId,json_decode($successor['request_json'],true)['_interrupted_retry_of']);


    }
}
