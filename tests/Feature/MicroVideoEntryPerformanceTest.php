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
    public function testImageCatalogUsesBatchedReadsAndPreservesLiveSkuPrices(): void
    {
        $queries=0;\think\facade\Db::listen(function()use(&$queries){$queries++;});
        $models=F::models(1);$listingQueries=$queries;
        self::assertNotEmpty($models);self::assertLessThanOrEqual(12,$listingQueries,'Do not re-query product, SKU and tenant price for every ratio');
        // Catalogue prices are advisory SKU data. Exact quotes additionally need
        // the authored prompt and material mode; omitting those can select a
        // different text/image SKU under the current admission contract.
        $options=array_column(R::options(1),null,'id');
        foreach($models as $model){$skus=array_column($options[$model['id']]['skus'],null,'market_sku_id');foreach($model['specs'] as $spec){
            self::assertArrayHasKey($spec['market_sku_id'],$skus);$sku=$skus[$spec['market_sku_id']];
            self::assertSame('reserved',$sku['settlement_mode']);
            self::assertSame($sku['quality'],$spec['quality']);
            self::assertContains($spec['ratio'],$sku['ratio_options']);
            self::assertEqualsWithDelta(round((float)$sku['tenant_unit_price'],4),$spec['user_charge_points'],0.00001);
        }}
    }
    public function testConfiguredVideoSubsetKeepsFullSpecificationAndDoesNotExposeOtherModels(): void
    {
        $selected=C::models(1,false,'reference',['market_video_model:182']);
        self::assertSame(['market_video_model:182'],array_column($selected,'id'));
        $all=array_column(C::models(1,false,'reference'),null,'id');self::assertSame($all['market_video_model:182'],$selected[0]);
        self::assertSame([],C::models(1,false,'reference',[]));
    }
}
