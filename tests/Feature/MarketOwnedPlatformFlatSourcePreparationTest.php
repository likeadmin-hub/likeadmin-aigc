<?php
namespace Tests\Feature;

use app\common\service\power\MarketOwnedPlatformContractBuilder as Builder;
use app\common\service\power\MarketOwnedPlatformContractRules as Owned;
use app\common\service\power\MarketOwnedPlatformFlatSourceRules as Flat;
use app\common\service\power\MarketOwnedPlatformExecutionEvidence as Execution;
use app\common\service\power\MarketGenerationContractReview as Review;
use app\common\service\power\MarketGenerationReviewedContract as Reviewed;
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
        $family=Flat::family($model,$channel);$maximum=$family==='grok'?7:($model==='nano-banana-2.1'?10:12);
        $binding=['params_schema'=>['synthetic_original_marker'=>['type'=>'string']],'material_capabilities'=>[]];
        $limits=['max_reference_images'=>$maximum,'max_reference_videos'=>0,'max_reference_audios'=>0];
        $execution=['binding'=>['version'=>1,'kind'=>'owned_platform_direct_execution','artifact_sha256'=>hash('sha256','synthetic current execution'),
            'platform_model_id'=>501,'model_code'=>$model,'channel_code'=>$channel,'upstream_model'=>'synthetic-upstream',
            'observed_at'=>'2026-11-01T00:00:00+08:00','configuration_fingerprint'=>hash('sha256',$model.'|'.$channel),
            'channel_mapping_enabled'=>false,'fallback_configured'=>false,'material_limits'=>$limits,
            'smart_route_context'=>['request_control'=>'omitted','smart_route_supported'=>true,
                'smart_route_tenant_force_enabled'=>false,'smart_route_user_force_enabled'=>false]],
            'config'=>[$family==='grok'?'video':'draw'=>['request_params'=>['image_urls'=>'image_urls']]],'public_defaults'=>[]];
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
    {return $model==='grok-video'?Videos::prepareRequest($snapshot,$request)->nativeInput:Images::prepareRequest($snapshot,$request)->nativeInput;}

    private static function request(string $model,string $channel): array
    {
        $request=['prompt'=>'Synthetic source contract','material_mode'=>'text','ratio'=>'16:9',
            'quality'=>$channel==='Google'?'1K':($model==='grok-video'?'720p':'1k')];
        if($model==='grok-video')$request['duration']=6;
        return $request;
    }

    /** @dataProvider exactModels */
    public function testAllTenExactFlatIdentitiesCompileAndPrepareTheirTextAndMaximumReferenceModes(string $model,string $channel): void
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
}
