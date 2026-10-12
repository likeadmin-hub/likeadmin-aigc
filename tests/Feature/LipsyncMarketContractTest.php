<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\power\MarketLipsyncAppRuntimeService as Runtime;
use app\common\service\power\MarketApplicationApiRuntimeService;
use app\common\service\app\aigc_digital_human\DigitalHumanMarketService;

class LipsyncMarketContractTest extends TestCase
{
    public function testMarketSkuOwnsModelAndVideoAudioFields(): void
    {
        foreach (['xiaojiayu1.0','xiaojiayu2.0','xiaojiayu3.0'] as $model) {
            $payload=Runtime::buildPayload(['locked_params'=>['model'=>$model]],[
                'model'=>'wrong','video_url'=>'https://example.test/avatar.mp4','audio_url'=>'https://example.test/voice.mp3']);
            self::assertSame($model,$payload['model']);
            self::assertSame('async_query',$payload['mode']);
            self::assertArrayNotHasKey('image_url',$payload);
        }
        self::assertSame(Runtime::class,MarketApplicationApiRuntimeService::adapterForSelection(['upstream_app_code'=>'lipsync']));
    }
    public function testMissingInputFailsBeforeReservation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Runtime::buildPayload(['locked_params'=>['model'=>'xiaojiayu1.0']],['video_url'=>'file:///tmp/private']);
    }
    public function testSharedRuntimeDispatchesChildPayloadAndIdentity(): void
    {
        $payload=(new \ReflectionMethod(Runtime::class,'payload'))->invoke(null,['locked_params'=>['model'=>'xiaojiayu2.0']],['video_url'=>'https://example.test/v.mp4','audio_url'=>'https://example.test/a.mp3'],'key');
        self::assertSame('xiaojiayu2.0',$payload['model']);
        self::assertSame('market_lipsync_app:4:5',(new \ReflectionMethod(Runtime::class,'selectionId'))->invoke(null,4,5));
    }
    public function testChannelUsesSkuNameAndPerSecondPriceWithoutLegacyDefaults(): void
    {
        $channel=DigitalHumanMarketService::channel(['value'=>'market_lipsync_app:4:5','label'=>'Market model',
            'locked_params'=>['model'=>'xiaojiayu2.0'],'tenant_unit_price'=>25,'usage_unit_size'=>5]);
        self::assertSame('Market model',$channel['name']);
        self::assertSame(5.0,$channel['tenant_unit_price']);
        self::assertSame('market_lipsync_app:4:5',$channel['code']);
        self::assertSame('adaptive',$channel['specs'][0]['ratio']);
    }
}
