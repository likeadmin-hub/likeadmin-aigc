<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationMaterials as Materials;
use app\common\service\power\MarketGenerationMedia as Media;
use app\common\service\power\MarketGenerationMediaInputs as Inputs;
use app\common\service\power\MarketGenerationMeter as Meter;
use app\common\service\power\MarketGenerationSchema as Schema;
use PHPUnit\Framework\TestCase;

class MarketStructuredUploadedMaterialsTest extends TestCase
{
    private const IMAGE='https://fixtures.invalid/source';
    private const AUDIO='https://fixtures.invalid/tone';
    private static function metadata(): array
    {
        $uri=['type'=>'string','format'=>'uri','pattern'=>'^https?://'];
        return ['params_schema'=>['type'=>'object','properties'=>['elements'=>['type'=>'array','maxItems'=>2,'items'=>['type'=>'object',
            'required'=>['name','element_input_urls'],'properties'=>['name'=>['type'=>'string'],'description'=>['type'=>'string'],
                'element_input_urls'=>['type'=>'array','minItems'=>1,'maxItems'=>2,'items'=>$uri],
                'element_input_audio_urls'=>['type'=>'array','maxItems'=>1,'items'=>$uri]]]]]],
            'material_capabilities'=>['modes'=>[['code'=>'reference_to_video','method'=>'omni_reference',
                'fields'=>[['field'=>'elements','kind'=>'subject','role'=>'reference','required'=>true,'maximum'=>2]]]]]];
    }
    private static function payload(): array
    {
        return ['elements'=>[['name'=>'Synthetic subject','element_input_urls'=>[self::IMAGE],'element_input_audio_urls'=>[self::AUDIO]]]];
    }
    private static function request(): array
    {
        return ['generation_method'=>'omni_reference','provider_params'=>self::payload(),'reference_assets'=>[
            ['type'=>'image','url'=>self::IMAGE,'role'=>'reference_image','field'=>'elements'],
            ['type'=>'audio','url'=>self::AUDIO,'role'=>'reference_audio','field'=>'elements']]];
    }
    public function testExplicitGroupedUploadsAreRetainedInDeclaredSlots(): void
    {
        $payload=Schema::normalize(self::metadata()['params_schema'],self::payload());
        Materials::validate(self::metadata(),self::request(),$payload);
        Materials::assertPreserved(self::request(),$payload);
        self::assertSame(self::payload(),$payload);
        $r=self::request();$r['reference_assets'][0]['type']='video';
        Materials::validate(self::metadata(),$r,$payload);self::assertCount(2,$r['reference_assets']);
    }
    /** @dataProvider invalidBindings */
    public function testGroupingCannotHideUnsupportedOrMissingUploads(string $case): void
    {
        $r=self::request();$p=self::payload();
        if($case==='unassigned')unset($r['reference_assets'][1]['field']);
        if($case==='foreign_field')$r['reference_assets'][1]['field']='unknown';
        if($case==='description_only'){$p['elements'][0]['description']=self::AUDIO;unset($p['elements'][0]['element_input_audio_urls']);}
        if($case==='wrong_kind'){$p['elements'][0]['element_input_urls']=[self::AUDIO];unset($p['elements'][0]['element_input_audio_urls']);$r['reference_assets']=[$r['reference_assets'][1]];}
        if($case==='duplicate_slot')$r['reference_assets'][]=$r['reference_assets'][0];
        $this->expectException(\InvalidArgumentException::class);
        Materials::validate(self::metadata(),$r,$p);Materials::assertPreserved($r,$p);
    }
    public static function invalidBindings(): array{return array_map(static fn($v)=>[$v],['unassigned','foreign_field','description_only','wrong_kind','duplicate_slot']);}
    public function testLocalOverridesAlsoCountNativeStructuredGroups(): void
    {
        $meta=self::metadata();$meta['_local_capability_overrides']=['max_reference_audios'=>0];
        // Typed audio slots need no network measurement to enforce the local count.
        $meta['params_schema']['properties']['elements']['items']['properties']['element_input_urls']['minItems']=0;
        $payload=['elements'=>[['name'=>'Synthetic subject','element_input_audio_urls'=>[self::AUDIO]]]];
        $this->expectException(\InvalidArgumentException::class);
        Materials::validate($meta,['provider_params'=>$payload,'reference_assets'=>[]],$payload);
    }
    public function testMixedVisualSlotsUseMeasuredKindsAndPreserveRepeatedSlots(): void
    {
        $native=['elements'=>[['element_input_urls'=>[self::IMAGE,'https://fixtures.invalid/clip','https://fixtures.invalid/clip'],
            'element_input_audio_urls'=>[self::AUDIO],'description'=>'https://fixtures.invalid/ignored']],
            'prompt'=>['element_input_urls'=>['https://fixtures.invalid/ignored']]];
        self::assertCount(3,Inputs::visualUrls($native));
        $visual=static fn($url)=>['kind'=>$url===self::IMAGE?'image':'video','duration'=>$url===self::IMAGE?0:6.0];
        $media=Media::inputs($native,$visual);self::assertCount(1,$media['image']);self::assertCount(2,$media['video']);self::assertCount(1,$media['audio']);
        $contract=Meter::contract(['meter_contract'=>['version'=>1,'basis'=>'output_plus_video','rounding'=>'ceil']]);
        $ctx=Meter::context($contract,$native,static function($input){self::assertCount(2,$input['video_urls']);return 12.0;},$visual);
        self::assertSame(['reference_images'=>1,'reference_video_seconds'=>12.0],$ctx);
        $old=['billing_meter'=>$contract,'usage_unit_size'=>1,'meter_context'=>['reference_images'=>0,'reference_video_seconds'=>0.0]];
        self::assertSame(10.0,Meter::price(2,5,$old));self::assertSame(34.0,Meter::price(2,5,array_replace($old,['meter_context'=>$ctx])));
        self::assertSame(10.0,Meter::price(2,5,$old));
    }
    public function testVisualGroupRejectsAudioOnlyFacts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Media::inputs(['elements'=>[['element_input_urls'=>[self::AUDIO]]]],static fn()=>['kind'=>'audio','duration'=>6]);
    }
}
