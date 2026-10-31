<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationMeter as Meter;
use app\common\service\power\MarketUsageSettlementService as Usage;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use app\common\service\power\MarketGenerationCompatibilityAudit as Audit;
use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\model\ai\AiConsumptionLog;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** SaaS contract-format fixtures, not an upstream Seedance capability claim. */
class MarketBoundedTokenBillingTest extends TestCase
{
    public static function declaration(): array
    {
        return ['version'=>2,'basis'=>'token','rounding'=>'half_up_6','maximum_quantity'=>1000,'usage_path'=>'data.usage.total_tokens'];
    }
    private static function sku(array $declaration): array
    {
        return ['usage_unit'=>'token','usage_unit_size'=>1000,'source_payload'=>['meter_contract'=>$declaration]];
    }
    public function testBoundAndReceiptPathSurviveFrozenJsonAndDoNotFollowLaterConfiguration(): void
    {
        $meter=Meter::tokenContract(self::sku(self::declaration()));
        $snapshot=['billing_meter'=>$meter,'usage_unit_size'=>1000];
        self::assertSame(10.,Meter::price(10.,1000.,$snapshot));
        $frozen=json_decode(json_encode($snapshot),true);
        Meter::tokenContract(self::sku(array_replace(self::declaration(),['maximum_quantity'=>2000])));
        self::assertSame(2.5,Meter::price(10.,Usage::boundedTokenUsage(['data'=>['usage'=>['total_tokens'=>250]]],$meter),$frozen));
        self::assertSame(1000,$frozen['billing_meter']['maximum_quantity']);
        self::assertSame(3.,Meter::price(10.,250.,['billing_meter'=>Meter::tokenContract(self::sku(array_replace(self::declaration(),['rounding'=>'ceil']))),'usage_unit_size'=>1000]));
        self::assertSame('recognized_upper_bound',Audit::sku('video','app_api',self::sku(self::declaration()))['meter_status']);
        self::assertSame('requires_submission_token_budget',Audit::sku('video','app_api',['usage_unit'=>'token'])['meter_status']);
    }
    /** @dataProvider invalidContracts */
    public function testUntrustedIncompleteOrWrongUnitDeclarationsDoNotOpenBilling(array $declaration, array $extra=[]): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Meter::tokenContract(array_replace(self::sku($declaration),$extra));
    }
    public static function invalidContracts(): array
    {
        $base=self::declaration();$rows=[];
        foreach([['version'=>1],['version'=>'2'],['maximum_quantity'=>0],['maximum_quantity'=>-1],['maximum_quantity'=>'1000'],['maximum_quantity'=>1000.5],['maximum_quantity'=>1000000000001],['basis'=>'megapixel'],['usage_path'=>'**.total_tokens'],['usage_path'=>'metadata.tokens'],['rounding'=>'unknown'],['browser_budget'=>1000]]as$bad)$rows[]=[array_replace($base,$bad)];
        $rows[]=[$base,['usage_unit'=>'per_call']];$rows[]=[$base,['usage_unit_size'=>0]];$rows[]=[$base,['usage_unit_size'=>INF]];
        return $rows;
    }
    /** @dataProvider absentReceipts */
    public function testMissingOrInvalidReceiptCannotUseAnUnrelatedNumber(array $receipt): void
    {
        self::assertSame(0.,Usage::boundedTokenUsage($receipt,Meter::tokenContract(self::sku(self::declaration()))));
    }
    public static function absentReceipts(): array
    {
        return array_merge([[[]],[['total_tokens'=>250]],[['data'=>['metadata'=>['total_tokens'=>250]]]]],
            array_map(static fn($value)=>[['data'=>['usage'=>['total_tokens'=>$value]]]], [0,-1,250.5,'1e2',INF,true]));
    }
    public function testOverBoundReceiptIsAnErrorInsteadOfASecondCharge(): void
    {
        $this->expectExceptionMessage('超过冻结合同上限');
        Usage::boundedTokenUsage(['data'=>['usage'=>['total_tokens'=>1001]]],Meter::tokenContract(self::sku(self::declaration())));
    }
    public function testLegacyTokenReceiptAndSnapshotSemanticsStaySeparate(): void
    {
        self::assertSame(250.,Usage::tokenUsage(['data'=>['usage'=>['total_tokens'=>250]]]));
        self::assertSame(2.5,Meter::price(10.,250.,['usage_unit_size'=>1000]));
        $this->expectExceptionMessage('Token 费用上限');Meter::tokenContract(['usage_unit'=>'token']);
    }
    /** @dataProvider lifecycleScenarios */
    public function testLocalRollbackReservationReceiptsRefundAndIdempotency(string $scenario): void
    {
        if(getenv('AIGC_VIDEO_PREPARATION_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();
        self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);
        self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $user=(int)Db::name('user')->where('tenant_id',1)->where('user_money','>',100)->order('user_money','desc')->value('id');
        if(!$user)self::markTestSkipped('Funded local rollback fixture unavailable');
        $key='bounded-token-'.bin2hex(random_bytes(8));Db::startTrans();
        try{
            Db::name('tenant')->where('id',1)->lock(true)->find();Db::name('user')->where('id',$user)->lock(true)->find();
            $before=$this->balances($user);
            $meta=['params_schema'=>['properties'=>['prompt'=>['type'=>'string'],'duration'=>['type'=>'integer','enum'=>[4]],'resolution'=>['type'=>'string','enum'=>['720P']],'aspect_ratio'=>['type'=>'string','enum'=>['1:1']]],'required'=>['prompt','duration']],
                'material_capabilities'=>['modes'=>[['code'=>'text_to_video','method'=>'text_to_video','fields'=>[]]]],
                'category_code'=>'video','supported_durations'=>[4],'supported_resolutions'=>['720P'],'supported_ratios'=>['1:1']];
            $p=PowerMarketProduct::create(['product_code'=>$key,'name'=>'Offline bounded Token fixture','resource_type'=>'model','model_type'=>'video','source_code'=>'offline-fixture','upstream_resource_key'=>$key,'upstream_model_code'=>'offline-video','upstream_channel_code'=>'offline','source_payload'=>['market_metadata'=>$meta],'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            $sku=PowerMarketSku::create(['product_id'=>$p['id'],'sku_key'=>$key,'title'=>'Offline fixture','locked_params'=>['duration'=>4,'resolution'=>'720P'],'usage_unit'=>'token','usage_unit_size'=>1000,'upstream_price'=>10,'sale_points'=>10,'sale_status'=>1,'status'=>1,'source_payload'=>['meter_contract'=>self::declaration()],'create_time'=>time(),'update_time'=>time()]);
            $request=['resource_type'=>'model','model_id'=>'market_video_model:'.$p['id'],'market_sku_id'=>$sku['id'],'prompt'=>'Synthetic bounded Token fixture','duration'=>4,'quality'=>'720P','ratio'=>'1:1','generation_method'=>'text_to_video','maximum_quantity'=>1,'token_budget'=>1];
            $quote=Videos::quote(1,$request);self::assertSame(1000.,$quote['quantity']);self::assertSame(10.,$quote['user_charge_points']);self::assertSame('actual_usage',$quote['settlement_mode']);
            if(in_array($scenario,['user_insufficient','tenant_insufficient'],true)){
                if($scenario==='user_insufficient')Db::name('user')->where('id',$user)->update(['user_money'=>0]);
                else Db::name('tenant')->where('id',1)->update(['point_balance'=>0]);
                $low=$this->balances($user);
                try{Videos::reserve(1,$user,'aigc_video','generate','aigc_video_task',$key,$request,$request);self::fail('Insufficient balance must reject before reservation');}catch(\RuntimeException$e){self::assertNotSame('',$e->getMessage());}
                self::assertSame($low,$this->balances($user));self::assertSame(0,(int)Db::name('ai_app_task')->where('idempotency_key',sha1('1|aigc_video|generate|aigc_video_task|'.$key.'|market_video'))->count());return;
            }
            try{Videos::reserve(1,$user,'aigc_video','generate','aigc_video_task',$key.'-override',$request,$request,['user_charge_points'=>1]);self::fail('Token billing cannot use fixed app-price overrides');}catch(\Exception$e){self::assertStringContainsString('按实际用量',$e->getMessage());}
            self::assertSame($before,$this->balances($user));
            $reserve=fn()=>Videos::reserve(1,$user,'aigc_video','generate','aigc_video_task',$key,$request,$request);
            $r=$reserve();self::assertSame($r['consumption_id'],$reserve()['consumption_id']);$id=(int)$r['consumption_id'];
            $log=AiConsumptionLog::find($id);$snapshot=$log['price_snapshot'];
            self::assertSame('reserved',$log['billing_status']);self::assertSame(10.,(float)$log['reserved_user_price']);
            self::assertSame(round($before['user']-10,2),$this->balances($user)['user']);
            $prepared=Videos::prepareRequest($snapshot,$request);self::assertArrayNotHasKey('token_budget',$prepared->nativeInput);self::assertArrayNotHasKey('maximum_quantity',$prepared->nativeInput);
            self::assertSame($prepared->nativeInput,Videos::prepareRequest(json_decode(json_encode($snapshot),true),$request)->nativeInput);
            $settle=new \ReflectionMethod(Videos::class,'settle');$videos=[['url'=>'https://fixtures.invalid/offline.mp4']];
            if($scenario==='missing'){
                $settle->invoke(null,$id,$videos,'','',['data'=>['metadata'=>['total_tokens'=>250]]]);
                $pending=(new \ReflectionMethod(Videos::class,'response'))->invoke(null,AiConsumptionLog::find($id)->toArray());self::assertSame('running',$pending['status']);self::assertSame([],$pending['videos']);
                self::assertSame('reserved',AiConsumptionLog::find($id)['billing_status']);self::assertSame('running',Db::name('ai_app_task')->where('id',$r['app_task_id'])->value('status'));self::assertSame(round($before['user']-10,2),$this->balances($user)['user']);
            }
            if($scenario==='over_bound'){
                try{$settle->invoke(null,$id,$videos,'','',['data'=>['usage'=>['total_tokens'=>1001]]]);self::fail('Receipt above bound must remain uncharged');}catch(\InvalidArgumentException$e){self::assertStringContainsString('超过冻结合同上限',$e->getMessage());}
                self::assertSame('reserved',AiConsumptionLog::find($id)['billing_status']);
            }
            if(in_array($scenario,['failure','over_bound'],true)){
                Videos::fail($id,'Offline fixture');Videos::fail($id,'Duplicate failure');self::assertSame($before,$this->balances($user));self::assertSame('refunded',AiConsumptionLog::find($id)['billing_status']);
            }else{
                // A live price change cannot replace the already reserved contract.
                $sku->save(['sale_points'=>99,'source_payload'=>['meter_contract'=>array_replace(self::declaration(),['maximum_quantity'=>2000])]]);
                $receipt=['data'=>['usage'=>['total_tokens'=>250]]];$settle->invoke(null,$id,$videos,'','',$receipt);$settled=$this->balances($user);
                self::assertSame(round($before['user']-2.5,2),$settled['user']);self::assertSame('settled',AiConsumptionLog::find($id)['billing_status']);self::assertSame(2.5,(float)AiConsumptionLog::find($id)['actual_user_price']);
                $settle->invoke(null,$id,$videos,'','',$receipt);Videos::fail($id,'Late failed callback');self::assertSame($settled,$this->balances($user));
            }
        }finally{Db::rollback();}
        self::assertSame(0,(int)Db::name('power_market_product')->where('product_code',$key)->count());
    }
    public static function lifecycleScenarios(): array{return [['success'],['missing'],['over_bound'],['failure'],['user_insufficient'],['tenant_insufficient']];}
    private function balances(int $user): array{return ['tenant'=>(float)Db::name('tenant')->where('id',1)->value('point_balance'),'user'=>(float)Db::name('user')->where('id',$user)->value('user_money')];}
}
