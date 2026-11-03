<?php
namespace Tests\Feature;

use app\common\service\power\{MarketCreationParameters as Controls, MarketGenerationControls,
    MarketGenerationParameters, MarketGenerationReviewedContract as Reviewed, MarketGenerationSchema as Schema,
    MarketGenerationApplicationProtocol as Protocol,
    MarketVideoRuntimeService as Videos, MarketNanoBananaAppRuntimeService as Nano};
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Current captured declarations, original SDK-free codecs, no HTTP, DB, media probes or real meter activation. */
class MarketApplicationSourceContractTest extends TestCase
{
    private static function fixture(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/fixtures/market-application-source-contracts-20261101.json'),true,512,JSON_THROW_ON_ERROR);
    }

    private static function entry(string $app): array
    {
        foreach(self::fixture()['entries'] as $entry) if($entry['product']['upstream_app_code']===$app) return $entry;
        throw new \LogicException('Missing captured app');
    }

    private static function snapshot(array $entry,array $sku): array
    {
        $app=$entry['product']['upstream_app_code'];$metadata=$entry['metadata'];
        return Protocol::bindNewSnapshot(['resource_type'=>'app_api','app_code'=>$app,'api_code'=>$entry['product']['upstream_api_code'],
            'product_id'=>$entry['product']['id'],'sku_id'=>$sku['id'],'sku_key'=>$sku['sku_key'],
            'model_code'=>$sku['locked_params']['model']??'','locked_params'=>$sku['locked_params'],
            'market_metadata'=>$metadata,'usage_unit'=>$sku['usage_unit'],'usage_unit_size'=>$sku['usage_unit_size'],
            'max_reference_images'=>$metadata['max_reference_images'],
            'ratio_options'=>Schema::choices(Schema::properties($metadata['params_schema'])['aspect_ratio']??[])]);
    }

    private static function request(array $entry,array $sku): array
    {
        $app=$entry['product']['upstream_app_code'];$locked=$sku['locked_params'];$model=$locked['model']??'';
        $request=['prompt'=>'Offline application preparation','duration'=>$app==='full_video'?4:5,
            'quality'=>$locked['resolution']??'1k','ratio'=>'16:9','generation_method'=>'text_to_video'];
        if(($app==='seedance'&&($locked['_pricing_variant']??'')==='with_video')||$model==='wan2.7-videoedit') {
            $request['reference_assets']=[['type'=>'video','role'=>'reference_video','url'=>'https://fixtures.invalid/input.mp4']];
            $request['generation_method']='video_edit';
        } elseif($model==='wan2.7-r2v'||$model==='grok-imagine-video-1.5') {
            $request['reference_assets']=[['type'=>'image','role'=>'reference_image','url'=>'https://fixtures.invalid/input.png']];
            $request['generation_method']='image_reference';
        }
        return $request;
    }

    public static function capturedSkus(): array
    {
        $cases=[];
        foreach(self::fixture()['entries'] as$entry) foreach($entry['skus'] as$sku) {
            $cases[$entry['product']['upstream_app_code'].'/'.$sku['id']]=[$entry,$sku];
        }
        return $cases;
    }

    public function testFixtureKeepsSixPublicDeclarationsAndAllOriginalSkuAndOptionBindings(): void
    {
        $fixture=self::fixture();self::assertCount(6,$fixture['entries']);self::assertCount(37,self::capturedSkus());
        self::assertCount(13,$fixture['historical_option_bindings']);
        foreach($fixture['entries'] as$entry) {
            self::assertSame($entry['public_schema_fingerprints']['params_schema'],$entry['saved_schema_fingerprints']['params_schema']);
            foreach($entry['public_schema_fingerprints'] as$key=>$fingerprint) self::assertSame($fingerprint,Reviewed::digest(['value'=>$entry['public_api_declaration'][$key]]));
            foreach($entry['saved_schema_fingerprints'] as$key=>$fingerprint) self::assertSame($fingerprint,Reviewed::digest(['value'=>$entry['metadata'][$key]]));
            self::assertSame($entry['public_api_declaration']['params_schema'],$entry['metadata']['params_schema']);
            self::assertSame($entry['public_api_declaration']['default_params'],$entry['metadata']['default_params']);
            foreach($entry['skus'] as$sku) self::assertSame($entry['product']['id'],$sku['product_id']);
        }
    }

    /** @dataProvider capturedSkus */
    public function testEveryCapturedSkuPreparesItsOriginalApplicationIdentityAndLocks(array $entry,array $sku): void
    {
        $snapshot=self::snapshot($entry,$sku);$request=self::request($entry,$sku);$before=$snapshot;
        $native=$snapshot['app_code']==='nano_banana'
            ? Nano::prepareRequest($snapshot,$request,'offline-fixture')->nativeInput
            : Videos::prepareRequest($snapshot,$request,'offline-fixture')->nativeInput;
        self::assertSame('offline-fixture',$native['idempotency_key']);
        if(isset($sku['locked_params']['model'])) self::assertSame($sku['locked_params']['model'],$native['model']);
        if(isset($sku['locked_params']['resolution'])) self::assertSame(strtolower($sku['locked_params']['resolution']),strtolower($native['resolution']));
        self::assertArrayNotHasKey('_pricing_variant',$native);self::assertSame($before,$snapshot);
    }

    public static function seedanceModels(): array
    {
        $entry=self::entry('seedance');$models=Schema::choices(Schema::properties($entry['metadata']['params_schema'])['model']);$cases=[];
        foreach($models as$model) foreach($entry['skus'] as$sku) {
            if(str_ends_with($model,'video-2-video')!==($sku['locked_params']['_pricing_variant']==='with_video')) continue;
            $cases[$model.'/'.$sku['id']]=[$model,$entry,$sku];
        }
        return $cases;
    }

    private static function option(array $entry,array $sku): array
    {
        return ['value'=>'fixture-seedance','resource_type'=>'app_api','app_code'=>'seedance','api_code'=>'create',
            'params_schema'=>$entry['metadata']['params_schema'],'skus'=>[['market_sku_id'=>$sku['id'],'locked_params'=>$sku['locked_params']]]];
    }

    private static function runtimeSnapshot(array $entry,array $sku): array
    {
        $market=['product'=>$entry['product']+['upstream_model_code'=>'','upstream_channel_code'=>'',
            'source_payload'=>['market_metadata'=>$entry['metadata']]],
            'sku'=>$sku+['upstream_price'=>1,'sale_points'=>1],'tenant_price'=>1];
        return (new ReflectionMethod(Videos::class,'snapshot'))->invoke(null,$market,1);
    }

    /** @dataProvider seedanceModels */
    public function testAllFourModelsAreReachableThroughExistingControlsAndThePreparedQuoteRequest(string $model,array $entry,array $sku): void
    {
        $option=self::option($entry,$sku);$request=self::request($entry,$sku)+['model_id'=>'fixture-seedance',
            'market_sku_id'=>$sku['id'],'advanced_params'=>['model'=>$model]];
        $described=Controls::describe($option,'video');
        self::assertContains($model,array_column($described['generation_parameters'],null,'key')['model']['options']);
        self::assertContains($model,array_column(MarketGenerationParameters::definitions($option,'video'),null,'key')['model']['options']);
        $request=Controls::applyOptions($request,[$option],'video');self::assertSame($model,$request['provider_params']['model']);
        $snapshot=self::runtimeSnapshot($entry,$sku);self::assertSame(1,$snapshot['application_contract']['version']);
        $prepared=Videos::prepareRequest($snapshot,$request,'frozen-key');
        self::assertSame($model,$prepared->nativeInput['model']);self::assertSame($model,$prepared->requestInput['provider_params']['model']);
        self::assertSame($prepared->nativeInput,Videos::prepareRequest($snapshot,$prepared->requestInput,'frozen-key')->nativeInput);

        // The actual quote/reservation preparation gate must keep rejecting these real unbounded Token SKUs.
        $market=['product'=>$entry['product']+['upstream_model_code'=>'','upstream_channel_code'=>'',
            'source_payload'=>['market_metadata'=>$entry['metadata']]],'sku'=>$sku,'tenant_price'=>1];
        $args=[&$market,$request];
        try{(new ReflectionMethod(Videos::class,'prepareMarketRequest'))->invokeArgs(null,$args);self::fail('A selectable model is not a Token spending bound');}
        catch(\InvalidArgumentException$error){self::assertStringContainsString('Token 费用上限',$error->getMessage());}
    }

    /** @dataProvider invalidControlIdentities */
    public function testEditableModelCannotEscapeItsExactApplicationAndApi(array $change): void
    {
        $entry=self::entry('seedance');$option=array_replace(self::option($entry,$entry['skus'][0]),$change);
        self::assertArrayNotHasKey('model',array_column(MarketGenerationControls::definitions($option,'video'),null,'key'));
        self::assertArrayNotHasKey('model',array_column(MarketGenerationParameters::definitions($option,'video'),null,'key'));
        $this->expectException(\InvalidArgumentException::class);
        Controls::applyOptions(['model_id'=>'fixture-seedance','advanced_params'=>['model'=>'seedance-2-fast-text-2-video']],[$option],'video');
    }
    public static function invalidControlIdentities(): array
    {
        return [[['resource_type'=>'model']],[['app_code'=>'wan']],[['api_code'=>'query']],[['api_code'=>'']],
            [['upstream_app_code'=>'wan']],[['upstream_api_code'=>'query']],[['params_schema'=>[]]]];
    }

    public function testSkuModelLockAndVideoPricingVariantRemainAuthoritative(): void
    {
        $entry=self::entry('seedance');$sku=$entry['skus'][1];$sku['locked_params']['model']='seedance-2-text-2-video';
        $option=self::option($entry,$sku);$request=self::request($entry,$sku)+['model_id'=>'fixture-seedance',
            'market_sku_id'=>$sku['id'],'advanced_params'=>['model'=>'seedance-2-fast-text-2-video']];
        $rejected=0;
        try{Controls::applyOptions($request,[$option],'video');}catch(\InvalidArgumentException$error){$rejected++;}
        $snapshot=self::snapshot($entry,$sku);$request['provider_params']['model']='seedance-2-fast-text-2-video';
        try{Videos::prepareRequest($snapshot,$request);}catch(\Exception$error){$rejected++;}
        unset($snapshot['locked_params']['model']);$request['provider_params']['model']='seedance-2-video-2-video';
        unset($snapshot['application_contract']);$snapshot=Protocol::bindNewSnapshot($snapshot);
        try{Videos::prepareRequest($snapshot,$request);}catch(\Exception$error){$rejected++;}
        self::assertSame(3,$rejected);
    }

    public function testExplicitModelRequiresItsFrozenDeclarationAndARealVideoInputMatch(): void
    {
        $entry=self::entry('seedance');$sku=$entry['skus'][1];$snapshot=self::snapshot($entry,$sku);$request=self::request($entry,$sku);
        $cases=[null,[],42,'unreviewed-model','seedance-2-fast-video-2-video'];$rejected=0;
        foreach($cases as$value) {
            try{Videos::prepareRequest($snapshot,$request+['provider_params'=>['model'=>$value]]);}
            catch(\Exception$error){$rejected++;}
        }
        self::assertSame(count($cases),$rejected);
        $request['provider_params']['model']='seedance-2-fast-text-2-video';
        $request['reference_assets']=[['type'=>'video','role'=>'reference_video','url'=>'https://fixtures.invalid/input.mp4']];
        $request['generation_method']='video_edit';
        unset($snapshot['locked_params']['_pricing_variant']);
        unset($snapshot['application_contract']);$snapshot=Protocol::bindNewSnapshot($snapshot);
        try{Videos::prepareRequest($snapshot,$request);self::fail('Text model must not consume a video input');}
        catch(\Exception$error){self::assertStringContainsString('视频输入',$error->getMessage());}
        unset($request['reference_assets'],$request['provider_params']);
        $request['generation_method']='text_to_video';
        $snapshot['market_metadata']['params_schema']['properties']['model']['options']='seedance-2-text-2-video';
        $this->expectExceptionMessage('应用冻结合同');
        Videos::prepareRequest($snapshot,$request+['provider_params'=>['model'=>'seedance-2-fast-text-2-video']]);
    }

    public function testMissingModelRetainsBothLegacyDefaultsAndTheOriginalFrozenRequest(): void
    {
        $entry=self::entry('seedance');
        foreach([$entry['skus'][0],$entry['skus'][1]] as$sku) {
            $snapshot=self::snapshot($entry,$sku);$request=self::request($entry,$sku);$before=$request;
            $expected=$sku['locked_params']['_pricing_variant']==='with_video'?'seedance-2-video-2-video':'seedance-2-text-2-video';
            self::assertSame($expected,Videos::prepareRequest($snapshot,$request)->nativeInput['model']);
            unset($snapshot['market_metadata'],$snapshot['api_code'],$snapshot['application_contract']);
            self::assertSame($expected,Videos::prepareRequest($snapshot,$request)->nativeInput['model']);
            self::assertSame($before,$request);
        }
    }

    public static function oldFrozenApplications(): array
    {
        $cases=[];
        foreach(['seedance','happy_horse','full_video'] as$app) foreach([false,true] as$unknown) $cases[$app.($unknown?'/unknown':'/same-fingerprint')]=[$app,$unknown];
        return $cases;
    }

    /** @dataProvider oldFrozenApplications */
    public function testUnmarkedOldSnapshotsKeepOriginalBehaviorEvenWithTodaysExactFingerprint(string $app,bool $unknown): void
    {
        $entry=self::entry($app);$sku=$entry['skus'][$app==='happy_horse'?0:1];$snapshot=self::snapshot($entry,$sku);
        unset($snapshot['application_contract']);
        if($unknown) {
            $snapshot['market_metadata']['params_schema']['description']='A different old declaration';
            $snapshot['market_metadata']['default_params']['legacy_route']='unchanged old defaults';
        }
        $request=self::request($entry,$sku);
        if($app==='seedance') {
            $snapshot['locked_params']['_pricing_variant']='with_video'; // The old codec did not reinterpret this local pricing field.
            $request['provider_params']['model']='seedance-2-fast-text-2-video';
        } elseif($app==='happy_horse') {
            $request=self::happyHorseRequest(1);
        } else {
            $request['prompt']=str_repeat('字',5001);$request['aigc_watermark']=true;$request['generation_method']='image_reference';
            for($i=0;$i<6;$i++)$request['reference_assets'][]=['type'=>'image','role'=>'reference_image','url'=>'https://fixtures.invalid/old-'.$i.'.png'];
        }
        $before=$snapshot;$native=Videos::prepareRequest($snapshot,$request)->nativeInput;
        if($app==='seedance') self::assertSame('seedance-2-text-2-video',$native['model']);
        elseif($app==='happy_horse') {self::assertSame('happyhorse-1.1-r2v',$native['model']);self::assertArrayNotHasKey('channel',$native);}
        else {self::assertTrue($native['aigc_watermark']);self::assertCount(7,$native['content']);}
        self::assertArrayNotHasKey('application_contract',$snapshot);self::assertArrayNotHasKey('application_contract',$native);
        self::assertSame($before,$snapshot);
    }

    public function testUnmarkedLegacySeedanceLockedModelIsNotReplacedByANewAllowList(): void
    {
        $entry=self::entry('seedance');$sku=$entry['skus'][1];$snapshot=self::snapshot($entry,$sku);
        unset($snapshot['application_contract'],$snapshot['market_metadata']['params_schema']['properties']['model']);
        $snapshot['market_metadata']['params_schema']['required']=array_values(array_diff($snapshot['market_metadata']['params_schema']['required']??[],['model']));
        $snapshot['locked_params']['model']='old-provider-model';$snapshot['market_metadata']['default_params']=[];
        self::assertSame('old-provider-model',Videos::prepareRequest($snapshot,self::request($entry,$sku))->nativeInput['model']);
    }

    public static function damagedContracts(): array
    {
        $cases=[];
        foreach(['seedance','happy_horse','full_video'] as$app) foreach(['version','profile','digest','schema','defaults','sku'] as$damage) $cases[$app.'/'.$damage]=[$app,$damage];
        return $cases;
    }

    /** @dataProvider damagedContracts */
    public function testMarkedSnapshotsCannotSilentlyFallBackWhenTheirContractChanges(string $app,string $damage): void
    {
        $entry=self::entry($app);$sku=$entry['skus'][0];$snapshot=self::snapshot($entry,$sku);
        if($damage==='version')$snapshot['application_contract']['version']=999;
        elseif($damage==='profile')$snapshot['application_contract']['profile']='another-application';
        elseif($damage==='digest')$snapshot['application_contract']['binding_digest']=str_repeat('0',64);
        elseif($damage==='schema')$snapshot['market_metadata']['params_schema']['description']='changed after freeze';
        elseif($damage==='defaults')$snapshot['market_metadata']['default_params']['duration']=123;
        else $snapshot['locked_params']['resolution']='different-after-freeze';
        $this->expectExceptionMessage('应用冻结合同');Videos::prepareRequest($snapshot,self::request($entry,$sku));
    }

    private static function happyHorseRequest(int $images,string $method='image_reference'): array
    {
        $entry=self::entry('happy_horse');$request=self::request($entry,$entry['skus'][0]);$request['generation_method']=$method;
        $request['reference_assets']=[];
        for($i=0;$i<$images;$i++) $request['reference_assets'][]=['type'=>'image',
            'role'=>$method==='image_to_video'?'first_frame_image':'reference_image','url'=>'https://fixtures.invalid/happy-'.$i.'.png'];
        return $request;
    }

    public function testBoundHappyHorseSubmitFreezesDashScopeAndPreservesTwoReferenceImagesAndOneFirstFrame(): void
    {
        $entry=self::entry('happy_horse');$snapshot=self::snapshot($entry,$entry['skus'][0]);$before=$snapshot;
        $native=Videos::prepareRequest($snapshot,self::happyHorseRequest(2))->nativeInput;
        self::assertSame('dashscope',$native['channel']);self::assertSame('happyhorse-1.1-r2v',$native['model']);self::assertCount(2,$native['media']);
        $first=Videos::prepareRequest($snapshot,self::happyHorseRequest(1,'image_to_video'))->nativeInput;
        self::assertSame('dashscope',$first['channel']);self::assertSame('happyhorse-1.1-i2v',$first['model']);self::assertCount(1,$first['media']);
        self::assertSame($before,$snapshot);
    }

    public function testSingleReferenceImageCannotEnterBoundDashScopeR2v(): void
    {
        $entry=self::entry('happy_horse');$snapshot=self::snapshot($entry,$entry['skus'][0]);
        $this->expectExceptionMessage('至少需要两张');Videos::prepareRequest($snapshot,self::happyHorseRequest(1));
    }

    public function testBoundHappyHorseRouteRejectsExplicitConflictingChannel(): void
    {
        $entry=self::entry('happy_horse');$snapshot=self::snapshot($entry,$entry['skus'][0]);$snapshot['locked_params']['channel']='happyhorse';
        unset($snapshot['application_contract']);$snapshot=Protocol::bindNewSnapshot($snapshot);
        $this->expectExceptionMessage('渠道与冻结');Videos::prepareRequest($snapshot,self::happyHorseRequest(2));
    }

    public function testUnknownDeclarationAndCreateKeepTheirOriginalChannelSemantics(): void
    {
        $entry=self::entry('happy_horse');$original=self::snapshot($entry,$entry['skus'][0]);
        $changed=$original;$changed['market_metadata']['params_schema']['media']['description'].=' Changed declaration';
        $create=$original;$create['api_code']='create';
        unset($changed['application_contract'],$create['application_contract']);
        foreach([$changed,$create] as$snapshot) {
            $native=Videos::prepareRequest($snapshot,self::happyHorseRequest(1))->nativeInput;
            self::assertArrayNotHasKey('channel',$native);self::assertSame('happyhorse-1.1-r2v',$native['model']);
        }
        $create['locked_params']['channel']='happyhorse';
        self::assertSame('happyhorse',Videos::prepareRequest($create,self::happyHorseRequest(1))->nativeInput['channel']);
    }

    public function testFullVideoDoesNotPrepareUnverifiedSurchargesOrCountFirstLastFramesAsReferences(): void
    {
        $entry=self::entry('full_video');$snapshot=self::snapshot($entry,$entry['skus'][0]);$request=self::request($entry,$entry['skus'][0]);
        $request['generation_method']='image_reference';$request['reference_assets']=[];
        for($i=0;$i<5;$i++) $request['reference_assets'][]=['type'=>'image','role'=>'reference_image','url'=>'https://fixtures.invalid/full-'.$i.'.png'];
        $native=Videos::prepareRequest($snapshot,$request)->nativeInput;
        self::assertCount(6,$native['content']); // Text plus the five currently free references.
        $withFrames=$native;
        foreach(['first_frame','last_frame'] as$role) $withFrames['content'][]=['type'=>'image_url','role'=>$role,'image_url'=>['url'=>'https://fixtures.invalid/'.$role.'.png']];
        Protocol::assertPreparedVideo($snapshot,$withFrames); // Billing role check only; public admission still forbids mixing these modes.
        $request['reference_assets'][]=['type'=>'image','role'=>'reference_image','url'=>'https://fixtures.invalid/full-5.png'];
        try{Videos::prepareRequest($snapshot,$request);self::fail('A sixth reference must not produce an incomplete quoteable request');}
        catch(\Exception$error){self::assertStringContainsString('附加计费尚待核验',$error->getMessage());}
        $changed=$snapshot;$changed['market_metadata']['params_schema']['content']['description'].=' Changed declaration';
        unset($changed['application_contract']);
        self::assertCount(7,Videos::prepareRequest($changed,$request)->nativeInput['content']);
        $candidate=self::fixture()['inactive_application_billing_candidates']['full_video_submit'];
        self::assertSame('inactive',$candidate['status']);self::assertSame(5,$candidate['current_config_evidence']['reference_image_free_count']);
        self::assertFalse($candidate['production_activation']);
    }

    public static function fullRoutes(): array
    {
        return array_map(static fn($sku)=>[$sku],self::entry('full_video')['skus']);
    }

    /** @dataProvider fullRoutes */
    public function testFullVideoEnforcesTheEncodedRouteWatermarkAndUnicodeTextBoundary(array $sku): void
    {
        $entry=self::entry('full_video');$snapshot=self::snapshot($entry,$sku);$request=self::request($entry,$sku);
        $remote=in_array(strtolower($sku['locked_params']['resolution']),['768p','1080p','2k'],true);$limit=$remote?5000:7000;
        $request['prompt']=str_repeat('字',$limit);$request['aigc_watermark']=false;
        $native=Videos::prepareRequest($snapshot,$request)->nativeInput;
        self::assertSame($limit,mb_strlen($native['content'][0]['text'],'UTF-8'));
        $request['quality']='480p'; // SKU-bound encoded resolution decides the route, not this conflicting UI alias.
        if($remote) {
            $request['aigc_watermark']=true;
            try{Videos::prepareRequest($snapshot,$request);self::fail('Remote route must reject watermark');}
            catch(\Exception$error){self::assertStringContainsString('生成标识',$error->getMessage());}
        } else {
            $request['aigc_watermark']=true;self::assertTrue(Videos::prepareRequest($snapshot,$request)->nativeInput['aigc_watermark']);
        }
        $request['aigc_watermark']=false;$request['prompt'].='字';$this->expectExceptionMessage('长度限制');
        Videos::prepareRequest($snapshot,$request);
    }
}
