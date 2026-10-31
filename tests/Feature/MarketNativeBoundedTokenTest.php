<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationMeter as Meter;
use app\common\service\open_platform\{OpenNativeMarketPricing as Native,OpenMarketContractCompiler as Compiler,OpenMarketBilling as Billing,OpenStore as Store,OpenCallContext as Context,OpenGateway as Gateway,OpenVerification,OpenException};
use app\common\model\power\{PowerMarketProduct as Product,PowerMarketSku as Sku};
use app\common\model\ai\AiConsumptionLog;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Contract/ledger fixtures only. No HTTP authentication, transport, sync or publication. */
class MarketNativeBoundedTokenTest extends TestCase
{
    private static function declaration(): array{return ['version'=>2,'basis'=>'token','rounding'=>'ceil_2','maximum_quantity'=>1000,'usage_path'=>'data.usage.total_tokens'];}
    private static function product(): array
    {
        return ['id'=>991701,'resource_type'=>'model','model_type'=>'video','name'=>'Offline native Token fixture','upstream_model_code'=>'offline-native-token','upstream_channel_code'=>'offline','upstream_app_code'=>'','upstream_api_code'=>'',
            'source_payload'=>['market_metadata'=>['method'=>'POST','call_type'=>2,'params_schema'=>['prompt'=>['type'=>'string','required'=>true],'duration'=>['type'=>'integer','enum'=>[4]],'resolution'=>['type'=>'string','enum'=>['720P']],'aspect_ratio'=>['type'=>'string','enum'=>['1:1']]],
                'material_capabilities'=>['modes'=>[['code'=>'text_to_video','method'=>'text_to_video','fields'=>[]]]],'supported_durations'=>[4],'supported_resolutions'=>['720P'],'supported_ratios'=>['1:1'],
                'developer_doc_content'=>"```json\n{\"model\":\"offline-native-token\",\"channel\":\"offline\",\"prompt\":\"Synthetic native Token fixture\",\"duration\":4,\"resolution\":\"720P\",\"aspect_ratio\":\"1:1\"}\n```"]]];
    }
    private static function sku(): array
    {
        return ['id'=>991702,'product_id'=>991701,'sku_key'=>'offline-native-token','title'=>'Offline Token SKU','usage_unit'=>'token','usage_unit_size'=>1000,'sale_points'=>10,'upstream_price'=>10,'sale_status'=>1,'status'=>1,'locked_params'=>['duration'=>4,'resolution'=>'720P'],'source_payload'=>['meter_contract'=>self::declaration()]];
    }
    private static function native(array $extra=[]): array
    {
        return ['kind'=>'video','market_snapshot'=>['billing_meter'=>Meter::tokenContract(self::sku()),'usage_unit'=>'token','usage_unit_size'=>1000]+$extra];
    }
    public function testRawPromptIsVisibleToAdmissionWithoutRewritingTheWire(): void
    {
        $raw=['model'=>'offline-native-token','prompt'=>'  Synthetic prompt  '];$wire=Store::encode($raw);
        self::assertSame($raw['prompt'],Native::bind(self::product(),$raw,991702,self::sku())['selection']['prompt']);self::assertSame($wire,Store::encode($raw));
        self::assertSame('',Native::bind(self::product(),['prompt'=>''],991702,self::sku())['selection']['prompt']);
        $raw['parameters']=['prompt'=>'Conflicting prompt'];
        $this->expectException(OpenException::class);Native::bind(self::product(),$raw,991702,self::sku());
    }
    public function testCompilerBindsTheVerifiedUpperBoundWithoutActivatingAnything(): void
    {
        $p=self::product();$s=self::sku();$plan=Compiler::compileProduct($p+['skus'=>[$s]]);
        self::assertCount(1,$plan['drafts']);$op=$plan['drafts'][0]['config'];
        self::assertSame(Native::ADAPTER,$op['native_pricing']);self::assertSame('usage',$op['billing'][0]['basis']);
        self::assertSame(1000,$op['billing'][0]['max_units']);self::assertSame('data.usage.total_tokens',$op['billing'][0]['usage_path']);
        self::assertSame(Native::boundedTokenBinding($p,$s),$op['billing'][0]['native_meter']);
        $s['source_payload']=[];$blocked=Compiler::compileProduct($p+['skus'=>[$s]]);
        self::assertSame([],$blocked['drafts']);self::assertContains('actual_usage_boundary_required',array_column($blocked['blockers'],'code'));
    }
    /** @dataProvider invalidBindings */
    public function testInvalidOrUnsupportedSkuCannotObtainANativeTokenBinding(array $product,array $sku): void
    {
        self::assertNull(Native::boundedTokenBinding(array_replace(self::product(),$product),array_replace(self::sku(),$sku)));
    }
    public static function invalidBindings(): array
    {
        return [[[],['source_payload'=>[]]],[[],['usage_unit_size'=>0]],[[],['usage_unit_size'=>INF]],[[],['usage_unit'=>'per_call']],
            [[],['source_payload'=>['meter_contract'=>null]]],[[],['source_payload'=>['meter_contract'=>array_replace(self::declaration(),['maximum_quantity'=>0])]]],
            [['model_type'=>'image'],[]],[['model_type'=>'text'],[]],[['resource_type'=>'app_api','upstream_app_code'=>'unsupported','upstream_api_code'=>'create'],[]]];
    }
    /** @dataProvider missingUsage */
    public function testOnlyTheFrozenReceiptPathCanReleaseAReservation(array $response): void
    {
        self::assertNull(Native::settledUnits(self::native(),$response,false));
    }
    public static function missingUsage(): array
    {
        return [[[]],[['total_tokens'=>250]],[['data'=>['metadata'=>['total_tokens'=>250]]]],
            ...array_map(static fn($v)=>[['data'=>['usage'=>['total_tokens'=>$v]]]], [0,-1,250.5,250.,'0','1e2',true])];
    }
    public function testFrozenUsageSettlementUsesSharedRoundingAndFailureIsZero(): void
    {
        $native=self::native();$response=['data'=>['usage'=>['total_tokens'=>250]]];
        self::assertSame(250.,Native::settledUnits($native,$response,false));self::assertSame(2.5,Native::settledPrice(10.,250.,$native,false));
        self::assertSame(250.,Native::settledUnits(json_decode(json_encode($native),true),$response,false));
        $native['market_snapshot']['usage_unit_size']=1000000000;self::assertSame(.01,Native::settledPrice(.000001,1.,$native,false));
        self::assertSame(0.,Native::settledUnits($native,[],true));self::assertSame(0.,Native::settledPrice(10.,0.,$native,true));
        self::assertSame(17.,Native::settledUnits(['kind'=>'video','market_snapshot'=>['usage_unit'=>'token']],['data'=>['usage'=>['total_tokens'=>17]]],false));
    }
    public function testOverBoundReceiptIsExplicitAndCannotUseTodaysLargerBound(): void
    {
        try{Native::settledUnits(self::native(),['data'=>['usage'=>['total_tokens'=>1001]]],false);self::fail('Over-bound usage must wait for review');}
        catch(OpenException $e){self::assertSame('usage_boundary_exceeded',$e->errorType);}
    }
    /** @dataProvider lifecycles */
    public function testLocalRollbackNativeBudgetFrozenSettlementAndRecovery(string $scenario): void
    {
        if(getenv('AIGC_NATIVE_PREPARATION_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();self::assertContains(Db::connect()->getConfig('hostname'),['127.0.0.1','localhost']);self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $user=(int)Db::name('user')->where('tenant_id',1)->where('user_money','>',100)->order('user_money','desc')->value('id');if(!$user)self::markTestSkipped('Funded local rollback fixture unavailable');
        $req=request();$previous=[$req->tenantId??null,$req->userId??null];$key='native-token-'.bin2hex(random_bytes(8));Db::startTrans();
        try{
            Db::name('tenant')->where('id',1)->lock(true)->find();Db::name('user')->where('id',$user)->lock(true)->find();$before=$this->balances($user);
            // Unit fixture context only; no HTTP request or login state is changed.
            $req->tenantId=1;$req->userId=$user;
            $p=self::product();unset($p['id']);$p=Product::create($p+['product_code'=>$key,'source_code'=>'offline-fixture','upstream_resource_key'=>$key,'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            $s=self::sku();unset($s['id']);$s['product_id']=$p['id'];$s['sku_key']=$key;
            if($scenario==='tiny'){$s['usage_unit_size']=1000000000;$s['upstream_price']=$s['sale_points']=.000001;$s['source_payload']['meter_contract']['maximum_quantity']=1;}
            $s=Sku::create($s+['create_time'=>time(),'update_time'=>time()]);$plan=Compiler::compileProduct($p->toArray()+['skus'=>[$s->toArray()]]);self::assertCount(1,$plan['drafts']);$op=$plan['drafts'][0]['config'];
            $raw=['model'=>'offline-native-token','channel'=>'offline','prompt'=>'Synthetic native Token fixture','duration'=>4,'resolution'=>'720P','aspect_ratio'=>'1:1'];$wire=Store::encode($raw);
            $rule=$op['billing'][0];$budget=Native::capture(1,$p->toArray(),$raw,(int)$s['id'],$rule);$reserved=$scenario==='tiny'?.01:10.;self::assertSame($reserved,$budget['user']);self::assertSame($reserved,$budget['tenant']);self::assertSame($wire,Store::encode($raw));
            if($scenario==='stale_binding'){
                $badRules=[null,array_diff_key($rule,['native_meter'=>true]),array_replace($rule,['max_units'=>999]),array_replace($rule,['usage_path'=>'usage.total_tokens'])];
                $changed=$rule;$changed['native_meter']['rounding_scale']=0;$badRules[]=$changed;
                $changed=$rule;$changed['native_meter']['usage_unit_size']='1.000000';$badRules[]=$changed;
                foreach($badRules as$bad){try{Native::capture(1,$p->toArray(),$raw,(int)$s['id'],$bad);self::fail('Missing or stale published binding must reject');}catch(OpenException $e){self::assertSame('native_usage_boundary_required',$e->errorType);}}
                self::assertSame($before,$this->balances($user));
            }else{
                $budget['snapshot']+=['contract'=>$op,'product_id'=>(int)$p['id'],'billing_owner'=>'gateway'];$rid='req_'.$key;
                $row=['request_id'=>$rid,'tenant_id'=>1,'user_id'=>$user,'key_id'=>0,'kind'=>'market','method'=>'POST','path'=>'/api/v1/tasks','status'=>'accepted','billing_status'=>'pending','active'=>1,'consumption_ids'=>'[]','snapshot'=>Store::encode($budget['snapshot']),'payload_hash'=>hash('sha256',$wire),'response_blob'=>'','budget_user'=>$reserved,'budget_tenant'=>$reserved]+Store::timestamp();$row['id']=Store::table('request')->insertGetId($row);Context::enter($rid);
                if(in_array($scenario,['user_insufficient','tenant_insufficient'],true)){
                    if($scenario==='user_insufficient')Db::name('user')->where('id',$user)->update(['user_money'=>0]);else Db::name('tenant')->where('id',1)->update(['point_balance'=>0]);$low=$this->balances($user);
                    try{Billing::reserve($row,$budget);self::fail('Insufficient balance must reject reservation');}catch(\RuntimeException $e){self::assertNotSame('',$e->getMessage());}
                    self::assertSame($low,$this->balances($user));self::assertSame(0,(int)AiConsumptionLog::where('consume_no',$rid)->count());
                }else{
                    Billing::reserve($row,$budget);$log=AiConsumptionLog::where('consume_no',$rid)->find();self::assertSame(round($before['user']-$reserved,2),$this->balances($user)['user']);
                    if($scenario!=='tiny'){
                        $source=$s['source_payload'];$source['meter_contract']['maximum_quantity']=2000;$s->save(['source_payload'=>$source,'upstream_price'=>99,'sale_points'=>99]);
                        try{Native::capture(1,$p->toArray(),$raw,(int)$s['id'],$rule);self::fail('Changed bound requires contract review');}catch(OpenException $e){self::assertSame('native_usage_boundary_required',$e->errorType);}
                    }
                    if($scenario==='missing'){
                        Gateway::settleKnownResult($row,['data'=>['metadata'=>['total_tokens'=>250]]],false);self::assertSame('pending_usage',AiConsumptionLog::find($log['id'])['billing_status']);self::assertSame(round($before['user']-$reserved,2),$this->balances($user)['user']);
                    }
                    if($scenario==='over_bound'){
                        Gateway::settleKnownResult($row,['data'=>['usage'=>['total_tokens'=>1001]]],false);$held=Store::table('request')->where('id',$row['id'])->find();self::assertSame('settlement_failed',$held['billing_status']);self::assertSame('usage_boundary_exceeded',$held['error_code']);self::assertSame(round($before['user']-$reserved,2),$this->balances($user)['user']);
                    }
                    $result=['data'=>['usage'=>['total_tokens'=>$scenario==='tiny'?1:250],'result'=>['videos'=>[['url'=>'https://fixtures.invalid/result.mp4']]]]];$receipt=Store::encode($result);$failed=$scenario==='failure';
                    Gateway::settleKnownResult($row,$result,$failed);$done=Store::table('request')->where('id',$row['id'])->find();$actual=$failed?0.:($scenario==='tiny'?.01:2.5);
                    self::assertSame($failed?'refunded':'settled',$done['billing_status']);self::assertSame($actual,(float)$done['actual_user']);self::assertSame($receipt,Store::encode($result));OpenVerification::assertLedger($done);
                    $charged=$this->balances($user);self::assertSame(round($before['user']-$actual,2),$charged['user']);self::assertSame(round($before['tenant']-$actual,2),$charged['tenant']);
                    Gateway::settleKnownResult($row,$result,false);Gateway::settleKnownResult($row,[],true);self::assertSame($charged,$this->balances($user));
                }
            }
        }finally{Context::leave();$req->tenantId=$previous[0];$req->userId=$previous[1];Db::rollback();}
        self::assertSame($before,$this->balances($user));self::assertSame(0,(int)Db::name('power_market_product')->where('product_code',$key)->count());
    }
    public static function lifecycles(): array{return [['success'],['missing'],['over_bound'],['failure'],['tiny'],['stale_binding'],['user_insufficient'],['tenant_insufficient']];}
    private function balances(int $user): array{return ['tenant'=>(float)Db::name('tenant')->where('id',1)->value('point_balance'),'user'=>(float)Db::name('user')->where('id',$user)->value('user_money')];}
}
