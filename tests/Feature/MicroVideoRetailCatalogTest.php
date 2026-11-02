<?php
namespace Tests\Feature;

use app\common\service\app\aigc_micro_video\MicroVideoRetailCatalog as Retail;
use app\common\service\power\MarketCatalogReadScope;
use app\common\service\power\MarketPriceRuleService;
use PHPUnit\Framework\TestCase;

final class MicroVideoRetailCatalogTest extends TestCase
{
    protected function setUp(): void { (new \think\App(dirname(__DIR__, 2).'/'))->initialize(); }
    private function models(): array
    {
        return [['id'=>'model:41','specs'=>array_map(static fn($duration)=>[
            'market_product_id'=>41,'market_sku_id'=>91,'quality'=>'2K','ratio'=>'9:16','duration'=>$duration,
            'user_charge_points'=>999, // A stale callable quote must never supply the display price.
        ],[6,10])]];
    }
    private function sku(float $base=10): array
    {
        return ['id'=>91,'product_id'=>41,'upstream_price'=>$base,'sale_points'=>999,'usage_unit'=>'output_second',
            'usage_unit_size'=>2,'source_payload'=>['meter_summary'=>'生成秒数 + 参考视频秒数 + 超过1的参考图片张数×1/2，总点数向上取整']];
    }
    public function testProjectionUsesEffectiveRetailRuleWithRealUnitsAndMeterConditions(): void
    {
        $sku=$this->sku();
        $resolved=MarketPriceRuleService::tenant(17,$sku,null,
            [['scope_type'=>'sku','scope_id'=>91,'mode'=>'manual','value'=>'37']],
            [['scope_type'=>'global','scope_id'=>0,'mode'=>'percent','value'=>'20']]);
        self::assertSame('12.000000',$resolved['platform']['price']);
        $out=Retail::projectVideoModels($this->models(),[41=>['available'=>[['sku'=>$sku,'tenant_price'=>(float)$resolved['tenant']['price']]]]]);
        $rate=$out[0]['reference_price']['rates'][0];
        self::assertSame(37.,$rate['points']);
        self::assertSame(2.,$rate['unit_size']);
        self::assertSame('output_second',$rate['unit']);
        self::assertSame([6,10],$rate['durations']);
        self::assertSame('2K',$rate['quality']);self::assertSame('9:16',$rate['ratio']);
        self::assertSame($sku['source_payload']['meter_summary'],$rate['meter_summary']);
        self::assertArrayNotHasKey('sale_points',$rate);self::assertArrayNotHasKey('upstream_price',$rate);
    }
    public function testExplicitFreeRetailSurvivesWithoutBecomingUnavailable(): void
    {
        MarketCatalogReadScope::run(function(): array {
            MarketCatalogReadScope::remember('market_price_product:41',static fn()=>['resource_type'=>'model']);
            $sku=$this->sku(0);
            $resolved=MarketPriceRuleService::tenant(17,$sku,null,[],[]);
            self::assertTrue($resolved['valid']);
            $out=Retail::projectVideoModels($this->models(),[41=>['available'=>[['sku'=>$sku,'tenant_price'=>(float)$resolved['tenant']['price']]]]]);
            self::assertSame('available',$out[0]['reference_price']['status']);
            self::assertSame(0.,$out[0]['reference_price']['rates'][0]['points']);
            return [];
        });
    }
    public function testUnavailableAndMissingRetailNeverFallBackToWholesaleOrStoredQuote(): void
    {
        foreach ([[],[41=>['available'=>[['sku'=>$this->sku()]]]],
            [41=>['available'=>[['sku'=>$this->sku(),'tenant_price'=>null]]]],
            [41=>['available'=>[['sku'=>$this->sku(),'tenant_price'=>-1]]]]] as $markets) {
            self::assertSame(['status'=>'unavailable','rates'=>[]],Retail::projectVideoModels($this->models(),$markets)[0]['reference_price']);
        }
    }
    public function testFreshReaderIgnoresRemovedSkuEvenWhenCallableCatalogRemainsCached(): void
    {
        $sku=$this->sku();$sku['id']=92;
        $out=Retail::projectVideoModels($this->models(),[41=>['available'=>[['sku'=>$sku,'tenant_price'=>1.]]]]);
        self::assertSame('unavailable',$out[0]['reference_price']['status']);
    }
    public function testLiveReaderUsesTheSameRetailRuleAsVideoQuoteWithoutAnyRequest(): void
    {
        $db=config('database.connections.mysql');
        if (($db['hostname']??'')!=='127.0.0.1'||!in_array($db['database']??'',['x_cn','z_cn'],true)) self::markTestSkipped('Local read-only catalog verification only');
        $products=\think\facade\Db::name('power_market_product')->where(['model_type'=>'video','status'=>1])->column('id');
        $markets=\app\common\service\power\MarketCatalogSkuReader::forProducts(1,$products);
        foreach ($markets as $product=>$group) foreach ($group['available']??[] as $market) {
            try { \app\common\service\power\MarketGenerationMeter::contract($market['sku']); } catch (\Throwable) { continue; }
            $sku=$market['sku'];
            $models=$this->models();$models[0]['specs'][0]['market_product_id']=$product;$models[0]['specs'][0]['market_sku_id']=$sku['id'];
            $models[0]['specs']=[$models[0]['specs'][0]];
            $out=Retail::videoModels(1,$models);
            $configured=\think\facade\Db::name('tenant_power_market_sku_price')->where(['tenant_id'=>1,'sku_id'=>$sku['id']])->find();
            self::assertSame(MarketPriceRuleService::tenantPrice(1,$sku,$configured?:null),$out[0]['reference_price']['rates'][0]['points']);
            self::assertSame(max(1.,(float)($sku['usage_unit_size']??1)),$out[0]['reference_price']['rates'][0]['unit_size']);
            return;
        }
        self::fail('Expected at least one local available video SKU');
    }

}
