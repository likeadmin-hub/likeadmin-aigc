<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationMaterials as Materials;
use app\common\service\power\MarketGenerationMeter as Meter;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/MarketMixedMaterialSelectorsTest.php';

class MarketH3MixedMaterialsTest extends TestCase
{
    public function testReferenceOnlyChannelsAdvertiseOnlyTheirContentRoles(): void
    {
        $metadata = [
            'developer_doc_content' => '不支持首帧、尾帧和参考视频',
            'params_schema' => ['properties' => ['content' => ['type' => 'array', 'items' => [
                'type' => 'object', 'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['text', 'image_url', 'audio_url']],
                    'role' => ['type' => 'string', 'enum' => ['reference_image', 'reference_audio']],
                ],
            ]]]],
        ];
        $generationModes = new \ReflectionMethod(Videos::class, 'generationModes');
        $assetTypes = new \ReflectionMethod(Videos::class, 'supportedAssetTypes');
        $modes = ['text_to_video', 'image_reference', 'omni_reference', 'audio_reference'];
        foreach (['minimax-h3-1080p-c1', 'minimax-h3-768-ms'] as $code) {
            $product = ['resource_type' => 'model', 'upstream_model_code' => $code];
            self::assertSame($modes, $generationModes->invoke(null, $product, $metadata));
            self::assertSame(['image', 'audio'], $assetTypes->invoke(null, $product, $metadata));
        }
        $metadata['params_schema']['properties']['content']['items']['properties']['role']['enum'][] = 'first_frame';
        self::assertContains('image_to_video', $generationModes->invoke(null, $product, $metadata));
    }

    public static function bundle(): array
    {
        $b=MarketMixedMaterialSelectorsTest::bundle();$e=&$b['entries'][0];$e['generation_protocol']='h3_content_v1';
        $e['model_code']=$e['public_model_code']='declared-h3-fixture';
        $branches=[['type'=>'object','properties'=>['type'=>['type'=>'string','const'=>'text'],'text'=>['type'=>'string']],
            'required'=>['type','text'],'additionalProperties'=>false]];
        foreach(['image','video','audio']as$kind){
            $branches[]=['type'=>'object','properties'=>['type'=>['type'=>'string','const'=>$kind.'_url'],
                'role'=>['type'=>'string','enum'=>$kind==='image'?['reference_image','first_frame','last_frame']:['reference_'.$kind]],
                $kind.'_url'=>['type'=>'object','properties'=>['url'=>['type'=>'string']],'required'=>['url'],'additionalProperties'=>false]],
                'required'=>['type','role',$kind.'_url'],'additionalProperties'=>false];
        }
        $schema=['type'=>'object','properties'=>['model'=>['type'=>'string'],'duration'=>['type'=>'string','enum'=>['4','8']],
            'ratio'=>['type'=>'string','enum'=>['1:1']],'resolution'=>['type'=>'string','enum'=>['720P']],
            'content'=>['type'=>'array','items'=>['oneOf'=>$branches]]],'required'=>['duration','content']];
        $e['declaration']['params_schema']=$schema;
        foreach($e['declaration']['material_capabilities']['modes']as&$mode)foreach($mode['fields']as&$field){
            $kind=$field['kind'];$role=$field['role'];$tag=$role==='reference'?'reference_'.$kind:($role==='first'?'first_frame':'last_frame');
            $field['field']='content.*.'.$kind.'_url.url';$field['item_match']=['type'=>$kind.'_url','role'=>$tag];
        }unset($mode,$field);
        foreach($e['modes']as&$mode)$mode['schema']=$schema;unset($mode);
        return $b;
    }
    public function testH3SharesMaterialClassificationSkuFlagsAndMetering(): void
    {
        $s=MarketMixedMaterialSelectorsTest::snapshot(self::bundle());$r=MarketMixedMaterialSelectorsTest::request();unset($r['audio'],$r['seed']);
        $native=Videos::prepareRequest($s,$r)->nativeInput;
        self::assertSame('4',$native['duration']);self::assertSame(4,count($native['content']));
        foreach(['image','video','audio']as$i=>$kind){self::assertSame($kind.'_url',$native['content'][$i+1]['type']);self::assertSame('reference_'.$kind,$native['content'][$i+1]['role']);}
        self::assertTrue(Materials::billingInputs($s['market_metadata'],$r)['_billing_video_input']);
        $meter=['version'=>1,'basis'=>'output_plus_video','reference_images'=>['free'=>0,'numerator'=>1,'denominator'=>2]];
        self::assertSame(['reference_images'=>1,'reference_video_seconds'=>2.5],Meter::context($meter,$native,static fn()=>2.5));
    }
    public function testH3KeepsTwoSlotsForIdenticalFirstAndLastFrameUrls(): void
    {
        $s=MarketMixedMaterialSelectorsTest::snapshot(self::bundle());$r=MarketMixedMaterialSelectorsTest::request();
        $r['material_mode']='frames';$r['reference_assets']=[];
        foreach(['first','last']as$role)$r['reference_assets'][]=['type'=>'image','role'=>$role.'_frame_image','url'=>'https://fixtures.invalid/frame'];
        $native=Videos::prepareRequest($s,$r)->nativeInput;
        self::assertSame(['text','image_url','image_url'],array_column($native['content'],'type'));
        self::assertSame(['first_frame','last_frame'],array_column(array_slice($native['content'],1),'role'));
    }
    public function testRawContentThatTheExistingEncoderCannotPreserveIsRejected(): void
    {
        $s=MarketMixedMaterialSelectorsTest::snapshot(self::bundle());$r=MarketMixedMaterialSelectorsTest::request();unset($r['reference_assets']);
        $r['provider_params']=['content'=>[['type'=>'video_url','role'=>'reference_video','video_url'=>['url'=>'https://fixtures.invalid/video']]]];
        $this->expectException(\InvalidArgumentException::class);Videos::prepareRequest($s,$r);
    }
    public function testH3CannotDeclareWanDocumentTags(): void
    {
        $b=self::bundle();$f=&$b['entries'][0]['declaration']['material_capabilities']['modes'][1]['fields'][0];$f['kind']='file';
        $this->expectException(\InvalidArgumentException::class);MarketMixedMaterialSelectorsTest::snapshot($b);
    }
}
