<?php
namespace Tests\Feature;
use app\common\service\power\PowerMarketService as Market;
use app\common\model\power\PowerMarketProduct;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class MarketScalarTokenSyncTest extends TestCase
{
    /** @dataProvider emptyRates */
    public function testScalarTokenPricesDoNotRequireAnInputOutputRatePair(array $optional): void
    {
        $sku=['sku_key'=>'native-video-token','billing_mode'=>'usage','usage_unit'=>'token','usage_unit_size'=>1000000,'price'=>['points'=>7.5]]+$optional;
        $snapshot=(new \ReflectionMethod(Market::class,'skuSnapshot'))->invoke(null,$sku);
        self::assertSame($sku['price'],$snapshot['price']);
        self::assertSame(1000000.,$snapshot['usage_unit_size']);
        self::assertArrayNotHasKey('effective_rate_config',$snapshot);
    }
    public static function emptyRates(): array {return [[],['effective_rate_config'=>[]],['effective_rate_config'=>null]];}

    public function testNonemptyButIncompleteSplitRateDeclarationIsStillRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new \ReflectionMethod(Market::class,'skuSnapshot'))->invoke(null,['usage_unit'=>'token','usage_unit_size'=>1000,'price'=>['points'=>7.5],'effective_rate_config'=>['input'=>2]]);
    }

    public function testRealSkuImportPreservesScalarPricesAndStillRejectsUnpricedFileQa(): void
    {
        if(getenv('MARKET_PRICE_DB_TESTS')!=='local-existing')self::markTestSkipped('Local rollback fixture only');
        (new \think\App(dirname(__DIR__,2).'/'))->initialize();
        self::assertContains(config('database.connections.mysql.hostname'),['127.0.0.1','localhost']);
        self::assertContains(config('database.connections.mysql.database'),['x_cn','z_cn']);
        Db::startTrans();
        try{
            $product=PowerMarketProduct::create(['product_code'=>'scalar-token-fixture-'.bin2hex(random_bytes(8)),'name'=>'Offline scalar Token fixture','resource_type'=>'app_api','model_type'=>'video','source_code'=>'offline-fixture','upstream_resource_key'=>'fixture','status'=>1,'create_time'=>time(),'update_time'=>time()]);
            $id=(int)$product['id'];$save=new \ReflectionMethod(Market::class,'upsertSku');
            $base=['billing_mode'=>'usage','usage_unit'=>'token','usage_unit_size'=>1000000,'locked_params'=>[],'selectable_params'=>[]];
            foreach(self::emptyRates()as$i=>$optional){
                $sku=$base+$optional+['sku_key'=>'scalar-'.$i,'price'=>['points'=>7.5]];
                $save->invoke(null,$id,$sku,['type'=>'app_api','resource'=>['app_code'=>'seedance','api_code'=>'create']]);
                $row=Db::name('power_market_sku')->where(['product_id'=>$id,'sku_key'=>'scalar-'.$i])->find();
                self::assertSame(7.5,(float)$row['upstream_price']);self::assertArrayNotHasKey('effective_rate_config',json_decode($row['source_payload'],true));
            }
            foreach(['parse','chat']as$api)foreach(self::emptyRates()as$i=>$optional){
                $key='file-'.$api.'-'.$i;$sku=$base+$optional+['sku_key'=>$key,'price'=>['points'=>0]];
                try{$save->invoke(null,$id,$sku,['type'=>'app_api','resource'=>['app_code'=>'file_qa','api_code'=>$api]]);self::fail('Missing file QA split rates cannot become a free SKU');}
                catch(\InvalidArgumentException$e){self::assertStringContainsString('费率不完整',$e->getMessage());}
                self::assertSame(0,Db::name('power_market_sku')->where(['product_id'=>$id,'sku_key'=>$key])->count());
            }
            $save->invoke(null,$id,$base+['sku_key'=>'legacy-file-scalar','price'=>['points'=>7.5],'effective_rate_config'=>[]],['type'=>'app_api','resource'=>['app_code'=>'file_qa','api_code'=>'parse']]);
            self::assertSame(7.5,(float)Db::name('power_market_sku')->where(['product_id'=>$id,'sku_key'=>'legacy-file-scalar'])->value('upstream_price'));
        }finally{Db::rollback();}
    }
}
