<?php
namespace Tests\Feature;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
final class MarketImageReservationMaterialsTest extends TestCase
{
    public function testReservationResolvesTheSameReferenceModeAsItsQuoteAndSubmission():void
    {
        if(getenv('IMAGE_RESERVATION_TEST')!=='local-existing')self::markTestSkipped('Explicit local rollback only; no provider submission');
        (new \think\App())->initialize();self::assertSame('127.0.0.1',config('database.connections.mysql.hostname'));
        $selection=['model_id'=>'market_image_model:262','resolution'=>'1k','ratio'=>'9:16'];
        $request=['prompt'=>'离线参考图规格验收','resolution'=>'1k','ratio'=>'9:16','reference_images'=>['https://example.com/reference.png']];
        $quote=Images::quote(1,array_replace($selection,$request));
        self::assertSame('image_to_image',$quote['market_snapshot']['locked_params']['generation_type']);
        Db::startTrans();try{
            $reserved=Images::reserve(1,3,'shot_image','offline-'.bin2hex(random_bytes(8)),$selection,$request);
            self::assertSame($quote['market_sku_id'],$reserved['market_snapshot']['sku_id']);
            self::assertSame('image_to_image',$reserved['market_snapshot']['locked_params']['generation_type']);
            self::assertNotEmpty(Images::prepareRequest($reserved['market_snapshot'],$request)->nativeInput);
        }finally{Db::rollback();}
    }
}
