<?php
namespace Tests\Feature;

use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\service\power\MarketGenerationMaterials as Materials;
use app\common\service\power\MarketGenerationNativeContract as Native;
use app\common\service\power\MarketGenerationReviewedContract as Reviewed;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Synthetic legacy native catalogue; full rollback, no supplier or task submission. */
class MarketLegacyNativeDurationTest extends TestCase
{
    public function testLegacyNativeDurationValidationPrecedesNegotiation(): void
    {
        if(getenv('AIGC_VIDEO_PREPARATION_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);
        self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $key='legacy-duration-'.bin2hex(random_bytes(8));
        $schema=['type'=>'object','properties'=>['prompt'=>['type'=>'string'],'duration'=>['type'=>'integer','enum'=>[-1,4,8]],
            'resolution'=>['type'=>'string','enum'=>['720P']],'aspect_ratio'=>['type'=>'string','enum'=>['1:1']]],'required'=>['prompt','duration']];
        $metadata=['params_schema'=>$schema,'upstream_metadata'=>['model_code'=>$key],
            'material_capabilities'=>['modes'=>[['code'=>'text_to_video','method'=>'text_to_video','fields'=>[]]]]];
        $catalog=new \ReflectionProperty(Native::class,'catalog');$previous=$catalog->getValue();
        $catalog->setValue(null,['models'=>[$key=>['fingerprint'=>Reviewed::digest([$schema,Materials::contract($metadata)]),
            'modes'=>['text_to_video'=>['schema'=>$schema,'rules'=>[]]]]]]);
        Db::startTrans();
        try{
            self::assertNull(Reviewed::selected($metadata));self::assertNotEmpty(Native::modes($metadata));
            $product=PowerMarketProduct::create(['product_code'=>$key,'name'=>'Offline legacy duration fixture','resource_type'=>'model','model_type'=>'video',
                'source_code'=>'offline-fixture','upstream_resource_key'=>$key,'upstream_model_code'=>$key,'upstream_channel_code'=>'offline',
                'source_payload'=>['market_metadata'=>$metadata],'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            $sku=PowerMarketSku::create(['product_id'=>$product['id'],'sku_key'=>$key,'title'=>'Offline duration fixture',
                'locked_params'=>['resolution'=>'720P','generation_type'=>'text_to_video'],'usage_unit'=>'output_second','usage_unit_size'=>1,
                'upstream_price'=>1,'sale_points'=>1,'sale_status'=>1,'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            $request=['resource_type'=>'model','model_id'=>'market_video_model:'.$product['id'],'market_sku_id'=>$sku['id'],
                'prompt'=>'Offline fixture','quality'=>'720P','ratio'=>'1:1','generation_method'=>'text_to_video'];
            foreach([4.5,'4.5','invalid',-2]as$duration){
                try{Videos::quote(1,$request+['duration'=>$duration]);self::fail('Malformed or undeclared signed duration must be rejected before negotiation');}
                catch(\Exception$e){self::assertMatchesRegularExpression('/整数|智能时长/',$e->getMessage());}
            }
            $user=(int)Db::name('user')->where('tenant_id',1)->value('id');
            foreach([4.5,'4.5']as$duration){
                try{Videos::reserve(1,$user,'aigc_video','generate','aigc_video_task',$key.'-bad',$request+['duration'=>4],$request+['duration'=>$duration]);self::fail('Reservation must validate the original duration before replacing it');}
                catch(\Exception$e){self::assertStringContainsString('整数',$e->getMessage());}
            }
            foreach([3=>4,9=>8,-1=>-1]as$requested=>$expected){
                $normalized=Videos::normalizeDurationSelection(1,$request+['duration'=>$requested],$requested);
                self::assertSame($expected,$normalized['duration']);
            }
        }finally{Db::rollback();$catalog->setValue(null,$previous);}
        self::assertSame(0,PowerMarketProduct::where('product_code',$key)->count());
    }
}
