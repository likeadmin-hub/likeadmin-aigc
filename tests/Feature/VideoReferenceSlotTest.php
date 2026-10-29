<?php

namespace Tests\Feature;

use app\common\service\ai\ReferenceMentionPromptService as Mentions;
use app\common\service\app\aigc_video\AigcVideoReferenceAssetService as Assets;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\power\MarketGenerationMeter as Meter;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__.'/MarketH3MixedMaterialsTest.php';

class VideoReferenceSlotTest extends TestCase
{
    private function video(string $slot = ''): array
    {
        return ['type'=>'video','uri'=>'https://fixtures.invalid/repeated.mp4','role'=>'reference_video']
            + ($slot !== '' ? ['reference_slot_id'=>$slot] : []);
    }

    public function testExplicitOccurrencesSurviveNormalizationAliasesAndReplay(): void
    {
        $params=['reference_assets'=>[$this->video('upload:a'),$this->video('upload:b')],
            'video_urls'=>['https://fixtures.invalid/repeated.mp4']];
        $normalized=Assets::normalize($params);
        self::assertSame(['upload:a','upload:b'],array_column($normalized,'reference_slot_id'));
        self::assertSame($normalized,Assets::normalize(['reference_assets'=>$normalized]));
        self::assertSame($normalized,Assets::normalize(['reference_assets'=>json_decode(json_encode($normalized),true)]));
        $this->expectExceptionMessage('参考素材数量超出限制');
        Assets::normalize($params,1);
    }

    public function testLegacyUiIdsDoNotTurnGraphAliasesIntoExplicitUses(): void
    {
        self::assertCount(1,Assets::normalize(['reference_assets'=>[
            ['id'=>'graph']+$this->video(),['id'=>'composer']+$this->video(),
        ]]));
        self::assertCount(1,Assets::normalize(['reference_assets'=>[$this->video('a'),$this->video('a')]]));
    }

    public function testSameSlotCannotChangeItsFileWithinOneRequest(): void
    {
        $other=$this->video('a');$other['uri']='https://fixtures.invalid/other.mp4';
        $this->expectExceptionMessage('同一参考素材槽位不能绑定不同文件');
        Assets::normalize(['reference_assets'=>[$this->video('a'),$other]]);
    }

    public function testMalformedSlotIdentityCannotBecomeAProviderField(): void
    {
        $this->expectExceptionMessage('参考素材槽位标识格式不正确');
        Assets::normalize(['reference_assets'=>[['reference_slot_id'=>['invalid']]+$this->video()]]);
    }

    public function testH3KeepsTwoEncodedUsesAndMeasuresBothWithoutSendingLocalIds(): void
    {
        $snapshot=MarketMixedMaterialSelectorsTest::snapshot(MarketH3MixedMaterialsTest::bundle());
        $request=MarketMixedMaterialSelectorsTest::request();unset($request['audio'],$request['seed']);
        $request['reference_assets']=[$this->video('a'),$this->video('b')];
        $native=Videos::prepareRequest($snapshot,$request)->nativeInput;
        self::assertSame(['text','video_url','video_url'],array_column($native['content'],'type'));
        self::assertStringNotContainsString('reference_slot_id',json_encode($native));
        self::assertSame(12.0,Meter::context(['version'=>1,'basis'=>'output_plus_video'],$native,static fn()=>6.0)['reference_video_seconds']);
        $request['reference_assets'][]=$this->video('c');
        $this->expectException(\InvalidArgumentException::class);
        Videos::prepareRequest($snapshot,$request);
    }

    public function testMentionOrdinalsKeepExplicitUsesButIgnoreLegacyAliases(): void
    {
        $a=['id'=>'upload:a','url'=>'https://fixtures.invalid/repeated.mp4']+$this->video('upload:a');
        $b=['id'=>'upload:b','url'=>'https://fixtures.invalid/repeated.mp4']+$this->video('upload:b');
        $compiled=Mentions::compile(['prompt'=>'@Second then @First','reference_assets'=>[$a,$b],
            'video_urls'=>[$a['url']], 'selected_mentions'=>[['id'=>'upload:b','name'=>'Second'],['id'=>'upload:a','name'=>'First']]]);
        self::assertStringStartsWith('@视频2 then @视频1',$compiled['prompt']);
        self::assertCount(2,$compiled['reference_mentions']);
    }

    public function testCanvasKeepsExplicitOccurrencesAndOwnedLegacyAlias(): void
    {
        $method=new ReflectionMethod(Canvas::class,'canonicalVideoReferenceAssets');$method->setAccessible(true);
        $a=['url'=>'https://fixtures.invalid/repeated.mp4']+$this->video('a');
        $b=['url'=>'https://fixtures.invalid/repeated.mp4']+$this->video('b');
        $owned=['asset_id'=>61,'uri'=>'uploads/owned.mp4','url'=>$a['url'],'type'=>'video','role'=>'reference_video'];
        foreach ([[$a,$b,$owned],[$owned,$a,$b]] as $input) {
            $result=$method->invoke(null,$input);
            self::assertSame(['a','b'],array_column($result,'reference_slot_id'));
            self::assertSame(61,$result[0]['asset_id']);
            self::assertCount(2,Assets::normalize(['reference_assets'=>$result]));
        }
        $quote=new ReflectionMethod(Canvas::class,'quoteInput');$quote->setAccessible(true);
        $one=$quote->invoke(null,'node',['reference_assets'=>[$owned+['reference_slot_id'=>'a']]]);
        $two=$quote->invoke(null,'node',['reference_assets'=>[$owned+['reference_slot_id'=>'b']]]);
        self::assertNotSame($one,$two);
    }
}
