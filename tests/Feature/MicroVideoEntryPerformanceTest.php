<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_micro_video\MicroVideoFrameCatalog as F;
use app\common\service\app\aigc_micro_video\MicroVideoCatalog as C;
use app\common\service\power\MarketImageModelRuntimeService as R;
final class MicroVideoEntryPerformanceTest extends TestCase
{
    protected function setUp(): void
    {(new \think\App(dirname(__DIR__,2).'/'))->initialize();$db=config('database.connections.mysql');if($db['hostname']!=='127.0.0.1'||$db['database']!=='z_cn')self::markTestSkipped('Local read-only catalog verification');}
    public function testImageCatalogUsesBatchedReadsAndAdvertisedPricesStillMatchLiveQuotes(): void
    {
        $queries=0;\think\facade\Db::listen(function()use(&$queries){$queries++;});
        $models=F::models(1);$listingQueries=$queries;
        self::assertNotEmpty($models);self::assertLessThanOrEqual(12,$listingQueries,'Do not re-query product, SKU and tenant price for every ratio');
        foreach($models as $model)foreach($model['specs'] as $spec){$q=R::quote(1,['model_id'=>$model['id'],'market_sku_id'=>$spec['market_sku_id'],'quality'=>$spec['quality'],'ratio'=>$spec['ratio']],1);self::assertSame((int)$q['market_sku_id'],$spec['market_sku_id']);self::assertEqualsWithDelta((float)$q['user_charge_points'],$spec['user_charge_points'],0.00001);}
    }
    public function testConfiguredVideoSubsetKeepsFullSpecificationAndDoesNotExposeOtherModels(): void
    {
        $selected=C::models(1,false,'reference',['market_video_model:182']);
        self::assertSame(['market_video_model:182'],array_column($selected,'id'));
        $all=array_column(C::models(1,false,'reference'),null,'id');self::assertSame($all['market_video_model:182'],$selected[0]);
        self::assertSame([],C::models(1,false,'reference',[]));
    }
}
