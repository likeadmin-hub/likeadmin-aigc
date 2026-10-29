<?php
namespace Tests\Feature;

use app\common\service\power\MarketPriceRuleService as Rules;
use app\common\service\power\TenantPowerMarketService as Market;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class MarketPriceSaveConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('MARKET_PRICE_DB_TESTS') !== 'local-existing') self::markTestSkipped('Local rollback test only');
        (new \think\App())->initialize();
        Db::startTrans();
    }
    protected function tearDown(): void { if (getenv('MARKET_PRICE_DB_TESTS') === 'local-existing') Db::rollback(); }
    private function sku(): array
    {
        $sku = Db::name('power_market_sku')->alias('s')->join('power_market_product p','p.id=s.product_id')
            ->where(['p.status'=>1,'s.status'=>1,'s.sale_status'=>1])->field('s.*')->order('s.id')->find();
        self::assertNotEmpty($sku);
        return $sku;
    }
    public function testShelfOnlyIgnoresStalePricesAndPreservesRules(): void
    {
        $sku=$this->sku(); $id=(int)$sku['id']; $product=(int)$sku['product_id'];
        $price=(float)Rules::tenant(1,$sku)['tenant']['price']+7;
        Market::savePrices(1,$product,[['sku_id'=>$id,'sale_points'=>$price,'sale_status'=>1]]);
        $rules=Rules::rules(1);
        Market::savePrices(1,$product,[['sku_id'=>$id,'sale_points'=>0,'sale_status'=>0,'state_only'=>true]]);
        $row=Db::name('tenant_power_market_sku_price')->where(['tenant_id'=>1,'sku_id'=>$id])->find();
        self::assertEquals($price,$row['sale_points']);
        self::assertSame(0,(int)$row['sale_status']); self::assertSame($rules,Rules::rules(1));
    }
    public function testStalePriceRejectsWholeProductWithoutChangingAnyRule(): void
    {
        $sku=$this->sku(); $id=(int)$sku['id']; $product=(int)$sku['product_id'];
        $price=(float)Rules::tenant(1,$sku)['tenant']['price']+9;
        Market::savePrices(1,$product,[['sku_id'=>$id,'sale_points'=>$price,'sale_status'=>1]]);
        $before=Rules::rules(1);
        try {
            Market::savePrices(1,$product,[['sku_id'=>$id,'sale_points'=>$price+1,'expected_sale_points'=>$price-1,'sale_status'=>1]]);
            self::fail('Stale editor must not overwrite another save');
        } catch (\Exception $error) { self::assertStringContainsString('价格已更新',$error->getMessage()); }
        self::assertSame($before,Rules::rules(1));
    }
    public function testUnchangedPriceDoesNotReplaceInheritRuleOrOverwriteNewerValue(): void
    {
        $sku=$this->sku();$id=(int)$sku['id'];$product=(int)$sku['product_id'];
        $price=(float)Rules::tenant(1,$sku)['tenant']['price']+11;
        Market::savePrices(1,$product,[['sku_id'=>$id,'sale_points'=>$price,'sale_status'=>1]]);
        Market::savePrices(1,$product,[['sku_id'=>$id,'sale_points'=>0,'manual_override'=>false,'sale_status'=>0]]);
        self::assertEquals($price,Db::name('tenant_power_market_sku_price')->where(['tenant_id'=>1,'sku_id'=>$id])->value('sale_points'));
    }

    public function testPlatformStateOnlyAndStaleEditorHaveTheSameProtection(): void
    {
        $sku=$this->sku(); $id=(int)$sku['id'];$product=(int)$sku['product_id'];
        $price=(float)Rules::platform($sku)['price']+5;
        \app\common\service\power\PowerMarketService::savePrices($product,[['sku_id'=>$id,'sale_points'=>$price,'sale_status'=>1]]);
        $before=Rules::rules(0);
        \app\common\service\power\PowerMarketService::savePrices($product,[['sku_id'=>$id,'state_only'=>true,'sale_points'=>0,'sale_status'=>0]]);
        self::assertSame($before,Rules::rules(0));
        self::assertEquals($price,Db::name('power_market_sku')->where('id',$id)->value('sale_points'));
        $this->expectExceptionMessage('价格已更新');
        \app\common\service\power\PowerMarketService::savePrices($product,[['sku_id'=>$id,'sale_points'=>$price+2,'expected_sale_points'=>$price-1,'sale_status'=>1]]);
    }
}
