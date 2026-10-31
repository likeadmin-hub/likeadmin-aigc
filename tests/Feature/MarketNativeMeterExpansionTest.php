<?php
namespace Tests\Feature;

use app\common\service\power\{MarketGenerationMeter as Meter,MarketPriceRuleService as Prices};
use app\common\service\open_platform\{OpenNativeMarketPricing as Native,OpenNativePixelMeter as Pixels,OpenMarketContractCompiler as Compiler,OpenMarketBilling as Billing,OpenStore as Store,OpenCallContext as Context,OpenGateway as Gateway,OpenVerification,OpenException};
use app\common\model\power\{PowerMarketProduct as Product,PowerMarketSku as Sku};
use app\common\model\ai\AiConsumptionLog;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** Synthetic request/schema, data URI bytes and rollback ledgers. No transport, uploads or publication. */
class MarketNativeMeterExpansionTest extends TestCase
{
    private static function token(): array{return ['version'=>2,'basis'=>'token','rounding'=>'ceil_2','maximum_quantity'=>1000,'usage_path'=>'data.usage.total_tokens'];}
    private static function pixel(): array{return ['version'=>3,'basis'=>'megapixel','rounding'=>'ceil_2','maximum_pixels_per_image'=>1000000,'maximum_images'=>4,'result_path'=>'data.image_urls','result_item_url'=>''];}
    private static function image(int $w=1,int $h=1): string
    {
        $c=static fn($t,$d)=>pack('N',strlen($d)).$t.$d.pack('N',crc32($t.$d));
        return 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n".$c('IHDR',pack('NNCCCCC',$w,$h,8,2,0,0,0)).$c('IDAT',gzcompress(str_repeat("\0".str_repeat("\xff\0\0",$w),$h))).$c('IEND',''));
    }
    public function testPixelEvidenceMustComeFromBytesAndMatchSnapshotAndResult(): void
    {
        $native=['kind'=>'image','market_snapshot'=>['billing_meter'=>Meter::contract(['usage_unit'=>'megapixel','source_payload'=>['meter_contract'=>self::pixel()]]),'reserved_image_quantity'=>2]];
        $result=['data'=>['image_urls'=>[self::image(),self::image()]],'width'=>9000,'height'=>9000,'measured_images'=>[['width'=>9999,'height'=>9999]]];$wire=Store::encode($result);
        self::assertNull(Native::settledUnits($native,$result,false));$e=Pixels::measure($native,$result);self::assertSame(.000002,Native::settledUnits($native,$result,false,$e));self::assertCount(2,$e['images']);self::assertSame($wire,Store::encode($result));
        $changed=$native;$changed['market_snapshot']['reserved_image_quantity']=1;
        try{Native::settledUnits($changed,$result,false,$e);self::fail('Stale measurement must reject');}catch(OpenException $x){self::assertSame('native_measurement_changed',$x->errorType);}
        $result['data']['image_urls']=[self::image(2,1)];$this->expectException(OpenException::class);Native::settledUnits($native,$result,false,$e);
    }
    public function testUnverifiedAndPrivateUrlsCannotBecomeServerMeasurements(): void
    {
        $native=['kind'=>'image','market_snapshot'=>['billing_meter'=>Meter::contract(['usage_unit'=>'megapixel','source_payload'=>['meter_contract'=>self::pixel()]]),'reserved_image_quantity'=>1]];
        self::assertNull(Pixels::measure($native,['width'=>1,'height'=>1]));
        $this->expectException(OpenException::class);Pixels::measure($native,['data'=>['image_urls'=>['http://127.0.0.1/private.png']]]);
    }
    /** @dataProvider scenarios */
    public function testLocalRollbackNativeMetersWithFrozenBindingAndWire(string $kind,string $scenario): void
    {
        if(getenv('AIGC_NATIVE_PREPARATION_DB_TESTS')!=='1')self::markTestSkipped('Explicit local rollback only');
        (new \think\App())->initialize();self::assertContains(Db::connect()->getConfig('hostname'),['localhost','127.0.0.1']);self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $user=(int)Db::name('user')->where('tenant_id',1)->where('user_money','>',100)->order('user_money','desc')->value('id');if(!$user)self::markTestSkipped('Funded fixture unavailable');
        $req=request();$previous=[$req->tenantId??null,$req->userId??null];$key='native-meter-'.bin2hex(random_bytes(8));Db::startTrans();
        try{
            Db::name('tenant')->where('id',1)->lock(true)->find();Db::name('user')->where('id',$user)->lock(true)->find();$before=$this->balances($user);$req->tenantId=1;$req->userId=$user;
            $nano=str_starts_with($kind,'nano');$video=$kind==='video_app_token';$pixel=str_ends_with($kind,'pixel');
            $meta=['method'=>'POST','call_type'=>2,'params_schema'=>['prompt'=>['type'=>'string','required'=>true]],'material_capabilities'=>['modes'=>[['code'=>$video?'text_to_video':'text_to_image','method'=>$video?'text_to_video':'text_to_image','fields'=>[]]]]];
            $raw=['model'=>'offline-image','channel'=>'offline','prompt'=>'Synthetic native meter fixture'];$locks=[];
            if($nano){$f=json_decode(file_get_contents(dirname(__DIR__).'/fixtures/canvas-generation-market-contracts.json'),true);foreach($f['contracts']as$r)if(($r['app_code']??'')==='nano_banana'){$meta=$r;break;}$locks=['model'=>'nano-banana-2','resolution'=>'1K'];$raw=['model'=>'nano-banana-2','action'=>'generate','resolution'=>'1K','aspect_ratio'=>'1:1','prompt'=>'Synthetic Nano fixture'];}
            if($video){$f=json_decode(file_get_contents(dirname(__DIR__).'/fixtures/canvas-generation-market-contracts.json'),true);foreach($f['contracts']as$r)if(($r['app_code']??'')==='grok_video'){$meta=$r;break;}$meta['category_code']='video';$locks=['model'=>'grok-imagine-video-1.5-fast','duration'=>6,'resolution'=>'720p'];$raw=['model'=>'grok-imagine-video-1.5-fast','prompt'=>'Synthetic video app fixture','duration'=>6,'resolution'=>'720p','ratio'=>'16:9'];}
            if(!$nano&&!$video)$raw['n']=2;
            $meta['developer_doc_content']="```json\n".json_encode($raw)."\n```\n```json\n".json_encode(['data'=>['task_id'=>'offline-task','status'=>'success','image_urls'=>['https://fixtures.invalid/out.png']]])."\n```";
            $p=Product::create(['product_code'=>$key,'name'=>'Offline meter fixture','resource_type'=>($nano||$video)?'app_api':'model','model_type'=>$video?'video':'image','upstream_model_code'=>($nano||$video)?'':'offline-image','upstream_channel_code'=>($nano||$video)?'':'offline','upstream_app_code'=>$nano?'nano_banana':($video?'grok_video':''),'upstream_api_code'=>$nano?'submit':($video?'create':''),'source_payload'=>['market_metadata'=>$meta,'resource'=>['max_reference_images'=>2]],'source_code'=>'offline-fixture','upstream_resource_key'=>$key,'status'=>1,'create_time'=>time(),'update_time'=>time()]);
            $s=Sku::create(['product_id'=>$p['id'],'sku_key'=>$key,'title'=>'Offline meter SKU','usage_unit'=>$pixel?'megapixel':'token','usage_unit_size'=>$pixel?1:1000,'sale_points'=>10,'upstream_price'=>10,'status'=>1,'sale_status'=>1,'locked_params'=>$locks,'source_payload'=>['meter_contract'=>$pixel?self::pixel():self::token()],'create_time'=>time(),'update_time'=>time()]);
            Prices::manual(0,(int)$p['id'],(int)$s['id'],10);Prices::manual(1,(int)$p['id'],(int)$s['id'],20);
            if(!$nano&&!$video){
                $plan=Compiler::compileProduct($p->toArray()+['skus'=>[$s->toArray()]]);self::assertCount(1,$plan['drafts'],json_encode($plan['blockers']??[]));
                self::assertSame($pixel?3:2,$plan['drafts'][0]['config']['billing'][0]['native_meter']['version']);
            }
            $binding=$pixel?Native::pixelBinding($p->toArray(),$s->toArray()):Native::boundedTokenBinding($p->toArray(),$s->toArray());self::assertNotNull($binding);
            $rule=['sku_id'=>(int)$s['id'],'basis'=>'usage','max_units'=>$pixel?4:1000,'usage_path'=>$pixel?'data.image_urls':'data.usage.total_tokens','native_meter'=>$binding];
            $wire=Store::encode($raw);$budget=Native::capture(1,$p->toArray(),$raw,(int)$s['id'],$rule);self::assertSame($wire,Store::encode($raw));$upper=$pixel?($nano?80.:40.):20.;self::assertSame($upper,$budget['user']);
            if($scenario==='stale_binding'){
                $bad=$rule;$bad['native_meter']['rounding_scale']=0;
                foreach([null,$bad]as$b){try{Native::capture(1,$p->toArray(),$raw,(int)$s['id'],$b);self::fail('Missing or stale published binding must reject');}catch(OpenException $e){self::assertSame('native_usage_boundary_required',$e->errorType);}}
                self::assertSame($before,$this->balances($user));
            }else{
                $op=['executor'=>'json','billable'=>true,'billing'=>[$rule]];$budget['snapshot']+=['contract'=>$op,'product_id'=>(int)$p['id'],'billing_owner'=>'gateway'];$rid='req_'.$key;
                $row=['request_id'=>$rid,'tenant_id'=>1,'user_id'=>$user,'key_id'=>0,'kind'=>'market','method'=>'POST','path'=>'/api/v1/tasks','status'=>'accepted','billing_status'=>'pending','active'=>1,'consumption_ids'=>'[]','snapshot'=>Store::encode($budget['snapshot']),'payload_hash'=>hash('sha256',$wire),'response_blob'=>'','budget_user'=>$upper,'budget_tenant'=>$budget['tenant']]+Store::timestamp();$row['id']=Store::table('request')->insertGetId($row);Context::enter($rid);
                if(in_array($scenario,['user_insufficient','tenant_insufficient'],true)){
                    if($scenario==='user_insufficient')Db::name('user')->where('id',$user)->update(['user_money'=>0]);else Db::name('tenant')->where('id',1)->update(['point_balance'=>0]);$low=$this->balances($user);
                    try{Billing::reserve($row,$budget);self::fail('Upper bound must be funded');}catch(\RuntimeException $e){self::assertNotSame('',$e->getMessage());}self::assertSame($low,$this->balances($user));
                }else{
                Billing::reserve($row,$budget);$log=AiConsumptionLog::where('consume_no',$rid)->find();self::assertSame(round($before['user']-$upper,2),$this->balances($user)['user']);
                if($scenario==='lease_lost'){
                    Store::table('request')->where('id',$row['id'])->update(['lease_token'=>'new-worker','lease_expire'=>time()+60]);Context::enter($rid,'old-worker');
                    try{Billing::settle($row,['data'=>['image_urls'=>['http://127.0.0.1/never-download']]],false);self::fail('Lost lease must reject before a download or charge');}catch(OpenException $e){self::assertSame('recovery_lease_lost',$e->errorType);}
                    self::assertSame(round($before['user']-$upper,2),$this->balances($user)['user']);Context::enter($rid,'new-worker');
                }
                $source=$s['source_payload'];$source['meter_contract'][$pixel?'maximum_pixels_per_image':'maximum_quantity']=2000000;$s->save(['source_payload'=>$source,'sale_points'=>99]);
                if(in_array($scenario,['missing','over_bound','invalid_bytes','too_many'],true)){
                    $bad=$pixel?['data'=>['image_urls'=>[]]]:['data'=>['metadata'=>['total_tokens'=>250]]];
                    if($scenario==='over_bound')$bad=$pixel?['data'=>['image_urls'=>[self::image(1001,1000)]]]:['data'=>['usage'=>['total_tokens'=>1001]]];
                    if($scenario==='invalid_bytes')$bad=['data'=>['image_urls'=>['data:image/png;base64,'.base64_encode('invalid bytes')]]];
                    if($scenario==='too_many')$bad=['data'=>['image_urls'=>array_fill(0,3,self::image())]];
                    Gateway::settleKnownResult($row,$bad,false);self::assertContains(Store::table('request')->where('id',$row['id'])->value('billing_status'),['pending_usage','settlement_failed']);self::assertContains(AiConsumptionLog::find($log['id'])['billing_status'],['reserved','pending_usage']);self::assertSame(round($before['user']-$upper,2),$this->balances($user)['user']);
                }
                $result=$pixel?['data'=>['image_urls'=>[self::image(),self::image()]],'width'=>9999,'height'=>9999]:['data'=>['usage'=>['total_tokens'=>250]]];$body=Store::encode($result);$failed=$scenario==='failure';
                Gateway::settleKnownResult($row,$result,$failed);$done=Store::table('request')->where('id',$row['id'])->find();$actual=$failed?0.:($pixel?.01:5.);self::assertSame($failed?'refunded':'settled',$done['billing_status']);self::assertSame($actual,(float)$done['actual_user']);self::assertSame($body,Store::encode($result));OpenVerification::assertLedger($done);
                $charged=$this->balances($user);self::assertSame(round($before['user']-$actual,2),$charged['user']);self::assertSame(round($before['tenant']-($failed?0.:($pixel?.01:2.5)),2),$charged['tenant']);
                Gateway::settleKnownResult($row,$result,false);Gateway::settleKnownResult($row,[],true);self::assertSame($charged,$this->balances($user));
                }
            }
        }finally{Context::leave();$req->tenantId=$previous[0];$req->userId=$previous[1];Db::rollback();}
        self::assertSame($before,$this->balances($user));self::assertSame(0,(int)Product::where('product_code',$key)->count());
    }
    public static function scenarios(): array
    {
        $out=[];foreach(['image_token','nano_token','video_app_token','image_pixel','nano_pixel']as$kind)foreach(['success','missing','over_bound','failure','stale_binding']as$s)$out[]=[$kind,$s];
        foreach(['invalid_bytes','too_many','user_insufficient','tenant_insufficient','lease_lost']as$s)$out[]=['image_pixel',$s];foreach(['user_insufficient','tenant_insufficient']as$s)$out[]=['image_token',$s];return $out;
    }
    private function balances(int $user): array{return ['tenant'=>(float)Db::name('tenant')->where('id',1)->value('point_balance'),'user'=>(float)Db::name('user')->where('id',$user)->value('user_money')];}
}
