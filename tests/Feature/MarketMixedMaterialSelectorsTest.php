<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationContractReview as Review;
use app\common\service\power\MarketGenerationReviewedContract as Reviewed;
use app\common\service\power\MarketGenerationMaterialPath as Path;
use app\common\service\power\MarketGenerationMaterials as Materials;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use PHPUnit\Framework\TestCase;

class MarketMixedMaterialSelectorsTest extends TestCase
{
    private const PATH='input.media.*.url';
    private const SOURCE='Synthetic mixed-media validator fixture';
    public static function field(string $kind,string $role='reference',int $maximum=2): array
    {
        return ['field'=>self::PATH,'kind'=>$kind,'role'=>$role,'maximum'=>$maximum,
            'item_match'=>['type'=>$role==='reference'?'reference_'.$kind:($role==='first'?'first_frame':'last_frame')]];
    }
    public static function bundle(): array
    {
        $schema=['type'=>'object','properties'=>[
            'input'=>['type'=>'object','properties'=>['prompt'=>['type'=>'string'],'media'=>['type'=>'array','items'=>[
                'type'=>'object','additionalProperties'=>false,'properties'=>[
                    'type'=>['type'=>'string','enum'=>['reference_image','reference_video','reference_audio','first_frame','last_frame']],
                    'url'=>['type'=>'string']],'required'=>['type','url']]]]],
            'parameters'=>['type'=>'object','properties'=>['duration'=>['type'=>'integer','minimum'=>2,'maximum'=>8],
                'resolution'=>['type'=>'string','enum'=>['720P']], 'ratio'=>['type'=>'string','enum'=>['1:1']],
                'audio'=>['type'=>'boolean'],'seed'=>['type'=>'integer','minimum'=>0]]],'model'=>['type'=>'string']],
            'required'=>['input','parameters']];
        $modes=[['code'=>'text_to_video','view_key'=>'text','method'=>'text_to_video','fields'=>[]],
            ['code'=>'reference_to_video','view_key'=>'reference','method'=>'omni_reference','fields'=>[
                self::field('image'),self::field('video'),self::field('audio')]],
            ['code'=>'image_reference','view_key'=>'images','method'=>'image_reference','fields'=>[self::field('image')]],
            ['code'=>'image_to_video','view_key'=>'frames','method'=>'start_end','fields'=>[
                self::field('image','first',1)+['required'=>true],self::field('image','last',1)]]];
        $contracts=[];foreach($modes as$m)$contracts[$m['code']]=['schema'=>$schema,'rules'=>[]];
        return ['version'=>1,'source'=>['kind'=>'gateway_validator','reference'=>'synthetic/wan-validator.json','revision'=>'fixture-v1',
            'reviewed_at'=>'2026-10-29T23:00:00+08:00','artifact_sha256'=>hash('sha256',self::SOURCE)],
            'entries'=>[['generation_type'=>'video','model_code'=>'declared-wan-fixture','channel_code'=>'fixture-channel','public_model_code'=>'declared-wan-fixture',
                'generation_protocol'=>'wan_video_media_v1','revision'=>'fixture-v1',
                'binding_declaration'=>['params_schema'=>['input'=>['type'=>'object'],'parameters'=>['type'=>'object']],'material_capabilities'=>[]],
                'declaration'=>['params_schema'=>$schema,'material_capabilities'=>['modes'=>$modes]],'modes'=>$contracts]]];
    }
    public static function snapshot(?array $bundle=null): array
    {
        $b=$bundle??self::bundle();$c=Review::reviewedCatalogue($b,self::SOURCE)['contracts'][0];
        $meta=$b['entries'][0]['binding_declaration']+['upstream_metadata'=>['model_code'=>$c['public_model_code'],'channel_code'=>$c['channel_code']],Reviewed::PIN=>$c];
        return ['resource_type'=>'model','model_code'=>$c['model_code'],'channel_code'=>$c['channel_code'],'locked_params'=>[],
            'params_schema'=>$meta['params_schema'],'market_metadata'=>$meta];
    }
    public static function request(): array
    {
        $assets=[];foreach(['image','video','audio']as$kind)$assets[]=['type'=>$kind,'role'=>'reference_'.$kind,'url'=>'https://fixtures.invalid/'.$kind];
        return ['prompt'=>'fixture','material_mode'=>'reference','duration'=>4,'quality'=>'720P','ratio'=>'1:1',
            'audio'=>false,'seed'=>0,'reference_assets'=>$assets];
    }
    public function testActualWanEncoderCountsEachTypeAndRetainsFalseAndZero(): void
    {
        $s=self::snapshot();$r=self::request();$a=Videos::prepareRequest($s,$r);$b=Videos::prepareRequest($s,$r);
        self::assertSame($a->nativeInput,$b->nativeInput);
        foreach(['image','video','audio']as$kind)self::assertSame(1,Path::count($a->nativeInput,self::field($kind)));
        self::assertFalse($a->nativeInput['parameters']['audio']);self::assertSame(0,$a->nativeInput['parameters']['seed']);
        self::assertSame(['_billing_video_input'=>true,'_billing_image_input'=>false],Materials::billingInputs($s['market_metadata'],$r));
        $r['reference_assets']=[$r['reference_assets'][0]];
        self::assertFalse(Materials::billingInputs($s['market_metadata'],$r)['_billing_video_input']);
    }
    public function testRawNativeVideoIsSeenBySkuFiltering(): void
    {
        $s=self::snapshot();$r=self::request();unset($r['reference_assets']);
        $r['provider_params']=['input'=>['media'=>[['type'=>'reference_video','url'=>'https://fixtures.invalid/video']]]];
        self::assertTrue(Materials::billingInputs($s['market_metadata'],$r)['_billing_video_input']);
        self::assertSame(1,Path::count(Videos::prepareRequest($s,$r)->nativeInput,self::field('video')));
    }
    public function testSameUrlInFirstAndLastFrameRemainsTwoSeparateSlots(): void
    {
        $s=self::snapshot();$r=self::request();$r['material_mode']='frames';$r['reference_assets']=[];
        foreach(['first','last']as$role)$r['reference_assets'][]=['type'=>'image','role'=>$role.'_frame_image','url'=>'https://fixtures.invalid/frame'];
        $p=Videos::prepareRequest($s,$r)->nativeInput;
        self::assertSame(1,Path::count($p,self::field('image','first')));self::assertSame(1,Path::count($p,self::field('image','last')));
        self::assertTrue(Materials::billingInputs($s['market_metadata'],$r)['_billing_image_input']);
    }
    /** @dataProvider rejectedRequests */
    public function testMixedInputsCannotBypassLimitsRolesModesOrSkuLocks(string $case): void
    {
        $s=self::snapshot();$r=self::request();
        switch($case){
            case 'audio_in_image_mode':$r['material_mode']='images';break;
            case 'text_with_materials':$r['material_mode']='text';break;
            case 'too_many_videos':for($i=0;$i<3;$i++)$r['reference_assets'][]=['type'=>'video','role'=>'reference_video','url'=>'https://fixtures.invalid/v'.$i];break;
            case 'local_limit':$s['market_metadata']['_local_capability_overrides']=['max_reference_videos'=>0];break;
            case 'sku_mode':$s['locked_params']['generation_type']='text_to_video';break;
            case 'two_sources':$r['provider_params']=['input'=>['media'=>[['type'=>'reference_video','url'=>'https://fixtures.invalid/native']]]];break;
            case 'wrong_role_with_field':$r['reference_assets']=[['type'=>'image','role'=>'last_frame_image','field'=>self::PATH,'url'=>'https://fixtures.invalid/frame']];break;
        }
        $this->expectException(\InvalidArgumentException::class);Videos::prepareRequest($s,$r);
    }
    public static function rejectedRequests(): array {return array_map(static fn($s)=>[$s],['audio_in_image_mode','text_with_materials','too_many_videos','local_limit','sku_mode','two_sources','wrong_role_with_field']);}
    /** @dataProvider invalidSelectors */
    public function testSelectorsMustBindTheSchemaAndTheActualProtocol(string $case): void
    {
        $b=self::bundle();$field=&$b['entries'][0]['declaration']['material_capabilities']['modes'][1]['fields'][0];
        switch($case){
            case 'unknown_tag':$field['item_match']['type']='unknown';break;
            case 'wrong_kind':$field['item_match']['type']='reference_video';break;
            case 'expression':$field['item_match']=['type'=>['eval'=>'image']];break;
            case 'overlap':$b['entries'][0]['declaration']['material_capabilities']['modes'][1]['fields'][]=$field;break;
            case 'missing_domain':unset($b['entries'][0]['modes']['reference_to_video']['schema']['properties']['input']['properties']['media']['items']['properties']['type']['enum']);break;
        }
        $this->expectException(\InvalidArgumentException::class);self::snapshot($b);
    }
    public static function invalidSelectors(): array {return array_map(static fn($s)=>[$s],['unknown_tag','wrong_kind','expression','overlap','missing_domain']);}
    public function testUndeclaredTagCannotDisappearBehindASharedUrlPath(): void
    {
        $s=self::snapshot();$body=['input'=>['media'=>[['type'=>'reference_audio','url'=>'https://fixtures.invalid/a']]],'parameters'=>['duration'=>4]];
        $this->expectException(\InvalidArgumentException::class);
        Materials::validate($s['market_metadata'],['material_mode'=>'images'],$body);
    }
}
