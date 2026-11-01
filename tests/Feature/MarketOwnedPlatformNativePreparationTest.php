<?php
namespace Tests\Feature;

use app\common\service\power\MarketOwnedPlatformContractBuilder as Builder;
use app\common\service\power\MarketOwnedPlatformContractRules as Owned;
use app\common\service\power\MarketGenerationContractReview as Review;
use app\common\service\power\MarketGenerationReviewedContract as Reviewed;
use app\common\service\power\MarketGenerationMaterials as Materials;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use PHPUnit\Framework\TestCase;

/** Synthetic bindings exercise the exact new pin branch; they are never deployed evidence or supplier requests. */
class MarketOwnedPlatformNativePreparationTest extends TestCase
{
    private static function snapshot(string $model,bool $newPin=true): array
    {
        $profile=array_values(array_filter(Builder::profiles(),static fn($p)=>$p['model_code']===$model))[0];
        $binding=['params_schema'=>['synthetic_original_marker'=>['type'=>'string']],'material_capabilities'=>[]];
        $entry=(new \ReflectionMethod(Builder::class,'entry'))->invoke(null,$profile,$binding,[]);
        $artifact='Synthetic native preparation evidence only';
        $source=['kind'=>'gateway_validator','reference'=>'synthetic/native','revision'=>Owned::REVISION,
            'reviewed_at'=>'2026-11-01T00:00:00+08:00','artifact_sha256'=>hash('sha256',$artifact)];
        $contract=Review::reviewedCatalogue(['version'=>1,'source'=>$source,'entries'=>[$entry]],$artifact)['contracts'][0];
        if($newPin){
            $contract['source']['reference']='https://gitee.com/yc_open/platform-api-saas/tree/'.Owned::REVISION;
            $contract['source']['artifact_sha256']=Builder::ARTIFACT_SHA256;
        }
        $contract['contract_digest']=Reviewed::contractDigest($contract);
        $metadata=$binding+['upstream_metadata'=>['model_code'=>$model,'channel_code'=>$profile['channel_code']],Reviewed::PIN=>$contract];
        return ['resource_type'=>'model','model_code'=>$model,'channel_code'=>$profile['channel_code'],'locked_params'=>[],
            'params_schema'=>$binding['params_schema'],'market_metadata'=>$metadata];
    }

    private static function qwen(): array
    {
        return ['material_mode'=>'reference','provider_params'=>['input'=>['messages'=>[['role'=>'user','content'=>[
            ['image'=>'https://fixtures.invalid/same.png'],['text'=>'raw native text'],['image'=>'https://fixtures.invalid/same.png']]]]],
            'parameters'=>['n'=>1,'size'=>'1024*1024','seed'=>0,'watermark'=>false,'prompt_extend'=>false]]];
    }

    private static function h3(): array
    {
        return ['material_mode'=>'first_frame','provider_params'=>['content'=>[
            ['type'=>'text','text'=>'first native text'],['type'=>'image_url','image_url'=>['url'=>'https://fixtures.invalid/first.png']],
            ['type'=>'text','text'=>'second native text']], 'ratio'=>'16:9','resolution'=>'2K','duration'=>5]];
    }

    private static function wan(): array
    {
        $assets=[];
        foreach(['image'=>10,'video'=>5,'audio'=>5,'file'=>1]as$type=>$count)
            for($i=0;$i<$count;$i++)$assets[]=['type'=>$type,'role'=>'reference_'.$type,'url'=>'https://fixtures.invalid/'.$type.'/'.$i];
        return ['material_mode'=>'reference','prompt'=>'Synthetic references','quality'=>'720P','ratio'=>'16:9','duration'=>5,'reference_assets'=>$assets];
    }

    /** @dataProvider qwenModels */
    public function testQwenRealPrepareRetainsWholeNativeMessageAndFalseZeroWithFrozenReplay(string $model): void
    {
        $snapshot=self::snapshot($model);$before=Reviewed::digest($snapshot);$request=self::qwen();$original=$request;
        $a=Images::prepareRequest($snapshot,$request);$b=Images::prepareRequest($snapshot,$request);
        self::assertSame($request['provider_params']['input'],$a->nativeInput['input']);
        self::assertSame(0,$a->nativeInput['parameters']['seed']);self::assertFalse($a->nativeInput['parameters']['watermark']);
        self::assertFalse($a->nativeInput['parameters']['prompt_extend']);self::assertSame($model,$a->nativeInput['model']);
        self::assertSame('dashscope_compatible',$a->nativeInput['channel']);self::assertSame($a->nativeInput,$b->nativeInput);
        self::assertSame($original,$request);self::assertSame($before,Reviewed::digest($snapshot));
    }

    public static function qwenModels(): array {return [['qwen-image-3.0'],['qwen-image-3.0-pro']];}

    /** @dataProvider badQwenNative */
    public function testQwenActualPrepareRejectsRatherThanRebuildingInvalidOrConflictingNativeInput(string $case): void
    {
        $request=self::qwen();
        switch($case){
            case 'two_texts':$request['provider_params']['input']['messages'][0]['content'][]=['text'=>'second'];break;
            case 'four_images':$request['provider_params']['input']['messages'][0]['content'][]=['image'=>'https://fixtures.invalid/3'];$request['provider_params']['input']['messages'][0]['content'][]=['image'=>'https://fixtures.invalid/4'];break;
            case 'n_two':$request['provider_params']['parameters']['n']=2;break;
            case 'wrong_channel':$request['provider_params']['channel']='OpenAIYI';break;
            case 'wrong_model':$request['provider_params']['model']='qwen-image-3.0-pro';break;
            case 'conflicting_prompt':$request['prompt']='business text';break;
            case 'business_assets':$request['reference_images']=['https://fixtures.invalid/business'];break;
            case 'agent_image':$request['provider_params']['parameters']['prompt_extend_mode']='agent';break;
        }
        $this->expectException(\Exception::class);Images::prepareRequest(self::snapshot('qwen-image-3.0'),$request);
    }

    public static function badQwenNative(): array
    {return array_map(static fn($s)=>[$s],['two_texts','four_images','n_two','wrong_channel','wrong_model','conflicting_prompt','business_assets','agent_image']);}

    public function testH3RealPrepareKeepsAllTextAndImplicitFirstFrameAndExactGatewayChannel(): void
    {
        $snapshot=self::snapshot('h3-video');$request=self::h3();$original=$request;$before=Reviewed::digest($snapshot);
        $a=Videos::prepareRequest($snapshot,$request);$b=Videos::prepareRequest($snapshot,$request);
        $expected=$request['provider_params']['content'];$expected[1]['role']='first_frame';
        self::assertSame($expected,$a->nativeInput['content']);self::assertSame('minimax',$a->nativeInput['channel']);
        self::assertSame('h3-video',$a->nativeInput['model']);self::assertSame('5',$a->nativeInput['duration']);
        self::assertSame($a->nativeInput,$b->nativeInput);self::assertSame($original,$request);self::assertSame($before,Reviewed::digest($snapshot));
    }

    public function testH3RolelessClassificationAndBillingAgreeBeforePrepareWithoutChangingTheOriginalRequest(): void
    {
        $snapshot=self::snapshot('h3-video');$request=self::h3();unset($request['material_mode']);
        $original=$request;$metadata=$snapshot['market_metadata'];$before=Reviewed::digest($metadata);
        self::assertSame('image_to_video',Materials::select($metadata,$request)['native_mode']);
        self::assertTrue(Materials::billingInputs($metadata,$request)['_billing_image_input']);
        self::assertFalse(Materials::billingInputs($metadata,$request)['_billing_video_input']);
        $request['material_mode']='first_frame';self::assertTrue(Materials::billingInputs($metadata,$request)['_billing_image_input']);
        unset($request['material_mode']);$body=Videos::prepareRequest($snapshot,$request)->nativeInput;
        self::assertSame('first_frame',$body['content'][1]['role']);self::assertSame($original,$request);
        self::assertSame($before,Reviewed::digest($metadata));
        $old=self::snapshot('h3-video',false)['market_metadata'];
        self::assertSame('text_to_video',Materials::select($old,$request)['native_mode']);
        self::assertFalse(Materials::billingInputs($old,$request)['_billing_image_input']);
    }

    public function testH3RolelessBillingRejectsDualMaterialSourcesBeforeReservation(): void
    {
        $snapshot=self::snapshot('h3-video');$request=self::h3();unset($request['material_mode']);
        $request['reference_assets']=[['type'=>'image','role'=>'first_frame_image','url'=>'https://fixtures.invalid/business']];
        $original=$request;
        try{Materials::billingInputs($snapshot['market_metadata'],$request);self::fail('Dual native/business materials must be rejected');}
        catch(\InvalidArgumentException $error){self::assertSame($original,$request);}
    }

    public function testH3NativeControlsAreBridgedBeforeNegotiationWithoutMutatingRawSelectionOrOldPins(): void
    {
        $snapshot=self::snapshot('h3-video');$request=self::h3();$original=$request;
        $bridged=Owned::normalizeH3BusinessSelection($snapshot['market_metadata'],$request);
        self::assertSame(5,$bridged['duration']);self::assertSame('2K',$bridged['resolution']);self::assertSame('16:9',$bridged['ratio']);
        self::assertSame($original,$request);self::assertSame('first_frame',$bridged['provider_params']['content'][1]['role']);
        self::assertSame($original,Owned::normalizeH3BusinessSelection(self::snapshot('h3-video',false)['market_metadata'],$request));
        $request['params']['seconds']=6;
        $this->expectException(\InvalidArgumentException::class);Owned::normalizeH3BusinessSelection($snapshot['market_metadata'],$request);
    }

    public function testH3MixedCaseResolutionAliasUsesTheSameComparisonDuringBridgeAndPrepare(): void
    {
        $snapshot=self::snapshot('h3-video');$snapshot['locked_params']['resolution']='2k';$request=self::h3();$request['quality']='2k';
        $bridged=Owned::normalizeVideoBusinessSelection($snapshot['market_metadata'],$request);
        self::assertSame('2K',$bridged['resolution']);
        self::assertSame('2K',Videos::prepareRequest($snapshot,$request)->nativeInput['resolution']);
    }

    /** Synthetic catalogue, explicit local transaction, quote/reserve only; never submit/query a provider. @dataProvider publicNativeModels */
    public function testPublicQuoteAndReservationUseNativeInputAndRollbackEveryFixture(string $model): void
    {
        if(getenv('AIGC_OWNED_PLATFORM_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();$db=\think\facade\Db::connect();
        self::assertContains($db->getConfig('hostname'),['127.0.0.1','localhost']);
        self::assertContains($db->getConfig('database'),['x_cn','z_cn']);
        $user=(int)\think\facade\Db::name('user')->where('tenant_id',1)->where('user_money','>',100)->order('user_money','desc')->value('id');
        if(!$user)self::markTestSkipped('Funded local rollback fixture unavailable');
        $frozen=self::snapshot($model);$metadata=$frozen['market_metadata'];$contract=$metadata[Reviewed::PIN];unset($metadata[Reviewed::PIN]);
        $catalogue=tempnam(sys_get_temp_dir(),'owned-native-test-');
        file_put_contents($catalogue,json_encode(['version'=>2,'contracts'=>[$contract]],JSON_THROW_ON_ERROR));
        $previous=getenv('MARKET_REVIEWED_GENERATION_CONTRACTS_PATH');putenv('MARKET_REVIEWED_GENERATION_CONTRACTS_PATH='.$catalogue);
        $key='owned-native-'.bin2hex(random_bytes(8));$image=str_starts_with($model,'qwen-image-');$consumptionId=0;
        \think\facade\Db::startTrans();
        try{
            \think\facade\Db::name('tenant')->where('id',1)->lock(true)->find();
            \think\facade\Db::name('user')->where('id',$user)->lock(true)->find();
            $balance=(float)\think\facade\Db::name('user')->where('id',$user)->value('user_money');
            $product=\app\common\model\power\PowerMarketProduct::create(['product_code'=>$key,'name'=>'Offline owned-native fixture',
                'resource_type'=>'model','model_type'=>$image?'image':'video','source_code'=>'offline-fixture','upstream_resource_key'=>$key,
                'upstream_model_code'=>$model,'upstream_channel_code'=>$frozen['channel_code'],
                'source_payload'=>['market_metadata'=>$metadata],'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            $skuFields=['product_id'=>$product['id'],'sku_key'=>$key,'title'=>'Offline owned-native fixture',
                'locked_params'=>$image?[]:($model==='h3-video'?['duration'=>5,'resolution'=>'2K','_billing_image_input'=>true,'_billing_video_input'=>false]:['duration'=>5,'resolution'=>'720P']),
                'usage_unit'=>'per_call','usage_unit_size'=>1,'upstream_price'=>1,'sale_points'=>1,'sale_status'=>1,'status'=>1,
                'source_payload'=>['meter_contract'=>['version'=>1,'basis'=>'call','rounding'=>'ceil']], 'create_time'=>time(),'update_time'=>time()];
            $sku=\app\common\model\power\PowerMarketSku::create($skuFields);
            if($model==='h3-video'){
                $other=$skuFields;$other['sku_key']=$key.'-text';$other['locked_params']=['duration'=>4,'resolution'=>'2K','_billing_image_input'=>false];
                \app\common\model\power\PowerMarketSku::create($other);
            }
            $selection=$image?self::qwen():($model==='h3-video'?self::h3():['provider_params'=>[
                'input'=>['prompt'=>'Native OSS public rollback reference','media'=>[['type'=>'file','url'=>'oss://synthetic-fixture/document']]],
                'parameters'=>['resolution'=>'720P','duration'=>5,'watermark'=>false,'seed'=>0]]]);unset($selection['material_mode']);
            $selection['model_id']=($image?'market_image_model:':'market_video_model:').$product['id'];
            $selection['resource_type']='model';$original=$selection;
            $quote=$image?Images::quote(1,$selection):Videos::quote(1,$selection);
            self::assertSame((int)$sku['id'],(int)$quote['market_sku_id']);
            if(!$image){$normalized=Videos::normalizeDurationSelection(1,$selection,0);self::assertSame(5,$normalized['duration']);self::assertSame((int)$sku['id'],$normalized['market_sku_id']);}
            $reserved=$image?Images::reserve(1,$user,'generate',$key,$selection,$selection,1,'aigc_image','aigc_image_task')
                :Videos::reserve(1,$user,'aigc_video','generate','aigc_video_task',$key,$selection,$selection);
            $consumptionId=$reserved['consumption_id'];$log=\app\common\model\ai\AiConsumptionLog::find($consumptionId);
            self::assertSame('',(string)$log['upstream_task_id']);self::assertSame('reserved',(string)$log['billing_status']);
            self::assertSame((int)$sku['id'],(int)$log['sku_id']);self::assertSame($original,$selection);
            $body=$image?Images::prepareRequest($reserved['market_snapshot'],$selection)->nativeInput:Videos::prepareRequest($reserved['market_snapshot'],$selection)->nativeInput;
            self::assertSame($model,$body['model']);self::assertSame($frozen['channel_code'],$body['channel']);
            if($image)self::assertSame($selection['provider_params']['input'],$body['input']);
            elseif($model==='h3-video'){self::assertSame('5',$body['duration']);self::assertSame('first_frame',$body['content'][1]['role']);}
            else{self::assertSame(5,$body['parameters']['duration']);self::assertSame($selection['provider_params']['input']['media'],$body['input']['media']);}
            self::assertEqualsWithDelta($balance-(float)$log['reserved_user_price'],(float)\think\facade\Db::name('user')->where('id',$user)->value('user_money'),0.00001);
        }finally{
            \think\facade\Db::rollback();putenv($previous===false?'MARKET_REVIEWED_GENERATION_CONTRACTS_PATH':'MARKET_REVIEWED_GENERATION_CONTRACTS_PATH='.$previous);
            unlink($catalogue);
        }
        self::assertSame(0,(int)\think\facade\Db::name('power_market_product')->where('product_code',$key)->count());
        self::assertSame(0,(int)\think\facade\Db::name('ai_consumption_log')->where('id',$consumptionId)->count());
    }

    public static function publicNativeModels(): array {return [['h3-video'],['qwen-image-3.0'],['qwen-image-3.0-pro'],['wan3.0-video']];}

    /** @dataProvider badH3Native */
    public function testH3ActualPrepareRejectsInvalidOrConflictingNativeSources(string $case): void
    {
        $snapshot=self::snapshot('h3-video');$request=self::h3();
        switch($case){
            case 'wrong_channel':$request['provider_params']['channel']='other';break;
            case 'wrong_snapshot_channel':$snapshot['channel_code']='other';break;
            case 'wrong_model':$request['provider_params']['model']='other';break;
            case 'conflicting_prompt':$request['prompt']='different business text';break;
            case 'business_assets':$request['reference_assets']=[['type'=>'image','role'=>'first_frame_image','url'=>'https://fixtures.invalid/business']];break;
            case 'duration_array':$request['provider_params']['duration']=[];break;
            case 'duration_business':$request['duration']=6;break;
            case 'duration_locked':$snapshot['locked_params']['duration']=6;break;
            case 'two_firsts':$request['provider_params']['content'][]=$request['provider_params']['content'][1];break;
            case 'last_only':$request['provider_params']['content'][1]['role']='last_frame';break;
        }
        $this->expectException(\Exception::class);Videos::prepareRequest($snapshot,$request);
    }

    public static function badH3Native(): array
    {return array_map(static fn($s)=>[$s],['wrong_channel','wrong_snapshot_channel','wrong_model','conflicting_prompt','business_assets','duration_array','duration_business','duration_locked','two_firsts','last_only']);}

    public function testOlderH3PinRetainsItsBusinessEnvelopeAndDoesNotAdoptNewNativeInputBehavior(): void
    {
        $snapshot=self::snapshot('h3-video',false);
        self::assertFalse(Owned::applies($snapshot,'h3_content_v1'));
        $body=Videos::prepareRequest($snapshot,['material_mode'=>'text','prompt'=>'legacy business text','quality'=>'2K','ratio'=>'16:9','duration'=>5])->nativeInput;
        self::assertArrayNotHasKey('channel',$body);self::assertSame([['type'=>'text','text'=>'legacy business text']],$body['content']);
        $this->expectException(\Exception::class);Videos::prepareRequest($snapshot,self::h3());
    }

    public function testWanRealPrepareAcceptsTwentyOnePerKindReferencesAndReplaysFrozenSource(): void
    {
        $snapshot=self::snapshot('wan3.0-video');$before=Reviewed::digest($snapshot);$request=self::wan();
        $a=Videos::prepareRequest($snapshot,$request);$b=Videos::prepareRequest($snapshot,$request);
        self::assertCount(21,$a->nativeInput['input']['media']);self::assertSame($a->nativeInput,$b->nativeInput);
        self::assertSame(['reference_image'=>10,'reference_video'=>5,'reference_audio'=>5,'file'=>1],array_count_values(array_column($a->nativeInput['input']['media'],'type')));
        self::assertSame($before,Reviewed::digest($snapshot));
    }

    /** @dataProvider badWanReferences */
    public function testWanRealPrepareRejectsTwentyTwoPerKindOverflowFileLinkAndMixedFrames(string $case): void
    {
        $request=self::wan();
        $type=match($case){'image'=>'image','video'=>'video','audio'=>'audio','file'=>'file','file_link'=>'link',default=>'image'};
        $request['reference_assets'][]=['type'=>$type,'role'=>$case==='mixed_frame'?'first_frame_image':'reference_'.$type,'url'=>'https://fixtures.invalid/overflow/'.$case];
        $this->expectException(\Exception::class);Videos::prepareRequest(self::snapshot('wan3.0-video'),$request);
    }

    public static function badWanReferences(): array {return array_map(static fn($s)=>[$s],['image','video','audio','file','file_link','mixed_frame']);}

    public function testOlderWanPinKeepsItsFifteenReferenceLimit(): void
    {
        $snapshot=self::snapshot('wan3.0-video',false);self::assertFalse(Owned::applies($snapshot,'wan_video_media_v1'));
        $request=self::wan();$request['reference_assets']=array_slice($request['reference_assets'],0,15);
        self::assertCount(15,Videos::prepareRequest($snapshot,$request)->nativeInput['input']['media']);
        $request['reference_assets'][]=['type'=>'audio','role'=>'reference_audio','url'=>'https://fixtures.invalid/16'];
        $this->expectException(\Exception::class);Videos::prepareRequest($snapshot,$request);
    }

    /** @dataProvider wanNativeKinds */
    public function testWanNativeOssReferencesRemainByteIdenticalThroughRealPreparation(string $kind,string $mode): void
    {
        $request=['material_mode'=>$mode,'provider_params'=>['input'=>['prompt'=>'Native OSS reference',
            'media'=>[['type'=>$kind,'url'=>'oss://synthetic-fixture/reference']]],
            'parameters'=>['resolution'=>'720P','duration'=>5,'seed'=>0,'watermark'=>false]]];
        $original=$request;$body=Videos::prepareRequest(self::snapshot('wan3.0-video'),$request)->nativeInput;
        self::assertSame($request['provider_params']['input']['media'],$body['input']['media']);
        self::assertSame(0,$body['parameters']['seed']);self::assertFalse($body['parameters']['watermark']);self::assertSame($original,$request);
    }

    public static function wanNativeKinds(): array
    {return [['first_frame','first_frame'],['last_frame','last_frame'],['reference_image','reference'],['reference_video','reference'],['reference_audio','reference'],['file','reference'],['link','reference']];}

    /** @dataProvider disallowedDocumentSchemes */
    public function testWanDocumentOssAllowanceCannotExpandOldPinsBusinessUploadsOrUnsafeSchemes(string $kind,string $scheme,bool $newPin,bool $business): void
    {
        $request=['material_mode'=>'reference','duration'=>5,'quality'=>'720P','ratio'=>'16:9','provider_params'=>['input'=>['prompt'=>'Synthetic document',
            'media'=>[['type'=>$kind,'url'=>$scheme.'://synthetic-fixture/document']]],'parameters'=>['resolution'=>'720P','duration'=>5]]];
        if($business){unset($request['provider_params']['input']['media']);$request['reference_assets']=[['type'=>$kind,'url'=>$scheme.'://synthetic-fixture/document']];}
        $this->expectException(\Exception::class);Videos::prepareRequest(self::snapshot('wan3.0-video',$newPin),$request);
    }

    public static function disallowedDocumentSchemes(): array
    {return [['file','file',true,false],['link','file',true,false],['file','oss',false,false],['link','oss',false,false],['file','oss',true,true],['link','oss',true,true]];}

    public function testWanNativeAutomaticDurationIsRetainedWithoutGrantingAnAutomaticBillingBound(): void
    {
        $snapshot=self::snapshot('wan3.0-video');$request=['material_mode'=>'text','provider_params'=>[
            'input'=>['prompt'=>'Synthetic automatic duration'],'parameters'=>['resolution'=>'720P','duration'=>-1]]];
        $body=Videos::prepareRequest($snapshot,$request)->nativeInput;self::assertSame(-1,$body['parameters']['duration']);
        $profile=array_values(array_filter(Builder::profiles(),static fn($p)=>$p['model_code']==='wan3.0-video'))[0];
        self::assertContains('automatic_duration_settlement_bound',$profile['external_verification']);
        self::assertArrayNotHasKey('billing_meter',$body);
    }

    public function testWanNativeControlBridgeRejectsBusinessAndFrozenSkuConflictsAndPreservesOldPins(): void
    {
        $snapshot=self::snapshot('wan3.0-video');$request=['provider_params'=>['parameters'=>['duration'=>5,'resolution'=>'720P','ratio'=>'16:9']]];
        $before=$request;$bridge=Owned::normalizeVideoBusinessSelection($snapshot['market_metadata'],$request,['duration'=>5,'resolution'=>'720p']);
        self::assertSame(5,$bridge['duration']);self::assertSame('720P',$bridge['resolution']);self::assertSame($before,$request);
        self::assertSame($request,Owned::normalizeVideoBusinessSelection(self::snapshot('wan3.0-video',false)['market_metadata'],$request));
        $this->expectException(\InvalidArgumentException::class);Owned::normalizeVideoBusinessSelection($snapshot['market_metadata'],$request,['duration'=>6]);
    }

    public function testWanOmittedDurationUsesSourceDefaultsAndNeverSynthesizesZeroFromARange(): void
    {
        $snapshot=self::snapshot('wan3.0-video');$request=['material_mode'=>'text','provider_params'=>[
            'input'=>['prompt'=>'Synthetic omitted duration'],'parameters'=>['resolution'=>'720P']]];
        $body=Videos::prepareRequest($snapshot,$request)->nativeInput;
        self::assertArrayNotHasKey('duration',$body['parameters']);self::assertSame(2,Owned::wanDefaultDuration($request));
        $request['material_mode']='reference';$request['provider_params']['input']['media']=[['type'=>'reference_video','url'=>'oss://synthetic-fixture/video']];
        $body=Videos::prepareRequest($snapshot,$request)->nativeInput;
        self::assertArrayNotHasKey('duration',$body['parameters']);self::assertSame(1,Owned::wanDefaultDuration($request));
    }

    /** @dataProvider badWanNativeControls */
    public function testWanInvalidNativeControlsAreRejectedBeforeEncodingRatherThanOverwritten(string $case): void
    {
        $snapshot=self::snapshot('wan3.0-video');$request=['material_mode'=>'text','provider_params'=>[
            'input'=>['prompt'=>'Synthetic control guard'],'parameters'=>['resolution'=>'720P','duration'=>5]]];
        switch($case){
            case 'business_duration':$request['duration']=6;break;
            case 'locked_duration':$snapshot['locked_params']['duration']=6;break;
            case 'nested_locked_duration':$snapshot['locked_params']['parameters']['duration']=6;break;
            case 'zero_duration':$request['provider_params']['parameters']['duration']=0;break;
            case 'wrong_channel':$request['provider_params']['channel']='other';break;
            case 'wrong_model':$request['provider_params']['model']='other';break;
            case 'scalar_input':$request['provider_params']['input']='bad';$request['prompt']='business';break;
            case 'scalar_parameters':$request['provider_params']['parameters']='bad';break;
        }
        $this->expectException(\Exception::class);Videos::prepareRequest($snapshot,$request);
    }

    public static function badWanNativeControls(): array
    {return array_map(static fn($s)=>[$s],['business_duration','locked_duration','nested_locked_duration','zero_duration','wrong_channel','wrong_model','scalar_input','scalar_parameters']);}
}
