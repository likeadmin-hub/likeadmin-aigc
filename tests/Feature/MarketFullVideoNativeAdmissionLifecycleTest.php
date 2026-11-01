<?php
namespace Tests\Feature;

use app\common\service\power\MarketVideoRuntimeService as Videos;
use app\common\service\open_platform\{OpenNativeMarketPricing as Native,OpenStore};
use app\common\model\power\{PowerMarketProduct,PowerMarketSku};
use app\common\model\ai\{AiAppTask,AiConsumptionLog};
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** An owned rollback-only catalogue quote; no provider, dispatch, storage or real fee activation. */
class MarketFullVideoNativeAdmissionLifecycleTest extends TestCase
{
    public function testActualNativeAdmissionQuotesOriginalContentAndRejectsOverageBeforeCreatingTasks(): void
    {
        if(getenv('AIGC_VIDEO_PREPARATION_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixtures only');
        (new \think\App())->initialize();self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);
        self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $fixture=json_decode(file_get_contents(dirname(__DIR__).'/fixtures/market-application-source-contracts-20261101.json'),true,512,JSON_THROW_ON_ERROR);
        foreach($fixture['entries']as$entry)if($entry['product']['upstream_app_code']==='full_video')break;
        $tag='native-full-'.bin2hex(random_bytes(8));Db::startTrans();
        try{
            $product=PowerMarketProduct::create(['product_code'=>$tag,'name'=>'Native Full owned fixture','resource_type'=>'app_api','model_type'=>'',
                'source_code'=>'owned-fixture','upstream_resource_key'=>$tag,'upstream_model_code'=>'','upstream_channel_code'=>'',
                'upstream_app_code'=>'full_video','upstream_api_code'=>'submit','source_payload'=>['resource'=>['category_code'=>'video','category_name'=>'视频生成'],'market_metadata'=>$entry['metadata']],
                'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            PowerMarketProduct::create(['product_code'=>$tag.'-query','name'=>'Native Full owned query dependency','resource_type'=>'app_api','model_type'=>'',
                'source_code'=>'owned-fixture','upstream_resource_key'=>$tag.'-query','upstream_model_code'=>'','upstream_channel_code'=>'',
                'upstream_app_code'=>'full_video','upstream_api_code'=>'query','source_payload'=>['resource'=>['category_code'=>'video','category_name'=>'视频生成']],
                'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            $sku=PowerMarketSku::create(['product_id'=>$product['id'],'sku_key'=>$tag,'title'=>'480P owned fixture',
                'locked_params'=>['resolution'=>'480p'],'usage_unit'=>'input_second','usage_unit_size'=>1,
                'upstream_price'=>1,'sale_points'=>1,'sale_status'=>1,'status'=>1,'source_payload'=>[],
                'create_time'=>time(),'update_time'=>time()]);
            $selection=['market_product_id'=>(int)$product['id'],'market_sku_id'=>(int)$sku['id'],'resource_type'=>'app_api','quality'=>'480p','duration'=>4,'quantity'=>1];
            self::assertSame('video',\app\common\service\power\PowerMarketService::appCategory($product->toArray())['category_code']);
            self::assertTrue(Videos::isSupportedAppProduct($product->toArray()));
            $native=['prompt'=>'Decoy top prompt','content'=>[['type'=>'text','text'=>'First'],['type'=>'text','text'=>'Second']],'duration'=>4,'resolution'=>'480P'];
            for($i=0;$i<5;$i++)$native['content'][]=['type'=>'image_url','role'=>'reference_image','image_url'=>['url'=>'https://fixtures.invalid/repeated.png']];
            $before=OpenStore::encode((object)$native);$tasks=AiAppTask::count();$consumptions=AiConsumptionLog::count();
            $capture=Videos::openPlatformFullVideoAdmissionQuote(1,$selection,$native);$snapshot=$capture['quote']['market_snapshot'];
            self::assertSame(4.0,$capture['quote']['quantity']);self::assertSame(5,$snapshot['full_video_native_projection']['context']['reference_images']);
            self::assertSame(4.0,Videos::openPlatformSettlementQuantity($snapshot,['duration'=>99]));
            Native::assertPreparedTransport(['native_media'=>['market_snapshot'=>$snapshot]],$native);
            self::assertSame($before,OpenStore::encode((object)$native));
            $native['content'][]=['type'=>'image_url','role'=>'reference_image','image_url'=>['url'=>'https://fixtures.invalid/repeated.png']];
            try{Videos::openPlatformFullVideoAdmissionQuote(1,$selection,$native);self::fail('A sixth native slot must not receive a base-only quote');}
            catch(\Exception$error){self::assertStringContainsString('附加计费尚待核验',$error->getMessage());}
            self::assertSame($tasks,AiAppTask::count());self::assertSame($consumptions,AiConsumptionLog::count());
        }finally{Db::rollback();}
        self::assertSame(0,PowerMarketProduct::whereIn('product_code',[$tag,$tag.'-query'])->count());
    }
}
