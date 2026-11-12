<?php
namespace Tests\Feature;

use app\common\service\power\{MarketMotionSyncRuntimeService as Motion, MarketVideoRuntimeService as Video, MarketGenerationRequest};
use PHPUnit\Framework\TestCase;

final class MotionSyncRuntimeTest extends TestCase
{
    private function product(string $code): array
    {
        return ['resource_type'=>'model','model_type'=>'other','upstream_channel_code'=>'official',
            'source_payload'=>['market_metadata'=>['upstream_metadata'=>['model_code'=>$code]]]];
    }

    public function testAdmissionIsRestrictedToThisApplicationAndStableModelIdentity(): void
    {
        $allowed = new \ReflectionMethod(Video::class, 'productAllowed');
        $allowed->setAccessible(true);
        foreach (Motion::MODELS as $key) {
            $product = $this->product($key);
            self::assertTrue(Motion::supportsProduct($product));
            self::assertFalse($allowed->invoke(null, $product, 'model'));
            self::assertTrue($allowed->invoke(null, $product, 'model', Motion::APP));
            self::assertFalse($allowed->invoke(null, $product, 'model', 'aigc_product_video'));
            self::assertFalse(Motion::supportsProduct(array_replace($product, ['upstream_channel_code'=>'unverified'])));
        }
        $spoof = $this->product('unrelated-model');
        $spoof['name'] = 'Kling-3.0 motion-control';
        self::assertFalse(Motion::supportsProduct($spoof));
        self::assertFalse($allowed->invoke(null, $spoof, 'model', Motion::APP));
        self::assertTrue($allowed->invoke(null, ['resource_type'=>'model','model_type'=>'video'], 'model'));
        self::assertFalse($allowed->invoke(null, ['resource_type'=>'model','model_type'=>'video'], 'model', Motion::APP));
    }

    public function testNativeMotionRequestPreservesBothMaterialsAndOnlyDeclaredControls(): void
    {
        (new \think\App(dirname(__DIR__, 2).'/'))->initialize();
        $schema = ['mode'=>['type'=>'string','enum'=>['720p','1080p'],'required'=>true],
            'character_orientation'=>['type'=>'string','enum'=>['image','video'],'required'=>true],
            'prompt'=>['type'=>'string','maxLength'=>2500],
            'input_urls'=>['type'=>'array','items'=>['type'=>'string'],'minItems'=>1,'maxItems'=>1,'required'=>true],
            'video_urls'=>['type'=>'array','items'=>['type'=>'string'],'minItems'=>1,'maxItems'=>1,'required'=>true]];
        $meta = ['params_schema'=>$schema,'material_capabilities'=>['modes'=>[['code'=>'','label'=>'动作控制','fields'=>[
            ['field'=>'input_urls','kind'=>'image','role'=>'reference','required'=>true,'minimum'=>1,'maximum'=>1],
            ['field'=>'video_urls','kind'=>'video','role'=>'reference','required'=>true,'minimum'=>1,'maximum'=>1],
        ]]]]];
        $request = ['prompt'=>'街头舞蹈','provider_params'=>['character_orientation'=>'image'], 'duration'=>0,
            'reference_assets'=>[
                ['type'=>'image','url'=>'https://media.example/character.png','role'=>'reference_image'],
                ['type'=>'video','url'=>'https://media.example/motion.mp4','role'=>'reference_video'],
            ]];
        $payload = MarketGenerationRequest::payload(['market_metadata'=>$meta,'locked_params'=>['mode'=>'1080p']], $request);
        self::assertSame(['https://media.example/character.png'], $payload['input_urls']);
        self::assertSame(['https://media.example/motion.mp4'], $payload['video_urls']);
        self::assertSame('image', $payload['character_orientation']);
        self::assertSame('1080p', $payload['mode']);
        self::assertArrayNotHasKey('duration', $payload);
        self::assertArrayNotHasKey('keep_original_sound', $payload);
        $this->expectException(\InvalidArgumentException::class);
        array_pop($request['reference_assets']);
        MarketGenerationRequest::payload(['market_metadata'=>$meta,'locked_params'=>['mode'=>'1080p']], $request);
    }
}
