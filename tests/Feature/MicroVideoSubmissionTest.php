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
        foreach(MicroVideoCatalog::models(1,true) as $m)if($m['id']==='market_video_model:157'){$q=MicroVideoCatalog::quote(1,$m['specs'][0],$m['id']);self::assertGreaterThan(0,$q['user_charge_points']);self::assertArrayNotHasKey('reference_assets',$q['selection']);return;}self::markTestSkipped('Configured local video model unavailable');
    }
    public function testMicroNativeTransportKeepsBillingMetadataOffProviderWire(): void
    {
        $m=new \ReflectionMethod(\app\common\service\power\MarketTextModelRuntimeService::class,'initialTransportOptions');$m->setAccessible(true);
        $micro=$m->invoke(null,['protocol'=>'openai_responses'],['source_app_code'=>'aigc_micro_video','_micro_video_native_transport'=>true],false);
        self::assertFalse($micro['include_market_context']);self::assertTrue($micro['include_channel']);
        $ordinary=$m->invoke(null,['protocol'=>'openai_responses'],['source_app_code'=>'aigc_llm','_micro_video_native_transport'=>true],false);self::assertTrue($ordinary['include_market_context']);
    }
    public function testCompletedReceiptSurvivesSettlementFailureWithoutEarlyDelivery(): void
    {
        $id=$this->consumption('success');$videos=[['video_uri'=>'uploads/micro-fixture.mp4']];
        Db::name('ai_consumption_log')->where('id',$id)->update(['response_summary'=>json_encode(['videos'=>$videos]),'reserved_tenant_cost'=>0,'reserved_user_price'=>0,'price_snapshot'=>json_encode(['quantity'=>1,'tenant_price'=>0,'platform_price'=>0])]);
        self::assertTrue(S::hold($id,'settlement database error'));
        $method=new \ReflectionMethod(\app\common\service\power\MarketVideoRuntimeService::class,'response');$method->setAccessible(true);
        $before=$method->invoke(null,Db::name('ai_consumption_log')->where('id',$id)->find());self::assertSame([],$before['videos']);self::assertSame('running',$before['status']);
        Db::name('ai_consumption_log')->where('id',$id)->update(['user_id'=>987654399,'upstream_task_id'=>'accepted-no-query-needed','price_snapshot'=>json_encode(['quantity'=>1,'tenant_price'=>1,'platform_price'=>0])]);
        $blocked=\app\common\service\power\MarketVideoRuntimeService::refresh($id);
        self::assertSame([],$blocked['videos']);self::assertSame('running',$blocked['status']);self::assertSame('reserved',Db::name('ai_consumption_log')->where('id',$id)->value('billing_status'));
        self::assertSame($videos,json_decode(Db::name('ai_consumption_log')->where('id',$id)->value('response_summary'),true)['videos']);
        Db::name('ai_consumption_log')->where('id',$id)->update(['price_snapshot'=>json_encode(['quantity'=>1,'tenant_price'=>0,'platform_price'=>0])]);
        $result=\app\common\service\power\MarketVideoRuntimeService::refresh($id);
        self::assertSame('settled',Db::name('ai_consumption_log')->where('id',$id)->value('billing_status'));self::assertSame($videos,$result['videos']);self::assertSame('success',$result['status']);
    }
    public function testRetainedSynchronousReceiptRecoversWithoutTaskIdOrProviderCall(): void
    {
        $id=$this->consumption('submitting');S::capture($id,['video_url'=>'uploads/micro-fixture.mp4'],'','');
        Db::name('ai_consumption_log')->where('id',$id)->update(['reserved_tenant_cost'=>0,'reserved_user_price'=>0,'price_snapshot'=>json_encode(['quantity'=>1,'tenant_price'=>0,'platform_price'=>0])]);
        $result=\app\common\service\power\MarketVideoRuntimeService::refresh($id);
        self::assertSame('settled',Db::name('ai_consumption_log')->where('id',$id)->value('billing_status'));self::assertNotEmpty($result['videos']);
    }
    public function testCrashReservationRecoversBeforeLookingUpChangedMarketPrice(): void
    {
        $id=$this->consumption();$c=Db::name('ai_consumption_log')->where('id',$id)->find();
        $attemptId=Db::name('aigc_micro_video_attempt')->insertGetId(['tenant_id'=>$c['tenant_id'],'user_id'=>$c['user_id'],'task_id'=>987654001,'segment_id'=>987654002,'kind'=>'video','attempt_no'=>1,'status'=>'prepared','request_json'=>'{}','result_json'=>'{}']);
        $key=sha1($c['tenant_id'].'|aigc_micro_video|video_generate|aigc_micro_video_segment|micro:'.$attemptId.'|market_video');
        Db::name('ai_app_task')->where('id',$c['app_task_id'])->update(['idempotency_key'=>$key]);
        $expected=['tenant_cost_points'=>(float)$c['reserved_tenant_cost'],'user_charge_points'=>(float)$c['reserved_user_price'],'market_sku_id'=>(int)$c['sku_id']];
        $task=['id'=>987654001,'tenant_id'=>$c['tenant_id'],'user_id'=>$c['user_id'],'confirmed_quote'=>json_encode(['segments'=>['987654002'=>$expected]])];
        // The deliberately unavailable selection would fail any live quote or fresh reserve.
        $selection=['resource_type'=>'model','market_product_id'=>0,'market_sku_id'=>0,'model_id'=>'unavailable-after-price-change'];
        $before=Db::name('ai_consumption_log')->count();$attempt=\app\common\service\app\aigc_micro_video\MicroVideoReservation::reserve($task,['id'=>987654002],['id'=>$attemptId],$selection,[]);
        self::assertSame($id,(int)$attempt['consumption_id']);self::assertSame($before,Db::name('ai_consumption_log')->count());self::assertSame(987654002,(int)Db::name('ai_app_task')->where('id',$c['app_task_id'])->value('business_id'));
    }

    public function testChangedFreshAdmissionPriceRollsBackReservationAndLink(): void
    {
        $model=null;foreach(MicroVideoCatalog::models(1,true) as $m)if($m['id']==='market_video_model:157')$model=$m;
        if(!$model)self::markTestSkipped('Configured local model unavailable');
        $selection=$model['specs'][0];$quote=MicroVideoCatalog::quote(1,$selection,$model['id']);$selection=$quote['selection'];
        $quote['user_charge_points']+=1;
        $attemptId=Db::name('aigc_micro_video_attempt')->insertGetId(['tenant_id'=>1,'user_id'=>1,'task_id'=>987654001,'segment_id'=>987654002,'kind'=>'video','attempt_no'=>1,'status'=>'prepared','request_json'=>'{}','result_json'=>'{}']);
        $task=['id'=>987654001,'tenant_id'=>1,'user_id'=>1,'confirmed_quote'=>json_encode(['segments'=>['987654002'=>$quote]])];
        $asset=['type'=>'image','uri'=>'uploads/reservation-test.png','role'=>$selection['generation_method']==='image_to_video'?'first_frame_image':'reference_image'];
        $request=$selection+['reference_images'=>[$asset['uri']],'reference_assets'=>[$asset],'prompt'=>'transactional reservation fixture'];
        $before=Db::name('ai_consumption_log')->count();$tasks=Db::name('ai_app_task')->count();
        try{\app\common\service\app\aigc_micro_video\MicroVideoReservation::reserve($task,['id'=>987654002],['id'=>$attemptId],$selection,$request);self::fail('Changed price accepted');}catch(\RuntimeException $error){self::assertStringContainsString('售价已变化',$error->getMessage());}
        self::assertSame($before,Db::name('ai_consumption_log')->count());self::assertSame($tasks,Db::name('ai_app_task')->count());self::assertSame(0,(int)Db::name('aigc_micro_video_attempt')->where('id',$attemptId)->value('consumption_id'));
    }

    public function testWorkerNeverDeliversCachedVideoBeforeSuccessfulSettlement(): void
    {
        $id=$this->consumption('success');$c=Db::name('ai_consumption_log')->where('id',$id)->find();$videos=[['video_uri'=>'uploads/never-read-before-settlement.mp4']];
        Db::name('ai_consumption_log')->where('id',$id)->update(['user_id'=>987654399,'reserved_tenant_cost'=>0,'reserved_user_price'=>0,'response_summary'=>json_encode(['videos'=>$videos]),'price_snapshot'=>json_encode(['quantity'=>1,'tenant_price'=>1,'platform_price'=>0])]);
        $segment=['tenant_id'=>$c['tenant_id'],'user_id'=>987654399,'task_id'=>987654001,'sort'=>1,'title'=>'恢复','duration'=>4,'prompt'=>'原提示词','image_indices'=>'[0]','start_state'=>'原开始','end_state'=>'原结束','continuity'=>'保持原样','status'=>'running'];
        $segment['id']=Db::name('aigc_micro_video_segment')->insertGetId($segment);
        $segment['attempt_id']=Db::name('aigc_micro_video_attempt')->insertGetId(['tenant_id'=>$c['tenant_id'],'user_id'=>987654399,'task_id'=>987654001,'segment_id'=>$segment['id'],'kind'=>'video','attempt_no'=>1,'status'=>'storing','consumption_id'=>$id,'request_json'=>json_encode(['selection'=>['resource_type'=>'model'],'request'=>[]]),'result_json'=>json_encode(['status'=>'success','videos'=>$videos])]);
        Db::name('aigc_micro_video_segment')->where('id',$segment['id'])->update(['attempt_id'=>$segment['attempt_id']]);
        $task=['id'=>987654001,'tenant_id'=>$c['tenant_id'],'user_id'=>987654399,'params_json'=>'{}','config_snapshot'=>'{}','model_snapshot'=>'{}'];
        $method=new \ReflectionMethod(\app\common\service\app\aigc_micro_video\MicroVideoWorker::class,'segment');$method->setAccessible(true);$method->invoke(null,$task,$segment);
        self::assertSame('running',Db::name('aigc_micro_video_segment')->where('id',$segment['id'])->value('status'));self::assertSame('reserved',Db::name('ai_consumption_log')->where('id',$id)->value('billing_status'));
        self::assertSame([],json_decode(Db::name('aigc_micro_video_attempt')->where('id',$segment['attempt_id'])->value('result_json'),true)['videos']);
    }

    public function testImageUnknownAdmissionNeverSubmitsOrRefundsAgain(): void
    {
        $id=$this->consumption('submitting');self::assertTrue(S::hold($id,'图片模型未返回任务 ID 或结果'));$before=Db::name('ai_consumption_log')->count();
        $result=\app\common\service\power\MarketImageModelRuntimeService::refresh($id);
        self::assertSame('running',$result['status']);self::assertSame([],$result['images']);self::assertSame('reserved',Db::name('ai_consumption_log')->where('id',$id)->value('billing_status'));self::assertFalse(S::begin($id));self::assertSame($before,Db::name('ai_consumption_log')->count());
    }
    public function testImageRetainedReceiptRecoversSettlementWithoutProviderOrStorageReplay(): void
    {
        $id=$this->consumption('running');$images=[['image_uri'=>'uploads/retained-image-fixture.png','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'']];
        Db::name('ai_consumption_log')->where('id',$id)->update(['user_id'=>987654399,'reserved_tenant_cost'=>0,'reserved_user_price'=>0,'response_summary'=>json_encode(['images'=>$images,'micro_provider_receipt'=>['images'=>[['url'=>'https://never-request.invalid/image.png']]]]),'price_snapshot'=>json_encode(['quantity'=>1,'tenant_price'=>1,'platform_price'=>0])]);
        $before=Db::name('ai_consumption_log')->count();$blocked=\app\common\service\power\MarketImageModelRuntimeService::refresh($id);self::assertSame([],$blocked['images']);self::assertSame('running',$blocked['status']);self::assertSame('reserved',Db::name('ai_consumption_log')->where('id',$id)->value('billing_status'));self::assertSame($images,json_decode(Db::name('ai_consumption_log')->where('id',$id)->value('response_summary'),true)['images']);
        Db::name('ai_consumption_log')->where('id',$id)->update(['price_snapshot'=>json_encode(['quantity'=>1,'tenant_price'=>0,'platform_price'=>0])]);$result=\app\common\service\power\MarketImageModelRuntimeService::refresh($id);self::assertSame($images,$result['images']);self::assertSame('success',$result['status']);self::assertSame('settled',Db::name('ai_consumption_log')->where('id',$id)->value('billing_status'));self::assertSame($before,Db::name('ai_consumption_log')->count());self::assertNotNull(S::receipt(Db::name('ai_consumption_log')->where('id',$id)->find()));
    }

    public function testAcceptedImageErrorEnvelopeRetainsTaskReceiptWithoutRefundOrResubmit(): void
    {
        $id=$this->consumption('submitting');$receipt=['error'=>['message'=>'temporarycapacity'],'task_id'=>'accepted-image-fixture','request_id'=>'accepted-request'];$error=new \app\common\service\power\MarketImageProviderRequestException('temporarycapacity',true,500,true,$receipt);
        $method=new \ReflectionMethod(\app\common\service\power\MarketImageModelRuntimeService::class,'holdMicroSubmissionError');$method->setAccessible(true);self::assertTrue($method->invoke(null,$id,$error));$c=Db::name('ai_consumption_log')->where('id',$id)->find();self::assertSame('reserved',$c['billing_status']);self::assertSame('running',$c['run_status']);self::assertSame('accepted-image-fixture',$c['upstream_task_id']);self::assertSame($receipt,S::receipt($c));self::assertFalse(S::begin($id));
        $retry=new \ReflectionMethod(\app\common\service\power\MarketImageModelRuntimeService::class,'shouldRetrySubmit');$retry->setAccessible(true);self::assertFalse($retry->invoke(null,$error,1,false));
    }

}
