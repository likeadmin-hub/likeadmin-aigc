<?php
namespace Tests\Feature;

use app\common\service\power\MarketOwnedPlatformContractBuilder as Builder;
use app\common\service\power\MarketOwnedPlatformContractRules as Owned;
use app\common\service\power\MarketOwnedPlatformFlatSourceRules as Flat;
use app\common\service\power\MarketOwnedPlatformExecutionEvidence as Execution;
use app\common\service\power\MarketGenerationContractReview as Review;
use app\common\service\power\MarketGenerationReviewedContract as Reviewed;
use app\common\service\power\MarketGenerationMaterials as Materials;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use PHPUnit\Framework\TestCase;

/** Synthetic original/configuration bindings. No actual-source authentication claim, files, catalogue activation, DB or HTTP. */
class MarketOwnedPlatformFlatSourcePreparationTest extends TestCase
{
    private const ARTIFACT='Synthetic flat candidate compiler fixture only';

    private static function fixture(string $model,string $channel): array
    {
        $profile=array_values(array_filter(Builder::profiles(),static fn($p)=>$p['model_code']===$model&&$p['channel_code']===$channel))[0];
        $family=Flat::family($model,$channel);$maximum=$family==='veo'?3:($family==='grok'?7:($model==='nano-banana-2.1'?10:12));
        $binding=['params_schema'=>['synthetic_original_marker'=>['type'=>'string']],'material_capabilities'=>[]];
        $limits=['max_reference_images'=>$maximum,'max_reference_videos'=>0,'max_reference_audios'=>0];
        $execution=['binding'=>['version'=>1,'kind'=>'owned_platform_direct_execution','artifact_sha256'=>hash('sha256','synthetic current execution'),
            'platform_model_id'=>501,'model_code'=>$model,'channel_code'=>$channel,'upstream_model'=>'synthetic-upstream',
            'observed_at'=>'2026-11-01T00:00:00+08:00','configuration_fingerprint'=>hash('sha256',$model.'|'.$channel),
            'channel_mapping_enabled'=>false,'fallback_configured'=>false,'material_limits'=>$limits,
            'smart_route_context'=>['request_control'=>'omitted','smart_route_supported'=>true,
                'smart_route_tenant_force_enabled'=>false,'smart_route_user_force_enabled'=>false]],
            'config'=>[in_array($family,['grok','veo'],true)?'video':'draw'=>['request_params'=>['image_urls'=>'image_urls']]],'public_defaults'=>[]];
        $entry=(new \ReflectionMethod(Builder::class,'entry'))->invoke(null,$profile,$binding,$execution);
        $bundle=['version'=>1,'source'=>['kind'=>'gateway_validator','reference'=>'synthetic/flat-format-only','revision'=>Owned::REVISION,
            'reviewed_at'=>'2026-11-01T00:00:00+08:00','artifact_sha256'=>hash('sha256',self::ARTIFACT)],'entries'=>[$entry]];
        $contract=Review::reviewedCatalogue($bundle,self::ARTIFACT)['contracts'][0];
        $metadata=$binding+$limits+['upstream_metadata'=>['model_code'=>$model,'channel_code'=>$channel,'model_id'=>501],Reviewed::PIN=>$contract];
        $snapshot=['resource_type'=>'model','model_code'=>$model,'channel_code'=>$channel,'params_schema'=>$binding['params_schema'],
            'market_metadata'=>$metadata,'locked_params'=>[]];
        // This deliberate fixture mutation selects the runtime branch. It does not authenticate any real source/configuration.
        $contract['source']['reference']='https://gitee.com/yc_open/platform-api-saas/tree/'.Owned::REVISION;
        $contract['source']['artifact_sha256']=Builder::ARTIFACT_SHA256;$contract['contract_digest']=Reviewed::contractDigest($contract);
        $snapshot['market_metadata'][Reviewed::PIN]=$contract;
        return [$snapshot,$entry,$bundle,$maximum];
    }

    public static function exactModels(): array
    {
        $out=[];foreach(Builder::profiles()as$p)if(in_array($p['profile'],['flat_image_v1','flat_video_v1'],true))$out[]=[$p['model_code'],$p['channel_code']];return $out;
    }

    private static function prepare(string $model,array $snapshot,array $request): array
    {return ($model==='grok-video'||str_starts_with($model,'veo3.1-'))?Videos::prepareRequest($snapshot,$request)->nativeInput:Images::prepareRequest($snapshot,$request)->nativeInput;}

    private static function request(string $model,string $channel): array
    {
        $request=['prompt'=>'Synthetic source contract','material_mode'=>'text','ratio'=>'16:9',
            'quality'=>$channel==='Google'?'1K':($model==='grok-video'||str_starts_with($model,'veo3.1-')?'720p':'1k')];
        if($model==='grok-video')$request['duration']=6;
        if(str_starts_with($model,'veo3.1-'))$request['duration']=8;
        return $request;
    }

    /** @dataProvider exactModels */
    public function testAllTwelveExactFlatIdentitiesCompileAndPrepareTheirTextAndMaximumReferenceModes(string $model,string $channel): void
    {
        [$snapshot,$entry,$bundle,$maximum]=self::fixture($model,$channel);
        self::assertCount(1,Review::candidates($bundle,self::ARTIFACT)['candidates']);
        self::assertCount(1,Review::reviewedCatalogue($bundle,self::ARTIFACT)['contracts']);
        self::assertTrue(Flat::applies($snapshot));$before=Reviewed::digest($snapshot);
        $request=self::request($model,$channel);$body=self::prepare($model,$snapshot,$request);
        self::assertSame($model,$body['model']);self::assertSame($channel,$body['channel']);
        $request['material_mode']='reference';$request['reference_assets']=[];
        for($i=0;$i<$maximum;$i++)$request['reference_assets'][]=['type'=>'image','role'=>'reference_image','url'=>'https://fixtures.invalid/'.$i];
        $original=$request;$a=self::prepare($model,$snapshot,$request);$b=self::prepare($model,$snapshot,$request);
        $field=$channel==='Google'?'urls':'image_urls';self::assertCount($maximum,$a[$field]);
        self::assertSame($a,$b);self::assertSame($original,$request);self::assertSame($before,Reviewed::digest($snapshot));
        self::assertSame(['synthetic_original_marker'=>['type'=>'string']],$entry['binding_declaration']['params_schema']);
    }

    /** @dataProvider exactModels */
    public function testActualPreparationRejectsReferenceOverflowWithoutChangingItsFrozenSource(string $model,string $channel): void
    {
        [$snapshot,,,$maximum]=self::fixture($model,$channel);$request=self::request($model,$channel);$request['material_mode']='reference';
        for($i=0;$i<=$maximum;$i++)$request['reference_assets'][]=['type'=>'image','role'=>'reference_image','url'=>'https://fixtures.invalid/'.$i];
        $this->expectException(\Exception::class);self::prepare($model,$snapshot,$request);
    }

    public function testGoogleOptionalPromptIsNotPromotedToAnInventedRequiredOrNonemptyCondition(): void
    {
        foreach(['nano-banana-2.1','nano-banana-2','nano-banana-pro']as$model){
            [$snapshot]=self::fixture($model,'Google');
            $body=Images::prepareRequest($snapshot,['material_mode'=>'text'])->nativeInput;
            self::assertSame($model,$body['model']);
            $body=Images::prepareRequest($snapshot,['material_mode'=>'reference','reference_images'=>['https://fixtures.invalid/edit']])->nativeInput;
            self::assertSame(['https://fixtures.invalid/edit'],$body['urls']);
        }
    }

    public function testFastNativeScalarAndListRemainDistinctAndAreNotOverwrittenByEmptyBusinessReferences(): void
    {
        [$snapshot]=self::fixture('gpt-image-2-fast','openaiD');
        foreach(['https://fixtures.invalid/one',['https://fixtures.invalid/one','https://fixtures.invalid/one']]as$urls){
            $request=['material_mode'=>'reference','provider_params'=>['prompt'=>'Native complete flat input','image_urls'=>$urls,'image_size'=>'1k','aspect_ratio'=>'3:1','quality'=>'low']];
            $body=Images::prepareRequest($snapshot,$request)->nativeInput;self::assertSame($urls,$body['image_urls']);self::assertSame('3:1',$body['aspect_ratio']);
        }
    }

    public function testFastPublishedAliasesClassifyBeforePreparationAndKeepEveryOriginalReferenceSlot(): void
    {
        [$snapshot]=self::fixture('gpt-image-2-fast','openaiD');$metadata=$snapshot['market_metadata'];$frozen=$snapshot;
        foreach(['https://fixtures.invalid/one',['https://fixtures.invalid/one','https://fixtures.invalid/one']]as$urls){
            $request=['provider_params'=>['prompt'=>'Native aliases','image'=>$urls,'resolution'=>'2K','size'=>'16:9']];$original=$request;
            self::assertSame('image_to_image',Materials::select($metadata,$request)['native_mode']);
            self::assertSame(['_billing_video_input'=>false,'_billing_image_input'=>false],Materials::billingInputs($metadata,$request));
            $copy=Flat::normalizeMetadataRequest($metadata,$request);
            self::assertSame('2k',$copy['resolution']);self::assertSame('16:9',$copy['ratio']);
            self::assertSame('image_urls',$copy['_owned_flat_normalization']['fields']['provider_params.image']);
            self::assertStringNotContainsString('https://',json_encode($copy['_owned_flat_normalization'],JSON_THROW_ON_ERROR));
            $a=Images::prepareRequest($snapshot,$request)->nativeInput;$b=Images::prepareRequest($snapshot,$request)->nativeInput;
            self::assertSame($urls,$a['image_urls']);self::assertSame('2k',$a['image_size']);self::assertSame('16:9',$a['aspect_ratio']);
            self::assertArrayNotHasKey('image',$a);self::assertArrayNotHasKey('resolution',$a);self::assertArrayNotHasKey('size',$a);
            self::assertSame($a,$b);self::assertSame($original,$request);self::assertSame($frozen,$snapshot);
        }
    }

    public function testFastBusinessAndEquivalentDualAliasesUseTheSameCanonicalDimensions(): void
    {
        [$snapshot]=self::fixture('gpt-image-2-fast','openaiD');
        $request=['prompt'=>'Business aliases','image'=>'https://fixtures.invalid/business','resolution'=>2,'size'=>' 16:9 '];
        $body=Images::prepareRequest($snapshot,$request)->nativeInput;
        self::assertSame('https://fixtures.invalid/business',$body['image_urls']);self::assertSame('2k',$body['image_size']);self::assertSame('16:9',$body['aspect_ratio']);
        $request=['provider_params'=>['prompt'=>'Equivalent aliases','image_urls'=>['https://fixtures.invalid/one'],
            'image'=>'https://fixtures.invalid/one','image_size'=>'2k','resolution'=>'2K','aspect_ratio'=>'1536x864','size'=>'1536×864']];
        $body=Images::prepareRequest($snapshot,$request)->nativeInput;
        self::assertSame(['https://fixtures.invalid/one'],$body['image_urls']);self::assertSame('1536x864',$body['aspect_ratio']);
    }

    /** @dataProvider conflictingFastAliases */
    public function testFastAliasesNeverHideConflictingOrUnknownNativeInputs(string $case): void
    {
        [$snapshot]=self::fixture('gpt-image-2-fast','openaiD');
        $request=['provider_params'=>['prompt'=>'Alias conflict','image'=>'https://fixtures.invalid/one','resolution'=>'2k','size'=>'16:9']];
        switch($case){
            case 'image':$request['provider_params']['image_urls']=['https://fixtures.invalid/other'];break;
            case 'tier':$request['provider_params']['image_size']='1k';break;
            case 'ratio':$request['provider_params']['aspect_ratio']='9:16';break;
            case 'business_tier':$request['quality']='1k';break;
            case 'business_ratio':$request['ratio']='9:16';break;
            case 'business_assets':$request['reference_images']=['https://fixtures.invalid/business'];break;
            case 'unknown':$request['provider_params']['unknown_native_field']=false;break;
            case 'image_object':$request['provider_params']['image']=['url'=>'https://fixtures.invalid/one'];break;
            case 'wrong_tier':$request['provider_params']['resolution']=true;break;
        }
        $this->expectException(\InvalidArgumentException::class);Images::prepareRequest($snapshot,$request);
    }

    public static function conflictingFastAliases(): array
    {return array_map(static fn($s)=>[$s],['image','tier','ratio','business_tier','business_ratio','business_assets','unknown','image_object','wrong_tier']);}

    public static function veoModels(): array {return [['veo3.1-fast'],['veo3.1-pro']];}

    /** @dataProvider veoModels */
    public function testEachExactVeoKeepsTextImagesAndEveryDocumentedModeWithoutChangingGenerationType(string $model): void
    {
        [$snapshot]=self::fixture($model,'xAIQ');$before=$snapshot;
        foreach(['TEXT'=>[0,1,3],'FIRST&LAST'=>[1,2],'REFERENCE'=>[1,3]]as$mode=>$counts)foreach($counts as$count){
            $urls=array_fill(0,$count,'https://fixtures.invalid/veo-repeat');
            $request=['provider_params'=>['prompt'=>'Documented Veo transport','quality'=>'1080p','duration'=>8,
                'aspect_ratio'=>'16:9','generation_type'=>$mode,'image_urls'=>$urls]];$original=$request;
            self::assertSame($mode,Materials::select($snapshot['market_metadata'],$request)['native_mode']);
            $body=Videos::prepareRequest($snapshot,$request)->nativeInput;$again=Videos::prepareRequest($snapshot,$request)->nativeInput;
            self::assertSame($urls,$body['image_urls']);self::assertSame($mode,$body['generation_type']);self::assertSame(8,$body['duration']);
            self::assertSame($model,$body['model']);self::assertSame('xAIQ',$body['channel']);self::assertSame('1080p',$body['quality']);
            self::assertSame($body,$again);self::assertSame($original,$request);self::assertSame($before,$snapshot);
        }
        $request=['provider_params'=>['prompt'=>'Default TEXT retains images','image_urls'=>['https://fixtures.invalid/veo-text']]];
        $body=Videos::prepareRequest($snapshot,$request)->nativeInput;self::assertSame('TEXT',$body['generation_type']);
        self::assertSame($request['provider_params']['image_urls'],$body['image_urls']);
        $locked=$snapshot;$locked['locked_params']=['resolution'=>'1080P','seconds'=>8];
        $body=Videos::prepareRequest($locked,['material_mode'=>'text','prompt'=>'Frozen dimension aliases'])->nativeInput;
        self::assertSame('1080p',$body['quality']);self::assertSame(8,$body['duration']);
        $old=$snapshot;$record=$old['market_metadata'][Reviewed::PIN];$record['source']['artifact_sha256']=hash('sha256',self::ARTIFACT);
        $record['contract_digest']=Reviewed::contractDigest($record);$old['market_metadata'][Reviewed::PIN]=$record;
        $raw=['provider_params'=>['prompt'=>'Legacy copy','duration'=>8,'quality'=>'1080p']];
        self::assertFalse(Flat::applies($old));self::assertSame($raw,Flat::normalizeMetadataRequest($old['market_metadata'],$raw));
    }

    public static function veoBoundaries(): array
    {
        $out=[];foreach(['veo3.1-fast','veo3.1-pro']as$model)foreach(['text_overflow','frame_empty','frame_overflow','reference_empty','reference_overflow',
            'reference_portrait','unknown_mode','unknown_field','duration','conflict','wrong_identity']as$case)$out[]=[$model,$case];return $out;
    }

    /** @dataProvider veoBoundaries */
    public function testEachExactVeoRejectsDocumentedBoundariesAndUnknownOrConflictingInput(string $model,string $case): void
    {
        [$snapshot]=self::fixture($model,'xAIQ');
        $provider=['prompt'=>'Finite Veo boundary','quality'=>'720p','duration'=>8,'aspect_ratio'=>'16:9','generation_type'=>'TEXT','image_urls'=>[]];
        switch($case){
            case 'text_overflow':$provider['image_urls']=array_fill(0,4,'https://fixtures.invalid/veo');break;
            case 'frame_empty':$provider['generation_type']='FIRST&LAST';break;
            case 'frame_overflow':$provider['generation_type']='FIRST&LAST';$provider['image_urls']=array_fill(0,3,'https://fixtures.invalid/veo');break;
            case 'reference_empty':$provider['generation_type']='REFERENCE';break;
            case 'reference_overflow':$provider['generation_type']='REFERENCE';$provider['image_urls']=array_fill(0,4,'https://fixtures.invalid/veo');break;
            case 'reference_portrait':$provider['generation_type']='REFERENCE';$provider['image_urls']=['https://fixtures.invalid/veo'];$provider['aspect_ratio']='9:16';break;
            case 'unknown_mode':$provider['generation_type']='OTHER';break;
            case 'unknown_field':$provider['unknown_native']=false;break;
            case 'duration':$provider['duration']=8.5;break;
            case 'wrong_identity':$provider['model']=$model==='veo3.1-fast'?'veo3.1-pro':'veo3.1-fast';break;
        }
        $request=['provider_params'=>$provider];if($case==='conflict')$request['quality']='4k';
        $this->expectException(\InvalidArgumentException::class);Videos::prepareRequest($snapshot,$request);
    }

    /** @dataProvider invalidQuantities */
    public function testSingleTaskQuantityIsRejectedBeforeClassificationBillingOrPreparation($quantity): void
    {
        foreach([['gpt-image-2-fast','openaiD'],['gpt-image-2.5','OpenAIYI'],['grok-video','xAIQ']]as[$model,$channel]){
            [$snapshot]=self::fixture($model,$channel);$request=self::request($model,$channel);$request['quantity']=$quantity;
            foreach(['select','billingInputs','prepare']as$entry){
                try{if($entry==='prepare')self::prepare($model,$snapshot,$request);else Materials::$entry($snapshot['market_metadata'],$request);
                    self::fail('Invalid single-task quantity reached '.$entry);
                }catch(\InvalidArgumentException $e){self::assertStringContainsString('整数 1',$e->getMessage());}
            }
        }
    }

    public static function invalidQuantities(): array {return [[1.5],[true],['1suffix'],[1.0],[2],[0]];}

    public function testLegacyPinsDoNotAdoptFastAliasOrQuantityNormalization(): void
    {
        [$snapshot]=self::fixture('gpt-image-2-fast','openaiD');$contract=$snapshot['market_metadata'][Reviewed::PIN];
        $contract['source']['artifact_sha256']=hash('sha256',self::ARTIFACT);$contract['contract_digest']=Reviewed::contractDigest($contract);
        $snapshot['market_metadata'][Reviewed::PIN]=$contract;
        $request=['quantity'=>1.5,'provider_params'=>['prompt'=>'Legacy input','image'=>'https://fixtures.invalid/one','resolution'=>'2k','size'=>'16:9']];
        self::assertSame($request,Flat::normalizeRequest($snapshot,$request));
        self::assertSame($request,Flat::normalizeMetadataRequest($snapshot['market_metadata'],$request));
        self::assertSame('text_to_image',Materials::select($snapshot['market_metadata'],$request)['native_mode']);
    }

    /** Public quote/reserve only, synthetic isolated catalogue, no submission, and unconditional outer rollback. */
    public function testFastPublicQuoteAndReserveNegotiateAliasesAndRejectFractionalQuantitiesBeforeAnyReservation(): void
    {
        if(getenv('AIGC_OWNED_PLATFORM_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();$db=\think\facade\Db::connect();
        self::assertContains($db->getConfig('hostname'),['127.0.0.1','localhost']);self::assertContains($db->getConfig('database'),['x_cn','z_cn']);
        $user=(int)\think\facade\Db::name('user')->where('tenant_id',1)->where('user_money','>',100)->order('user_money','desc')->value('id');
        if(!$user)self::markTestSkipped('Funded local rollback fixture unavailable');
        [$frozen]=self::fixture('gpt-image-2-fast','openaiD');$metadata=$frozen['market_metadata'];$contract=$metadata[Reviewed::PIN];unset($metadata[Reviewed::PIN]);
        $catalogue=tempnam(sys_get_temp_dir(),'owned-flat-test-');
        file_put_contents($catalogue,json_encode(['version'=>2,'contracts'=>[$contract]],JSON_THROW_ON_ERROR));
        $previous=getenv('MARKET_REVIEWED_GENERATION_CONTRACTS_PATH');putenv('MARKET_REVIEWED_GENERATION_CONTRACTS_PATH='.$catalogue);
        $key='owned-flat-'.bin2hex(random_bytes(8));$consumptionId=0;\think\facade\Db::startTrans();
        try{
            \think\facade\Db::name('tenant')->where('id',1)->lock(true)->find();\think\facade\Db::name('user')->where('id',$user)->lock(true)->find();
            $balance=(float)\think\facade\Db::name('user')->where('id',$user)->value('user_money');
            $product=\app\common\model\power\PowerMarketProduct::create(['product_code'=>$key,'name'=>'Offline flat alias fixture',
                'resource_type'=>'model','model_type'=>'image','source_code'=>'offline-fixture','upstream_resource_key'=>$key,
                'upstream_model_code'=>'gpt-image-2-fast','upstream_channel_code'=>'openaiD','source_payload'=>['market_metadata'=>$metadata],
                'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            $skus=[];
            foreach(['1k','2k']as$tier)$skus[$tier]=\app\common\model\power\PowerMarketSku::create(['product_id'=>$product['id'],
                'sku_key'=>$key.'-'.$tier,'title'=>'Offline flat alias fixture','locked_params'=>['image_size'=>$tier],
                'usage_unit'=>'per_call','usage_unit_size'=>1,'upstream_price'=>1,'sale_points'=>1,'sale_status'=>1,'status'=>1,
                'source_payload'=>['meter_contract'=>['version'=>1,'basis'=>'call','rounding'=>'ceil']],'create_time'=>time(),'update_time'=>time()]);
            $selection=['model_id'=>'market_image_model:'.$product['id'],'resource_type'=>'model',
                'provider_params'=>['prompt'=>'Native public aliases','image'=>['https://fixtures.invalid/one','https://fixtures.invalid/one'],'resolution'=>'2K','size'=>'16:9']];
            $original=$selection;$quote=Images::quote(1,$selection);self::assertSame((int)$skus['2k']['id'],(int)$quote['market_sku_id']);
            $logs=(int)\think\facade\Db::name('ai_consumption_log')->count();$tasks=(int)\think\facade\Db::name('ai_app_task')->count();
            foreach([1.5,true,'1suffix']as$bad)foreach(['quote','reserve']as$entry){
                $invalid=$selection;$invalid['quantity']=$bad;
                try{if($entry==='quote')Images::quote(1,$invalid);else Images::reserve(1,$user,'generate',$key,$invalid,$invalid,1,'aigc_image','aigc_image_task');
                    self::fail('Invalid quantity reached public '.$entry);
                }catch(\InvalidArgumentException $e){self::assertStringContainsString('整数 1',$e->getMessage());}
                self::assertSame($logs,(int)\think\facade\Db::name('ai_consumption_log')->count());
                self::assertSame($tasks,(int)\think\facade\Db::name('ai_app_task')->count());
                self::assertEqualsWithDelta($balance,(float)\think\facade\Db::name('user')->where('id',$user)->value('user_money'),0.00001);
            }
            $reserved=Images::reserve(1,$user,'generate',$key,$selection,$selection,1,'aigc_image','aigc_image_task');$consumptionId=$reserved['consumption_id'];
            $log=\app\common\model\ai\AiConsumptionLog::find($consumptionId);
            self::assertSame('',(string)$log['upstream_task_id']);self::assertSame('reserved',(string)$log['billing_status']);
            self::assertSame((int)$skus['2k']['id'],(int)$log['sku_id']);
            $body=Images::prepareRequest($reserved['market_snapshot'],$selection)->nativeInput;
            self::assertSame($selection['provider_params']['image'],$body['image_urls']);self::assertSame('2k',$body['image_size']);self::assertSame('16:9',$body['aspect_ratio']);
            self::assertSame($original,$selection);
        }finally{
            \think\facade\Db::rollback();putenv($previous===false?'MARKET_REVIEWED_GENERATION_CONTRACTS_PATH':'MARKET_REVIEWED_GENERATION_CONTRACTS_PATH='.$previous);unlink($catalogue);
        }
        self::assertSame(0,(int)\think\facade\Db::name('power_market_product')->where('product_code',$key)->count());
        self::assertSame(0,(int)\think\facade\Db::name('ai_consumption_log')->where('id',$consumptionId)->count());
    }

    public function testNativePromptAndSkuConflictsAreRejectedInsteadOfSilentlyOverwritingDeclaredInput(): void
    {
        [$snapshot]=self::fixture('gpt-image-2','OpenaiM');$snapshot['locked_params']['resolution']='2k';
        $request=['material_mode'=>'text','provider_params'=>['prompt'=>'Native input','resolution'=>'1k']];
        $this->expectException(\InvalidArgumentException::class);Images::prepareRequest($snapshot,$request);
    }

    public function testFrozenDimensionAliasesRemainAuthoritativeAndTheOriginalSnapshotIsUnchanged(): void
    {
        [$snapshot]=self::fixture('gpt-image-2.5','OpenAIYI');$snapshot['locked_params']=['image_size'=>'2K','ratio'=>'3:2'];$before=$snapshot;
        $body=Images::prepareRequest($snapshot,['material_mode'=>'text','prompt'=>'Frozen aliases'])->nativeInput;
        self::assertSame('2k',$body['resolution']);self::assertSame('3:2',$body['aspect_ratio']);self::assertSame($before,$snapshot);
        [$video]=self::fixture('grok-video','xAIQ');$video['locked_params']=['resolution'=>'720P','seconds'=>10];
        $body=Videos::prepareRequest($video,['material_mode'=>'text','prompt'=>'Frozen video aliases'])->nativeInput;
        self::assertSame('720p',$body['quality']);self::assertSame(10,$body['duration']);
    }

    public function testFractionalNativeDurationIsRejectedBeforeIntegerNormalizationCanTruncateIt(): void
    {
        [$snapshot]=self::fixture('grok-video','xAIQ');
        $this->expectException(\InvalidArgumentException::class);
        Videos::prepareRequest($snapshot,['material_mode'=>'text','provider_params'=>['prompt'=>'Fractional input','duration'=>6.5]]);
    }

    public function testSourceMatricesAreSeparateAndTheirMissingCellsAreRejectedByActualPrepare(): void
    {
        [$dakka]=self::fixture('gpt-image-2.5-flare','OpenAIYI');[$fast]=self::fixture('gpt-image-2-fast','openaiD');
        self::assertSame('2048x688',Flat::matrix('dakka_pixels')['3:1']['1k']);self::assertSame('2016x672',Flat::matrix('fast_pixels')['3:1']['1k']);
        self::assertSame(43,array_sum(array_map('count',Flat::matrix('dakka_pixels'))));
        foreach(['dakka_pixels'=>$dakka,'fast_pixels'=>$fast]as$family=>$snapshot)foreach(Flat::matrix($family)as$ratio=>$cells)foreach($cells as$resolution=>$pixels){
            $body=Images::prepareRequest($snapshot,['material_mode'=>'text','prompt'=>'Finite matrix fixture','ratio'=>$ratio,'quality'=>$resolution])->nativeInput;
            self::assertSame($ratio,$body['aspect_ratio']);
            self::assertSame($resolution,$body[$family==='fast_pixels'?'image_size':'resolution']);
        }
        $this->expectException(\InvalidArgumentException::class);
        Images::prepareRequest($dakka,['material_mode'=>'text','prompt'=>'No fabricated 4k cell','ratio'=>'3:1','quality'=>'4k']);
    }

    /** @dataProvider changedBindings */
    public function testChangedPublicCapsOrPlatformIdCannotReuseAnOlderDirectExecutionPin(string $field): void
    {
        [$snapshot]=self::fixture('nano-banana-2','Google');
        if($field==='cap')$snapshot['market_metadata']['max_reference_images']=11;
        else $snapshot['market_metadata']['upstream_metadata']['model_id']=502;
        $this->expectException(\InvalidArgumentException::class);Images::prepareRequest($snapshot,self::request('nano-banana-2','Google'));
    }

    public static function changedBindings(): array {return [['cap'],['id']];}

    public function testConflictingNativeAndBusinessPromptsAreNotRebuiltUnderTheNewPin(): void
    {
        [$snapshot]=self::fixture('gpt-image-2.5','OpenAIYI');
        $this->expectException(\InvalidArgumentException::class);
        Images::prepareRequest($snapshot,['material_mode'=>'text','prompt'=>'Business source','provider_params'=>['prompt'=>'Different native source']]);
    }

    public function testSyntheticSourceOrMissingExecutionBindingCannotSelectTheNewSerializer(): void
    {
        [$snapshot]=self::fixture('gpt-image-2-fast','openaiD');$contract=$snapshot['market_metadata'][Reviewed::PIN];
        $contract['source']['artifact_sha256']=hash('sha256',self::ARTIFACT);$contract['contract_digest']=Reviewed::contractDigest($contract);
        $snapshot['market_metadata'][Reviewed::PIN]=$contract;self::assertFalse(Flat::applies($snapshot));
        unset($contract['execution_binding']);$contract['contract_digest']=Reviewed::contractDigest($contract);$snapshot['market_metadata'][Reviewed::PIN]=$contract;
        self::assertFalse(Flat::applies($snapshot));
    }

    public function testEvidenceResolverDoesNotGuessMissingFallbackStateOrTreatAnEnabledChannelMappingAsDirect(): void
    {
        $profile=array_values(array_filter(Builder::profiles(),static fn($p)=>$p['model_code']==='nano-banana-2'&&$p['channel_code']==='Google'))[0];
        $model=['platform_model_id'=>63,'model_code'=>'nano-banana-2','channel_code'=>'Google','upstream_model'=>'synthetic',
            'channel_mapping_enabled'=>false,'observed_at'=>'2026-11-01T00:00:00+08:00','api_config'=>['draw'=>[]],
            'max_reference_images'=>12,'max_reference_videos'=>0,'max_reference_audios'=>0];
        $make=static fn($row)=>Execution::parse(json_encode(['version'=>1,'models'=>[$row],'channels'=>[['code'=>'Google','extra_config'=>[]]]],JSON_THROW_ON_ERROR));
        self::assertSame(['current_fallback_chain_enabled_or_unverified'],Execution::resolve($make($model),$profile,[])['missing']);
        $model['fallback_configured']=false;$model['channel_mapping_enabled']=true;
        self::assertSame(['current_channel_mapping_enabled_or_unverified'],Execution::resolve($make($model),$profile,[])['missing']);
        $model['channel_mapping_enabled']=false;
        self::assertSame(['configured_api_key_smart_route_force_context_required'],Execution::resolve($make($model),$profile,[])['missing']);
        $model['smart_route_context']=['smart_route_supported'=>true,'smart_route_tenant_force_enabled'=>true,'smart_route_user_force_enabled'=>false];
        self::assertSame(['configured_api_key_forces_smart_route_target_closure_required'],Execution::resolve($make($model),$profile,[])['missing']);
    }

    public function testNullUiDefaultsRequireASeparateExactEmptyCurrentPublicDeclaration(): void
    {
        $profile=array_values(array_filter(Builder::profiles(),static fn($p)=>$p['model_code']==='nano-banana-2'&&$p['channel_code']==='Google'))[0];
        $model=['platform_model_id'=>63,'model_code'=>'nano-banana-2','channel_code'=>'Google','upstream_model'=>'synthetic',
            'channel_mapping_enabled'=>false,'fallback_configured'=>false,'observed_at'=>'2026-11-01T00:00:00+08:00','api_config'=>['draw'=>[]],'default_params'=>null,
            'smart_route_context'=>['smart_route_supported'=>false,'smart_route_tenant_force_enabled'=>false,'smart_route_user_force_enabled'=>false],
            'max_reference_images'=>12,'max_reference_videos'=>0,'max_reference_audios'=>0];
        $bytes=json_encode(['version'=>1,'models'=>[$model],'channels'=>[['channel_code'=>'Google','extra_config'=>[]]]],JSON_THROW_ON_ERROR);
        self::assertSame(['null_editor_defaults_require_exact_empty_current_public_declaration'],Execution::resolve(Execution::parse($bytes),$profile,[])['missing']);
        $public=json_encode(['version'=>1,'models'=>[['model_code'=>'nano-banana-2','channel_code'=>'Google','identity_verified'=>true,'declaration'=>['default_params'=>[]]]]],JSON_THROW_ON_ERROR);
        $resolved=Execution::resolve(Execution::parse($bytes,$public),$profile,[]);
        self::assertSame([],$resolved['missing']);self::assertSame('null_editor_cross_checked_against_empty_public_defaults',$resolved['binding']['ui_default_params_state']);
        self::assertNull($model['default_params']);
    }

    public function testCurrentEvidenceGatesPreviouslyCompleteNativeProfilesAndAllowsTheirExactDirectDrivers(): void
    {
        foreach(['qwen-image-3.0','qwen-image-3.0-pro','h3-video','wan3.0-video']as$model){
            $profile=array_values(array_filter(Builder::profiles(),static fn($p)=>$p['model_code']===$model))[0];
            $video=$profile['generation_type']==='video';$config=$video?['video'=>$model==='h3-video'?['content_validation'=>'minimax_h3']:['request_shape'=>'dashscope_task']]
                :['draw'=>['driver'=>'dashscope_qwen_image']];
            $row=['platform_model_id'=>501,'model_code'=>$model,'channel_code'=>$profile['channel_code'],'upstream_model'=>$model,
                'channel_mapping_enabled'=>false,'fallback_configured'=>false,'observed_at'=>'2026-11-01T00:00:00+08:00','api_config'=>$config,
                'max_reference_images'=>3,'max_reference_videos'=>0,'max_reference_audios'=>0,
                'smart_route_context'=>['smart_route_supported'=>true,'smart_route_tenant_force_enabled'=>true,'smart_route_user_force_enabled'=>true]];
            $make=static fn($r)=>Execution::parse(json_encode(['version'=>1,'models'=>[$r],'channels'=>[['channel_code'=>$profile['channel_code'],'extra_config'=>[]]]],JSON_THROW_ON_ERROR));
            self::assertSame(['configured_api_key_forces_smart_route_target_closure_required'],Execution::resolve($make($row),$profile,[])['missing']);
            $row['smart_route_context']['smart_route_tenant_force_enabled']=false;$row['smart_route_context']['smart_route_user_force_enabled']=false;
            self::assertSame([],Execution::resolve($make($row),$profile,[])['missing']);
        }
    }
}
