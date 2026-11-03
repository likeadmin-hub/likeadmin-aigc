<?php
namespace Tests\Feature;

use app\common\service\power\MarketOwnedPlatformContractBuilder as Builder;
use app\common\service\power\MarketOwnedPlatformContractRules as Owned;
use app\common\service\power\MarketGenerationContractReview as Review;
use app\common\service\power\MarketGenerationReviewedContract as Reviewed;
use app\common\service\power\MarketGenerationRules as Rules;
use app\common\service\power\MarketGenerationSchema as Schema;
use PHPUnit\Framework\TestCase;

/** Synthetic original declarations prove compiler/validator behavior, never deployed binding or provider generation. */
class MarketOwnedPlatformContractBuilderTest extends TestCase
{
    private const ARTIFACT='Synthetic compiler test artifact. This is not the authenticated platform source artifact.';

    private static function entry(string $code): array
    {
        $profile=array_values(array_filter(Builder::profiles(),static fn($p)=>$p['model_code']===$code))[0];
        $binding=['params_schema'=>['synthetic_original_marker'=>['type'=>'string']],'material_capabilities'=>[]];
        return (new \ReflectionMethod(Builder::class,'entry'))->invoke(null,$profile,$binding,['upstream_model'=>'synthetic-q4-upstream']);
    }

    private static function bundle(string $code): array
    {
        return ['version'=>1,'source'=>['kind'=>'gateway_validator','reference'=>'synthetic/compiler-source-only',
            'revision'=>Owned::REVISION,'reviewed_at'=>'2026-11-01T00:00:00+08:00','artifact_sha256'=>hash('sha256',self::ARTIFACT)],
            'entries'=>[self::entry($code)]];
    }

    private static function rule(string $profile,string $mode=''): array
    {
        return ['kind'=>'owned_platform','profile'=>$profile,'mode'=>$mode,'source_revision'=>Owned::REVISION,
            'upstream_model'=>'synthetic-q4-upstream','message'=>'Synthetic source-backed rule rejection'];
    }

    private static function qwen(): array
    {
        return ['input'=>['messages'=>[['role'=>'user','content'=>[['text'=>'Synthetic prompt']]]]],
            'parameters'=>['n'=>1,'size'=>'1024*1024','seed'=>0,'watermark'=>false]];
    }

    private static function h3(): array
    {
        return ['content'=>[['type'=>'text','text'=>'Synthetic prompt']], 'resolution'=>'2K','duration'=>5,'ratio'=>'16:9'];
    }

    private static function wan(): array
    {
        return ['input'=>['prompt'=>'Synthetic prompt'],'parameters'=>['resolution'=>'720P','audio'=>false,'seed'=>0]];
    }

    private static function q4(): array { return ['images'=>['https://fixtures.invalid/frame.png']]; }

    public function testAllEighteenIdentitiesAreExplicitAndPartialModelsAreNotCompiled(): void
    {
        $profiles=Builder::profiles();self::assertCount(18,$profiles);
        $keys=array_map(static fn($p)=>$p['generation_type'].'|'.$p['model_code'].'|'.$p['channel_code'],$profiles);
        self::assertCount(18,array_unique($keys));
        self::assertContains('image|gpt-image-2|OpenaiM',$keys);self::assertContains('image|gpt-image-2|OpenAIYI',$keys);
        self::assertCount(17,array_filter($profiles,static fn($p)=>$p['profile']!==null));
        foreach($profiles as$p)if($p['profile']===null)self::assertNotEmpty($p['missing_constraints']);
    }

    /** @dataProvider candidateModels */
    public function testCompleteSourceProfilesPassTheRealCandidateAndReviewedCompilers(string $model): void
    {
        $bundle=self::bundle($model);$entry=$bundle['entries'][0];
        $candidates=Review::candidates($bundle,self::ARTIFACT);self::assertCount(1,$candidates['candidates']);
        $catalogue=Review::reviewedCatalogue($bundle,self::ARTIFACT);self::assertCount(1,$catalogue['contracts']);
        $contract=$catalogue['contracts'][0];
        self::assertSame('unverified',$contract['sku_pricing']);self::assertSame('unverified',$contract['live_generation']);
        self::assertSame($entry['model_code'],$contract['model_code']);self::assertSame($entry['channel_code'],$contract['channel_code']);
        self::assertSame(Reviewed::digest([$entry['binding_declaration']['params_schema'],[]]),$contract['declaration_fingerprint']);
        self::assertSame(['synthetic_original_marker'=>['type'=>'string']],$entry['binding_declaration']['params_schema']);
        foreach($entry['modes']as$mode)self::assertSame('owned_platform',$mode['rules'][0]['kind']);
    }

    public static function candidateModels(): array
    {
        return [['qwen-image-3.0'],['qwen-image-3.0-pro'],['q4-preview-video'],['h3-video'],['wan3.0-video']];
    }

    public function testExportRequiresTheActualPinnedSourceBytesNotACallerClaimedRevision(): void
    {
        $fake=json_encode(['version'=>1,'repository'=>'https://gitee.com/yc_open/platform-api-saas.git','revision'=>Owned::REVISION,'files'=>[]]);
        $this->expectException(\InvalidArgumentException::class);
        Builder::build(['version'=>1,'captured_at'=>'2026-11-01T00:00:00+08:00','entries'=>[]],$fake);
    }

    public function testActualVerificationClassifiesExpectedOverflowAsParameterRejectionWhileKeepingFullScopeGate(): void
    {
        $bundle=self::bundle('qwen-image-3.0');$entry=$bundle['entries'][0];
        $identity=array_intersect_key($entry,array_flip(['generation_type','model_code','channel_code']));
        $bundle['model_states']=[$identity+['status'=>'source_candidate_ready_not_activated','facts'=>['parameters'=>[]]]];
        $metadata=$entry['binding_declaration']+['upstream_metadata'=>[
            'model_code'=>$entry['model_code'],'channel_code'=>$entry['channel_code']]];
        $snapshot=['entries'=>[['generation_type'=>'image','metadata'=>$metadata,
            'product'=>['upstream_model_code'=>$entry['model_code'],'upstream_channel_code'=>$entry['channel_code']]]]];
        $before=Reviewed::digest($snapshot);
        $result=\app\common\service\power\MarketOwnedPlatformPreparationVerification::verify($bundle,$snapshot,self::ARTIFACT);
        $cases=array_column($result['models'][0]['cases'],null,'case');
        self::assertSame('prepared_and_replayed',$cases['image_to_image_maximum']['status']);
        self::assertSame('rejected',$cases['image_to_image_overflow']['status']);
        self::assertSame(\InvalidArgumentException::class,$cases['image_to_image_overflow']['error_class']);
        self::assertTrue($result['models'][0]['frozen_unchanged']);self::assertTrue($result['original_snapshot_unchanged']);
        self::assertSame($before,Reviewed::digest($snapshot));
        self::assertFalse($result['success'],'A single synthetic identity cannot satisfy the unchanged 18-model success gate');
    }

    public function testSchemaAndRulesRetainZeroFalseAndRawDeclarationSeparation(): void
    {
        $entry=self::entry('qwen-image-3.0');$body=self::qwen();
        Schema::validate($entry['modes']['text_to_image']['schema'],$body);
        Rules::validate($entry['modes']['text_to_image']['rules'],$body);
        self::assertSame(0,$body['parameters']['seed']);self::assertFalse($body['parameters']['watermark']);
        self::assertArrayNotHasKey('input',$entry['binding_declaration']['params_schema']);
    }

    /** @dataProvider preparedTextModels */
    public function testSourceCandidateValidatesTheRealRegisteredTextPreparation(string $model,string $mode,string $quality): void
    {
        $bundle=self::bundle($model);$entry=$bundle['entries'][0];
        $contract=Review::reviewedCatalogue($bundle,self::ARTIFACT)['contracts'][0];
        $metadata=$entry['binding_declaration']+['upstream_metadata'=>['model_code'=>$model,'channel_code'=>$entry['channel_code']],Reviewed::PIN=>$contract];
        $snapshot=['resource_type'=>'model','model_code'=>$model,'channel_code'=>$entry['channel_code'],'locked_params'=>[],
            'params_schema'=>$metadata['params_schema'],'market_metadata'=>$metadata];
        $request=['prompt'=>'Synthetic compiler preparation','material_mode'=>$mode,'quality'=>$quality,'ratio'=>'16:9','duration'=>5];
        $prepared=$entry['generation_type']==='image'
            ? \app\common\service\power\MarketImageModelRuntimeService::prepareRequest($snapshot,$request)
            : \app\common\service\power\MarketVideoRuntimeService::prepareRequest($snapshot,$request);
        self::assertSame($model,$prepared->nativeInput['model']);
        if($model==='h3-video')self::assertSame('5',$prepared->nativeInput['duration']);
        if(str_starts_with($model,'qwen-image'))self::assertSame(1,$prepared->nativeInput['parameters']['n']);
        self::assertSame($entry['binding_declaration'],array_intersect_key($metadata,array_flip(['params_schema','material_capabilities'])));
    }

    public static function preparedTextModels(): array
    {
        return [['qwen-image-3.0','text','1k'],['qwen-image-3.0-pro','text','1k'],['h3-video','text','2K'],['wan3.0-video','text','720P']];
    }

    /** @dataProvider badQwen */
    public function testQwenNestedCountsPixelProductsAndAgentConditionsAreActuallyEnforced(string $case): void
    {
        $body=self::qwen();$content=&$body['input']['messages'][0]['content'];
        switch($case){
            case 'two_messages':$body['input']['messages'][]=$body['input']['messages'][0];break;
            case 'wrong_role':$body['input']['messages'][0]['role']='assistant';break;
            case 'two_texts':$content[]=['text'=>'second'];break;
            case 'blank_text':$content[0]['text']='  ';break;
            case 'both_keys':$content[0]['image']='https://fixtures.invalid/a.png';break;
            case 'four_images':for($i=0;$i<4;$i++)$content[]=['image'=>'https://fixtures.invalid/'.$i];break;
            case 'bad_scheme':$content[]=['image'=>'file:///fixture.png'];break;
            case 'agent_with_image':$content[]=['image'=>'https://fixtures.invalid/a'];$body['parameters']['prompt_extend_mode']='agent';break;
            case 'two_outputs':$body['parameters']['n']=2;break;
            case 'pixel_product':$body['parameters']['size']='2048*2049';break;
            case 'pixel_minimum':$body['parameters']['size']='511*512';break;
            case 'ratio':$body['parameters']['size']='8192*512';break;
            case 'seed':$body['parameters']['seed']=2147483648;break;
        }
        self::assertFalse(Owned::valid(self::rule('qwen_image_v1'),$body));
    }

    public static function badQwen(): array
    {
        return array_map(static fn($x)=>[$x],['two_messages','wrong_role','two_texts','blank_text','both_keys','four_images','bad_scheme',
            'agent_with_image','two_outputs','pixel_product','pixel_minimum','ratio','seed']);
    }

    public function testH3UsesAtLeastOneTextAndImplicitFirstFrameWithoutInventingTailOnlySupport(): void
    {
        $body=self::h3();$body['content'][]=['type'=>'text','text'=>'second text'];
        self::assertTrue(Owned::valid(self::rule('h3_content_v1','text_to_video'),$body));
        $body['content'][]=['type'=>'image_url','image_url'=>['url'=>'https://fixtures.invalid/first']];
        self::assertTrue(Owned::valid(self::rule('h3_content_v1','image_to_video'),$body));
        $body['content'][2]['role']='last_frame';
        self::assertFalse(Owned::valid(self::rule('h3_content_v1'),$body));
        $body=self::h3();$body['ratio']='adaptive';
        self::assertFalse(Owned::valid(self::rule('h3_content_v1'),$body));
    }

    public function testH3ReferenceAudioRequiresAVisualReferenceAndCannotMixWithFrames(): void
    {
        $body=self::h3();$body['content'][]=['type'=>'audio_url','role'=>'reference_audio','audio_url'=>['url'=>'https://fixtures.invalid/a']];
        self::assertFalse(Owned::valid(self::rule('h3_content_v1'),$body));
        $body['content'][]=['type'=>'image_url','role'=>'reference_image','image_url'=>['url'=>'https://fixtures.invalid/i']];
        self::assertTrue(Owned::valid(self::rule('h3_content_v1','reference_to_video'),$body));
        $body['content'][]=['type'=>'image_url','role'=>'first_frame','image_url'=>['url'=>'https://fixtures.invalid/f']];
        self::assertFalse(Owned::valid(self::rule('h3_content_v1'),$body));
    }

    public function testWanDefaultsAndTailOnlyFollowItsOwnSourceRatherThanH3(): void
    {
        $body=self::wan();self::assertTrue(Owned::valid(self::rule('wan_video_media_v1','text_to_video'),$body));
        $body['input']=['media'=>[['type'=>'last_frame','url'=>'https://fixtures.invalid/last']]];
        self::assertTrue(Owned::valid(self::rule('wan_video_media_v1','last_frame'),$body));
        $body['parameters']['duration']=1;self::assertFalse(Owned::valid(self::rule('wan_video_media_v1'),$body));
        $body['input']['media']=[['type'=>'reference_video','url'=>'oss://fixture/reference']];
        self::assertTrue(Owned::valid(self::rule('wan_video_media_v1','reference_to_video'),$body));
        $body['parameters']['duration']=-1;self::assertTrue(Owned::valid(self::rule('wan_video_media_v1'),$body));
        $body['parameters']['duration']=0;self::assertFalse(Owned::valid(self::rule('wan_video_media_v1'),$body));
    }

    public function testWanFileLinkExclusivityAndPerKindBoundsAreNotReplacedByAnInventedGlobalCap(): void
    {
        $body=self::wan();$body['input']['media']=[];
        foreach(['reference_image'=>10,'reference_video'=>5,'reference_audio'=>5,'file'=>1]as$type=>$count)
            for($i=0;$i<$count;$i++)$body['input']['media'][]=['type'=>$type,'url'=>'https://fixtures.invalid/'.$type.'/'.$i];
        self::assertCount(21,$body['input']['media']);self::assertTrue(Owned::valid(self::rule('wan_video_media_v1'),$body));
        $body['input']['media'][]=['type'=>'link','url'=>'https://fixtures.invalid/page'];
        self::assertFalse(Owned::valid(self::rule('wan_video_media_v1'),$body));
        array_pop($body['input']['media']);$body['input']['media'][]=['type'=>'reference_video','url'=>'https://fixtures.invalid/extra'];
        self::assertFalse(Owned::valid(self::rule('wan_video_media_v1'),$body));
    }

    public function testQ4StrictBooleansJsonAndWholeRequestBytesUseTheVerifiedUpstreamModel(): void
    {
        $rule=self::rule('q4_video_v1');$body=self::q4();self::assertTrue(Owned::valid($rule,$body));
        foreach([['duration'=>'5'],['audio'=>'false'],['seed'=>-1],['meta_data'=>'{bad'],['wm_position'=>5],['images'=>[]]]as$change)
            self::assertFalse(Owned::valid($rule,array_replace($body,$change)));
        self::assertTrue(Owned::valid($rule,$body+['audio'=>false,'seed'=>0,'meta_data'=>'{"fixture":true}']));
        $body['images']=['data:image/png;base64,'.str_repeat('AAAA',5*1024*1024)];
        self::assertFalse(Owned::valid($rule,$body));
        unset($rule['upstream_model']);self::assertFalse(Owned::valid($rule,self::q4()));
    }

    public function testUnsupportedRuleRevisionProfileAndEncoderCannotCompile(): void
    {
        foreach(['revision','profile','protocol','mode','upstream_model']as$case){
            $bundle=self::bundle('q4-preview-video');$rule=&$bundle['entries'][0]['modes']['image_to_video']['rules'][0];
            if($case==='revision')$rule['source_revision']='another-commit';
            if($case==='profile')$rule['profile']='evaluate_php';
            if($case==='protocol')$bundle['entries'][0]['generation_protocol']='flat_video_v1';
            if($case==='mode')$rule['mode']='unreviewed_mode';
            if($case==='upstream_model')unset($rule['upstream_model']);
            try{Review::candidates($bundle,self::ARTIFACT);self::fail('Unsupported source rule compiled');}
            catch(\InvalidArgumentException $expected){self::assertNotEmpty($expected->getMessage());}
            unset($rule);
        }
    }
}
