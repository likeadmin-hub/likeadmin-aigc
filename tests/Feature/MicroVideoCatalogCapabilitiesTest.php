<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_micro_video\MicroVideoCatalog as C;
final class MicroVideoCatalogCapabilitiesTest extends TestCase
{
    protected function setUp(): void{(new \think\App(dirname(__DIR__,2).'/'))->initialize();$db=config('database.connections.mysql');if($db['hostname']!=='127.0.0.1'||$db['database']!=='z_cn')self::markTestSkipped('Read-only local market verification');}
    public function testH3TextPricedSkuRetainsBothCallableReferenceAndStartEndSpecifications(): void
    {
        $model=C::model(1,'market_video_model:182');self::assertTrue($model['supports_first_last_frame']);foreach(['768P','2K'] as $quality){$reference=C::spec($model,$quality,'9:16',6);$frames=C::spec($model,$quality,'9:16',6,'start_end');self::assertSame('image_reference',$reference['generation_method']);self::assertSame('start_end',$frames['generation_method']);self::assertGreaterThan(0,C::quote(1,$reference,$model['id'])['user_charge_points']);self::assertGreaterThan(0,C::quote(1,$frames,$model['id'])['user_charge_points']);}
    }
    public function testVeoRetainsOnlyRatiosThatCanQuoteInBothModes(): void
    {
        $model=C::model(1,'market_video_model:156');self::assertSame(['16:9'],array_values(array_unique(array_column($model['specs'],'ratio'))));foreach($model['specs'] as $spec){self::assertGreaterThan(0,C::quote(1,$spec,$model['id'])['user_charge_points']);$end=C::spec($model,$spec['quality'],$spec['ratio'],$spec['duration'],'start_end');self::assertGreaterThan(0,C::quote(1,$end,$model['id'])['user_charge_points']);}
    }
    public function testActualUsageOnlyModelsRemainExcludedAndOtherCallableModelsRemainAvailable(): void
    {
        $ids=array_column(C::models(1),'id');self::assertContains('market_video_model:181',$ids);self::assertContains('market_video_app:full_video:183',$ids);self::assertContains('market_video_app:wan:31',$ids);self::assertNotContains('market_video_app:seedance:38',$ids);self::assertNotContains('market_video_model:157',$ids);self::assertContains('market_video_model:157',array_column(C::models(1,true),'id'));
    }
}
