<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationAdapterRegistry as Registry;
use app\common\service\power\MarketGenerationNativeContract;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use app\common\service\power\PowerMarketService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MarketGenerationProtocolDeclarationTest extends TestCase
{
    /** @dataProvider modelFamilies */
    public function testNewCompatibleModelReusesRegisteredCodecFromDeclaration(string $type, string $model, string $channel, string $protocol, string $quality, int $duration): void
    {
        $fixture=json_decode(file_get_contents(dirname(__DIR__).'/fixtures/canvas-generation-market-contracts.json'),true,512,JSON_THROW_ON_ERROR);
        $metadata=null;
        foreach($fixture['contracts'] as $c)if($c['resource_type']==='model'&&$c['model_code']===$model&&$c['channel_code']===$channel){$metadata=$c;break;}
        self::assertNotNull($metadata);
        // These synthetic capability declarations are independent of choosing
        // a serializer. The old Qwen public sample contains no material bound.
        if($type==='image')$metadata+=['supports_reference_images'=>true,'max_reference_images'=>2];
        $snapshot=['resource_type'=>'model','model_code'=>$model,'channel_code'=>$channel,'locked_params'=>[],
            'market_metadata'=>$metadata,'params_schema'=>$metadata['params_schema']];
        self::assertSame($protocol,Registry::modelProtocol($type,$snapshot));
        $request=['prompt'=>'Offline fixture','quantity'=>1,'ratio'=>'9:16','quality'=>$quality,'duration'=>$duration,
            'reference_images'=>['https://fixtures.invalid/first.png']];
        if($type==='video')$request['reference_assets']=[['type'=>'image','role'=>'first_frame_image','url'=>'https://fixtures.invalid/first.png']];
        $prepare=$type==='image'?static fn($s)=>Images::prepareRequest($s,$request)->nativeInput:static fn($s)=>Videos::prepareRequest($s,$request)->nativeInput;
        $expected=$prepare($snapshot);
        $snapshot['model_code']='compatible-fixture-next';$snapshot['channel_code']='fixture-channel';
        $snapshot['market_metadata']['generation_protocol']=$protocol;
        $schema=&$snapshot['market_metadata']['params_schema'];
        $properties=&$schema;
        if(isset($schema['properties']))$properties=&$schema['properties'];
        if(isset($properties['model']))$properties['model']=array_replace($properties['model'],['enum'=>['compatible-fixture-next'],'options'=>['compatible-fixture-next'],'default'=>'compatible-fixture-next']);
        $snapshot['params_schema']=$schema;
        $actual=$prepare($snapshot);
        self::assertSame('compatible-fixture-next',$actual['model']);
        foreach(['model','channel'] as $key){unset($expected[$key],$actual[$key]);}
        self::assertSame($expected,$actual);
        self::assertSame([],MarketGenerationNativeContract::modes($snapshot['market_metadata']),'A serializer declaration does not create a native contract');
    }

    public static function modelFamilies(): array
    {
        return [
            ['image','nano-banana-2','Google','flat_image_v1','1k',0],
            ['image','gpt-image-2.5','OpenAIYI','flat_image_v1','1k',0],
            ['image','qwen-image-3.0','dashscope_compatible','qwen_image_v1','1k',0],
            ['video','h3-video','minimax','h3_content_v1','768P',5],
            ['video','wan3.0-video','dashscope_compatible','wan_video_media_v1','720P',5],
            ['video','q4-preview-video','q4_video','q4_video_v1','720p',5],
            ['video','veo3.1-fast','xAIQ','veo_video_v1','720p',8],
        ];
    }

    /** @dataProvider invalidProtocols */
    public function testUnknownOrWrongTypeProtocolCannotFallBack(string $type, $protocol): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Registry::modelProtocol($type,['model_code'=>'grok-video','market_metadata'=>['generation_protocol'=>$protocol]]);
    }

    public static function invalidProtocols(): array
    {
        return [['image','h3_content_v1'],['video','qwen_image_v1'],['image','unknown_v9'],['image',null],['video',''],['image',['codec'=>'flat_image_v1']]];
    }

    public function testProtocolHintCannotBypassDeclaredMaterialsOrInventNativeContract(): void
    {
        $metadata=['generation_protocol'=>'flat_image_v1','material_capabilities'=>['modes'=>[['code'=>'reference','fields'=>[]]]]];
        self::assertSame('native_v1',Registry::modelProtocol('image',['market_metadata'=>$metadata]));
        $this->expectExceptionMessage('缺少素材合同');
        Registry::modelProtocol('image',['generation_protocol'=>'native_v1']);
    }

    public function testWanCompatibleVariantUsesDeclaredEditProtocolAndPreservesAllMaterials(): void
    {
        $snapshot=['resource_type'=>'app_api','app_code'=>'wan','locked_params'=>[
            'model'=>'compatible-wan-next','_generation_protocol'=>'wan_edit_v1']];
        $request=['prompt'=>'Offline fixture','reference_assets'=>[
            ['type'=>'video','role'=>'reference_video','url'=>'https://fixtures.invalid/input.mp4'],
            ['type'=>'image','role'=>'reference_image','url'=>'https://fixtures.invalid/look.png'],
        ]];
        $native=Videos::prepareRequest($snapshot,$request)->nativeInput;
        self::assertSame('compatible-wan-next',$native['model']);
        self::assertSame(['https://fixtures.invalid/input.mp4'],$native['video_urls']);
        self::assertSame(['https://fixtures.invalid/look.png'],$native['image_urls']);
        self::assertArrayNotHasKey('_generation_protocol',$native);
        $this->expectExceptionMessage('不属于当前应用');
        Registry::applicationProtocol('nano_banana',['generation_protocol'=>'wan_edit_v1']);
    }

    public function testAppSyncCarriesProtocolWithItsOwnApiIdentity(): void
    {
        $metadata=(new ReflectionMethod(PowerMarketService::class,'appApiMetadata'))->invoke(null,
            ['code'=>'wan'],['code'=>'create','params_schema'=>['prompt'=>['type'=>'string']],'generation_protocol'=>'wan_edit_v1']);
        self::assertSame('wan_edit_v1',$metadata['generation_protocol']);
        self::assertSame('create',$metadata['api_code']);
        $merged=(new ReflectionMethod(PowerMarketService::class,'mergeAppApiRequestFields'))->invoke(null,
            ['id'=>22,'code'=>'create'],[['id'=>22,'code'=>'create','generation_protocol'=>'wan_edit_v1','params_schema'=>['prompt'=>['type'=>'string']]]]);
        self::assertSame('wan_edit_v1',$merged['generation_protocol']);
    }

    public function testCompatibleQwenProtocolKeepsItsSingleImageAdmissionConstraint(): void
    {
        $this->expectExceptionMessage('仅支持生成 1 张图片');
        Images::prepareRequest(['model_code'=>'compatible-next','market_metadata'=>['generation_protocol'=>'qwen_image_v1']],
            ['prompt'=>'Offline fixture','quantity'=>2]);
    }

    public function testProtocolDeclarationsAlsoGovernSharedSkuEligibility(): void
    {
        foreach(['h3_content_v1'=>'isH3Product','wan_video_media_v1'=>'isWanThreeProduct'] as $protocol=>$method){
            $product=['resource_type'=>'model','upstream_model_code'=>'compatible-next','source_payload'=>['market_metadata'=>['generation_protocol'=>$protocol]]];
            self::assertTrue((new ReflectionMethod(Videos::class,$method))->invoke(null,$product));
        }
        $q4=['resource_type'=>'model','upstream_model_code'=>'compatible-next','source_payload'=>['market_metadata'=>['generation_protocol'=>'q4_video_v1']]];
        self::assertTrue(\app\common\service\power\Q4VideoContract::matches($q4));
        $locked=['model'=>'compatible-wan-next','_generation_protocol'=>'wan_edit_v1'];
        self::assertSame('video_edit',(new ReflectionMethod(Videos::class,'skuInputMode'))->invoke(null,$locked));
        $product=['resource_type'=>'app_api','upstream_app_code'=>'wan'];
        $admit=new ReflectionMethod(Videos::class,'skuSupportsInputMode');
        self::assertTrue($admit->invoke(null,'text_to_video','video_edit',$product,$locked));
        self::assertFalse($admit->invoke(null,'text_to_video','image_reference',$product,$locked));
        $this->expectExceptionMessage('at most four reference images');
        (new ReflectionMethod(Videos::class,'assertWanAppAssets'))->invoke(null,['product'=>$product,'sku'=>['locked_params'=>$locked]],
            ['image'=>array_fill(0,5,'https://fixtures.invalid/a.png'),'video'=>['https://fixtures.invalid/a.mp4'],'audio'=>[]]);
    }

    public function testInvalidProtocolDoesNotBreakOtherCatalogueClassifications(): void
    {
        $product=['resource_type'=>'model','upstream_model_code'=>'q4-preview-video','source_payload'=>['market_metadata'=>['generation_protocol'=>'unknown_v9']]];
        self::assertFalse(\app\common\service\power\Q4VideoContract::matches($product));
        foreach(['isH3Product','isWanThreeProduct'] as $method)self::assertFalse((new ReflectionMethod(Videos::class,$method))->invoke(null,$product));
        self::assertFalse((new ReflectionMethod(Videos::class,'skuSupportsInputMode'))->invoke(null,'text_to_video','video_edit',
            ['resource_type'=>'app_api','upstream_app_code'=>'wan'],['model'=>'fixture','_generation_protocol'=>'unknown_v9']));
        $this->expectException(\InvalidArgumentException::class);
        Registry::modelProtocol('video',$product);
    }
}
