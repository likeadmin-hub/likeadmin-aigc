<?php
namespace Tests\Feature;

use app\common\service\power\{MarketGenerationMeter as Meter,MarketImageModelRuntimeService as Images,MarketVideoRuntimeService as Videos};
use app\common\model\power\{PowerMarketProduct,PowerMarketSku};
use app\common\model\ai\AiConsumptionLog;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Synthetic account-boundary examples; never submits, polls or stores provider output. */
class MarketBoundedMeterRoundingTest extends TestCase
{
    private static function declaration(string $basis,string $rounding='ceil_2',int $maximum=1000): array
    {
        return $basis==='token'
            ? ['version'=>2,'basis'=>'token','rounding'=>$rounding,'maximum_quantity'=>$maximum,'usage_path'=>'data.usage.total_tokens']
            : ['version'=>3,'basis'=>'megapixel','rounding'=>$rounding,'maximum_pixels_per_image'=>1000000,'maximum_images'=>2,'result_path'=>'data.image_urls','result_item_url'=>''];
    }
    /** @dataProvider boundaries */
    public function testExactAccountRoundingPreservesPositiveAndBoundaryRemainders(string $basis,string $rounding,float $price,float $quantity,float $unit,float $expected): void
    {
        $meter=Meter::contract(['usage_unit'=>$basis==='token'?'token':'business_unit','meter_contract'=>self::declaration($basis,$rounding)]);
        $snapshot=['billing_meter'=>$meter,'usage_unit_size'=>$unit];
        self::assertSame($expected,Meter::price($price,$quantity,$snapshot));
        self::assertSame($expected,Meter::price($price,$quantity,json_decode(json_encode($snapshot),true)));
    }
    public static function boundaries(): array
    {
        $rows=[];
        foreach(['token','megapixel']as$basis){
            $tiny=$basis==='token'?1.:.000001;
            $rows[$basis.'_tiny_cent']=[$basis,'ceil_2',.000001,$tiny,1000000000.,.01];
            $rows[$basis.'_tiny_whole']=[$basis,'ceil',.000001,$tiny,1000000000.,1.];
            $rows[$basis.'_above_cent']=[$basis,'ceil_2',1000000.000001,1.,100000000.,.02];
            $rows[$basis.'_above_whole']=[$basis,'ceil',100000000.000001,1.,100000000.,2.];
            $rows[$basis.'_exact_cent']=[$basis,'ceil_2',1000000.,1.,100000000.,.01];
            $rows[$basis.'_exact_whole']=[$basis,'ceil',100000000.,1.,100000000.,1.];
            $rows[$basis.'_free']=[$basis,'ceil_2',0.,1.,1000000000.,0.];
            $rows[$basis.'_fractional_exact']=[$basis,'ceil_2',.010001,1.,1.0001,.01];
            $rows[$basis.'_fractional_above']=[$basis,'ceil_2',.010002,1.,1.0001,.02];
            $rows[$basis.'_ordinary_cent']=[$basis,'ceil_2',1.23,1.,1.,1.23];
            $rows[$basis.'_ordinary_whole']=[$basis,'ceil',1.23,1.,1.,2.];
        }
        return $rows;
    }
    public function testLegacyUnmeteredAndVersionOneSnapshotsKeepTheirOriginalRounding(): void
    {
        self::assertSame(2.5,Meter::price(10.,250.,['usage_unit_size'=>1000]));
        $old=['billing_meter'=>['version'=>1,'basis'=>'image','round_up'=>true],'usage_unit_size'=>1000000000];
        self::assertSame(0.,Meter::price(.000001,1.,$old));
        self::assertSame(1.,Meter::price(1.23,1.,['billing_meter'=>['version'=>1,'basis'=>'call','round_up'=>true],'usage_unit_size'=>1000]));
    }
    /** @dataProvider ledgers */
    public function testLocalRollbackQuoteReserveAndActualSettlementRespectTheAccountBoundary(string $basis,float $price,float $unit,int $maximum,string $rounding,int $side,float $reserve,float $actual,bool $insufficient=false): void
    {
        if(getenv('AIGC_VIDEO_PREPARATION_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $user=(int)Db::name('user')->where('tenant_id',1)->where('user_money','>',100)->order('user_money','desc')->value('id');if(!$user)self::markTestSkipped('Funded local rollback fixture unavailable');
        $key='bounded-rounding-'.bin2hex(random_bytes(8));Db::startTrans();
        try{
            Db::name('tenant')->where('id',1)->lock(true)->find();Db::name('user')->where('id',$user)->lock(true)->find();$before=$this->balances($user);
            $video=$basis==='token';$type=$video?'video':'image';$method=$video?'text_to_video':'text_to_image';$service=$video?Videos::class:Images::class;
            $meta=['params_schema'=>['prompt'=>['type'=>'string','required'=>true]],'material_capabilities'=>['modes'=>[['code'=>$method,'method'=>$method,'fields'=>[]]]]];
            if($video)$meta+=['category_code'=>'video','supported_durations'=>[4],'supported_resolutions'=>['720P'],'supported_ratios'=>['1:1']];
            $p=PowerMarketProduct::create(['product_code'=>$key,'name'=>'Offline account rounding fixture','resource_type'=>'model','model_type'=>$type,'source_code'=>'offline-fixture','upstream_resource_key'=>$key,'upstream_model_code'=>'offline-rounding','upstream_channel_code'=>'offline','source_payload'=>['market_metadata'=>$meta],'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            $s=PowerMarketSku::create(['product_id'=>$p['id'],'sku_key'=>$key,'title'=>'Offline rounding fixture','locked_params'=>$video?['duration'=>4,'resolution'=>'720P']:[],
                'usage_unit'=>$video?'token':'business_unit','usage_unit_size'=>$unit,'upstream_price'=>$price,'sale_points'=>$price,'sale_status'=>1,'status'=>1,'source_payload'=>['meter_contract'=>self::declaration($basis,$rounding,$maximum)],'create_time'=>time(),'update_time'=>time()]);
            $request=['resource_type'=>'model','model_id'=>'market_'.$type.'_model:'.$p['id'],'market_sku_id'=>$s['id'],'prompt'=>'Synthetic rounding fixture','generation_method'=>$method,'quantity'=>1];
            if($video)$request+=['duration'=>4,'quality'=>'720P','ratio'=>'1:1'];
            $quote=$video?Videos::quote(1,$request):Images::quote(1,$request,1);self::assertSame($reserve,$quote['user_charge_points']);self::assertSame($reserve,$quote['tenant_cost_points']);
            $admission=$video?Videos::openPlatformAdmissionQuote(1,$request):Images::openPlatformAdmissionQuote(1,$request,1);self::assertSame($quote['market_sku_id'],$admission['quote']['market_sku_id']);self::assertSame($reserve,$admission['quote']['user_charge_points']);
            $create=static fn()=>$video?Videos::reserve(1,$user,'aigc_video','generate','aigc_video_task',$key,$request,$request):Images::reserve(1,$user,'generate',$key,$request,$request,1,'aigc_image','aigc_image_task');
            if($insufficient){
                Db::name('user')->where('id',$user)->update(['user_money'=>0]);$low=$this->balances($user);
                try{$create();self::fail('A positive sub-precision charge must reject a zero balance');}catch(\RuntimeException $e){self::assertNotSame('',$e->getMessage());}
                self::assertSame($low,$this->balances($user));self::assertSame(0,(int)AiConsumptionLog::where('product_id',$p['id'])->count());
            }else{
                $r=$create();$id=(int)$r['consumption_id'];self::assertSame(round($before['user']-$reserve,2),$this->balances($user)['user']);
                $stored=AiConsumptionLog::find($id)['price_snapshot'];self::assertSame((float)$unit,(float)$stored['usage_unit_size']);
                $settle=new \ReflectionMethod($service,'settle');
                $images=[['image_uri'=>'https://fixtures.invalid/measured.png','width'=>$side,'height'=>$side]];
                $finish=static function()use($video,$settle,$id,$images){
                    if($video)$settle->invoke(null,$id,'https://fixtures.invalid/result.mp4','','',['data'=>['usage'=>['total_tokens'=>1]]]);
                    else $settle->invoke(null,$id,$images,'','',[]);
                };
                $finish();self::assertSame('settled',AiConsumptionLog::find($id)['billing_status']);self::assertSame($actual,(float)AiConsumptionLog::find($id)['actual_user_price']);
                $done=$this->balances($user);self::assertSame(round($before['user']-$actual,2),$done['user']);self::assertSame(round($before['tenant']-$actual,2),$done['tenant']);
                $finish();$service::fail($id,'Late synthetic failure');self::assertSame($done,$this->balances($user));
            }
        }finally{Db::rollback();}
        self::assertSame($before,$this->balances($user));self::assertSame(0,(int)Db::name('power_market_product')->where('product_code',$key)->count());
    }
    public static function ledgers(): array
    {
        return ['pixel_tiny_reserve'=>['megapixel',.000001,1000000000.,1000,'ceil_2',1,.01,.01],
            'pixel_tiny_actual'=>['megapixel',.000001,1000000.,1000,'ceil_2',1,.01,.01],
            'token_tiny_reserve'=>['token',.000001,1000000000.,1,'ceil_2',1,.01,.01],
            'token_tiny_actual'=>['token',.000001,1000000000.,1000,'ceil_2',1,.01,.01],
            'pixel_above_cent'=>['megapixel',1000000.000001,100000000.,1000,'ceil_2',1000,.02,.02],
            'token_above_cent'=>['token',1000000.000001,100000000.,1000,'ceil_2',1,10.01,.02],
            'pixel_tiny_whole'=>['megapixel',.000001,1000000000.,1000,'ceil',1,1.,1.],
            'token_above_whole'=>['token',100000000.000001,100000000.,1,'ceil',1,2.,2.],
            'pixel_zero_balance'=>['megapixel',.000001,1000000000.,1000,'ceil_2',1,.01,.01,true],
            'token_zero_balance'=>['token',.000001,1000000000.,1,'ceil_2',1,.01,.01,true]];
    }
    private function balances(int $user): array{return ['tenant'=>(float)Db::name('tenant')->where('id',1)->value('point_balance'),'user'=>(float)Db::name('user')->where('id',$user)->value('user_money')];}
}
