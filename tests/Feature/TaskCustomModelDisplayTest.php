<?php
namespace Tests\Feature;

use app\common\model\power\{PowerMarketProduct,TenantPowerMarketProduct};
use app\common\service\ai\AiTaskRecordService;
use app\common\service\power\TenantPowerMarketService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use think\facade\Db;

class TaskCustomModelDisplayTest extends TestCase
{
    /** @dataProvider mediaKinds */
    public function testCustomNameMatchesSelectionWithoutRewritingAcceptedGenerationFacts(string $kind): void
    {
        if(getenv('AIGC_MODEL_DISPLAY_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);
        self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $key='display-'.bin2hex(random_bytes(8));$tenant=1999999431;
        Db::startTrans();
        try {
            $product=PowerMarketProduct::create(['product_code'=>$key,'name'=>'Upstream original name','resource_type'=>'model',
                'model_type'=>$kind,'source_code'=>'offline-fixture','upstream_resource_key'=>$key,'upstream_model_code'=>'upstream-original',
                'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            foreach([$tenant=>'REK 自定义模型',$tenant+1=>'另一租户的模型',$tenant+2=>''] as $owner=>$name) {
                TenantPowerMarketProduct::create(['tenant_id'=>$owner,'product_id'=>$product['id'],'name'=>$name,'icon'=>'','description'=>'',
                    'create_time'=>time(),'update_time'=>time()]);
            }
            $row=['tenant_id'=>$tenant,'user_id'=>1,'model_name'=>'Old task name','channel'=>'market_'.$kind.'_model:'.$product['id'],
                'model_json'=>['model_id'=>'market_'.$kind.'_model:'.$product['id'],'model_name'=>'Old snapshot name','seed'=>0,'reference_images'=>['original.png']],
                'pricing_snapshot'=>['quantity'=>2,'platform_price'=>99],'prompt'=>'original prompt','ratio'=>'9:16'];
            $frozen=$row;
            $selection=$product->toArray();TenantPowerMarketService::applyProductDisplays($tenant,$selection);
            self::assertSame($selection['name'],AiTaskRecordService::modelDisplayName($row,'aigc_'.$kind));
            self::assertSame('另一租户的模型',AiTaskRecordService::modelDisplayName(array_replace($row,['tenant_id'=>$tenant+1]),'aigc_'.$kind));
            self::assertSame('Old task name',AiTaskRecordService::modelDisplayName(array_replace($row,['tenant_id'=>$tenant+2]),'aigc_'.$kind));
            self::assertSame('Old task name',AiTaskRecordService::modelDisplayName(array_replace($row,['tenant_id'=>$tenant+3]),'aigc_'.$kind));
            $service=$kind==='video'?\app\common\service\app\aigc_video\AigcVideoService::class:\app\common\service\app\aigc_image\AigcImageService::class;
            (new ReflectionMethod($service,'appendTaskModelDisplay'))->invokeArgs(null,[&$row]);
            self::assertSame('REK 自定义模型',$row['model_name']);self::assertSame($row['model_name'],$row['display_model_name']);
            foreach(['channel','model_json','pricing_snapshot','prompt','ratio'] as $field)self::assertSame($frozen[$field],$row[$field]);
            $ordinary=array_replace($frozen,['tenant_id'=>$tenant+3]);
            (new ReflectionMethod($service,'appendTaskModelDisplay'))->invokeArgs(null,[&$ordinary]);
            self::assertSame('',$ordinary['display_model_name']);self::assertSame('Old task name',$ordinary['model_name']);
        } finally {Db::rollback();}
        self::assertSame(0,PowerMarketProduct::where('product_code',$key)->count());
    }

    public static function mediaKinds(): array { return [['image'],['video']]; }
}
