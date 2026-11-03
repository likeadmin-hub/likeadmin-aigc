<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationKlingRules as Kling;
use app\common\service\power\MarketGenerationMaterials as Materials;
use app\common\service\power\MarketGenerationNativeContract as Native;
use app\common\service\power\MarketGenerationReviewedContract as Reviewed;
use app\common\service\power\MarketGenerationRequest as Request;
use PHPUnit\Framework\TestCase;

/** Offline admitted grammar; no media probe, database or supplier requests. */
class MarketVideoReferencePromptTest extends TestCase
{
    private array $metadata;
    private array $mode;
    private $previous;
    private const IMAGE='https://fixtures.invalid/image.png';
    private const AUDIO='https://fixtures.invalid/audio.wav';

    protected function setUp(): void
    {
        $uri=['type'=>'string','format'=>'uri'];$urls=['type'=>'array','items'=>$uri];
        $schema=['type'=>'object','properties'=>['prompt'=>['type'=>'string'],'keep_original_sound'=>['type'=>'boolean'],
            'first_frame_url'=>$uri,'last_frame_url'=>$uri,'image_urls'=>$urls,'video_urls'=>$urls,'keyframe_urls'=>$urls,
            'voices'=>['type'=>'array','items'=>['type'=>'object','required'=>['name','audio_url'],'properties'=>[
                'name'=>['type'=>'string','pattern'=>'^[一-龥A-Za-z0-9_]{1,20}$'],'audio_url'=>$uri]]]]];
        $fields=[];foreach(['first_frame_url'=>['image','first'],'last_frame_url'=>['image','last'],'image_urls'=>['image','reference'],
            'video_urls'=>['video','reference'],'keyframe_urls'=>['image','keyframe'],'voices'=>['audio','reference']]as$key=>[$kind,$role])
            $fields[]=['field'=>$key,'kind'=>$kind,'role'=>$role,'required'=>false,'maximum'=>10];
        $this->mode=['code'=>'reference_to_video','native_mode'=>'reference_to_video','method'=>'omni_reference','first_last_frame'=>'支持','fields'=>$fields];
        $this->metadata=['params_schema'=>$schema,'upstream_metadata'=>['model_code'=>'offline-reference-prompt'],
            'material_capabilities'=>['modes'=>[$this->mode]]];
        $catalog=new \ReflectionProperty(Native::class,'catalog');$this->previous=$catalog->getValue();
        $catalog->setValue(null,['models'=>['offline-reference-prompt'=>[
            'fingerprint'=>Reviewed::digest([$schema,Materials::contract($this->metadata)]),
            'modes'=>['reference_to_video'=>['schema'=>$schema,'rules'=>[['kind'=>'kling4','check'=>'names','message'=>'素材名称无效'],['kind'=>'kling4','check'=>'prompt','message'=>'每个 @引用须匹配本次素材']]]]]]]);
    }

    protected function tearDown(): void{(new \ReflectionProperty(Native::class,'catalog'))->setValue(null,$this->previous);}
    private function asset(string $kind,string $url,string $slot,string $role=''): array
    {return ['type'=>$kind,'url'=>$url,'reference_slot_id'=>$slot,'role'=>$role?:'reference_'.$kind];}
    private function payload(array $request): array{return Request::payload(['market_metadata'=>$this->metadata],$request);}

    public function testOrdinaryImageAndVoiceAliasesCompileWithoutChangingSavedPromptOrSound(): void
    {
        $r=['prompt'=>'让@图片1 使用@音频1 说话。','generation_method'=>'omni_reference','keep_original_sound'=>true,
            'reference_assets'=>[$this->asset('image',self::IMAGE,'image'),$this->asset('audio',self::AUDIO,'audio')]];
        $before=$r;$p=$this->payload($r);
        self::assertSame('让@image_1 使用@音频1 说话。',$p['prompt']);self::assertSame($before,$r);
        self::assertTrue($p['keep_original_sound']);self::assertSame('音频1',$p['voices'][0]['name']);
        self::assertSame(self::AUDIO,$p['voices'][0]['audio_url']);
    }

    public function testSameFileInTwoFrameUsesMapsToSeparateNativeRoles(): void
    {
        $p=$this->payload(['prompt'=>'@图片1 过渡到@图片2','generation_method'=>'start_end','reference_assets'=>[
            $this->asset('image',self::IMAGE,'first','first_frame_image'),$this->asset('image',self::IMAGE,'last','last_frame_image')]]);
        self::assertSame('@first_frame 过渡到@last_frame',$p['prompt']);
        self::assertSame(self::IMAGE,$p['first_frame_url']);self::assertSame(self::IMAGE,$p['last_frame_url']);
    }

    public function testTypedOrdinalIncludesFrameButNativeReferenceOrdinalDoesNot(): void
    {
        $p=$this->payload(['prompt'=>'@图片1 看向@图片2','generation_method'=>'image_to_video','reference_assets'=>[
            $this->asset('image',self::IMAGE,'first','first_frame_image'),$this->asset('image','https://fixtures.invalid/other.png','reference')]]);
        self::assertSame('@first_frame 看向@image_1',$p['prompt']);
    }

    public function testSameAudioFileInIndependentUsesKeepsBothAliasesAndSlots(): void
    {
        $p=$this->payload(['prompt'=>'@图片1 配合@音频1 和@音频2','generation_method'=>'omni_reference','reference_assets'=>[
            $this->asset('audio',self::AUDIO,'one'),$this->asset('image',self::IMAGE,'image'),$this->asset('audio',self::AUDIO,'two')]]);
        self::assertSame('@image_1 配合@音频1 和@音频2',$p['prompt']);
        self::assertSame(['音频1','音频2'],array_column($p['voices'],'name'));self::assertCount(2,$p['voices']);
    }

    /** @dataProvider unknownReferences */
    public function testUnknownOrLongerReferenceNeverBindsToTheFirstUpload(string $prompt): void
    {
        $this->expectExceptionMessage('每个 @引用须匹配');
        $this->payload(['prompt'=>$prompt,'generation_method'=>'omni_reference','reference_assets'=>[$this->asset('image',self::IMAGE,'image')]]);
    }
    public function unknownReferences(): array{return [['@图片10'],['@图片1suffix'],['@未上传角色'],['@音频1']];}

    public function testNativeAuthoredNamesAndFrozenReplayStayExact(): void
    {
        $r=['prompt'=>'@图片1 与@image_1','generation_method'=>'omni_reference','reference_assets'=>[$this->asset('image',self::IMAGE,'image'),$this->asset('audio',self::AUDIO,'audio')],
            'provider_params'=>['voices'=>[['name'=>'图片1','audio_url'=>self::AUDIO]]]];
        $p=$this->payload($r);self::assertSame($r['prompt'],$p['prompt']);
        self::assertSame($p,Request::payload(json_decode(json_encode(['market_metadata'=>$this->metadata]),true),json_decode(json_encode($r),true)));
    }

    public function testAmbiguousNativeUseStaysUnresolvedUntilExplicitAssignment(): void
    {
        $mode=$this->mode;$mode['fields']=[['field'=>'image_urls','kind'=>'image','role'=>'reference'],['field'=>'keyframe_urls','kind'=>'image','role'=>'reference']];
        $r=['reference_assets'=>[$this->asset('image',self::IMAGE,'image')]];
        $p=['prompt'=>'@图片1','image_urls'=>[self::IMAGE],'keyframe_urls'=>[self::IMAGE]];
        self::assertSame('@图片1',Materials::compileReferencePrompt($this->metadata,$mode,$r,$p)['prompt']);
        $r['reference_assets'][0]['field']='image_urls';
        self::assertSame('@image_1',Materials::compileReferencePrompt($this->metadata,$mode,$r,$p)['prompt']);
    }

    public function testOtherPromptContractsDoNotAdoptThisGrammar(): void
    {
        $catalog=new \ReflectionProperty(Native::class,'catalog');$value=$catalog->getValue();
        $value['models']['offline-reference-prompt']['modes']['reference_to_video']['rules']=[];$catalog->setValue(null,$value);
        $p=['prompt'=>'@图片1','image_urls'=>[self::IMAGE]];
        self::assertSame($p,Materials::compileReferencePrompt($this->metadata,$this->mode,['reference_assets'=>[$this->asset('image',self::IMAGE,'image')]],$p));
    }
}
