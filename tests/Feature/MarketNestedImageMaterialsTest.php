<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationMaterialPath as Path;
use app\common\service\power\MarketGenerationContractReview as Review;
use app\common\service\power\MarketGenerationReviewedContract as Reviewed;
use app\common\service\power\MarketGenerationMaterials as Materials;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use PHPUnit\Framework\TestCase;

class MarketNestedImageMaterialsTest extends TestCase
{
    private const PATH='input.messages.*.content.*.image';
    private const IMAGE='https://fixtures.invalid/a.png';
    private static function images(int $count): array {return array_map(static fn($n)=>'https://fixtures.invalid/reference-'.$n.'.png',range(1,$count));}
    private function bundle(): array
    {
        $content=['anyOf'=>[
            ['type'=>'object','properties'=>['text'=>['type'=>'string']],'required'=>['text'],'additionalProperties'=>false],
            ['type'=>'object','properties'=>['image'=>['type'=>'string']],'required'=>['image'],'additionalProperties'=>false]]];
        $schema=['type'=>'object','properties'=>[
            'input'=>['type'=>'object','properties'=>['messages'=>['type'=>'array','minItems'=>1,'maxItems'=>1,'items'=>[
                'type'=>'object','properties'=>['role'=>['type'=>'string','const'=>'user'],
                    'content'=>['type'=>'array','minItems'=>1,'items'=>$content]],'required'=>['role','content']]]],'required'=>['messages']],
            'parameters'=>['type'=>'object','properties'=>['n'=>['type'=>'integer','const'=>1],'size'=>['type'=>'string'],
                'watermark'=>['type'=>'boolean'],'seed'=>['type'=>'integer','minimum'=>0]]]],'required'=>['input','parameters']];
        return ['version'=>1,'source'=>['kind'=>'gateway_validator','reference'=>'synthetic/nested-validator.json',
            'revision'=>'fixture-v1','reviewed_at'=>'2026-10-29T23:00:00+08:00','artifact_sha256'=>hash('sha256','synthetic source')],
            'entries'=>[['generation_type'=>'image','model_code'=>'qwen-image-3.0','channel_code'=>'dashscope_compatible',
                'public_model_code'=>'qwen-image-3.0','generation_protocol'=>'qwen_image_v1','revision'=>'fixture-v1',
                'binding_declaration'=>['params_schema'=>['input'=>['type'=>'object'],'parameters'=>['type'=>'object']],'material_capabilities'=>[]],
                'declaration'=>['params_schema'=>$schema,'material_capabilities'=>['modes'=>[
                    ['code'=>'text_to_image','view_key'=>'text','method'=>'text_to_image','fields'=>[]],
                    ['code'=>'image_to_image','view_key'=>'reference','method'=>'image_to_image','fields'=>[
                        ['field'=>self::PATH,'kind'=>'image','role'=>'reference','required'=>true,'maximum'=>3]]]]]],
                'modes'=>['text_to_image'=>['schema'=>$schema,'rules'=>[]],'image_to_image'=>['schema'=>$schema,'rules'=>[]]]]]];
    }
    private function snapshot(array $bundle): array
    {
        $contract=Review::reviewedCatalogue($bundle,'synthetic source')['contracts'][0];
        return ['resource_type'=>'model','model_code'=>$contract['model_code'],'channel_code'=>$contract['channel_code'],
            'locked_params'=>[],'market_metadata'=>$bundle['entries'][0]['binding_declaration']+[
                'upstream_metadata'=>['model_code'=>$contract['public_model_code'],'channel_code'=>$contract['channel_code']],Reviewed::PIN=>$contract]];
    }
    public function testActualQwenEncoderPreservesMultipleReferencesAndTypedControls(): void
    {
        $s=$this->snapshot($this->bundle());$before=$s;
        $r=['prompt'=>'fixture','ratio'=>'1:1','reference_images'=>self::images(3),
            'provider_params'=>['parameters'=>['seed'=>0,'watermark'=>false,'size'=>'1024*1024']]];
        $a=Images::prepareRequest($s,$r);$b=Images::prepareRequest($s,$r);
        self::assertSame($a->nativeInput,$b->nativeInput);
        self::assertSame(3,Path::count($a->nativeInput,['field'=>self::PATH]));
        self::assertSame(0,$a->nativeInput['parameters']['seed']);self::assertFalse($a->nativeInput['parameters']['watermark']);
        self::assertSame('image_to_image',Materials::select($s['market_metadata'],$r)['native_mode']);
        self::assertSame($before,$s);
    }
    /** @dataProvider rejectedMaterials */
    public function testNestedMaterialLimitsModesAndRolesRemainEnforced(string $case): void
    {
        $s=$this->snapshot($this->bundle());$r=['prompt'=>'fixture','material_mode'=>'reference','reference_images'=>[self::IMAGE]];
        switch($case){case 'over_limit':$r['reference_images']=self::images(4);break;
            case 'wrong_mode':$r['material_mode']='text';break;case 'missing':$r['reference_images']=[];break;
            case 'local_limit':$r['reference_images']=self::images(2);$s['market_metadata']['_local_capability_overrides']=['max_reference_images'=>1];break;
            case 'wrong_role':unset($r['reference_images']);$r['reference_assets']=[['type'=>'image','role'=>'last_frame_image','url'=>self::IMAGE]];break;
            case 'sku_mode':$s['locked_params']['generation_type']='text_to_image';break;}
        $this->expectException(\InvalidArgumentException::class);Images::prepareRequest($s,$r);
    }
    public static function rejectedMaterials(): array {return array_map(static fn($s)=>[$s],['over_limit','wrong_mode','missing','local_limit','wrong_role','sku_mode']);}
    public function testPathCountKeepsDuplicateSlotsAndIgnoresPromptUrls(): void
    {
        $body=['prompt'=>self::IMAGE,'input'=>['messages'=>[['content'=>[['text'=>self::IMAGE],['image'=>self::IMAGE],['image'=>self::IMAGE]]]]]];
        self::assertSame(2,Path::count($body,['field'=>self::PATH]));
        self::assertSame([self::IMAGE,self::IMAGE],Path::values($body,self::PATH));
        self::assertSame([],Path::values(['input'=>['messages'=>['wrong'=>'object']]],self::PATH));
    }
    /** @dataProvider unsupportedPaths */
    public function testUnsupportedPathsCannotBeCompiledOrTreatedAsExpressions(string $path): void
    {
        $b=$this->bundle();$b['entries'][0]['declaration']['material_capabilities']['modes'][1]['fields'][0]['field']=$path;
        $this->expectException(\InvalidArgumentException::class);$this->snapshot($b);
    }
    public static function unsupportedPaths(): array {return [['input.messages[0].image'],['input.messages.*.unknown'],['input.**.image']];}
    public function testNestedVideoContractsRemainBlockedUntilMixedMediaCountingIsImplemented(): void
    {
        $b=$this->bundle();$e=&$b['entries'][0];$e['generation_type']='video';$e['generation_protocol']='wan_video_media_v1';
        $e['declaration']['material_capabilities']['modes'][1]['fields'][0]['kind']='video';
        $this->expectException(\InvalidArgumentException::class);Review::reviewedCatalogue($b,'synthetic source');
    }
}
