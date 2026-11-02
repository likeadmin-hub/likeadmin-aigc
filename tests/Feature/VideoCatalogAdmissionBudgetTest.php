<?php
namespace Tests\Feature;

use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\service\power\MarketGenerationCatalogService;
use app\common\service\power\MarketVideoRuntimeService;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class VideoCatalogAdmissionBudgetTest extends TestCase
{
    /** @dataProvider resources */
    public function testCatalogueOnlyOffersSkusWhoseTokenBudgetCanBeReserved(string $resource): void
    {
        if (getenv('AIGC_VIDEO_PREPARATION_DB_TESTS') !== '1') self::markTestSkipped('Explicit local rollback fixtures required');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'), ['127.0.0.1', 'localhost']);
        self::assertContains(Db::connect()->getConfig('database'), ['x_cn', 'z_cn']);
        $key = 'catalogue-budget-'.bin2hex(random_bytes(8));
        Db::startTrans();
        try {
            $product = PowerMarketProduct::create(['product_code'=>$key,'name'=>'Offline budget admission fixture',
                'resource_type'=>$resource,'model_type'=>'video','source_code'=>'offline-fixture','upstream_resource_key'=>$key,
                'upstream_model_code'=>$key,'upstream_channel_code'=>'offline',
                'upstream_app_code'=>$resource === 'app_api' ? 'seedance' : '', 'upstream_api_code'=>$resource === 'app_api' ? 'create' : '',
                'source_payload'=>['category_code'=>'video','market_metadata'=>['category_code'=>'video',
                    'params_schema'=>['properties'=>['prompt'=>['type'=>'string'],'duration'=>['type'=>'integer','enum'=>[4]]]],
                    'supported_durations'=>[4]]], 'status'=>1,'update_time'=>time()]);
            $sku = PowerMarketSku::create(['product_id'=>$product['id'],'sku_key'=>$key,'title'=>'Offline unbounded tokens',
                'locked_params'=>['resolution'=>'720P'],'usage_unit'=>'token','usage_unit_size'=>1000,
                'upstream_price'=>1,'sale_points'=>1,'sale_status'=>1,'status'=>1,'source_payload'=>[]]);
            $option = static function () use ($resource, $product): array {
                foreach (MarketVideoRuntimeService::options(1, $resource) as $row) if ((int)$row['market_product_id'] === (int)$product['id']) return $row;
                self::fail('The runtime must retain an unavailable diagnostic option');
            };
            $unavailable = $option();
            self::assertFalse($unavailable['available']);
            self::assertSame([], $unavailable['skus']);
            self::assertStringContainsString('Token 费用上限', $unavailable['unavailable_reason']);
            $catalogue = MarketGenerationCatalogService::options(1, 'video');
            self::assertNotContains((int)$product['id'], array_map('intval', array_column($catalogue['channels'], 'market_product_id')));
            if ($resource === 'app_api') {
                $diagnostic = array_values(array_filter($catalogue['unavailable_applications'], static fn($row)=>(int)$row['market_product_id'] === (int)$product['id']));
                self::assertCount(1, $diagnostic);
                self::assertSame($unavailable['unavailable_reason'], $diagnostic[0]['unavailable_reason']);
            }
            $bounded = PowerMarketSku::create(['product_id'=>$product['id'],'sku_key'=>$key.'-bounded','title'=>'Offline bounded tokens',
                'locked_params'=>['resolution'=>'720P'],'usage_unit'=>'token','usage_unit_size'=>1000,
                'upstream_price'=>1,'sale_points'=>1,'sale_status'=>1,'status'=>1,
                'source_payload'=>['meter_contract'=>['version'=>2,'basis'=>'token','rounding'=>'ceil_2',
                    'maximum_quantity'=>1000,'usage_path'=>'data.usage.total_tokens']]]);
            $available = $option();
            self::assertNotSame(false, $available['available'] ?? true);
            self::assertSame([(int)$bounded['id']], array_map('intval', array_column($available['skus'], 'market_sku_id')));
            self::assertSame(1, (int)PowerMarketSku::find($sku['id'])['sale_status']);
            self::assertSame([], PowerMarketSku::find($sku['id'])['source_payload']);
        } finally { Db::rollback(); }
        self::assertSame(0, (int)Db::name('power_market_product')->where('product_code', $key)->count());
    }

    public static function resources(): array { return [['model'], ['app_api']]; }
}
