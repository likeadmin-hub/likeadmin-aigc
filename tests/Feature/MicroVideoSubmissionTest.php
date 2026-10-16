<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\service\app\aigc_micro_video\MicroVideoSubmission as S;
use app\common\service\app\aigc_micro_video\MicroVideoPolicy as P;
use app\common\service\app\aigc_micro_video\MicroVideoCatalog;

final class MicroVideoSubmissionTest extends TestCase
{
    protected function setUp(): void
    {
        (new \think\App(dirname(__DIR__,2).'/'))->initialize();$db=config('database.connections.mysql');
        if($db['hostname']!=='127.0.0.1'||$db['database']!=='z_cn')self::markTestSkipped('Local transactional acceptance only');Db::startTrans();
    }
    protected function tearDown(): void{Db::rollback();}
    private function consumption(string $state='reserved',string $app='aigc_micro_video'): int
    {
        $task=Db::name('ai_app_task')->order('id')->find();unset($task['id']);$task['task_no']='MT'.bin2hex(random_bytes(12));$task['app_code']=$app;$task['business_id']=0;$task['business_table']='micro_video_test';$task['status']='running';$task['idempotency_key']='micro-test-'.bin2hex(random_bytes(12));
        $parent=Db::name('ai_app_task')->insertGetId($task);
        $c=Db::name('ai_consumption_log')->order('id')->find();unset($c['id']);$c['app_task_id']=$parent;$c['app_code']=$app;$c['run_status']=$state;$c['billing_status']='reserved';$c['upstream_task_id']='';$c['upstream_request_id']='';$c['consume_no']='MC'.bin2hex(random_bytes(12));$c['price_snapshot']='{}';$c['response_summary']='{}';$c['tenant_point_sn']='';$c['user_point_sn']='';return Db::name('ai_consumption_log')->insertGetId($c);
    }
    public function testAdmissionIsDurableAndOnlyOneTransportCanStart(): void
    {
        $id=$this->consumption();self::assertTrue(S::begin($id));self::assertSame('submitting',Db::name('ai_consumption_log')->where('id',$id)->value('run_status'));self::assertFalse(S::begin($id));self::assertTrue(S::pending(Db::name('ai_consumption_log')->where('id',$id)->find()));
    }
    public function testTimeoutPreservesUnknownUntilConfirmedReceipt(): void
    {
        $id=$this->consumption('submitting');self::assertTrue(S::hold($id,'cURL error 28: Operation timed out'));$c=Db::name('ai_consumption_log')->where('id',$id)->find();self::assertSame('pending_confirmation',$c['run_status']);self::assertSame('reserved',$c['billing_status']);self::assertFalse(S::begin($id));
        S::capture($id,['task_id'=>'fixture-receipt'],'fixture-receipt','request-fixture');$c=Db::name('ai_consumption_log')->where('id',$id)->find();self::assertSame('running',$c['run_status']);self::assertFalse(S::pending($c));self::assertSame(['task_id'=>'fixture-receipt'],S::receipt($c));
    }
    public function testExplicitRejectionAndOtherApplicationsAreUnaffected(): void
    {
        $id=$this->consumption('submitting');self::assertFalse(S::hold($id,'invalid_parameter: unsupported resolution'));
        $id=$this->consumption('reserved','aigc_video');self::assertTrue(S::begin($id));self::assertSame('reserved',Db::name('ai_consumption_log')->where('id',$id)->value('run_status'));self::assertFalse(S::hold($id,'cURL error 28'));
    }
    public function testUnreadablePostResponsesNeverAuthorizeRegeneration(): void
    {
        foreach(['视频 API 响应格式错误','文本模型返回格式异常','文本模型未返回有效内容','transfer closed with outstanding read data remaining'] as $error){
            $id=$this->consumption('submitting');self::assertTrue(S::ambiguous($error));self::assertTrue(S::hold($id,$error));self::assertSame('pending_confirmation',Db::name('ai_consumption_log')->where('id',$id)->value('run_status'));self::assertFalse(S::begin($id));
        }
    }
    public function testStableAssetIdentityAndColumnBounds(): void
    {
        $a=['images'=>[['uri'=>'uploads/a.png','file_id'=>7,'url'=>'https://example.com/a?signature=old']]];$b=$a;$b['images'][0]['url']='https://example.com/a?signature=new';
        self::assertSame(P::fingerprint(P::stableParams($a)),P::fingerprint(P::stableParams($b)));$b['images'][0]['file_id']=8;self::assertNotSame(P::fingerprint(P::stableParams($a)),P::fingerprint(P::stableParams($b)));
        $this->expectException(\InvalidArgumentException::class);P::segments([['title'=>str_repeat('长',201),'duration'=>6,'prompt'=>'有效提示词','image_indices'=>[0],'start_state'=>'开始','end_state'=>'结束','continuity'=>'连续']],[6],1,1,2);
    }
    public function testReadOnlyImageModelQuoteHasNoExecutablePlaceholder(): void
    {
        foreach(MicroVideoCatalog::models(1) as $m)if($m['id']==='market_video_model:157'){$q=MicroVideoCatalog::quote(1,$m['specs'][0],$m['id']);self::assertGreaterThan(0,$q['user_charge_points']);self::assertArrayNotHasKey('reference_assets',$q['selection']);return;}self::markTestSkipped('Configured local video model unavailable');
    }
    public function testMicroNativeTransportKeepsBillingMetadataOffProviderWire(): void
    {
        $m=new \ReflectionMethod(\app\common\service\power\MarketTextModelRuntimeService::class,'initialTransportOptions');$m->setAccessible(true);
        $micro=$m->invoke(null,['protocol'=>'openai_responses'],['source_app_code'=>'aigc_micro_video','_micro_video_native_transport'=>true],false);
        self::assertFalse($micro['include_market_context']);self::assertTrue($micro['include_channel']);
        $ordinary=$m->invoke(null,['protocol'=>'openai_responses'],['source_app_code'=>'aigc_llm','_micro_video_native_transport'=>true],false);self::assertTrue($ordinary['include_market_context']);
    }
}
