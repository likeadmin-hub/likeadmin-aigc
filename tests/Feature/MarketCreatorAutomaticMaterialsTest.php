<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationCreatorMaterials as Creator;
use app\common\service\power\MarketGenerationMaterials as Materials;
use app\common\service\power\MarketGenerationNativeContract as Native;
use app\common\service\power\MarketGenerationReviewedContract as Reviewed;
use app\common\service\power\MarketGenerationRequest as Request;
use PHPUnit\Framework\TestCase;

class MarketCreatorAutomaticMaterialsTest extends TestCase
{
    private array $metadata;
    private array $mode;
    private $previous;

    protected function setUp(): void
    {
        $fields=[['field'=>'clips','kind'=>'video','required'=>false,'role'=>'reference'],['field'=>'voices','kind'=>'audio','required'=>false,'role'=>'reference']];
        $schema=['type'=>'object','properties'=>[
            'clips'=>['type'=>'array','items'=>['type'=>'object','required'=>['url','start','ends'],'properties'=>[
                'url'=>['type'=>'string','format'=>'uri'],'start'=>['type'=>'number','minimum'=>0,'description'=>'单位为秒'],
                'ends'=>['type'=>'number','minimum'=>0,'description'=>'单位为秒']]]],
            'voices'=>['type'=>'array','items'=>['type'=>'object','required'=>['name','audio_url'],'properties'=>[
                'name'=>['type'=>'string','pattern'=>'^[一-龥A-Za-z0-9_]{1,20}$'],'audio_url'=>['type'=>'string','format'=>'uri']]]]]];
        $this->mode=['code'=>'reference_to_video','native_mode'=>'reference_to_video','method'=>'omni_reference','fields'=>$fields];
        $this->metadata=['params_schema'=>$schema,'upstream_metadata'=>['model_code'=>'offline-creator-media'],
            'material_capabilities'=>['modes'=>[$this->mode]]];
        $catalog=new \ReflectionProperty(Native::class,'catalog');$this->previous=$catalog->getValue();
        $catalog->setValue(null,['models'=>['offline-creator-media'=>[
            'fingerprint'=>Reviewed::digest([$schema,Materials::contract($this->metadata)]),
            'modes'=>['reference_to_video'=>['schema'=>$schema,'rules'=>[['kind'=>'clip_range','field'=>'clips','start'=>'start','end'=>'ends','maximum'=>10]]]]]]]);
    }

    protected function tearDown(): void{(new \ReflectionProperty(Native::class,'catalog'))->setValue(null,$this->previous);}

    public function testWholeVideoUsesMeasuredSecondsAndStableAudioAliases(): void
    {
        $params=['clips'=>[['url'=>'https://fixtures.invalid/clip.mp4']],
            'voices'=>[['name'=>'音频1','audio_url'=>'https://fixtures.invalid/a.wav'],['audio_url'=>'https://fixtures.invalid/b.wav']]];
        $result=Creator::complete($this->metadata,$this->mode,$params,static fn($url,$kind)=>$kind==='video'?3.25:0);
        self::assertSame(['url'=>$params['clips'][0]['url'],'start'=>0.0,'ends'=>3.25],$result['clips'][0]);
        self::assertSame(['音频1','音频2'],array_column($result['voices'],'name'));
        self::assertArrayNotHasKey('start',$params['clips'][0]);
        self::assertSame('full_video',Materials::modes($this->metadata)[0]['fields'][0]['creator_automatic']);
    }

    public function testLongVideoIsRejectedInsteadOfSilentlyTruncated(): void
    {
        $this->expectExceptionMessage('不超过10秒');
        Creator::complete($this->metadata,$this->mode,['clips'=>[['url'=>'https://fixtures.invalid/long.mp4']]],static fn()=>10.01);
    }

    public function testExplicitHistoricalRangesAndNamesRemainExact(): void
    {
        $params=['clips'=>[['url'=>'https://fixtures.invalid/clip.mp4','start'=>2,'ends'=>4]],'voices'=>[['name'=>'旧声音','audio_url'=>'https://fixtures.invalid/a.wav']]];
        self::assertSame($params,Creator::complete($this->metadata,$this->mode,$params,static function(){throw new \RuntimeException('No remeasurement of explicit ranges');}));
        self::assertSame($params,Request::payload(['market_metadata'=>$this->metadata],['provider_params'=>$params]));
    }

    public function testPartialRangeStaysInvalidInsteadOfInventingTheMissingEndpoint(): void
    {
        $params=['clips'=>[['url'=>'https://fixtures.invalid/clip.mp4','start'=>0]]];
        self::assertSame($params,Creator::complete($this->metadata,$this->mode,$params,static fn()=>3));
        $this->expectExceptionMessage('clips[0].ends');
        Request::payload(['market_metadata'=>$this->metadata],['provider_params'=>$params]);
    }

    public function testChangedUnknownContractAndSpoofedPolicyCannotEnableAutomaticCompletion(): void
    {
        $metadata=$this->metadata;$metadata['params_schema']['properties']['clips']['items']['properties']['start']['description']='单位为毫秒';
        $metadata['material_capabilities']['modes'][0]['fields'][0]['creator_automatic']='full_video';
        self::assertSame([],Creator::policies($metadata,'reference_to_video',$this->mode['fields']));
        $params=['clips'=>[['url'=>'https://fixtures.invalid/clip.mp4']]];
        self::assertSame($params,Creator::complete($metadata,$this->mode,$params,static fn()=>3));
    }
}
