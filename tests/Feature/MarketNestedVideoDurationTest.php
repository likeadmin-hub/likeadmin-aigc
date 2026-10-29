<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationNativeContract as NativeContract;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/MarketMixedMaterialSelectorsTest.php';

class MarketNestedVideoDurationTest extends TestCase
{
    public static function bundle(array $duration): array
    {
        $b=MarketMixedMaterialSelectorsTest::bundle();
        $b['entries'][0]['declaration']['params_schema']['properties']['parameters']['properties']['duration']=$duration;
        foreach($b['entries'][0]['modes']as&$mode)$mode['schema']['properties']['parameters']['properties']['duration']=$duration;
        return $b;
    }
    public function testNestedDurationIsReadFromTheExactModeContract(): void
    {
        $s=MarketMixedMaterialSelectorsTest::snapshot();$meta=$s['market_metadata'];
        self::assertSame(['type'=>'integer','minimum'=>2,'maximum'=>8],NativeContract::durationDefinition($meta,'reference_to_video'));
        self::assertSame(range(2,8),NativeContract::durationChoices($meta,'reference_to_video'));
        self::assertSame(4,Videos::prepareRequest($s,MarketMixedMaterialSelectorsTest::request())->nativeInput['parameters']['duration']);
    }
    public function testExplicitSmartDurationSurvivesTheExistingEncoder(): void
    {
        $s=MarketMixedMaterialSelectorsTest::snapshot(self::bundle(['type'=>'integer','enum'=>[-1,4,8]]));
        $r=MarketMixedMaterialSelectorsTest::request();$r['duration']=-1;
        self::assertSame(-1,Videos::prepareRequest($s,$r)->nativeInput['parameters']['duration']);
        $s['locked_params']['duration']=4;$r['duration']=4;
        self::assertSame(4,Videos::prepareRequest($s,$r)->nativeInput['parameters']['duration']);
    }
    public function testDescriptionCannotGrantASmartDuration(): void
    {
        $s=MarketMixedMaterialSelectorsTest::snapshot(self::bundle(['type'=>'integer','minimum'=>2,'maximum'=>8,'description'=>'-1表示智能时长']));
        self::assertSame(range(2,8),NativeContract::durationChoices($s['market_metadata'],'reference_to_video'));
        $r=MarketMixedMaterialSelectorsTest::request();$r['duration']=-1;
        $this->expectException(\InvalidArgumentException::class);Videos::prepareRequest($s,$r);
    }
    public function testFractionalBusinessDurationCannotBeTruncatedIntoAValidInteger(): void
    {
        $r=MarketMixedMaterialSelectorsTest::request();$r['duration']=4.5;
        $this->expectException(\InvalidArgumentException::class);Videos::prepareRequest(MarketMixedMaterialSelectorsTest::snapshot(),$r);
    }
    public function testModeDurationsRemainSeparateAndDoNotAdoptPartialPublicEnums(): void
    {
        $b=self::bundle(['type'=>'integer','enum'=>[4,8]]);
        $b['entries'][0]['modes']['text_to_video']['schema']['properties']['parameters']['properties']['duration']=['type'=>'integer','enum'=>[2,6]];
        $s=MarketMixedMaterialSelectorsTest::snapshot($b);
        self::assertSame([2,6],NativeContract::durationChoices($s['market_metadata'],'text_to_video'));
        self::assertSame([4,8],NativeContract::durationChoices($s['market_metadata'],'reference_to_video'));
    }
}
