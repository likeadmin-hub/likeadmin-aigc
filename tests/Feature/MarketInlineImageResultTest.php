<?php
namespace Tests\Feature;

use app\common\service\ai\AiTaskResultUrlService as Urls;
use app\common\service\power\{MarketImageModelRuntimeService as Images,MarketNanoBananaAppRuntimeService as Nano,MarketGenerationMeter as Meter};
use app\common\service\app\aigc_image\AigcImageAssetService as Assets;
use PHPUnit\Framework\TestCase;

/** Standard inline image parsing and pre-upload rejection only; no valid storage writes. */
class MarketInlineImageResultTest extends TestCase
{
    public function testOnlyImageConsumersIncludeStandardInlineResults(): void
    {
        $uri='data:image/png;base64,YQ==';$payload=['data'=>['results'=>[['url'=>$uri],['image_url'=>$uri],['url'=>'https://fixtures.invalid/result.png']]],'prompt'=>$uri,'reference_images'=>[$uri]];
        self::assertSame(['https://fixtures.invalid/result.png'],Urls::collect($payload));self::assertSame([$uri,'https://fixtures.invalid/result.png'],Urls::collectImages($payload));
    }
    public function testStandardHttpStringListsWorkForAllMediaWithoutReadingInputs(): void
    {
        foreach(['image_urls','video_urls','audio_urls']as$key){
            $url='https://fixtures.invalid/result';$input=['data'=>[$key=>[$url,$url]],'reference_images'=>['https://fixtures.invalid/input'],'input'=>['images'=>['https://fixtures.invalid/input']]];
            self::assertSame([$url],Urls::collect($input));
        }
    }
    public function testUnsupportedOrUnboundedInlineFormatsDoNotBecomeImageResults(): void
    {
        foreach(['data:audio/wav;base64,YQ==','data:image/png;base64,%%%','data:image/png;base64,'.str_repeat('A',27962112),'blob:fixture']as$url)self::assertSame([],Urls::collectImages(['images'=>[$url]]));
        self::assertSame([],Urls::collectImages(['data'=>[['b64_json'=>'YQ==']],'asset_id'=>'unverified-id']));
    }
    public function testStandardInlineBytesCanBeMeasuredWithoutUpload(): void
    {
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/a9sAAAAASUVORK5CYII=');$uri='data:image/png;base64,'.base64_encode($png);
        self::assertSame([$uri],Urls::collectImages(['result'=>['images'=>[$uri]]]));self::assertSame(['width'=>1,'height'=>1],Assets::measureGeneratedImage($uri,1));
    }
    /** @dataProvider runtimes */
    public function testTokenInlineImagesReachByteValidationBeforeManagedStorage(string $runtime): void
    {
        $meter=Meter::tokenContract(['usage_unit'=>'token','usage_unit_size'=>1000,'source_payload'=>['meter_contract'=>['version'=>2,'basis'=>'token','rounding'=>'ceil_2','maximum_quantity'=>1000,'usage_path'=>'usage.total_tokens']]]);
        $this->expectExceptionMessage('不是可核验的图片内容');
        (new \ReflectionMethod($runtime,'images'))->invoke(null,['status'=>'completed','data'=>['images'=>['data:image/png;base64,'.base64_encode('invalid image bytes')]]],1,1,['billing_meter'=>$meter]);
    }
    public static function runtimes(): array{return [[Images::class],[Nano::class]];}
}
